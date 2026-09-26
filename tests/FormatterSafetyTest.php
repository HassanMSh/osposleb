<?php

namespace Tests;

use App\Controllers\Sales;
use App\Libraries\Sale_lib;
use App\Models\Attribute;
use App\Models\Item;
use App\Models\Item_quantity;
use App\Models\Stock_location;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;
use ReflectionClass;
use ReflectionProperty;

/**
 * Pins type-sensitive sale and receipt behavior before formatter changes.
 *
 * @internal
 */
final class FormatterSafetyTest extends CIUnitTestCase
{
    /**
     * Loads helpers used by the sale and receipt assertions.
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
            'tax_decimals'        => '2',
            'tax_included'        => true,
            'thousands_separator' => '1',
        ];
        Factories::injectMock('config', OSPOS::class, $ospos);
    }

    /**
     * A priced-only free line stays out of the receipt cart.
     */
    public function testPricedOnlyZeroStringIsExcludedFromReceipt(): void
    {
        $sales = (new ReflectionClass(Sales::class))->newInstanceWithoutConstructor();
        $cart  = [
            'free' => [
                'print_option' => PRINT_PRICED,
                'price'        => '0.00',
            ],
            'priced' => [
                'print_option' => PRINT_PRICED,
                'price'        => '1.00',
            ],
            'always' => [
                'print_option' => PRINT_ALL,
                'price'        => '0.00',
            ],
        ];

        $filtered = $sales->get_filtered($cart);

        $this->assertArrayNotHasKey('free', $filtered);
        $this->assertArrayHasKey('priced', $filtered);
        $this->assertArrayHasKey('always', $filtered);
    }

    /**
     * A null optional description does not create a work-order description row.
     */
    public function testNullDescriptionHasNoEmptyDescriptionBlock(): void
    {
        $source = file_get_contents(APPPATH . 'Views/sales/work_order.php');

        $this->assertIsString($source);
        $this->assertStringContainsString(
            "&& !empty(\$item['description'])",
            $source,
        );
    }

    /**
     * Receipt templates guard empty logos before writing an image element.
     */
    public function testEmptyCompanyLogoDoesNotEmitAnImageTag(): void
    {
        $config = [
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
            'return_policy'               => '',
            'lbp_exchange_rate'           => '89500',
        ];
        $data = [
            'transaction_time'     => '2026-09-20 12:00:00',
            'sale_id'              => 'POS 1',
            'sale_id_num'          => 1,
            'invoice_number'       => '',
            'employee'             => 'Test employee',
            'cart'                 => [],
            'discount'             => 0.0,
            'prediscount_subtotal' => 0.0,
            'subtotal'             => 0.0,
            'taxes'                => [],
            'total'                => 0.0,
            'lbp_total'            => 0,
            'payments'             => [],
            'amount_change'        => 0.0,
            'barcode'              => '',
            'config'               => $config,
        ];

        foreach (['sales/receipt_default', 'sales/receipt_short'] as $template) {
            $output = view($template, $data);

            $this->assertStringNotContainsString('<img', $output, $template);
        }
    }

    /**
     * A kit price override of '0' still matches the stored zero price.
     */
    public function testZeroPriceOverrideKeepsTheZeroDiscountRule(): void
    {
        $saleLib  = $this->makeSaleLibrary();
        $itemId   = '1';
        $discount = '5.00';

        $this->assertTrue($saleLib->add_item(
            $itemId,
            1,
            '1',
            $discount,
            PERCENT,
            PRICE_MODE_KIT,
            PRICE_OPTION_ALL,
            PRINT_ALL,
            '0',
        ));

        $line = $saleLib->get_cart()[1];

        $this->assertSame('0', $line['price']);
        $this->assertSame('0.00', $line['discount']);
    }

    /**
     * Builds a sale library with the minimum model doubles needed by add_item().
     */
    private function makeSaleLibrary(): Sale_lib
    {
        $saleLib = (new ReflectionClass(Sale_lib::class))->newInstanceWithoutConstructor();
        $item    = $this->createMock(Item::class);
        $item->method('get_info_by_id_or_number')->willReturn((object) [
            'item_id'               => 1,
            'item_type'             => ITEM,
            'stock_type'            => HAS_STOCK,
            'unit_price'            => '0.00',
            'cost_price'            => '0.00',
            'is_serialized'         => false,
            'name'                  => 'Free item',
            'item_number'           => 'FREE-1',
            'description'           => null,
            'allow_alt_description' => false,
            'hsn_code'              => '',
            'tax_category_id'       => null,
            'pack_name'             => '',
        ]);

        $attributeLinks = $this->createMock(\CodeIgniter\Database\ResultInterface::class);
        $attributeLinks->method('getRowObject')->willReturn((object) [
            'attribute_values'   => '',
            'attribute_dtvalues' => '',
        ]);

        $attribute = $this->createMock(Attribute::class);
        $attribute->method('get_link_values')->willReturn($attributeLinks);

        $itemQuantity = $this->createMock(Item_quantity::class);
        $itemQuantity->method('get_item_quantity')->willReturn((object) ['quantity' => 0]);

        $stockLocation = $this->createMock(Stock_location::class);
        $stockLocation->method('get_location_name')->willReturn('Main');

        $session = session();
        $session->remove('sales_cart');

        $this->writePrivateProperty($saleLib, 'item', $item);
        $this->writePrivateProperty($saleLib, 'attribute', $attribute);
        $this->writePrivateProperty($saleLib, 'item_quantity', $itemQuantity);
        $this->writePrivateProperty($saleLib, 'stock_location', $stockLocation);
        $this->writePrivateProperty($saleLib, 'session', $session);
        $this->writePrivateProperty($saleLib, 'config', ['multi_pack_enabled' => false]);

        return $saleLib;
    }

    /**
     * Writes one private property for a controlled test double setup.
     */
    private function writePrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setValue($object, $value);
    }
}
