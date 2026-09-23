# Windows client install checklist

This is the step-by-step list for setting up the shop on a new Windows client computer.

It was written from the first real run on a Windows 10 shop computer on 2026-09-23 (issue #70). Problems found in that run are fixed in the repository and listed at the end so they can be recognised if they come back.

The full reference for the client folder, secrets, and backups is [backup and restore](backup-and-restore.md). Daily operation is in the [operations runbook](operations-runbook.md).

## Before you start

- Windows 10 or 11 with Docker Desktop installed and running, using the WSL 2 engine and Linux containers (the defaults).
- Git for Windows, which also installs Git Bash.
- Internet access, to download the app image.
- The shop hardware (scanner, receipt printer, cash drawer) connected to the computer.
- The client folder must be on an NTFS drive. The internal `C:` drive is NTFS. Do not use a USB stick formatted as exFAT or FAT32.

## Which window to type in

The commands below are for Git Bash. Git Bash has two traps:

- It rewrites any argument that starts with `/` (for example `/app/public/uploads`) into a Windows path. Put `MSYS_NO_PATHCONV=1` in front of any command that passes a container path, or run that command in PowerShell instead.
- It removes backslashes in unquoted Windows paths. Wrap Windows paths in single quotes (`'C:\OSPOS\Client'`) or use forward slashes (`C:/OSPOS/Client`).

Windows blocks local PowerShell scripts by default. Always run the project's `.ps1` scripts as `powershell.exe -ExecutionPolicy Bypass -File ./scripts/<name>.ps1 ...`.

## 1. Get the repository

Clone the repository into a folder that will not move, because the daily backup task runs the backup script from this folder.

```bash
git clone https://github.com/HassanMSh/osposleb.git
cd osposleb
git switch develop
```

Keep the checkout on the same `develop` commit as the app image. A new database is created from the SQL files in this checkout.

## 2. Create the client folder

```bash
powershell.exe -ExecutionPolicy Bypass -File ./scripts/setup-client.ps1 --data-directory 'C:\OSPOS\Client'
```

- The folder must not exist yet. If a failed try left it behind, check it is empty and delete it first.
- The output should include a line starting with `Protected secrets for`.
- The command creates the database passwords and the encryption key in `C:\OSPOS\Client\secrets`. Copy the `secrets` folder to a safe place that is not the backup drive.
- Check that `C:\OSPOS\Client\ospos.conf` contains `OSPOS_UPLOADS=client_uploads`. Windows setup adds it so item pictures are kept in a Docker volume.

## 3. Start the app

```bash
export OSPOS_DATA_DIR='C:/OSPOS/Client'
docker compose --env-file "$OSPOS_DATA_DIR/ospos.conf" -f docker-compose.yml -f docker-compose.client.yml pull
docker compose --env-file "$OSPOS_DATA_DIR/ospos.conf" -f docker-compose.yml -f docker-compose.client.yml up -d
docker compose --env-file "$OSPOS_DATA_DIR/ospos.conf" -f docker-compose.yml -f docker-compose.client.yml ps
```

- Both `ospos` and `mysql` must show as running.
- Every new Git Bash window needs the `export` line again.
- If port 80 is already used, set `OSPOS_HTTP_PORT=8080` in `ospos.conf`, rerun `up -d`, and use `http://localhost:8080/`.
- The app container is named after the repository folder, for example `osposleb-ospos-1`. `docker ps --format '{{.Names}}'` lists the names. You can use `docker exec <name> ...` for quick checks instead of the long `docker compose` command.

## 4. First login and settings

1. Open `http://localhost/` in Google Chrome. The first start creates the database, so wait about a minute if the page shows a database error.
2. Log in with `admin` / `pointofsale`.
3. Change the admin password under Employees.
4. In Settings, Localization tab, set the timezone to `Asia/Beirut` and save. The default is `America/New_York`, which stamps sales 7 hours early.
5. Leave the shop language on Arabic (Lebanon).
6. Upload a company logo in Settings to prove that pictures can be saved.

## 5. Hardware

The app runs in Chrome, so Windows and Chrome handle the hardware. Docker is not involved.

- Barcode scanner: on the sale screen, click the item box and scan. The item must be added without pressing any key. An unknown barcode must show an error.
- Receipt printer: install the Windows driver, make it the default printer, finish a test sale, and print the receipt from Chrome.
- Cash drawer: it is usually connected to the receipt printer. Turn on the "open drawer" (or "kick drawer") option in the printer driver so the drawer opens when a receipt prints.

Result of the first run: pending. Record the device models and any settings needed here.

## 6. Backups

```bash
export OSPOS_DATA_DIR='C:/OSPOS/Client'
powershell.exe -ExecutionPolicy Bypass -File ./scripts/backup.ps1
powershell.exe -ExecutionPolicy Bypass -File ./scripts/schedule-backup.ps1 --time 23:30
```

- After the manual backup, `C:\OSPOS\Client\backups\backup.log` must end with a `result=ok` line.
- The scheduled task runs only while the shop's Windows account is logged in.
- Windows backups do not include item pictures yet (issue #82).

Do one practice restore before the shop starts selling, because it replaces the database. Record how long it takes.

```bash
docker stop osposleb-ospos-1
powershell.exe -ExecutionPolicy Bypass -File ./scripts/restore.ps1 --archive 'C:\OSPOS\Client\backups\ospos-backup-YYYYMMDD-HHMMSS.tar.gz' --yes
docker start osposleb-ospos-1
```

Result of the first run: pending.

## 7. Finish

- Tick the passed items on the client install issue (#70) and open an issue for anything that failed.
- Leave Docker Desktop set to start when Windows starts.

## Problems found on the first run

All three came from one cause: Docker Desktop shows folders shared from Windows as readable and writable by the container's root user only, while the web server runs as `www-data`. Each one is fixed in the repository.

| What you see | Cause | Fix in the repository |
| --- | --- | --- |
| `setup-client.ps1` stops with a "parameter set cannot be resolved" error. | Windows PowerShell 5.1, which ships with Windows 10, does not accept `Split-Path -LiteralPath` with `-Parent` or `-Leaf`. | #79 |
| Every page shows a CodeIgniter error from `DotEnv.php:64`: `The .env file is not readable`. | `secrets\app.env` appears as root-only mode 600 inside the container. | #81 copies it to a `www-data` file at every start. |
| Settings shows "Whoops! We seem to have hit a snag", and the log says `stat failed for /app/public/uploads/item_pics/`. | `uploads\` appears as root-only mode 700, and neither `chmod` in the container nor `icacls` on Windows changes that. | #83 keeps pictures in the `client_uploads` Docker volume. |

If one of these comes back, first check that the checkout is on the latest `develop`, then check `ospos.conf` for `OSPOS_UPLOADS=client_uploads`.

To see the real error behind a "Whoops" page, read the app log:

```bash
MSYS_NO_PATHCONV=1 docker exec osposleb-ospos-1 sh -c 'tail -n 60 /app/writable/logs/log-*.log'
```
