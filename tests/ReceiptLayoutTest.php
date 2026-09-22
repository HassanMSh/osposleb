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
     * Keeps the register wording separate from the printed receipt heading.
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
     * Keeps the tax wording on one line and the rate left to right.
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

                $output = view('sales/receipt_default', $data);

                preg_match_all('/<td colspan="3" class="total-value">\s*(.*?)\s*<\/td>/s', $output, $matches);
                $vat_lines = [];

                foreach ($matches[1] ?? [] as $line) {
                    if (str_contains($line, '<span dir="ltr">11')) {
                        $vat_lines[] = $line;
                    }
                }

                $this->assertCount(1, $vat_lines, $locale);
                $this->assertSame(1, substr_count($vat_lines[0], '%'), $locale);
                $this->assertStringContainsString('<span dir="ltr">11%', $vat_lines[0]);
                $this->assertStringContainsString('*', strip_tags($vat_lines[0]));
            }
        } finally {
            $request->setLocale('en');
            $language->setLocale('en');
        }
    }

    /**
     * Prints change due when the customer pays more than the sale total.
     */
    public function testReceiptPrintsChangeDueForOverpayment(): void
    {
        $data                  = $this->receiptData('');
        $data['amount_change'] = 5.0;

        $output = view('sales/receipt_default', $data);
        $row    = $this->rowContaining($output, lang('Sales.change_due'));

        $this->assertNotSame('', $row);
        $this->assertStringContainsString(to_currency(5.0), $row);
    }

    /**
     * Omits the change row when the payment exactly settles the sale.
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

        $output = view('sales/receipt_default', $data);

        $this->assertStringNotContainsString(lang('Sales.change_due'), $output);
        $this->assertStringNotContainsString(lang('Sales.check_balance'), $output);
        $this->assertStringNotContainsString(lang('Sales.amount_due'), $output);
        $this->assertStringNotContainsString(lang('Sales.cash'), $output);
    }

    /**
     * Prints amount due when the customer has not paid the full sale total.
     */
    public function testReceiptPrintsAmountDueForUnderpayment(): void
    {
        $data                  = $this->receiptData('');
        $data['amount_change'] = -5.0;

        $output = view('sales/receipt_default', $data);
        $row    = $this->rowContaining($output, lang('Sales.amount_due'));

        $this->assertNotSame('', $row);
        $this->assertStringContainsString(to_currency(-5.0), $row);
    }

    /**
     * Prints every payment row when a sale uses split payments.
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

        $output  = view('sales/receipt_default', $data);
        $cashRow = $this->rowContaining($output, lang('Sales.cash'));
        $cardRow = $this->rowContaining($output, lang('Sales.giftcard'));

        $this->assertNotSame('', $cashRow);
        $this->assertNotSame('', $cardRow);
        $this->assertStringContainsString(to_currency(-5.0), $cashRow);
        $this->assertStringContainsString(to_currency(-5.0), $cardRow);
    }

    /**
     * Suppresses the single payment row when it exactly settles the sale.
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

        $output = view('sales/receipt_default', $data);

        $this->assertStringNotContainsString(lang('Sales.cash'), $output);
    }

    /**
     * Omits punctuation-only return policies from the printed receipt.
     */
    public function testPunctuationOnlyReturnPolicyIsNotRendered(): void
    {
        $output = view('sales/receipt_default', $this->receiptData('.'));

        $this->assertStringNotContainsString('id="sale_return_policy"', $output);
    }

    /**
     * Keeps a return policy that contains real text on the receipt.
     */
    public function testTextReturnPolicyIsRendered(): void
    {
        $output = view('sales/receipt_default', $this->receiptData('Returns accepted.'));

        $this->assertStringContainsString('id="sale_return_policy"', $output);
        $this->assertStringContainsString('Returns accepted.', $output);
    }

    /**
     * Prints the gift card balance when a gift card paid for part of the sale.
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

        $output = view('sales/receipt_default', $data);
        $row    = $this->rowContaining($output, lang('Sales.giftcard_balance'));

        $this->assertNotSame('', $row);
        $this->assertStringContainsString(to_currency(12.5), $row);
    }

    /**
     * Prints a refunded return total without adding a zero change row.
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

        $output = view('sales/receipt_default', $data);
        $row    = $this->rowContaining($output, lang('Sales.total_to_pay'));

        $this->assertNotSame('', $row);
        $this->assertStringContainsString(to_currency(-8.0), $row);
        $this->assertStringNotContainsString(lang('Sales.change_due'), $output);
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
     * Builds the smallest valid data set for rendering the default receipt.
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
