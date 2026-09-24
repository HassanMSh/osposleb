#!/usr/bin/env bash
set -euo pipefail

umask 077

script_dir=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
repo_root=$(dirname -- "$script_dir")
project_name=${COMPOSE_PROJECT_NAME:-$(basename -- "$repo_root")}
network=${OSPOS_DOCKER_NETWORK:-${project_name}_app_net}
db_host=${OSPOS_DB_HOST:-mysql}
data_dir=${OSPOS_DATA_DIR:-C:/OSPOS/Client}
archive_arg=''
uploads_arg=''
env_arg=''
database_arg=''
restore_marker=''
restore_marker_owner=0
restore_marker_preexisting=0
restore_lock_dir=''
restore_lock_acquired=0
yes_flag=0
archive_option_set=0
uploads_option_set=0
env_option_set=0
database_option_set=0

# Stop with a readable launcher error before the restore container runs.
fail() {
    printf 'Error: %s\n' "$1" >&2
    exit 20
}

# Lock a direct restore against shop commands unless ./shop already owns the lock.
acquire_restore_lock() {
    mkdir -p -- "$data_dir" || fail "Could not create the client data directory: $data_dir"
    data_dir=$(CDPATH='' cd -- "$data_dir" && pwd -P) \
        || fail "Cannot use client data directory: $data_dir"
    [[ ${OSPOS_SHOP_LOCK_HELD:-0} == 1 ]] && return 0
    restore_lock_dir="$data_dir/.shop-command.lock"
    if ! mkdir -- "$restore_lock_dir" 2>/dev/null; then
        fail "Another shop command or backup is running. If none is running, remove the lock folder: $restore_lock_dir"
    fi
    restore_lock_acquired=1
    restore_marker_owner=1
    trap release_restore_lock EXIT
    printf '%s\n' "$$" > "$restore_lock_dir/pid" || fail 'Could not write the shop lock file.'
    printf '%s\n' "$(hostname 2>/dev/null || uname -n)" > "$restore_lock_dir/host" || fail 'Could not write the shop lock owner.'
    printf '%s\n' restore > "$restore_lock_dir/command" || fail 'Could not write the shop lock command.'
    printf '%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" > "$restore_lock_dir/started" || fail 'Could not write the shop lock start time.'
    printf '%s\n' "$(date +%s)" > "$restore_lock_dir/started_epoch" || fail 'Could not write the shop lock age.'
}

# Remove a standalone restore's shared client-data lock when it exits.
release_restore_lock() {
    (( restore_lock_acquired == 1 )) || return 0
    rm -f -- "$restore_lock_dir/pid" "$restore_lock_dir/host" "$restore_lock_dir/command" \
        "$restore_lock_dir/started" "$restore_lock_dir/started_epoch"
    rmdir -- "$restore_lock_dir" 2>/dev/null || true
    restore_lock_acquired=0
}

# Refuse direct restores unless ./shop explicitly marked this launcher call as rollback recovery.
refuse_recovery_markers() {
    local line update_pending=0 recovery_command='./shop update'
    if [[ ${OSPOS_SHOP_LOCK_HELD:-0} == 1 && ${OSPOS_RESTORE_FOR_ROLLBACK:-0} == 1 ]]; then
        return 0
    fi
    if [[ -f $data_dir/rollback.conf ]]; then
        while IFS= read -r line || [[ -n $line ]]; do
            [[ $line != UPDATE_PENDING=1 ]] || update_pending=1
        done < "$data_dir/rollback.conf"
    fi
    if [[ -e $data_dir/rollback.unfinished ]]; then
        printf 'Error: A rollback did not finish, so the database state is unknown.\nRecovery command: ./shop rollback\n' >&2
        exit 20
    fi
    if [[ -e $data_dir/update.in-progress ]] || (( update_pending == 1 )); then
        if (( update_pending == 1 )); then
            recovery_command='./shop rollback'
        elif [[ -e $data_dir/restore.unfinished ]]; then
            recovery_command='./shop status'
        fi
        printf 'Error: Restore is refused while an update is unfinished.\nNothing was changed. Safe next step: %s\n' "$recovery_command" >&2
        exit 20
    fi
}

# Create the recovery marker for a direct restore while its lock is held.
write_restore_marker() {
    (( restore_marker_owner == 1 )) || return 0
    restore_marker="$data_dir/restore.unfinished"
    if [[ -e $restore_marker ]]; then
        restore_marker_preexisting=1
        return 0
    fi
    marker_temporary=$(mktemp "$data_dir/restore.unfinished.XXXXXX") \
        || fail 'Could not create the restore recovery marker.'
    if ! : > "$marker_temporary"; then
        rm -f -- "$marker_temporary"
        fail 'Could not write the restore recovery marker.'
    fi
    if ! mv -f -- "$marker_temporary" "$restore_marker"; then
        rm -f -- "$marker_temporary"
        fail 'Could not save the restore recovery marker.'
    fi
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
acquire_restore_lock
refuse_recovery_markers
[[ -n $uploads_arg ]] || uploads_arg="$data_dir/uploads"
[[ -n $env_arg ]] || env_arg="$data_dir/secrets/app.env"
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
    --platform linux
)

if (( database_option_set == 1 )); then
    docker_args+=(--database "$database_arg")
fi
if (( yes_flag == 1 )); then
    docker_args+=(--yes)
fi

write_restore_marker

# Rewrite the uploads path in streamed output and retain the Docker status for phase mapping.
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
if (( docker_status == 20 || docker_status == 21 )); then
    if (( docker_status == 20 && restore_marker_owner == 1 && restore_marker_preexisting == 0 )); then
        rm -f -- "$data_dir/restore.unfinished" || {
            printf 'Error: Restore failed before database import, but its recovery marker could not be cleared.\n' >&2
            exit 21
        }
    fi
    exit "$docker_status"
fi
if (( docker_status != 0 )); then
    printf 'Error: Restore could not be confirmed safe; the database may be partial.\n' >&2
    exit 21
fi
if (( restore_marker_owner == 1 )); then
    rm -f -- "$data_dir/restore.unfinished" || {
        printf 'Error: Restore succeeded, but its recovery marker could not be cleared.\n' >&2
        exit 21
    }
fi
exit 0
