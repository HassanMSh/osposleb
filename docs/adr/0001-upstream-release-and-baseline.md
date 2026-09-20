# ADR 0001: Upstream release and baseline

- Status: Accepted
- Date: 2026-09-20
- Decision owners: Project owner; implementation agent
- Implementation branch: `chore/ospos-baseline`
- Approved by: Project owner on 2026-09-20

## Context

Phase 0 selected the OSPOS 3.4.1 line for the Lebanon supermarket and
fast-food fork. The project owner approved the exact `develop` snapshot
`bcc9efc7c1ecf48273f03c5c0e3b24a8aef0c350` on 2026-09-20. The application
reports version 3.4.1 in `app/Config/App.php` and `package.json`.

The repository is the existing local fork at
`/home/dev-hassanshd/hassan/pos/osposleb`. Project documentation is kept in
the repository at `osposleb/docs/`, including
`docs/OSPOS_IMPLEMENTATION_PLAN.md`. The local ref named `3.4.1` points to
`5f395d987b02562092b838073cb2e23a22d2bca4`; this is different from the
approved `develop` snapshot. This ADR therefore treats the approved immutable
snapshot as the working baseline and does not assert an unverified tag
ancestry relationship.

The unchanged repository describes OSPOS as a PHP web application using
MySQL or MariaDB. Relevant native behavior is visible in the source:

- `app/Config/App.php` sets application version 3.4.1 and lists Arabic
  locales (`ar-EG` and `ar-LB`) among supported locales.
- `app/Config/Database.php` uses the MySQLi driver, `utf8mb4`, and a default
  `ospos` database with the `ospos_` table prefix.
- `app/Controllers/Taxes.php`, `app/Models/Tax.php`,
  `app/Models/Item_taxes.php`, and `app/Controllers/Config.php` provide
  tax configuration and item-tax paths.
- `app/Controllers/Item_kits.php`, `app/Models/Item_kit.php`, and
  `app/Models/Item_kit_items.php` provide native item-kit behavior.
- `app/Controllers/Sales.php`, `app/Controllers/Receivings.php`, and
  `app/Libraries/Barcode_lib.php` provide sales, receiving, and barcode
  paths.
- `app/Views/sales/receipt.php` and `app/Views/receivings/receipt.php`
  provide receipt views; printer and cash-drawer behavior still requires
  environment and device validation.
- `app/Database/Migrations/` contains versioned database migrations, so
  database changes must be isolated and reversible.

## Requirements and non-goals

Requirements for this baseline are:

- Keep the approved snapshot reproducible and preserve existing sales and
  historical data.
- Build and test the unmodified application using the repository workflow.
- Preserve the OSPOS license and mandatory footer signature.
- Use the upstream-supported PHP/MySQL-compatible deployment model while the
  later phases audit Arabic/RTL, TVA, hardware, receipts, fast-food, and
  production requirements.

This ADR does not implement Arabic translations, RTL changes, TVA changes,
hardware integrations, receipt redesign, fast-food workflow changes, schema
migrations, or production hardening. It also does not choose a new upstream
release or change the database engine.

## Options considered

1. Use the approved `develop` snapshot as the fork baseline.
2. Reset the fork to the local `3.4.1` tag.
3. Move to a newer upstream development or prerelease snapshot.

## Decision

Use `bcc9efc7c1ecf48273f03c5c0e3b24a8aef0c350` as the immutable application
baseline on `chore/ospos-baseline`. The project owner approved this choice on
2026-09-20. Keep project documentation under `osposleb/docs/` because the
user moved `docs/` inside the repository. Do not replace the approved snapshot
with the local `3.4.1` tag or a newer development snapshot.

This is the safest handoff because it is the exact reviewed `develop` state,
has the expected 3.4.1 application version, and avoids introducing release or
upgrade changes before the native capability audit. Later work must first
configure native behavior, then add only the smallest accepted extensions.

## Runtime, database, and build requirements

Repository evidence in `INSTALL.md`, `BUILD.md`, `composer.json`,
`package.json`, and the Docker files records:

- PHP 8.1 through 8.4 are supported. Required PHP extensions include
  `json`, `gd`, `bcmath`, `intl`, `openssl`, `mbstring`, `curl`, and `xml`.
- MySQL 5.7 is supported; MariaDB 10.x is supported as a compatible option.
- Apache 2.4 is supported; Nginx is documented as an alternative.
- Composer and npm are required for a source build. The documented workflow
  is `composer install`, `npm install`, then `npm run build`.
- `composer.json` requires PHP `^8.1` and CodeIgniter 4.6.0. PHPUnit is
  available through the development dependencies, with `composer test` as
  the test command.
- `BUILD.md` records npm 9.4.2 and Composer 2.5.1 as tested tool versions;
  these are build evidence, not a new hard pin for the fork.
- Docker development files use MariaDB 10.5 and must not be treated as a
  production deployment without later hardening.

## Licensing and compatibility

The repository is MIT-licensed with additional OSPOS conditions in `LICENSE`
and `README.md`: copyright and license notices must remain, the required
footer signature with version/hash/URL must remain visible and unmodified,
and the software is provided without warranty. Future changes must preserve
these conditions and must not commit secrets, databases, backups, or customer
data.

The baseline preserves the current PHP/CodeIgniter/MySQL-compatible stack,
existing migrations, locale configuration, permissions, reports, receipts,
and item-kit models. No schema or runtime behavior is changed by this ADR.

## Data, migration, rollback, and operational impact

No data migration is required. No database file or connection setting is
changed. A rollback is a source rollback to the approved snapshot
`bcc9efc7c1ecf48273f03c5c0e3b24a8aef0c350`; database rollback is not needed
for this ADR. Later schema work must use a backup copy, a versioned migration,
and a tested down/restore path before production use.

The baseline build may create ordinary generated build output or dependency
directories. Such output must not be treated as application changes or
committed as part of this ADR. No `.env` file, database, generated dependency,
or production data is changed here.

Security and privacy remain at upstream baseline behavior. No new endpoint,
permission, data collection, or network service is introduced. Performance,
localization, RTL, printer, scanner, cash drawer, and browser behavior remain
to be measured in the Phase 1 native capability audit. Hardware compatibility
is unverified until a real device is tested.

## Test and acceptance plan

Acceptance requires:

1. Confirm the working snapshot and reported application version using local
   repository evidence.
2. Run the supported source build as far as the available PHP, Composer, npm,
   and database environment allows, without changing `.env` or a database.
3. Run the available baseline automated tests (`composer test` or the
   repository's safe equivalent) and record exact results.
4. Run PHP syntax/configuration checks where available.
5. If a service, dependency, or hardware device is unavailable, record the
   exact command and blocker instead of disguising it as a passing test.
6. Confirm no application code, schema, environment, database, or generated
   dependency was intentionally modified by the baseline work.

The verification results are appended to this ADR after the checks run.

## Consequences, risks, and follow-up

Positive consequences:

- The project has one reproducible, approved starting point.
- Existing OSPOS behavior remains the reference for later gap analysis.
- The documentation layout and rollback target are explicit.

Risks and limitations:

- The approved `develop` snapshot is not the same local ref as tag `3.4.1`;
  tag ancestry was not asserted in this ADR.
- Build or application startup may be blocked by missing local services or
  dependencies.
- Arabic/RTL, TVA semantics, printers, scanners, drawers, and kitchen routing
  are not validated by this baseline ADR.

Follow-up work is Phase 1's native capability audit and gap analysis. ADR 0002
must document native configuration and the disposition of each project
requirement before custom feature work begins.

## References

- [Implementation plan](../OSPOS_IMPLEMENTATION_PLAN.md)
- [Installation requirements](../../INSTALL.md)
- [Build workflow](../../BUILD.md)
- [Repository README](../../README.md)
- [Composer manifest](../../composer.json)
- [NPM manifest](../../package.json)
- [OSPOS upstream repository](https://github.com/opensourcepos/opensourcepos)
- [OSPOS upstream releases](https://github.com/opensourcepos/opensourcepos/releases)
- [CodeIgniter 4 documentation](https://codeigniter.com/user_guide/)

## Verification results

Verification was run on 2026-09-20 against the unchanged application code:

- Baseline evidence: `.git/HEAD` points to `refs/heads/chore/ospos-baseline`,
  whose local ref contains
  `bcc9efc7c1ecf48273f03c5c0e3b24a8aef0c350`. `app/Config/App.php` and
  `package.json` both report version 3.4.1.
- `npm run build` — blocked, exit 127: `gulp: not found`. `node_modules/`
  is absent. No dependency installation was attempted.
- `composer test` — blocked, exit 1: Composer is not installed. `vendor/`
  is absent, and PHP is not installed.
- `docker compose -f docker-compose.yml config --quiet` — passed, exit 0.
- `docker compose -f docker-compose.dev.yml config --quiet` — passed, exit 0;
  Docker reported unset `USERID` and `GROUPID` variables only.
- `docker compose -f docker-compose.nginx.yml config --quiet` — passed, exit
  0; Docker reported unset environment variables and the existing obsolete
  `version` key warning.
- `docker compose -f docker-compose.test.yml config --quiet` — failed, exit
  1: the existing test file references an undefined `sqlscript` service. It
  also reports the existing obsolete `version` key warning.
- `node --check` passed for `tests/sanity_check.js`,
  `tests/giftcard_numbering.js`, `tests/receiving_quantity.js`,
  `tests/make_sale_receiving.js`, and `tests/ospos.js`.

No application code, `.env` file, database, generated dependency directory,
or production data was changed. PHP syntax, PHPUnit, application startup,
login, database-backed sales, and hardware tests remain unverified because
the required runtime, dependencies, database, browser, and devices are not
available in this environment. No container was built or started.
