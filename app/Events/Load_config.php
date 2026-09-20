<?php

namespace App\Events;

use App\Libraries\MY_Migration;
use App\Models\Appconfig;
use CodeIgniter\Session\Session;
use Config\OSPOS;
use Config\Services;

/**
 * @property Appconfig appconfig;
 * @property MY_Migration migration;
 * @property Session session;
 * @property mixed $config
 * @property mixed $migration_config
 */
class Load_config
{
    public Session $session;

    /**
     * Loads configuration from database into App CI config and then applies those settings
     */
    public function load_config(): void
    {
        // Migrations
        $migration_config = config('Migrations');
        $migration        = new MY_Migration($migration_config);

        $this->session = session();

        // Database Configuration
        $config = config(OSPOS::class);

        if (! $migration->is_latest()) {
            $this->session->destroy();
        }

        // Language
        // current_language_code() prefers the logged-in employee's language and
        // falls back to the system language, so the translations that are loaded
        // match the language the employee actually selected.
        $language_code   = current_language_code();
        $language_exists = $language_code !== '' && is_dir(APPPATH . 'Language/' . $language_code);

        if (! $language_exists || current_language() === '') {
            $config->settings['language']      = DEFAULT_LANGUAGE;
            $config->settings['language_code'] = DEFAULT_LANGUAGE_CODE;
            $language_code                     = DEFAULT_LANGUAGE_CODE;
        }

        $language = Services::language();
        $language->setLocale($language_code);

        // Time Zone
        date_default_timezone_set($config->settings['timezone'] ?? ini_get('date.timezone'));

        bcscale(max(2, totals_decimals() + tax_decimals()));
    }
}
