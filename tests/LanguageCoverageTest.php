<?php

namespace Tests;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Guards Arabic translation coverage against silent regressions.
 *
 * Only English keys that hold real words are counted. Many keys ship with an
 * empty English value, and those are not translation gaps: there is nothing to
 * translate. Counting them makes a complete locale look unfinished.
 *
 * @internal
 */
final class LanguageCoverageTest extends CIUnitTestCase
{
    /**
     * Keys that are deliberately left in Latin script because they are product
     * names or brand names rather than translatable text.
     */
    private const UNTRANSLATED_BY_DESIGN = [
        'Common.php::software_short',
    ];

    private const LANGUAGE_PATH = APPPATH . 'Language';

    #[DataProvider('arabicLocaleProvider')]
    public function testArabicCoversEveryTranslatableEnglishKey(string $locale): void
    {
        $gaps = $this->findGaps($locale);

        $this->assertSame([], $gaps, "Untranslated {$locale} keys:\n" . implode("\n", $gaps));
    }

    public function testTheEnglishSourceStillHasTranslatableKeys(): void
    {
        $translatable = array_filter($this->loadLocale('en'), fn ($value) => $this->isTranslatable($value));

        $this->assertGreaterThan(1000, count($translatable));
    }

    #[DataProvider('arabicLocaleProvider')]
    public function testEveryArabicLocaleIsDeclaredRightToLeft(string $locale): void
    {
        helper('locale');

        $this->assertSame('rtl', text_direction($locale));
    }

    public function testLebaneseMonthNamesUseLevantineForms(): void
    {
        $calendar = $this->loadLocale('ar-LB');

        $this->assertSame('كانون الثاني', $calendar['Calendar.php::january']);
        $this->assertSame('تشرين الأول', $calendar['Calendar.php::october']);
    }

    public static function arabicLocaleProvider(): array
    {
        return [
            'lebanon' => ['ar-LB'],
            'egypt'   => ['ar-EG'],
        ];
    }

    /**
     * Returns the keys in the given locale that still need a translation.
     *
     * A key is a gap when it is absent, empty, or identical to the English
     * string while the English string contains real words.
     *
     * @param string $locale Locale directory name, for example `ar-LB`.
     *
     * @return list<string> Gap descriptions, empty when the locale is complete.
     */
    private function findGaps(string $locale): array
    {
        $english = $this->loadLocale('en');
        $target  = $this->loadLocale($locale);
        $gaps    = [];

        foreach ($english as $key => $englishValue) {
            if (! $this->isTranslatable($englishValue) || in_array($key, self::UNTRANSLATED_BY_DESIGN, true)) {
                continue;
            }

            $value = $target[$key] ?? null;

            if ($value === null) {
                $gaps[] = "{$key} is missing";
            } elseif ($value === '') {
                $gaps[] = "{$key} is empty";
            } elseif ($value === $englishValue) {
                $gaps[] = "{$key} is still English";
            }
        }

        return $gaps;
    }

    /**
     * Loads every language file for a locale into one flat map.
     *
     * @param string $locale Locale directory name.
     *
     * @return array<string, string> Map of `File.php::key` to its value.
     */
    private function loadLocale(string $locale): array
    {
        $entries   = [];
        $directory = self::LANGUAGE_PATH . DIRECTORY_SEPARATOR . $locale;

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $lines = require $file;

            if (! is_array($lines)) {
                continue;
            }

            foreach ($lines as $key => $value) {
                if (is_string($value)) {
                    $entries[basename($file) . '::' . $key] = $value;
                }
            }
        }

        return $entries;
    }

    /**
     * Returns true when a string contains words a translator can act on.
     *
     * Symbols, numbers, and format placeholders need no translation.
     *
     * @param string $value English source string.
     */
    private function isTranslatable(string $value): bool
    {
        return (bool) preg_match('/[A-Za-z]{2,}/', $value);
    }
}
