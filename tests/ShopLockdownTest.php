<?php

namespace Tests;

use App\Filters\ShopLockdownFilter;
use App\Libraries\Sale_lib;
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
     * Returns 404 for every removed module when its route is typed directly.
     */
    public function testRemovedModuleRoutesReturnNotFound(): void
    {
        $filter = new ShopLockdownFilter();

        foreach (ShopLockdown::REMOVED_MODULES as $module) {
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
     * Applies the lockdown filter globally before controller routing.
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
     * Keeps the non-admin grants exactly aligned with the accepted decision.
     */
    public function testNonAdminGrantSetMatchesTheDecision(): void
    {
        $this->assertSame([
            'items',
            'reports',
            'reports_items',
            'reports_inventory',
            'reports_sales',
            'reports_sales_taxes',
            'reports_taxes',
            'reports_payments',
            'reports_categories',
            'sales',
            'home',
        ], ShopLockdown::NON_ADMIN_GRANTS);
    }
}
