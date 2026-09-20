#!/usr/bin/env bash
set -euo pipefail

umask 077

platform=''
data_dir=''
host_data_dir=''

# Print the setup command help.
show_help() {
    cat <<'HELP'
Usage: scripts/container/setup-client.sh --data-directory <path> --host-data-directory <path> --platform <linux|windows>

Create one client configuration directory.

The target directory must not already exist. Database files are not created here;
the client Compose override keeps them in a Docker named volume.
HELP
}

# Stop with a readable setup error.
fail() {
    printf 'Error: %s\n' "$1" >&2
    exit 1
}

# Generate a hexadecimal secret inside the setup container.
generate_secret() {
    od -An -N32 -tx1 /dev/urandom | tr -d ' \n'
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
            --data-directory)
                (( $# >= 2 )) || fail '--data-directory needs a directory.'
                [[ -z $data_dir ]] || fail '--data-directory was given more than once.'
                data_dir=$2
                shift 2
                ;;
            --host-data-directory)
                (( $# >= 2 )) || fail '--host-data-directory needs a directory.'
                [[ -z $host_data_dir ]] || fail '--host-data-directory was given more than once.'
                host_data_dir=$2
                shift 2
                ;;
            --platform)
                (( $# >= 2 )) || fail '--platform needs linux or windows.'
                [[ -z $platform ]] || fail '--platform was given more than once.'
                platform=$2
                shift 2
                ;;
            *)
                fail "Unknown option: $option"
                ;;
        esac
    done

    [[ -n $data_dir ]] || fail '--data-directory is required.'
    [[ -n $host_data_dir ]] || fail '--host-data-directory is required.'
    [[ $platform == linux || $platform == windows ]] || fail '--platform must be linux or windows.'
}

# Print the generated layout without printing any secret values.
print_layout() {
    printf 'Created client configuration directory: %s\n' "$host_data_dir"
    printf 'Created: %s\n' "$host_data_dir/ospos.conf"
    printf 'Created: %s\n' "$host_data_dir/secrets/app.env"
    printf 'Created: %s\n' "$host_data_dir/secrets/db.env"
    printf 'Created: %s\n' "$host_data_dir/uploads"
    printf 'Created: %s\n' "$host_data_dir/backups"
    if [[ $platform == linux ]]; then
        printf 'Protected secrets: mode 600\n'
    else
        printf 'Windows secret protection: the host launcher will apply NTFS permissions.\n'
    fi
    printf 'Database storage: Docker named volume only\n'
}

parse_args "$@"

[[ ! -e $data_dir && ! -L $data_dir ]] || fail "Refusing to run against an existing installation: $host_data_dir"

mkdir -p "$data_dir/secrets" "$data_dir/uploads" "$data_dir/backups"

db_password=$(generate_secret)
encryption_key=$(generate_secret)
[[ ${#db_password} -eq 64 ]] || fail 'Could not generate a database password.'
[[ ${#encryption_key} -eq 64 ]] || fail 'Could not generate an application encryption key.'

cat > "$data_dir/secrets/db.env" <<EOF
MYSQL_ROOT_PASSWORD=$db_password
MYSQL_DATABASE=ospos
MYSQL_USER=admin
MYSQL_PASSWORD=$db_password
EOF

cat > "$data_dir/secrets/app.env" <<EOF
CI_ENVIRONMENT=production
CI_DEBUG=false
PHP_TIMEZONE=UTC
FORCE_HTTPS=false

database.default.hostname=mysql
database.default.database=ospos
database.default.username=admin
database.default.password=$db_password
database.default.DBDriver=MySQLi
database.default.DBPrefix=ospos_
database.default.port=3306

MYSQL_USERNAME=admin
MYSQL_PASSWORD=$db_password
MYSQL_DB_NAME=ospos
MYSQL_HOST_NAME=mysql

encryption.key=$encryption_key
EOF

cat > "$data_dir/ospos.conf" <<EOF
OSPOS_DATA_DIR='$host_data_dir'
OSPOS_BACKUP_DESTINATION='backups'
EOF

chmod 700 "$data_dir/secrets"
chmod 600 "$data_dir/secrets/app.env" "$data_dir/secrets/db.env"
chmod 777 "$data_dir/uploads"

print_layout
