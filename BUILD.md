# Building OSPOS

## Development assets

The front-end build uses Node.js 22 and npm.
The asset build does not need PHP or Composer.
The development Docker stack runs the one-shot `assets` service before it starts the app.
The service runs `npm ci` when dependencies are missing or the package lock changed.
It then runs `npm run build` in the project checkout.
To start the development stack, set `USERID` and `GROUPID` to your host user and group IDs.
Run `docker compose -f docker-compose.dev.yml up` from the project root.
To build assets by hand, run `npm ci` and then `npm run build` from the project root.
The two GitHub-hosted npm packages use commit-pinned archive URLs, so this build does not need Git.
The build writes content-hashed files under `public/resources`.
It copies `app/Views/partial/header_assets.template.php` to the ignored `header_assets.php` file before it injects asset names.
The build does not change the tracked `app/Views/partial/header.php` file.
The `build-database` task still creates `app/Database/database.sql` for release archives.
The development database loads `tables.sql` and `constraints.sql` directly.

## License files

The default asset build does not run Composer or update license files.
The Docker image build runs `npx gulp update-licenses` with Composer and PHP in its build stage.
The final image includes the generated Composer, npm, and project license files under `public/license`.

## What the shop image contains

The shop image holds only the files the app needs to run: `app`, `public`, `writable`, `vendor`, `spark`, `preload.php`, `composer.json`, `composer.lock`, `.env-example`, `.htaccess`, `LICENSE`, and `docker/migrations.php`.
The `app-files` build stage deletes everything else, such as tests, docs, notes, CI files, build scripts, and design files.
The final image copies from that stage, so the deleted files are in none of its layers.
When you add a file or folder at the project root that the app needs at run time, check that the `app-files` stage keeps it.
Keep `LICENSE` in the image, because the MIT license needs the original copyright notice to ship with the code.
The `ospos_test` image adds `tests`, `phpunit.xml.dist`, and `gulpfile.js` back so the test suite can run.
This does not hide the PHP code, because the app needs it to run.

## Running OSPOS outside Docker

Install the PHP packages with `composer install` when you run OSPOS outside Docker.
Run `npm ci` and `npm run build` to make the front-end assets.
Some PHP packages need extensions such as `intl`, so enable the required PHP extensions in `php.ini`.
Copy a configured `.env` file into the project root before starting the app.
Use the standard installation guide if you need to create or upgrade a database.

## Windows

Run `build.ps1` for a full build and optional `.env` restore.
Run `build-steps.ps1` to build the main asset groups one step at a time.
`build-steps.ps1` skips the Bootstrap 5 copy, the module icons and the database file, so use `build.ps1` when you need a complete build.
Both scripts must run from the project root.

## Asset checks

`AssetIntegrityTest` checks the generated header partial and the files it names.
Build the assets before running that test.
If `header_assets.php` is missing, the app shows a developer notice and the test explains how to build it.
