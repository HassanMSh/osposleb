<?php

namespace Tests;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Keeps the footer signature and support line text stable.
 *
 * @internal
 */
final class FooterSignatureTest extends CIUnitTestCase
{
    /**
     * Checks the OSPOS link label in supported locales and support text in English and Lebanese Arabic.
     */
    public function testEnglishAndArabicFooterTranslations(): void
    {
        $english  = require APPPATH . 'Language/en/Common.php';
        $lebanese = require APPPATH . 'Language/ar-LB/Common.php';
        $egyptian = require APPPATH . 'Language/ar-EG/Common.php';

        $this->assertSame('opensourcepos.org', $english['website']);
        $this->assertSame('opensourcepos.org', $lebanese['website']);
        $this->assertSame('opensourcepos.org', $egyptian['website']);

        foreach ([$english, $lebanese] as $translations) {
            $this->assertArrayHasKey('managed_by', $translations);
            $this->assertArrayHasKey('support_contact', $translations);
        }

        $this->assertSame(
            'Managed by Shamseddine Tech · For inquiries and support: +96171881267',
            $english['managed_by'] . ' Shamseddine Tech · ' . $english['support_contact'] . ' +96171881267',
        );
        $this->assertSame(
            'إدارة Shamseddine Tech · للاستفسارات والدعم: +96171881267',
            $lebanese['managed_by'] . ' Shamseddine Tech · ' . $lebanese['support_contact'] . ' +96171881267',
        );
    }

    /**
     * Checks that the footer links the brand and wraps the phone number as left-to-right text.
     */
    public function testFooterLinksBrandAndWrapsPhoneNumber(): void
    {
        $footer = file_get_contents(APPPATH . 'Views/partial/footer.php');

        $this->assertIsString($footer);
        $this->assertStringContainsString(
            'href="https://hassanshamseddine.qzz.io/" target="_blank" rel="noopener noreferrer"',
            $footer,
        );
        $this->assertStringContainsString('<bdi dir="ltr">+96171881267</bdi>', $footer);
    }
}
