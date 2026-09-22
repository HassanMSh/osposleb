#!/usr/bin/env bash
set -euo pipefail

umask 077

script_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
repo_root=$(dirname -- "$script_dir")
project_name=${COMPOSE_PROJECT_NAME:-$(basename -- "$repo_root")}
network=${OSPOS_DOCKER_NETWORK:-${project_name}_app_net}
db_host=${OSPOS_DB_HOST:-mysql}
data_dir=${OSPOS_DATA_DIR:-}
destination_arg=''
uploads_arg=''
env_arg=''
config_arg=''
config_value=''
config_destination=''
config_destination_name=''
config_matches=0
configured_keep=7
configured_copy_to=''
copy_directory=''
destination=''
log_file=''
launcher_failure_logged=0
destination_option_set=0
uploads_option_set=0
env_option_set=0

# Stop with a readable launcher error.
fail() {
    if [[ -n $log_file ]] && (( launcher_failure_logged == 0 )); then
        append_failure_log "$1"
    fi
    printf 'Error: %s\n' "$1" >&2
    exit 1
}

# Read one client setting without executing the config file.
read_config_value() {
    local key=$1 required=$2
    local line raw pattern

    config_value=''
    config_matches=0
    pattern="^[[:space:]]*${key//./\\.}[[:space:]]*=[[:space:]]*(.*)$"
    while IFS= read -r line || [[ -n "$line" ]]; do
        if [[ $line =~ $pattern ]]; then
            config_matches=$((config_matches + 1))
            raw=${BASH_REMATCH[1]}
            raw="${raw#"${raw%%[![:space:]]*}"}"
            raw="${raw%"${raw##*[![:space:]]}"}"

            if [[ ${raw:0:1} == "'" ]]; then
                [[ ${#raw} -ge 2 && ${raw: -1} == "'" ]] || fail "Invalid $key in $config_arg."
                config_value=${raw:1:${#raw}-2}
            elif [[ ${raw:0:1} == '"' ]]; then
                [[ ${#raw} -ge 2 && ${raw: -1} == '"' ]] || fail "Invalid $key in $config_arg."
                config_value=${raw:1:${#raw}-2}
            else
                raw=${raw%%#*}
                raw="${raw#"${raw%%[![:space:]]*}"}"
                raw="${raw%"${raw##*[![:space:]]}"}"
                config_value=$raw
            fi
        fi
    done < "$config_arg"

    (( config_matches <= 1 )) || fail "The config file contains more than one value for $key."
    if [[ $required == required && $config_matches != 1 ]]; then
        fail "The config file must contain one value for $key."
    fi
}

# Read and validate the client backup destination.
read_client_backup_destination() {
    read_config_value OSPOS_BACKUP_DESTINATION required
    [[ -n $config_value ]] || fail 'OSPOS_BACKUP_DESTINATION must not be empty.'
    [[ $config_value != /* ]] || fail 'OSPOS_BACKUP_DESTINATION must be relative to the client directory.'
    case $config_value in
        .|..|*../*|*/..|*'/..'*)
            fail 'OSPOS_BACKUP_DESTINATION contains an unsafe path.'
            ;;
    esac
    config_destination_name=$config_value
    config_destination="$data_dir/$config_value"
}

# Read and validate client retention and copy settings after choosing the log path.
read_client_backup_options() {
    read_config_value OSPOS_BACKUP_KEEP optional
    if (( config_matches == 1 )); then
        [[ $config_value =~ ^[0-9]+$ ]] || fail 'OSPOS_BACKUP_KEEP must be a non-negative integer.'
        configured_keep=$config_value
    fi

    read_config_value OSPOS_BACKUP_COPY_TO optional
    if (( config_matches == 1 )); then
        configured_copy_to=$config_value
        if [[ -n $configured_copy_to && $configured_copy_to != /* ]]; then
            fail 'OSPOS_BACKUP_COPY_TO must be an absolute Linux path.'
        fi
    fi
}

# Append one host-side launcher failure line to the selected backup log.
append_failure_log() {
    local reason=$1 timestamp
    (( launcher_failure_logged == 0 )) || return 0
    launcher_failure_logged=1
    reason=$(printf '%s' "$reason" | tr -cs '[:alnum:]_.:-' '_')
    timestamp=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
    printf '%s result=failed archive=- size=- deleted=0 copy=- copy_deleted=0 message=%s\n' \
        "$timestamp" "$reason" >> "$log_file" 2>/dev/null || true
}

while (( $# > 0 )); do
    case $1 in
        --help|-h)
            exec docker run --rm \
                --user "$(id -u):$(id -g)" \
                --network "$network" \
                --mount "type=bind,source=$repo_root,target=/work,readonly" \
                --entrypoint bash mariadb:10.5 \
                /work/scripts/container/backup.sh --help --db-host "$db_host"
            ;;
        --destination)
            (( $# >= 2 )) || fail '--destination needs a directory.'
            (( destination_option_set == 0 )) || fail '--destination was given more than once.'
            destination_arg=$2
            destination_option_set=1
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
            env_arg=$2
            env_option_set=1
            shift 2
            ;;
        *)
            fail "Unknown option: $1"
            ;;
    esac
done

if [[ -n $data_dir ]]; then
    [[ -d $data_dir ]] || fail "Client data directory does not exist: $data_dir"
    data_dir=$(CDPATH='' cd -- "$data_dir" && pwd -P) || fail "Cannot use client data directory: $data_dir"
    [[ -n $uploads_arg ]] || uploads_arg="$data_dir/uploads"
    [[ -n $env_arg ]] || env_arg="$data_dir/secrets/app.env"
    config_arg="$data_dir/ospos.conf"
else
    [[ -n $destination_arg ]] || fail '--destination is required unless OSPOS_DATA_DIR is set.'
    [[ -n $uploads_arg ]] || uploads_arg="$repo_root/public/uploads"
    [[ -n $env_arg ]] || env_arg="$repo_root/.env"
fi
if [[ -z $destination_arg && -z $data_dir ]]; then
    fail '--destination is required unless OSPOS_DATA_DIR is set.'
fi
if [[ -n $data_dir ]]; then
    # Until ospos.conf is read, log config errors to the default client backups folder when it exists.
    [[ -d $data_dir/backups ]] && log_file="$data_dir/backups/backup.log"
    [[ -f $config_arg ]] || fail "Config file does not exist: $config_arg"
    [[ -r $config_arg ]] || fail "Config file is not readable: $config_arg"
    read_client_backup_destination
    [[ -d $config_destination ]] || fail "Configured backup destination does not exist: $config_destination"
    config_destination=$(CDPATH='' cd -- "$config_destination" && pwd -P) \
        || fail "Cannot use configured backup destination: $config_destination"
    destination=$config_destination
else
    [[ -d $destination_arg ]] || fail "Destination directory does not exist: $destination_arg"
    destination=$(CDPATH='' cd -- "$destination_arg" && pwd -P) \
        || fail "Cannot use destination directory: $destination_arg"
fi
if [[ -n $data_dir ]]; then
    log_file="$config_destination/backup.log"
else
    log_file="$destination/backup.log"
fi
if [[ -n $data_dir ]]; then
    read_client_backup_options
fi
if [[ $uploads_arg != /* ]]; then
    uploads_arg="$repo_root/$uploads_arg"
fi
[[ -d $uploads_arg ]] || fail "Uploads directory does not exist: $uploads_arg"
[[ -f $env_arg ]] || fail "Environment file does not exist: $env_arg"
[[ -r $env_arg ]] || fail "Environment file is not readable: $env_arg"
if [[ -n $destination_arg ]]; then
    [[ -d $destination_arg ]] || fail "Destination directory does not exist: $destination_arg"
    destination=$(CDPATH='' cd -- "$destination_arg" && pwd -P) \
        || fail "Cannot use destination directory: $destination_arg"
fi
uploads_parent=$(CDPATH='' cd -- "$(dirname -- "$uploads_arg")" && pwd -P) \
    || fail "Cannot use uploads directory: $uploads_arg"
uploads_name=$(basename -- "$uploads_arg")
docker_args=(
    run --rm
    --user "$(id -u):$(id -g)"
    --network "$network"
    --mount "type=bind,source=$repo_root,target=/work,readonly"
    --mount "type=bind,source=$uploads_parent,target=/uploads-parent,readonly"
    --mount "type=bind,source=$env_arg,target=/client.env,readonly"
)

if [[ -n $destination_arg ]]; then
    docker_args+=(--mount "type=bind,source=$destination,target=/destination")
    if [[ -n $data_dir ]]; then
        docker_args+=(--mount "type=bind,source=$config_destination,target=/log-destination")
    fi
fi
if [[ -n $data_dir && -z $destination_arg ]]; then
    docker_args+=(
        --mount "type=bind,source=$config_arg,target=/client/ospos.conf,readonly"
        --mount "type=bind,source=$config_destination,target=/client/$config_destination_name"
    )
fi
if [[ -n $data_dir && -n $configured_copy_to && -d $configured_copy_to ]]; then
    copy_directory=$(CDPATH='' cd -- "$configured_copy_to" && pwd -P) \
        || fail "Cannot use copy directory: $configured_copy_to"
    docker_args+=(--mount "type=bind,source=$copy_directory,target=/copy")
fi

docker_args+=(
    --entrypoint bash
    mariadb:10.5
    /work/scripts/container/backup.sh
)
if [[ -n $destination_arg ]]; then
    docker_args+=(--destination /destination)
    if [[ -n $data_dir ]]; then
        docker_args+=(--log /log-destination/backup.log)
    fi
else
    docker_args+=(--config /client/ospos.conf)
fi
docker_args+=(
    --uploads "/uploads-parent/$uploads_name"
    --env /client.env
    --db-host "$db_host"
)
if [[ -n $data_dir ]]; then
    docker_args+=(--keep "$configured_keep")
    if [[ -n $configured_copy_to ]]; then
        if [[ -n $copy_directory ]]; then
            docker_args+=(--copy-to /copy)
        else
            docker_args+=(--copy-missing "$configured_copy_to")
        fi
    fi
fi

if ! docker info >/dev/null 2>&1; then
    append_failure_log Docker_is_not_running
    fail 'Docker is not running or cannot be reached.'
fi

# Rewrite the container mount path in streamed success output while preserving the Docker status.
if docker "${docker_args[@]}" | while IFS= read -r line || [[ -n $line ]]; do
    case $line in
        /destination/*)
            printf '%s/%s\n' "${destination%/}" "${line#/destination/}"
            ;;
        /copy/*)
            printf '%s/%s\n' "${copy_directory%/}" "${line#/copy/}"
            ;;
        /client/*)
            printf '%s/%s\n' "${data_dir%/}" "${line#/client/}"
            ;;
        *)
            printf '%s\n' "$line"
            ;;
    esac
done; then
    docker_status=${PIPESTATUS[0]}
else
    docker_status=${PIPESTATUS[0]}
fi
if (( docker_status != 0 )); then
    if (( docker_status != 1 )); then
        append_failure_log "Backup_container_failed_exit_$docker_status"
    fi
fi
exit "$docker_status"
