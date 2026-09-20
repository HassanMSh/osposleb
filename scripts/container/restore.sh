#!/usr/bin/env bash
set -euo pipefail

umask 077

script_dir=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
repo_root=$(dirname -- "$(dirname -- "$script_dir")")
env_file="$repo_root/.env"
archive_path=''
uploads_arg="$repo_root/public/uploads"
database_arg=''
yes_flag=0
uploads_option_set=0
env_option_set=0
database_option_set=0
env_value=''
db_host_arg=''
db_host_option_set=0
db_host=''
db_port='3306'
db_user=''
db_password=''
configured_database=''
target_database=''
stage_dir=''
defaults_file=''
old_uploads=''
old_uploads_moved=0
uploads_installed=0
restore_success=0

# Print the command-line help for the restore tool.
show_help() {
    cat <<'HELP'
Usage: scripts/container/restore.sh --archive <file> --db-host <host> [--uploads <path>] [--env <path>] [--database <name>] [--yes]

Restore one OSPOS backup archive.

Options:
  --archive <file>           Backup archive to restore.
  --db-host <host>           Database service name or TCP host.
  --uploads <path>           Uploads directory; default is public/uploads.
  --env <path>               Database .env file; default is the repository .env.
  --database <name>          Restore into another database for a restore drill.
  --yes                      Skip the interactive confirmation.
  --help                     Show this help.

Without --database, the database from .env is overwritten.
Use --database with a separate, empty database to test a restore without touching the live shop.
This command never restores .env and never drops or recreates a database.
HELP
}

# Stop with a readable error message without exposing database credentials.
fail() {
    printf 'Error: %s\n' "$1" >&2
    exit 1
}

# Check that one required external command is available.
require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "Required command is not available: $1"
}

# Read one CodeIgniter .env key without executing the file or printing its value.
read_env_value() {
    local key=$1
    local required=${2:-required}
    local line raw pattern
    local matches=0

    env_value=''
    pattern="^[[:space:]]*${key//./\\.}[[:space:]]*=[[:space:]]*(.*)$"
    while IFS= read -r line || [[ -n "$line" ]]; do
        if [[ $line =~ $pattern ]]; then
            matches=$((matches + 1))
            raw=${BASH_REMATCH[1]}
            raw="${raw#"${raw%%[![:space:]]*}"}"
            raw="${raw%"${raw##*[![:space:]]}"}"

            if [[ ${raw:0:1} == "'" ]]; then
                [[ ${#raw} -ge 2 && ${raw: -1} == "'" ]] || fail "Invalid value for $key in $env_file."
                env_value=${raw:1:${#raw}-2}
            elif [[ ${raw:0:1} == '"' ]]; then
                [[ ${#raw} -ge 2 && ${raw: -1} == '"' ]] || fail "Invalid value for $key in $env_file."
                env_value=${raw:1:${#raw}-2}
            else
                raw=${raw%%#*}
                raw="${raw#"${raw%%[![:space:]]*}"}"
                raw="${raw%"${raw##*[![:space:]]}"}"
                env_value=$raw
            fi
        fi
    done < "$env_file"

    if (( matches > 1 )); then
        fail "The .env file contains more than one value for $key."
    fi
    if (( matches == 0 )); then
        if [[ $required == required ]]; then
            fail "The .env file has no value for $key."
        fi
        env_value=''
    fi
}

# Check that a database name is safe to pass to the MySQL client.
validate_identifier() {
    [[ $1 =~ ^[A-Za-z0-9_][A-Za-z0-9_-]*$ ]] || fail "Unsafe database identifier: $2"
}

# Check that a database port is a valid TCP port number.
validate_port() {
    [[ $1 =~ ^[0-9]+$ ]] || fail "Invalid database port in $env_file."
    (( 1 <= 10#$1 && 10#$1 <= 65535 )) || fail "Invalid database port in $env_file."
}

# Resolve the uploads target and reject a symlink at the directory being replaced.
resolve_uploads_target() {
    local path=$uploads_arg
    local parent name
    if [[ $path != /* ]]; then
        path="$repo_root/$path"
    fi
    [[ $path != / ]] || fail 'Refusing to replace the filesystem root as uploads.'
    parent=$(dirname -- "$path")
    name=$(basename -- "$path")
    [[ -n $name && $name != / ]] || fail "Invalid uploads path: $path"
    [[ -d $parent ]] || fail "Uploads parent directory does not exist: $parent"
    parent=$(CDPATH= cd -- "$parent" && pwd -P) || fail "Cannot use uploads parent directory: $path"
    uploads_target="$parent/$name"
    [[ ! -L $uploads_target ]] || fail "Uploads target must not be a symlink: $uploads_target"
    if [[ -e $uploads_target && ! -d $uploads_target ]]; then
        fail "Uploads target is not a directory: $uploads_target"
    fi
}

# Create the temporary MySQL defaults file and keep it private.
create_defaults_file() {
    defaults_file="$stage_dir/mysql.cnf"
    {
        printf '[client]\n'
        printf 'host=%s\n' "$db_host"
        printf 'port=%s\n' "$db_port"
        printf 'user=%s\n' "$db_user"
        printf 'password=%s\n' "$db_password"
    } > "$defaults_file"
    chmod 600 "$defaults_file"
}

# Run mysql with the private defaults file over TCP.
run_mysql() {
    mysql --defaults-extra-file="$defaults_file" "$target_database" "$@"
}

# Run mysql against the server without selecting the restore target.
run_mysql_server() {
    mysql --defaults-extra-file="$defaults_file" "$@"
}

# Validate every outer archive member before extracting anything.
validate_archive_members() {
    local member normalized line member_type
    local database_count=0
    local manifest_count=0
    local uploads_count=0

    tar -tzf "$archive_path" > "$stage_dir/members" 2>/dev/null \
        || fail 'The backup archive is not a readable gzip tar archive.'
    while IFS= read -r member || [[ -n $member ]]; do
        [[ -n $member ]] || fail 'The backup archive contains an empty member name.'
        [[ $member != /* ]] || fail 'The backup archive contains an absolute path.'
        case "/$member/" in
            */../*|*/..//*) fail 'The backup archive contains a path traversal entry.' ;;
        esac
        case $member in
            */./*|./*|*/.) fail 'The backup archive contains a dot path.' ;;
        esac

        normalized=${member%/}
        case $normalized in
            .env|*/.env) fail 'The backup archive must not contain a .env file.' ;;
        esac
        case $normalized in
            database.sql)
                database_count=$((database_count + 1))
                ;;
            manifest.txt)
                manifest_count=$((manifest_count + 1))
                ;;
            uploads)
                uploads_count=$((uploads_count + 1))
                ;;
            uploads/*)
                ;;
            *)
                fail "The backup archive contains an unexpected member: $member"
                ;;
        esac
    done < "$stage_dir/members"

    (( database_count == 1 )) || fail 'The backup archive must contain one database.sql file.'
    (( manifest_count == 1 )) || fail 'The backup archive must contain one manifest.txt file.'
    (( uploads_count >= 1 )) || fail 'The backup archive must contain an uploads/ directory.'

    tar -tvzf "$archive_path" > "$stage_dir/member-types" 2>/dev/null \
        || fail 'The backup archive member list could not be read.'
    while IFS= read -r line || [[ -n $line ]]; do
        [[ -n $line ]] || continue
        member_type=${line:0:1}
        case $member_type in
            -|d|h)
                ;;
            *)
                fail 'The backup archive contains a non-regular file or symlink.'
                ;;
        esac
    done < "$stage_dir/member-types"
}

# Read required manifest fields without treating the manifest as shell code.
read_manifest() {
    local line
    local format_count=0
    local database_count=0
    local contents_count=0
    local checksum_count=0
    manifest_format=''
    manifest_database=''
    manifest_contents=''
    manifest_checksum=''

    while IFS= read -r line || [[ -n $line ]]; do
        case $line in
            'Backup format: '*)
                format_count=$((format_count + 1))
                manifest_format=${line#Backup format: }
                ;;
            'Database name: '*)
                database_count=$((database_count + 1))
                manifest_database=${line#Database name: }
                ;;
            'Contents: '*)
                contents_count=$((contents_count + 1))
                manifest_contents=${line#Contents: }
                ;;
            'Database SHA-256: '*)
                checksum_count=$((checksum_count + 1))
                manifest_checksum=${line#Database SHA-256: }
                ;;
        esac
    done < "$stage_dir/manifest.txt"

    (( format_count == 1 )) || fail 'The backup manifest has an invalid format.'
    [[ $manifest_format == 1 ]] || fail 'The backup manifest has an invalid format.'
    (( database_count == 1 )) || fail 'The backup manifest has no database name.'
    [[ -n $manifest_database ]] || fail 'The backup manifest has no database name.'
    (( contents_count == 1 )) || fail 'The backup manifest has invalid contents.'
    [[ $manifest_contents == 'database.sql, uploads/' ]] || fail 'The backup manifest has invalid contents.'
    (( checksum_count == 1 )) || fail 'The backup manifest has an invalid database checksum.'
    [[ $manifest_checksum =~ ^[[:xdigit:]]{64}$ ]] || fail 'The backup manifest has an invalid database checksum.'
}

# Compare the database checksum recorded in the manifest with the extracted dump.
verify_database_checksum() {
    local actual_checksum
    actual_checksum=$(sha256sum "$stage_dir/database.sql" | awk '{print $1}')
    [[ $actual_checksum == "$manifest_checksum" ]] || fail 'The database checksum does not match the manifest.'
}

# Ask for the destructive confirmation unless --yes was supplied.
confirm_restore() {
    printf 'Database to overwrite: %s\n' "$target_database"
    if (( yes_flag == 1 )); then
        return 0
    fi
    [[ -t 0 ]] || fail 'Restore needs --yes when standard input is not interactive.'
    printf 'This will overwrite the database and uploads directory. Type yes to continue: ' >&2
    local answer=''
    IFS= read -r answer || fail 'Restore was cancelled.'
    [[ $answer == yes ]] || fail 'Restore cancelled. Type yes in full to continue.'
}

# Move the old uploads aside and install the validated uploads directory.
restore_uploads() {
    old_uploads="$stage_dir/old-uploads"
    if [[ -e $uploads_target ]]; then
        mv -- "$uploads_target" "$old_uploads" || fail 'Could not move the existing uploads directory aside.'
        old_uploads_moved=1
    fi
    mv -- "$stage_dir/uploads" "$uploads_target" || fail 'Could not install the restored uploads directory.'
    uploads_installed=1
}

# Restore the old uploads directory if the restore fails after moving it.
rollback_uploads() {
    if (( uploads_installed == 1 )) && [[ -e $uploads_target ]]; then
        rm -rf -- "$uploads_target"
    fi
    if (( old_uploads_moved == 1 )) && [[ -e $old_uploads ]]; then
        mv -- "$old_uploads" "$uploads_target" >/dev/null 2>&1 || true
    fi
}

# Remove temporary files, remote credentials, and a partially installed upload restore.
cleanup() {
    if (( restore_success == 0 )); then
        rollback_uploads
    fi
    if [[ -n $stage_dir && -d $stage_dir ]]; then
        rm -rf -- "$stage_dir"
    fi
}

# Parse command-line options and reject missing, duplicate, or unknown values.
parse_args() {
    local option
    while (( $# > 0 )); do
        option=$1
        case $option in
            --help|-h)
                show_help
                exit 0
                ;;
            --archive)
                (( $# >= 2 )) || fail '--archive needs a file path.'
                [[ -z $archive_path ]] || fail '--archive was given more than once.'
                archive_path=$2
                shift 2
                ;;
            --db-host)
                (( $# >= 2 )) || fail '--db-host needs a host name.'
                (( db_host_option_set == 0 )) || fail '--db-host was given more than once.'
                db_host_arg=$2
                db_host_option_set=1
                shift 2
                ;;
            --uploads)
                (( $# >= 2 )) || fail '--uploads needs a directory.'
                (( uploads_option_set == 0 )) || fail '--uploads was given more than once.'
                uploads_arg=$2
                uploads_option_set=1
                shift 2
                ;;
            --env)
                (( $# >= 2 )) || fail '--env needs a file path.'
                (( env_option_set == 0 )) || fail '--env was given more than once.'
                env_file=$2
                env_option_set=1
                shift 2
                ;;
            --database)
                (( $# >= 2 )) || fail '--database needs a database name.'
                (( database_option_set == 0 )) || fail '--database was given more than once.'
                database_arg=$2
                database_option_set=1
                shift 2
                ;;
            --yes)
                (( yes_flag == 0 )) || fail '--yes was given more than once.'
                yes_flag=1
                shift
                ;;
            *)
                fail "Unknown option: $option"
                ;;
        esac
    done
    [[ -n $archive_path ]] || fail '--archive is required.'
}

parse_args "$@"
[[ -f $archive_path ]] || fail "Archive file does not exist: $archive_path"
[[ -r $archive_path ]] || fail "Archive file is not readable: $archive_path"
[[ -f $env_file ]] || fail "Environment file does not exist: $env_file"
[[ -r $env_file ]] || fail "Environment file is not readable: $env_file"
(( db_host_option_set == 1 )) || fail '--db-host is required.'
[[ -n $db_host_arg ]] || fail '--db-host must not be empty.'
require_command mysql
require_command awk
require_command chmod
require_command gzip
require_command mktemp
require_command mv
require_command rm
require_command sha256sum
require_command tar

db_host=$db_host_arg
read_env_value 'database.default.port' optional
[[ -n $env_value ]] && db_port=$env_value
read_env_value 'database.default.database'
configured_database=$env_value
read_env_value 'database.default.username'
db_user=$env_value
read_env_value 'database.default.password'
db_password=$env_value
validate_port "$db_port"
[[ -n $db_host ]] || fail 'The database host must not be empty.'
[[ -n $db_user ]] || fail 'database.default.username must not be empty.'

if (( database_option_set == 1 )); then
    target_database=$database_arg
else
    target_database=$configured_database
fi
validate_identifier "$configured_database" 'database.default.database'
validate_identifier "$target_database" '--database'

if [[ $uploads_arg != /* ]]; then
    uploads_arg="$repo_root/$uploads_arg"
fi
resolve_uploads_target

stage_dir=$(mktemp -d "${TMPDIR:-/tmp}/ospos-restore.XXXXXX")
trap cleanup EXIT
trap 'exit 1' HUP INT TERM
create_defaults_file
validate_archive_members
tar -xzf "$archive_path" -C "$stage_dir" --no-same-owner --no-same-permissions --no-overwrite-dir \
    database.sql manifest.txt uploads
[[ -f $stage_dir/database.sql && ! -L $stage_dir/database.sql ]] \
    || fail 'The extracted database.sql is not a regular file.'
[[ -f $stage_dir/manifest.txt && ! -L $stage_dir/manifest.txt ]] \
    || fail 'The extracted manifest.txt is not a regular file.'
[[ -d $stage_dir/uploads && ! -L $stage_dir/uploads ]] \
    || fail 'The extracted uploads/ entry is not a directory.'
read_manifest
validate_identifier "$manifest_database" 'manifest database name'
if (( database_option_set == 0 )) && [[ $manifest_database != "$configured_database" ]]; then
    fail "The backup belongs to database $manifest_database, not $configured_database. Use --database for a restore drill."
fi
verify_database_checksum
confirm_restore

if ! run_mysql_server -e 'SELECT 1' >/dev/null 2> "$stage_dir/mysql-connection-error"; then
    fail 'Could not connect to the database server.'
fi
if ! run_mysql -e 'SELECT 1' >/dev/null 2> "$stage_dir/mysql-target-error"; then
    if (( database_option_set == 1 )) && [[ $target_database != "$configured_database" ]]; then
        fail 'Could not access the scratch database; the account may need rights on it.'
    fi
    fail 'Could not access the target database.'
fi
if ! run_mysql < "$stage_dir/database.sql" 2> "$stage_dir/mysql-restore-error"; then
    fail 'Database restore failed; the uploads directory was not changed.'
fi
restore_uploads
restore_success=1
printf 'Restored database: %s\n' "$target_database"
printf 'Restored uploads: %s\n' "$uploads_target"
printf 'Check that you can log in.\n'
