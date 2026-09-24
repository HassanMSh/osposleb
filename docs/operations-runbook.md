# Shop operations runbook

Run `./shop` from Git Bash in the repository folder.

The client folder is `C:/OSPOS/Client` on Windows and defaults to `$HOME/ospos-client` on Linux.

The exported `OSPOS_IMAGE_TAG` set by `./shop` takes priority over a value in `ospos.conf`.

## Start the shop

```bash
./shop start
```

Start does not pull new code or an image.

The web page is available on the shop computer at `http://localhost/` or at the configured port.

## Stop the shop

```bash
./shop stop
```

Stop brings the containers down without removing the database volume.

Never remove volumes when stopping the shop.

## Check shop health

```bash
./shop check
./shop status
```

Open the local shop page in Google Chrome and sign in.

## One-minute weekly backup check

Run `./shop backups` and check the last line from `./shop status`.

The backup log is in the folder set by `OSPOS_BACKUP_DESTINATION` in `ospos.conf`, normally `<client>/backups/backup.log`.

Confirm that there is one `result=ok` line for each day the shop was open.

Check the `copy` value when a USB copy is configured.

The log time and archive names use UTC.

Beirut is UTC+2 in winter and UTC+3 in summer.

See [backup and restore](backup-and-restore.md) for backup checks and restore steps.

## Restore a backup

Stop sales before restoring because restore replaces the selected database and uploads folder.

Check the archive and target before confirming the restore.

```bash
./shop restore ARCHIVE=ospos-backup-YYYYMMDD-HHMMSS.tar.gz
```

The command stops the app before restore and starts it after, because restore swaps in a new uploads folder and a running container keeps using the old one.

After the restore, open Settings and check that item pictures show.

## Update and rollback

Use `./shop update` to fetch changes, take a backup, and update the checkout and image.

The update checks Docker Hub with `docker buildx imagetools inspect` to see whether the `develop` image changed.

The shop image uses the moving `develop` tag; `./shop` sets `OSPOS_IMAGE_TAG` itself.

The container applies pending database migrations when it starts.

If it cannot reach the database or a migration fails, it stops and writes the reason to its logs.

Because the container restarts automatically, a startup that keeps failing shows as a container that keeps restarting.

Check `./shop status` and `./shop logs`, then fix the database or restore the backup taken before the update.

The update command checks for tracked local changes and asks before it takes the backup and pulls.

If `./shop status` says an update is pending, run `./shop update` to retry or `./shop rollback` to return to the saved point.

To roll back, use `./shop rollback`; it restores the backup taken before the update along with the saved code and image.

Rolling back only the image is not supported, because a newer version may have changed the database.

If login keeps returning to the login page with "A database migration to ... will start after login", run `./shop rollback`.

## Collect logs for support

```bash
./shop status
./shop logs LINES=200
```

Also collect `backup.log` from the backup folder and the Task Scheduler task history.

Do not send `secrets/`, passwords, customer data, or a full database backup unless support asks for it through an approved safe method.

## Install checklist

- Change the `admin` password at the first login.
- Set the timezone to Beirut in OSPOS Settings.
- Run `./shop install` to schedule a daily backup on Windows or print the Linux cron line.
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
