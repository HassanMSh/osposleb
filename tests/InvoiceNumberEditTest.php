<?php

namespace Tests;

use App\Controllers\Sales as SalesController;
use App\Models\Employee;
use App\Models\Inventory;
use App\Models\Sale;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\ResultInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use ReflectionClass;
use ReflectionProperty;

/**
 * Covers how the sale edit form stores and checks the invoice number (issue #48).
 *
 * @internal
 */
final class InvoiceNumberEditTest extends CIUnitTestCase
{
    /**
     * Removes the injected inventory model after each test.
     */
    protected function tearDown(): void
    {
        Factories::reset('models');

        parent::tearDown();
    }

    /**
     * Stores an invoice number with an ampersand as typed, not HTML-encoded.
     */
    public function testSaveStoresInvoiceNumberAsTyped(): void
    {
        $saved = $this->saveSale(' INV&1 <b> ');

        $this->assertSame('INV&1 <b>', $saved['invoice_number']);
    }

    /**
     * Stores a blank invoice number as null, so the sale has no invoice number.
     */
    public function testSaveStoresBlankInvoiceNumberAsNull(): void
    {
        $this->assertNull($this->saveSale('   ')['invoice_number']);
        $this->assertNull($this->saveSale('')['invoice_number']);
    }

    /**
     * Looks up duplicates with the invoice number as typed, matching how it is stored.
     */
    public function testDuplicateCheckUsesInvoiceNumberAsTyped(): void
    {
        $sale = $this->createMock(Sale::class);
        $sale->expects($this->once())
            ->method('check_invoice_number_exists')
            ->with('INV&1', '5')
            ->willReturn(true);

        $controller = $this->makeController(['invoice_number' => 'INV&1', 'sale_id' => '5'], $sale);

        ob_start();
        $controller->postCheckInvoiceNumber();
        $this->assertSame('false', ob_get_clean());
    }

    /**
     * Skips the duplicate lookup when the invoice number is blank.
     */
    public function testDuplicateCheckAcceptsBlankInvoiceNumber(): void
    {
        $sale = $this->createMock(Sale::class);
        $sale->expects($this->never())->method('check_invoice_number_exists');

        $controller = $this->makeController(['invoice_number' => '  ', 'sale_id' => '5'], $sale);

        ob_start();
        $controller->postCheckInvoiceNumber();
        $this->assertSame('true', ob_get_clean());
    }

    /**
     * Posts the sale edit form with the given invoice number and returns the data passed to the sale model.
     */
    private function saveSale(string $invoice_number): array
    {
        $payments = $this->createMock(ResultInterface::class);
        $payments->method('getResult')->willReturn([]);

        $saved = [];
        $sale  = $this->createMock(Sale::class);
        $sale->method('get_sale_payments')->willReturn($payments);
        $sale->expects($this->once())
            ->method('update')
            ->willReturnCallback(static function ($sale_id, $sale_data) use (&$saved): bool {
                $saved = $sale_data;

                return true;
            });

        $inventory = $this->createMock(Inventory::class);
        $inventory->method('update')->willReturn(true);
        Factories::injectMock('models', Inventory::class, $inventory);

        $controller = $this->makeController([
            'date'               => '2026-09-24 10:00:00',
            'customer_id'        => '',
            'employee_id'        => '',
            'comment'            => '',
            'invoice_number'     => $invoice_number,
            'number_of_payments' => '0',
        ], $sale);

        ob_start();
        $controller->postSave(5);
        ob_end_clean();

        return $saved;
    }

    /**
     * Builds a sales controller with the posted fields and the given sale model, skipping its constructor.
     */
    private function makeController(array $post, Sale $sale): SalesController
    {
        $request = new IncomingRequest(new App(), new URI('/sales/save/5'), null, new UserAgent());
        $request->setGlobal('post', $post);
        $request->setGlobal('request', $post);

        $employee = $this->createMock(Employee::class);
        $employee->method('get_logged_in_employee_info')->willReturn((object) ['person_id' => 1]);

        $controller = (new ReflectionClass(SalesController::class))->newInstanceWithoutConstructor();
        $properties = [
            'request'  => $request,
            'sale'     => $sale,
            'employee' => $employee,
            'config'   => ['dateformat' => 'Y-m-d', 'timeformat' => 'H:i:s'],
        ];

        foreach ($properties as $name => $value) {
            $property = new ReflectionProperty(SalesController::class, $name);
            $property->setAccessible(true);
            $property->setValue($controller, $value);
        }

        return $controller;
    }
}
