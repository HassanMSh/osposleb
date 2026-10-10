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
     * Checks that the shared support line links the brand and wraps the phone number as left-to-right text.
     */
    public function testSupportLineLinksBrandAndWrapsPhoneNumber(): void
    {
        $supportLine = file_get_contents(APPPATH . 'Views/partial/support_line.php');

        $this->assertIsString($supportLine);
        $this->assertStringContainsString(
            'href="https://hassanshamseddine.qzz.io/" target="_blank" rel="noopener noreferrer"',
            $supportLine,
        );
        $this->assertStringContainsString('<bdi dir="ltr">+96171881267</bdi>', $supportLine);
    }

    /**
     * Checks that the app footer and the login page footer both show the shared support line.
     */
    public function testAppAndLoginFootersShowSupportLine(): void
    {
        foreach (['Views/partial/footer.php', 'Views/login.php'] as $view) {
            $contents = file_get_contents(APPPATH . $view);

            $this->assertIsString($contents);
            $this->assertStringContainsString("<?= view('partial/support_line') ?>", $contents, $view);
        }
    }
}
