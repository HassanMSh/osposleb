# ADR 0011: One configuration directory for a client installation

- Status: Accepted
- Date: 2026-09-20
- Decision owners: Project owner and implementation team
- Scope: Per-shop configuration, secrets, uploads, and backup destination for the OSPOS Lebanon fork
- Baseline: `develop` snapshot `bcc9efc7c1ecf48273f03c5c0e3b24a8aef0c350`, application version 3.4.1
- Branch: `feat/client-config`
- Related: ADR 0013 (containerised backup, Windows first), ADR 0009 (deployment and production hardening, still to be written), `docs/backup-and-restore.md`

## Context and current behaviour

The shop computer is Windows, while development runs on Linux.

Everything that makes one installation different from another must live in one place that can be copied to a replacement machine.

Before this change, database credentials and the application encryption key lived in `.env` beside the application code.

Item pictures and the company logo were written to `public/uploads` inside the application container.

The database lived in a Docker named volume.

Backups required a destination on every command.

The shipped `docker-compose.yml` mounts a volume at `/app/writable/uploads`, which is the CSV import scratch area, but does not mount `/app/public/uploads`.

`app/Controllers/Items.php:820` and `app/Controllers/Config.php:342` write the item pictures and company logo to `public/uploads`.

Those files were therefore destroyed whenever the application container was replaced, including normal upgrades.

The first Linux-only draft of this ADR proposed binding the database directory and relying on Unix ownership and `chmod 600`.

That approach does not work honestly on a Windows Docker Desktop bind mount.

## Requirements and non-goals

### Requirements

- Keep one client directory for non-secret settings, secrets, uploads, and backups.
- Keep database files in a Docker named volume on both Linux and Windows.
- Generate separate MariaDB root and application database passwords, plus the application encryption key, inside a throwaway container.
- Provide thin Linux and Windows setup launchers over one container entry point.
- Protect Linux secret files with mode 600.
- Restrict NTFS secrets to the current Windows account and refuse setup when the filesystem cannot protect them.
- Mount the application environment read-only and mount `public/uploads` from the client directory.
- Let the backup command use the destination in `ospos.conf` when no destination is supplied.
- Keep explicit backup destinations working.
- Keep secrets out of backup archives.
- Refuse setup when the target directory already exists.

### Non-goals

- No database bind mount.
- No change to the upstream Compose files.
- No automatic backup scheduling or retention policy.
- No passphrase encryption of the secrets in this workstream.
- No claim of Windows hardware or filesystem verification without a real Windows test.

## Decision

One client installation uses this layout.

```text
<data directory>/
  ospos.conf
  secrets/
    app.env
    db.env
  uploads/
  backups/
```

The database files are not in this directory.

The database stays in the named `mysql` volume on both platforms.

The client Compose override uses the one `OSPOS_DATA_DIR` variable.

`ospos.conf` is a non-secret Compose environment file and records `OSPOS_DATA_DIR` plus the relative `OSPOS_BACKUP_DESTINATION=backups` setting.

The operator passes `ospos.conf` to Compose with `--env-file` and exports `OSPOS_DATA_DIR` for the launchers.

The setup entry point creates the layout, generates both secrets, writes `app.env`, writes `db.env`, writes `ospos.conf`, and prints the paths it created without printing secret values.

The Linux launcher runs the entry point as the current UID and GID so the generated files belong to the operator.

The Windows launcher creates the new target and its `secrets` directory, applies the platform-specific filesystem and ACL preparation, and then runs the shared entry point in prepared-directory mode.

The Windows launcher restricts `secrets\` with `icacls`, removes inherited access, and grants full control to the current Windows account on NTFS before any secret file is written. If filesystem discovery or ACL preparation fails, it removes the exact new target and stops.

The Windows launcher refuses exFAT and FAT32 because those filesystems do not store permissions. It also refuses any other filesystem it cannot confirm as NTFS.

The setup command refuses to run if the target directory already exists, including an empty directory or a symlink.

The generated `app.env` contains the CodeIgniter database settings, the application database username and password, the application database environment values, and the application encryption key.

The generated `db.env` contains only the MariaDB root password.

The application Compose service injects `secrets/app.env` as its environment and also binds the same file read-only at `/app/.env`, and binds `uploads/` to `/app/public/uploads`.

The application reads the generated settings from the bind-mounted `/app/.env`, because Compose `env_file` silently drops any key containing a dot, such as `database.default.*` and `encryption.key`, so those keys never reach the container's environment. The secret file is still not copied into the image and is not made readable by the web-server account inside the container.

The MariaDB service reads both `secrets/app.env` and `secrets/db.env` with Compose `env_file`. This supplies the application account values from `app.env` and the separate root password from `db.env`.

The override resets the upstream hard-coded environment values so the generated password is used.

The override does not change the `/var/lib/mysql` named volume.

The backup launcher uses the client uploads and application environment when `OSPOS_DATA_DIR` is set.

When the configured client backup destination is used, the launcher mounts only the application environment, the config file, the uploads directory, and the writable backup destination. It does not mount the whole client directory.

With no explicit destination, it passes `ospos.conf` to the container entry point.

The entry point reads `OSPOS_BACKUP_DESTINATION` without sourcing the file and resolves the relative path under the client directory.

An explicit `--destination` bypasses that setting and still wins.

The restore launchers accept the client application environment through `OSPOS_DATA_DIR` or `--env` so a restored installation uses its own credentials.

The application secret file is mounted read-only into the backup and restore tool containers. The backup tool gets no mount for `db.env` or the rest of the client directory.

The secret files are never copied into the application image and are never included in a backup archive. Backup and restore reject `.env`, `app.env`, and `db.env` by basename at every archive depth.

The passphrase-encrypted secrets archive proposed in the first draft is deferred.

It depends on ADR 0009 decisions about who holds the passphrase and how a lost passphrase is recovered.

Until then, keeping secrets out of every archive is the proved behaviour.

## Why the database remains a named volume

A Windows Docker Desktop bind mount does not provide MariaDB with normal POSIX file locking or ownership.

The failure mode can be corruption rather than a clear startup error.

The recovery path for this shop is a restored database backup, not a copied database directory.

Backups already cover the database and uploads end to end.

Keeping the named volume gives Linux and Windows the same deployment behaviour and avoids a class of Windows-only corruption.

## Alternatives considered

### Bind-mount the database directory

Rejected because Windows file locking and ownership are emulated and unsafe.

It is also rejected on Linux so both platforms have one recovery model.

### Put the whole client directory on an external drive

Rejected as the default because an unplugged or failed drive would stop the shop.

The external drive is for backup archives, not live database or application state.

### Require WSL or Git Bash on Windows

Rejected because Docker Desktop is already required and the shop gains no second host runtime.

### Use Docker secrets

Rejected because Swarm or a more complex Compose feature set is unnecessary for a single-shop installation.

### Write a separate PowerShell setup implementation

Rejected because it would duplicate secret generation and layout logic.

PowerShell only performs the Windows-specific ACL operation after the shared container entry point finishes.

### Encrypt secrets with a passphrase now

Deferred rather than rejected.

The owner, recovery process, and lost-passphrase policy belong in ADR 0009.

## Security and operational consequences

Linux secret files are mode 600 and the containing directory is mode 700. The root and application database passwords are different values.

NTFS protection is applied to the `secrets` directory for the current Windows account.

exFAT and FAT32 provide no secret protection, and the setup command refuses to write secrets there.

Anyone with administrator rights on the shop computer, or anyone holding the disk, can read the secrets.

The secrets are not in backups.

A lost shop computer therefore leaves the backup archive usable only after the owner supplies a separate copy of `secrets`.

The owner must keep that separate copy somewhere other than the backup drive.

One stolen drive must not contain both the archives and the credentials that open them.

Docker Desktop must be granted access to the drive holding the client directory and any backup destination.

The database is not restored by copying the client directory.

A move to a new machine always requires a backup restore into the new named volume.

No migration is required.

The upstream Compose files remain untouched, so the development setup keeps its current behaviour.

Removing the client override and returning to the existing environment returns the deployment to its prior Compose shape.

## Test and acceptance evidence

The shell files were checked with `bash -n`.

`git check-attr text eol` reports `text: set` and `eol: lf` for every new shell file.

The Linux proof uses a throwaway client directory and a separate Compose project and named volume.

The setup command creates both secret files with mode 600 and refuses to run against the existing directory on a second invocation.

The Linux proof created a shop, logged in, uploaded `proof-logo.png`, created `Proof Item`, and completed sale `POS 1` for `$2.00`.

After the app and database containers were destroyed and recreated, login still worked, the logo remained in the client `uploads/` directory, and the named database still contained the completed sale.

This explicitly proves that the client mount fixes the upstream `public/uploads` persistence defect.

The no-argument backup wrote `ospos-backup-20260920-164515.tar.gz` under the configured `backups/` directory, and its archive listing contained `uploads/proof-logo.png` but neither secret file.

A restore drill into a second client directory restored the same logo, one completed sale, and a working login.

The setup command returned status 1 and refused the existing first client directory.

Windows setup, NTFS ACL enforcement, and exFAT/FAT32 refusal behaviour are written and reviewed but remain expected, not verified, until the project owner runs them on the shop computer.

## Rollback

This change is additive.

Stop the client Compose project, remove the client override from the Compose command, and return to the previous launcher arguments to restore the old deployment shape.

Do not delete the named database volume during rollback.

The client directory remains recoverable as an operator-owned copy, but its database is only recoverable from a backup archive.

No schema migration or data rewrite is involved.

## Links and implementation notes

- [Manual backup and restore guide](../backup-and-restore.md).
- [ADR 0013: containerised backup and restore](0013-containerised-backup-windows-first.md).
- `docker-compose.client.yml`, the client-only Compose override.
- `scripts/setup-client.sh` and `scripts/setup-client.ps1`, the thin setup launchers.
- `scripts/container/setup-client.sh`, the shared setup entry point.
- `scripts/container/backup.sh`, the default destination reader and archive entry point.
- `app/Controllers/Items.php:820`, the item picture upload path.
- `app/Controllers/Config.php:342`, the company logo upload path.
