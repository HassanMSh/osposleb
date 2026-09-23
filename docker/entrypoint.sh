#!/bin/sh
set -eu

database_timeout=60
database_started_at=$(date +%s)

# Runs Spark as Apache's user in root containers and as the current user in development.
run_spark() {
    if [ "$(id -u)" -eq 0 ]; then
        su -s /bin/sh www-data -c "php spark $*"
    else
        php spark "$@"
    fi
}

# Runs the database helper as the application user without opening a prompt.
run_migration_helper() {
    if [ "$(id -u)" -eq 0 ]; then
        su -s /bin/sh www-data -c "php /app/docker/migrations.php $1"
    else
        php /app/docker/migrations.php "$1"
    fi
}

# The client install mounts its database settings here. Copy them in before the database check,
# because this script ignores the compose command that used to copy them.
if [ -f /run/ospos/app.env ]; then
    install -o www-data -g www-data -m 0400 /run/ospos/app.env /app/.env
fi

echo "Waiting for the database (up to ${database_timeout} seconds)."
while :; do
    if probe_output=$(run_migration_helper check 2>&1 </dev/null); then
        break
    else
        probe_status=$?
    fi

    if [ "$probe_status" -eq 2 ]; then
        printf '%s\n' "$probe_output" >&2
        exit 1
    fi

    database_now=$(date +%s)
    database_elapsed=$((database_now - database_started_at))
    if [ "$database_elapsed" -ge "$database_timeout" ]; then
        echo "The database did not become ready within ${database_timeout} seconds (elapsed ${database_elapsed}s); check the database settings and database service." >&2
        exit 1
    fi

    sleep 1
done

echo "The database is ready."
echo "Applying database migrations."
run_migration_helper migrate

echo "Clearing the application cache."
run_spark cache:clear

echo "Starting Apache."
exec docker-php-entrypoint apache2-foreground
