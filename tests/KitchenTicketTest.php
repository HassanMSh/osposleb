<?php

namespace Tests;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Covers kitchen ticket output and the restaurant-only receipt hook.
 *
 * @internal
 */
final class KitchenTicketTest extends CIUnitTestCase
{
    /**
     * Renders saved ticket lines and omits all price and payment data.
     */
    public function testTicketRendersSavedLinesAndShowsOnlyKitchenDetails(): void
    {
        $html = $this->renderTicket();

        preg_match_all(
            '/<div class="kitchen-ticket-line">\s*<span class="kitchen-ticket-qty" dir="ltr">(.*?)<\/span>\s*<span class="kitchen-ticket-name">(.*?)<\/span>\s*<\/div>/s',
            $html,
            $matches,
        );

        $this->assertSame(['1', '2', '1'], $matches[1]);
        $this->assertSame(['Burger', 'Cola', 'Burger'], array_map('trim', $matches[2]));
        $this->assertStringContainsString('No onions', $html);
        $this->assertStringContainsString('123', $html);
        $this->assertStringContainsString('2026-09-26 12:00:00', $html);
        $this->assertStringNotContainsString('12.34', $html);
        $this->assertStringNotContainsString('2.50', $html);
        $this->assertStringNotContainsString('5.00', $html);
        $this->assertStringNotContainsString('LL', $html);
        $this->assertStringNotContainsString('TVA', $html);
        $this->assertStringNotContainsString('$', $html);
        $description_line = [[
            'line'               => 1,
            'quantity_purchased' => '1.000',
            'name'               => 'Burger',
            'description'        => 'Cheese removed',
        ]];
        $hidden_description = $this->renderTicket(['comments' => '', 'ticket_lines' => $description_line]);
        $this->assertStringNotContainsString('Cheese removed', $hidden_description);

        $shown_description = $this->renderTicket([
            'config'       => ['till_layout' => 'restaurant', 'receipt_show_description' => true],
            'comments'     => '',
            'ticket_lines' => $description_line,
        ]);
        $this->assertStringContainsString('Cheese removed', $shown_description);
    }

    /**
     * Renders tickets only for eligible restaurant sales with positive quantities.
     */
    public function testOnlyCompletedRestaurantSalesWithPositiveLinesRenderTickets(): void
    {
        $ticket_lines = [
            ['line' => 1, 'quantity_purchased' => '1.000', 'name' => 'Burger', 'description' => ''],
            ['line' => 2, 'quantity_purchased' => '0.000', 'name' => 'Zero line', 'description' => ''],
            ['line' => 3, 'quantity_purchased' => '-1.000', 'name' => 'Return line', 'description' => ''],
        ];
        $this->assertStringContainsString('id="kitchen_ticket"', $this->renderTicket(['ticket_lines' => $ticket_lines]));

        $ineligible_sales = [
            ['sale_type' => SALE_TYPE_RETURN],
            ['sale_status' => SUSPENDED],
            ['sale_status' => SUSPENDED, 'sale_type' => SALE_TYPE_QUOTE],
            ['sale_type'   => SALE_TYPE_INVOICE],
            ['config'      => ['till_layout' => 'shop']],
            ['config'      => []],
            ['ticket_lines' => [
                ['line' => 1, 'quantity_purchased' => '0.000', 'name' => 'Zero line', 'description' => ''],
                ['line' => 2, 'quantity_purchased' => '-1.000', 'name' => 'Return line', 'description' => ''],
            ]],
        ];

        foreach ($ineligible_sales as $overrides) {
            $this->assertStringNotContainsString('id="kitchen_ticket"', $this->renderTicket($overrides));
        }
    }

    /**
     * Renders the ticket partial with stable quantity formatting and default sale data.
     *
     * @param array<string, mixed> $overrides Values that replace the default view data.
     */
    private function renderTicket(array $overrides = []): string
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
            return view('sales/kitchen_ticket', array_replace([
                'config'        => ['till_layout' => 'restaurant', 'receipt_show_description' => false],
                'comments'      => 'No onions',
                'language_code' => 'en',
                'sale_id_num'   => 123,
                'sale_status'   => COMPLETED,
                'sale_type'     => SALE_TYPE_POS,
                'ticket_lines'  => [
                    ['line' => 1, 'quantity_purchased' => '1.000', 'name' => 'Burger', 'description' => ''],
                    ['line' => 2, 'quantity_purchased' => '2.000', 'name' => 'Cola', 'description' => ''],
                    ['line' => 3, 'quantity_purchased' => '1.000', 'name' => 'Burger', 'description' => ''],
                ],
                'transaction_time' => '2026-09-26 12:00:00',
            ], $overrides));
        } finally {
            if ($previous_settings === false) {
                $settings_cache->delete('settings');
            } else {
                $settings_cache->save('settings', $previous_settings);
            }
        }
    }
}
