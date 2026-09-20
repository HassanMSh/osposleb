#!/usr/bin/env bash
set -euo pipefail

umask 077

script_dir=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
repo_root=$(dirname -- "$script_dir")
project_name=${COMPOSE_PROJECT_NAME:-$(basename -- "$repo_root")}
network=${OSPOS_DOCKER_NETWORK:-${project_name}_app_net}
db_host=${OSPOS_DB_HOST:-mysql}
destination_arg=''
uploads_arg="$repo_root/public/uploads"
env_arg="$repo_root/.env"
destination_option_set=0
uploads_option_set=0
env_option_set=0

# Stop with a readable launcher error.
fail() {
    printf 'Error: %s\n' "$1" >&2
    exit 1
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

[[ -n $destination_arg ]] || fail '--destination is required.'
if [[ $uploads_arg != /* ]]; then
    uploads_arg="$repo_root/$uploads_arg"
fi
[[ -d $destination_arg ]] || fail "Destination directory does not exist: $destination_arg"
[[ -d $uploads_arg ]] || fail "Uploads directory does not exist: $uploads_arg"
[[ -f $env_arg ]] || fail "Environment file does not exist: $env_arg"
[[ -r $env_arg ]] || fail "Environment file is not readable: $env_arg"

destination=$(CDPATH= cd -- "$destination_arg" && pwd -P) \
    || fail "Cannot use destination directory: $destination_arg"
uploads_parent=$(CDPATH= cd -- "$(dirname -- "$uploads_arg")" && pwd -P) \
    || fail "Cannot use uploads directory: $uploads_arg"
uploads_name=$(basename -- "$uploads_arg")
env_parent=$(CDPATH= cd -- "$(dirname -- "$env_arg")" && pwd -P) \
    || fail "Cannot use environment file: $env_arg"
env_name=$(basename -- "$env_arg")

docker_args=(
    run --rm
    --user "$(id -u):$(id -g)"
    --network "$network"
    --mount "type=bind,source=$repo_root,target=/work,readonly"
    --mount "type=bind,source=$destination,target=/destination"
    --mount "type=bind,source=$uploads_parent,target=/uploads-parent,readonly"
    --mount "type=bind,source=$env_parent,target=/env-parent,readonly"
    --entrypoint bash
    mariadb:10.5
    /work/scripts/container/backup.sh
    --destination /destination
    --uploads "/uploads-parent/$uploads_name"
    --env "/env-parent/$env_name"
    --db-host "$db_host"
)

# Rewrite the container mount path in streamed success output while preserving the Docker status.
if docker "${docker_args[@]}" | while IFS= read -r line || [[ -n $line ]]; do
    case $line in
        /destination/*)
            printf '%s/%s\n' "${destination%/}" "${line#/destination/}"
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
