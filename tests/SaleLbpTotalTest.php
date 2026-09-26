<?php

namespace Tests;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Covers the pound total and exchange rate saved with a sale, without a database.
 *
 * @internal
 */
final class SaleLbpTotalTest extends CIUnitTestCase
{
    /**
     * Loads the currency helper.
     */
    protected function setUp(): void
    {
        parent::setUp();

        helper('currency');
    }

    /**
     * Saves only a usable exchange rate, so a sale without one keeps both pound columns empty.
     */
    public function testOnlyAPositiveRateIsSaved(): void
    {
        $this->assertSame(90000.0, lbp_rate_to_save('90000'));
        $this->assertSame(89500.5, lbp_rate_to_save(89500.5));
        $this->assertNull(lbp_rate_to_save(null));
        $this->assertNull(lbp_rate_to_save(''));
        $this->assertNull(lbp_rate_to_save('abc'));
        $this->assertNull(lbp_rate_to_save('0'));
        $this->assertNull(lbp_rate_to_save(-1));
    }

    /**
     * Gives a return a negative pound total, so saved sales and returns net out in reports.
     */
    public function testReturnCartHasNegativePoundTotal(): void
    {
        $cart = [
            1 => ['line' => 1, 'quantity' => -2, 'price' => '1.22', 'discounted_total' => '-2.44'],
        ];

        $totals = get_lbp_cart_totals($cart, [], 90000);

        $this->assertSame(110000, $totals['lines'][1]['customer_unit_lbp']);
        $this->assertSame(-220000, $totals['total']);
    }

    /**
     * Shows a saved sale with its saved total and rate even after the rate setting changed.
     */
    public function testStoredSaleUsesSavedTotalAndRate(): void
    {
        $cart = [
            1 => ['line' => 1, 'quantity' => 1, 'price' => '4.22', 'discounted_total' => '4.22'],
        ];
        $sale_info = ['lbp_total' => '380000', 'lbp_exchange_rate' => '90000.0000'];

        $values = get_stored_sale_lbp_totals($cart, [], $sale_info, '100000');

        $this->assertSame(90000.0, $values['rate']);
        $this->assertSame(380000, $values['total']);
        $this->assertSame(380000, $values['lbp_totals']['lines'][1]['customer_unit_lbp']);
    }

    /**
     * Shows a sale from before the pound total was saved at the current rate, as before.
     */
    public function testOlderSaleUsesCurrentRate(): void
    {
        $cart = [
            1 => ['line' => 1, 'quantity' => 1, 'price' => '4.22', 'discounted_total' => '4.22'],
        ];
        $sale_info = ['lbp_total' => null, 'lbp_exchange_rate' => null];

        $values = get_stored_sale_lbp_totals($cart, [], $sale_info, '100000');

        $this->assertSame('100000', $values['rate']);
        $this->assertSame(422000, $values['total']);
        $this->assertSame(422000, get_stored_sale_lbp_totals($cart, [], null, '100000')['total']);
    }

    /**
     * Does not trust a saved total without a usable saved rate.
     */
    public function testSavedTotalWithoutRateFallsBackToCurrentRate(): void
    {
        $cart = [
            1 => ['line' => 1, 'quantity' => 1, 'price' => '1.00', 'discounted_total' => '1.00'],
        ];
        $sale_info = ['lbp_total' => '5000', 'lbp_exchange_rate' => '0'];

        $values = get_stored_sale_lbp_totals($cart, [], $sale_info, 89500);

        $this->assertSame(89500, $values['rate']);
        $this->assertSame(90000, $values['total']);
    }
}
