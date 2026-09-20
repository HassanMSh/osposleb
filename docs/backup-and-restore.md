# Manual backup and restore

This is a manual command-line process for the shop.

The shop has no automatic backup yet.

The Windows procedure comes first because the shop computer is Windows.

The Linux procedure comes second for development and Linux-operated deployments.

## Windows operator procedure

### Before the first backup

Install Docker Desktop and start it before running a launcher.

Keep the OSPOS repository on the shop computer.

Connect the external drive and create a backup folder such as `E:\OSPOS-Backups`.

In Docker Desktop, open Settings, open Resources, open File Sharing, add the external drive or the folder containing `E:\OSPOS-Backups`, and select Apply and Restart.

Without this Docker Desktop access, the launcher usually fails with an unhelpful permission error.

The external drive must have enough free space for the database and uploads.

Most external drives are formatted as exFAT or FAT32.

exFAT and FAT32 cannot store Unix file permissions.

The archive is set to mode 600 inside the container, but exFAT and FAT32 silently ignore that setting.

Anyone holding an exFAT or FAT32 drive can therefore read the archive.

The mode-600 setting is not protection for an archive stored on those drives.

This is another reason that `.env` stays out of the archive.

The `.env` file contains the database password and the encryption key.

Keep `.env` and the encryption key information in a separate protected location.

Do not put `.env` on the backup drive beside the archive.

### Set the application network

The launcher joins the application Docker network and connects to the database service named `mysql`.

The default network name is `ospos_app_net`.

Set `OSPOS_DOCKER_NETWORK` when the running Compose project uses another name.

For the verification stack used by this project, set it as follows.

```powershell
$env:OSPOS_DOCKER_NETWORK = "ospos-baseline-verify_verify_net"
```

### Take a Windows backup

Open PowerShell in the repository directory.

The following is a worked example using the real Windows drive-letter form `E:`.

```powershell
Set-Location C:\osposleb
$env:OSPOS_DOCKER_NETWORK = "ospos-baseline-verify_verify_net"
New-Item -ItemType Directory -Force E:\OSPOS-Backups | Out-Null
.\scripts\backup.ps1 --destination E:\OSPOS-Backups
```

The command writes a file named `ospos-backup-YYYYMMDD-HHMMSS.tar.gz` to `E:\OSPOS-Backups`.

The Windows launcher and the Windows container path are expected, not verified here.

The project owner must run this example on the shop computer before Windows support is marked verified.

The launcher does not accept the removed `--db-container` option.

Use `--uploads C:\path\to\uploads` only when the shop stores uploads outside `public\uploads`.

Use `--env C:\path\to\another.env` when the database settings are in another file.

The launcher passes the database host `mysql` explicitly because the `.env` value `localhost` is not the database service from inside the tool container.

The password is kept in a temporary private MySQL defaults file inside the throwaway container.

The password is not printed, placed in the process list, or written into the archive.

The temporary file is removed when the command finishes or fails.

### Check a Windows backup

Check that the archive exists and is not empty.

```powershell
$backup = Get-ChildItem E:\OSPOS-Backups\ospos-backup-*.tar.gz | Sort-Object LastWriteTime -Descending | Select-Object -First 1
if ($null -eq $backup -or $backup.Length -eq 0) { throw "No non-empty backup was found." }
```

The archive must contain `database.sql`, `manifest.txt`, and `uploads/`.

The archive must not contain `.env`.

Copy an important archive to a second safe location.

Do not leave the only copy on the shop computer or the external drive.

### Restore the Windows shop

A restore overwrites the selected database and replaces the selected uploads directory.

Take a fresh backup before restoring.

Stop the application and prevent new sales while the restore runs.

Check the archive path and database name before continuing.

Use `--yes` only after the operator has approved the exact restore.

```powershell
Set-Location C:\osposleb
$env:OSPOS_DOCKER_NETWORK = "ospos-baseline-verify_verify_net"
.\scripts\restore.ps1 --archive E:\OSPOS-Backups\ospos-backup-YYYYMMDD-HHMMSS.tar.gz --yes
```

The launcher prints the target database name before the restore tool imports it.

The tool checks the archive members and the database checksum before importing the database.

It restores the database first and then replaces the uploads directory.

If the database import fails, the uploads directory is not changed.

Start the application after the restore and check that you can log in.

Check a recent sale, item picture, company logo, report, and receipt before reopening the shop.

### Windows restore drill

Use a separate empty database and a separate uploads directory for a drill.

Do not point the running shop at the scratch database.

Create the scratch database with the database administrator account and a password prompt.

Create the scratch uploads directory before starting the drill.

```powershell
docker exec -it ospos-baseline-verify-mysql-1 mysql -uroot -p -e "CREATE DATABASE ospos_restore_drill;"
New-Item -ItemType Directory -Force C:\ospos-restore-drill\uploads | Out-Null
.\scripts\restore.ps1 --archive E:\OSPOS-Backups\ospos-backup-YYYYMMDD-HHMMSS.tar.gz --database ospos_restore_drill --uploads C:\ospos-restore-drill\uploads --yes
```

The `--database` option changes only the restore target.

The `--uploads` option keeps the live uploads directory untouched.

Check the five items, nine sales, both employee rows, and the restored uploads before cleanup.

Drop the scratch database and remove the scratch uploads directory after the drill.

The Windows restore drill is expected, not verified here, because no Windows machine is available.

## Linux operator procedure

The Linux launcher also runs the tool inside `mariadb:10.5`.

The host needs Docker, but it does not need Bash database clients or GNU archive tools.

The launcher does not receive the Docker socket.

### Before the first backup

Mount an external drive and create a writable backup directory.

Set `OSPOS_DOCKER_NETWORK` when the application network is not `ospos_app_net`.

The Linux launcher passes the current `uid:gid`, so files written into the mount belong to the operator.

### Take a Linux backup

Run the command from the repository root.

```bash
cd /path/to/osposleb
export OSPOS_DOCKER_NETWORK=ospos-baseline-verify_verify_net
mkdir -p /media/shop-backup
scripts/backup.sh --destination /media/shop-backup
```

Use `--env /path/to/another.env` when the database settings are in another file.

Use `--uploads /path/to/uploads` only when the shop stores uploads somewhere other than `public/uploads`.

The launcher passes `--db-host mysql` to the container entry point.

The `.env` hostname is not used for the container connection.

### Check the Linux backup

The command prints the final archive path and its size when it succeeds.

Check that the file is not empty and that the archive lists the required entries.

```bash
backup=/media/shop-backup/ospos-backup-YYYYMMDD-HHMMSS.tar.gz
test -s "$backup"
tar -tzf "$backup"
```

The listing must include `database.sql`, `manifest.txt`, and `uploads/`.

The listing must not include `.env`.

### Restore the live Linux shop

Restoring overwrites the selected database and replaces the selected uploads directory.

Take a fresh backup before restoring.

Stop the application and prevent new sales while the restore runs.

Check the archive path and database name before continuing.

Use `--yes` only after the operator has approved the exact restore.

```bash
cd /path/to/osposleb
export OSPOS_DOCKER_NETWORK=ospos-baseline-verify_verify_net
scripts/restore.sh --archive /media/shop-backup/ospos-backup-YYYYMMDD-HHMMSS.tar.gz --yes
```

The tool checks the archive contents and database checksum before importing the database.

It restores the database first and then replaces the uploads directory.

If the database import fails, the uploads directory is not changed.

Start the application after the restore and check that you can log in.

### Linux restore drill

Use a separate empty database and a separate uploads directory for a drill.

Do not point the running shop at the scratch database.

Create the scratch database with the database administrator account and a password prompt.

```bash
docker exec -it ospos-baseline-verify-mysql-1 mysql -uroot -p -e 'CREATE DATABASE ospos_restore_drill;'
mkdir -p /path/to/restore-drill/uploads
export OSPOS_DOCKER_NETWORK=ospos-baseline-verify_verify_net
scripts/restore.sh --archive /media/shop-backup/ospos-backup-YYYYMMDD-HHMMSS.tar.gz --database ospos_restore_drill --uploads /path/to/restore-drill/uploads --yes
```

The `--database` option changes only the restore target.

The `--uploads` option keeps the live uploads directory untouched.

Check the five items, nine sales, both employee rows, and the restored uploads before cleanup.

Drop the scratch database and remove the scratch uploads directory after the drill.

### How often to do this

Take a backup at the end of every trading day.

Take another backup before every upgrade, migration, or major configuration change.

The shop currently has no scheduled or automatic backup.

The owner must copy the archive to an external drive and keep a separate safe copy.

Automated backups, retention, and managed off-machine copies remain Phase 6 work.

## What the backup contains

Each backup is one file named `ospos-backup-YYYYMMDD-HHMMSS.tar.gz`.

The file contains the database, including its tables, data, routines, and triggers.

The file contains `public/uploads/` by default, including item pictures and the company logo.

The file contains a small manifest with the backup date, application version, database name, migration version, and a database checksum.

The file does not contain `.env`.

The backup does not contain the application code, operating system, Docker images, logs, sessions, or cache files.

Keep the repository or deployment files available with the backup and keep the database settings needed to use the archive.

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

The Linux path was verified against the running MariaDB 10.5 stack on 2026-09-20.

A real backup contained `database.sql` with twenty `INSERT` statements, `uploads/`, and `manifest.txt`, and did not contain `.env`.

Its manifest recorded application version 3.4.1, migration version `20260920000001`, and a SHA-256 checksum.

A real restore drill into a scratch database and scratch uploads directory restored five items, nine sales, both employee rows with their language settings, and the uploads directory.

The live database was verified untouched after the drill.

Three refusals were verified: a restore without `--yes` on non-interactive input was refused, an archive naming a different database than `.env` was refused without `--database`, and an archive with altered `database.sql` was refused because its checksum did not match the manifest.

The tool container was verified not to have access to the Docker socket.

The Windows launchers and Windows restore drill remain expected, not verified, until the project owner tests them on the shop computer.

Restore into a live production shop has not been rehearsed and remains Phase 6 work.

The scripts use temporary files and atomic archive renaming so an interrupted backup does not look complete.
