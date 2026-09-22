#!/usr/bin/env bash
set -euo pipefail

umask 077

script_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
repo_root=$(dirname -- "$(dirname -- "$script_dir")")
env_file="$repo_root/.env"
config_file=''
destination_arg=''
uploads_arg="$repo_root/public/uploads"
destination_option_set=0
config_option_set=0
uploads_option_set=0
env_option_set=0
db_host_arg=''
db_host_option_set=0
db_host=''
db_port='3306'
db_name=''
db_user=''
db_password=''
db_prefix=''
env_value=''
config_value=''
stage_dir=''
defaults_file=''
archive_tmp=''
archive_path=''
archive_name='-'
archive_size='-'
log_arg=''
log_path=''
log_option_set=0
keep_arg=''
keep_option_set=0
copy_to_arg=''
copy_missing_arg=''
copy_option_set=0
copy_status='-'
copy_deleted=0
deleted=0
retention_deleted=0
archive_verified=0
archive_created=0
failure_message='backup_command_failed'
success_message='-'

# Print the command-line help for the backup tool.
show_help() {
    cat <<'HELP'
Usage: scripts/container/backup.sh --db-host <host> [--destination <directory>] [--config <path>] [--uploads <path>] [--env <path>] [--keep <N>] [--copy-to <dir>|--copy-missing <text>] [--log <file>]

Create one timestamped OSPOS backup archive.

Options:
  --destination <directory>  Existing, writable directory for the archive.
  --config <path>            Plain client config used when destination is omitted.
  --db-host <host>           Database service name or TCP host.
  --uploads <path>           Uploads directory; default is public/uploads.
  --env <path>               Database .env file; default is the repository .env.
  --keep <N>                 Keep the newest N archives; 0 keeps every archive.
  --copy-to <directory>      Copy the verified archive to an existing directory.
  --copy-missing <text>      Record that a configured copy directory is missing.
  --log <file>               Append one result line to this file.
  --help                     Show this help.

The database credentials are read from --env and are never printed or archived.
The verified archive contains database.sql, manifest.txt, and uploads/.
Without --destination, OSPOS_BACKUP_DESTINATION is read from --config.
Without --log, backup.log is written in the destination directory.
HELP
}

# Stop with a readable error message without exposing database credentials.
fail() {
    failure_message=$1
    printf 'Error: %s\n' "$1" >&2
    exit 1
}

# Append one result line after cleanup and report a failed success append.
write_run_log() {
    local status=$1
    local timestamp message

    [[ -n $log_path ]] || return 0
    timestamp=$(date -u '+%Y-%m-%dT%H:%M:%SZ') || return 0
    if (( status == 0 )); then
        message=$(printf '%s' "$success_message" | tr -cs '[:alnum:]_.:-' '_')
        [[ -n $message ]] || message='-'
        if printf '%s result=ok archive=%s size=%s deleted=%s copy=%s copy_deleted=%s message=%s\n' \
            "$timestamp" "$archive_name" "$archive_size" "$deleted" "$copy_status" "$copy_deleted" "$message" \
            >> "$log_path" 2>/dev/null; then
            return 0
        fi
        printf 'Warning: could not append successful backup result to log: %s\n' "$log_path" >&2 || true
        return 1
    fi

    message=$(printf '%s' "$failure_message" | tr -cs '[:alnum:]_.:-' '_')
    [[ -n $message ]] || message='backup_command_failed'
    printf '%s result=failed archive=- size=- deleted=0 copy=- copy_deleted=0 message=%s\n' \
        "$timestamp" "$message" >> "$log_path" 2>/dev/null || true
}

# Clean temporary files, remove an unverified archive this run created, and write the one log line.
finish_run() {
    local status=$?
    trap - EXIT
    set +e
    cleanup
    if (( status != 0 && archive_verified == 0 && archive_created == 1 )) && [[ -n $archive_path && -e $archive_path ]]; then
        rm -f -- "$archive_path"
    fi
    if ! write_run_log "$status" && (( status == 0 )); then
        status=1
    fi
    # Any failure exits 1, so the launcher knows this script already logged it.
    (( status == 0 )) || status=1
    exit "$status"
}

# Keep the newest matching archives and always preserve the archive just written.
apply_retention() {
    local directory=$1
    local keep_value=$2
    local protected_name=$3
    local path name keep_count keep_digits delete_count index
    local -a names=() older_names=()

    retention_deleted=0
    [[ $keep_value =~ ^[0-9]+$ ]] || return 0
    keep_digits=$keep_value
    while [[ ${#keep_digits} -gt 1 && ${keep_digits:0:1} == 0 ]]; do
        keep_digits=${keep_digits:1}
    done
    [[ $keep_digits != 0 ]] || return 0

    for path in "$directory"/ospos-backup-*.tar.gz; do
        [[ -e $path || -L $path ]] || continue
        name=${path##*/}
        [[ $name =~ ^ospos-backup-[0-9]{8}-[0-9]{6}\.tar\.gz$ ]] || continue
        [[ -f $path && ! -L $path ]] || continue
        names+=("$name")
    done
    ((${#names[@]} > 0)) || return 0
    mapfile -t names < <(printf '%s\n' "${names[@]}" | LC_ALL=C sort)
    for name in "${names[@]}"; do
        [[ $name == "$protected_name" ]] || older_names+=("$name")
    done

    if ((${#keep_digits} > 9)); then
        return 0
    fi
    keep_count=$((10#$keep_digits))
    (( keep_count > 0 )) || return 0
    delete_count=$((${#older_names[@]} - keep_count + 1))
    (( delete_count > 0 )) || return 0
    for ((index = 0; index < delete_count; index++)); do
        path="$directory/${older_names[index]}"
        if rm -f -- "$path"; then
            retention_deleted=$((retention_deleted + 1))
        else
            printf 'Warning: could not remove old backup: %s\n' "$path" >&2 || true
        fi
    done
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

# Read the non-secret backup setting without executing the config file.
read_config_value() {
    local key=$1
    local line raw pattern
    local matches=0

    config_value=''
    pattern="^[[:space:]]*${key//./\\.}[[:space:]]*=[[:space:]]*(.*)$"
    while IFS= read -r line || [[ -n "$line" ]]; do
        if [[ $line =~ $pattern ]]; then
            matches=$((matches + 1))
            raw=${BASH_REMATCH[1]}
            raw="${raw#"${raw%%[![:space:]]*}"}"
            raw="${raw%"${raw##*[![:space:]]}"}"

            if [[ ${raw:0:1} == "'" ]]; then
                [[ ${#raw} -ge 2 && ${raw: -1} == "'" ]] || fail "Invalid value for $key in $config_file."
                config_value=${raw:1:${#raw}-2}
            elif [[ ${raw:0:1} == '"' ]]; then
                [[ ${#raw} -ge 2 && ${raw: -1} == '"' ]] || fail "Invalid value for $key in $config_file."
                config_value=${raw:1:${#raw}-2}
            else
                raw=${raw%%#*}
                raw="${raw#"${raw%%[![:space:]]*}"}"
                raw="${raw%"${raw##*[![:space:]]}"}"
                config_value=$raw
            fi
        fi
    done < "$config_file"

    (( matches == 1 )) || fail "The config file must contain one value for $key."
}

# Resolve the configured backup destination under the client directory.
resolve_configured_destination() {
    local config_directory
    read_config_value 'OSPOS_BACKUP_DESTINATION'
    [[ -n $config_value ]] || fail 'OSPOS_BACKUP_DESTINATION must not be empty.'
    [[ $config_value != /* ]] || fail 'OSPOS_BACKUP_DESTINATION must be relative to the client directory.'
    case $config_value in
        .|..|*../*|*/..|*'/..'*)
            fail 'OSPOS_BACKUP_DESTINATION contains an unsafe path.'
            ;;
    esac
    config_directory=$(dirname -- "$config_file")
    destination_arg="$config_directory/$config_value"
}

# Check that a database name or prefix is safe to use as an identifier.
validate_identifier() {
    [[ $1 =~ ^[A-Za-z0-9_][A-Za-z0-9_-]*$ ]] || fail "Unsafe database identifier: $2"
}

# Check that a database port is a valid TCP port number.
validate_port() {
    [[ $1 =~ ^[0-9]+$ ]] || fail "Invalid database port in $env_file."
    (( 1 <= 10#$1 && 10#$1 <= 65535 )) || fail "Invalid database port in $env_file."
}

# Resolve and validate the uploads directory without following a directory symlink.
resolve_uploads_directory() {
    local path=$uploads_arg
    if [[ $path != /* ]]; then
        path="$repo_root/$path"
    fi
    [[ -d $path ]] || fail "Uploads directory does not exist: $path"
    [[ ! -L $path ]] || fail "Uploads directory must not be a symlink: $path"
    uploads_dir=$(CDPATH='' cd -- "$path" && pwd -P) || fail "Cannot read uploads directory: $path"
    [[ $uploads_dir != / ]] || fail "Refusing to archive the filesystem root as uploads."
}

# Refuse uploads content that would make the archive contain secrets or unsafe links.
check_uploads_content() {
    if find "$uploads_dir" -mindepth 1 \( -iname '.env' -o -iname 'app.env' -o -iname 'mysql.env' -o -iname 'db.env' \) -print -quit | grep -q .; then
        fail 'Uploads directory contains a .env, app.env, mysql.env, or db.env basename; refusing to archive it.'
    fi
    if find "$uploads_dir" -type l -print -quit | grep -q .; then
        fail 'Uploads directory contains a symlink; refusing to archive it.'
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

# Run mysqldump using the private defaults file over TCP.
run_dump() {
    mysqldump --defaults-extra-file="$defaults_file" \
        --single-transaction --routines --triggers \
        --default-character-set=utf8mb4 "$db_name"
}

# Read the latest migration version when the database connection permits it.
read_migration_version() {
    migration_version='unavailable'
    [[ $db_prefix =~ ^[A-Za-z0-9_]*$ ]] || return 0
    local table_name="${db_prefix}migrations"
    local query="SELECT MAX(version) FROM \`${table_name}\`;"
    local result=''

    result=$(mysql --defaults-extra-file="$defaults_file" \
        --batch --skip-column-names "$db_name" -e "$query" 2>/dev/null) || return 0
    [[ $result =~ ^[0-9]+$ ]] && migration_version=$result
}

# Read the application version from the tracked application configuration.
read_application_version() {
    application_version='unavailable'
    [[ -f $repo_root/app/Config/App.php ]] || return 0
    application_version=$(awk -F"'" '/public string \$application_version/{print $2; exit}' \
        "$repo_root/app/Config/App.php")
    [[ -n $application_version ]] || application_version='unavailable'
}

# Remove temporary files.
cleanup() {
    if [[ -n $archive_tmp && -e $archive_tmp ]]; then
        rm -f -- "$archive_tmp"
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
            --destination)
                (( $# >= 2 )) || fail "--destination needs a directory."
                (( destination_option_set == 0 )) || fail "--destination was given more than once."
                destination_arg=$2
                destination_option_set=1
                shift 2
                ;;
            --config)
                (( $# >= 2 )) || fail "--config needs a file path."
                (( config_option_set == 0 )) || fail "--config was given more than once."
                config_file=$2
                config_option_set=1
                shift 2
                ;;
            --db-host)
                (( $# >= 2 )) || fail "--db-host needs a host name."
                (( db_host_option_set == 0 )) || fail "--db-host was given more than once."
                db_host_arg=$2
                db_host_option_set=1
                shift 2
                ;;
            --uploads)
                (( $# >= 2 )) || fail "--uploads needs a directory."
                (( uploads_option_set == 0 )) || fail "--uploads was given more than once."
                uploads_arg=$2
                uploads_option_set=1
                shift 2
                ;;
            --env)
                (( $# >= 2 )) || fail "--env needs a file path."
                (( env_option_set == 0 )) || fail "--env was given more than once."
                env_file=$2
                env_option_set=1
                shift 2
                ;;
            --keep)
                (( $# >= 2 )) || fail "--keep needs a non-negative integer."
                (( keep_option_set == 0 )) || fail "--keep was given more than once."
                [[ $2 =~ ^[0-9]+$ ]] || fail "--keep needs a non-negative integer."
                keep_arg=$2
                keep_option_set=1
                shift 2
                ;;
            --copy-to)
                (( $# >= 2 )) || fail "--copy-to needs a directory."
                (( copy_option_set == 0 )) || fail "--copy-to and --copy-missing cannot both be used."
                [[ -n $2 ]] || fail "--copy-to needs a directory."
                copy_to_arg=$2
                copy_option_set=1
                shift 2
                ;;
            --copy-missing)
                (( $# >= 2 )) || fail "--copy-missing needs a path."
                (( copy_option_set == 0 )) || fail "--copy-to and --copy-missing cannot both be used."
                [[ -n $2 ]] || fail "--copy-missing needs a path."
                copy_missing_arg=$2
                copy_option_set=1
                shift 2
                ;;
            --log)
                (( $# >= 2 )) || fail "--log needs a file path."
                (( log_option_set == 0 )) || fail "--log was given more than once."
                [[ -n $2 ]] || fail "--log needs a file path."
                log_arg=$2
                log_option_set=1
                shift 2
                ;;
            *)
                fail "Unknown option: $option"
                ;;
        esac
    done
    if (( destination_option_set == 0 )); then
        [[ -n $config_file ]] || fail '--destination is required unless --config is provided.'
    fi
}

parse_args "$@"
if [[ -n $log_arg ]]; then
    log_path=$log_arg
elif (( destination_option_set == 1 )); then
    log_path="$destination_arg/backup.log"
fi
trap finish_run EXIT
trap 'exit 1' HUP INT TERM
[[ -f $env_file ]] || fail "Environment file does not exist: $env_file"
[[ -r $env_file ]] || fail "Environment file is not readable: $env_file"
if [[ -n $config_file ]]; then
    [[ -f $config_file ]] || fail "Config file does not exist: $config_file"
    [[ -r $config_file ]] || fail "Config file is not readable: $config_file"
fi
if (( destination_option_set == 0 )); then
    resolve_configured_destination
fi
[[ -d $destination_arg ]] || fail "Destination directory does not exist: $destination_arg"
destination=$(CDPATH='' cd -- "$destination_arg" && pwd -P) || fail "Cannot use destination directory: $destination_arg"
if [[ -n $log_arg ]]; then
    if [[ $log_path != /* ]]; then
        log_path="$PWD/$log_path"
    fi
else
    log_path="$destination/backup.log"
fi
if ! : >> "$log_path"; then
    fail "Backup log is not writable: $log_path"
fi
[[ -w $destination ]] || fail "Destination directory is not writable: $destination"
(( db_host_option_set == 1 )) || fail "--db-host is required."
[[ -n $db_host_arg ]] || fail "--db-host must not be empty."
require_command mysqldump
require_command awk
require_command chmod
require_command cp
require_command date
require_command find
require_command grep
require_command gzip
require_command mktemp
require_command mv
require_command rm
require_command sha256sum
require_command stat
require_command tar
require_command tr

db_host=$db_host_arg
read_env_value 'database.default.port' optional
[[ -n $env_value ]] && db_port=$env_value
read_env_value 'database.default.database'
db_name=$env_value
read_env_value 'database.default.username'
db_user=$env_value
read_env_value 'database.default.password'
db_password=$env_value
read_env_value 'database.default.DBPrefix' optional
db_prefix=$env_value

validate_identifier "$db_name" 'database.default.database'
validate_port "$db_port"
[[ -n $db_host ]] || fail 'The database host must not be empty.'
[[ -n $db_user ]] || fail 'database.default.username must not be empty.'

if [[ -n $copy_to_arg ]]; then
    [[ -d $copy_to_arg ]] || fail "Copy directory does not exist: $copy_to_arg"
    copy_to_arg=$(CDPATH='' cd -- "$copy_to_arg" && pwd -P) || fail "Cannot use copy directory: $copy_to_arg"
    copy_status='failed'
elif [[ -n $copy_missing_arg ]]; then
    copy_status='missing'
else
    copy_status='none'
fi
resolve_uploads_directory
check_uploads_content

timestamp=$(date -u '+%Y%m%d-%H%M%S')
archive_path="$destination/ospos-backup-$timestamp.tar.gz"
[[ ! -e $archive_path ]] || fail "A backup already exists for this timestamp: $archive_path"

stage_dir=$(mktemp -d "${TMPDIR:-/tmp}/ospos-backup.XXXXXX")
create_defaults_file

if ! run_dump > "$stage_dir/database.sql" 2> "$stage_dir/dump-error"; then
    fail 'Database dump failed.'
fi
[[ -s $stage_dir/database.sql ]] || fail 'Database dump was empty.'

mkdir "$stage_dir/uploads"
cp -a -- "$uploads_dir/." "$stage_dir/uploads/"
read_application_version
read_migration_version
database_sha256=$(sha256sum "$stage_dir/database.sql" | awk '{print $1}')
backup_date=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
{
    printf 'Backup format: 1\n'
    printf 'Backup date (UTC): %s\n' "$backup_date"
    printf 'Application version: %s\n' "$application_version"
    printf 'Database name: %s\n' "$db_name"
    printf 'Migration version: %s\n' "$migration_version"
    printf 'Contents: database.sql, uploads/\n'
    printf 'Database SHA-256: %s\n' "$database_sha256"
} > "$stage_dir/manifest.txt"

archive_tmp=$(mktemp "$destination/.ospos-backup.XXXXXX.tar.gz")
rm -f -- "$archive_tmp"
tar -czf "$archive_tmp" -C "$stage_dir" database.sql manifest.txt uploads
mv -- "$archive_tmp" "$archive_path"
archive_created=1
chmod 600 "$archive_path"
archive_name=${archive_path##*/}
if ! gzip -t "$archive_path"; then
    fail 'Archive gzip verification failed.'
fi
if ! archive_members=$(tar -tzf "$archive_path"); then
    fail 'Archive tar verification failed.'
fi
for required_member in database.sql manifest.txt uploads/; do
    if ! printf '%s\n' "$archive_members" | grep -Fxq "$required_member"; then
        fail "Archive is missing required member: $required_member"
    fi
done
archive_verified=1
archive_size=$(stat -c '%s' "$archive_path")
if (( keep_option_set == 1 )); then
    apply_retention "$destination" "$keep_arg" "$archive_name"
    deleted=$retention_deleted
fi

if [[ -n $copy_to_arg ]]; then
    copy_final="$copy_to_arg/$archive_name"
    copy_temp=''
    source_hash=''
    copy_hash=''
    copy_ok=1
    if [[ -e $copy_final || -L $copy_final ]]; then
        copy_ok=0
    elif ! copy_temp=$(mktemp "$copy_to_arg/.${archive_name}.XXXXXX"); then
        copy_ok=0
    elif ! cp -- "$archive_path" "$copy_temp"; then
        copy_ok=0
    elif ! source_hash=$(sha256sum -- "$archive_path" | awk '{print $1}'); then
        copy_ok=0
    elif ! copy_hash=$(sha256sum -- "$copy_temp" | awk '{print $1}'); then
        copy_ok=0
    elif [[ $source_hash != "$copy_hash" ]]; then
        copy_ok=0
    elif ! mv -- "$copy_temp" "$copy_final"; then
        copy_ok=0
    else
        copy_temp=''
        copy_status='ok'
    fi
    if (( copy_ok == 0 )); then
        if [[ -n $copy_temp && -e $copy_temp ]]; then
            rm -f -- "$copy_temp" || true
        fi
        printf 'Warning: could not verify or write the backup copy.\n' >&2 || true
        copy_status='failed'
    else
        if (( keep_option_set == 1 )); then
            apply_retention "$copy_to_arg" "$keep_arg" "$archive_name"
            copy_deleted=$retention_deleted
        fi
    fi
elif [[ -n $copy_missing_arg ]]; then
    success_message="copy_folder_missing_$copy_missing_arg"
    printf 'Warning: backup copy folder is missing: %s\n' "$copy_missing_arg" >&2 || true
fi

printf '%s (%s bytes)\n' "$archive_path" "$archive_size" || true
