<?php

namespace Tests;

use App\Filters\ShopLockdownFilter;
use App\Libraries\Sale_lib;
use App\Models\Sale;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Filters;
use Config\ShopLockdown;
use ReflectionClass;

/**
 * Covers the shop lockdown policy and the simplified register contract.
 *
 * @internal
 */
final class ShopLockdownTest extends CIUnitTestCase
{
    /**
     * Loads the helpers used by the register policy tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        helper('locale');
    }

    /**
     * Keeps every removed module out of the configured application module set.
     */
    public function testRemovedModulesAreAbsentFromTheModulePolicy(): void
    {
        $this->assertSame([
            'customers',
            'item_kits',
            'suppliers',
            'receivings',
            'giftcards',
            'messages',
            'expenses',
            'expenses_categories',
            'cashups',
            'office',
        ], ShopLockdown::REMOVED_MODULES);
    }

    /**
     * Returns 404 for removed modules despite route case and repeated encoding.
     */
    public function testRemovedModuleRoutesReturnNotFound(): void
    {
        $filter = new ShopLockdownFilter();

        foreach (array_merge(ShopLockdown::REMOVED_MODULES, ['Customers', '%2563ustomers', 'customers%252Fsearch']) as $module) {
            $request  = new IncomingRequest(new App(), new URI('/' . $module . '/index'), null, new UserAgent());
            $response = $filter->before($request);

            $this->assertInstanceOf(ResponseInterface::class, $response, $module);
            $this->assertSame(404, $response->getStatusCode(), $module);
        }
    }

    /**
     * Leaves non-removed routes available to the normal router.
     */
    public function testNonRemovedRoutesPassThrough(): void
    {
        $request = new IncomingRequest(new App(), new URI('/sales/index'), null, new UserAgent());

        $this->assertNull((new ShopLockdownFilter())->before($request));
    }

    /**
     * Applies the lockdown filter globally before controller execution.
     */
    public function testLockdownFilterIsRegisteredGlobally(): void
    {
        $filters = new Filters();

        $this->assertSame(ShopLockdownFilter::class, $filters->aliases['shoplockdown']);
        $this->assertContains('shoplockdown', $filters->globals['before']);
    }

    /**
     * Exposes cash as the only payment option in the register.
     */
    public function testPaymentOptionsContainCashAndNothingElse(): void
    {
        $cash = lang('Sales.cash');

        $this->assertSame([$cash => $cash], get_payment_options());
    }

    /**
     * Exposes only cash options while editing an existing sale.
     */
    public function testExistingSalePaymentOptionsContainCashOnly(): void
    {
        $cash = lang('Sales.cash');

        $this->assertSame([$cash => $cash], (new Sale())->get_payment_options(false, false));
    }

    /**
     * Rejects a non-cash payment before the sale model can write it.
     */
    public function testSaleUpdateRejectsNonCashPayments(): void
    {
        $sale = new Sale();

        foreach (['Credit', lang('Sales.rewards'), lang('Sales.cash_adjustment')] as $payment_type) {
            $this->assertFalse($sale->update(1, [
                'payments' => [
                    ['payment_type' => $payment_type],
                ],
            ]), $payment_type);
        }

        $sale_status = COMPLETED;
        $items       = [];
        $payments    = [['payment_type' => 'Credit']];
        $sales_taxes = [[], []];

        $this->assertSame(-1, $sale->save_value(
            NEW_ENTRY,
            $sale_status,
            $items,
            NEW_ENTRY,
            1,
            '',
            null,
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $sales_taxes,
        ));
    }

    /**
     * Accepts a cash payment recorded in another language.
     *
     * The shop runs in Arabic and the owner account runs in English, so an
     * owner editing a cashier's sale posts back the Arabic label.
     */
    public function testCashLabelsCoverEveryInstalledLanguage(): void
    {
        $labels = get_translated_payment_labels('Sales.cash');

        $this->assertContains('Cash', $labels);
        $this->assertContains(lang('Sales.cash', [], 'ar-LB'), $labels);
        $this->assertContains(lang('Sales.cash', [], 'ar-EG'), $labels);
        $this->assertNotContains(lang('Sales.credit'), $labels);
        $this->assertNotContains('', $labels);
    }

    /**
     * Accepts an Arabic cash sale through the model guard and rejects a card.
     */
    public function testModelAcceptsCashRecordedInArabicAndRejectsCard(): void
    {
        $sale   = new Sale();
        $method = (new ReflectionClass(Sale::class))->getMethod('hasOnlyCashPayments');
        $method->setAccessible(true);

        $arabic = lang('Sales.cash', [], 'ar-LB');

        $this->assertTrue($method->invoke($sale, [['payment_type' => 'Cash']]));
        $this->assertTrue($method->invoke($sale, [['payment_type' => $arabic]]));
        $this->assertFalse($method->invoke($sale, [['payment_type' => lang('Sales.credit')]]));
    }

    /**
     * Exposes exactly receipt, invoice, and return register modes.
     */
    public function testRegisterModesAreExactlyTheAllowedThree(): void
    {
        $saleLibrary = (new ReflectionClass(Sale_lib::class))->newInstanceWithoutConstructor();

        $this->assertSame([
            'sale'         => lang('Sales.receipt'),
            'sale_invoice' => lang('Sales.invoice'),
            'return'       => lang('Sales.return'),
        ], $saleLibrary->get_register_mode_options());
    }

    /**
     * Falls back when a removed register mode remains in the session.
     */
    public function testRemovedRegisterModeFallsBackToReceipt(): void
    {
        $saleLibrary = (new ReflectionClass(Sale_lib::class))->newInstanceWithoutConstructor();
        $session     = session();
        $session->set('sales_mode', 'sale_quote');

        $property = (new ReflectionClass(Sale_lib::class))->getProperty('session');
        $property->setValue($saleLibrary, $session);

        $this->assertSame('sale', $saleLibrary->get_mode());
        $this->assertSame('sale', $session->get('sales_mode'));
    }
}
