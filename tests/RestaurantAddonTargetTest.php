<?php

namespace Tests;

use App\Libraries\Sale_lib;
use App\Libraries\Till_layout;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionClass;

/**
 * Covers restaurant add-on target selection, insertion, merge, deletion, and line renumbering.
 *
 * @internal
 */
final class RestaurantAddonTargetTest extends CIUnitTestCase
{
    private array $config = ['till_layout' => 'restaurant', 'till_addon_category' => 'Add-ons'];

    /**
     * Builds a cart line for the pure restaurant line helpers.
     */
    private function line(int $line, int $item_id, string $name, string $category = 'Food', string $price = '10.00'): array
    {
        return [
            'line'          => $line,
            'item_id'       => $item_id,
            'item_location' => 1,
            'category'      => $category,
            'name'          => $name,
            'price'         => $price,
            'discount'      => '0',
            'discount_type' => 0,
            'item_type'     => ITEM,
        ];
    }

    /**
     * Uses the selected main line and falls back to the last main line when the stored target is invalid.
     */
    public function testTargetLineUsesSelectionAndFallsBackToLastMain(): void
    {
        $cart = [
            1 => $this->line(1, 1, 'Burger'),
            2 => $this->line(2, 2, 'No onion', 'Add-ons', '0'),
            3 => $this->line(3, 3, 'Cola'),
        ];

        $this->assertSame(1, Sale_lib::resolve_restaurant_target_line($cart, 1, $this->config));
        $this->assertSame(3, Sale_lib::resolve_restaurant_target_line($cart, 2, $this->config));
        $this->assertSame(3, Sale_lib::resolve_restaurant_target_line($cart, null, $this->config));
    }

    /**
     * Inserts an add-on after a selected group in the middle or at the end of the cart.
     */
    public function testAddonInsertionUsesSelectedGroupEnd(): void
    {
        $cart = [
            1 => $this->line(1, 1, 'Burger'),
            2 => $this->line(2, 2, 'No onion', 'Add-ons', '0'),
            3 => $this->line(3, 3, 'Cola'),
            4 => $this->line(4, 4, 'Fries'),
        ];
        $middle = Sale_lib::insert_restaurant_addon_line($cart, $this->line(5, 5, 'Extra cheese', 'Add-ons', '1'), 1, $this->config);
        $last   = Sale_lib::insert_restaurant_addon_line($cart, $this->line(5, 5, 'Extra cheese', 'Add-ons', '1'), 4, $this->config);

        $this->assertSame(['Burger', 'No onion', 'Extra cheese', 'Cola', 'Fries'], array_column($middle['cart'], 'name'));
        $this->assertSame(1, $middle['target_line']);
        $this->assertSame(3, $middle['inserted_line']);
        $this->assertSame(['Burger', 'No onion', 'Cola', 'Fries', 'Extra cheese'], array_column($last['cart'], 'name'));
        $this->assertSame(4, $last['target_line']);
        $this->assertSame(5, $last['inserted_line']);
    }

    /**
     * Uses the last main line when no target is stored and appends when no main line exists.
     */
    public function testNoTargetFallsBackAndNoMainLineAppends(): void
    {
        $cart = [
            1 => $this->line(1, 1, 'Burger'),
            2 => $this->line(2, 2, 'Cola'),
        ];
        $fallback   = Sale_lib::insert_restaurant_addon_line($cart, $this->line(3, 3, 'Ice', 'Add-ons', '0'), null, $this->config);
        $addon_cart = [
            1 => $this->line(1, 4, 'No onion', 'Add-ons', '0'),
            2 => $this->line(2, 5, 'Extra cheese', 'Add-ons', '1'),
        ];
        $append = Sale_lib::insert_restaurant_addon_line($addon_cart, $this->line(3, 6, 'Ice', 'Add-ons', '0'), null, $this->config);

        $this->assertSame(['Burger', 'Cola', 'Ice'], array_column($fallback['cart'], 'name'));
        $this->assertSame(2, $fallback['target_line']);
        $this->assertSame(['No onion', 'Extra cheese', 'Ice'], array_column($append['cart'], 'name'));
        $this->assertNull($append['target_line']);
    }

    /**
     * Renumbers array keys and line fields together and keeps a moved target attached to its item.
     */
    public function testInsertionRenumbersCartAndMovesTarget(): void
    {
        $cart = [
            5 => $this->line(5, 1, 'Burger'),
            8 => $this->line(8, 3, 'Cola'),
        ];
        $result = Sale_lib::insert_restaurant_addon_line($cart, $this->line(9, 2, 'No onion', 'Add-ons', '0'), 8, $this->config);

        $this->assertSame([1, 2, 3], array_keys($result['cart']));
        $this->assertSame([1, 2, 3], array_column($result['cart'], 'line'));
        $this->assertSame('Cola', $result['cart'][$result['target_line']]['name']);
    }

    /**
     * Merges an add-on into the last line in its selected group only when all sale terms match.
     */
    public function testAddonMergeUsesTheSelectedGroupTail(): void
    {
        $cart = [
            1 => $this->line(1, 1, 'Burger'),
            2 => $this->line(2, 2, 'No onion', 'Add-ons', '0'),
            3 => $this->line(3, 3, 'Extra cheese', 'Add-ons', '1.00'),
            4 => $this->line(4, 4, 'Cola'),
        ];
        $group_end = Sale_lib::get_restaurant_addon_group_end_line($cart, 1, $this->config);

        $this->assertSame(3, $group_end);
        $this->assertSame(3, Sale_lib::get_restaurant_item_merge_line($cart, 3, 1, false, '1.00', '0', 0, $group_end));
        $this->assertNull(Sale_lib::get_restaurant_item_merge_line($cart, 2, 1, false, '0', '0', 0, $group_end));
        $this->assertNull(Sale_lib::get_restaurant_item_merge_line($cart, 3, 2, false, '1.00', '0', 0, $group_end));
        $this->assertNull(Sale_lib::get_restaurant_item_merge_line($cart, 3, 1, false, '1.00', '10', 0, $group_end));
    }

    /**
     * Deletes a main line with its add-ons, then selects the last remaining main line.
     */
    public function testDeletingMainLineDeletesItsAddonGroup(): void
    {
        $cart = [
            1 => $this->line(1, 1, 'Burger'),
            2 => $this->line(2, 2, 'No onion', 'Add-ons', '0'),
            3 => $this->line(3, 3, 'Extra cheese', 'Add-ons', '1'),
            4 => $this->line(4, 4, 'Cola'),
        ];
        $result = Sale_lib::delete_restaurant_line_group($cart, 1, 1, $this->config);

        $this->assertSame(['Cola'], array_column($result['cart'], 'name'));
        $this->assertSame(1, $result['target_line']);
        $this->assertCount(3, $result['deleted_lines']);
    }

    /**
     * Selects the last remaining main line after deleting the targeted first group.
     */
    public function testDeletingTargetFallsBackToLastMainAfterRenumbering(): void
    {
        $cart = [
            1 => $this->line(1, 1, 'Burger'),
            2 => $this->line(2, 2, 'Cola'),
            3 => $this->line(3, 3, 'Fries'),
        ];
        $result = Sale_lib::delete_restaurant_line_group($cart, 1, 1, $this->config);

        $this->assertSame(['Cola', 'Fries'], array_column($result['cart'], 'name'));
        $this->assertSame(2, $result['target_line']);
        $this->assertSame('Fries', $result['cart'][$result['target_line']]['name']);
    }

    /**
     * Deletes an add-on by itself and updates line ids without losing the selected main target.
     */
    public function testDeletingAddonKeepsMainAndOtherLines(): void
    {
        $cart = [
            1 => $this->line(1, 1, 'Burger'),
            2 => $this->line(2, 2, 'No onion', 'Add-ons', '0'),
            3 => $this->line(3, 3, 'Extra cheese', 'Add-ons', '1'),
            4 => $this->line(4, 4, 'Cola'),
        ];
        $result = Sale_lib::delete_restaurant_line_group($cart, 2, 1, $this->config);

        $this->assertSame(['Burger', 'Extra cheese', 'Cola'], array_column($result['cart'], 'name'));
        $this->assertSame([1, 2, 3], array_keys($result['cart']));
        $this->assertSame([1, 2, 3], array_column($result['cart'], 'line'));
        $this->assertSame(1, $result['target_line']);
        $this->assertCount(1, $result['deleted_lines']);
    }

    /**
     * Keeps add-on category detection disabled for the Shop layout.
     */
    public function testShopLayoutDoesNotClassifyAddonCategory(): void
    {
        $shop_config = ['till_layout' => 'shop', 'till_addon_category' => 'Add-ons'];

        $this->assertFalse(Till_layout::is_addon_category('Add-ons', $shop_config));
        $this->assertSame(2, Sale_lib::get_restaurant_item_merge_line([
            1 => $this->line(1, 1, 'Burger'),
            2 => $this->line(2, 2, 'Cola'),
        ], 2, 1, false, '10.00', '0', 0));
    }

    /**
     * Uses Shop add_item duplicate merging and delete_item without a database.
     */
    public function testShopAddAndDeleteItemKeepsShopCartBehaviorWithoutDatabase(): void
    {
        $sale_library = $this->getMockBuilder(Sale_lib::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_item_total'])
            ->getMock();
        $sale_library->method('get_item_total')->willReturn('10.00');
        $sale_reflection = new ReflectionClass(Sale_lib::class);
        $config_property = $sale_reflection->getProperty('config');
        $config_property->setAccessible(true);
        $config_property->setValue($sale_library, [
            'till_layout'         => 'shop',
            'till_addon_category' => 'Add-ons',
            'multi_pack_enabled'  => false,
        ]);
        $session_property = $sale_reflection->getProperty('session');
        $session_property->setAccessible(true);
        $session_property->setValue($sale_library, session());

        $item = $this->createMock(\App\Models\Item::class);
        $item->method('get_info_by_id_or_number')->willReturn((object) [
            'item_id'               => 7,
            'item_type'             => ITEM,
            'stock_type'            => HAS_STOCK,
            'unit_price'            => '10.00',
            'cost_price'            => '0.00',
            'is_serialized'         => false,
            'name'                  => 'Burger',
            'category'              => 'Food',
            'item_number'           => 'BURGER-7',
            'description'           => '',
            'allow_alt_description' => false,
            'hsn_code'              => null,
            'tax_category_id'       => null,
            'pack_name'             => '',
        ]);
        $attribute_result = $this->createMock(\CodeIgniter\Database\ResultInterface::class);
        $attribute_result->method('getRowObject')->willReturn((object) [
            'attribute_values'   => '',
            'attribute_dtvalues' => '',
        ]);
        $attribute = $this->createMock(\App\Models\Attribute::class);
        $attribute->method('get_link_values')->willReturn($attribute_result);
        $stock_location = $this->createMock(\App\Models\Stock_location::class);
        $stock_location->method('get_location_name')->willReturn('Main');
        $item_quantity = $this->createMock(\App\Models\Item_quantity::class);
        $item_quantity->method('get_item_quantity')->willReturn((object) ['quantity' => '10']);

        foreach ([
            'item'           => $item,
            'attribute'      => $attribute,
            'stock_location' => $stock_location,
            'item_quantity'  => $item_quantity,
        ] as $property_name => $property_value) {
            $property = $sale_reflection->getProperty($property_name);
            $property->setAccessible(true);
            $property->setValue($sale_library, $property_value);
        }

        $sale_library->set_cart([]);
        $item_id  = 'BURGER-7';
        $discount = '0';
        $this->assertTrue($sale_library->add_item($item_id, 1, '1', $discount));
        $item_id = 'BURGER-7';
        $this->assertTrue($sale_library->add_item($item_id, 1, '1', $discount));
        $this->assertSame('2', $sale_library->get_cart()[1]['quantity']);

        $sale_library->delete_item(1);

        $this->assertSame([], $sale_library->get_cart());
    }
}
