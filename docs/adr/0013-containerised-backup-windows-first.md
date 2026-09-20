# ADR 0013: Containerised backup and restore, Windows first

- Status: Accepted
- Date: 2026-09-20
- Decision owners: Project owner and implementation team
- Scope: Manual backup and restore for the OSPOS Lebanon fork
- Baseline: `develop` snapshot `bcc9efc7c1ecf48273f03c5c0e3b24a8aef0c350`, application version 3.4.1
- Branch: `feat/containerised-backup`

## Context and current behaviour

The shop computer is Windows.

The existing `scripts/backup.sh` and `scripts/restore.sh` were proven on Linux, but the host scripts depend on Linux tools.

The backup script uses `stat -c '%s'`, while BSD and macOS use a different `stat` syntax.

Both scripts use `sha256sum`, while macOS provides `shasum -a 256` instead.

The backup script uses `mktemp "$destination/.ospos-backup.XXXXXX.tar.gz"`, with characters after the `XXXXXX` template, which BSD `mktemp` rejects.

Windows has no Bash shell by default.

Patching those three lines would not provide a Windows operator path and would create more host-specific code to maintain.

The proven scripts also have a Docker execution mode that copies a private MySQL defaults file into the database container and runs `docker exec`.

That mode gives a backup tool access to the Docker control path, which is broader access than the tool needs.

The development `.env` uses `database.default.hostname = 'localhost'`, while the shipped Compose setup reaches the database as the `mysql` service.

An entry point inside a separate container must therefore receive its database host explicitly.

## Requirements and non-goals

The solution must:

- run the proven backup and restore logic inside a throwaway container;
- use the existing `mariadb:10.5` image;
- connect to the database over TCP using the `mysql` service name;
- never mount the Docker socket;
- preserve the non-sourcing environment parser, private defaults file, cleanup trap, atomic archive rename, manifest checksum, and restore refusals;
- reject `.env`, `app.env`, and `db.env` by basename at every archive depth in both backup input and restore archives;
- keep Linux and Windows operator launchers small and separate from the container entry points;
- keep files written by the Linux launcher owned by the current operator;
- keep the Windows path marked expected until it is tested on the shop computer.

This decision does not add automatic retention, off-machine scheduling, production restore rehearsal, hardware support, or a new application image.

## Options considered

### Patch the host scripts for each operating system

Rejected.

This would solve only some Unix differences and would not provide Bash on Windows.

### Require WSL or Git Bash on Windows

Rejected.

Docker Desktop is already required by the shop deployment, so adding another host runtime gives no benefit.

### Write a separate PowerShell backup implementation

Rejected.

Two full implementations would need to stay in step and would double the maintenance surface.

### Build a purpose-made backup image

Rejected.

`mariadb:10.5` already contains the database clients and GNU tools used by the proven scripts, and it matches the database server image.

## Decision

The proven script bodies now run as `scripts/container/backup.sh` and `scripts/container/restore.sh` inside a throwaway `mariadb:10.5` container.

The entry points connect to the database over TCP with an explicit `--db-host` option.

They do not read the `.env` hostname for the connection, because `localhost` inside the tool container is not the database service.

The four host launchers are:

- `scripts/backup.sh` and `scripts/restore.sh` for Linux;
- `scripts/backup.ps1` and `scripts/restore.ps1` for Windows PowerShell.

Each launcher mounts the repository read-only, mounts the selected archive or destination, mounts the uploads parent, and mounts the selected application environment read-only. The client-config backup path also mounts only the config file and writable configured backup destination, not the whole client directory. Each launcher joins the configured application network and passes `mysql` as the database host.

The Linux launchers pass the current `uid:gid`.

No launcher mounts `/var/run/docker.sock` or any other Docker control socket.

The application network defaults to `ospos_app_net` and can be set with `OSPOS_DOCKER_NETWORK` when the Compose project uses another network name.

The existing option names remain on the host launchers except for the removed `--db-container` option.

## Consequences and risks

The database client version now matches the MariaDB server image, so the dump is not dependent on tools installed on the host.

The host needs Docker Desktop or Docker Engine, but it does not need Bash, `mysqldump`, `mysql`, GNU `tar`, GNU `stat`, or GNU `sha256sum`.

The tool container has no Docker control access, which limits the damage if the backup process is compromised.

The Linux path was verified in this environment.

The Windows path is expected, not verified, because no Windows machine is available here.

The project owner must test both Windows launchers on the shop computer before treating them as verified.

Docker Desktop must be granted access to the external backup drive before a Windows bind mount can use it.

Most portable drives use exFAT or FAT32 and cannot store Unix file permissions.

On those file systems, the archive's mode-600 setting is silently ignored, so anyone holding the drive can read the archive.

The archive must therefore never contain `.env`, `app.env`, or `db.env`, and the guide must not present mode 600 as protection on those drives.

The repository keeps shell scripts as LF so a Windows checkout does not turn the container entry point into a CRLF script.

ADR 0011 records the Windows secret preparation and cleanup rules used by the client setup path.

## Compatibility, migration, and rollback

No database schema, application data, or Compose file changes are made.

Existing archives keep the same `database.sql`, `uploads/`, and `manifest.txt` format and continue to use the same checksum validation.

Rollback is deleting the container launcher and entry-point changes and restoring the proven host scripts from the preceding backup commit.

No migration is required.

## Test and acceptance criteria

Linux acceptance requires:

- a real archive with `database.sql`, `uploads/`, and `manifest.txt`, no `.env`, `app.env`, or `db.env` basename, application version 3.4.1, and a numeric migration version;
- a restore drill into a scratch database and uploads directory with five items, nine sales, and both employee rows restored;
- proof that the live database is unchanged after the drill;
- refusal of a restore without `--yes` on non-interactive input;
- refusal of a database-name mismatch when `--database` is absent;
- refusal of an altered `database.sql` whose checksum no longer matches the manifest;
- proof that the tool container cannot access the Docker socket;
- shell syntax and launcher argument checks.

Windows acceptance is expected until the project owner runs a backup and a restore drill on the shop computer with its real drive and Docker Desktop settings.

## Test and rollback approach

The scripts retain the existing test protections and are tested against the live MariaDB 10.5 stack without changing the live database.

The scratch database is dropped after the restore drill, and test archives are deleted from the repository workspace.

If the container path fails in production, stop the shop, keep the existing archive, and roll back this branch's launcher and entry-point changes before using the prior approved Linux scripts.

## Links and evidence

- [Manual backup and restore guide](../backup-and-restore.md), including Windows setup and Linux operation.
- [ADR 0002](0002-native-feature-configuration-and-gap-analysis.md), which routed backup and restore to Phase 6.
- [ADR 0013 draft](adr-0013-draft.md), the implementation plan kept as an ignored working draft.
- Implementation branch: `feat/containerised-backup`.
