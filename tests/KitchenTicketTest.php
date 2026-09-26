<?php

namespace Tests;

use App\Libraries\Till_layout;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Covers kitchen ticket output and the restaurant-only receipt hook.
 *
 * @internal
 */
final class KitchenTicketTest extends CIUnitTestCase
{
    /**
     * Lists cart lines by their line number and omits all price and payment data.
     */
    public function testTicketUsesLineOrderAndShowsOnlyKitchenDetails(): void
    {
        $settings_cache    = cache();
        $previous_settings = $settings_cache->get('settings');
        $settings_cache->save('settings', encode_array([
            'number_locale'       => 'en-US',
            'quantity_decimals'   => 0,
            'thousands_separator' => ',',
            'currency_symbol'     => '$',
        ]));

        try {
            $html = view('sales/kitchen_ticket', [
                'cart' => [
                    'third'  => ['line' => 3, 'quantity' => '1.000', 'name' => 'Burger', 'price' => 12.34, 'total' => 12.34],
                    'first'  => ['line' => 1, 'quantity' => '1.000', 'name' => 'Burger', 'price' => 12.34, 'total' => 12.34],
                    'second' => ['line' => 2, 'quantity' => '2.000', 'name' => 'Cola', 'price' => 2.50, 'total' => 5.00],
                ],
                'comments'         => 'No onions',
                'language_code'    => 'en',
                'sale_id_num'      => 123,
                'transaction_time' => '2026-09-26 12:00:00',
            ]);
        } finally {
            if ($previous_settings === false) {
                $settings_cache->delete('settings');
            } else {
                $settings_cache->save('settings', $previous_settings);
            }
        }

        preg_match_all(
            '/<div class="kitchen-ticket-line">\s*<span class="kitchen-ticket-qty" dir="ltr">(.*?)<\/span>\s*<span class="kitchen-ticket-name">(.*?)<\/span>\s*<\/div>/s',
            $html,
            $matches,
        );

        $this->assertSame(['1', '2', '1'], $matches[1]);
        $this->assertSame(['Burger', 'Cola', 'Burger'], $matches[2]);
        $this->assertStringContainsString('No onions', $html);
        $this->assertStringContainsString('123', $html);
        $this->assertStringContainsString('2026-09-26 12:00:00', $html);
        $this->assertStringNotContainsString('12.34', $html);
        $this->assertStringNotContainsString('2.50', $html);
        $this->assertStringNotContainsString('5.00', $html);
        $this->assertStringNotContainsString('LL', $html);
        $this->assertStringNotContainsString('TVA', $html);
        $this->assertStringNotContainsString('$', $html);
    }

    /**
     * Checks that the receipt calls the ticket view only for a restaurant layout.
     */
    public function testReceiptHookIsLimitedToRestaurantLayout(): void
    {
        $receipt_view = file_get_contents(APPPATH . 'Views/sales/receipt.php');

        $this->assertIsString($receipt_view);
        $this->assertStringContainsString("Till_layout::get_layout(\$config) === 'restaurant'", $receipt_view);
        $this->assertStringContainsString("view('sales/kitchen_ticket'", $receipt_view);
        $this->assertSame('shop', Till_layout::get_layout([]));
        $this->assertSame('shop', Till_layout::get_layout(['till_layout' => 'shop']));
        $this->assertSame('restaurant', Till_layout::get_layout(['till_layout' => 'restaurant']));
    }
}
