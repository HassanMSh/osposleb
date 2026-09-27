<?php

namespace Tests;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Covers the restaurant quantity and percentage discount step controls.
 *
 * @internal
 */
final class RestaurantLineStepperTest extends CIUnitTestCase
{
    /**
     * Renders the quantity step targets and keeps the minimum quantity at one.
     */
    public function testQuantityTargetsAndMinimum(): void
    {
        $html = $this->renderCell('sales/restaurant_quantity_cell', ['quantity' => '3']);

        $this->assertStringContainsString('data-step-value="2"', $html);
        $this->assertStringContainsString('data-step-value="4"', $html);
        $this->assertStringContainsString('aria-label="' . esc(lang('Sales.quantity_decrease_step')) . '"', $html);

        $minimum_html = $this->renderCell('sales/restaurant_quantity_cell', ['quantity' => '1']);
        $this->assertMatchesRegularExpression(
            '/<button\b(?=[^>]*data-step-target="quantity")(?=[^>]*data-step-value="1")(?=[^>]*disabled)[^>]*>−<\/button>/',
            $minimum_html,
        );
    }

    /**
     * Renders percent discount steps with limits at zero and one hundred.
     */
    public function testPercentDiscountTargetsAndLimits(): void
    {
        $html = $this->renderCell('sales/restaurant_discount_cell', ['discount' => '10', 'discount_type' => PERCENT]);

        $this->assertStringContainsString('data-step-value="5.00"', $html);
        $this->assertStringContainsString('data-step-value="15.00"', $html);
        $this->assertStringContainsString('aria-label="' . esc(lang('Sales.discount_increase_step')) . '"', $html);

        $zero_html = $this->renderCell('sales/restaurant_discount_cell', ['discount' => '0', 'discount_type' => PERCENT]);
        $this->assertMatchesRegularExpression(
            '/<button\b(?=[^>]*data-step-target="discount")(?=[^>]*data-step-value="0\.00")(?=[^>]*disabled)[^>]*>−<\/button>/',
            $zero_html,
        );

        $full_html = $this->renderCell('sales/restaurant_discount_cell', ['discount' => '100', 'discount_type' => PERCENT]);
        $this->assertMatchesRegularExpression(
            '/<button\b(?=[^>]*data-step-target="discount")(?=[^>]*data-step-value="100\.00")(?=[^>]*disabled)[^>]*>\+<\/button>/',
            $full_html,
        );

        $near_full_html = $this->renderCell('sales/restaurant_discount_cell', ['discount' => '97', 'discount_type' => PERCENT]);
        $this->assertStringContainsString('data-step-value="100.00"', $near_full_html);
    }

    /**
     * Renders a percent-only discount cell with no switch to an LL amount.
     */
    public function testDiscountHasNoLbpSwitch(): void
    {
        $html = $this->renderCell('sales/restaurant_discount_cell', ['discount' => '10', 'discount_type' => PERCENT]);

        $this->assertStringNotContainsString('discount_toggle', $html);
        $this->assertStringNotContainsString('data-toggle', $html);
        $this->assertMatchesRegularExpression('/name="discount_type"[^>]*value="0"|value="0"[^>]*name="discount_type"/', $html);
    }

    /**
     * Keeps an existing fixed discount in LL, without percentage steps or a switch.
     */
    public function testFixedDiscountHasNoStepButtons(): void
    {
        $html = $this->renderCell('sales/restaurant_discount_cell', ['discount' => '10', 'discount_type' => FIXED]);

        $this->assertStringNotContainsString('restaurant-line-step', $html);
        $this->assertStringNotContainsString('discount_toggle', $html);
        $this->assertStringContainsString('value="895000"', $html);
        $this->assertMatchesRegularExpression('/name="discount_type"[^>]*value="1"|value="1"[^>]*name="discount_type"/', $html);
    }

    /**
     * Renders a restaurant cell with stable locale settings and restores the prior settings.
     *
     * @param array<string, mixed> $item
     */
    private function renderCell(string $view_name, array $item): string
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
            return view($view_name, [
                'config'   => ['till_layout' => 'restaurant', 'lbp_exchange_rate' => 89_500],
                'item'     => $item,
                'line'     => 1,
                'tabindex' => 1,
            ]);
        } finally {
            if ($previous_settings === false) {
                $settings_cache->delete('settings');
            } else {
                $settings_cache->save('settings', $previous_settings);
            }
        }
    }
}
