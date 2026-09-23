<?php

namespace Tests;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers URL-safe payment id encoding used by the register delete link.
 *
 * @internal
 */
final class PaymentIdHelperTest extends CIUnitTestCase
{
    /** Loads the helper under test. */
    protected function setUp(): void
    {
        parent::setUp();

        helper('sale');
    }

    /** Confirms English, Arabic, and reserved base64 bytes survive a URL-safe round trip. */
    #[DataProvider('paymentIdProvider')]
    public function testPaymentIdsRoundTripThroughUrlSafeBase64(string $paymentId): void
    {
        $encodedPaymentId = encode_payment_id($paymentId);

        $this->assertSame($paymentId, decode_payment_id($encodedPaymentId));
        $this->assertMatchesRegularExpression('/\A[a-z0-9_\-]+\z/i', $encodedPaymentId);
    }

    /** Rejects values that are not unpadded URL-safe base64. */
    public function testInvalidPaymentIdsAreRejected(): void
    {
        $this->assertFalse(decode_payment_id('!!!'));
        $this->assertFalse(decode_payment_id('a'));
    }

    /** Confirms standard base64 plus and slash characters become URL-safe characters. */
    public function testBase64PlusAndSlashAreReplacedInTheUrlSafeValue(): void
    {
        $paymentId = "\xfb\xff";

        $this->assertSame('+/8=', base64_encode($paymentId));
        $this->assertSame('-_8', encode_payment_id($paymentId));
    }

    /** Supplies representative payment ids and bytes that encode to plus and slash. */
    public static function paymentIdProvider(): array
    {
        return [
            'English cash' => ['Cash'],
            'Lebanese Arabic cash' => ['نقدي'],
            'Lebanese Arabic cash adjustment' => ['تعديل الصندوق'],
            'base64 plus and slash' => ["\xfb\xff"],
        ];
    }
}
