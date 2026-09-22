# Shop operations runbook

## Start the shop

Open PowerShell in the repository folder.

Set `OSPOS_DATA_DIR` to the client folder.

```powershell
$env:OSPOS_DATA_DIR = 'C:\OSPOS\Client'
docker compose --env-file "$env:OSPOS_DATA_DIR\ospos.conf" -f docker-compose.yml -f docker-compose.client.yml up -d
```

The web page is available on the shop computer at `http://localhost/`.

## Stop the shop

Run this command from the repository folder.

```powershell
docker compose --env-file "$env:OSPOS_DATA_DIR\ospos.conf" -f docker-compose.yml -f docker-compose.client.yml down
```

Do not add `-v` when stopping the shop because that removes its database volume.

## Check shop health

Check that both containers are running.

```powershell
docker compose --env-file "$env:OSPOS_DATA_DIR\ospos.conf" -f docker-compose.yml -f docker-compose.client.yml ps
```

Open `http://localhost/` in Google Chrome and sign in.

## One-minute weekly backup check

Open `backup.log` in Notepad once a week. It is in the backup folder set by `OSPOS_BACKUP_DESTINATION` in `ospos.conf`, normally `<client>\backups\backup.log`.

Confirm that there is one `result=ok` line for each day the shop was open.

Check the `copy` value when a USB copy is configured.

The log time and archive names use UTC.

Beirut is UTC+2 in winter and UTC+3 in summer.

See [backup and restore](backup-and-restore.md) for backup checks and restore steps.

## Restore a backup

Use the restore steps in [backup and restore](backup-and-restore.md).

Stop sales before restoring because restore replaces the selected database and uploads folder.

Check the archive and target database before confirming the restore.

Stop the `ospos` container before the restore and start it after, because restore swaps in a new uploads folder and a running container keeps using the old one.

After the restore, open Settings and check that item pictures show.

## Update and rollback

The shop image version is pinned with `OSPOS_IMAGE_TAG`; see "Pin or roll back the client image" in [backup and restore](backup-and-restore.md).

The container does not apply database migrations by itself yet, so an update that changes the database still needs the developer.

Always take a backup and confirm its `result=ok` line before changing the image version.

To update: take a backup, set `OSPOS_IMAGE_TAG` to the new version, pull, and start.

To roll back: set `OSPOS_IMAGE_TAG` back to the previous version and restore the backup taken before the update.

Rolling back only the image is not supported, because a newer version may have changed the database.

## Collect logs for support

Collect the output from `docker compose ps` and the recent application and database logs.

```powershell
docker compose --env-file "$env:OSPOS_DATA_DIR\ospos.conf" -f docker-compose.yml -f docker-compose.client.yml logs --tail 200
```

Also collect `backup.log` from the backup folder and the Task Scheduler task history.

Do not send `secrets\`, passwords, customer data, or a full database backup unless support asks for it through an approved safe method.

## Install checklist

- Change the `admin` password at the first login.
- Set the timezone to Beirut in OSPOS Settings.
- Schedule a daily backup with `scripts\schedule-backup.ps1`.
- Copy the `secrets` folder to a safe place away from the backup drive.
- Turn on Dependabot alerts in the GitHub repository settings.

## Support limits

Supported use is one Windows computer with Docker Desktop and Google Chrome.

English and Arabic (Lebanon) are supported.

Hardware support depends on the named devices and real-device checks.

Networked tills or tablets, remote access, cloud backups, and other browsers are not supported.

Updates without a backup first are not supported.

## Known risks

Without a USB drive, one disk failure can lose both the shop data and its backups.

MariaDB 10.5 is past end of life and remains an accepted tracked risk.

Automatic backups run only while the shop account is logged in.

The weekly log check is the only alert for a failed daily backup.
