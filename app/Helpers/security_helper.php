<?php

use CodeIgniter\Encryption\Encryption;
use Config\Services;

/**
 * Ensures that the application has a usable encryption key and persists a generated key when needed.
 *
 * @return bool True when the current key is usable or was saved successfully.
 */
function check_encryption(): bool
{
    $encryption = config('Encryption');
    $old_key    = $encryption->key;

    if ((empty($old_key)) || (strlen($old_key) < 64)) {
        $key           = bin2hex((new Encryption())->createKey());
        $config_path   = ROOTPATH . '.env';
        $backup_path   = WRITEPATH . '/backup/.env.bak';
        $backup_folder = WRITEPATH . '/backup';

        if (! file_exists($config_path) && ! @copy(ROOTPATH . '.env-example', $config_path)) {
            log_message('error', "Unable to create {$config_path} from .env-example");

            return false;
        }

        if (! is_file($config_path) || ! is_readable($config_path)) {
            log_message('error', "Unable to read {$config_path} for updating");

            return false;
        }

        if (! is_writable($config_path)) {
            log_message('error', "Unable to write {$config_path} for updating");

            return false;
        }

        $permissions = @fileperms($config_path);
        if ($permissions !== false && ($permissions & 0222) === 0) {
            log_message('error', "Unable to write {$config_path} for updating");

            return false;
        }

        if (! file_exists($backup_folder) && ! @mkdir($backup_folder, 0775, true)) {
            log_message('error', 'Could not create backup folder');

            return false;
        }

        if (! @copy($config_path, $backup_path)) {
            log_message('error', "Unable to copy {$config_path} to {$backup_path}");

            return false;
        }
        @chmod($backup_path, 0660);

        $config_file = @file_get_contents($config_path);
        if ($config_file === false) {
            log_message('error', "Unable to read {$config_path} for updating");

            return false;
        }

        $encryption_line = '/^([ \t]*encryption\.key[ \t]*=[ \t]*)[^\r\n]*$/mi';
        if (preg_match($encryption_line, $config_file) === 1) {
            $config_file = preg_replace($encryption_line, "$1'{$key}'", $config_file, 1);
        } else {
            $config_file = rtrim($config_file, "\r\n");
            $config_file .= ($config_file === '' ? '' : PHP_EOL) . "encryption.key = '{$key}'" . PHP_EOL;
        }

        if ($config_file === null) {
            log_message('error', "Unable to update encryption.key in {$config_path}");

            return false;
        }

        if (! empty($old_key)) {
            $old_line    = "# encryption.key = '{$old_key}' REMOVE IF UNNEEDED" . PHP_EOL;
            $config_file = preg_replace($encryption_line, $old_line . '$0', $config_file, 1);
        }

        if ($config_file === null || @file_put_contents($config_path, $config_file, LOCK_EX) !== strlen($config_file)) {
            log_message('error', "Unable to write to {$config_path} for updating.");

            return false;
        }
        @chmod($config_path, 0660);

        $encryption->key = $key;
        log_message('info', "File {$config_path} has been updated.");
    }

    return true;
}

function abort_encryption_conversion(): void
{
    $config_path = ROOTPATH . '.env';
    $backup_path = WRITEPATH . '/backup/.env.bak';

    $config_file = file_get_contents($backup_path);

    $handle = @fopen($config_path, 'w+b');

    if (empty($handle)) {
        log_message('error', "Unable to open {$config_path} to undo encryption conversion");
    } else {
        @chmod($config_path, 0660);
        $write_failed = ! fwrite($handle, $config_file);
        fclose($handle);

        if ($write_failed) {
            log_message('error', "Unable to write to {$config_path} to undo encryption conversion.");

            return;
        }
        log_message('info', "File {$config_path} has been updated to undo encryption conversion");
    }
}

function remove_backup(): void
{
    $backup_path = WRITEPATH . '/backup/.env.bak';
    if (! file_exists($backup_path)) {
        return;
    }
    if (! unlink($backup_path)) {
        log_message('error', "Unable to remove {$backup_path}.");

        return;
    }
    log_message('info', "File {$backup_path} has been removed");
}

function purifyHtml($data)
{
    if (is_array($data)) {
        return array_map('purifyHtml', $data);
    }
    if (is_string($data)) {
        return Services::HtmlPurifier()->purify($data);
    }

    return $data;
}
