# Automatic backups and restore

The shop takes one automatic backup each day at the scheduled time.

You can also take a manual backup before an upgrade or major change.

The Windows procedure comes first because the shop computer is Windows.

The Linux procedure comes second for development and Linux-operated deployments.

## Client installation setup

The client layout keeps non-secret settings, secrets, uploads, and backups together while the database stays in a Docker named volume.

The owner must keep a separate copy of the `secrets` directory somewhere other than the backup drive. `app.env` contains the application's own database connection settings and its encryption key; `mysql.env` contains the application database credentials for the database container; `db.env` contains the separate MariaDB root password.

The shop secrets file `secrets/app.env` holds the application encryption key.

Back up the shop before running `docker compose pull` on it, because a new image can migrate the database once automatic migrations land.

Backups deliberately exclude the secrets files, including the encryption key.

Losing the encryption key makes stored email and messaging credentials unreadable.

After restoring a database with a different encryption key, clear the stored SMTP Password before opening Settings.
Clear the stored SMS-API Password before opening Settings.
Clear the stored MailChimp API key before opening Settings.
Clear the stored MailChimp List(s) before opening Settings.
Then enter these settings again.

An archive must not carry both the backups and the secrets, because one stolen drive would then give up everything.

Anyone with administrator rights on the shop computer, or anyone holding its disk, can read the secrets.

### Windows setup

Windows support is expected, not verified, until the project owner runs it on the shop computer.

Install Docker Desktop and give Docker Desktop access to the drive holding the client directory.

Keep the client directory on the internal NTFS drive when possible.

Do not put the client directory on exFAT or FAT32 when secrets need protection, because those file systems have no file permissions.

Open PowerShell in the repository directory and create the layout.

```powershell
.\scripts\setup-client.ps1 --data-directory C:\OSPOS\Client
```

On NTFS the setup command creates the new target and `secrets\`, restricts `secrets\` to the current Windows account with `icacls`, and removes inherited access before the shared setup entry point writes any secret.

On exFAT or FAT32 the command refuses setup because those filesystems cannot protect the secrets. It does not write passwords there.

If filesystem discovery or ACL preparation fails, setup stops and removes the exact new target. It does not leave generated secrets behind.

Set the one client-directory variable before using Compose or a launcher.

```powershell
$env:OSPOS_DATA_DIR = 'C:\OSPOS\Client'
docker compose --env-file "$env:OSPOS_DATA_DIR\ospos.conf" -f docker-compose.yml -f docker-compose.client.yml pull
docker compose --env-file "$env:OSPOS_DATA_DIR\ospos.conf" -f docker-compose.yml -f docker-compose.client.yml up -d
```

The client override binds `secrets\app.env` read-only at `/app/.env` and mounts `uploads\` at `/app/public/uploads`. The application container takes no `env_file` and has no environment of its own.

The application reads the generated settings from the bind-mounted `/app/.env`, because Compose `env_file` silently drops any key containing a dot, so the secret file is delivered only by bind mount, never by the container environment. It is still not copied into the image.

It leaves the database in the named `mysql` volume.

The setup command refuses to run when the target directory already exists.

New client setup creates `uploads/item_pics/` automatically and sets it to mode 777 on Linux.

If it is missing from an existing client data directory, run `mkdir -p "$OSPOS_DATA_DIR/uploads/item_pics" && chmod 777 "$OSPOS_DATA_DIR/uploads/item_pics"` on Linux or `New-Item -ItemType Directory -Force "$env:OSPOS_DATA_DIR\uploads\item_pics" | Out-Null` in PowerShell.

### Backup settings for older client installs

Older `ospos.conf` files still work when they do not contain the new settings.

The defaults are keep seven archives, no USB copy, and web port 80.

Add these lines to `ospos.conf` to set them yourself.

```text
OSPOS_BACKUP_KEEP=7
OSPOS_BACKUP_COPY_TO=''
OSPOS_HTTP_PORT=80
```

Set `OSPOS_BACKUP_KEEP=0` to keep every archive.

Set `OSPOS_BACKUP_COPY_TO` to an absolute host folder path to copy backups to a USB drive.

In Docker Desktop, enable file sharing for the USB drive or its backup folder.

The separate MariaDB root password, application database password, and application encryption key are made inside the setup container, so the host does not need OpenSSL or a PowerShell secret generator.

### Linux setup

The Linux path was verified in this environment.

Run the setup command into a new directory.

```bash
./scripts/setup-client.sh --data-directory "$PWD/client-data"
```

The Linux setup command protects the secrets with the `secrets` directory at mode 700, not with the mode of the files inside it, keeps `app.env` at mode 644 because the application container's web server must read it there, keeps `mysql.env` and `db.env` at mode 600 because only Compose `env_file` ever reads them, and keeps the database in a named Docker volume.

Set `OSPOS_DATA_DIR` and start the stack with the same client override.

```bash
export OSPOS_DATA_DIR="$PWD/client-data"
docker compose --env-file "$OSPOS_DATA_DIR/ospos.conf" -f docker-compose.yml -f docker-compose.client.yml pull
docker compose --env-file "$OSPOS_DATA_DIR/ospos.conf" -f docker-compose.yml -f docker-compose.client.yml up -d
```

### Pin or roll back the client image

Set `OSPOS_IMAGE_TAG` to `develop-<sha>` in the shell or `ospos.conf` to pin a specific build.

On Windows, set `$env:OSPOS_IMAGE_TAG = 'develop-<sha>'` in PowerShell; on Linux, run `export OSPOS_IMAGE_TAG=develop-<sha>`.

After setting the tag, rerun the same full `up -d` command shown above for your system.

To roll back, set `OSPOS_IMAGE_TAG` to an earlier `develop-<sha>` and rerun the same full `up -d` command shown above for your system.

Keep this repository checkout on the same `develop` commit as the image tag because a fresh database is seeded from the checkout's tracked `tables.sql` and `constraints.sql` files.

### Client backups and moves

With `OSPOS_DATA_DIR` set, the backup command with no arguments reads `OSPOS_BACKUP_DESTINATION` from `ospos.conf` and writes the archive to that directory.

An explicit `--destination` sets the archive location and overrides the configured destination.

```powershell
.\scripts\backup.ps1
.\scripts\backup.ps1 --destination E:\OSPOS-Backups
```

```bash
./scripts/backup.sh
./scripts/backup.sh --destination /media/shop-backup
```

The default destination is the client `backups\` or `backups/` directory.

Without `--destination`, the archive and log use the destination in `ospos.conf`.

With a client directory, `--destination` moves the archive only; the log stays in `backup.log` under the configured destination in `ospos.conf`.

Without a client directory, the log is `backup.log` beside the `--destination` archive.

If `ospos.conf` is missing or its backup destination is invalid, the error goes to `<client>/backups/backup.log` when that folder exists; a missing client directory can only print an error.

The backup does not contain `.env`, `app.env`, `mysql.env`, or `db.env` at any archive depth.

When the client-config destination is used, the launcher mounts the config file and writable backup destination separately. It does not mount the whole client directory into the backup container.

To move a shop, install Docker, copy the client directory, set `OSPOS_DATA_DIR`, start the stack, and restore the latest archive.

The restore step is required because the database is not in the copied client directory.

Restart the `ospos` service after the restore so it uses the restored uploads directory.

```powershell
$env:OSPOS_DATA_DIR = 'C:\OSPOS\Client'
docker compose --env-file "$env:OSPOS_DATA_DIR\ospos.conf" -f docker-compose.yml -f docker-compose.client.yml up -d
.\scripts\restore.ps1 --archive E:\OSPOS-Backups\ospos-backup-YYYYMMDD-HHMMSS.tar.gz --yes
docker compose --env-file "$env:OSPOS_DATA_DIR\ospos.conf" -f docker-compose.yml -f docker-compose.client.yml restart ospos
```

```bash
export OSPOS_DATA_DIR="$PWD/client-data"
docker compose --env-file "$OSPOS_DATA_DIR/ospos.conf" -f docker-compose.yml -f docker-compose.client.yml up -d
./scripts/restore.sh --archive /media/shop-backup/ospos-backup-YYYYMMDD-HHMMSS.tar.gz --yes
docker compose --env-file "$OSPOS_DATA_DIR/ospos.conf" -f docker-compose.yml -f docker-compose.client.yml restart ospos
```

## Automatic daily backups

The Windows task runs once a day at the time you choose and keeps the newest seven archives.

Set `OSPOS_BACKUP_KEEP` in `ospos.conf` to change the count; `0` keeps all archives.

Set `OSPOS_BACKUP_COPY_TO` to an absolute USB folder path to add a verified copy with the same retention count.

An empty `OSPOS_BACKUP_COPY_TO` disables the copy without a warning.

If the USB folder is missing, the backup still succeeds and the log records `copy=missing`.

If the copy cannot be written or checked, the backup still succeeds and the log records `copy=failed`.

Enable Docker Desktop file sharing for the USB drive or folder before using it, or the whole backup can fail, not only the copy.

Schedule the Windows task from PowerShell with a time you choose.

```powershell
.\scripts\schedule-backup.ps1 --time 23:30
```

Run the command again with a new time to change the schedule.

Remove the scheduled task with `--remove`.

The task runs only while the shop account is logged in.

It runs after logon if the computer missed the scheduled time while it was off.

Do not start two manual backups at the same time; the scheduled task ignores a new run while one is active.

On Linux, add a crontab entry with the client directory and repository path.

```cron
30 23 * * * OSPOS_DATA_DIR=/path/to/client /path/to/osposleb/scripts/backup.sh
```

The log file is plain text with one `key=value` line per run.

Each line records the UTC time, result, archive, size, deleted archive count, copy result, copy deleted count, and message.

For client runs, `backup.log` stays under the configured destination in `ospos.conf`, usually `<client>/backups/backup.log`.

A backup that fails deletes no old archive and removes only its own temporary or unverified files.

If the backup succeeds but its log line cannot be written, for example on a full disk, the old archives may already be trimmed; the run then reports an error so the problem is noticed.

Manual backups in the same destination also count toward the keep-seven limit.

Copy a pre-upgrade backup to another safe folder if you want to keep it longer.

Archive names and log times use UTC; Beirut is UTC+2 in winter and UTC+3 in summer.

The client Compose file binds the web page to `127.0.0.1` on this computer only.

Set `OSPOS_HTTP_PORT` in `ospos.conf` to change the local port from 80.

Open the till at `http://localhost/` or `http://localhost:<port>/` when using another port.

## Windows operator procedure

### Before the first backup

Install Docker Desktop and start it before running a launcher.

Keep the OSPOS repository on the shop computer.

Connect an optional external drive and create a backup folder such as `E:\OSPOS-Backups`.

To use the optional USB copy, open Docker Desktop Settings, open Resources, open File Sharing, add the external drive or its backup folder, and select Apply and Restart.

Without this Docker Desktop access, Docker may refuse to start the backup container, so the whole backup fails and the log records `result=failed`.

The external drive must have enough free space for the database and uploads.

Most external drives are formatted as exFAT or FAT32.

exFAT and FAT32 cannot store Unix file permissions.

The archive is set to mode 600 inside the container, but exFAT and FAT32 silently ignore that setting.

Anyone holding an exFAT or FAT32 drive can therefore read the archive.

The mode-600 setting is not protection for an archive stored on those drives.

This is another reason that `secrets\app.env`, `secrets\mysql.env`, and `secrets\db.env` stay out of the archive.

The client `secrets\` directory contains the database passwords and the application encryption key.

Keep a separate copy of `secrets\` somewhere other than the backup drive.

Do not put `secrets\` on the backup drive beside the archive.

### Set the application network

The launcher joins the application Docker network and connects to the database service named `mysql`.

The default network name is `project-folder_app_net`: replace `project-folder` with the Compose project folder name. For a repository folder named `osposleb`, it is `osposleb_app_net`.

If `COMPOSE_PROJECT_NAME` is set, the launcher uses that value before adding `_app_net`.

Set `OSPOS_DOCKER_NETWORK` when the running Compose project uses another name.

Set `OSPOS_DB_HOST` to override the database host; it defaults to `mysql`.

To find the real network and database container, run these commands and look for the network containing the application and the container named `mysql`.

```text
docker network ls
docker ps --format "table {{.Names}}\t{{.Networks}}"
```

### Take a Windows backup

Open PowerShell in the repository directory.

The following is a worked example using the real Windows drive-letter form `E:`.

```powershell
Set-Location C:\osposleb
$env:OSPOS_DOCKER_NETWORK = "osposleb_app_net"
New-Item -ItemType Directory -Force E:\OSPOS-Backups | Out-Null
.\scripts\backup.ps1 --destination E:\OSPOS-Backups
```

The command writes a file named `ospos-backup-YYYYMMDD-HHMMSS.tar.gz` to `E:\OSPOS-Backups`.

The Windows launcher and the Windows container path are expected, not verified here.

The project owner must run this example on the shop computer before Windows support is marked verified.

The launcher does not accept the removed `--db-container` option.

Use `--uploads C:\path\to\uploads` only when the shop stores uploads outside `public\uploads`.

Use `--env C:\path\to\another.env` when the database settings are in another file.

The `app.env` hostname is not used for the container connection unless `OSPOS_DB_HOST` overrides it.

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

The archive must not contain a member whose basename is `.env`, `app.env`, `mysql.env`, or `db.env`, at any depth.

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
$env:OSPOS_DOCKER_NETWORK = "osposleb_app_net"
.\scripts\restore.ps1 --archive E:\OSPOS-Backups\ospos-backup-YYYYMMDD-HHMMSS.tar.gz --yes
```

The launcher prints the target database name before the restore tool imports it.

The tool checks the archive members and the database checksum before importing the database.

Before importing the database, restore creates `uploads/item_pics/` in its temporary uploads copy if the archive did not contain it.

The Windows restore path skips the explicit permission step.

On the shop computer, check that restored pictures show.

It restores the database first and then replaces the live uploads directory.

If the database import fails, the uploads directory is not changed.

If restore fails and cannot put the old uploads back, it keeps them in a hidden sibling folder named in the error.

Start the application after the restore and check that you can log in.

Check a recent sale, item picture, company logo, report, and receipt before reopening the shop.

### Windows restore drill

Use a separate empty database and a separate uploads directory for a drill.

Do not point the running shop at the scratch database.

Create the scratch database with the database administrator account and a password prompt.

Create the scratch uploads directory before starting the drill.

```powershell
docker exec -it mysql mysql -uroot -p -e "CREATE DATABASE ospos_restore_drill; GRANT ALL PRIVILEGES ON ospos_restore_drill.* TO 'admin'@'%';"
New-Item -ItemType Directory -Force C:\ospos-restore-drill\uploads | Out-Null
.\scripts\restore.ps1 --archive E:\OSPOS-Backups\ospos-backup-YYYYMMDD-HHMMSS.tar.gz --database ospos_restore_drill --uploads C:\ospos-restore-drill\uploads --yes
```

The `--database` option changes only the restore target.

The `--uploads` option keeps the live uploads directory untouched.

Check the five items, nine sales, both employee rows, and the restored uploads before cleanup.

```powershell
docker exec -it mysql mysql -uroot -p -e "REVOKE ALL PRIVILEGES ON ospos_restore_drill.* FROM 'admin'@'%'; DROP DATABASE ospos_restore_drill;"
Remove-Item -Recurse -Force C:\ospos-restore-drill\uploads
```

Remove the scratch database and scratch uploads directory after the drill.

The Windows restore drill is expected, not verified here, because no Windows machine is available.

## Linux operator procedure

The Linux launcher also runs the tool inside `mariadb:10.5`.

The host needs Docker, but it does not need Bash database clients or GNU archive tools.

The launcher does not receive the Docker socket.

### Before the first backup

Mount an external drive and create a writable backup directory.

Set `OSPOS_DOCKER_NETWORK` when the application network is not the launcher default.

The Linux launcher passes the current `uid:gid`, so files written into the mount belong to the operator.

### Take a Linux backup

Run the command from the repository root.

```bash
cd /path/to/osposleb
export OSPOS_DOCKER_NETWORK=osposleb_app_net
mkdir -p /media/shop-backup
scripts/backup.sh --destination /media/shop-backup
```

Use `--env /path/to/another.env` when the database settings are in another file.

Use `--uploads /path/to/uploads` only when the shop stores uploads somewhere other than `public/uploads`.

The launcher uses `OSPOS_DB_HOST` when set and otherwise passes `--db-host mysql` to the container entry point.

### Check the Linux backup

The command prints the final archive path and its size when it succeeds.

Check that the file is not empty and that the archive lists the required entries.

```bash
backup=/media/shop-backup/ospos-backup-YYYYMMDD-HHMMSS.tar.gz
test -s "$backup"
tar -tzf "$backup"
```

The listing must include `database.sql`, `manifest.txt`, and `uploads/`.

The listing must not include a member whose basename is `.env`, `app.env`, `mysql.env`, or `db.env`, at any depth.

### Restore the live Linux shop

Restoring overwrites the selected database and replaces the selected uploads directory.

Take a fresh backup before restoring.

Stop the application and prevent new sales while the restore runs.

Check the archive path and database name before continuing.

Use `--yes` only after the operator has approved the exact restore.

```bash
cd /path/to/osposleb
export OSPOS_DOCKER_NETWORK=osposleb_app_net
scripts/restore.sh --archive /media/shop-backup/ospos-backup-YYYYMMDD-HHMMSS.tar.gz --yes
```

The tool checks the archive contents and database checksum before importing the database.

Before importing the database, restore creates `uploads/item_pics/` in its temporary uploads copy if the archive did not contain it.

On Linux, restore sets every uploads directory to mode 777 and every regular file to mode 644 so the web server can use restored pictures.

It restores the database first and then replaces the live uploads directory.

If the database import fails, the uploads directory is not changed.

If restore fails and cannot put the old uploads back, it keeps them in a hidden sibling folder named in the error.

Start the application after the restore and check that you can log in.

### Linux restore drill

Use a separate empty database and a separate uploads directory for a drill.

Do not point the running shop at the scratch database.

Create the scratch database with the database administrator account and a password prompt.

```bash
docker exec -it mysql mysql -uroot -p -e "CREATE DATABASE ospos_restore_drill; GRANT ALL PRIVILEGES ON ospos_restore_drill.* TO 'admin'@'%';"
mkdir -p /path/to/restore-drill/uploads
export OSPOS_DOCKER_NETWORK=osposleb_app_net
scripts/restore.sh --archive /media/shop-backup/ospos-backup-YYYYMMDD-HHMMSS.tar.gz --database ospos_restore_drill --uploads /path/to/restore-drill/uploads --yes
```

The `--database` option changes only the restore target.

The `--uploads` option keeps the live uploads directory untouched.

Check the five items, nine sales, both employee rows, and the restored uploads before cleanup.

```bash
docker exec -it mysql mysql -uroot -p -e "REVOKE ALL PRIVILEGES ON ospos_restore_drill.* FROM 'admin'@'%'; DROP DATABASE ospos_restore_drill;"
rm -rf -- /path/to/restore-drill/uploads
```

Remove the scratch database and scratch uploads directory after the drill.

### How often to do this

The automatic task takes one backup each day at its scheduled time.

Take another backup before every upgrade, migration, or major configuration change.

Use the automatic task and check `backup.log` once a week.

## What the backup contains

Each backup is one file named `ospos-backup-YYYYMMDD-HHMMSS.tar.gz`.

The file contains the database, including its tables, data, routines, and triggers.

The file contains `public/uploads/` by default, including item pictures and the company logo.

The file contains a small manifest with the backup date, application version, database name, migration version, and a database checksum.

The file does not contain a member whose basename is `.env`, `app.env`, `mysql.env`, or `db.env`, at any depth.

The backup does not contain the application code, operating system, Docker images, logs, sessions, or cache files.

Keep the repository or deployment files available with the backup and keep the database settings needed to use the archive.

## Risks and limits

- The shipped development `docker-compose.yml` still does not persist `public/uploads`.
- The client override mounts `public/uploads` from the client directory, so the client deployment keeps the company logo and item pictures when the application container is replaced.
- `app/Database/resetdatabase.sh` drops and recreates the database.
- `app/Database/resetdatabase.sh` destroys data and is not a backup tool.
- Do not use `resetdatabase.sh` to make or restore a backup.
- A restore can overwrite live data, so stop the shop and confirm the database name first.
- Real printer, scanner, hardware, and production restore validation are still pending.

## Testing status for this change

For ADR 0009, `bash -n` and ShellCheck passed on Linux on 2026-09-23.

The Linux path was verified against the running MariaDB 10.5 stack on 2026-09-20.

A real backup contained `database.sql` with twenty `INSERT` statements, `uploads/`, and `manifest.txt`, and did not contain `.env`, `app.env`, or `db.env`.

Its manifest recorded application version 3.4.1, migration version `20260920000001`, and a SHA-256 checksum.

A real restore drill into a scratch database and scratch uploads directory restored five items, nine sales, both employee rows with their language settings, and the uploads directory.

The live database was verified untouched after the drill.

Three refusals were verified: a restore without `--yes` on non-interactive input was refused, an archive naming a different database than `.env` was refused without `--database`, and an archive with altered `database.sql` was refused because its checksum did not match the manifest.

The tool container was verified not to have access to the Docker socket.

The Linux verification used the test-only network `ospos-baseline-verify_verify_net` and container `ospos-baseline-verify-mysql-1`; those names are not operator defaults.

ADR 0009 Linux proofs are recorded in `docs/test-plan.md`.

Windows scheduling, backup, USB copy, and restore remain expected until the project owner tests them on the shop computer.

Restore into a live production shop has not been rehearsed.

The scripts use temporary files and atomic archive renaming so an interrupted backup does not look complete.

## ADR 0011 Linux proof

This proof ran on Linux on 2026-09-20 in a separate Docker Compose project and named database volume.

The setup command created a new client directory, generated both secret files with mode 600, started the stack, and completed a real login, logo upload, item creation, and `$2.00` cash sale `POS 1`.

The app and database containers were then destroyed and recreated without removing the named database volume.

The login worked again, `uploads/proof-logo.png` remained, and the database still contained the completed sale.

This is the explicit proof that the client `public/uploads` mount fixes the upstream uploads persistence defect.

The no-argument backup wrote `ospos-backup-20260920-164515.tar.gz` to the `backups/` directory named by `ospos.conf`.

The archive contained `database.sql`, `manifest.txt`, `uploads/`, and `uploads/proof-logo.png`.

The archive contained neither `secrets/app.env` nor `secrets/db.env`.

A restore drill into a second throwaway client directory restored the same logo, one completed sale, and a working login.

A second setup attempt against the existing first directory returned status 1 and refused to run.

Windows setup, NTFS ACL enforcement, and exFAT/FAT32 refusal remain expected, not verified, because no real Windows machine was available.
