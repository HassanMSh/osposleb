#!/usr/bin/env bash
set -euo pipefail

source_root=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
test_tmp_dir=${TMPDIR:-$PWD}
test_root=$(mktemp -d "$test_tmp_dir/shop-command-test.XXXXXX") || {
    printf 'Could not create a temporary test folder under TMPDIR or HOME.\n' >&2
    exit 1
}
real_git=$(type -P git)
real_uname=$(type -P uname)
trap cleanup EXIT

# Remove the throwaway repositories and stub commands after the test run.
cleanup() {
    rm -rf -- "$test_root"
}

# Create a throwaway checkout with a local bare origin, fake Docker, and launcher stubs.
make_case() {
    local name=$1
    case_root="$test_root/$name"
    seed_repo="$case_root/seed"
    origin="$case_root/origin.git"
    checkout="$case_root/checkout"
    data_dir="$case_root/client"
    state_dir="$case_root/state"
    bin_dir="$case_root/bin"
    event_file="$case_root/events.log"
    docker_log="$case_root/docker.log"
    output_file="$case_root/output.log"
    base_commit=''
    middle_commit=''
    latest_commit=''
    old_image_id="sha256:$(printf '1%.0s' {1..64})"
    new_image_id="sha256:$(printf '2%.0s' {1..64})"
    digest_old="sha256:$(printf '3%.0s' {1..64})"
    digest_new="sha256:$(printf '4%.0s' {1..64})"
    remote_digest=$digest_new
    mkdir -p -- "$case_root" "$state_dir" "$bin_dir"
    "$real_git" init --bare --initial-branch=develop "$origin" >/dev/null
    "$real_git" init --initial-branch=develop "$seed_repo" >/dev/null
    "$real_git" -C "$seed_repo" config user.name 'Shop Test'
    "$real_git" -C "$seed_repo" config user.email 'shop-test@example.invalid'
    cp -- "$source_root/shop" "$seed_repo/shop"
    chmod +x "$seed_repo/shop"
    mkdir -p -- "$seed_repo/scripts"
    touch "$seed_repo/docker-compose.yml" "$seed_repo/docker-compose.client.yml"
    printf 'initial\n' > "$seed_repo/version.txt"
    write_launcher_stubs "$seed_repo/scripts"
    "$real_git" -C "$seed_repo" add shop scripts docker-compose.yml docker-compose.client.yml version.txt
    "$real_git" -C "$seed_repo" commit -m 'initial test version' >/dev/null
    "$real_git" -C "$seed_repo" remote add origin "$origin"
    "$real_git" -C "$seed_repo" push -u origin develop >/dev/null 2>&1
    base_commit=$("$real_git" -C "$seed_repo" rev-parse HEAD)
    "$real_git" clone "$origin" "$checkout" >/dev/null 2>&1
    "$real_git" -C "$seed_repo" config user.name 'Shop Test'
    "$real_git" -C "$seed_repo" config user.email 'shop-test@example.invalid'
    printf 'middle\n' > "$seed_repo/version.txt"
    "$real_git" -C "$seed_repo" commit -am 'middle test version' >/dev/null
    middle_commit=$("$real_git" -C "$seed_repo" rev-parse HEAD)
    "$real_git" -C "$seed_repo" push origin develop >/dev/null 2>&1
    printf 'latest\n' > "$seed_repo/version.txt"
    "$real_git" -C "$seed_repo" commit -am 'latest test version' >/dev/null
    latest_commit=$("$real_git" -C "$seed_repo" rev-parse HEAD)
    "$real_git" -C "$seed_repo" push origin develop >/dev/null 2>&1
    write_stub_commands
    prepare_client_data
}

# Write a backup stub and copy the real restore launcher into the throwaway checkout.
write_launcher_stubs() {
    local script_dir=$1
    cat > "$script_dir/backup.sh" <<'BACKUP'
#!/usr/bin/env bash
set -euo pipefail
printf 'BACKUP\n' >> "$EVENTS"
[[ ${BACKUP_FAIL:-0} != 1 ]] || exit 1
count_file="$OSPOS_DATA_DIR/.backup-stub-count"
count=0
[[ ! -f $count_file ]] || count=$(<"$count_file")
count=$((count + 1))
printf '%s\n' "$count" > "$count_file"
archive=$(printf 'ospos-backup-20260924-%06d.tar.gz' "$((10100 + count))")
stage="$OSPOS_DATA_DIR/.backup-stub"
mkdir -p -- "$stage/uploads/item_pics" "$OSPOS_DATA_DIR/backups"
cp -- "$OSPOS_DATA_DIR/db-state.txt" "$stage/database.sql"
database_checksum=$(sha256sum "$stage/database.sql")
database_checksum=${database_checksum%% *}
cat > "$stage/manifest.txt" <<MANIFEST
Backup format: 1
Database name: ospos
Contents: database.sql, uploads/
Database SHA-256: $database_checksum
MANIFEST
tar -czf "$OSPOS_DATA_DIR/backups/$archive" -C "$stage" database.sql manifest.txt uploads
printf '2026-09-24T07:17:21Z result=ok archive=%s size=1 deleted=0 copy=%s copy_deleted=0 message=ok\n' "$archive" "${BACKUP_COPY_VALUE:-none}" \
    >> "$OSPOS_DATA_DIR/backups/backup.log"
rm -rf -- "$stage"
BACKUP
    cp -- "$source_root/scripts/restore.sh" "$script_dir/restore.sh"
    chmod +x "$script_dir/backup.sh" "$script_dir/restore.sh"
}

# Install Docker, PowerShell, path, platform, curl, Git, and socket stubs for isolated commands.
write_stub_commands() {
    cat > "$bin_dir/docker" <<'DOCKER'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$DOCKER_LOG"
if [[ $1 == info ]]; then
    if [[ ${2:-} == --format ]]; then printf 'linux\n'; fi
    exit 0
fi
if [[ $1 == buildx ]]; then
    printf '"%s"\n' "$REMOTE_DIGEST"
    exit 0
fi
if [[ $1 == ps ]]; then
    if [[ " $* " == *' -a '* ]]; then
        if [[ " $* " == *'service=ospos'* ]]; then
            [[ ${NO_OSPOS_CONTAINER:-0} == 1 ]] || printf 'app-container\n'
        elif [[ " $* " == *'service=mysql'* ]]; then
            [[ ${NO_MYSQL_CONTAINER:-0} == 1 ]] || printf 'mysql-container\n'
        fi
    else
        printf 'container1\n'
    fi
    exit 0
fi
if [[ $1 == run ]]; then
    if [[ -n ${EVENTS:-} ]]; then
        printf 'RESTORE\n' >> "$EVENTS"
        if [[ -n ${REAL_GIT:-} ]]; then
            printf 'RESTORE_HEAD=%s\n' "$("$REAL_GIT" rev-parse HEAD)" >> "$EVENTS"
        fi
    fi
    exit "${RESTORE_DOCKER_EXIT:-${RESTORE_EXIT_CODE:-0}}"
fi
if [[ $1 == inspect ]]; then
    format=''
    target=''
    shift
    while (($# > 0)); do
        if [[ $1 == --format ]]; then format=$2; shift 2; else target=$1; shift; fi
    done
    case $format in
        *RestartPolicy.Name*)
            if [[ $target == mysql-container ]]; then printf '%s\n' "${MYSQL_RESTART_POLICY:-always}"; else printf '%s\n' "${OSPOS_RESTART_POLICY:-always}"; fi
            ;;
        *RepoDigests*)
            if [[ ${SOURCE_DIGEST_MISSING:-0} == 1 && ( $target == sha256:* || -z $target ) ]]; then exit 0; fi
            if [[ $target == hassanshamseddine/osposlb:develop ]]; then
                digest=$(<"$STATE_DIR/local_digest")
            else
                digest=$(<"$STATE_DIR/app_digest")
            fi
            printf 'hassanshamseddine/osposlb@%s\n' "$digest"
            ;;
        *Image*)
            [[ ${SOURCE_IMAGE_ID_MISSING:-0} != 1 ]] || exit 0
            cat "$STATE_DIR/app_image_id"
            ;;
        *Id*)
            if [[ ${SOURCE_IMAGE_ID_MISSING:-0} == 1 && $target == hassanshamseddine/osposlb:update-source ]]; then exit 0; fi
            case $target in
                hassanshamseddine/osposlb:rollback) cat "$STATE_DIR/rollback_image_id" ;;
                hassanshamseddine/osposlb:update-source) cat "$STATE_DIR/update_source_image_id" ;;
                hassanshamseddine/osposlb:develop) cat "$STATE_DIR/develop_image_id" ;;
                sha256:*) printf '%s\n' "$target" ;;
                *) cat "$STATE_DIR/develop_image_id" ;;
            esac
            ;;
        *Created*) printf '2026-09-24T07:17:21Z\n' ;;
        *) exit 1 ;;
    esac
    exit 0
fi
if [[ $1 == image && $2 == inspect ]]; then
    format=''
    target=''
    shift 2
    while (($# > 0)); do
        if [[ $1 == --format ]]; then format=$2; shift 2; else target=$1; shift; fi
    done
    case $format in
        *RepoDigests*)
            if [[ ${SOURCE_DIGEST_MISSING:-0} == 1 && ( $target == sha256:* || -z $target ) ]]; then exit 0; fi
            if [[ $target == hassanshamseddine/osposlb:develop ]]; then digest=$(<"$STATE_DIR/local_digest"); else digest=$(<"$STATE_DIR/app_digest"); fi
            printf 'hassanshamseddine/osposlb@%s\n' "$digest"
            ;;
        *Id*)
            if [[ ${SOURCE_IMAGE_ID_MISSING:-0} == 1 && $target == hassanshamseddine/osposlb:update-source ]]; then exit 0; fi
            case $target in
                hassanshamseddine/osposlb:rollback) cat "$STATE_DIR/rollback_image_id" ;;
                hassanshamseddine/osposlb:update-source) cat "$STATE_DIR/update_source_image_id" ;;
                hassanshamseddine/osposlb:develop) cat "$STATE_DIR/develop_image_id" ;;
                sha256:*) printf '%s\n' "$target" ;;
                *) cat "$STATE_DIR/develop_image_id" ;;
            esac
            ;;
        *Created*) printf '2026-09-24T07:17:21Z\n' ;;
        '')
            if [[ ${SOURCE_IMAGE_ID_MISSING:-0} == 1 && $target == sha256:* ]]; then exit 1; fi
            [[ $target == sha256:* ]] && exit 0
            exit 1
            ;;
        *) exit 1 ;;
    esac
    exit 0
fi
if [[ $1 == tag ]]; then
    case $3 in
        hassanshamseddine/osposlb:rollback) printf '%s\n' "$2" > "$STATE_DIR/rollback_image_id" ;;
        hassanshamseddine/osposlb:update-source) printf '%s\n' "$2" > "$STATE_DIR/update_source_image_id" ;;
    esac
    exit 0
fi
if [[ $1 == compose ]]; then
    if [[ " $* " == *' exec -T mysql sh -c '* ]]; then
        [[ ${RESTORE_COUNTS_FAIL:-0} != 1 ]] || exit 1
        printf '%s\n' "${RESTORE_COUNTS:-12 34 56}"
        exit 0
    fi
    if [[ " $* " == *' pull ospos '* ]]; then
        marker_image=$(<"$OSPOS_DATA_DIR/update.in-progress")
        pinned_image=$(<"$STATE_DIR/update_source_image_id")
        [[ $marker_image == "UPDATE_SOURCE_IMAGE_ID=$pinned_image" ]] || exit 97
        printf 'PULL\n' >> "$EVENTS"
        printf 'sale during image pull\n' >> "$OSPOS_DATA_DIR/db-state.txt"
        printf '%s\n' "$REMOTE_DIGEST" > "$STATE_DIR/local_digest"
        if [[ ${PULL_SAME_IMAGE:-0} == 1 ]]; then
            cat "$STATE_DIR/update_source_image_id" > "$STATE_DIR/develop_image_id"
        else
            printf '%s\n' "$PULLED_IMAGE_ID" > "$STATE_DIR/develop_image_id"
        fi
        [[ ${PULL_FAIL:-0} != 1 ]] || exit 1
        [[ ${PULL_INTERRUPT:-0} != 1 ]] || exit 99
    fi
    for arg in "$@"; do
        case $arg in
            up)
                printf 'COMPOSE_UP\n' >> "$EVENTS"
                if [[ ${OSPOS_IMAGE_TAG:-develop} == rollback ]]; then
                    cat "$STATE_DIR/rollback_image_id" > "$STATE_DIR/app_image_id"
                else
                    cat "$STATE_DIR/develop_image_id" > "$STATE_DIR/app_image_id"
                fi
                ;;
            stop|down) printf 'COMPOSE_%s\n' "${arg^^}" >> "$EVENTS" ;;
        esac
    done
    if [[ ${OSPOS_IMAGE_TAG:-develop} == rollback && ${ROLLBACK_APP_UP_FAIL:-0} == 1 && " $* " == *' up '* && " $* " != *' mysql '* ]]; then
        exit 1
    fi
    exit 0
fi
exit 0
DOCKER
    cat > "$bin_dir/curl" <<'CURL'
#!/usr/bin/env bash
printf '200'
CURL
    cat > "$bin_dir/git" <<'GIT'
#!/usr/bin/env bash
if [[ ${1:-} == pull ]]; then printf 'GIT_PULL\n' >> "$EVENTS"; fi
if [[ ${1:-} == pull && ${GIT_PULL_FAIL:-0} == 1 ]]; then exit 1; fi
if [[ ${1:-} == checkout && ${2:-} == --detach && ${GIT_CHECKOUT_FAIL:-0} == 1 ]]; then exit 1; fi
exec "$REAL_GIT" "$@"
GIT
    chmod +x "$bin_dir/docker" "$bin_dir/curl" "$bin_dir/git"
    cat > "$bin_dir/powershell.exe" <<'POWERSHELL'
#!/usr/bin/env bash
set -euo pipefail
command_text="$*"
if [[ $command_text == *'-File'*'backup.ps1'* ]]; then
    mkdir -p -- "$OSPOS_DATA_DIR/backups"
    printf '2026-09-24T07:17:21Z result=ok archive=ospos-backup-20260924-010101.tar.gz size=1 deleted=0 copy=%s copy_deleted=0 message=ok\n' \
        "${BACKUP_COPY_VALUE:-none}" >> "$OSPOS_DATA_DIR/backups/backup.log"
    exit 0
fi
if [[ $command_text == *'Get-Volume'* ]]; then printf 'NTFS\r\n'; exit 0; fi
if [[ $command_text == *'Get-NetTCPConnection'* ]]; then exit "${PS_LISTENER_STATUS:-0}"; fi
if [[ $command_text == *'Get-Acl'* ]]; then
    case ${PS_ACL_MODE:-ok} in
        ok) printf 'ok\r\n' ;;
        inherited) printf 'inherited\r\n' ;;
        everyone) printf 'S-1-1-0\r\n' ;;
        authenticated) printf 'S-1-5-11\r\n' ;;
        users) printf 'S-1-5-32-545\r\n' ;;
        unreadable) exit 1 ;;
    esac
    exit 0
fi
if [[ $command_text == *'ConvertFrom-Json'* ]]; then
    case ${PS_DOCKER_SETTING:-true} in
        true|false) printf '%s\r\n' "$PS_DOCKER_SETTING" ;;
        *) exit 1 ;;
    esac
    exit 0
fi
if [[ $command_text == *'Get-ScheduledTaskInfo'* ]]; then
    case ${PS_TASK_MODE:-present} in
        present) printf 'present|2026-09-25 23:30:00|0\r\n' ;;
        missing) printf 'missing\r\n' ;;
        failed) exit 1 ;;
    esac
    exit 0
fi
if [[ $command_text == *'Get-ScheduledTask'* ]]; then
    case ${PS_TASK_MODE:-present} in
        missing) printf 'missing\r\n'; exit 0 ;;
        failed) exit 1 ;;
        *) printf 'present\r\n'; exit 0 ;;
    esac
fi
exit 0
POWERSHELL
    cat > "$bin_dir/cygpath" <<'CYGPATH'
#!/usr/bin/env bash
set -euo pipefail
case $1 in
    -w)
        case $2 in
            *backup.ps1) printf 'C:\\shop\\scripts\\backup.ps1\r\n' ;;
            *) printf 'C:\\OSPOS\\Client\\secrets\r\n' ;;
        esac
        ;;
    -u) printf '%s\r\n' "${COPY_FOLDER_LOCAL:-$2}" ;;
    *) exit 1 ;;
esac
CYGPATH
    cat > "$bin_dir/uname" <<'UNAME'
#!/usr/bin/env bash
set -euo pipefail
if [[ ${WINDOWS_MODE:-0} == 1 ]]; then printf 'MINGW64_NT-10.0\n'; else exec "${REAL_UNAME:-/usr/bin/uname}" "$@"; fi
UNAME
    cat > "$bin_dir/ss" <<'SS'
#!/usr/bin/env bash
exit 0
SS
    chmod +x "$bin_dir/powershell.exe" "$bin_dir/cygpath" "$bin_dir/uname" "$bin_dir/ss"
    printf '%s\n' "$old_image_id" > "$state_dir/app_image_id"
    printf '%s\n' "$old_image_id" > "$state_dir/develop_image_id"
    printf '%s\n' "$old_image_id" > "$state_dir/rollback_image_id"
    printf '%s\n' "$old_image_id" > "$state_dir/update_source_image_id"
    printf '%s\n' "$digest_old" > "$state_dir/local_digest"
    printf '%s\n' "$digest_old" > "$state_dir/app_digest"
    : > "$event_file"
    : > "$docker_log"
}

# Create shop settings, the uploads folder, and simulated database state for one test.
prepare_client_data() {
    mkdir -p -- "$data_dir/backups" "$data_dir/rollback" "$data_dir/secrets" "$data_dir/uploads"
    printf 'OSPOS_HTTP_PORT=18080\nOSPOS_BACKUP_DESTINATION=backups\n' > "$data_dir/ospos.conf"
    printf 'database.default.database=ospos\n' > "$data_dir/secrets/app.env"
    printf 'Monday sale\n' > "$data_dir/db-state.txt"
    create_backup_archive "$data_dir/backups/ospos-backup-20260924-010001.tar.gz" "$data_dir/db-state.txt"
}

# Prepare the fixed Windows client path inside the throwaway checkout.
prepare_windows_client() {
    windows_client_dir="$checkout/C:/OSPOS/Client"
    mkdir -p -- "$windows_client_dir/backups" "$windows_client_dir/rollback" "$windows_client_dir/secrets"
    printf "OSPOS_HTTP_PORT=18080\nOSPOS_BACKUP_DESTINATION=backups\nOSPOS_BACKUP_COPY_TO=''\n" > "$windows_client_dir/ospos.conf"
    printf 'database.default.database=ospos\ndatabase.default.username=admin\ndatabase.default.password=shop-test-secret\ndatabase.default.DBPrefix=ospos_\n' \
        > "$windows_client_dir/secrets/app.env"
    printf 'Windows shop test\n' > "$windows_client_dir/db-state.txt"
}

# Fail if an output file contains text that should be absent.
assert_not_contains() {
    local file=$1 unexpected=$2
    if grep -Fq -- "$unexpected" "$file"; then
        printf 'Expected not to find %s in %s\n' "$unexpected" "$file" >&2
        cat "$file" >&2
        exit 1
    fi
}

# Create a backup archive with the restore files and an optional database name.
create_backup_archive() {
    local archive_path=$1 database_source=$2 database_name=${3:-ospos} stage_dir checksum_line checksum
    stage_dir="$case_root/archive-seed"
    rm -rf -- "$stage_dir"
    mkdir -p -- "$stage_dir/uploads/item_pics"
    cp -- "$database_source" "$stage_dir/database.sql"
    checksum_line=$(sha256sum "$stage_dir/database.sql")
    checksum=${checksum_line%% *}
    cat > "$stage_dir/manifest.txt" <<MANIFEST
Backup format: 1
Database name: $database_name
Contents: database.sql, uploads/
Database SHA-256: $checksum
MANIFEST
    tar -czf "$archive_path" -C "$stage_dir" database.sql manifest.txt uploads
    rm -rf -- "$stage_dir"
}

# Add a hard link whose archive target is missing.
create_dangling_hardlink_archive() {
    local archive_path=$1 database_source=$2 stage_dir checksum_line checksum raw_archive
    stage_dir="$case_root/dangling-hardlink-seed"
    raw_archive="$case_root/dangling-hardlink.tar"
    rm -rf -- "$stage_dir"
    mkdir -p -- "$stage_dir/uploads/item_pics"
    cp -- "$database_source" "$stage_dir/database.sql"
    checksum_line=$(sha256sum "$stage_dir/database.sql")
    checksum=${checksum_line%% *}
    cat > "$stage_dir/manifest.txt" <<MANIFEST
Backup format: 1
Database name: ospos
Contents: database.sql, uploads/
Database SHA-256: $checksum
MANIFEST
    printf 'image bytes\n' > "$stage_dir/uploads/item_pics/a-target"
    ln -- "$stage_dir/uploads/item_pics/a-target" "$stage_dir/uploads/item_pics/z-dangling"
    tar --no-recursion -cf "$raw_archive" -C "$stage_dir" \
        database.sql manifest.txt uploads uploads/item_pics uploads/item_pics/a-target uploads/item_pics/z-dangling
    tar --delete --file="$raw_archive" uploads/item_pics/a-target
    if ! tar -tvf "$raw_archive" | awk '$1 ~ /^h/ && index($0, "uploads/item_pics/z-dangling link to uploads/item_pics/a-target") { found = 1 } END { exit !found }'; then
        printf 'Could not create the expected dangling hard-link archive entry.\n' >&2
        exit 1
    fi
    if tar -tf "$raw_archive" | grep -Fqx 'uploads/item_pics/a-target'; then
        printf 'The dangling hard-link archive still contains its target.\n' >&2
        exit 1
    fi
    gzip -c "$raw_archive" > "$archive_path"
    rm -rf -- "$stage_dir" "$raw_archive"
}

# Save a valid archived rollback point, checksums, and optional source image ID.
write_rollback_record() {
    local commit=$1 active=$2 pending=$3 from_commit=$4 from_digest=$5 from_image_id=${6:-} archive hash archive_path
    archive=ospos-backup-20260924-000001.tar.gz
    archive_path="$data_dir/rollback/$archive"
    create_backup_archive "$archive_path" "$data_dir/db-state.txt"
    hash=$(sha256sum "$archive_path")
    hash=${hash%% *}
    cat > "$data_dir/rollback.conf" <<RECORD
ROLLBACK_COMMIT=$commit
ROLLBACK_ARCHIVE=$archive
ROLLBACK_ARCHIVE_SHA256=$hash
ROLLBACK_DATE=2026-09-24 07:17:21 EEST
ROLLBACK_IMAGE=$old_image_id
ROLLBACK_ACTIVE=$active
UPDATE_PENDING=$pending
ROLLBACK_FROM_COMMIT=$from_commit
ROLLBACK_FROM_DIGEST=$from_digest
ROLLBACK_FROM_IMAGE_ID=$from_image_id
RECORD
}

# Run the shop command with the test stubs and save its output.
run_shop() {
    local target=$1
    shift
    (
        cd "$checkout"
        REAL_GIT="$real_git" REAL_UNAME="$real_uname" STATE_DIR="$state_dir" DOCKER_LOG="$docker_log" EVENTS="$event_file" \
            REMOTE_DIGEST="$remote_digest" PULLED_IMAGE_ID="$new_image_id" \
            OSPOS_DATA_DIR="$data_dir" HOME="$case_root/home" PATH="${SHOP_TEST_PATH:-$bin_dir:$PATH}" \
            ./shop "$target" "$@"
    ) > "$output_file" 2>&1
}

# Run the shop command with a typed line on standard input.
run_shop_with_input() {
    local input=$1 target=$2
    shift 2
    (
        cd "$checkout"
        printf '%s\n' "$input" | REAL_GIT="$real_git" REAL_UNAME="$real_uname" STATE_DIR="$state_dir" DOCKER_LOG="$docker_log" EVENTS="$event_file" \
            REMOTE_DIGEST="$remote_digest" PULLED_IMAGE_ID="$new_image_id" \
            OSPOS_DATA_DIR="$data_dir" HOME="$case_root/home" PATH="${SHOP_TEST_PATH:-$bin_dir:$PATH}" \
            ./shop "$target" "$@"
    ) > "$output_file" 2>&1
}

# Fail with a short message when an expected output line is missing.
assert_contains() {
    local file=$1 expected=$2
    if ! grep -Fq -- "$expected" "$file"; then
        printf 'Expected to find %s in %s\n' "$expected" "$file" >&2
        cat "$file" >&2
        exit 1
    fi
}

# Write an old lock record without asking the shop to judge its owner process.
write_old_lock() {
    mkdir -p -- "$data_dir/.shop-command.lock"
    printf '987654321\n' > "$data_dir/.shop-command.lock/pid"
    printf '%s\n' "$(hostname 2>/dev/null || uname -n)" > "$data_dir/.shop-command.lock/host"
    printf 'backup\n' > "$data_dir/.shop-command.lock/command"
    printf '2026-09-24T07:17:21Z\n' > "$data_dir/.shop-command.lock/started"
    printf '%s\n' "$(( $(date +%s) - 90 ))" > "$data_dir/.shop-command.lock/started_epoch"
}

# Return the first event line number for an exact event name.
event_line() {
    awk -v event="$1" '$0 == event { print NR; exit }' "$event_file"
}

# Ensure an update after rollback saves a fresh point with the sales made since rollback.
test_update_after_rollback_saves_fresh_point() {
    make_case update-after-rollback
    printf 'Tuesday sale\nWednesday sale\nThursday sale\nFriday sale\n' >> "$data_dir/db-state.txt"
    write_rollback_record "$base_commit" 1 0 "$middle_commit" "$digest_old"
    remote_digest=$digest_new
    if ! run_shop update --yes; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$data_dir/rollback.conf" "ROLLBACK_ARCHIVE=ospos-backup-20260924-010101.tar.gz"
    assert_contains "$data_dir/rollback.conf" "ROLLBACK_IMAGE=$old_image_id"
    assert_contains "$data_dir/rollback.conf" "ROLLBACK_ACTIVE=0"
    tar -xOzf "$data_dir/rollback/ospos-backup-20260924-010101.tar.gz" database.sql > "$case_root/saved-db.txt"
    assert_contains "$case_root/saved-db.txt" 'Tuesday sale'
    assert_contains "$case_root/saved-db.txt" 'sale during image pull'
    [[ $(cat "$state_dir/rollback_image_id") == "$old_image_id" ]]
}

# Ensure the backup is taken after the image download but before Git updates code.
test_update_backup_follows_image_pull() {
    make_case update-backup-order
    local pull_count
    remote_digest=$digest_new
    if ! run_shop update --yes; then cat "$output_file" >&2; exit 1; fi
    tar -xOzf "$data_dir/rollback/ospos-backup-20260924-010101.tar.gz" database.sql > "$case_root/saved-db.txt"
    assert_contains "$case_root/saved-db.txt" 'sale during image pull'
    if ! grep -Fq 'pull ospos' "$docker_log"; then
        printf 'Update did not pull only the ospos image.\n' >&2
        cat "$docker_log" >&2
        exit 1
    fi
    pull_line=$(event_line PULL)
    backup_line=$(event_line BACKUP)
    git_line=$(event_line GIT_PULL)
    up_line=$(event_line COMPOSE_UP)
    (( pull_line > 0 && pull_line < backup_line && backup_line < git_line && git_line < up_line ))
    pull_count=$(grep -Ec 'compose .* pull' "$docker_log" || true)
    [[ $pull_count == 1 ]] || { printf 'Update must pull exactly one service.\n' >&2; cat "$docker_log" >&2; exit 1; }
}

# Check that an interrupted update blocks start, keeps the old image pinned, and can retry.
check_interrupted_update_recovery() {
    assert_contains "$data_dir/update.in-progress" "UPDATE_SOURCE_IMAGE_ID=$old_image_id"
    [[ $(cat "$state_dir/update_source_image_id") == "$old_image_id" ]]
    : > "$event_file"
    : > "$docker_log"
    if run_shop start; then
        printf 'Expected start to refuse while an update is incomplete.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'Recovery command: ./shop update'
    [[ $(cat "$state_dir/app_image_id") == "$old_image_id" ]]
    if grep -Fq 'COMPOSE_UP' "$event_file"; then
        printf 'Start ran Compose up while the update was incomplete.\n' >&2
        exit 1
    fi
    if ! run_shop update --yes; then cat "$output_file" >&2; exit 1; fi
    [[ ! -e $data_dir/update.in-progress ]]
    [[ $(cat "$state_dir/rollback_image_id") == "$old_image_id" ]]
    [[ $(cat "$state_dir/app_image_id") == "$new_image_id" ]]
}

# Keep the old app image safe when the image pull fails after changing its local tag.
test_pull_failure_blocks_start_and_retries() {
    make_case pull-failure
    remote_digest=$digest_new
    if PULL_FAIL=1 run_shop update --yes; then
        printf 'Expected the simulated partial image pull to fail.\n' >&2
        exit 1
    fi
    check_interrupted_update_recovery
}

# Keep the old app image safe when the backup fails after the image pull.
test_backup_failure_blocks_start_and_retries() {
    make_case backup-failure
    remote_digest=$digest_new
    if BACKUP_FAIL=1 run_shop update --yes; then
        printf 'Expected the simulated backup to fail.\n' >&2
        exit 1
    fi
    check_interrupted_update_recovery
}

# Keep the old app image safe when the update stops after a successful image pull.
test_interruption_after_pull_blocks_start_and_retries() {
    make_case interrupted-after-pull
    remote_digest=$digest_new
    if PULL_INTERRUPT=1 run_shop update --yes; then
        printf 'Expected the simulated interruption after pull to fail.\n' >&2
        exit 1
    fi
    check_interrupted_update_recovery
}

# Keep start blocked after the rollback point is saved but the code pull fails.
test_code_pull_failure_blocks_start_and_retries() {
    make_case code-pull-failure
    remote_digest=$digest_new
    if GIT_PULL_FAIL=1 run_shop update --yes; then
        printf 'Expected the simulated code pull to fail.\n' >&2
        exit 1
    fi
    assert_contains "$data_dir/rollback.conf" 'UPDATE_PENDING=1'
    assert_contains "$data_dir/rollback.conf" 'ROLLBACK_ARCHIVE=ospos-backup-20260924-010101.tar.gz'
    : > "$event_file"
    : > "$docker_log"
    if run_shop start; then
        printf 'Expected start to refuse after the code pull failed.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'Recovery command: ./shop rollback'
    [[ $(cat "$state_dir/app_image_id") == "$old_image_id" ]]
    if grep -Fq 'COMPOSE_UP' "$event_file"; then
        printf 'Start ran Compose up while the update was pending.\n' >&2
        exit 1
    fi
    if ! run_shop update --yes; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$data_dir/rollback.conf" 'UPDATE_PENDING=0'
    assert_contains "$data_dir/rollback.conf" 'ROLLBACK_ARCHIVE=ospos-backup-20260924-010101.tar.gz'
    [[ ! -e $data_dir/update.in-progress ]]
}

# Ensure a second rollback refuses before it stops the running app.
test_second_rollback_is_refused() {
    make_case second-rollback
    write_rollback_record "$base_commit" 1 0 "$middle_commit" "$digest_old"
    if run_shop rollback --yes; then
        printf 'Expected the second rollback to be refused.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'A rollback is already active'
    assert_contains "$output_file" 'Nothing was changed. Safe next step: ./shop start'
    [[ ! -s $docker_log ]]
    [[ ! -e $data_dir/rollback.unfinished ]]
    if ! run_shop restore ARCHIVE=ospos-backup-20260924-010001.tar.gz --yes; then cat "$output_file" >&2; exit 1; fi
}

# Ensure a damaged rollback archive fails gzip validation before the app is stopped.
test_damaged_rollback_archive_is_checked_first() {
    make_case damaged-rollback
    local archive hash
    archive=ospos-backup-20260924-000001.tar.gz
    printf 'truncated gzip data' > "$data_dir/rollback/$archive"
    hash=$(sha256sum "$data_dir/rollback/$archive")
    hash=${hash%% *}
    cat > "$data_dir/rollback.conf" <<RECORD
ROLLBACK_COMMIT=$base_commit
ROLLBACK_ARCHIVE=$archive
ROLLBACK_ARCHIVE_SHA256=$hash
ROLLBACK_DATE=2026-09-24 07:17:21 EEST
ROLLBACK_IMAGE=$old_image_id
ROLLBACK_ACTIVE=0
UPDATE_PENDING=0
RECORD
    if run_shop rollback --yes; then
        printf 'Expected the damaged rollback archive to be refused.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'not a valid gzip archive'
    [[ ! -s $docker_log ]]
    [[ ! -e $data_dir/rollback.unfinished ]]
}

# Refuse an invalid archive before a plain restore stops the running app.
test_restore_validates_archive_before_stopping() {
    make_case invalid-restore-archive
    printf 'not a backup\n' > "$case_root/unexpected.txt"
    tar -czf "$data_dir/backups/ospos-backup-20260924-010002.tar.gz" -C "$case_root" unexpected.txt
    if run_shop restore ARCHIVE=ospos-backup-20260924-010002.tar.gz --yes; then
        printf 'Expected a plain restore with invalid archive contents to be refused.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'unexpected member: unexpected.txt'
    [[ ! -s $docker_log ]]
    [[ ! -e $data_dir/restore.unfinished ]]
}

# Refuse a backup for a different configured database before stopping the app.
test_restore_refuses_archive_for_another_database() {
    make_case restore-database-mismatch
    create_backup_archive "$data_dir/backups/ospos-backup-20260924-010002.tar.gz" \
        "$data_dir/db-state.txt" other_shop
    if run_shop restore ARCHIVE=ospos-backup-20260924-010002.tar.gz --yes; then
        printf 'Expected a backup for another database to be refused.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'belongs to database other_shop, not the configured shop database ospos'
    [[ ! -s $docker_log ]]
    [[ ! -e $data_dir/restore.unfinished ]]
}

# Never let a plain restore remove the marker for an unfinished rollback.
test_restore_does_not_clear_unfinished_rollback() {
    make_case restore-keeps-rollback-marker
    write_rollback_record "$base_commit" 0 0 '' ''
    : > "$data_dir/rollback.unfinished"
    if run_shop restore ARCHIVE=ospos-backup-20260924-010001.tar.gz --yes; then
        printf 'Expected restore to refuse while rollback is unfinished.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'A rollback did not finish'
    assert_contains "$output_file" 'Recovery command: ./shop rollback'
    [[ -e $data_dir/rollback.unfinished ]]
    [[ ! -s $docker_log ]]
}

# Refuse a valid gzip archive that does not contain a restorable backup before stopping.
test_rollback_rejects_archive_without_backup_entries() {
    make_case incomplete-backup-archive
    local archive hash
    archive=ospos-backup-20260924-000001.tar.gz
    write_rollback_record "$base_commit" 0 0 '' ''
    printf 'not a backup\n' > "$case_root/unexpected.txt"
    tar -czf "$data_dir/rollback/$archive" -C "$case_root" unexpected.txt
    hash=$(sha256sum "$data_dir/rollback/$archive")
    hash=${hash%% *}
    sed -i "s/^ROLLBACK_ARCHIVE_SHA256=.*/ROLLBACK_ARCHIVE_SHA256=$hash/" "$data_dir/rollback.conf"
    if run_shop rollback --yes; then
        printf 'Expected an archive without database.sql to be refused.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'unexpected member: unexpected.txt'
    [[ ! -s $docker_log ]]
    [[ ! -e $data_dir/rollback.unfinished ]]
}

# Reject a legacy archive whose SQL dump no longer matches its manifest checksum.
test_legacy_rollback_rejects_bad_manifest_checksum() {
    make_case legacy-bad-manifest
    local archive stage_dir
    archive=ospos-backup-20260924-000001.tar.gz
    write_rollback_record "$base_commit" 0 0 '' ''
    stage_dir="$case_root/bad-manifest"
    mkdir -p -- "$stage_dir"
    tar -xzf "$data_dir/rollback/$archive" -C "$stage_dir"
    printf 'changed dump\n' > "$stage_dir/database.sql"
    tar -czf "$data_dir/rollback/$archive" -C "$stage_dir" database.sql manifest.txt uploads
    sed -i '/^ROLLBACK_ARCHIVE_SHA256=/d' "$data_dir/rollback.conf"
    if run_shop rollback --yes; then
        printf 'Expected a legacy archive with a bad database checksum to be refused.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'database checksum does not match its manifest'
    if grep -Fq 'ROLLBACK_ARCHIVE_SHA256=' "$data_dir/rollback.conf"; then
        printf 'An invalid legacy archive must not receive a saved checksum.\n' >&2
        exit 1
    fi
    [[ ! -s $docker_log ]]
}

# Require the archive uploads entry to be a directory before stopping the app.
test_rollback_rejects_non_directory_uploads_entry() {
    make_case invalid-uploads-entry
    local archive stage_dir hash
    archive=ospos-backup-20260924-000001.tar.gz
    write_rollback_record "$base_commit" 0 0 '' ''
    stage_dir="$case_root/bad-uploads"
    mkdir -p -- "$stage_dir"
    tar -xzf "$data_dir/rollback/$archive" -C "$stage_dir"
    rm -rf -- "$stage_dir/uploads"
    printf 'not a directory\n' > "$stage_dir/uploads"
    tar -czf "$data_dir/rollback/$archive" -C "$stage_dir" database.sql manifest.txt uploads
    hash=$(sha256sum "$data_dir/rollback/$archive")
    hash=${hash%% *}
    sed -i "s/^ROLLBACK_ARCHIVE_SHA256=.*/ROLLBACK_ARCHIVE_SHA256=$hash/" "$data_dir/rollback.conf"
    if run_shop rollback --yes; then
        printf 'Expected a non-directory uploads entry to be refused.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'uploads/ entry is not a directory'
    [[ ! -s $docker_log ]]
}

# Refuse a dangling upload hard link before any restore command runs.
test_rollback_rejects_dangling_upload_hardlink() {
    make_case dangling-upload-hardlink
    local archive hash
    archive=ospos-backup-20260924-000001.tar.gz
    write_rollback_record "$base_commit" 0 0 '' ''
    create_dangling_hardlink_archive "$data_dir/rollback/$archive" "$data_dir/db-state.txt"
    hash=$(sha256sum "$data_dir/rollback/$archive")
    hash=${hash%% *}
    sed -i "s/^ROLLBACK_ARCHIVE_SHA256=.*/ROLLBACK_ARCHIVE_SHA256=$hash/" "$data_dir/rollback.conf"
    if run_shop rollback --yes; then
        printf 'Expected a dangling upload hard link to be refused.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'could not be fully extracted'
    [[ ! -s $docker_log ]]
    [[ ! -e $data_dir/rollback.unfinished ]]
}

# Add a checksum to a valid legacy rollback record that predates checksum storage.
test_legacy_rollback_record_gets_checksum() {
    make_case legacy-rollback-checksum
    local hash
    write_rollback_record "$base_commit" 0 0 '' ''
    sed -i '/^ROLLBACK_ARCHIVE_SHA256=/d' "$data_dir/rollback.conf"
    hash=$(sha256sum "$data_dir/rollback/ospos-backup-20260924-000001.tar.gz")
    hash=${hash%% *}
    if ! run_shop rollback --yes; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'old rollback record had no archive checksum'
    assert_contains "$data_dir/rollback.conf" "ROLLBACK_ARCHIVE_SHA256=$hash"
}

# Keep an invalid legacy rollback record unchanged when its image check fails.
test_legacy_checksum_waits_for_rollback_validation() {
    make_case legacy-checksum-late-write
    write_rollback_record "$base_commit" 0 0 '' ''
    sed -i '/^ROLLBACK_ARCHIVE_SHA256=/d' "$data_dir/rollback.conf"
    printf '%s\n' "$new_image_id" > "$state_dir/rollback_image_id"
    if run_shop rollback --yes; then
        printf 'Expected the mismatched rollback image to be refused.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'rollback image tag does not match'
    if grep -Fq 'ROLLBACK_ARCHIVE_SHA256=' "$data_dir/rollback.conf"; then
        printf 'An invalid legacy rollback record must not receive a saved checksum.\n' >&2
        exit 1
    fi
    [[ ! -s $docker_log ]] || ! grep -Fq 'compose ' "$docker_log"
}

# Treat an empty checksum field as a damaged record instead of a legacy record.
test_rollback_rejects_empty_checksum_field() {
    make_case empty-rollback-checksum
    write_rollback_record "$base_commit" 0 0 '' ''
    sed -i 's/^ROLLBACK_ARCHIVE_SHA256=.*/ROLLBACK_ARCHIVE_SHA256=/' "$data_dir/rollback.conf"
    if run_shop rollback --yes; then
        printf 'Expected an empty rollback checksum field to be refused.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'saved rollback archive checksum is invalid'
    [[ ! -s $docker_log ]]
}

# Check update and rollback tools before contacting Docker or changing the shop.
test_archive_tools_are_checked_before_update_and_rollback() {
    make_case missing-archive-tools
    restricted_path="$case_root/restricted-bin"
    mkdir -p -- "$restricted_path"
    for utility in bash uname dirname basename mkdir rm rmdir; do
        ln -s "$(type -P "$utility")" "$restricted_path/$utility"
    done
    if SHOP_TEST_PATH=$restricted_path run_shop update --yes; then
        printf 'Expected update to refuse when archive tools are missing.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'Missing required update and rollback tools: sha256sum gzip tar'
    [[ ! -s $docker_log ]]
    : > "$output_file"
    if SHOP_TEST_PATH=$restricted_path run_shop rollback --yes; then
        printf 'Expected rollback to refuse when archive tools are missing.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'Missing required update and rollback tools: sha256sum gzip tar'
    [[ ! -s $docker_log ]]
}

# Refuse tracked edits and non-ignored untracked files before update or rollback work.
test_update_and_rollback_refuse_untracked_files() {
    make_case update-untracked
    printf 'local file\n' > "$checkout/operator-notes.txt"
    if run_shop update --yes; then
        printf 'Expected update to refuse a non-ignored untracked file.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'operator-notes.txt'
    assert_contains "$output_file" 'Move or delete these files'
    [[ ! -s $docker_log ]]

    make_case rollback-untracked
    write_rollback_record "$base_commit" 0 0 '' ''
    printf 'local file\n' > "$checkout/operator-notes.txt"
    if run_shop rollback --yes; then
        printf 'Expected rollback to refuse a non-ignored untracked file.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'operator-notes.txt'
    assert_contains "$output_file" 'Move or delete these files'
    [[ ! -s $docker_log ]]
}

# Allow ignored files because git status does not report them as local work.
test_update_allows_ignored_untracked_files() {
    make_case ignored-untracked
    printf 'shop-test-ignored\n' >> "$checkout/.git/info/exclude"
    printf 'ignored file\n' > "$checkout/shop-test-ignored"
    remote_digest=$digest_new
    if ! run_shop update --yes; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'Updated to commit:'
}

# Preserve restore phase codes and classify uncertain Docker exits without Docker.
test_restore_launcher_preserves_database_phase_status() {
    make_case restore-launcher-phase
    mkdir -p -- "$data_dir/uploads" "$data_dir/secrets"
    : > "$data_dir/secrets/app.env"
    local status=0
    if OSPOS_DATA_DIR="$data_dir" DOCKER_LOG="$docker_log" PATH="$bin_dir:$PATH" \
        bash "$source_root/scripts/restore.sh" --archive "$case_root/missing.tar.gz" --yes > "$output_file" 2>&1; then
        printf 'Expected local restore validation to return its pre-database phase code.\n' >&2
        exit 1
    else
        status=$?
    fi
    [[ $status == 20 ]]
    [[ ! -s $docker_log ]]
    printf 'stub archive\n' > "$case_root/restore.tar.gz"
    status=0
    if RESTORE_DOCKER_EXIT=20 OSPOS_DATA_DIR="$data_dir" DOCKER_LOG="$docker_log" PATH="$bin_dir:$PATH" \
        bash "$source_root/scripts/restore.sh" --archive "$case_root/restore.tar.gz" --yes > "$output_file" 2>&1; then
        printf 'Expected the restore launcher to preserve exit code 20.\n' >&2
        exit 1
    else
        status=$?
    fi
    [[ $status == 20 ]]
    [[ ! -e $data_dir/restore.unfinished ]]
    : > "$data_dir/restore.unfinished"
    status=0
    if RESTORE_DOCKER_EXIT=20 OSPOS_DATA_DIR="$data_dir" DOCKER_LOG="$docker_log" PATH="$bin_dir:$PATH" \
        bash "$source_root/scripts/restore.sh" --archive "$case_root/restore.tar.gz" --yes > "$output_file" 2>&1; then
        printf 'Expected code 20 to preserve an earlier restore marker.\n' >&2
        exit 1
    else
        status=$?
    fi
    [[ $status == 20 ]]
    [[ -f $data_dir/restore.unfinished ]]
    rm -f -- "$data_dir/restore.unfinished"
    status=0
    if RESTORE_DOCKER_EXIT=125 OSPOS_DATA_DIR="$data_dir" DOCKER_LOG="$docker_log" PATH="$bin_dir:$PATH" bash "$source_root/scripts/restore.sh" --archive "$case_root/restore.tar.gz" --yes > "$output_file" 2>&1; then
        printf 'Expected a container launch failure to map to exit code 21.\n' >&2
        exit 1
    else
        status=$?
    fi
    [[ $status == 21 ]]
    [[ -f $data_dir/restore.unfinished ]]
    status=0
    if RESTORE_DOCKER_EXIT=137 OSPOS_DATA_DIR="$data_dir" DOCKER_LOG="$docker_log" PATH="$bin_dir:$PATH" \
        bash "$source_root/scripts/restore.sh" --archive "$case_root/restore.tar.gz" --yes > "$output_file" 2>&1; then
        printf 'Expected a killed restore container to map to exit code 21.\n' >&2
        exit 1
    else
        status=$?
    fi
    [[ $status == 21 ]]
    [[ -f $data_dir/restore.unfinished ]]
    status=0
    if RESTORE_DOCKER_EXIT=21 OSPOS_DATA_DIR="$data_dir" DOCKER_LOG="$docker_log" PATH="$bin_dir:$PATH" \
        bash "$source_root/scripts/restore.sh" --archive "$case_root/restore.tar.gz" --yes > "$output_file" 2>&1; then
        printf 'Expected the restore launcher to return the database-phase exit code.\n' >&2
        exit 1
    else
        status=$?
    fi
    [[ $status == 21 ]]
    [[ -f $data_dir/restore.unfinished ]]
    status=0
    if RESTORE_DOCKER_EXIT=0 OSPOS_DATA_DIR="$data_dir" DOCKER_LOG="$docker_log" PATH="$bin_dir:$PATH" \
        bash "$source_root/scripts/restore.sh" --archive "$case_root/restore.tar.gz" --yes > "$output_file" 2>&1; then
        status=0
    else
        status=$?
        cat "$output_file" >&2
        exit 1
    fi
    [[ $status == 0 ]]
    [[ ! -e $data_dir/restore.unfinished ]]
    [[ ! -e $data_dir/.shop-command.lock ]]
}

# Use the shop lock, marker owner, and default client folder for direct restores.
test_direct_restore_lock_marker_and_default_directory() {
    make_case direct-restore-lock
    local status=0 default_home default_client
    mkdir -p -- "$data_dir/uploads" "$data_dir/secrets"
    : > "$data_dir/secrets/app.env"
    printf 'stub archive\n' > "$case_root/restore.tar.gz"
    mkdir -p -- "$data_dir/.shop-command.lock"
    : > "$data_dir/restore.unfinished"
    : > "$docker_log"
    if RESTORE_DOCKER_EXIT=0 OSPOS_DATA_DIR="$data_dir" DOCKER_LOG="$docker_log" PATH="$bin_dir:$PATH" \
        bash "$source_root/scripts/restore.sh" --archive "$case_root/restore.tar.gz" --yes > "$output_file" 2>&1; then
        printf 'Expected a direct restore to stop when the shared lock is held.\n' >&2
        exit 1
    else
        status=$?
    fi
    [[ $status == 20 ]]
    assert_contains "$output_file" 'Another shop command or backup is running'
    [[ ! -s $docker_log ]]
    [[ -f $data_dir/restore.unfinished ]]
    rmdir -- "$data_dir/.shop-command.lock"

    mkdir -p -- "$data_dir/.shop-command.lock"
    printf '12345\n' > "$data_dir/.shop-command.lock/pid"
    : > "$docker_log"
    status=0
    if OSPOS_SHOP_LOCK_HELD=1 RESTORE_DOCKER_EXIT=0 OSPOS_DATA_DIR="$data_dir" DOCKER_LOG="$docker_log" PATH="$bin_dir:$PATH" \
        bash "$source_root/scripts/restore.sh" --archive "$case_root/restore.tar.gz" --yes > "$output_file" 2>&1; then
        status=0
    else
        status=$?
        cat "$output_file" >&2
        exit 1
    fi
    [[ $status == 0 ]]
    [[ -f $data_dir/.shop-command.lock/pid ]]
    [[ -f $data_dir/restore.unfinished ]]
    [[ -s $docker_log ]]
    rm -rf -- "$data_dir/.shop-command.lock" "$data_dir/restore.unfinished"

    default_home="$case_root/default-home"
    default_client="$case_root/C:/OSPOS/Client"
    mkdir -p -- "$default_client/uploads" "$default_client/secrets"
    : > "$default_client/secrets/app.env"
    status=0
    if (cd "$case_root" && env -u OSPOS_DATA_DIR HOME="$default_home" RESTORE_DOCKER_EXIT=21 DOCKER_LOG="$docker_log" PATH="$bin_dir:$PATH" \
        bash "$source_root/scripts/restore.sh" --archive "$case_root/restore.tar.gz" --yes > "$output_file" 2>&1); then
        printf 'Expected the default-folder restore stub to preserve its failure status.\n' >&2
        exit 1
    else
        status=$?
    fi
    [[ $status == 21 ]]
    [[ -f $default_client/restore.unfinished ]]
    [[ ! -e $default_client/.shop-command.lock ]]
}

# Refuse direct shell restores during unfinished update and rollback recovery.
test_direct_restore_refuses_update_and_rollback_recovery() {
    make_case direct-restore-recovery
    mkdir -p -- "$data_dir/uploads" "$data_dir/secrets"
    : > "$data_dir/secrets/app.env"
    printf 'stub archive\n' > "$case_root/restore.tar.gz"
    local status=0

    : > "$data_dir/rollback.unfinished"
    if OSPOS_SHOP_LOCK_HELD=1 OSPOS_DATA_DIR="$data_dir" DOCKER_LOG="$docker_log" PATH="$bin_dir:$PATH" \
        bash "$source_root/scripts/restore.sh" --archive "$case_root/restore.tar.gz" --yes > "$output_file" 2>&1; then
        printf 'Expected direct restore to refuse an unfinished rollback.\n' >&2
        exit 1
    else
        status=$?
    fi
    [[ $status == 20 ]]
    assert_contains "$output_file" 'Error: A rollback did not finish, so the database state is unknown.'
    assert_contains "$output_file" 'Recovery command: ./shop rollback'
    [[ ! -s $docker_log ]]
    rm -f -- "$data_dir/rollback.unfinished"

    : > "$data_dir/update.in-progress"
    if OSPOS_DATA_DIR="$data_dir" DOCKER_LOG="$docker_log" PATH="$bin_dir:$PATH" \
        bash "$source_root/scripts/restore.sh" --archive "$case_root/restore.tar.gz" --yes > "$output_file" 2>&1; then
        printf 'Expected direct restore to refuse an unfinished update.\n' >&2
        exit 1
    else
        status=$?
    fi
    [[ $status == 20 ]]
    assert_contains "$output_file" 'Nothing was changed. Safe next step: ./shop update'
    [[ ! -s $docker_log ]]

    printf 'UPDATE_PENDING=1\n' > "$data_dir/rollback.conf"
    if OSPOS_DATA_DIR="$data_dir" DOCKER_LOG="$docker_log" PATH="$bin_dir:$PATH" \
        bash "$source_root/scripts/restore.sh" --archive "$case_root/restore.tar.gz" --yes > "$output_file" 2>&1; then
        printf 'Expected direct restore to advise rollback when its point is saved.\n' >&2
        exit 1
    else
        status=$?
    fi
    [[ $status == 20 ]]
    assert_contains "$output_file" 'Nothing was changed. Safe next step: ./shop rollback'
    [[ ! -s $docker_log ]]
}

# Block backup and retention during partial restore or rollback states only.
test_backup_refuses_partial_database_state() {
    make_case backup-partial-state
    mkdir -p -- "$data_dir/uploads" "$data_dir/secrets"
    : > "$data_dir/secrets/app.env"
    local marker status=0
    for marker in restore.unfinished rollback.unfinished; do
        : > "$data_dir/$marker"
        : > "$docker_log"
        if OSPOS_DATA_DIR="$data_dir" DOCKER_LOG="$docker_log" EVENTS="$event_file" PATH="$bin_dir:$PATH" \
            bash "$source_root/scripts/backup.sh" > "$output_file" 2>&1; then
            printf 'Expected backup to refuse an unfinished database operation.\n' >&2
            exit 1
        fi
        assert_contains "$output_file" 'backup and retention were not run'
        assert_contains "$data_dir/backups/backup.log" 'result=failed'
        [[ ! -s $docker_log ]]
        [[ ! -e $data_dir/.shop-command.lock ]]
        rm -f -- "$data_dir/$marker"
    done

    : > "$data_dir/update.in-progress"
    : > "$docker_log"
    if OSPOS_DATA_DIR="$data_dir" DOCKER_LOG="$docker_log" EVENTS="$event_file" PATH="$bin_dir:$PATH" \
        bash "$source_root/scripts/backup.sh" > "$output_file" 2>&1; then
        status=0
    else
        status=$?
        cat "$output_file" >&2
        exit 1
    fi
    [[ $status == 0 ]]
    assert_contains "$docker_log" 'run'
}

# Return phase exit codes from restore.sh without calling a real database or Docker.
test_container_restore_reports_both_failure_phases() {
    make_case container-restore-phases
    local restore_bin archive_path real_tar status=0
    restore_bin="$case_root/restore-bin"
    archive_path="$case_root/good-backup.tar.gz"
    mkdir -p -- "$restore_bin" "$case_root/restore-uploads"
    cat > "$restore_bin/mysql" <<'MYSQL'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$MYSQL_LOG"
has_execute=0
for argument in "$@"; do
    [[ $argument != -e && $argument != --execute ]] || has_execute=1
done
(( has_execute == 1 )) && exit 0
[[ ${RESTORE_MYSQL_FAIL:-0} != 1 ]] || exit 1
cat >/dev/null
MYSQL
    chmod +x "$restore_bin/mysql"
    cat > "$case_root/restore.env" <<'ENV'
database.default.database = ospos
database.default.username = admin
database.default.password = stub-password
ENV
    printf 'invalid archive\n' > "$case_root/invalid-backup.tar.gz"
    cat > "$restore_bin/mktemp" <<'MKTEMP'
#!/usr/bin/env bash
exit 42
MKTEMP
    chmod +x "$restore_bin/mktemp"
    if MYSQL_LOG="$case_root/mysql.log" PATH="$restore_bin:$PATH" \
        bash "$source_root/scripts/container/restore.sh" --archive "$case_root/invalid-backup.tar.gz" \
            --uploads "$case_root/restore-uploads" --env "$case_root/restore.env" --db-host localhost --platform linux --yes \
            > "$output_file" 2>&1; then
        printf 'Expected temporary-folder creation to fail.\n' >&2
        exit 1
    else
        status=$?
    fi
    [[ $status == 20 ]]
    assert_contains "$output_file" 'Restore command failed with status'
    [[ ! -s $case_root/mysql.log ]]
    rm -f -- "$restore_bin/mktemp"

    if MYSQL_LOG="$case_root/mysql.log" PATH="$restore_bin:$PATH" \
        bash "$source_root/scripts/container/restore.sh" --archive "$case_root/invalid-backup.tar.gz" \
            --uploads "$case_root/restore-uploads" --env "$case_root/restore.env" --db-host localhost --platform linux --yes \
            > "$output_file" 2>&1; then
        printf 'Expected archive validation to fail before database restore.\n' >&2
        exit 1
    else
        status=$?
    fi
    [[ $status == 20 ]]
    [[ ! -s $case_root/mysql.log ]]
    create_backup_archive "$archive_path" "$data_dir/db-state.txt"
    real_tar=$(type -P tar)
    cat > "$restore_bin/tar" <<'TAR'
#!/usr/bin/env bash
[[ ${1:-} != -xzf ]] || exit 42
exec "$REAL_TAR" "$@"
TAR
    chmod +x "$restore_bin/tar"
    status=0
    if REAL_TAR="$real_tar" MYSQL_LOG="$case_root/mysql.log" PATH="$restore_bin:$PATH" \
        bash "$source_root/scripts/container/restore.sh" --archive "$archive_path" \
            --uploads "$case_root/restore-uploads" --env "$case_root/restore.env" --db-host localhost --platform linux --yes \
            > "$output_file" 2>&1; then
        printf 'Expected a raw archive extraction failure to map to exit code 20.\n' >&2
        exit 1
    else
        status=$?
    fi
    [[ $status == 20 ]]
    assert_contains "$output_file" 'Restore command failed with status'
    [[ ! -s $case_root/mysql.log ]]
    rm -f -- "$restore_bin/tar"
    status=0
    if MYSQL_LOG="$case_root/mysql.log" RESTORE_MYSQL_FAIL=1 PATH="$restore_bin:$PATH" \
        bash "$source_root/scripts/container/restore.sh" --archive "$archive_path" \
            --uploads "$case_root/restore-uploads" --env "$case_root/restore.env" --db-host localhost --platform linux --yes \
            > "$output_file" 2>&1; then
        printf 'Expected the simulated database import to fail.\n' >&2
        exit 1
    else
        status=$?
    fi
    [[ $status == 21 ]]
    assert_contains "$output_file" 'Database restore failed'
    assert_contains "$case_root/mysql.log" 'SELECT 1'
}

# Restore from the current checkout before switching to the saved rollback commit.
test_rollback_restores_before_checkout() {
    make_case rollback-restore-order
    write_rollback_record "$base_commit" 0 0 '' ''
    "$real_git" -C "$checkout" pull --ff-only origin develop >/dev/null 2>&1
    if ! run_shop rollback --yes; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$event_file" "RESTORE_HEAD=$latest_commit"
    [[ $("$real_git" -C "$checkout" rev-parse HEAD) == "$base_commit" ]]
    assert_contains "$data_dir/rollback.conf" 'ROLLBACK_ACTIVE=1'
    [[ ! -e $data_dir/rollback.unfinished ]]
    [[ ! -e $data_dir/restore.unfinished ]]
}

# Finish the client recovery path without replacing its saved rolled-back-from values.
test_rollback_retry_keeps_recorded_source_values() {
    make_case rollback-retry-recorded-source
    write_rollback_record "$base_commit" 0 0 "$latest_commit" "$digest_new" "$new_image_id"
    "$real_git" -C "$checkout" checkout --detach "$base_commit" >/dev/null 2>&1
    : > "$data_dir/rollback.unfinished"
    "$real_git" -C "$checkout" switch develop >/dev/null 2>&1
    "$real_git" -C "$checkout" pull --ff-only origin develop >/dev/null 2>&1
    if ! run_shop rollback --yes; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$data_dir/rollback.conf" "ROLLBACK_FROM_COMMIT=$latest_commit"
    assert_contains "$data_dir/rollback.conf" "ROLLBACK_FROM_DIGEST=$digest_new"
    assert_contains "$data_dir/rollback.conf" "ROLLBACK_FROM_IMAGE_ID=$new_image_id"
    [[ $("$real_git" -C "$checkout" rev-parse HEAD) == "$base_commit" ]]
    [[ ! -e $data_dir/rollback.unfinished ]]
    [[ ! -e $data_dir/restore.unfinished ]]
}

# Keep rollback recovery state after checkout failure and finish on the next retry.
test_rollback_retries_after_checkout_failure() {
    make_case rollback-checkout-retry
    write_rollback_record "$base_commit" 0 0 '' ''
    "$real_git" -C "$checkout" pull --ff-only origin develop >/dev/null 2>&1
    if GIT_CHECKOUT_FAIL=1 run_shop rollback --yes; then
        printf 'Expected the saved-code checkout to fail after restore.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'Recovery command: ./shop rollback'
    assert_not_contains "$output_file" 'Recovery command: git switch develop'
    assert_contains "$event_file" "RESTORE_HEAD=$latest_commit"
    [[ -e $data_dir/rollback.unfinished ]]
    [[ ! -e $data_dir/restore.unfinished ]]
    assert_contains "$data_dir/rollback.conf" "ROLLBACK_FROM_COMMIT=$latest_commit"
    if ! run_shop rollback --yes; then cat "$output_file" >&2; exit 1; fi
    [[ $("$real_git" -C "$checkout" rev-parse HEAD) == "$base_commit" ]]
    [[ ! -e $data_dir/rollback.unfinished ]]
    assert_contains "$data_dir/rollback.conf" "ROLLBACK_FROM_COMMIT=$latest_commit"
}

# Recover after rollback checks out pre-fix code while local develop trails the fixed remote.
test_rollback_recovers_from_pre_fix_saved_code() {
    local pre_fix_commit fixed_commit restore_count expected_advice actual_advice
    make_case rollback-pre-fix-saved-code
    "$real_git" -C "$seed_repo" checkout -b pre-fix "$latest_commit" >/dev/null 2>&1
    "$real_git" -C "$source_root" show 693e31202:shop > "$seed_repo/shop"
    "$real_git" -C "$source_root" show 693e31202:scripts/restore.sh > "$seed_repo/scripts/restore.sh"
    chmod +x "$seed_repo/shop" "$seed_repo/scripts/restore.sh"
    "$real_git" -C "$seed_repo" add shop scripts/restore.sh
    "$real_git" -C "$seed_repo" commit -m 'pre-fix rollback recovery code' >/dev/null
    pre_fix_commit=$("$real_git" -C "$seed_repo" rev-parse HEAD)
    "$real_git" -C "$seed_repo" checkout develop >/dev/null 2>&1
    "$real_git" -C "$seed_repo" merge --ff-only pre-fix >/dev/null 2>&1
    "$real_git" -C "$seed_repo" push origin develop >/dev/null 2>&1
    cp -- "$source_root/shop" "$seed_repo/shop"
    cp -- "$source_root/scripts/restore.sh" "$seed_repo/scripts/restore.sh"
    chmod +x "$seed_repo/shop" "$seed_repo/scripts/restore.sh"
    "$real_git" -C "$seed_repo" add shop scripts/restore.sh
    "$real_git" -C "$seed_repo" commit -m 'restore fixed rollback recovery code' >/dev/null
    fixed_commit=$("$real_git" -C "$seed_repo" rev-parse HEAD)
    "$real_git" -C "$seed_repo" push origin develop >/dev/null 2>&1
    latest_commit=$fixed_commit
    "$real_git" -C "$checkout" fetch origin develop >/dev/null 2>&1
    "$real_git" -C "$checkout" reset --hard "$pre_fix_commit" >/dev/null 2>&1
    [[ $("$real_git" -C "$checkout" rev-parse develop) == "$pre_fix_commit" ]]
    [[ $("$real_git" -C "$checkout" rev-parse origin/develop) == "$fixed_commit" ]]
    if "$real_git" -C "$checkout" show develop:shop | grep -Fq 'rollback_checkout_succeeded'; then
        printf 'Expected local develop to keep the pre-fix shop launcher.\n' >&2
        exit 1
    fi
    if "$real_git" -C "$checkout" show develop:scripts/restore.sh | grep -Fq 'OSPOS_RESTORE_FOR_ROLLBACK'; then
        printf 'Expected local develop to keep the pre-fix restore guard.\n' >&2
        exit 1
    fi
    "$real_git" -C "$checkout" checkout --detach "$fixed_commit" >/dev/null 2>&1
    base_commit=$pre_fix_commit
    write_rollback_record "$base_commit" 0 0 "$latest_commit" "$digest_old" "$old_image_id"

    if ROLLBACK_APP_UP_FAIL=1 run_shop rollback --yes; then
        printf 'Expected the saved-image start to fail after checking out the pre-fix code.\n' >&2
        exit 1
    fi
    expected_advice=$'Recovery command: git switch develop\nRecovery command: git pull --ff-only origin develop\nRecovery command: ./shop rollback'
    actual_advice=$(grep -F 'Recovery command:' "$output_file")
    [[ $actual_advice == "$expected_advice" ]]
    [[ -z $("$real_git" -C "$checkout" symbolic-ref --quiet --short HEAD 2>/dev/null || true) ]]
    [[ $("$real_git" -C "$checkout" rev-parse HEAD) == "$pre_fix_commit" ]]
    [[ -e $data_dir/rollback.unfinished ]]
    [[ -z $("$real_git" -C "$checkout" status --porcelain --untracked-files=all) ]]
    assert_contains "$data_dir/rollback.conf" "ROLLBACK_FROM_COMMIT=$latest_commit"

    "$real_git" -C "$checkout" switch develop >/dev/null 2>&1
    [[ $("$real_git" -C "$checkout" branch --show-current) == develop ]]
    [[ $("$real_git" -C "$checkout" rev-parse HEAD) == "$pre_fix_commit" ]]
    "$real_git" -C "$checkout" pull --ff-only origin develop >/dev/null 2>&1
    [[ $("$real_git" -C "$checkout" rev-parse HEAD) == "$fixed_commit" ]]
    if ! run_shop rollback --yes; then cat "$output_file" >&2; exit 1; fi
    [[ $("$real_git" -C "$checkout" rev-parse HEAD) == "$pre_fix_commit" ]]
    [[ ! -e $data_dir/rollback.unfinished ]]
    [[ ! -e $data_dir/restore.unfinished ]]
    [[ ! -e $data_dir/update.in-progress ]]
    assert_contains "$data_dir/rollback.conf" 'ROLLBACK_ACTIVE=1'
    assert_contains "$data_dir/rollback.conf" "ROLLBACK_FROM_COMMIT=$latest_commit"
    assert_contains "$data_dir/rollback.conf" "ROLLBACK_FROM_DIGEST=$digest_old"
    assert_contains "$data_dir/rollback.conf" "ROLLBACK_FROM_IMAGE_ID=$old_image_id"
    restore_count=$(grep -Fc "RESTORE_HEAD=$latest_commit" "$event_file")
    [[ $restore_count == 2 ]]
}

# Give detached saved-code checkouts the same recovery advice as post-checkout failures.
test_status_advises_switch_from_detached_saved_commit() {
    make_case status-detached-rollback-recovery
    write_rollback_record "$base_commit" 0 0 '' ''
    : > "$data_dir/rollback.unfinished"
    if ! run_shop status; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'Recovery command: ./shop rollback'
    assert_not_contains "$output_file" 'Recovery command: git switch develop'

    "$real_git" -C "$checkout" checkout --detach "$base_commit" >/dev/null 2>&1
    if ! run_shop status; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'Recovery command: git switch develop'
    assert_contains "$output_file" 'Recovery command: git pull --ff-only origin develop'
    assert_contains "$output_file" 'Recovery command: ./shop rollback'
}

# Recover an update that already saved a rollback point and clear its update marker.
test_rollback_recovers_update_pending() {
    make_case rollback-update-pending
    write_rollback_record "$base_commit" 0 1 '' ''
    printf 'UPDATE_SOURCE_IMAGE_ID=%s\n' "$old_image_id" > "$data_dir/update.in-progress"
    "$real_git" -C "$checkout" pull --ff-only origin develop >/dev/null 2>&1
    if ! run_shop rollback --yes; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$event_file" "RESTORE_HEAD=$latest_commit"
    assert_contains "$data_dir/rollback.conf" 'UPDATE_PENDING=0'
    [[ ! -e $data_dir/update.in-progress ]]
    [[ ! -e $data_dir/rollback.unfinished ]]
    [[ ! -e $data_dir/restore.unfinished ]]
}

# Save the rolled-back code and image digests, and clear any restore marker on success.
test_rollback_records_source_version() {
    make_case rollback-source-record
    write_rollback_record "$base_commit" 0 0 '' ''
    : > "$data_dir/restore.unfinished"
    "$real_git" -C "$checkout" pull --ff-only origin develop >/dev/null 2>&1
    if ! run_shop rollback --yes; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$data_dir/rollback.conf" "ROLLBACK_FROM_COMMIT=$latest_commit"
    assert_contains "$data_dir/rollback.conf" "ROLLBACK_FROM_DIGEST=$digest_old"
    assert_contains "$data_dir/rollback.conf" "ROLLBACK_FROM_IMAGE_ID=$old_image_id"
    assert_contains "$data_dir/rollback.conf" 'ROLLBACK_ACTIVE=1'
    [[ ! -e $data_dir/rollback.unfinished ]]
    [[ ! -e $data_dir/restore.unfinished ]]
    [[ ! -e $data_dir/.shop-command.lock ]]
}

# Block an update when the rolled-back image digest is still published, even if code advanced.
test_update_blocks_rolled_back_image_digest() {
    make_case rolled-version-warning
    write_rollback_record "$base_commit" 1 0 "$middle_commit" "$digest_old"
    remote_digest=$digest_old
    if run_shop update --yes; then
        printf 'Expected update to stop while the rolled-back image digest is still published.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'Update is blocked until the published image digest changes'
    assert_contains "$output_file" 'Nothing was changed. Safe next step: ./shop start'
    [[ ! -e $data_dir/backups/ospos-backup-20260924-010101.tar.gz ]]
    if grep -Fq 'PULL' "$event_file"; then
        printf 'Update pulled the rolled-back image.\n' >&2
        exit 1
    fi
}

# Clear a failed pre-backup update marker when the retry finds the old image still published.
test_update_retry_clears_marker_after_broken_image_guard() {
    make_case update-retry-rolled-image
    write_rollback_record "$base_commit" 1 0 "$middle_commit" "$digest_old"
    remote_digest=$digest_new
    if PULL_FAIL=1 run_shop update --yes; then
        printf 'Expected the first update pull to fail.\n' >&2
        exit 1
    fi
    [[ -e $data_dir/update.in-progress ]]
    assert_contains "$data_dir/update.in-progress" "UPDATE_SOURCE_IMAGE_ID=$old_image_id"
    [[ $(<"$state_dir/app_image_id") == "$old_image_id" ]]

    : > "$event_file"
    : > "$docker_log"
    remote_digest=$digest_old
    if run_shop update --yes; then
        printf 'Expected the update retry to stop while the rolled-back image is published.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'Update is blocked until the published image digest changes'
    assert_contains "$output_file" 'Nothing was changed. Safe next step: ./shop start'
    [[ ! -e $data_dir/update.in-progress ]]
    if grep -Fq 'PULL' "$event_file"; then
        printf 'The retry pulled the rolled-back image after the guard fired.\n' >&2
        exit 1
    fi

    if ! run_shop start; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$event_file" 'COMPOSE_UP'
    [[ $(<"$state_dir/app_image_id") == "$old_image_id" ]]
}

# Keep restore refused during update recovery and use rollback when its point exists.
test_missing_update_source_image_requires_manual_recovery_and_rollback() {
    make_case missing-update-source
    write_rollback_record "$base_commit" 0 1 '' ''
    printf 'UPDATE_SOURCE_IMAGE_ID=%s\n' "$old_image_id" > "$data_dir/update.in-progress"
    if SOURCE_IMAGE_ID_MISSING=1 run_shop update --yes; then
        printf 'Expected update retry to stop when its pinned image is missing.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'pinned app image is missing'
    assert_contains "$output_file" 'docs/operations-runbook.md#recover-a-missing-pinned-image'
    assert_contains "$output_file" 'Recovery command: ./shop rollback'
    : > "$docker_log"
    if run_shop restore ARCHIVE=ospos-backup-20260924-010001.tar.gz --yes; then
        printf 'Restore must stay refused while the update marker exists.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'Restore is refused while an update is unfinished'
    assert_contains "$output_file" 'Nothing was changed. Safe next step: ./shop rollback'
    ! grep -Fq 'compose stop' "$docker_log"
    [[ -e $data_dir/update.in-progress ]]
    [[ ! -e $data_dir/rollback.unfinished ]]
    if ! run_shop rollback --yes; then cat "$output_file" >&2; exit 1; fi
    [[ ! -e $data_dir/update.in-progress ]]
    assert_contains "$data_dir/rollback.conf" 'UPDATE_PENDING=0'
    assert_contains "$event_file" RESTORE
    assert_contains "$event_file" COMPOSE_UP
}

# Use the saved image ID guard when the source digest is missing.
test_update_with_missing_rollback_digest_uses_image_id_guard() {
    make_case missing-rollback-digest
    write_rollback_record "$base_commit" 0 0 '' ''
    "$real_git" -C "$checkout" pull --ff-only origin develop >/dev/null 2>&1
    if ! SOURCE_DIGEST_MISSING=1 run_shop rollback --yes; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'current app image digest could not be recorded'
    assert_contains "$data_dir/rollback.conf" "ROLLBACK_FROM_IMAGE_ID=$old_image_id"
    if ! grep -Fxq 'ROLLBACK_FROM_DIGEST=' "$data_dir/rollback.conf"; then
        printf 'Expected rollback to save an empty source digest.\n' >&2
        cat "$data_dir/rollback.conf" >&2
        exit 1
    fi
    remote_digest=$digest_new
    if PULL_SAME_IMAGE=1 run_shop update --yes; then
        printf 'Expected update to refuse the image that was rolled back.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'matches the image that was rolled back'
    [[ ! -e $data_dir/update.in-progress ]]
    if grep -Fq 'BACKUP' "$event_file"; then
        printf 'Update took a backup after pulling the rolled-back image.\n' >&2
        exit 1
    fi
    if ! run_shop update --yes; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$data_dir/rollback.conf" 'ROLLBACK_ACTIVE=0'
}

# Refuse --yes when rollback saved neither image identifier.
test_update_requires_typed_unknown_image_confirmation() {
    make_case unknown-rollback-image
    write_rollback_record "$base_commit" 0 0 '' ''
    "$real_git" -C "$checkout" pull --ff-only origin develop >/dev/null 2>&1
    if ! SOURCE_DIGEST_MISSING=1 SOURCE_IMAGE_ID_MISSING=1 run_shop rollback --yes; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$data_dir/rollback.conf" 'ROLLBACK_FROM_DIGEST='
    assert_contains "$data_dir/rollback.conf" 'ROLLBACK_FROM_IMAGE_ID='
    remote_digest=$digest_new
    if run_shop update --yes; then
        printf 'Expected --yes to be refused when both rollback image identifiers are missing.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" '--yes cannot override this check'
    if grep -Fq 'PULL' "$event_file"; then
        printf 'Update pulled an image without either rollback image identifier.\n' >&2
        exit 1
    fi
    if run_shop update --yes --allow-unknown-image; then
        printf 'Expected a typed confirmation to require an interactive terminal.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'Run update in a terminal with --allow-unknown-image'
    if grep -Fq 'PULL' "$event_file"; then
        printf 'Update pulled an image without a typed unknown-image confirmation.\n' >&2
        exit 1
    fi
}

# Restart after a first pre-import failure and keep earlier or uncertain restore markers.
test_restore_failure_hints_match_restore_phase() {
    make_case restore-failure
    if RESTORE_EXIT_CODE=20 run_shop restore ARCHIVE=ospos-backup-20260924-010001.tar.gz --yes; then
        printf 'Expected the pre-database restore stub to fail.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'Nothing was changed; restarting the shop'
    [[ ! -e $data_dir/restore.unfinished ]]
    assert_contains "$event_file" 'COMPOSE_UP'

    : > "$event_file"
    if RESTORE_EXIT_CODE=21 run_shop restore ARCHIVE=ospos-backup-20260924-010001.tar.gz --yes; then
        printf 'Expected the post-database restore stub to fail.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'The database may be partial'
    assert_contains "$output_file" 'Recovery command: ./shop restore ARCHIVE=<known-good-backup>'
    [[ -f $data_dir/restore.unfinished ]]
    : > "$event_file"
    : > "$docker_log"
    if grep -Fq 'then run ./shop start' "$output_file"; then
        printf 'Restore suggested starting after database work may have begun.\n' >&2
        exit 1
    fi
    if RESTORE_EXIT_CODE=20 run_shop restore ARCHIVE=ospos-backup-20260924-010001.tar.gz --yes; then
        printf 'Expected a pre-database restore retry to fail.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'Restore did not complete'
    assert_contains "$output_file" 'Recovery command: ./shop restore ARCHIVE=<known-good-backup>'
    [[ -f $data_dir/restore.unfinished ]]
    if grep -Fq 'up -d --pull never ospos' "$docker_log"; then
        printf 'A pre-import retry started the app while an earlier restore marker existed.\n' >&2
        exit 1
    fi
    if grep -Fq 'then run ./shop start' "$output_file"; then
        printf 'Restore suggested starting after an earlier partial restore.\n' >&2
        exit 1
    fi
    if RESTORE_EXIT_CODE=1 run_shop restore ARCHIVE=ospos-backup-20260924-010001.tar.gz --yes; then
        printf 'Expected an unclassified restore stub failure.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'The database may be partial'
    [[ -f $data_dir/restore.unfinished ]]
    if grep -Fq 'then run ./shop start' "$output_file"; then
        printf 'Restore suggested starting after an unclassified failure.\n' >&2
        exit 1
    fi
    [[ ! -e $data_dir/.shop-command.lock ]]
}

# Suggest a restore when an active rollback makes another rollback unavailable.
test_restore_advice_during_active_rollback() {
    make_case restore-advice-active-rollback
    write_rollback_record "$base_commit" 1 0 "$middle_commit" "$digest_old"
    : > "$data_dir/restore.unfinished"
    if run_shop start; then
        printf 'Expected start to refuse the unfinished restore.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'Recovery command: ./shop restore ARCHIVE=<known-good-backup>'
    if grep -Fq './shop rollback' "$output_file"; then
        printf 'Restore recovery advice suggested a rollback that is blocked while rollback is active.\n' >&2
        exit 1
    fi

    : > "$docker_log"
    if run_shop rollback; then
        printf 'Expected another rollback to be refused while rollback is active.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'A rollback is already active'
    [[ ! -s $docker_log ]]

    if ! RESTORE_EXIT_CODE=0 run_shop restore ARCHIVE=ospos-backup-20260924-010001.tar.gz --yes; then
        cat "$output_file" >&2
        exit 1
    fi
    [[ ! -e $data_dir/restore.unfinished ]]
}

# Keep a failed restore blocked from start and update until a later restore succeeds.
test_restore_marker_blocks_start_and_update_until_success() {
    make_case restore-marker-retry
    if RESTORE_EXIT_CODE=21 run_shop restore ARCHIVE=ospos-backup-20260924-010001.tar.gz --yes; then
        printf 'Expected a possibly partial restore to fail.\n' >&2
        exit 1
    fi
    [[ -f $data_dir/restore.unfinished ]]
    : > "$docker_log"
    if run_shop start; then
        printf 'Expected start to refuse an unfinished restore.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'restore did not complete successfully'
    [[ ! -s $docker_log ]]
    : > "$docker_log"
    if run_shop update --yes; then
        printf 'Expected update to refuse an unfinished restore.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'restore did not complete successfully'
    [[ ! -s $docker_log ]]
    if ! RESTORE_EXIT_CODE=0 run_shop restore ARCHIVE=ospos-backup-20260924-010001.tar.gz --yes; then
        cat "$output_file" >&2
        exit 1
    fi
    [[ ! -e $data_dir/restore.unfinished ]]
    assert_contains "$docker_log" 'compose'
    [[ ! -e $data_dir/.shop-command.lock ]]
}

# Leave a persistent marker when the restore process is interrupted after launch.
test_interrupted_restore_leaves_marker() {
    make_case interrupted-restore
    if RESTORE_EXIT_CODE=137 run_shop restore ARCHIVE=ospos-backup-20260924-010001.tar.gz --yes; then
        printf 'Expected an interrupted restore to fail.\n' >&2
        exit 1
    fi
    [[ -f $data_dir/restore.unfinished ]]
    assert_contains "$output_file" 'Recovery command: ./shop restore ARCHIVE=<known-good-backup>'
    [[ ! -e $data_dir/.shop-command.lock ]]
}

# Ensure Windows always uses the agreed client folder even when the environment sets another path.
test_windows_client_path_ignores_environment() {
    make_case windows-client-path
    cat > "$bin_dir/uname" <<'UNAME'
#!/usr/bin/env bash
printf 'MINGW64_NT-10.0\n'
UNAME
    cat > "$bin_dir/powershell.exe" <<'POWERSHELL'
#!/usr/bin/env bash
if [[ $* == *Get-Volume* ]]; then printf 'NTFS\n'; fi
POWERSHELL
    chmod +x "$bin_dir/uname" "$bin_dir/powershell.exe"
    (
        cd "$checkout"
        REAL_GIT="$real_git" STATE_DIR="$state_dir" DOCKER_LOG="$docker_log" EVENTS="$event_file" \
            REMOTE_DIGEST="$digest_new" PULLED_IMAGE_ID="$new_image_id" \
            OSPOS_DATA_DIR="$case_root/wrong-data" HOME="$case_root/home" PATH="$bin_dir:$PATH" \
            ./shop check
    ) > "$output_file" 2>&1 || { cat "$output_file" >&2; exit 1; }
    [[ -d $checkout/C:/OSPOS/Client ]]
    [[ ! -e $case_root/wrong-data ]]
}

# Validate archives under C:/OSPOS/Client with a tar that, like Git Bash tar, reads C: as a remote host without --force-local.
test_windows_archive_validation_uses_local_tar_paths() {
    make_case windows-archive-tar
    local real_tar
    real_tar=$(type -P tar)
    cat > "$bin_dir/uname" <<'UNAME'
#!/usr/bin/env bash
printf 'MINGW64_NT-10.0\n'
UNAME
    cat > "$bin_dir/powershell.exe" <<'POWERSHELL'
#!/usr/bin/env bash
if [[ $* == *Get-Volume* ]]; then printf 'NTFS\n'; fi
POWERSHELL
    cat > "$bin_dir/tar" <<TAR
#!/usr/bin/env bash
force_local=0
for argument in "\$@"; do
    [[ \$argument == --force-local ]] && force_local=1
done
if (( force_local == 0 )); then
    for argument in "\$@"; do
        if [[ \$argument =~ ^[A-Za-z]: ]]; then
            printf 'tar (child): Cannot connect to %s: resolve failed\n' "\${argument%%:*}" >&2
            exit 2
        fi
    done
fi
exec "$real_tar" "\$@"
TAR
    chmod +x "$bin_dir/uname" "$bin_dir/powershell.exe" "$bin_dir/tar"
    data_dir="$checkout/C:/OSPOS/Client"
    prepare_client_data
    create_backup_archive "$data_dir/backups/ospos-backup-20260924-010002.tar.gz" \
        "$data_dir/db-state.txt" other_shop
    if run_shop restore ARCHIVE=ospos-backup-20260924-010002.tar.gz --yes; then
        printf 'Expected a backup for another database to be refused.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'belongs to database other_shop, not the configured shop database ospos'
    if grep -Fq 'Cannot connect to' "$output_file"; then
        cat "$output_file" >&2
        exit 1
    fi
    [[ ! -s $docker_log ]]
}

# Let status inspect a held lock, log a failed scheduled backup, and protect a live owner's lock.
test_shared_lock_blocks_parallel_commands() {
    make_case shared-lock
    mkdir -p -- "$data_dir/.shop-command.lock"
    printf '%s\n' "$$" > "$data_dir/.shop-command.lock/pid"
    printf '%s\n' "$(hostname 2>/dev/null || uname -n)" > "$data_dir/.shop-command.lock/host"
    printf 'update\n' > "$data_dir/.shop-command.lock/command"
    printf '2026-09-24T07:17:21Z\n' > "$data_dir/.shop-command.lock/started"
    printf '%s\n' "$(date +%s)" > "$data_dir/.shop-command.lock/started_epoch"
    if ! run_shop status; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'Shop command lock: held'
    assert_contains "$output_file" 'age='
    assert_contains "$output_file" "pid=$$"
    assert_contains "$output_file" 'host='
    assert_contains "$output_file" 'command=update'
    mkdir -p -- "$data_dir/uploads" "$data_dir/secrets"
    : > "$data_dir/secrets/app.env"
    : > "$docker_log"
    if OSPOS_DATA_DIR="$data_dir" DOCKER_LOG="$docker_log" PATH="$bin_dir:$PATH" \
        bash "$source_root/scripts/backup.sh" > "$output_file" 2>&1; then
        printf 'Expected the scheduled backup launcher to respect the active lock.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'Another shop command or backup is running'
    assert_contains "$output_file" 'age:'
    assert_contains "$data_dir/backups/backup.log" 'result=failed'
    assert_contains "$data_dir/backups/backup.log" 'age_'
    assert_contains "$data_dir/backups/backup.log" 'shop_unlock'
    [[ ! -s $docker_log ]]
    if ! run_shop status; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'Last backup: '
    assert_contains "$output_file" 'result=failed'
    if run_shop unlock --yes; then
        printf 'Expected --yes to be insufficient to clear a lock.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'Shop command lock: held'
    assert_contains "$output_file" "pid=$$"
    assert_contains "$output_file" 'No lock confirmation was given'
    [[ -d $data_dir/.shop-command.lock ]]
    if ! run_shop_with_input 'UNLOCK SHOP LOCK' unlock --yes; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'Shop command lock cleared.'
    [[ ! -e $data_dir/.shop-command.lock ]]
}

# Check recovery advice for all marker, rollback, update, and lock state combinations.
test_recovery_advice_commands_are_allowed() {
    make_case recovery-advice-matrix
    local update_flag rollback_flag restore_flag lock_flag active_flag pending_flag expected archive app_image_id code_version
    archive=ospos-backup-20260924-010001.tar.gz
    create_backup_archive "$data_dir/backups/$archive" "$data_dir/db-state.txt"
    for update_flag in 0 1; do
        for rollback_flag in 0 1; do
            for restore_flag in 0 1; do
                for active_flag in 0 1; do
                    for pending_flag in 0 1; do
                        for lock_flag in 0 1; do
                            "$real_git" -C "$checkout" checkout -f develop >/dev/null 2>&1
                            "$real_git" -C "$checkout" reset --hard "$base_commit" >/dev/null
                            printf '%s\n' "$old_image_id" > "$state_dir/app_image_id"
                            printf '%s\n' "$old_image_id" > "$state_dir/develop_image_id"
                            printf '%s\n' "$old_image_id" > "$state_dir/rollback_image_id"
                            printf '%s\n' "$old_image_id" > "$state_dir/update_source_image_id"
                            printf '%s\n' "$digest_old" > "$state_dir/local_digest"
                            printf '%s\n' "$digest_old" > "$state_dir/app_digest"
                            : > "$event_file"
                            : > "$docker_log"
                            rm -f -- "$data_dir/update.in-progress" "$data_dir/rollback.unfinished" \
                                "$data_dir/restore.unfinished" "$data_dir/rollback.conf"
                            rm -rf -- "$data_dir/rollback" "$data_dir/.shop-command.lock"
                            mkdir -p -- "$data_dir/rollback"
                            (( update_flag == 0 )) || printf 'UPDATE_SOURCE_IMAGE_ID=%s\n' "$old_image_id" > "$data_dir/update.in-progress"
                            (( restore_flag == 0 )) || : > "$data_dir/restore.unfinished"
                            if (( rollback_flag == 1 || active_flag == 1 || pending_flag == 1 )); then
                                write_rollback_record "$base_commit" "$active_flag" "$pending_flag" "$middle_commit" "$digest_old" "$old_image_id"
                            fi
                            (( rollback_flag == 0 )) || : > "$data_dir/rollback.unfinished"
                            if (( lock_flag == 1 )); then write_old_lock; fi
                            if ! run_shop status; then cat "$output_file" >&2; exit 1; fi
                            if (( lock_flag == 1 )); then
                                assert_contains "$output_file" 'Shop command lock: held'
                                assert_contains "$output_file" 'age=1m'
                                assert_contains "$output_file" 'pid=987654321'
                                assert_contains "$output_file" 'host='
                                assert_contains "$output_file" 'command=backup'
                                expected='./shop unlock'
                                assert_contains "$output_file" "Recovery command: $expected"
                                if ! run_shop_with_input 'UNLOCK SHOP LOCK' unlock; then cat "$output_file" >&2; exit 1; fi
                                if ! run_shop status; then cat "$output_file" >&2; exit 1; fi
                            fi
                            if (( rollback_flag == 1 || pending_flag == 1 )); then
                                expected='./shop rollback'
                            elif (( restore_flag == 1 && update_flag == 1 )); then
                                expected='./shop status'
                            elif (( restore_flag == 1 )); then
                                expected='./shop restore ARCHIVE=<known-good-backup>'
                            elif (( update_flag == 1 )); then
                                expected='./shop update'
                            elif (( active_flag == 1 )); then
                                expected='./shop start'
                            else
                                expected=''
                            fi
                            if [[ -n $expected ]]; then
                                assert_contains "$output_file" "Recovery command: $expected"
                                case $expected in
                                    './shop rollback')
                                        if ! run_shop rollback --yes; then cat "$output_file" >&2; exit 1; fi
                                        ;;
                                    './shop restore ARCHIVE=<known-good-backup>')
                                        if ! run_shop restore "ARCHIVE=$archive" --yes; then cat "$output_file" >&2; exit 1; fi
                                        ;;
                                    './shop update')
                                        if ! run_shop update --yes; then cat "$output_file" >&2; exit 1; fi
                                        ;;
                                    './shop start')
                                        if ! run_shop start; then cat "$output_file" >&2; exit 1; fi
                                        ;;
                                    './shop status')
                                        if ! run_shop status; then cat "$output_file" >&2; exit 1; fi
                                        ;;
                                esac
                            elif grep -Fq 'Recovery command:' "$output_file"; then
                                printf 'Unexpected recovery advice with no recovery state.\n' >&2
                                cat "$output_file" >&2
                                exit 1
                            fi
                            if (( update_flag == 1 )); then
                                app_image_id=$(<"$state_dir/app_image_id")
                                code_version=$(<"$checkout/version.txt")
                                if [[ $app_image_id == "$new_image_id" && $code_version != latest ]]; then
                                    printf 'Recovery started the new image with old code.\n' >&2
                                    cat "$output_file" >&2
                                    exit 1
                                fi
                            fi
                        done
                    done
                done
            done
        done
    done
}

# Hold the client lock before setup so a second first install cannot enter setup.
test_concurrent_first_installs_share_the_lock() {
    make_case concurrent-first-install
    local first_pid
    rm -rf -- "$data_dir"
    cat > "$checkout/scripts/setup-client.sh" <<'SETUP'
#!/usr/bin/env bash
set -euo pipefail
[[ -d $OSPOS_DATA_DIR/.shop-command.lock && -f $OSPOS_DATA_DIR/.shop-command.lock/pid ]] || exit 91
count=0
[[ ! -f $SETUP_STATE ]] || count=$(<"$SETUP_STATE")
count=$((count + 1))
printf '%s\n' "$count" > "$SETUP_STATE"
if (( count == 1 )); then
    : > "$SETUP_ENTERED"
    while [[ ! -e $SETUP_RELEASE ]]; do sleep 0.02; done
else
    exit 92
fi
printf 'OSPOS_HTTP_PORT=18080\nOSPOS_BACKUP_DESTINATION=backups\n' > "$OSPOS_DATA_DIR/ospos.conf"
mkdir -p -- "$OSPOS_DATA_DIR/uploads" "$OSPOS_DATA_DIR/backups"
SETUP
    chmod +x "$checkout/scripts/setup-client.sh"
    cat > "$bin_dir/ss" <<'SS'
#!/usr/bin/env bash
exit 0
SS
    chmod +x "$bin_dir/ss"
    (
        cd "$checkout"
        REAL_GIT="$real_git" STATE_DIR="$state_dir" DOCKER_LOG="$docker_log" EVENTS="$event_file" \
            REMOTE_DIGEST="$remote_digest" PULLED_IMAGE_ID="$new_image_id" \
            OSPOS_DATA_DIR="$data_dir" HOME="$case_root/home" PATH="$bin_dir:$PATH" \
            SETUP_STATE="$case_root/setup-count" SETUP_ENTERED="$case_root/setup-entered" \
            SETUP_RELEASE="$case_root/setup-release" ./shop install --yes
    ) > "$case_root/first-install.log" 2>&1 &
    first_pid=$!
    for attempt in {1..200}; do
        [[ -e $case_root/setup-entered ]] && break
        sleep 0.02
    done
    [[ -e $case_root/setup-entered ]] || { cat "$case_root/first-install.log" >&2; exit 1; }
    if run_shop install --yes; then
        printf 'Expected a second first install to stop at the shared lock.\n' >&2
        exit 1
    fi
    assert_contains "$output_file" 'Another shop command or backup is running'
    [[ $(<"$case_root/setup-count") == 1 ]]
    : > "$case_root/setup-release"
    if ! wait "$first_pid"; then cat "$case_root/first-install.log" >&2; exit 1; fi
    assert_contains "$case_root/first-install.log" 'Manual steps:'
    [[ -f $data_dir/ospos.conf ]]
    [[ ! -e $data_dir/.shop-command.lock ]]
}

# Let Linux setup populate a client folder that contains only the active lock.
test_locked_first_install_setup_accepts_lock_folder() {
    make_case locked-first-install-setup
    local setup_dir="$case_root/locked-client"
    mkdir -p -- "$setup_dir/.shop-command.lock"
    printf '12345\n' > "$setup_dir/.shop-command.lock/pid"
    bash "$source_root/scripts/container/setup-client.sh" \
        --data-directory "$setup_dir" --host-data-directory "$setup_dir" --platform linux --locked-directory \
        > "$output_file" 2>&1 || { cat "$output_file" >&2; exit 1; }
    [[ -f $setup_dir/ospos.conf ]]
    [[ -f $setup_dir/secrets/app.env ]]
    [[ -f $setup_dir/uploads/item_pics/.keep ]] || [[ -d $setup_dir/uploads/item_pics ]]
    [[ -d $setup_dir/.shop-command.lock ]]
}

# Keep Windows ACL warnings from blocking check or install prerequisites.
test_windows_secrets_folder_check() {
    make_case windows-secrets-check
    prepare_windows_client
    if ! WINDOWS_MODE=1 PS_ACL_MODE=ok run_shop check; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'ok: the secrets folder is limited to the shop account.'
    if ! WINDOWS_MODE=1 PS_ACL_MODE=inherited run_shop check; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'warning: the secrets folder has inherited access rules'
    if ! WINDOWS_MODE=1 PS_ACL_MODE=everyone run_shop check; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'warning: the secrets folder grants access to Everyone'
    rm -rf -- "$windows_client_dir/secrets"
    if ! WINDOWS_MODE=1 run_shop check; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'warning: no secrets folder yet'
}

# Check empty, present, and missing backup-copy folders without changing check status.
test_backup_copy_folder_check() {
    make_case backup-copy-check
    local copy_folder="$case_root/usb-backups"
    if ! run_shop check; then cat "$output_file" >&2; exit 1; fi
    assert_not_contains "$output_file" 'backup copy folder'
    mkdir -p -- "$copy_folder"
    printf "OSPOS_HTTP_PORT=18080\nOSPOS_BACKUP_DESTINATION=backups\nOSPOS_BACKUP_COPY_TO='%s'\n" "$copy_folder" > "$data_dir/ospos.conf"
    if ! run_shop check; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" "ok: backup copy folder $copy_folder exists."
    printf "OSPOS_HTTP_PORT=18080\nOSPOS_BACKUP_DESTINATION=backups\nOSPOS_BACKUP_COPY_TO='%s/missing'\n" "$copy_folder" > "$data_dir/ospos.conf"
    if ! run_shop check; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'warning: backup copy folder'
    printf "OSPOS_HTTP_PORT=18080\nOSPOS_BACKUP_DESTINATION=backups\nOSPOS_BACKUP_COPY_TO='a'\nOSPOS_BACKUP_COPY_TO='b'\n" > "$data_dir/ospos.conf"
    if ! run_shop check; then cat "$output_file" >&2; exit 1; fi
    assert_not_contains "$output_file" 'backup copy folder'
}

# Convert Windows USB paths through cygpath before checking for their folders.
test_windows_backup_copy_folder_check() {
    make_case windows-copy-folder-check
    prepare_windows_client
    local copy_folder="$case_root/windows-usb"
    mkdir -p -- "$copy_folder"
    printf "OSPOS_HTTP_PORT=18080\nOSPOS_BACKUP_DESTINATION=backups\nOSPOS_BACKUP_COPY_TO='E:\\OSPOS-Backups'\n" \
        > "$windows_client_dir/ospos.conf"
    if ! COPY_FOLDER_LOCAL="$copy_folder" WINDOWS_MODE=1 run_shop check; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'ok: backup copy folder E:\OSPOS-Backups exists.'
    if ! COPY_FOLDER_LOCAL="$copy_folder/missing" WINDOWS_MODE=1 run_shop check; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'warning: backup copy folder E:\OSPOS-Backups is missing.'
}

# Show backup copy results and warn only when a configured copy did not work.
test_backup_copy_reporting() {
    make_case backup-copy-report
    printf "OSPOS_HTTP_PORT=18080\nOSPOS_BACKUP_DESTINATION=backups\nOSPOS_BACKUP_COPY_TO=''\n" > "$data_dir/ospos.conf"
    if ! BACKUP_COPY_VALUE=- run_shop backup; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'Backup copy: -'
    assert_not_contains "$output_file" 'warning: the backup copy did not succeed'
    printf "OSPOS_HTTP_PORT=18080\nOSPOS_BACKUP_DESTINATION=backups\nOSPOS_BACKUP_COPY_TO='/media/usb'\n" > "$data_dir/ospos.conf"
    if ! BACKUP_COPY_VALUE=ok run_shop backup; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'Backup copy: ok'
    assert_not_contains "$output_file" 'warning: the backup copy did not succeed'
    if ! BACKUP_COPY_VALUE=missing run_shop backup; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'Backup copy: missing'
    assert_contains "$output_file" 'warning: the backup copy did not succeed'
}

# Remind Windows operators to share the USB folder with Docker Desktop after a failed copy.
test_windows_backup_copy_warning() {
    make_case windows-backup-copy-warning
    prepare_windows_client
    printf "OSPOS_HTTP_PORT=18080\nOSPOS_BACKUP_DESTINATION=backups\nOSPOS_BACKUP_COPY_TO='E:\\OSPOS-Backups'\n" \
        > "$windows_client_dir/ospos.conf"
    if ! WINDOWS_MODE=1 BACKUP_COPY_VALUE=missing run_shop backup; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'Backup copy: missing'
    assert_contains "$output_file" 'Docker Desktop > Settings > Resources > File Sharing'
}

# Report Windows restart policies and Docker Desktop sign-in startup as checks only.
test_restart_policy_and_docker_autostart_check() {
    make_case restart-policy-check
    prepare_windows_client
    if ! WINDOWS_MODE=1 PS_DOCKER_SETTING=true OSPOS_RESTART_POLICY=always MYSQL_RESTART_POLICY=always run_shop check; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'ok: the ospos container restart policy is always.'
    assert_contains "$output_file" 'ok: the mysql container restart policy is always.'
    assert_contains "$output_file" 'ok: Docker Desktop starts at login.'
    if ! WINDOWS_MODE=1 PS_DOCKER_SETTING=false OSPOS_RESTART_POLICY=no MYSQL_RESTART_POLICY=no run_shop check; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'warning: the ospos container may not restart after a reboot'
    assert_contains "$output_file" 'warning: the mysql container may not restart after a reboot'
    assert_contains "$output_file" 'warning: turn on Docker Desktop > Settings > General > Start Docker Desktop when you sign in.'
    if ! WINDOWS_MODE=1 PS_DOCKER_SETTING=unreadable run_shop check; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'warning: could not read the Docker Desktop start setting'
}

# Show the Windows daily backup task without making status fail when PowerShell cannot read it.
test_status_backup_task() {
    make_case status-backup-task
    prepare_windows_client
    if ! WINDOWS_MODE=1 PS_TASK_MODE=present run_shop status; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'Backup task: present, next run 2026-09-25 23:30:00, last result 0'
    if ! WINDOWS_MODE=1 PS_TASK_MODE=missing run_shop status; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'Backup task: missing'
    assert_contains "$output_file" 'warning: run ./shop schedule-backup'
    if ! WINDOWS_MODE=1 PS_TASK_MODE=failed run_shop status; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'Backup task: unknown'
}

# Keep Linux status output free of the Windows scheduled-task row.
test_linux_status_has_no_backup_task_row() {
    make_case linux-status-backup-task
    if ! run_shop status; then cat "$output_file" >&2; exit 1; fi
    assert_not_contains "$output_file" 'Backup task:'
}

# Report restored row counts and duration, while treating a failed count query as a warning.
test_restore_counts_and_elapsed_time() {
    make_case restore-counts
    printf 'database.default.database=ospos\ndatabase.default.username=admin\ndatabase.default.password=shop-test-secret\ndatabase.default.DBPrefix=ospos_\n' \
        > "$data_dir/secrets/app.env"
    if ! RESTORE_COUNTS='12 34 56' run_shop restore ARCHIVE=ospos-backup-20260924-010001.tar.gz --yes; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'Restored items: 12'
    assert_contains "$output_file" 'Restored sales: 34'
    assert_contains "$output_file" 'Restored employees: 56'
    assert_contains "$output_file" 'Restore took '
    assert_not_contains "$output_file" 'shop-test-secret'
    assert_not_contains "$docker_log" 'shop-test-secret'
    if ! RESTORE_COUNTS_FAIL=1 run_shop restore ARCHIVE=ospos-backup-20260924-010001.tar.gz --yes; then cat "$output_file" >&2; exit 1; fi
    assert_contains "$output_file" 'warning: could not count restored database rows; the restore succeeded.'
    assert_contains "$output_file" 'Restore took '
}

# Run each isolated shop-command scenario without contacting a real Docker daemon.
run_all_tests() {
    test_rollback_restores_before_checkout
    test_rollback_retry_keeps_recorded_source_values
    test_rollback_retries_after_checkout_failure
    test_rollback_recovers_from_pre_fix_saved_code
    test_status_advises_switch_from_detached_saved_commit
    test_rollback_recovers_update_pending
    test_update_after_rollback_saves_fresh_point
    test_update_backup_follows_image_pull
    test_pull_failure_blocks_start_and_retries
    test_backup_failure_blocks_start_and_retries
    test_interruption_after_pull_blocks_start_and_retries
    test_code_pull_failure_blocks_start_and_retries
    test_second_rollback_is_refused
    test_damaged_rollback_archive_is_checked_first
    test_restore_validates_archive_before_stopping
    test_restore_refuses_archive_for_another_database
    test_restore_does_not_clear_unfinished_rollback
    test_rollback_rejects_archive_without_backup_entries
    test_legacy_rollback_rejects_bad_manifest_checksum
    test_rollback_rejects_non_directory_uploads_entry
    test_rollback_rejects_dangling_upload_hardlink
    test_legacy_rollback_record_gets_checksum
    test_legacy_checksum_waits_for_rollback_validation
    test_rollback_rejects_empty_checksum_field
    test_archive_tools_are_checked_before_update_and_rollback
    test_update_and_rollback_refuse_untracked_files
    test_update_allows_ignored_untracked_files
    test_rollback_records_source_version
    test_update_blocks_rolled_back_image_digest
    test_update_retry_clears_marker_after_broken_image_guard
    test_missing_update_source_image_requires_manual_recovery_and_rollback
    test_update_with_missing_rollback_digest_uses_image_id_guard
    test_update_requires_typed_unknown_image_confirmation
    test_restore_failure_hints_match_restore_phase
    test_restore_advice_during_active_rollback
    test_restore_marker_blocks_start_and_update_until_success
    test_interrupted_restore_leaves_marker
    test_restore_launcher_preserves_database_phase_status
    test_direct_restore_lock_marker_and_default_directory
    test_direct_restore_refuses_update_and_rollback_recovery
    test_backup_refuses_partial_database_state
    test_container_restore_reports_both_failure_phases
    test_windows_client_path_ignores_environment
    test_windows_archive_validation_uses_local_tar_paths
    test_shared_lock_blocks_parallel_commands
    test_recovery_advice_commands_are_allowed
    test_concurrent_first_installs_share_the_lock
    test_locked_first_install_setup_accepts_lock_folder
    test_windows_secrets_folder_check
    test_backup_copy_folder_check
    test_windows_backup_copy_folder_check
    test_backup_copy_reporting
    test_windows_backup_copy_warning
    test_restart_policy_and_docker_autostart_check
    test_status_backup_task
    test_linux_status_has_no_backup_task_row
    test_restore_counts_and_elapsed_time
    printf 'shop command tests passed\n'
}

run_all_tests
