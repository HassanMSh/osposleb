<?php

namespace Tests;

use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;
use ReflectionClass;

/**
 * Covers the sales receipt wording, date format, and layout safeguards.
 *
 * @internal
 */
final class ReceiptLayoutTest extends CIUnitTestCase
{
    private const RECEIPT_VIEWS = ['sales/receipt_default', 'sales/receipt_short'];

    /**
     * Loads the receipt helpers with the minimum display settings they need.
     */
    protected function setUp(): void
    {
        parent::setUp();

        helper(['currency', 'locale']);

        $ospos           = (new ReflectionClass(OSPOS::class))->newInstanceWithoutConstructor();
        $ospos->settings = [
            'currency_decimals'   => '2',
            'currency_symbol'     => '$',
            'lbp_exchange_rate'   => '89500',
            'number_locale'       => 'en_US',
            'quantity_decimals'   => '0',
            'tax_decimals'        => '2',
            'tax_included'        => true,
            'thousands_separator' => '1',
            'timeformat'          => 'H:i:s',
        ];
        Factories::injectMock('config', OSPOS::class, $ospos);
    }

    /**
     * Keeps the register wording separate and renders the same receipt heading.
     */
    public function testReceiptHeadingHasItsOwnModeWordingKey(): void
    {
        $english  = $this->loadSalesLanguage('en');
        $lebanese = $this->loadSalesLanguage('ar-LB');
        $egyptian = $this->loadSalesLanguage('ar-EG');

        $this->assertSame('Sale', $english['mode_sale']);
        $this->assertSame('عملية بيع', $lebanese['mode_sale']);
        $this->assertSame('عملية بيع', $egyptian['mode_sale']);
        $this->assertSame('Sales Receipt', $english['receipt']);
        $this->assertSame('إيصال بيع', $lebanese['receipt']);
        $this->assertSame('إيصال بيع', $egyptian['receipt']);

        $saleLibrary = file_get_contents(APPPATH . 'Libraries/Sale_lib.php');
        $sales       = file_get_contents(APPPATH . 'Controllers/Sales.php');

        $this->assertIsString($saleLibrary);
        $this->assertIsString($sales);
        $this->assertStringContainsString("'sale'       => lang('Sales.mode_sale')", $saleLibrary);
        $this->assertStringContainsString("default        => lang('Sales.mode_sale')", $sales);
        $this->assertStringContainsString('$subject = lang(\'Sales.receipt\')', $sales);

        $request  = service('request');
        $language = service('language');

        try {
            foreach (['en', 'ar-LB', 'ar-EG'] as $locale) {
                $request->setLocale($locale);
                $language->setLocale($locale);
                $expected_heading = $this->loadSalesLanguage($locale)['receipt'];

                foreach (self::RECEIPT_VIEWS as $receipt_view) {
                    $output = view($receipt_view, $this->receiptData(''));

                    $this->assertStringContainsString('<div id="sale_receipt">' . $expected_heading . '</div>', $output, "{$receipt_view} ({$locale})");
                    $this->assertStringContainsString('<div id="sale_time">21/09/2026 14:12:15</div>', $output, "{$receipt_view} ({$locale})");
                }
            }
        } finally {
            $request->setLocale('en');
            $language->setLocale('en');
        }
    }

    /**
     * Formats receipt timestamps day first while preserving the configured time.
     */
    public function testReceiptDateUsesDayFirstFormatAndConfiguredTime(): void
    {
        $timestamp = mktime(14, 12, 15, 9, 21, 2026);

        $this->assertSame('21/09/2026 14:12:15', to_receipt_datetime($timestamp));
    }

    /**
     * Keeps receipt-only print margins out of the shared print stylesheet.
     */
    public function testReceiptPrintRulesAreScopedToSalesReceipt(): void
    {
        $sharedStylesheet = file_get_contents(ROOTPATH . 'public/css/ospos_print.css');
        $receiptPage      = file_get_contents(APPPATH . 'Views/sales/receipt.php');

        $this->assertIsString($sharedStylesheet);
        $this->assertIsString($receiptPage);
        $this->assertStringNotContainsString('@page', $sharedStylesheet);
        $this->assertStringNotContainsString('padding: 3mm', $sharedStylesheet);
        $this->assertMatchesRegularExpression('/@page\s*\{\s*margin:\s*0;/', $receiptPage);
        $this->assertMatchesRegularExpression('/#receipt_wrapper\s*\{[^}]*padding:\s*3mm;/', $receiptPage);
        $this->assertStringContainsString('<style media="print">', $receiptPage);
    }

    /**
     * Keeps one-line tax wording, rate, and amount in both receipt templates.
     */
    public function testReceiptUsesOneLineTaxLabel(): void
    {
        $request  = service('request');
        $language = service('language');

        try {
            foreach (['en', 'ar-LB', 'ar-EG'] as $locale) {
                $request->setLocale($locale);
                $language->setLocale($locale);

                $data                                 = $this->receiptData('');
                $data['config']['receipt_show_taxes'] = true;
                $data['taxes']                        = [
                    [
                        'tax_group'       => 'VAT',
                        'tax_rate'        => 11,
                        'sale_tax_amount' => 1.10,
                    ],
                ];

                foreach (self::RECEIPT_VIEWS as $receipt_view) {
                    $output   = view($receipt_view, $data);
                    $vat_rows = [];

                    preg_match_all('/<tr>(.*?)<\/tr>/s', $output, $receipt_rows);

                    foreach ($receipt_rows[1] ?? [] as $row) {
                        if (str_contains($row, '<span dir="ltr">11%</span>')) {
                            $vat_rows[] = $row;
                        }
                    }

                    $this->assertCount(1, $vat_rows, "{$receipt_view} ({$locale})");
                    $this->assertSame(1, substr_count($vat_rows[0], '%'), "{$receipt_view} ({$locale})");
                    $this->assertStringContainsString('*', strip_tags($vat_rows[0]), "{$receipt_view} ({$locale})");
                    $this->assertStringContainsString(to_currency(1.10), $vat_rows[0], "{$receipt_view} ({$locale})");
                }
            }
        } finally {
            $request->setLocale('en');
            $language->setLocale('en');
        }
    }

    /**
     * Removes the old split VAT wording keys from the active receipt languages.
     */
    public function testReceiptLanguagesUseOnlyTheSingleVatWordingKey(): void
    {
        $old_vat_keys = ['vat_included_' . 'prefix', 'vat_included_' . 'suffix'];

        foreach (['en', 'ar-LB', 'ar-EG'] as $locale) {
            $sales_language = $this->loadSalesLanguage($locale);

            $this->assertArrayHasKey('vat_included', $sales_language, $locale);

            foreach ($old_vat_keys as $old_vat_key) {
                $this->assertArrayNotHasKey($old_vat_key, $sales_language, $locale);
            }
        }

        foreach (self::RECEIPT_VIEWS as $receipt_view) {
            $view_source = file_get_contents(APPPATH . 'Views/' . $receipt_view . '.php');

            $this->assertIsString($view_source);

            foreach ($old_vat_keys as $old_vat_key) {
                $this->assertStringNotContainsString($old_vat_key, $view_source, $receipt_view);
            }
        }
    }

    /**
     * Escapes transaction times in both printed receipt templates.
     */
    public function testReceiptEscapesTransactionTime(): void
    {
        $data                     = $this->receiptData('');
        $data['transaction_time'] = '21/09/2026 <script>alert("receipt")</script>';

        foreach (self::RECEIPT_VIEWS as $receipt_view) {
            $output = view($receipt_view, $data);

            $this->assertStringNotContainsString('<script>', $output, $receipt_view);
            $this->assertStringContainsString('&lt;script&gt;', $output, $receipt_view);
        }
    }

    /**
     * Escapes the invoice number once in the short receipt.
     */
    public function testShortReceiptEscapesInvoiceNumber(): void
    {
        $data                    = $this->receiptData('');
        $data['invoice_number']  = '<b>INV&1</b>';
        $escaped_invoice_number  = esc(": {$data['invoice_number']}");
        $expected_invoice_markup = '<div id="invoice_number">' . lang('Sales.invoice_number') . $escaped_invoice_number . '</div>';
        $output                  = view('sales/receipt_short', $data);

        $this->assertStringContainsString($expected_invoice_markup, $output);
        $this->assertSame(1, substr_count($output, '&lt;b&gt;INV&amp;1&lt;/b&gt;'));
        $this->assertStringNotContainsString('&amp;lt;b&amp;gt;', $output);
    }

    /**
     * Keeps the pound amount and LL marker inside one no-wrap amount cell.
     */
    public function testReceiptKeepsPoundAmountAndMarkerOnOneLine(): void
    {
        $data          = $this->receiptData('');
        $data['total'] = 10.0;
        $pound_amount  = esc(format_lbp(to_lbp($data['total'])));
        $stylesheet    = file_get_contents(ROOTPATH . 'public/css/receipt.css');

        $this->assertIsString($stylesheet);
        $this->assertMatchesRegularExpression('/\.total-value\s*\{[^}]*white-space:\s*nowrap;/s', $stylesheet);

        foreach (self::RECEIPT_VIEWS as $receipt_view) {
            $output = view($receipt_view, $data);
            $row    = $this->rowContaining($output, $pound_amount);

            $this->assertNotSame('', $row, $receipt_view);
            $this->assertStringContainsString('<td class="total-value"><span dir="ltr">' . $pound_amount . '</span></td>', $row, $receipt_view);
        }
    }

    /**
     * Skips empty item detail and spacer rows in both receipt templates.
     */
    public function testReceiptOmitsEmptyDetailAndSpacerRows(): void
    {
        $data          = $this->receiptData('');
        $data['cart'][] = [
            'name'             => 'Test item',
            'attribute_values' => '',
            'taxed_flag'       => null,
            'print_option'     => PRINT_YES,
            'quantity'         => 1,
            'price'            => 10.0,
            'total'            => 10.0,
            'discounted_total' => 10.0,
            'discount'         => 0.0,
            'discount_type'    => FIXED,
            'description'      => '',
            'serialnumber'     => '',
        ];

        foreach (self::RECEIPT_VIEWS as $receipt_view) {
            $output = view($receipt_view, $data);

            $this->assertDoesNotMatchRegularExpression('/<tr>\s*<\/tr>/s', $output, $receipt_view);

            preg_match_all('/<tr>(.*?)<\/tr>/s', $output, $receipt_rows);

            foreach ($receipt_rows[1] ?? [] as $row) {
                $this->assertNotSame('', trim(strip_tags($row)), $receipt_view);
            }
        }
    }

    /**
     * Prints change due when the customer overpays in either receipt template.
     */
    public function testReceiptPrintsChangeDueForOverpayment(): void
    {
        $data                  = $this->receiptData('');
        $data['amount_change'] = 5.0;

        foreach (self::RECEIPT_VIEWS as $receipt_view) {
            $output = view($receipt_view, $data);
            $row    = $this->rowContaining($output, lang('Sales.change_due'));

            $this->assertNotSame('', $row, $receipt_view);
            $this->assertStringContainsString(to_currency(5.0), $row, $receipt_view);
        }
    }

    /**
     * Omits payment and change rows when one payment exactly settles either receipt.
     */
    public function testReceiptOmitsChangeRowForExactPayment(): void
    {
        $data             = $this->receiptData('');
        $data['total']    = 10.0;
        $data['payments'] = [
            [
                'payment_type'   => lang('Sales.cash'),
                'payment_amount' => 10.0,
            ],
        ];

        foreach (self::RECEIPT_VIEWS as $receipt_view) {
            $output = view($receipt_view, $data);

            $this->assertStringNotContainsString(lang('Sales.change_due'), $output, $receipt_view);
            $this->assertStringNotContainsString(lang('Sales.check_balance'), $output, $receipt_view);
            $this->assertStringNotContainsString(lang('Sales.amount_due'), $output, $receipt_view);
            $this->assertStringNotContainsString(lang('Sales.cash'), $output, $receipt_view);
        }
    }

    /**
     * Prints an amount due for an underpaid sale in either receipt template.
     */
    public function testReceiptPrintsAmountDueForUnderpayment(): void
    {
        $data                  = $this->receiptData('');
        $data['amount_change'] = -5.0;

        foreach (self::RECEIPT_VIEWS as $receipt_view) {
            $output = view($receipt_view, $data);
            $row    = $this->rowContaining($output, lang('Sales.amount_due'));

            $this->assertNotSame('', $row, $receipt_view);
            $this->assertStringContainsString(to_currency(-5.0), $row, $receipt_view);
        }
    }

    /**
     * Prints each split payment row in either receipt template.
     */
    public function testReceiptPrintsRowsForMultiplePayments(): void
    {
        $data             = $this->receiptData('');
        $data['payments'] = [
            [
                'payment_type'   => lang('Sales.cash'),
                'payment_amount' => 5.0,
            ],
            [
                'payment_type'   => lang('Sales.giftcard'),
                'payment_amount' => 5.0,
            ],
        ];

        foreach (self::RECEIPT_VIEWS as $receipt_view) {
            $output  = view($receipt_view, $data);
            $cashRow = $this->rowContaining($output, lang('Sales.cash'));
            $cardRow = $this->rowContaining($output, lang('Sales.giftcard'));

            $this->assertNotSame('', $cashRow, $receipt_view);
            $this->assertNotSame('', $cardRow, $receipt_view);
            $this->assertStringContainsString(to_currency(-5.0), $cashRow, $receipt_view);
            $this->assertStringContainsString(to_currency(-5.0), $cardRow, $receipt_view);
        }
    }

    /**
     * Suppresses the single settled payment row in either receipt template.
     */
    public function testReceiptSuppressesSingleFullySettlingPayment(): void
    {
        $data             = $this->receiptData('');
        $data['payments'] = [
            [
                'payment_type'   => lang('Sales.cash'),
                'payment_amount' => 10.0,
            ],
        ];

        foreach (self::RECEIPT_VIEWS as $receipt_view) {
            $output = view($receipt_view, $data);

            $this->assertStringNotContainsString(lang('Sales.cash'), $output, $receipt_view);
        }
    }

    /**
     * Omits punctuation-only return policies from both receipt templates.
     */
    public function testPunctuationOnlyReturnPolicyIsNotRendered(): void
    {
        foreach (self::RECEIPT_VIEWS as $receipt_view) {
            $output = view($receipt_view, $this->receiptData('.'));

            $this->assertStringNotContainsString('id="sale_return_policy"', $output, $receipt_view);
        }
    }

    /**
     * Keeps a return policy that contains real text in both receipt templates.
     */
    public function testTextReturnPolicyIsRendered(): void
    {
        foreach (self::RECEIPT_VIEWS as $receipt_view) {
            $output = view($receipt_view, $this->receiptData('Returns accepted.'));

            $this->assertStringContainsString('id="sale_return_policy"', $output, $receipt_view);
            $this->assertStringContainsString('Returns accepted.', $output, $receipt_view);
        }
    }

    /**
     * Prints a gift card balance in either receipt template.
     */
    public function testReceiptPrintsGiftCardBalance(): void
    {
        $data                       = $this->receiptData('');
        $data['cur_giftcard_value'] = 12.5;
        $data['payments']           = [
            [
                'payment_type'   => lang('Sales.giftcard') . ':1234',
                'payment_amount' => 5.0,
            ],
            [
                'payment_type'   => lang('Sales.cash'),
                'payment_amount' => 5.0,
            ],
        ];

        foreach (self::RECEIPT_VIEWS as $receipt_view) {
            $output = view($receipt_view, $data);
            $row    = $this->rowContaining($output, lang('Sales.giftcard_balance'));

            $this->assertNotSame('', $row, $receipt_view);
            $this->assertStringContainsString(to_currency(12.5), $row, $receipt_view);
        }
    }

    /**
     * Prints a return total without adding a zero change row in either template.
     */
    public function testReceiptPrintsReturnTotalWithoutChangeRow(): void
    {
        $data             = $this->receiptData('');
        $data['total']    = -8.0;
        $data['payments'] = [
            [
                'payment_type'   => lang('Sales.cash'),
                'payment_amount' => -8.0,
            ],
        ];

        foreach (self::RECEIPT_VIEWS as $receipt_view) {
            $output = view($receipt_view, $data);
            $row    = $this->rowContaining($output, lang('Sales.total_to_pay'));

            $this->assertNotSame('', $row, $receipt_view);
            $this->assertStringContainsString(to_currency(-8.0), $row, $receipt_view);
            $this->assertStringNotContainsString(lang('Sales.change_due'), $output, $receipt_view);
        }
    }

    /**
     * Returns the single table row that holds the given text.
     *
     * @param string $html   Rendered receipt markup.
     * @param string $needle Text the row must contain.
     *
     * @return string Matching row markup, or an empty string when no row holds the text.
     */
    private function rowContaining(string $html, string $needle): string
    {
        foreach (explode('<tr>', $html) as $row) {
            if ($needle !== '' && str_contains($row, $needle)) {
                return $row;
            }
        }

        return '';
    }

    /**
     * Reads the sales language file for a locale.
     *
     * @param string $locale Locale directory name.
     *
     * @return array<string, string> Sales translations.
     */
    private function loadSalesLanguage(string $locale): array
    {
        return require APPPATH . "Language/{$locale}/Sales.php";
    }

    /**
     * Builds the smallest valid data set for rendering either receipt template.
     *
     * @param string $returnPolicy Return policy text to place in the view data.
     *
     * @return array<string, mixed> Receipt view data.
     */
    private function receiptData(string $returnPolicy): array
    {
        return [
            'transaction_time'     => '21/09/2026 14:12:15',
            'sale_id'              => 'POS 42',
            'invoice_number'       => '',
            'employee'             => 'Test employee',
            'cart'                 => [],
            'discount'             => 0.0,
            'prediscount_subtotal' => 0.0,
            'subtotal'             => 0.0,
            'taxes'                => [],
            'total'                => 0.0,
            'payments'             => [],
            'amount_change'        => 0.0,
            'barcode'              => '',
            'config'               => [
                'company_logo'                => '',
                'receipt_font_size'           => 12,
                'receipt_show_company_name'   => false,
                'receipt_show_description'    => true,
                'receipt_show_serialnumber'   => true,
                'receipt_show_total_discount' => false,
                'receipt_show_taxes'          => false,
                'company'                     => 'Test shop',
                'address'                     => '',
                'phone'                       => '',
                'return_policy'               => $returnPolicy,
                'lbp_exchange_rate'           => '89500',
            ],
        ];
    }
}
