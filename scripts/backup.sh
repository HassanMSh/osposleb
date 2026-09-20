#!/usr/bin/env bash
set -euo pipefail

umask 077

script_dir=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
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
destination_option_set=0
uploads_option_set=0
env_option_set=0

# Stop with a readable launcher error.
fail() {
    printf 'Error: %s\n' "$1" >&2
    exit 1
}

# Read the client backup destination without executing the config file.
read_configured_destination() {
    local line raw pattern
    local matches=0

    config_value=''
    pattern='^[[:space:]]*OSPOS_BACKUP_DESTINATION[[:space:]]*=[[:space:]]*(.*)$'
    while IFS= read -r line || [[ -n "$line" ]]; do
        if [[ $line =~ $pattern ]]; then
            matches=$((matches + 1))
            raw=${BASH_REMATCH[1]}
            raw="${raw#"${raw%%[![:space:]]*}"}"
            raw="${raw%"${raw##*[![:space:]]}"}"

            if [[ ${raw:0:1} == "'" ]]; then
                [[ ${#raw} -ge 2 && ${raw: -1} == "'" ]] || fail "Invalid OSPOS_BACKUP_DESTINATION in $config_arg."
                config_value=${raw:1:${#raw}-2}
            elif [[ ${raw:0:1} == '"' ]]; then
                [[ ${#raw} -ge 2 && ${raw: -1} == '"' ]] || fail "Invalid OSPOS_BACKUP_DESTINATION in $config_arg."
                config_value=${raw:1:${#raw}-2}
            else
                raw=${raw%%#*}
                raw="${raw#"${raw%%[![:space:]]*}"}"
                raw="${raw%"${raw##*[![:space:]]}"}"
                config_value=$raw
            fi
        fi
    done < "$config_arg"

    (( matches == 1 )) || fail "The config file must contain one value for OSPOS_BACKUP_DESTINATION."
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
    data_dir=$(CDPATH= cd -- "$data_dir" && pwd -P) || fail "Cannot use client data directory: $data_dir"
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
if [[ $uploads_arg != /* ]]; then
    uploads_arg="$repo_root/$uploads_arg"
fi
[[ -d $uploads_arg ]] || fail "Uploads directory does not exist: $uploads_arg"
[[ -f $env_arg ]] || fail "Environment file does not exist: $env_arg"
[[ -r $env_arg ]] || fail "Environment file is not readable: $env_arg"
if [[ -z $destination_arg ]]; then
    [[ -f $config_arg ]] || fail "Config file does not exist: $config_arg"
    [[ -r $config_arg ]] || fail "Config file is not readable: $config_arg"
    read_configured_destination
    [[ -n $config_value ]] || fail 'OSPOS_BACKUP_DESTINATION must not be empty.'
    [[ $config_value != /* ]] || fail 'OSPOS_BACKUP_DESTINATION must be relative to the client directory.'
    case $config_value in
        .|..|*../*|*/..|*'/..'*)
            fail 'OSPOS_BACKUP_DESTINATION contains an unsafe path.'
            ;;
    esac
    config_destination="$data_dir/$config_value"
    [[ -d $config_destination ]] || fail "Configured backup destination does not exist: $config_destination"
    config_destination=$(CDPATH= cd -- "$config_destination" && pwd -P) \
        || fail "Cannot use configured backup destination: $config_destination"
fi
if [[ -n $destination_arg ]]; then
    [[ -d $destination_arg ]] || fail "Destination directory does not exist: $destination_arg"
fi

if [[ -n $destination_arg ]]; then
    destination=$(CDPATH= cd -- "$destination_arg" && pwd -P) \
        || fail "Cannot use destination directory: $destination_arg"
fi
uploads_parent=$(CDPATH= cd -- "$(dirname -- "$uploads_arg")" && pwd -P) \
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
fi
if [[ -n $data_dir && -z $destination_arg ]]; then
    docker_args+=(
        --mount "type=bind,source=$config_arg,target=/client/ospos.conf,readonly"
        --mount "type=bind,source=$config_destination,target=/client/$config_value"
    )
fi

docker_args+=(
    --entrypoint bash
    mariadb:10.5
    /work/scripts/container/backup.sh
)
if [[ -n $destination_arg ]]; then
    docker_args+=(--destination /destination)
else
    docker_args+=(--config /client/ospos.conf)
fi
docker_args+=(
    --uploads "/uploads-parent/$uploads_name"
    --env /client.env
    --db-host "$db_host"
)

# Rewrite the container mount path in streamed success output while preserving the Docker status.
if docker "${docker_args[@]}" | while IFS= read -r line || [[ -n $line ]]; do
    case $line in
        /destination/*)
            printf '%s/%s\n' "${destination%/}" "${line#/destination/}"
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
exit "$docker_status"
