<?php

namespace Tests;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Guards saved colour themes, their entry points, and screen-only styling.
 *
 * @internal
 */
final class ColourThemeTest extends CIUnitTestCase
{
    /**
     * Checks the early theme script and the picker are included only in the signed-in page header.
     */
    public function testHeaderLoadsThemePartialsBeforeStylesAndLoginStaysDefault(): void
    {
        $header = file_get_contents(APPPATH . 'Views/partial/header.php');
        $login  = file_get_contents(APPPATH . 'Views/login.php');

        $this->assertIsString($header);
        $this->assertIsString($login);
        $themeInitPosition  = strpos($header, "view('partial/theme_init')");
        $stylesheetPosition = strpos($header, '<link rel="stylesheet"');

        $this->assertNotFalse($themeInitPosition);
        $this->assertNotFalse($stylesheetPosition);
        $this->assertLessThan($stylesheetPosition, $themeInitPosition);
        $this->assertStringContainsString("view('partial/theme_switch')", $header);
        $this->assertStringNotContainsString("view('partial/header')", $login);
        $this->assertStringNotContainsString('theme_init', $login);
        $this->assertStringNotContainsString('theme_switch', $login);
    }

    /**
     * Checks that stored themes are limited to the supported values and storage errors are caught.
     */
    public function testThemePartialsValidateValuesAndCatchBlockedStorage(): void
    {
        $themeInit   = file_get_contents(APPPATH . 'Views/partial/theme_init.php');
        $themeSwitch = file_get_contents(APPPATH . 'Views/partial/theme_switch.php');

        $this->assertIsString($themeInit);
        $this->assertIsString($themeSwitch);
        $this->assertStringContainsString("['dark', 'tech', 'earthy']", $themeInit);
        $this->assertStringContainsString('window.localStorage.getItem', $themeInit);
        $this->assertStringContainsString('try {', $themeInit);
        $this->assertStringContainsString('catch (error)', $themeInit);
        $this->assertStringNotContainsString("'default'", $themeInit);
        $this->assertStringContainsString('window.localStorage.setItem', $themeSwitch);
        $this->assertStringContainsString('window.localStorage.removeItem', $themeSwitch);
        $this->assertStringContainsString('try {', $themeSwitch);
        $this->assertStringContainsString('catch (error)', $themeSwitch);
        $this->assertStringContainsString('if (!themeSelect) {', $themeSwitch);

        foreach (['default', 'dark', 'tech', 'earthy'] as $theme) {
            $this->assertStringContainsString('value="' . ($theme === 'default' ? '' : $theme) . '"', $themeSwitch);
        }

        foreach (['theme', 'theme_default', 'theme_dark', 'theme_tech', 'theme_earthy'] as $key) {
            $this->assertStringContainsString("esc(lang('Common.{$key}'))", $themeSwitch);
        }
    }

    /**
     * Checks all supported languages and keeps every theme rule scoped to an active theme attribute.
     */
    public function testThemeTranslationsAndStylesAreScoped(): void
    {
        $english  = require APPPATH . 'Language/en/Common.php';
        $lebanese = require APPPATH . 'Language/ar-LB/Common.php';
        $egyptian = require APPPATH . 'Language/ar-EG/Common.php';
        $themeCss = file_get_contents(ROOTPATH . 'public/css/ospos_themes.css');

        $this->assertIsString($themeCss);

        foreach ([':hover', ':focus', ':active', ':disabled', '.disabled'] as $state) {
            $this->assertStringContainsString($state, $themeCss);
        }

        foreach ([$english, $lebanese, $egyptian] as $translations) {
            foreach (['theme', 'theme_default', 'theme_dark', 'theme_tech', 'theme_earthy'] as $key) {
                $this->assertArrayHasKey($key, $translations);
                $this->assertNotSame('', $translations[$key]);
            }
        }

        $this->assertSame('سمة الألوان', $lebanese['theme']);
        $this->assertSame('ترابي', $lebanese['theme_earthy']);
        $this->assertSame('سمة الألوان', $egyptian['theme']);
        $this->assertSame('ترابي', $egyptian['theme_earthy']);

        preg_match_all('/(?:^|})\s*([^{}]+?)\s*\{/s', $themeCss, $ruleMatches);
        $checkedRules = 0;

        foreach ($ruleMatches[1] as $selectorBlock) {
            $selectorBlock = trim($selectorBlock);

            if (str_starts_with($selectorBlock, '@')) {
                continue;
            }

            foreach (explode(',', $selectorBlock) as $selector) {
                $selector = trim($selector);
                $this->assertStringStartsWith('html[data-ospos-theme=', $selector);
                $checkedRules++;
            }
        }

        $this->assertGreaterThan(50, $checkedRules);
    }

    /**
     * Checks that Dark mode protects documents and cashier popups from unreadable inherited colours.
     */
    public function testDarkThemeKeepsDocumentsAndCashierPopupsReadable(): void
    {
        $themeCss = file_get_contents(ROOTPATH . 'public/css/ospos_themes.css');

        $this->assertIsString($themeCss);

        foreach (['#receipt_wrapper', '#page-wrap', '.kitchen-ticket'] as $documentContainer) {
            $this->assertStringContainsString('html[data-ospos-theme="dark"] ' . $documentContainer, $themeCss);
        }

        foreach (['.ui-autocomplete', '.datepicker', '.daterangepicker', '.bootstrap-select .dropdown-menu', '.popover', '.tooltip-inner', '#payment_details'] as $cashierComponent) {
            $this->assertStringContainsString('html[data-ospos-theme="dark"] ' . $cashierComponent, $themeCss);
        }

        foreach (['html[data-ospos-theme="tech"] .ui-autocomplete', 'html[data-ospos-theme="earthy"] .ui-autocomplete'] as $themedAutocomplete) {
            $this->assertStringContainsString($themedAutocomplete, $themeCss);
        }
    }

    /**
     * Checks that the theme stylesheet is part of both debug and production asset builds.
     */
    public function testThemeStylesheetIsIncludedInBothAssetLists(): void
    {
        $gulpfile = file_get_contents(ROOTPATH . 'gulpfile.js');

        $this->assertIsString($gulpfile);
        $this->assertSame(1, preg_match("~gulp\\.task\\('debug-css'.*?gulp\\.task\\('prod-css'~s", $gulpfile, $debugTask));
        $this->assertSame(1, preg_match("~gulp\\.task\\('prod-css'.*?gulp\\.task\\('copy-fonts'~s", $gulpfile, $productionTask));
        $this->assertStringContainsString('./public/css/ospos_themes.css', $debugTask[0]);
        $this->assertStringContainsString('./public/css/ospos_themes.css', $productionTask[0]);
    }
}
