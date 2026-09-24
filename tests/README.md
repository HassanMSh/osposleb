# Running Application Tests

This guide explains how to install and run the OSPOS test suite.
See the linked documentation for more detail about CodeIgniter and PHPUnit.

## Resources

- [CodeIgniter 4 User Guide on Testing](https://codeigniter.com/user_guide/testing/index.html)
- [PHPUnit documentation](https://phpunit.de/documentation.html)
- [CodeIgniter 4 testing discussion](https://forum.codeigniter.com/showthread.php?tid=81830)

## Requirements

Install the PHP dependencies with `composer install`.
Install XDebug only if you need code coverage.
Enable `xdebug.mode=coverage` in `php.ini` to collect coverage.

On macOS or Linux, you can make a short link to PHPUnit.

```console
ln -s ./vendor/bin/phpunit ./phpunit
```

## Setting up

Some tests need a running database.
Set the `tests` database group in `app/Config/Database.php` or `.env` to a test database.
See the [CodeIgniter database testing guide](https://codeigniter.com/user_guide/testing/database.html) for details.

## Running the tests

Run the full test suite from the project root.

```console
./vendor/bin/phpunit --no-coverage --colors=never
```

On Windows, run `vendor\bin\phpunit` from the project root.
You can pass a test directory to run only the tests in that directory.

```console
./vendor/bin/phpunit app/Models
```

## Generating code coverage

Run PHPUnit with coverage options to create text and HTML reports.

```console
./vendor/bin/phpunit --colors --coverage-text=tests/coverage.txt --coverage-html=tests/coverage/ -d memory_limit=1024m
```

The text report is saved to `tests/coverage.txt`.
The HTML report is saved to `tests/coverage/index.html`.

## PHPUnit configuration

The root `phpunit.xml.dist` file supplies the default PHPUnit settings.
Copy it to the ignored `phpunit.xml` file if you need local settings.
You can use the local file to select tests or change report options.

## Test cases

`AssetIntegrityTest` reads all four asset blocks from the generated `header_assets.php` partial.
It fails with a build command when that generated partial is missing.
It checks that every stylesheet and script named by the partial, the default theme stylesheet, and the favicon exists under `public/`.
It checks that the production bundle keeps the right-to-left and print stylesheets.
It checks that every icon produced by the `copy-menubar` task exists.
It checks that `header.php` has no inject markers or content-hashed asset names.
Run `npm run build` from the project root before running this test.

`AssetCacheControlTest` checks the year-long cache rule against sample names and against every built file under `public/resources/`, and that the copied theme folders are left out.

Every test needs a test case class.
CodeIgniter provides the `CodeIgniter\Test\CIUnitTestCase` class for this purpose.
Create shared test setup in a custom class when several tests need the same helpers.

## Creating tests

Put all tests in the `tests/` directory.
Use test method names that start with `test` and describe the behavior being checked.
Review the linked guides and check that each test covers a clear case.

## Database tests

Database tests can use migrations, seed data, or a mock database.
Point each test case to the right seed data and migrations.
Add any setup steps to the `setUp()` method.
See the [CodeIgniter database testing guide](https://codeigniter.com/user_guide/testing/database.html) for details.
