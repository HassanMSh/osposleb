<?php

namespace Tests;

use App\Controllers\Sales as SalesController;
use App\Libraries\Sale_lib;
use App\Libraries\Tax_lib;
use CodeIgniter\Config\Factories;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\OSPOS;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;

/**
 * Covers the register's reduced cart-row request when editing a sale item.
 *
 * @internal
 */
final class TillEditItemTest extends CIUnitTestCase
{
    /**
     * Loads locale helpers and injects the settings used by the edit path.
     */
    protected function setUp(): void
    {
        parent::setUp();

        helper(['currency', 'locale']);

        $ospos           = (new ReflectionClass(OSPOS::class))->newInstanceWithoutConstructor();
        $ospos->settings = [
            'currency_decimals'   => '2',
            'lbp_exchange_rate'   => '90000',
            'number_locale'       => 'en_US',
            'quantity_decimals'   => '0',
            'thousands_separator' => '1',
        ];
        Factories::injectMock('config', OSPOS::class, $ospos);
    }

    /**
     * Removes the cart shared by the controller and its sale library.
     */
    protected function tearDown(): void
    {
        session()->remove('sales_cart');
        session()->remove('sales_location');

        parent::tearDown();
    }

    /**
     * Stores a changed quantity when the removed discount input is absent.
     */
    public function testEditingCartLineWithoutDiscountStoresNewQuantity(): void
    {
        [$controller, $saleLibrary, $reloadException] = $this->makeController([
            'location'     => '1',
            'item_id'      => '7',
            'price'        => '1080000',
            'quantity'     => '2.00',
            'description'  => '',
            'serialnumber' => '',
        ]);

        $this->invokeEditItem($controller, $reloadException);

        $cart = $saleLibrary->get_cart();

        $this->assertSame('2', $cart[1]['quantity']);
        $this->assertSame(12.0, (float) $cart[1]['price']);
        $this->assertSame('0', $cart[1]['discount']);
    }

    /**
     * Converts an edited LBP cart price to the stored dollar value.
     */
    public function testEditingCartPriceConvertsPoundsToDollars(): void
    {
        [$controller, $saleLibrary, $reloadException] = $this->makeController([
            'location'     => '1',
            'item_id'      => '7',
            'price'        => '120000',
            'quantity'     => '1',
            'description'  => '',
            'serialnumber' => '',
        ]);

        $this->invokeEditItem($controller, $reloadException);

        $this->assertSame('1.33', $saleLibrary->get_cart()[1]['price']);
    }

    /**
     * Keeps a stored one-dollar price when a quantity edit posts its rounded LBP display.
     */
    #[DataProvider('priceInputVisibilityProvider')]
    public function testQuantityEditAtRate89500KeepsStoredPrice(bool $hiddenPriceInput): void
    {
        $source = file_get_contents(APPPATH . 'Views/sales/register.php');
        $this->assertIsString($source);

        $expectedPriceInput = $hiddenPriceInput
            ? "'type' => 'hidden', 'name' => 'price', 'value' => format_lbp_input(\$lbp_line['price_unit_lbp'])"
            : "'name' => 'price', 'class' => 'form-control input-sm', 'dir' => 'ltr', 'value' => format_lbp_input(\$lbp_line['price_unit_lbp'])";
        $this->assertStringContainsString($expectedPriceInput, $source);

        [$controller, $saleLibrary, $reloadException] = $this->makeController([
            'location'     => '1',
            'item_id'      => '7',
            'price'        => '90000',
            'quantity'     => '2',
            'description'  => '',
            'serialnumber' => '',
        ], exchangeRate: '89500');

        $cart                        = $saleLibrary->get_cart();
        $cart[1]['price']            = '1.00';
        $cart[1]['total']            = '1';
        $cart[1]['discounted_total'] = '1';
        $saleLibrary->set_cart($cart);

        $this->invokeEditItem($controller, $reloadException);

        $this->assertSame('1.00', $saleLibrary->get_cart()[1]['price'], $hiddenPriceInput ? 'Hidden price input' : 'Visible price input');
    }

    /**
     * Names the two register price fields that post the same LBP value to the edit endpoint.
     */
    public static function priceInputVisibilityProvider(): array
    {
        return [
            'visible price input' => [false],
            'hidden price input'  => [true],
        ];
    }

    /**
     * Keeps the stored amount-entry total separate when a quantity-only request omits that input.
     */
    public function testQuantityOnlyEditDoesNotReplaceDiscountedTotal(): void
    {
        [$controller, $saleLibrary, $reloadException] = $this->makeController([
            'location'     => '1',
            'item_id'      => '7',
            'price'        => '900000',
            'quantity'     => '2',
            'description'  => '',
            'serialnumber' => '',
        ], mockEditItem: true);

        $cart                 = $saleLibrary->get_cart();
        $cart[1]['item_type'] = ITEM_AMOUNT_ENTRY;
        $saleLibrary->set_cart($cart);
        $edit_item_arguments = [];
        $saleLibrary->expects($this->once())
            ->method('edit_item')
            ->willReturnCallback(static function (...$arguments) use (&$edit_item_arguments): bool {
                $edit_item_arguments = $arguments;

                return false;
            });

        $this->invokeEditItem($controller, $reloadException);

        $this->assertNull($edit_item_arguments[7]);
        $this->assertSame('10', $saleLibrary->get_cart()[1]['discounted_total']);
    }

    /**
     * Preserves the edited amount-entry total with included tax and with excluded 11 percent tax.
     */
    #[DataProvider('amountEntryTaxProvider')]
    public function testEditedAmountEntryTotalKeepsTypedLbpAmount(string $taxType, string $postedTotal, float $initialTax, float $updatedTax, int $expectedLbpTotal): void
    {
        $post = [
            'location'         => '1',
            'item_id'          => '7',
            'price'            => '900000',
            'quantity'         => '1',
            'discounted_total' => $postedTotal,
            'description'      => '',
            'serialnumber'     => '',
        ];
        $tax_rows = [[
            'line'            => 1,
            'tax_type'        => $taxType,
            'item_tax_amount' => $initialTax,
        ]];

        [$controller, $saleLibrary, $reloadException] = $this->makeController($post, itemTaxes: $tax_rows);

        $cart                        = $saleLibrary->get_cart();
        $cart[1]['item_type']        = ITEM_AMOUNT_ENTRY;
        $cart[1]['price']            = '10';
        $cart[1]['total']            = '10';
        $cart[1]['discounted_total'] = '10';
        $saleLibrary->set_cart($cart);

        $this->invokeEditItem($controller, $reloadException);

        $updated_cart = $saleLibrary->get_cart();
        $this->assertSame('2', $updated_cart[1]['quantity']);
        $this->assertSame(20.0, (float) $updated_cart[1]['discounted_total']);

        $updated_tax_rows = [[
            'line'            => 1,
            'tax_type'        => $taxType,
            'item_tax_amount' => $updatedTax,
        ]];
        $totals = get_lbp_cart_totals($updated_cart, $updated_tax_rows, 90_000);

        $this->assertSame($expectedLbpTotal, $totals['total']);
    }

    /**
     * Provides pound totals for both tax-included and 11 percent tax-excluded amount entries.
     */
    public static function amountEntryTaxProvider(): array
    {
        return [
            'included tax'            => [Tax_lib::TAX_TYPE_INCLUDED, '1800000', 2.0, 2.0, 1_800_000],
            '11 percent excluded tax' => [Tax_lib::TAX_TYPE_EXCLUDED, '1998000', 1.1, 2.2, 1_998_000],
        ];
    }

    /**
     * Refuses till edits at missing or unusable exchange rates and leaves the cart line unchanged.
     */
    #[DataProvider('invalidExchangeRateProvider')]
    public function testEditingCartRefusesAnInvalidExchangeRate(?string $exchangeRate): void
    {
        $post = [
            'location'     => '1',
            'item_id'      => '7',
            'price'        => '900000',
            'quantity'     => '2',
            'description'  => '',
            'serialnumber' => '',
        ];

        [$controller, $saleLibrary, $reloadException] = $this->makeController($post, expectOutOfStock: false, exchangeRate: $exchangeRate);
        $cartBefore                                   = $saleLibrary->get_cart();

        $this->invokeEditItem($controller, $reloadException);

        $this->assertSame($cartBefore, $saleLibrary->get_cart());
        $source = file_get_contents(APPPATH . 'Controllers/Sales.php');
        $this->assertIsString($source);
        $this->assertStringContainsString("lang('Common.lbp_rate_missing')", $source);
    }

    /**
     * Supplies exchange-rate values that must stop till edits before the cart changes.
     */
    public static function invalidExchangeRateProvider(): array
    {
        return [
            'missing'      => [null],
            'not a number' => ['bad-rate'],
            'zero'         => ['0'],
            'negative'     => ['-1'],
        ];
    }

    /**
     * Stores zero when the removed discount input is posted blank.
     */
    public function testEditingCartLineWithBlankDiscountStoresZero(): void
    {
        [$controller, $saleLibrary, $reloadException] = $this->makeController([
            'location'     => '1',
            'item_id'      => '7',
            'price'        => '1080000',
            'quantity'     => '2.00',
            'discount'     => '',
            'description'  => '',
            'serialnumber' => '',
        ]);

        $this->invokeEditItem($controller, $reloadException);

        $this->assertSame('0', $saleLibrary->get_cart()[1]['discount']);
    }

    /**
     * Keeps an explicitly posted discount when the legacy input is present.
     */
    public function testEditingCartLineWithExplicitDiscountAppliesDiscount(): void
    {
        [$controller, $saleLibrary, $reloadException] = $this->makeController([
            'location'      => '1',
            'item_id'       => '7',
            'price'         => '1080000',
            'quantity'      => '2.00',
            'discount'      => '1.50',
            'discount_type' => (string) FIXED,
            'description'   => '',
            'serialnumber'  => '',
        ]);

        $this->invokeEditItem($controller, $reloadException);

        $cart = $saleLibrary->get_cart();

        $this->assertSame('1.5', $cart[1]['discount']);
        $this->assertSame((string) FIXED, $cart[1]['discount_type']);
        $this->assertSame(21.0, (float) $cart[1]['discounted_total']);
    }

    /**
     * Uses the cart line location when a hand-built request omits the location.
     */
    public function testEditingCartLineWithoutLocationDoesNotThrow(): void
    {
        [$controller, $saleLibrary, $reloadException] = $this->makeController([
            'item_id'      => '7',
            'price'        => '1080000',
            'quantity'     => '2.00',
            'description'  => '',
            'serialnumber' => '',
        ]);

        $this->invokeEditItem($controller, $reloadException);

        $this->assertSame('2', $saleLibrary->get_cart()[1]['quantity']);
    }

    /**
     * Treats a blank location as absent and completes the cart edit.
     */
    public function testEditingCartLineWithBlankLocationDoesNotThrowOrPartiallyEdit(): void
    {
        [$controller, $saleLibrary, $reloadException] = $this->makeController([
            'location'     => '',
            'item_id'      => '7',
            'price'        => '1080000',
            'quantity'     => '2.00',
            'description'  => '',
            'serialnumber' => '',
        ]);

        $this->invokeEditItem($controller, $reloadException);

        $cart = $saleLibrary->get_cart();

        $this->assertSame('2', $cart[1]['quantity']);
        $this->assertSame(12.0, (float) $cart[1]['price']);
        $this->assertSame('0', $cart[1]['discount']);
        $this->assertSame('24', $cart[1]['total']);
        $this->assertSame('24', $cart[1]['discounted_total']);
    }

    /**
     * Falls back to the cart line location when a non-integer location is posted.
     */
    public function testEditingCartLineWithNonIntegerLocationUsesCartLineLocation(): void
    {
        [$controller, $saleLibrary, $reloadException] = $this->makeController([
            'location'     => '+',
            'item_id'      => '7',
            'price'        => '1080000',
            'quantity'     => '2.00',
            'discount'     => '',
            'description'  => '',
            'serialnumber' => '',
        ]);

        $this->invokeEditItem($controller, $reloadException);

        $cart = $saleLibrary->get_cart();

        $this->assertSame('2', $cart[1]['quantity']);
        $this->assertSame(1, $cart[1]['item_location']);
    }

    /**
     * Uses the sale location when the cart line has no location.
     */
    public function testEditingCartLineWithoutLocationUsesSaleLocation(): void
    {
        [$controller, $saleLibrary, $reloadException] = $this->makeController([
            'item_id'      => '7',
            'price'        => '1080000',
            'quantity'     => '2.00',
            'description'  => '',
            'serialnumber' => '',
        ], null, 2);

        $this->invokeEditItem($controller, $reloadException);

        $cart = $saleLibrary->get_cart();

        $this->assertArrayNotHasKey('item_location', $cart[1]);
        $this->assertSame('2', $cart[1]['quantity']);
    }

    /**
     * Posts the exact reduced field set generated by the register row form.
     */
    public function testRegisterRowFieldSetPostsWithoutRemovedInputs(): void
    {
        $post = [
            'location'     => '1',
            'item_id'      => '7',
            'price'        => '1080000',
            'quantity'     => '2.00',
            'description'  => '',
            'serialnumber' => '',
        ];

        [$controller, $saleLibrary, $reloadException] = $this->makeController($post);

        $this->invokeEditItem($controller, $reloadException);

        $this->assertSame('2', $saleLibrary->get_cart()[1]['quantity']);
        $this->assertArrayNotHasKey('discount', $post);
        $this->assertArrayNotHasKey('discount_type', $post);
    }

    /**
     * Shows the edit error and preserves the cart when the request body is empty.
     */
    public function testEditingCartLineWithEmptyPostShowsErrorAndKeepsCartUnchanged(): void
    {
        [$controller, $saleLibrary, $reloadException] = $this->makeController([], expectOutOfStock: false);
        $cartBefore                                   = $saleLibrary->get_cart();

        $this->invokeEditItem($controller, $reloadException);

        $this->assertSame($cartBefore, $saleLibrary->get_cart());
        $this->assertMissingEditFieldsUseTheEditError();
    }

    /**
     * Shows the edit error and preserves the cart when quantity is missing.
     */
    public function testEditingCartLineWithoutQuantityShowsErrorAndKeepsCartUnchanged(): void
    {
        $post = [
            'location'     => '1',
            'item_id'      => '7',
            'price'        => '1080000',
            'description'  => '',
            'serialnumber' => '',
        ];

        [$controller, $saleLibrary, $reloadException] = $this->makeController($post, expectOutOfStock: false);
        $cartBefore                                   = $saleLibrary->get_cart();

        $this->invokeEditItem($controller, $reloadException);

        $this->assertSame($cartBefore, $saleLibrary->get_cart());
        $this->assertMissingEditFieldsUseTheEditError();
    }

    /**
     * Shows the edit error and preserves the cart when price is missing.
     */
    public function testEditingCartLineWithoutPriceShowsErrorAndKeepsCartUnchanged(): void
    {
        $post = [
            'location'     => '1',
            'item_id'      => '7',
            'quantity'     => '2.00',
            'description'  => '',
            'serialnumber' => '',
        ];

        [$controller, $saleLibrary, $reloadException] = $this->makeController($post, expectOutOfStock: false);
        $cartBefore                                   = $saleLibrary->get_cart();

        $this->invokeEditItem($controller, $reloadException);

        $this->assertSame($cartBefore, $saleLibrary->get_cart());
        $this->assertMissingEditFieldsUseTheEditError();
    }

    /**
     * Refuses a quantity of zero and keeps the cart line unchanged (issue #47).
     */
    public function testEditingCartLineToZeroQuantityKeepsCartUnchanged(): void
    {
        foreach (['0', '0.00', '0.0001'] as $quantity) {
            [$controller, $saleLibrary, $reloadException] = $this->makeController([
                'location'     => '1',
                'item_id'      => '7',
                'price'        => '900000',
                'quantity'     => $quantity,
                'description'  => '',
                'serialnumber' => '',
            ], expectOutOfStock: false);
            $cartBefore = $saleLibrary->get_cart();

            $this->invokeEditItem($controller, $reloadException);

            $this->assertSame($cartBefore, $saleLibrary->get_cart(), "Quantity {$quantity} changed the cart.");
        }
    }

    /**
     * Still stores a negative quantity, because returns use negative lines.
     */
    public function testEditingCartLineToNegativeQuantityStillWorks(): void
    {
        [$controller, $saleLibrary, $reloadException] = $this->makeController([
            'location'     => '1',
            'item_id'      => '7',
            'price'        => '900000',
            'quantity'     => '-1',
            'description'  => '',
            'serialnumber' => '',
        ]);

        $this->invokeEditItem($controller, $reloadException);

        $this->assertSame('-1', $saleLibrary->get_cart()[1]['quantity']);
    }

    /**
     * Requires every input rendered in a cart line to name that line's form.
     */
    public function testEveryCartInputNamesItsLineForm(): void
    {
        $source = file_get_contents(APPPATH . 'Views/sales/register.php');

        $this->assertIsString($source);

        $cartStart = strpos($source, 'foreach ($cart_display as $line => $item)');
        $cartEnd   = strpos($source, '<?= form_close() ?>', $cartStart);

        $this->assertNotFalse($cartStart);
        $this->assertNotFalse($cartEnd);

        $cartSource = substr($source, $cartStart, $cartEnd - $cartStart);
        $inputCount = preg_match_all('/form_input\(\[([^\r\n]*)\]\)/', $cartSource, $inputMatches);

        $this->assertGreaterThan(0, $inputCount);
        $this->assertStringNotContainsString('form_hidden(', $cartSource);

        foreach ($inputMatches[1] as $inputAttributes) {
            $this->assertStringContainsString('\'form\' => "cart_{$line}"', $inputAttributes);
        }
    }

    /**
     * Requires missing price or quantity to take the normal edit-error reload path.
     */
    private function assertMissingEditFieldsUseTheEditError(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Sales.php');

        $this->assertIsString($source);
        $this->assertMatchesRegularExpression(
            '/if \(! is_string\(\$price\) \|\| ! is_string\(\$quantity\)\) \{\s*\$data\[\'error\'\] = lang\(\'Sales\.error_editing_item\'\);\s*\$this->_reload\(\$data\);\s*return;\s*\}/',
            $source,
        );
    }

    /**
     * Builds a controller with a cart line and stops when the register reload starts.
     *
     * @param array       $post             Posted register fields.
     * @param int|null    $cartLineLocation Location stored on the cart line.
     * @param int         $saleLocation     Location used when the line has none.
     * @param bool        $expectOutOfStock Whether a valid edit must reach the stock check.
     * @param string|null $exchangeRate     Exchange rate used by the edit controller.
     * @param array       $itemTaxes        Per-line taxes returned by the tax-library mock.
     * @param bool        $mockEditItem     Whether to intercept the cart edit call.
     *
     * @return array{0: SalesController, 1: Sale_lib, 2: RuntimeException}
     */
    private function makeController(array $post, ?int $cartLineLocation = 1, int $saleLocation = 1, bool $expectOutOfStock = true, ?string $exchangeRate = '90000', array $itemTaxes = [], bool $mockEditItem = false): array
    {
        $reloadException = new RuntimeException('register reload reached');
        $mocked_methods  = ['out_of_stock', 'reset_cash_rounding'];

        if ($mockEditItem) {
            $mocked_methods[] = 'edit_item';
        }

        $saleLibrary = $this->getMockBuilder(Sale_lib::class)
            ->onlyMethods($mocked_methods)
            ->getMock();

        $expectedLocation = $cartLineLocation ?? $saleLocation;
        if ($expectOutOfStock) {
            $saleLibrary->expects($this->once())
                ->method('out_of_stock')
                ->with(7, $expectedLocation)
                ->willReturn('');
        } else {
            $saleLibrary->expects($this->never())
                ->method('out_of_stock');
        }
        $saleLibrary->method('reset_cash_rounding')->willThrowException($reloadException);
        $saleLibrary->set_sale_location($saleLocation);

        $cartLine = [
            'item_id'          => 7,
            'description'      => '',
            'serialnumber'     => '',
            'quantity'         => '1',
            'price'            => '900000',
            'discount'         => '0',
            'discount_type'    => PERCENT,
            'total'            => '10',
            'discounted_total' => '10',
        ];
        if ($cartLineLocation !== null) {
            $cartLine['item_location'] = $cartLineLocation;
        }

        $saleLibrary->set_cart([1 => $cartLine]);

        $taxLibrary = $this->createMock(Tax_lib::class);
        $taxLibrary->method('get_taxes')->willReturn([[], $itemTaxes]);

        $request = new IncomingRequest(new App(), new URI('/sales/editItem/1'), null, new UserAgent());
        $request->setGlobal('post', $post);
        $request->setGlobal('request', $post);
        $this->assertSame($post, $request->getPost());

        $controller = (new ReflectionClass(SalesController::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionClass($controller);

        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($controller, $request);

        $sessionProperty = $reflection->getProperty('session');
        $sessionProperty->setAccessible(true);
        $sessionProperty->setValue($controller, session());

        $saleLibraryProperty = $reflection->getProperty('sale_lib');
        $saleLibraryProperty->setAccessible(true);
        $saleLibraryProperty->setValue($controller, $saleLibrary);

        $taxLibraryProperty = $reflection->getProperty('tax_lib');
        $taxLibraryProperty->setAccessible(true);
        $taxLibraryProperty->setValue($controller, $taxLibrary);

        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $config = config(OSPOS::class)->settings;
        if ($exchangeRate === null) {
            unset($config['lbp_exchange_rate']);
        } else {
            $config['lbp_exchange_rate'] = $exchangeRate;
        }
        $configProperty->setValue($controller, $config);

        return [$controller, $saleLibrary, $reloadException];
    }

    /**
     * Runs the edit action and confirms execution reached its normal reload point.
     */
    private function invokeEditItem(SalesController $controller, RuntimeException $reloadException): void
    {
        try {
            $controller->postEditItem('1');
        } catch (RuntimeException $exception) {
            $this->assertSame($reloadException, $exception);

            return;
        }

        $this->fail('The controller did not reach the register reload.');
    }
}
