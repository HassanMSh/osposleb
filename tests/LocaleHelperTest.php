<?php

namespace Tests;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers the writing-direction helpers in app/Helpers/locale_helper.php.
 *
 * These decide the `dir` attribute on every page, so a regression here silently
 * flips the whole interface for one group of users.
 *
 * @internal
 */
final class LocaleHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper('locale');
    }

    #[DataProvider('rightToLeftLanguageProvider')]
    public function testRightToLeftLanguagesReportRtl(string $languageCode): void
    {
        $this->assertSame('rtl', text_direction($languageCode));
        $this->assertTrue(is_right_to_left($languageCode));
    }

    #[DataProvider('leftToRightLanguageProvider')]
    public function testLeftToRightLanguagesReportLtr(string $languageCode): void
    {
        $this->assertSame('ltr', text_direction($languageCode));
        $this->assertFalse(is_right_to_left($languageCode));
    }

    public function testRegionalVariantsFollowTheirBaseLanguage(): void
    {
        $this->assertSame('rtl', text_direction('ar-LB'));
        $this->assertSame('rtl', text_direction('ar_EG'));
        $this->assertSame('ltr', text_direction('en-GB'));
    }

    public function testLanguageCodeCaseIsIgnored(): void
    {
        $this->assertSame('rtl', text_direction('AR-lb'));
        $this->assertSame('ltr', text_direction('EN'));
    }

    public function testUnknownLanguageFallsBackToLeftToRight(): void
    {
        $this->assertSame('ltr', text_direction('zz'));
        $this->assertSame('ltr', text_direction(''));
    }

    public static function rightToLeftLanguageProvider(): array
    {
        return [
            'arabic'         => ['ar'],
            'arabic lebanon' => ['ar-LB'],
            'arabic egypt'   => ['ar-EG'],
            'kurdish sorani' => ['ckb'],
            'persian'        => ['fa'],
            'hebrew'         => ['he'],
            'urdu'           => ['ur'],
        ];
    }

    public static function leftToRightLanguageProvider(): array
    {
        return [
            'english'    => ['en'],
            'english gb' => ['en-GB'],
            'french'     => ['fr'],
            'german'     => ['de-DE'],
        ];
    }
}
