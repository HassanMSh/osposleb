<?php

declare(strict_types=1);

use CodeIgniter\Boot;
use CodeIgniter\Config\DotEnv;
use Config\Paths;

$projectRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require $projectRoot . 'app/Config/Paths.php';
$paths = new Paths();

define('APPPATH', realpath($paths->appDirectory) . DIRECTORY_SEPARATOR);
define('ROOTPATH', realpath($projectRoot) . DIRECTORY_SEPARATOR);
define('SYSTEMPATH', realpath($paths->systemDirectory) . DIRECTORY_SEPARATOR);
define('WRITEPATH', realpath($paths->writableDirectory) . DIRECTORY_SEPARATOR);
define('TESTPATH', realpath($paths->testsDirectory) . DIRECTORY_SEPARATOR);
define('FCPATH', realpath($projectRoot . 'public') . DIRECTORY_SEPARATOR);
define('CIPATH', realpath(SYSTEMPATH . '../') . DIRECTORY_SEPARATOR);
define('VENDORPATH', realpath(ROOTPATH . 'vendor') . DIRECTORY_SEPARATOR);
define('COMPOSER_PATH', VENDORPATH . 'autoload.php');

require SYSTEMPATH . 'Config/DotEnv.php';
(new DotEnv($projectRoot))->load();

$environment = $_ENV['CI_ENVIRONMENT'] ?? $_SERVER['CI_ENVIRONMENT'] ?? getenv('CI_ENVIRONMENT');
define('ENVIRONMENT', $environment ?: 'production');

require SYSTEMPATH . 'Boot.php';
Boot::bootTest($paths);

// Production connections return false on SQL errors instead of throwing, which would let a
// migration with a failed statement be recorded as applied. Throw on every database error here.
$databaseConfig = config('Database');

$databaseConfig->{$databaseConfig->defaultGroup}['DBDebug'] = true;

$app = service('codeigniter');
$app->initialize();
$app->setContext('php-cli');

$action = $argv[1] ?? '';

if ($action === 'check') {
    try {
        $database   = db_connect();
        $connection = $database->query('SELECT 1');

        if ($connection === false) {
            exit(1);
        }

        $itemsTable = $database->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['ospos_items'],
        );

        if ($itemsTable === false) {
            exit(1);
        }

        if ($itemsTable->getNumRows() === 0) {
            fwrite(STDERR, "The database is empty or has no OSPOS items table; seed it or restore a backup.\n");

            exit(2);
        }
    } catch (Throwable $exception) {
        exit(1);
    }

    exit(0);
}

if ($action !== 'migrate') {
    fwrite(STDERR, "Use 'check' or 'migrate'.\n");

    exit(64);
}

try {
    $migrations = service('migrations');
    $migrations->setSilent(false);
    $migrations->clearCliMessages();

    if (! $migrations->latest()) {
        throw new RuntimeException('The migration runner reported a failure.');
    }

    if ($migrations->getCliMessages() === []) {
        echo "No pending migrations.\n";
    } else {
        echo "Migrations complete.\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . "\n");

    exit(1);
}
