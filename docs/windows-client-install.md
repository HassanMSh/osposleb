# Windows client install checklist

This is the step-by-step list for setting up the shop on a new Windows client computer.

It was written from the first real run on a Windows 10 shop computer on 2026-09-23 (issue #70). Problems found in that run are fixed in the repository and listed at the end so they can be recognised if they come back.

The full reference for the client folder, secrets, and backups is [backup and restore](backup-and-restore.md). Daily operation is in the [operations runbook](operations-runbook.md).

## Before you start

- Windows 10 or 11 with Docker Desktop installed and running, using the WSL 2 engine and Linux containers (the defaults).
- Git for Windows, which also installs Git Bash.
- Internet access, to download the app image.
- The shop hardware (scanner, receipt printer, cash drawer) connected to the computer.
- The client folder is always `C:\OSPOS\Client`, so the `C:` drive must be NTFS. The internal `C:` drive is NTFS.

## Which window to type in

The commands below are for Git Bash, run from the repository folder. `./shop` handles the Git Bash path rules and the PowerShell execution policy for its own commands.

For any other command you type by hand, Git Bash has two traps:

- It rewrites any argument that starts with `/` (for example `/app/public/uploads`) into a Windows path. Put `MSYS_NO_PATHCONV=1` in front of any command that passes a container path, or run that command in PowerShell instead.
- It removes backslashes in unquoted Windows paths. Wrap Windows paths in single quotes (`'C:\OSPOS\Client'`) or use forward slashes (`C:/OSPOS/Client`).

Windows blocks local PowerShell scripts by default. When you run one of the project's `.ps1` scripts by hand, use `powershell.exe -ExecutionPolicy Bypass -File ./scripts/<name>.ps1 ...`.

## 1. Get the repository

Clone the repository into a folder that will not move, because the daily backup task runs the backup script from this folder.

```bash
git clone https://github.com/HassanMSh/osposleb.git
cd osposleb
git switch develop
```

Keep the checkout on the `develop` branch. `./shop update` refuses to run from any other branch, and a new database is created from the SQL files in this checkout.

## 2. Install and start

```bash
./shop install
```

The command does these steps and skips any step that is already done, so it is safe to run again:

- Checks Docker Desktop, Linux containers, Git, curl, `sha256sum`, `gzip`, `tar`, the NTFS drive and app port; it warns about secrets access, USB copies, restart policies, and Docker Desktop startup.
- Creates `C:\OSPOS\Client` with its secrets and locks the folder before setup; setup accepts the lock but stops if the folder has any other files and no `ospos.conf`.
- Downloads the app and database images the first time, starts the app, and waits until it answers.
- Schedules the daily backup at 23:30 (`./shop install TIME=HH:MM` picks another time).
- Prints the manual steps that are left.

Then:

- In Git Bash, confirm `sha256sum`, `gzip`, and `tar` are installed; `./shop check` reports each tool.
- The setup output should include a line starting with `Protected secrets for`.
- Copy `C:\OSPOS\Client\secrets` to a safe place that is not the backup drive. It holds the database passwords and the encryption key.
- Check that `C:\OSPOS\Client\ospos.conf` contains `OSPOS_UPLOADS=client_uploads`. Windows setup adds it so item pictures are kept in a Docker volume.
- If port 80 is already used, `./shop install` creates the client folder and then stops. Set `OSPOS_HTTP_PORT=8080` in `ospos.conf`, run `./shop install` again, and use `http://localhost:8080/`.
- `./shop status` shows the containers, code version, running image, last backup result, and daily backup task status.

### Shop installed by hand before `./shop`

A shop set up with the older manual steps uses the same `osposleb` folder, the same `C:\OSPOS\Client` folder and the same Compose files, so `./shop` picks up its containers, data and item pictures. Do not reinstall it and do not run `./shop install`; switch it over once with these steps in Git Bash:

```bash
cd osposleb
git switch develop
git pull --ff-only origin develop
./shop check
./shop update
```

- `git pull` is the only manual pull, because the old checkout does not contain `./shop` yet. After this, only `./shop update` pulls.
- If `git pull` or `./shop update` reports local changes or untracked files, the manual setup left files in the checkout. Move or delete them, then run the command again.
- `./shop update` takes a backup, saves a rollback point, downloads the latest app image and restarts the shop. Stop sales first.
- The daily backup task created by hand keeps working, because it runs `scripts/backup.ps1` from this checkout.
- The rollback point saved by this first update pairs the old app image with the new checkout, because `git pull` moved the code first. The app itself is inside the image, so a rollback still returns the old app, but it is not a full old-version rollback. From the next `./shop update` on, code and image move together.

## 3. First login and settings

1. Open `http://localhost/` in Google Chrome. After section 4, use the Chrome shortcuts described there instead. The first start creates the database, so wait about a minute if the page shows a database error.
2. Log in with `admin` / `pointofsale`.
3. Change the admin password under Employees.
4. In Settings, Localization tab, set the timezone to `Asia/Beirut` and save. The default is `America/New_York`, which stamps sales 7 hours early.
5. Leave the shop language on Arabic (Lebanon).
6. Upload a company logo in Settings to prove that pictures can be saved.

### Restaurant till setup

In Settings > General, set Till layout to Restaurant and save.
Create each menu choice as a standard item on the Items page and give it a category.
Set each restaurant item's Tax mode to No TVA and choose Non-stock.
Tap a main item in the cart to choose where new add-ons go.
New add-ons go under the chosen item, even when other cart lines follow it.
Deleting a main item also deletes its add-ons.
Changing an item's quantity, price, or discount does not change its add-ons.
Fixed line discounts are entered in Lebanese pounds (LL), while percentage discounts are entered as a percent.
Create a category such as "Add-ons" and put extras and removals like "Extra cheese" or "No onion" in it with their own price or 0.
In Settings > General, enter that category in Add-on category.
On screens at least 992 pixels wide, the restaurant register shows sections, items, and the bill in three columns.
English places sections on the left and the bill on the right; Arabic mirrors them.
On narrower screens, the sections, items, and bill are stacked and the page scrolls.
Select a section, then tap an item.
The Add-ons section appears last, and add-on items have dashed borders.
On the three-column layout, the order lines and the bill details scroll on their own, and the Amount Tendered box and the Complete button stay at the bottom of the screen.
On both the shop and restaurant tills, empty "No description" lines are not shown in the cart; items that allow or have a description still show it and it can be edited.
After each restaurant sale, the printer prints the customer receipt and then the kitchen ticket.
The print button on the receipt page and in the sales list reprints both.
The cut between them depends on the printer driver cutting after each page.
Returns, quotes, and suspended sales do not print a kitchen ticket.
Add-on lines show indented with a + under their item on the kitchen ticket too.
Set Till layout back to Shop to restore the item search and barcode box.

#### Load the menu from a CSV

- Open Items > CSV Import and download the template first.
- Set `Stock Type` to `Non-stock` and `Tax Mode` to `No TVA` for restaurant items.
- Enter Unit Price in dollars: divide the Lebanese pound price by the exchange rate and round to 2 decimals.
- Save the CSV file as UTF-8 so Arabic names stay readable.
- The Image column only names a file; copy picture files into the `client_uploads` volume at `/app/public/uploads/item_pics` first.

## 4. Hardware

The app runs in Chrome, so Windows and Chrome handle the hardware. Docker is not involved.

- Barcode scanner: on the sale screen, click the item box and scan. The item must be added without pressing any key. An unknown barcode must show an error.
- Receipt printer: install the Windows driver and make it the default printer. In Windows Settings, Printers & scanners, turn off "Let Windows manage my default printer" so Windows does not change the default. Test it from the POS Register shortcut (below).
- Cash drawer: it is usually connected to the receipt printer. Turn on the "open drawer" (or "kick drawer") option in the printer driver so the drawer opens when a receipt prints.
- Barcode label printer: tested 2026-09-23 on an Xprinter XP-365B. The shop's labels measure 1.6 × 1.05 in (about 40.6 × 26.7 mm, measured 2026-09-24, issue #97).
  - In the driver, add a paper size of 1.6 × 1.05 in (Portrait), the exact size of one label, under both "Printing preferences" and "Printer properties → Advanced → Printing Defaults". Then restart Chrome. A paper size that does not match the real label makes the content land at a different height on each label and can split one label across two.
  - Calibrate the label gap: turn the printer off, hold FEED, turn it on, and let go after the second beep.
  - In Settings, Barcode tab: type EAN13, width 125, height 30, font size 9, number in row 1, page width 100, cell spacing 0, first row item name, second row retail price, third row none.
  - Print labels from the POS Office shortcut (below). In Chrome's print window: destination XP-365B, paper size the label size, margins None, scale Custom 100, headers and footers off.
  - Each item prints on its own label. The page drops its extra spacing only on paper 80 mm wide or narrower, so A4 label sheets print as before.

### Chrome shortcuts for printing

`./shop install` does not create these shortcuts. Create them by hand.

Receipts must print with no Chrome print window, but barcode labels need the window so the label printer can be picked. Chrome's `--kiosk-printing` option skips the window for everything that Chrome prints, so the shop uses two desktop shortcuts. Each one has its own `--user-data-dir`, which makes it a separate Chrome with its own settings and login (issue #93).

| Shortcut | Target | Use it for |
| --- | --- | --- |
| POS Register | `"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk-printing --user-data-dir="C:\POS\chrome-register" http://localhost/sales` | Selling. Receipts go straight to the default printer. |
| POS Office | `"C:\Program Files\Google\Chrome\Application\chrome.exe" --user-data-dir="C:\POS\chrome-office" http://localhost/` | Items, barcode labels, reports and settings. Chrome shows its normal print window. |

1. Create each shortcut: right-click the desktop, New, Shortcut, and paste the target. If port 80 was changed, use the same port in the address. If Chrome is installed elsewhere, use its real `chrome.exe` path.
2. Close every Chrome window before you open each shortcut for the first time. If another Chrome is already running, the new window can join it and ignore the options.
3. Log in once in each shortcut. They do not share a login.
4. In Settings, Receipt tab, set "Print Receipt checkbox" to "Always checked" and "Autoreturn to Sale delay" to `1`. With `0`, the page can go back to the sale before the receipt is sent.
5. Test in POS Register: finish a sale. The receipt must print with no window, and the screen must return to a new sale.
6. Test in POS Office: print a barcode sheet. The print window must open, and Chrome remembers the label printer for this shortcut.

Do not print labels from POS Register, because they would go to the receipt printer without asking.

Result of the first run: pending. Record the device models and any settings needed here.

## 5. Backups

`./shop install` already scheduled the daily backup. Take one manual backup now:

```bash
./shop backup
./shop backups
```

- `./shop backup` prints the last backup log line and the copy result; a failed configured USB copy includes a Docker Desktop File Sharing reminder.
- To change the daily time, run `./shop schedule-backup TIME=HH:MM`. To remove the task, run `./shop schedule-backup REMOVE=1`.
- The scheduled task runs only while the shop's Windows account is logged in.
- Windows backups do not include item pictures yet (issue #82).

### Backup copy to another drive

Set this up so each backup is also copied to a second drive. The target can be any other drive: a USB drive, a second disk, or another partition.

1. In Explorer, create the target folder, for example `E:\OSPOS-Backups`.
2. Open `C:\OSPOS\Client\ospos.conf` and set `OSPOS_BACKUP_COPY_TO='E:\OSPOS-Backups'`. Use single quotes and the full Windows path.
3. In Docker Desktop, open Settings, Resources, File Sharing, add the drive, then select Apply and Restart. Without this, the whole backup can fail, not only the copy.
4. Run `./shop backup` and check that it prints `Backup copy: ok`. `missing` means the folder is not there; the backup itself still succeeds.

- The copy keeps the same number of archives as `backups\` (7 by default).
- It deletes only old `ospos-backup-*.tar.gz` files, so other files on the drive are safe.
- On an exFAT or FAT32 drive, anyone holding the drive can read the archive (see [Backup and restore](backup-and-restore.md)).
- `./shop check` reports whether the copy folder is available.

Do one practice restore before the shop starts selling, because it replaces the database. Record how long it takes. The command asks before it replaces anything, stops the app, restores, and starts the app again.

After restore, check the printed item, sale, and employee counts and the elapsed time.

The restore launcher writes `restore.unfinished` before it runs; a new marker is cleared after code 20, while an earlier marker and code-21 or unknown failures keep it.

If `restore.unfinished` exists, a full restore has not succeeded, so do not run `./shop start` or `./shop update`.

Retry with `./shop restore ARCHIVE=<known-good-backup>`; a successful restore clears the marker.

```bash
./shop restore ARCHIVE=ospos-backup-YYYYMMDD-HHMMSS.tar.gz
```

Result of the first run: pending.

## 6. Finish

- In the issue #70 Windows run, test an update that changes `shop` itself, then run another `./shop` command.
- Tick the passed items on the client install issue (#70) and open an issue for anything that failed.
- Leave Docker Desktop set to start when Windows starts.

## Daily use

| Command | What it does |
| --- | --- |
| `./shop status` | Shows the containers, code version, running image, recovery markers, lock age and owner, recovery steps, and last backup result. |
| `./shop start` / `./shop stop` | Starts or stops the shop without downloading anything, and start refuses while update or rollback recovery is needed. |
| `./shop update` | Refuses local changes and previews code and image changes before asking; stop sales first because it downloads the app image before the rollback backup, then saves a rollback point, pulls code, and starts the shop without changing the database image. |
| `./shop rollback` | Checks and fully extracts the saved archive before stopping, restores through the current checkout, checks out the saved code, and starts the saved image; see the recovery steps below if a rollback retry is needed. |
| `./shop backup` / `./shop backups` | Takes a backup now, or lists the backups newest first. |
| `./shop restore ARCHIVE=<file>` | Validates and restores a backup by file name or path, after asking; it refuses while update or rollback recovery is unfinished. |
| `./shop unlock` | Shows the lock owner and clears the lock only after you type `UNLOCK SHOP LOCK`; `--yes` does not bypass the prompt. |
| `./shop logs` | Shows the last 200 lines of container logs (`LINES=<count>` changes the number). |

If rollback fails after checking out the saved code, run these commands in order:

```bash
git switch develop
git pull --ff-only origin develop
./shop rollback
```

If `git pull` fails, restore the internet connection and run it again before `./shop rollback`; the retry needs the fixed script from `develop`.

The image used after an update is the newest published `develop` image.
Right after a merge, the image build takes a few minutes; `./shop update` warns when there is new code but no new image yet and refuses if either the digest or downloaded image ID matches the version rolled back.

If rollback could not record either the source image ID or digest, `./shop update` requires `--allow-unknown-image` and the exact typed phrase `ALLOW UNKNOWN IMAGE`; `--yes` does not bypass this check.

An update retry keeps the saved rollback point; `./shop status` advises update retry before a point is saved and rollback after one is saved.

An update after a completed rollback saves a fresh point with the sales made since that rollback.

Shop commands and the scheduled backup share a lock in `C:\OSPOS\Client`, and failed scheduled runs record lock age and unlock advice in `backup.log`.

After a forced shutdown, use `./shop status` to check the lock owner and run `./shop unlock` only after checking that no shop command or backup is running.

## Problems found on the first run

All three came from one cause: Docker Desktop shows folders shared from Windows as readable and writable by the container's root user only, while the web server runs as `www-data`. Each one is fixed in the repository.

| What you see | Cause | Fix in the repository |
| --- | --- | --- |
| `setup-client.ps1` stops with a "parameter set cannot be resolved" error. | Windows PowerShell 5.1, which ships with Windows 10, does not accept `Split-Path -LiteralPath` with `-Parent` or `-Leaf`. | #79 |
| Every page shows a CodeIgniter error from `DotEnv.php:64`: `The .env file is not readable`. | `secrets\app.env` appears as root-only mode 600 inside the container. | #81 copies it to a `www-data` file at every start. |
| Settings shows "Whoops! We seem to have hit a snag", and the log says `stat failed for /app/public/uploads/item_pics/`. | `uploads\` appears as root-only mode 700, and neither `chmod` in the container nor `icacls` on Windows changes that. | #83 keeps pictures in the `client_uploads` Docker volume. |

If one of these comes back, first check that the checkout is on the latest `develop`, then check `ospos.conf` for `OSPOS_UPLOADS=client_uploads`.

To see the real error behind a "Whoops" page, read the app log. The app container is named after the repository folder, for example `osposleb-ospos-1`; `docker ps --format '{{.Names}}'` lists the names.

```bash
MSYS_NO_PATHCONV=1 docker exec osposleb-ospos-1 sh -c 'tail -n 60 /app/writable/logs/log-*.log'
```
