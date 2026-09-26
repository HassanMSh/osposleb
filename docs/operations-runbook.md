# Shop operations runbook

Run `./shop` from Git Bash in the repository folder.

The client folder is always `C:/OSPOS/Client` on Windows, even if the shell has another `OSPOS_DATA_DIR` value, and defaults to `$HOME/ospos-client` on Linux.

Shop commands and standalone backup and restore launchers share a lock in the client folder, so they cannot overlap.

The lock records its process ID, host, command, start time, and age for `./shop status`.

Client backups refuse to run or apply retention while `restore.unfinished` or `rollback.unfinished` exists.

If a command says another shop command or backup is running, check its owner with `./shop status`.

After a forced shutdown, inspect the owner with `./shop status` and use `./shop unlock` only after checking that no shop command or backup is running.

`./shop unlock` shows the owner details again and requires the exact phrase `UNLOCK SHOP LOCK`; `--yes` does not skip this prompt.

The backup log records a failed result, lock age, and unlock advice when a scheduled backup cannot get the lock.

The first install takes this lock before setup begins; setup accepts only that lock as a pre-existing entry, then creates the client files.

The exported `OSPOS_IMAGE_TAG` set by `./shop` takes priority over a value in `ospos.conf`.

## Item prices and pound amounts

Enter item cost and retail prices in Lebanese pounds (LL) on the item form.

Cost and retail prices both show rounded to the nearest 1,000 LL, with 500 LL and above rounding up.

The Items list shows those same rounded LBP cost and retail prices.

The database stores prices in dollars with two decimal places, and the current Settings rate controls the LBP values shown on item forms, the Items list, the till, and new receipts.

Saving an item without changing its displayed prices keeps its stored dollar prices unchanged.

On the till, each customer-paid unit is rounded to the nearest 1,000 LL after discount and TVA, then multiplied by its quantity; the LBP sale total is the sum of those rounded lines.

The change helper and receipt pound total use that same LBP sale total, while receipt lines and payment entry stay in dollars. The restaurant till does not show the change helper.

The amount-due line below the dollar amount due is rounded to a whole pound and shows zero when the sale is fully paid.

Rule R can make a displayed retail price differ from the exact dollar price times the rate by up to 500 LL per unit.

Storing a typed pound price in cents can add up to 450 LL of difference at a 90,000 rate.

Retail prices typed outside whole thousands show rounded to the nearest 1,000 LL, so 2,500 LL shows as 3,000 LL.

A cost of 100,000 LL at a 90,000 rate stores as $1.11 and reopens at 99,900 LL because the database keeps cents.

At 90,000 LL per dollar, a $4.22 report total is 379,800 LL while rounded item lines can collect 380,000 LL, so the pound cash drawer may differ from the dollar total times the rate.

Each completed sale and return also saves the LBP sale total the till showed and the rate used, and returns save a negative total.

The "Total (LBP)" column and footer of the Sales Summary report add up those saved sale totals, so they match what the till charged even after the rate changes.

Use that figure as the expected pound total at closing, and still count the drawer and check the recorded payments separately.

The "Total (LBP)" cell stays blank for a day that includes a sale completed before this total was saved, and the footer stays blank when any such sale is in the selected dates.

The Detailed Transactions report also has a "Total (LBP)" column with each sale's saved total, blank for older sales, and a footer that follows the same rule.

Held sales, quotes, and work orders save no LBP total until they are completed.

A reprinted or emailed receipt shows the saved LBP total and the rate used at the sale, not today's rate; receipts of older sales still use the current rate.

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

On Windows, `./shop check` also reports secrets-folder access, USB backup-folder availability, container restart policies, and Docker Desktop startup warnings.

On Windows, `./shop status` shows whether the daily backup task exists, its next run, and its last result.

## One-minute weekly backup check

Run `./shop backups` and check the last line from `./shop status`.

The backup log is in the folder set by `OSPOS_BACKUP_DESTINATION` in `ospos.conf`, normally `<client>/backups/backup.log`.

Confirm that there is one `result=ok` line for each day the shop was open.

Check the `copy` value when a USB copy is configured.

`./shop backup` prints the copy result and warns when a configured USB copy did not succeed.

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

After a successful restore, the command prints the item, sale, and employee counts and the time it took.

`./shop` writes `restore.unfinished` before restore; a first code-20 failure clears the new marker and restarts the app, while an earlier marker or a code-21/unknown failure keeps it.

If the marker exists, a full restore has not succeeded, so `./shop start` and `./shop update` refuse to run.

Retry with `./shop restore ARCHIVE=<known-good-backup>`; a successful restore clears the marker.

Do not start the services with raw Compose commands while the marker exists, because Compose does not check it.

After the restore, open Settings and check that item pictures show.

## Update and rollback

Before `./shop update`, stop sales at the register; it downloads only the app image while the old app is still running, then takes the backup used for rollback.

Update never downloads or changes the database image.

After the backup, update switches the checkout and starts the new app image.

The update checks Docker Hub with `docker buildx imagetools inspect` to see whether the `develop` image changed.

The shop image uses the moving `develop` tag; `./shop` sets `OSPOS_IMAGE_TAG` itself.

The container applies pending database migrations when it starts.

If it cannot reach the database or a migration fails, it stops and writes the reason to its logs.

Because the container restarts automatically, a startup that keeps failing shows as a container that keeps restarting.

Check `./shop status` and `./shop logs`, then fix the database or restore the backup taken before the update.

The update command refuses tracked changes and non-ignored untracked files; move or delete the listed files before retrying.

The update command asks before it downloads the app image and takes the backup.

If `./shop status` shows a recovery marker, follow its recovery steps and wait for the marker to clear before opening the shop.

An update writes a recovery marker and pins the current app image before the image download; `./shop status` prints the allowed recovery steps if the update does not finish.

If an update marker exists but no rollback point was saved, retry with `./shop update`.

If a rollback point was saved, recover with `./shop rollback`.

Restore stays refused while the update marker exists, and only a successful rollback clears that marker.

Retry an unfinished rollback with `./shop rollback`; if it fails after the saved code is checked out, run these commands in order:

```bash
git switch develop
git pull --ff-only origin develop
./shop rollback
```

If `git pull` fails, restore the internet connection and run it again before `./shop rollback`; the retry needs the fixed script from `develop`.

A second completed rollback is refused until a new update saves a fresh point.

Restore is advised only when `restore.unfinished` shows that database changes may be partial.

### Recover a missing pinned image

If `./shop update` reports that its pinned image is missing, do not start the app or use raw Compose commands while the update marker remains.

If `./shop status` shows `Update pending: 1`, use the advised `./shop rollback`; its saved rollback image is the recovery image.

If no rollback point was saved, read the exact `UPDATE_SOURCE_IMAGE_ID` from `update.in-progress` in the client folder.

Ask the project owner or administrator to find that exact image in a trusted Docker image archive or another shop host.

Load the trusted archive with `docker load --input <archive-file>`.

Tag the exact recorded image ID with `docker tag <recorded-image-id> hassanshamseddine/osposlb:update-source`.

Confirm the tag with `docker image inspect --format '{{.Id}}' hassanshamseddine/osposlb:update-source` and compare its result with the marker.

After the exact image is back under that tag, retry with `./shop update`.

If the exact image cannot be recovered, leave the app stopped and contact the project owner; do not clear the marker by hand.

An update retry keeps the saved rollback point; a new update after a completed rollback saves a fresh point with the sales made since that rollback.

To roll back, use `./shop rollback`; it checks the saved archive checksum, manifest and database checksum, and fully extracts every archive entry before stopping the app, restores through the current checkout, checks out the saved code, then starts the saved image.

After one rollback finishes, a second rollback is refused until a new `./shop update` saves a fresh rollback point.

Older rollback records without an archive checksum are accepted after validation, and the command saves the calculated checksum only after its archive, commit, and image checks pass.

If a saved rollback archive fails validation, rollback stops before stopping the app and nothing was changed; ask the project owner to replace the saved archive and its recorded checksum from a trusted copy, then retry `./shop rollback`.

After a rollback, `./shop update` stops if either the published digest or downloaded image ID matches the image that was rolled back, even if the code has advanced.

If rollback recorded neither the source image ID nor digest, update requires `--allow-unknown-image` and the exact typed phrase `ALLOW UNKNOWN IMAGE`; `--yes` does not bypass this check.

Restore returns exit code 20 for failures before database import and 21 once import may have started; the Linux and Windows launchers pass 20 and 21 through and map every other Docker failure to 21.

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
