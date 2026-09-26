<?php

namespace Tests;

use App\Libraries\Tax_lib;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;
use ReflectionClass;

/**
 * Covers the display-only Lebanese pound conversion and register change maths.
 *
 * @internal
 */
final class CurrencyHelperTest extends CIUnitTestCase
{
    /**
     * Loads the currency helper with an in-memory exchange-rate setting.
     */
    protected function setUp(): void
    {
        parent::setUp();

        helper('currency');

        $ospos           = (new ReflectionClass(OSPOS::class))->newInstanceWithoutConstructor();
        $ospos->settings = [
            'lbp_exchange_rate'   => '89500',
            'currency_decimals'   => '2',
            'number_locale'       => 'en_US',
            'thousands_separator' => '1',
        ];
        Factories::injectMock('config', OSPOS::class, $ospos);
    }

    /**
     * Keeps the legacy exact conversion available to existing callers.
     */
    public function testPoundConversionKeepsExactAmount(): void
    {
        $this->assertSame(4_090_150, to_lbp('45.70'));
        $this->assertSame(1_104_430, to_lbp('12.34'));
        $this->assertSame(1_107_115, to_lbp('12.37'));
        $this->assertSame(89_500, to_lbp('1.00'));
        $this->assertSame(268_500, to_lbp('3.00'));
    }

    /**
     * Formats the pound amount with separators and the LL marker.
     */
    public function testPoundFormattingUsesThousandsSeparatorsAndMarker(): void
    {
        $this->assertSame('4,090,150 LL', format_lbp(to_lbp('45.70')));
    }

    /**
     * Applies rule R at exact 500-pound boundaries and mirrors negative amounts.
     */
    public function testThousandRoundingUsesIntegerBoundariesAndMirrorsReturns(): void
    {
        $this->assertSame(110_000, round_lbp_to_thousand(109_800));
        $this->assertSame(25_000, round_lbp_to_thousand(25_200));
        $this->assertSame(110_000, round_lbp_to_thousand(109_500));
        $this->assertSame(109_000, round_lbp_to_thousand(109_499));
        $this->assertSame(-110_000, round_lbp_to_thousand(-109_800));
        $this->assertSame(-25_000, round_lbp_to_thousand(-25_200));
        $this->assertSame(-110_000, round_lbp_to_thousand(-109_500));
        $this->assertSame(-109_000, round_lbp_to_thousand(-109_499));
    }

    /**
     * Rounds each cart unit before multiplying by quantity and totals the lines.
     */
    public function testCartTotalsRoundUnitsBeforeMultiplyingQuantity(): void
    {
        $cart = [
            1 => ['line' => 1, 'price' => 1.22, 'quantity' => 3, 'discounted_total' => 3.66],
            2 => ['line' => 2, 'price' => 0.28, 'quantity' => 2, 'discounted_total' => 0.56],
        ];

        $totals = get_lbp_cart_totals($cart, [], 90_000);

        $this->assertSame(330_000, $totals['lines'][1]['line_total_lbp']);
        $this->assertSame(50_000, $totals['lines'][2]['line_total_lbp']);
        $this->assertSame(380_000, $totals['total']);
    }

    /**
     * Uses discounted dollars and adds only excluded TVA to the customer unit.
     */
    public function testCartTotalsIncludeDiscountAndExcludedTaxInCustomerUnit(): void
    {
        $cart = [
            1 => ['line' => 1, 'price' => 10.0, 'quantity' => 2, 'discounted_total' => 18.0],
            2 => ['line' => 2, 'price' => 10.0, 'quantity' => 1, 'discounted_total' => 10.0],
        ];
        $item_taxes = [
            ['line' => 1, 'tax_type' => Tax_lib::TAX_TYPE_INCLUDED, 'item_tax_amount' => 1.8],
            ['line' => 2, 'tax_type' => Tax_lib::TAX_TYPE_EXCLUDED, 'item_tax_amount' => 1.1],
        ];

        $totals = get_lbp_cart_totals($cart, $item_taxes, 90_000);

        $this->assertSame(810_000, $totals['lines'][1]['customer_unit_lbp']);
        $this->assertSame(1_620_000, $totals['lines'][1]['line_total_lbp']);
        $this->assertSame(999_000, $totals['lines'][2]['customer_unit_lbp']);
    }

    /**
     * Keeps stored dollars on unchanged input and converts new pound entries to cents.
     */
    public function testPostedPoundsPreserveStoredPricesAndConvertNewValues(): void
    {
        $this->assertSame('1.22', lbp_to_dollar_string('110000', 90_000, '1.22'));
        $this->assertSame('1.22', lbp_to_dollar_string('110000', 90_000));
        $this->assertSame('0.28', lbp_to_dollar_string('25000', 90_000));

        $cost_shown = round_lbp_to_thousand(1.23 * 90_000);

        $this->assertSame(111_000, $cost_shown);
        $this->assertSame('1.23', lbp_to_dollar_string('111000', 90_000, '1.23', true));
        $this->assertSame('1.23', lbp_to_dollar_string('110700', 90_000, '1.23', false));
        $this->assertSame('110000', format_lbp_input(110_000));
    }

    /**
     * Preserves a stored cent value when rounding at 89,500 LL would otherwise move it.
     */
    public function testPostedPoundsPreserveAStoredPriceThatWouldDriftAtRate89500(): void
    {
        $this->assertSame(90_000, round_lbp_to_thousand(1.00 * 89_500));
        $this->assertSame('1.01', lbp_to_dollar_string('90000', 89_500));
        $this->assertSame('1.00', lbp_to_dollar_string('90000', 89_500, '1.00'));
    }

    /**
     * Confirms plain pound digits parse under the English and Lebanese number locales.
     */
    public function testPlainIntegerPoundsParseInEnglishAndArabicLocales(): void
    {
        $settings = config(OSPOS::class)->settings;

        foreach (['en_US', 'ar_LB'] as $locale) {
            config(OSPOS::class)->settings['number_locale'] = $locale;
            $this->assertSame(110_000.0, parse_decimals('110000'));
        }

        config(OSPOS::class)->settings = $settings;
    }

    /**
     * Calculates pound change from the rounded LBP sale total.
     */
    public function testChangeFiguresUseTheRoundedLbpSaleTotal(): void
    {
        $sale_total_lbp = 380_000;
        $change_lbp     = 400_000 - $sale_total_lbp;
        $change_dollars = $change_lbp / 90_000;

        $this->assertSame(20_000, $change_lbp);
        $this->assertSame(0.22, round($change_dollars, 2));
        $this->assertSame(70_000, 5 * 90_000 - $sale_total_lbp);
        $this->assertSame(0.78, round(5 - 4.22, 2));
    }

    /**
     * Confirms the conversion helper only reads settings and leaves the sale data untouched.
     */
    public function testConversionDoesNotWriteStoredValues(): void
    {
        $before = config(OSPOS::class)->settings;

        to_lbp('45.70');

        $this->assertSame($before, config(OSPOS::class)->settings);
    }

    /**
     * Translates the internal exemption reasons and leaves a shop tax name alone.
     */
    public function testTaxGroupLabelTranslatesExemptionReasonsOnly(): void
    {
        $this->assertSame(lang('Items.tax_reason_exempt'), format_tax_group_label('exempt'));
        $this->assertSame(lang('Items.tax_reason_zero_rated'), format_tax_group_label('zero-rated'));
        $this->assertSame('VAT', format_tax_group_label('VAT'));
        $this->assertSame('', format_tax_group_label(null));
    }

    /**
     * Resolves exemption labels in English and Lebanese Arabic.
     */
    public function testTaxGroupLabelsResolveInEnglishAndLebaneseArabic(): void
    {
        $language = service('language');
        $request  = service('request');

        $labels = [
            'en'    => ['Exempt', 'Zero-rated'],
            'ar-LB' => ['معفى', 'معدل صفري'],
        ];

        foreach ($labels as $locale => [$exempt, $zeroRated]) {
            $request->setLocale($locale);
            $language->setLocale($locale);

            $this->assertSame($exempt, format_tax_group_label('exempt'));
            $this->assertSame($zeroRated, format_tax_group_label('zero-rated'));
        }
    }

    /**
     * Keeps every tax-group label in the register and both receipts translated.
     */
    public function testEveryTaxGroupLabelGoesThroughTheHelper(): void
    {
        $views = [
            'Views/sales/register.php',
            'Views/sales/receipt_default.php',
            'Views/sales/receipt_short.php',
        ];

        foreach ($views as $view) {
            $source = file_get_contents(APPPATH . $view);

            $this->assertIsString($source);

            $raw     = substr_count($source, '$tax[\'tax_group\']');
            $wrapped = substr_count($source, 'format_tax_group_label($tax[\'tax_group\'])');

            $this->assertSame($raw, $wrapped, $view . ' uses a tax group name without the translating helper.');
        }
    }

    /**
     * Uses the cart marker for both taxed and untaxed receipt lines.
     */
    public function testReceiptTaxMarkerUsesExistingCartFlag(): void
    {
        $this->assertSame('*', format_receipt_tax_marker('T'));
        $this->assertSame('*', format_receipt_tax_marker('ض'));
        $this->assertSame('', format_receipt_tax_marker(' '));
        $this->assertSame('', format_receipt_tax_marker(null));
    }

    /**
     * Keeps the dollar and pound totals paired in both receipt templates.
     */
    public function testBothReceiptTemplatesUseTheSameDisplayTotal(): void
    {
        foreach (['receipt_default.php', 'receipt_short.php'] as $template) {
            $source = file_get_contents(APPPATH . 'Views/sales/' . $template);

            $this->assertIsString($source);
            $this->assertStringContainsString('format_receipt_tax_marker', $source);
            $this->assertStringContainsString('format_lbp($lbp_total)', $source);
            $this->assertStringContainsString("lang('Sales.total_to_pay')", $source);
        }
    }

    /**
     * Keeps the register change helper read-only and based on the rounded LBP total.
     */
    public function testRegisterChangeHelperUsesRoundedLbpTotalWithoutWriting(): void
    {
        $source = file_get_contents(APPPATH . 'Views/sales/register.php');

        $this->assertIsString($source);
        $this->assertStringContainsString('id="change_helper_amount"', $source);
        $this->assertStringContainsString('const changePounds = tenderedPounds - changeHelperPoundsTotal;', $source);
        $this->assertStringContainsString('const changeDollars = isPoundTender ? changePounds / rate : amount - changeHelperTotal;', $source);
        $this->assertStringContainsString('if (!Number.isFinite(rate) || rate <= 0)', $source);

        $helper_start = strpos($source, 'function updateChangeHelper');
        $helper_end   = strpos($source, '// Add Keyboard Shortcuts', $helper_start);
        $helper_code  = substr($source, $helper_start, $helper_end - $helper_start);

        $this->assertStringContainsString("$('#change_helper_dollars').text(formatChangeDollars(0));", $helper_code);
        $this->assertStringContainsString("$('#change_helper_pounds').text(formatChangePounds(0));", $helper_code);
        $this->assertStringNotContainsString('$.post', $helper_code);
        $this->assertStringNotContainsString('add_payment_form', $helper_code);
    }

    /**
     * Exposes the current sale total on the fragment read by scan updates.
     */
    public function testRegisterChangeHelperTotalUsesOverallSaleAttribute(): void
    {
        $source = file_get_contents(APPPATH . 'Views/sales/register.php');

        $this->assertIsString($source);
        $this->assertStringContainsString('data-change-helper-total="<?= esc((string) (float) $total, \'attr\') ?>"', $source);
        $this->assertStringContainsString("\$sale.attr('data-change-helper-total')", $source);
        $this->assertStringContainsString('!Number.isFinite(responseTotal)', $source);
        $this->assertStringContainsString("const changeHelperAmount = \$('#change_helper_amount').val();", $source);
        $this->assertStringContainsString("\$('#change_helper_amount').val(changeHelperAmount);", $source);
        $this->assertStringNotContainsString('response.match(/let changeHelperTotal', $source);
    }

    /**
     * Keeps the exchange rate outside stored, report, and drawer calculations.
     */
    public function testExchangeRateIsUsedOnlyByDisplayCode(): void
    {
        $helper_source   = file_get_contents(APPPATH . 'Helpers/currency_helper.php');
        $register_source = file_get_contents(APPPATH . 'Views/sales/register.php');

        $this->assertIsString($helper_source);
        $this->assertIsString($register_source);
        $this->assertStringNotContainsString('insert(', $helper_source);
        $this->assertStringNotContainsString('update(', $helper_source);
        $this->assertStringNotContainsString('delete(', $helper_source);
        $change_start   = strpos($register_source, 'id="change_helper"');
        $payment_start  = strpos($register_source, 'id="payment_details"');
        $change_section = substr($register_source, $change_start, $payment_start - $change_start);

        $this->assertStringNotContainsString('amount_tendered', $change_section);
    }

    /**
     * Keeps receipt, register, and report dollar totals on the existing cent formatter.
     */
    public function testDollarTotalsUseTheSameSourceAcrossScreensAndReports(): void
    {
        foreach (['receipt_default.php', 'receipt_short.php', 'register.php'] as $template) {
            $source = file_get_contents(APPPATH . 'Views/sales/' . $template);

            $this->assertIsString($source);
            $this->assertStringContainsString('to_currency($total)', $source);
        }

        $reports = file_get_contents(APPPATH . 'Controllers/Reports.php');

        $this->assertIsString($reports);
        $this->assertStringContainsString("'total'     => to_currency(\$row['total'])", $reports);
    }

    /**
     * Confirms pound display code is absent from reports and drawer calculations.
     */
    public function testReportsAndDrawerCalculationsDoNotReadTheExchangeRate(): void
    {
        $paths = array_merge(
            glob(APPPATH . 'Models/Reports/*.php') ?: [],
            [
                APPPATH . 'Controllers/Reports.php',
                APPPATH . 'Controllers/Cashups.php',
                APPPATH . 'Models/Cashup.php',
            ],
        );

        foreach ($paths as $path) {
            $source = file_get_contents($path);

            $this->assertIsString($source);
            $this->assertStringNotContainsString('lbp_exchange_rate', $source, $path);
            $this->assertStringNotContainsString('to_lbp(', $source, $path);
        }

        $tabular = file_get_contents(APPPATH . 'Helpers/tabular_helper.php');

        $this->assertIsString($tabular);
        $this->assertStringNotContainsString('to_lbp(', $tabular);
        $this->assertSame(1, substr_count($tabular, 'lbp_exchange_rate'), 'Only the Items list row may read the exchange rate.');

        $item_row_start = strpos($tabular, 'function get_item_data_row(');
        $item_row_end   = strpos($tabular, "\nfunction ", $item_row_start + 1);
        $rate_position  = strpos($tabular, 'lbp_exchange_rate');

        $this->assertIsInt($item_row_start);
        $this->assertTrue($rate_position > $item_row_start && $rate_position < $item_row_end, 'The exchange rate is read outside get_item_data_row().');
    }
}
