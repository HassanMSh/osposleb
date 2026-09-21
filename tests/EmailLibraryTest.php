<?php

namespace Tests;

use App\Libraries\Email_lib;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;
use ReflectionClass;

/**
 * Covers email-library startup when no encryption key is configured.
 *
 * @internal
 */
final class EmailLibraryTest extends CIUnitTestCase
{
    private string $encryptionKey;

    /**
     * Provides email settings with a runtime-generated stored credential.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->encryptionKey      = config('Encryption')->key;
        config('Encryption')->key = '';

        $ospos           = (new ReflectionClass(OSPOS::class))->newInstanceWithoutConstructor();
        $ospos->settings = [
            'company'      => 'Test shop',
            'email'        => 'test@example.com',
            'mailpath'     => '/usr/sbin/sendmail',
            'protocol'     => 'mail',
            'smtp_crypto'  => '',
            'smtp_host'    => '',
            'smtp_pass'    => bin2hex(random_bytes(32)),
            'smtp_port'    => '25',
            'smtp_timeout' => '5',
            'smtp_user'    => '',
        ];
        Factories::injectMock('config', OSPOS::class, $ospos);
    }

    /**
     * Restores the original encryption setting.
     */
    protected function tearDown(): void
    {
        config('Encryption')->key = $this->encryptionKey;

        parent::tearDown();
    }

    /**
     * Constructs the email library without resolving an empty-key encrypter.
     */
    public function testConstructingWithEmptyKeyDoesNotThrow(): void
    {
        $this->assertInstanceOf(Email_lib::class, new Email_lib());
    }
}
