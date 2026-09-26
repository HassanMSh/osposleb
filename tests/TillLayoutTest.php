<?php

namespace Tests;

use App\Libraries\Till_layout;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Covers the till layout default and accepted setting values.
 *
 * @internal
 */
final class TillLayoutTest extends CIUnitTestCase
{
    /**
     * Uses the shop till when the setting has not been saved.
     */
    public function testMissingLayoutUsesShop(): void
    {
        $this->assertSame('shop', Till_layout::get_layout([]));
    }

    /**
     * Keeps restaurant mode and treats all other saved values as shop.
     */
    public function testUnsupportedLayoutValuesUseShop(): void
    {
        $this->assertSame('restaurant', Till_layout::get_layout(['till_layout' => 'restaurant']));
        $this->assertSame('shop', Till_layout::get_layout(['till_layout' => 'shop']));
        $this->assertSame('shop', Till_layout::get_layout(['till_layout' => 'other']));
        $this->assertSame('shop', Till_layout::get_layout(['till_layout' => null]));
    }

    /**
     * Normalizes an unsupported value before the settings model saves it.
     */
    public function testSavingNormalizesUnsupportedValuesToShop(): void
    {
        $this->assertSame('restaurant', Till_layout::normalize('restaurant'));
        $this->assertSame('shop', Till_layout::normalize('shop'));
        $this->assertSame('shop', Till_layout::normalize('restaurant '));
        $this->assertSame('shop', Till_layout::normalize(['restaurant']));
    }

    /**
     * Matches a non-empty add-on category after trimming spaces and ignoring letter case.
     */
    public function testAddonCategoryMatchesTrimmedCaseInsensitively(): void
    {
        $config = ['till_layout' => 'restaurant', 'till_addon_category' => '  ADD-ons  '];

        $this->assertTrue(Till_layout::is_addon_category(' Add-ons ', $config));
        $this->assertFalse(Till_layout::is_addon_category('Drinks', $config));
        $this->assertFalse(Till_layout::is_addon_category(null, $config));
    }

    /**
     * Does not mark lines as add-ons when the category setting is empty or the till uses Shop.
     */
    public function testAddonCategoryRequiresRestaurantLayoutAndASetting(): void
    {
        $this->assertFalse(Till_layout::is_addon_category('Add-ons', ['till_layout' => 'restaurant']));
        $this->assertFalse(Till_layout::is_addon_category('Add-ons', [
            'till_layout'         => 'shop',
            'till_addon_category' => 'Add-ons',
        ]));
    }

    /**
     * Keeps regular sections first and moves the configured add-on section to the end.
     */
    public function testAddonCategoryIsLastInRestaurantMenuOrder(): void
    {
        $categories = [
            'Add-ons' => ['extra'],
            'Drinks'  => ['cola'],
            'Food'    => ['burger'],
        ];
        $config = ['till_layout' => 'restaurant', 'till_addon_category' => ' add-ons '];

        $this->assertSame(
            ['Drinks', 'Food', 'Add-ons'],
            array_keys(Till_layout::order_categories($categories, $config)),
        );
    }
}
