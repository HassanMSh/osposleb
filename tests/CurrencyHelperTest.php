<?php

namespace Tests;

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
        $ospos->settings = ['lbp_exchange_rate' => '89500'];
        Factories::injectMock('config', OSPOS::class, $ospos);
    }

    /**
     * Rounds to the nearest 1,000 pounds: below 500 goes down, 500 and above goes up.
     */
    public function testPoundConversionRoundsUpAndDown(): void
    {
        $this->assertSame(4_090_000, to_lbp('45.70'));
        $this->assertSame(1_104_000, to_lbp('12.34'));
        $this->assertSame(1_107_000, to_lbp('12.37'));
        $this->assertSame(90_000, to_lbp('1.00'));
        $this->assertSame(269_000, to_lbp('3.00'));
    }

    /**
     * Formats the rounded pound amount with separators and the LL marker.
     */
    public function testPoundFormattingUsesThousandsSeparatorsAndMarker(): void
    {
        $this->assertSame('4,090,000 LL', format_lbp(to_lbp('45.70')));
    }

    /**
     * Derives both change figures from one dollar change calculation.
     */
    public function testChangeFiguresAgreeThroughDollarCalculation(): void
    {
        $dollar_change = 50.00 - 45.70;

        $this->assertSame(4.30, round($dollar_change, 2));
        $this->assertSame(385_000, to_lbp((string) $dollar_change));

        $tendered_pounds  = 5_000_000;
        $tendered_dollars = $tendered_pounds / 89500;
        $change_dollars   = $tendered_dollars - 45.70;
        $change_pounds    = to_lbp((string) $change_dollars);

        $this->assertSame(10.17, round($change_dollars, 2));
        $this->assertSame(910_000, $change_pounds);
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
            $this->assertStringContainsString('to_lbp($total)', $source);
            $this->assertStringContainsString("lang('Sales.total_to_pay')", $source);
        }
    }

    /**
     * Keeps the register change helper read-only and based on one dollar result.
     */
    public function testRegisterChangeHelperUsesOneDollarCalculationWithoutWriting(): void
    {
        $source = file_get_contents(APPPATH . 'Views/sales/register.php');

        $this->assertIsString($source);
        $this->assertStringContainsString('id="change_helper_amount"', $source);
        $this->assertStringContainsString('const changeDollars = tenderedDollars - changeHelperTotal;', $source);
        $this->assertStringContainsString('const changePounds = Math.round((changeDollars * rate) / 1000) * 1000;', $source);

        $helper_start = strpos($source, 'function updateChangeHelper');
        $helper_end   = strpos($source, '// Add Keyboard Shortcuts', $helper_start);
        $helper_code  = substr($source, $helper_start, $helper_end - $helper_start);

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
                APPPATH . 'Helpers/tabular_helper.php',
            ],
        );

        foreach ($paths as $path) {
            $source = file_get_contents($path);

            $this->assertIsString($source);
            $this->assertStringNotContainsString('lbp_exchange_rate', $source, $path);
            $this->assertStringNotContainsString('to_lbp(', $source, $path);
        }
    }
}
