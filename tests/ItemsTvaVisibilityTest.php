<?php

namespace Tests;

use App\Controllers\Items;
use App\Models\Attribute;
use App\Models\Employee;
use App\Models\Item;
use App\Models\Item_quantity;
use App\Models\Item_taxes;
use App\Models\Stock_location;
use App\Models\Supplier;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Session\Session;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;
use ReflectionClass;
use ReflectionProperty;

/**
 * Covers the global TVA details passed to and shown by the item form.
 *
 * @internal
 */
final class ItemsTvaVisibilityTest extends CIUnitTestCase
{
    /**
     * Shows the configured global TVA rate when loading both item form variants.
     */
    public function testItemFormReceivesGlobalTvaForNewAndExistingItems(): void
    {
        $this->injectSettings();
        $controller = $this->makeItemsController();

        ob_start();

        try {
            $controller->getView(NEW_ENTRY);
            $new_item_form = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        ob_start();

        try {
            $controller->getView(1);
            $existing_item_form = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $this->assertStringContainsString('TVA 11%', $new_item_form);
        $this->assertStringContainsString('TVA 11%', $existing_item_form);
    }

    /**
     * Explains that an inheriting item is untaxed when no global rate exists.
     */
    public function testItemFormExplainsAnUnsetGlobalTva(): void
    {
        $this->injectSettings();
        $controller = $this->makeItemsController('');

        ob_start();

        try {
            $controller->getView(NEW_ENTRY);
            $item_form = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $this->assertStringContainsString(lang('Items.tax_mode_inherit_none'), $item_form);
    }

    /**
     * Builds an item controller with the minimum collaborators needed by getView().
     *
     * @param string $globalRate Global TVA rate shown by the inherit option.
     */
    private function makeItemsController(string $globalRate = '11'): Items
    {
        $stock_result = $this->createMock(ResultInterface::class);
        $stock_result->method('getResultArray')->willReturn([
            ['location_id' => 1, 'location_name' => 'Main'],
        ]);

        $supplier_result = $this->createMock(ResultInterface::class);
        $supplier_result->method('getResultArray')->willReturn([]);

        $session = $this->createMock(Session::class);
        $session->method('get')->willReturn(0);

        $employee = $this->createMock(Employee::class);
        $employee->method('has_grant')->willReturn(true);
        $employee->method('get_logged_in_employee_info')->willReturn((object) ['person_id' => 1]);

        $attribute = $this->createMock(Attribute::class);
        $attribute->method('get_attributes_by_item')->willReturn([]);
        $attribute->method('get_definition_names')->willReturn([]);

        $item = $this->createMock(Item::class);
        $item->method('get_info')->willReturnCallback(
            fn (int $item_id): object => $this->itemInfo($item_id),
        );

        $item_taxes = $this->createMock(Item_taxes::class);
        $item_taxes->method('get_info')->willReturn([]);

        $item_quantity = $this->createMock(Item_quantity::class);
        $item_quantity->method('get_item_quantity')->willReturn((object) ['quantity' => 0]);

        $stock_location = $this->createMock(Stock_location::class);
        $stock_location->method('get_undeleted_all')->willReturn($stock_result);

        $supplier = $this->createMock(Supplier::class);
        $supplier->method('get_all')->willReturn($supplier_result);

        $controller = (new ReflectionClass(Items::class))->newInstanceWithoutConstructor();
        $this->setProperty($controller, 'session', $session);
        $this->setProperty($controller, 'employee', $employee);
        $this->setProperty($controller, 'attribute', $attribute);
        $this->setProperty($controller, 'item', $item);
        $this->setProperty($controller, 'item_quantity', $item_quantity);
        $this->setProperty($controller, 'item_taxes', $item_taxes);
        $this->setProperty($controller, 'stock_location', $stock_location);
        $this->setProperty($controller, 'supplier', $supplier);
        $this->setProperty($controller, 'config', [
            'category_dropdown'         => '0',
            'currency_symbol'           => '$',
            'default_tax_1_name'        => 'TVA',
            'default_tax_1_rate'        => $globalRate,
            'default_tax_2_name'        => '',
            'default_tax_2_rate'        => '',
            'derive_sale_quantity'      => '0',
            'include_hsn'               => '0',
            'multi_pack_enabled'        => '0',
            'use_destination_based_tax' => '0',
        ]);

        return $controller;
    }

    /**
     * Returns an item record with the fields required by the item form.
     */
    private function itemInfo(int $item_id): object
    {
        return (object) [
            'allow_alt_description' => 0,
            'category'              => '',
            'cost_price'            => 0,
            'deleted'               => 0,
            'description'           => '',
            'hsn_code'              => '',
            'is_serialized'         => 0,
            'item_id'               => $item_id,
            'item_number'           => '',
            'item_type'             => ITEM,
            'low_sell_item_id'      => $item_id,
            'name'                  => 'Coffee',
            'pack_name'             => 'Each',
            'pic_filename'          => null,
            'qty_per_pack'          => 1,
            'receiving_quantity'    => 1,
            'reorder_level'         => 1,
            'stock_type'            => HAS_STOCK,
            'supplier_id'           => '',
            'tax_category_id'       => null,
            'tax_exemption_reason'  => 'exempt',
            'taxable'               => 1,
            'unit_price'            => 100,
        ];
    }

    /**
     * Injects the application settings used by the item form helpers.
     */
    private function injectSettings(): void
    {
        $ospos    = (new ReflectionClass(OSPOS::class))->newInstanceWithoutConstructor();
        $settings = [
            'currency_decimals'    => '2',
            'currency_symbol'      => '$',
            'category_dropdown'    => '0',
            'default_tax_1_name'   => 'TVA',
            'default_tax_2_name'   => '',
            'derive_sale_quantity' => '0',
            'multi_pack_enabled'   => '0',
            'number_locale'        => 'en_US',
            'quantity_decimals'    => '0',
            'tax_decimals'         => '2',
            'tax_included'         => '1',
            'thousands_separator'  => '1',
        ];
        $ospos->settings = $settings;
        Factories::injectMock('config', OSPOS::class, $ospos);
        view('viewData', [
            'config'          => $settings,
            'controller_name' => 'items',
        ]);
    }

    /**
     * Sets a private or protected property for a controller test double.
     */
    private function setProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setValue($object, $value);
    }
}
