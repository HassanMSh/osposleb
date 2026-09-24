#!/usr/bin/env bash
set -euo pipefail

umask 077

script_dir=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
repo_root=$(dirname -- "$script_dir")
data_arg=''
locked_directory=0
client_entry=''
client_entries=()

# Stop with a readable launcher error.
fail() {
    printf 'Error: %s\n' "$1" >&2
    exit 1
}

# Print the Linux setup command help.
show_help() {
    cat <<'HELP'
Usage: scripts/setup-client.sh --data-directory <directory> [--locked-directory]

Create a new client configuration directory.

The directory must not already exist unless --locked-directory is used while the shop command lock is held.
Docker is the only host dependency.
HELP
}

while (( $# > 0 )); do
    case $1 in
        --help|-h)
            show_help
            exit 0
            ;;
        --data-directory)
            (( $# >= 2 )) || fail '--data-directory needs a directory.'
            [[ -z $data_arg ]] || fail '--data-directory was given more than once.'
            data_arg=$2
            shift 2
            ;;
        --locked-directory)
            (( locked_directory == 0 )) || fail '--locked-directory was given more than once.'
            locked_directory=1
            shift
            ;;
        *)
            fail "Unknown option: $1"
            ;;
    esac
done

[[ -n $data_arg ]] || fail '--data-directory is required.'

if [[ $data_arg != /* ]]; then
    data_arg="$PWD/$data_arg"
fi
data_parent=$(dirname -- "$data_arg")
data_name=$(basename -- "$data_arg")
[[ -n $data_name && $data_name != . && $data_name != .. ]] || fail "Invalid data directory: $data_arg"
mkdir -p -- "$data_parent"
data_parent=$(CDPATH= cd -- "$data_parent" && pwd -P) || fail "Cannot use data directory parent: $data_arg"

if (( locked_directory == 1 )); then
    [[ -d $data_arg && ! -L $data_arg ]] || fail "Locked client directory is not available: $data_arg"
    [[ -d $data_arg/.shop-command.lock && ! -L $data_arg/.shop-command.lock && -f $data_arg/.shop-command.lock/pid ]] \
        || fail 'The shop command lock is missing from the client directory.'
    shopt -s dotglob nullglob
    client_entries=("$data_arg"/*)
    shopt -u dotglob nullglob
    (( ${#client_entries[@]} == 1 )) && [[ ${client_entries[0]} == "$data_arg/.shop-command.lock" ]] \
        || fail "Refusing to set up a client directory with files other than the shop command lock: $data_arg"
elif [[ -e $data_arg || -L $data_arg ]]; then
    fail "Refusing to run against an existing installation: $data_arg"
fi

docker_args=(
    run --rm
    --user "$(id -u):$(id -g)"
    --mount "type=bind,source=$repo_root,target=/work,readonly"
    --mount "type=bind,source=$data_parent,target=/client-parent"
    --entrypoint bash
    mariadb:10.5
    /work/scripts/container/setup-client.sh
    --data-directory "/client-parent/$data_name"
    --host-data-directory "$data_arg"
    --platform linux
)
if (( locked_directory == 1 )); then docker_args+=(--locked-directory); fi

exec docker "${docker_args[@]}"
