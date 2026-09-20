# Manual backup and restore

This is a manual command-line process for the shop.

The shop has no automatic backup yet.

## What the backup contains

Each backup is one file named `ospos-backup-YYYYMMDD-HHMMSS.tar.gz`.

The file contains the database, including its tables, data, routines, and triggers.

The file contains `public/uploads/` by default, including item pictures and the company logo.

The file contains a small manifest with the backup date, application version, database name, migration version when available, and a database checksum.

The file does not contain `.env`.

The `.env` file holds the database password and the encryption key.

Keep a separate, secure copy of `.env` and the encryption key information.

Do not put `.env` on the backup drive beside the archive.

The backup does not contain the application code, operating system, Docker images, logs, sessions, or cache files.

Keep the repository or deployment files available with the backup and keep the database settings needed to use the archive.

## Before you start

Use an external drive with enough free space.

Make sure the drive is mounted and its backup directory already exists.

The backup script needs `bash`, `mysqldump`, `tar`, `gzip`, and the normal shell tools.

The restore script also needs `mysql`.

The scripts are executable as shipped and should be run from the repository root.

## Take a backup with Docker

Replace `/media/shop-backup` with the mounted directory on the external drive.

```bash
cd /path/to/osposleb
mkdir -p /media/shop-backup
scripts/backup.sh --destination /media/shop-backup --db-container mysql
```

The Docker database container is called `mysql` in the shipped setup.

The script reads the database settings from `.env` and runs `mysqldump` through `docker exec`.

## Take a backup on the host

Use this form when `mysqldump` can connect to the database from the shop computer.

```bash
cd /path/to/osposleb
mkdir -p /media/shop-backup
scripts/backup.sh --destination /media/shop-backup
```

Use `--env /path/to/another.env` when the database settings are in another file.

Use `--uploads /path/to/uploads` only when the shop stores uploads somewhere other than `public/uploads`.

The database password is kept in a temporary private MySQL defaults file.

The password is not printed, placed in the process list, or written into the archive.

The temporary file is removed when the command finishes or fails.

## Check the backup

The command prints the final archive path and its size when it succeeds.

Check that the file is not empty and that the archive lists the required entries.

```bash
backup=/media/shop-backup/ospos-backup-YYYYMMDD-HHMMSS.tar.gz
test -s "$backup"
tar -tzf "$backup"
```

The listing must include `database.sql`, `manifest.txt`, and `uploads/`.

Copy the archive to a second safe place when the shop is important.

Do not leave the only copy in the shop computer.

## Restore the live shop

Restoring overwrites the selected database and replaces the selected uploads directory.

Take a fresh backup before restoring.

Stop the application and prevent new sales while the restore runs.

Check the archive path before you continue.

With Docker, run:

```bash
cd /path/to/osposleb
scripts/restore.sh --archive /media/shop-backup/ospos-backup-YYYYMMDD-HHMMSS.tar.gz --db-container mysql
```

On a host database, run:

```bash
cd /path/to/osposleb
scripts/restore.sh --archive /media/shop-backup/ospos-backup-YYYYMMDD-HHMMSS.tar.gz
```

The script prints the exact database name before it asks for confirmation.

At the prompt, type `yes` in full, or use `--yes` when an operator has already approved the restore.

The script checks the archive contents and database checksum before it imports the database.

It restores the database first and then replaces the uploads directory.

If the database import fails, the uploads directory is not changed.

After the restore, start the application and check that you can log in.

Check a recent sale, item picture, company logo, report, and receipt before reopening the shop.

## Restore drill into a scratch database

Use a separate empty database and a separate uploads directory for a drill.

Do not point the running shop at the scratch database.

Create the scratch database with the database administrator account and a password prompt.

For Docker, an example is:

```bash
docker exec -it mysql mysql -u root -p -e 'CREATE DATABASE ospos_restore_drill;'
mkdir -p /path/to/restore-drill/uploads
scripts/restore.sh --archive /media/shop-backup/ospos-backup-YYYYMMDD-HHMMSS.tar.gz --db-container mysql --database ospos_restore_drill --uploads /path/to/restore-drill/uploads
```

For a host database, use the same `mysql -u root -p -e 'CREATE DATABASE ospos_restore_drill;'` command on the shop computer.

The `--database` option changes only the restore target and is the safe way to test another database.

The `--uploads` option is also required for a drill so the live `public/uploads` directory is not replaced.

Log in to the restored test installation, open the sales screen, view item pictures, and check a report.

Remove the scratch database and scratch uploads after the drill according to your database administrator process.

## How often to do this

Take a backup at the end of every trading day.

Take another backup before every upgrade, migration, or major configuration change.

The shop currently has no scheduled or automatic backup.

The owner must copy the archive to an external drive and keep a separate safe copy.

Automated backups, retention, and managed off-machine copies remain Phase 6 work.

## Risks and limits

- The shipped `docker-compose.yml` does not persist `public/uploads`.
- In that Docker setup, the company logo and item pictures live inside the application container and are lost when that container is replaced.
- The backup captures `public/uploads`, but the deployment should mount that directory as a volume.
- Route the Docker volume fix to Phase 6 and ADR 0009.
- `app/Database/resetdatabase.sh` drops and recreates the database.
- `app/Database/resetdatabase.sh` destroys data and is not a backup tool.
- Do not use `resetdatabase.sh` to make or restore a backup.
- A restore can overwrite live data, so stop the shop and confirm the database name first.
- Real printer, scanner, hardware, and production restore validation are still pending.

## Testing status for this change

The scripts were checked with `bash -n`; `shellcheck` was not available in this environment.

A real backup was taken on 2026-09-20 from a running MariaDB 10.5 container holding five items and nine sales. The archive contained `database.sql` with twenty `INSERT` statements, `uploads/`, and a manifest recording application version 3.4.1, migration version `20260920000001`, and a SHA-256 checksum. No `.env` was present in the archive.

A real restore drill was run on 2026-09-20 into a scratch database and a scratch uploads directory. All five items, all nine sales, both employee records with their language settings, and the uploads directory were restored. The live database was verified untouched afterwards.

Three refusals were verified: a restore without `--yes` on a non-interactive terminal is refused, a restore whose archive names a different database than `.env` is refused unless `--database` is given, and an archive whose `database.sql` has been altered is refused because the checksum no longer matches its manifest.

Restore into a live production shop has not been rehearsed and remains Phase 6 work.

The scripts use temporary files and atomic archive renaming so an interrupted backup does not look complete.
