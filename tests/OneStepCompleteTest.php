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
use ReflectionClass;
use RuntimeException;

/**
 * Covers one-step cash payment and sale completion without a database.
 *
 * @internal
 */
final class OneStepCompleteTest extends CIUnitTestCase
{
    /**
     * Loads locale helpers and injects the number settings used by payment parsing.
     */
    protected function setUp(): void
    {
        parent::setUp();

        helper('locale');

        $ospos           = (new ReflectionClass(OSPOS::class))->newInstanceWithoutConstructor();
        $ospos->settings = [
            'currency_decimals'   => '2',
            'number_locale'       => 'en_US',
            'quantity_decimals'   => '0',
            'thousands_separator' => '1',
        ];
        Factories::injectMock('config', OSPOS::class, $ospos);
    }

    /**
     * Clears the sale session values shared by the controller and Sale_lib.
     */
    protected function tearDown(): void
    {
        session()->remove('sales_cart');
        session()->remove('sales_payments');
        session()->remove('sale_id');
        session()->remove('sales_location');
        session()->remove('cash_mode');
        session()->remove('cash_rounding');
        session()->remove('payment_type');

        parent::tearDown();
    }

    /**
     * Completes a sale when the posted payment covers its total.
     */
    public function testFullPaymentCompletesSale(): void
    {
        [$controller, $saleLibrary] = $this->makeController('11.10', true, true, '11.10', '11.10');
        $controller->expects($this->once())->method('postComplete');

        $saleLibrary->expects($this->once())
            ->method('add_payment')
            ->with(lang('Sales.cash'), '11.1');

        $controller->postAddPaymentAndComplete();
    }

    /**
     * Reloads a partial payment without completing the sale.
     */
    public function testPartialPaymentReloadsWithoutCompletingSale(): void
    {
        [$controller, $saleLibrary] = $this->makeController('4.00', true, false, '11.10', '11.10');
        $reloadException            = new RuntimeException('register reload reached');

        $controller->expects($this->never())->method('postComplete');
        $saleLibrary->expects($this->once())
            ->method('add_payment')
            ->with(lang('Sales.cash'), '4');
        $saleLibrary->expects($this->exactly(2))
            ->method('reset_cash_rounding')
            ->willReturnOnConsecutiveCalls(0, $this->throwException($reloadException));

        try {
            $controller->postAddPaymentAndComplete();
        } catch (RuntimeException $exception) {
            $this->assertSame($reloadException, $exception);

            return;
        }

        $this->fail('The partial payment did not reload the register.');
    }

    /**
     * Reloads an invalid amount with the existing error and records no payment.
     */
    public function testInvalidAmountReloadsWithErrorAndAddsNoPayment(): void
    {
        [$controller, $saleLibrary] = $this->makeController('not-money', false, false, '10.00', '10.00', 2);
        $reloadException            = new RuntimeException('register reload reached');

        $controller->expects($this->never())->method('postComplete');
        $saleLibrary->expects($this->never())->method('add_payment');
        $saleLibrary->expects($this->once())
            ->method('reset_cash_rounding')
            ->willThrowException($reloadException);

        $reflection = new ReflectionClass(SalesController::class);
        $method     = $reflection->getMethod('recordPayment');
        $method->setAccessible(true);
        $data = $method->invoke($controller);

        $this->assertSame(lang('Sales.must_enter_numeric'), $data['error']);

        try {
            $controller->postAddPaymentAndComplete();
        } catch (RuntimeException $exception) {
            $this->assertSame($reloadException, $exception);

            return;
        }

        $this->fail('The invalid amount did not reload the register.');
    }

    /**
     * Records a negative cash payment and completes a return.
     */
    public function testNegativeFullPaymentCompletesReturn(): void
    {
        [$controller, $saleLibrary] = $this->makeController('-11.10', true, true, '-11.10', '-11.10');
        $saleLibrary->set_mode('return');

        $controller->expects($this->once())->method('postComplete');
        $saleLibrary->expects($this->once())
            ->method('add_payment')
            ->with(lang('Sales.cash'), '-11.1');

        $controller->postAddPaymentAndComplete();

        $this->assertSame('return', $saleLibrary->get_mode());
    }

    /**
     * Refuses a cart with a zero-quantity line before recording the payment (issue #47).
     */
    public function testZeroQuantityLineReloadsWithoutPaymentOrCompletion(): void
    {
        $saleLibrary = $this->getMockBuilder(Sale_lib::class)
            ->onlyMethods(['add_payment', 'get_cart', 'reset_cash_rounding'])
            ->getMock();
        $saleLibrary->method('get_cart')->willReturn([
            1 => ['item_id' => 7, 'quantity' => '1', 'price' => '10'],
            2 => ['item_id' => 8, 'quantity' => '0.000', 'price' => '10'],
        ]);
        $saleLibrary->expects($this->never())->method('add_payment');

        $reloadException = new RuntimeException('register reload reached');
        $saleLibrary->expects($this->once())
            ->method('reset_cash_rounding')
            ->willThrowException($reloadException);

        $controller = $this->getMockBuilder(SalesController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['validate', 'postComplete'])
            ->getMock();
        $controller->expects($this->never())->method('validate');
        $controller->expects($this->never())->method('postComplete');

        $reflection = new ReflectionClass(SalesController::class);
        $reflection->getProperty('session')->setValue($controller, session());
        $reflection->getProperty('sale_lib')->setValue($controller, $saleLibrary);

        try {
            $controller->postAddPaymentAndComplete();
        } catch (RuntimeException $exception) {
            $this->assertSame($reloadException, $exception);

            return;
        }

        $this->fail('The zero-quantity cart did not reload the register.');
    }

    /**
     * Refuses to complete a sale directly while a cart line has a quantity of zero.
     */
    public function testCompleteRefusesZeroQuantityLine(): void
    {
        $saleLibrary = $this->getMockBuilder(Sale_lib::class)
            ->onlyMethods(['get_cart', 'get_sale_id', 'reset_cash_rounding'])
            ->getMock();
        $saleLibrary->method('get_cart')->willReturn([1 => ['item_id' => 7, 'quantity' => 0.0, 'price' => '10']]);
        $saleLibrary->expects($this->never())->method('get_sale_id');

        $reloadException = new RuntimeException('register reload reached');
        $saleLibrary->method('reset_cash_rounding')->willThrowException($reloadException);

        $controller = (new ReflectionClass(SalesController::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionClass(SalesController::class);
        $reflection->getProperty('session')->setValue($controller, session());
        $reflection->getProperty('sale_lib')->setValue($controller, $saleLibrary);

        try {
            $controller->postComplete();
        } catch (RuntimeException $exception) {
            $this->assertSame($reloadException, $exception);

            return;
        }

        $this->fail('The zero-quantity cart was not refused.');
    }

    /**
     * Builds the mocked sales controller, sale totals, taxes, and payment request.
     *
     * @return array{0: SalesController, 1: Sale_lib}
     */
    private function makeController(
        string $amount,
        bool $valid,
        bool $paymentsCoverTotal,
        string $total,
        string $salesTotal,
        int $validationCalls = 1,
    ): array {
        $cart = [
            1 => [
                'item_id'  => 7,
                'quantity' => '1',
                'price'    => '10',
            ],
        ];
        $taxes       = [['sale_tax_amount' => $total[0] === '-' ? '-1.10' : '1.10']];
        $saleLibrary = $this->getMockBuilder(Sale_lib::class)
            ->onlyMethods(['get_total', 'add_payment', 'get_cart', 'get_totals', 'reset_cash_rounding'])
            ->getMock();
        if ($valid) {
            $saleLibrary->expects($this->exactly(2))
                ->method('get_total')
                ->willReturnOnConsecutiveCalls($total, $salesTotal);
            $saleLibrary->expects($this->exactly(2))->method('get_cart')->willReturn($cart);
            $saleLibrary->expects($this->once())
                ->method('get_totals')
                ->with($taxes)
                ->willReturn(['payments_cover_total' => $paymentsCoverTotal]);

            if ($paymentsCoverTotal) {
                $saleLibrary->expects($this->once())->method('reset_cash_rounding')->willReturn(0);
            }
        } else {
            $saleLibrary->expects($this->never())->method('get_total');
            $saleLibrary->expects($this->once())->method('get_cart')->willReturn($cart);
            $saleLibrary->expects($this->never())->method('get_totals');
        }

        $taxLibrary = $this->createMock(Tax_lib::class);
        if ($valid) {
            $taxLibrary->expects($this->once())->method('get_taxes')->with($cart)->willReturn([$taxes]);
        } else {
            $taxLibrary->expects($this->never())->method('get_taxes');
        }

        $controller = $this->getMockBuilder(SalesController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['validate', 'postComplete'])
            ->getMock();
        $controller->expects($this->exactly($validationCalls))
            ->method('validate')
            ->with(
                ['amount_tendered' => 'trim|required|decimal_locale'],
                ['amount_tendered' => lang('Sales.must_enter_numeric')],
            )
            ->willReturn($valid);

        $request = new IncomingRequest(new App(), new URI('/sales/addPaymentAndComplete'), null, new UserAgent());
        $request->setGlobal('post', ['amount_tendered' => $amount]);
        $request->setGlobal('request', ['amount_tendered' => $amount]);

        $reflection = new ReflectionClass(SalesController::class);
        $reflection->getProperty('request')->setValue($controller, $request);
        $reflection->getProperty('session')->setValue($controller, session());
        $reflection->getProperty('sale_lib')->setValue($controller, $saleLibrary);
        $reflection->getProperty('tax_lib')->setValue($controller, $taxLibrary);

        return [$controller, $saleLibrary];
    }
}
