#!/usr/bin/env bash
set -euo pipefail

umask 077

script_dir=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
repo_root=$(dirname -- "$script_dir")
project_name=${COMPOSE_PROJECT_NAME:-$(basename -- "$repo_root")}
network=${OSPOS_DOCKER_NETWORK:-${project_name}_app_net}
db_host=${OSPOS_DB_HOST:-mysql}
data_dir=${OSPOS_DATA_DIR:-}
archive_arg=''
uploads_arg=''
env_arg=''
database_arg=''
yes_flag=0
archive_option_set=0
uploads_option_set=0
env_option_set=0
database_option_set=0

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
                /work/scripts/container/restore.sh --help --db-host "$db_host"
            ;;
        --archive)
            (( $# >= 2 )) || fail '--archive needs a file path.'
            (( archive_option_set == 0 )) || fail '--archive was given more than once.'
            archive_arg=$2
            archive_option_set=1
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
            fail "Unknown option: $1"
            ;;
    esac
done

[[ -n $archive_arg ]] || fail '--archive is required.'
if [[ -n $data_dir ]]; then
    [[ -d $data_dir ]] || fail "Client data directory does not exist: $data_dir"
    data_dir=$(CDPATH= cd -- "$data_dir" && pwd -P) || fail "Cannot use client data directory: $data_dir"
    [[ -n $uploads_arg ]] || uploads_arg="$data_dir/uploads"
    [[ -n $env_arg ]] || env_arg="$data_dir/secrets/app.env"
else
    [[ -n $uploads_arg ]] || uploads_arg="$repo_root/public/uploads"
    [[ -n $env_arg ]] || env_arg="$repo_root/.env"
fi
if [[ $uploads_arg != /* ]]; then
    uploads_arg="$repo_root/$uploads_arg"
fi
[[ -f $archive_arg ]] || fail "Archive file does not exist: $archive_arg"
[[ -r $archive_arg ]] || fail "Archive file is not readable: $archive_arg"
[[ -d $uploads_arg ]] || fail "Uploads directory does not exist: $uploads_arg"
[[ -f $env_arg ]] || fail "Environment file does not exist: $env_arg"
[[ -r $env_arg ]] || fail "Environment file is not readable: $env_arg"

archive_parent=$(CDPATH= cd -- "$(dirname -- "$archive_arg")" && pwd -P) \
    || fail "Cannot use archive file: $archive_arg"
archive_name=$(basename -- "$archive_arg")
uploads=$(CDPATH= cd -- "$uploads_arg" && pwd -P) \
    || fail "Cannot use uploads directory: $uploads_arg"
uploads_parent=$(CDPATH= cd -- "$(dirname -- "$uploads")" && pwd -P) \
    || fail "Cannot use uploads directory: $uploads_arg"
uploads_name=$(basename -- "$uploads")
docker_args=(
    run --rm
    --user "$(id -u):$(id -g)"
    --network "$network"
    --mount "type=bind,source=$repo_root,target=/work,readonly"
    --mount "type=bind,source=$archive_parent,target=/archive-parent,readonly"
    --mount "type=bind,source=$uploads_parent,target=/uploads-parent"
    --mount "type=bind,source=$env_arg,target=/client.env,readonly"
    --entrypoint bash
)

if [[ -t 0 && -t 1 ]]; then
    docker_args+=(--interactive --tty)
fi

docker_args+=(
    mariadb:10.5
    /work/scripts/container/restore.sh
    --archive "/archive-parent/$archive_name"
    --uploads "/uploads-parent/$uploads_name"
    --env /client.env
    --db-host "$db_host"
)

if (( database_option_set == 1 )); then
    docker_args+=(--database "$database_arg")
fi
if (( yes_flag == 1 )); then
    docker_args+=(--yes)
fi

# Rewrite the container uploads path in streamed success output while preserving the Docker status.
if docker "${docker_args[@]}" | while IFS= read -r line || [[ -n $line ]]; do
    case $line in
        'Restored uploads: /uploads-parent/'*)
            printf 'Restored uploads: %s\n' "$uploads"
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
