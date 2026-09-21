<?php

namespace Tests;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Covers encryption-key repair for development and read-only deployments.
 *
 * @internal
 */
final class SecurityHelperTest extends CIUnitTestCase
{
    private string $configPath;
    private string $backupPath;
    private bool $configExisted;
    private bool $backupExisted;
    private ?string $configContents;
    private ?string $backupContents;
    private ?int $configMode;
    private ?int $backupMode;
    private string $encryptionKey;

    /**
     * Saves the environment files and loads the security helper.
     */
    protected function setUp(): void
    {
        parent::setUp();

        helper('security');

        $this->configPath     = ROOTPATH . '.env';
        $this->backupPath     = WRITEPATH . '/backup/.env.bak';
        $this->configExisted  = file_exists($this->configPath);
        $this->backupExisted  = file_exists($this->backupPath);
        $this->configContents = $this->configExisted ? file_get_contents($this->configPath) : null;
        $this->backupContents = $this->backupExisted ? file_get_contents($this->backupPath) : null;
        $this->configMode     = $this->configExisted ? fileperms($this->configPath) & 0777 : null;
        $this->backupMode     = $this->backupExisted ? fileperms($this->backupPath) & 0777 : null;
        $this->encryptionKey  = config('Encryption')->key;

        if ($this->configExisted) {
            unlink($this->configPath);
        }

        config('Encryption')->key = '';
    }

    /**
     * Restores the environment files and the original encryption setting.
     */
    protected function tearDown(): void
    {
        if (file_exists($this->configPath)) {
            unlink($this->configPath);
        }

        if ($this->configExisted) {
            file_put_contents($this->configPath, $this->configContents);
            chmod($this->configPath, $this->configMode);
        }

        if (file_exists($this->backupPath)) {
            unlink($this->backupPath);
        }

        if ($this->backupExisted) {
            file_put_contents($this->backupPath, $this->backupContents);
            chmod($this->backupPath, $this->backupMode);
        }

        config('Encryption')->key = $this->encryptionKey;

        parent::tearDown();
    }

    /**
     * Creates .env from the tracked example when the file is missing.
     */
    public function testMissingEnvFileIsCreatedWithGeneratedKey(): void
    {
        $this->assertFileDoesNotExist($this->configPath);
        $this->assertTrue(check_encryption());
        $this->assertGeneratedKeyWasWritten();
    }

    /**
     * Replaces an empty quoted encryption key line.
     */
    public function testQuotedKeyLineIsReplaced(): void
    {
        file_put_contents($this->configPath, "encryption.key = ''\n");

        $this->assertTrue(check_encryption());
        $this->assertGeneratedKeyWasWritten();
    }

    /**
     * Replaces an unquoted encryption key line.
     */
    public function testUnquotedKeyLineIsReplaced(): void
    {
        $oldKey = bin2hex(random_bytes(32));
        file_put_contents($this->configPath, "encryption.key = {$oldKey}\n");

        $this->assertTrue(check_encryption());
        $this->assertGeneratedKeyWasWritten();
        $this->assertStringNotContainsString($oldKey, file_get_contents($this->configPath));
    }

    /**
     * Keeps a short runtime key as a comment while writing a new valid key.
     */
    public function testShortRuntimeKeyIsCommentedWhenReplaced(): void
    {
        $oldKey                   = bin2hex(random_bytes(8));
        config('Encryption')->key = $oldKey;
        file_put_contents($this->configPath, "encryption.key = '{$oldKey}'\n");

        $this->assertTrue(check_encryption());

        $contents = file_get_contents($this->configPath);

        $this->assertStringContainsString("# encryption.key = '{$oldKey}' REMOVE IF UNNEEDED", $contents);
        $this->assertMatchesRegularExpression("/^\\s*encryption\\.key\\s*=\\s*'([0-9a-f]{64})'\\s*$/mi", $contents);
    }

    /**
     * Appends an encryption key line when the environment has no such setting.
     */
    public function testMissingKeyLineIsAppended(): void
    {
        file_put_contents($this->configPath, "CI_ENVIRONMENT = production\n");

        $this->assertTrue(check_encryption());
        $this->assertGeneratedKeyWasWritten();
        $this->assertStringContainsString('CI_ENVIRONMENT = production', file_get_contents($this->configPath));
    }

    /**
     * Returns false without changing a file that cannot be written.
     */
    public function testUnwritableEnvFileReturnsFalse(): void
    {
        file_put_contents($this->configPath, "encryption.key = ''\n");
        chmod($this->configPath, 0444);

        $this->assertFalse(check_encryption());
        $this->assertSame("encryption.key = ''\n", file_get_contents($this->configPath));
    }

    /**
     * Confirms that a generated key is hexadecimal and has the required length.
     */
    private function assertGeneratedKeyWasWritten(): void
    {
        $contents = file_get_contents($this->configPath);

        $this->assertIsString($contents);
        $this->assertMatchesRegularExpression("/^[ \t]*encryption\\.key[ \t]*=[ \t]*'([0-9a-f]{64})'[ \t]*$/mi", $contents);
        $this->assertSame(0660, fileperms($this->configPath) & 0777);
        $this->assertSame(0660, fileperms($this->backupPath) & 0777);
    }
}
