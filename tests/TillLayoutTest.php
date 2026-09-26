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

    /**
     * Places listed restaurant sections first in the configured order.
     */
    public function testSavedSectionOrderIsApplied(): void
    {
        $categories = [
            'Zebra'  => ['z'],
            'Food'   => ['burger'],
            'Drinks' => ['cola'],
        ];
        $config = ['till_layout' => 'restaurant', 'till_category_order' => "Drinks\nFood"];

        $this->assertSame(['Drinks', 'Food', 'Zebra'], array_keys(Till_layout::order_categories($categories, $config)));
    }

    /**
     * Keeps unlisted restaurant sections after listed ones in their existing input order.
     */
    public function testUnlistedSectionsKeepInputOrderAfterListedSections(): void
    {
        $categories = [
            'Zulu'  => ['z'],
            'Food'  => ['burger'],
            'Alpha' => ['a'],
        ];
        $config = ['till_layout' => 'restaurant', 'till_category_order' => 'Food'];

        $this->assertSame(['Food', 'Zulu', 'Alpha'], array_keys(Till_layout::order_categories($categories, $config)));
    }

    /**
     * Keeps the add-on section last even when it appears in the saved section order.
     */
    public function testAddonSectionRemainsLastWhenListed(): void
    {
        $categories = [
            'Add-ons' => ['extra'],
            'Food'    => ['burger'],
            'Drinks'  => ['cola'],
        ];
        $config = [
            'till_layout'         => 'restaurant',
            'till_addon_category' => 'Add-ons',
            'till_category_order' => "Add-ons\nFood",
        ];

        $this->assertSame(['Food', 'Drinks', 'Add-ons'], array_keys(Till_layout::order_categories($categories, $config)));
    }

    /**
     * Matches saved section names after trimming spaces and ignoring letter case.
     */
    public function testSavedSectionOrderIgnoresCaseAndSpaces(): void
    {
        $categories = [
            ' Food ' => ['burger'],
            'Drinks' => ['cola'],
        ];
        $config = ['till_layout' => 'restaurant', 'till_category_order' => ' food '];

        $this->assertSame([' Food ', 'Drinks'], array_keys(Till_layout::order_categories($categories, $config)));
    }

    /**
     * Skips saved section names that no longer have items.
     */
    public function testStaleSavedSectionsAreIgnored(): void
    {
        $categories = [
            'Drinks' => ['cola'],
            'Food'   => ['burger'],
        ];
        $config = ['till_layout' => 'restaurant', 'till_category_order' => "Removed\nFood"];

        $this->assertSame(['Food', 'Drinks'], array_keys(Till_layout::order_categories($categories, $config)));
    }

    /**
     * Keeps the current category order when no section order has been saved.
     */
    public function testEmptySectionOrderKeepsExistingOrder(): void
    {
        $categories = [
            'Zebra' => ['z'],
            'Apple' => ['a'],
        ];
        $config = ['till_layout' => 'restaurant', 'till_category_order' => ''];

        $this->assertSame(['Zebra', 'Apple'], array_keys(Till_layout::order_categories($categories, $config)));
    }

    /**
     * Ignores the section order setting when the shop till layout is selected.
     */
    public function testShopLayoutIgnoresSavedSectionOrder(): void
    {
        $categories = [
            'Zebra' => ['z'],
            'Apple' => ['a'],
        ];
        $config = ['till_layout' => 'shop', 'till_category_order' => "Apple\nZebra"];

        $this->assertSame(['Zebra', 'Apple'], array_keys(Till_layout::order_categories($categories, $config)));
    }

    /**
     * Trims saved names, removes blank lines, and keeps only the first case-insensitive duplicate.
     */
    public function testCategoryOrderCleanup(): void
    {
        $category_order = " Food \n\n Drinks \r\nfood\nFOOD\nCafé\nCAFÉ";

        $this->assertSame("Food\nDrinks\nCafé", Till_layout::clean_category_order($category_order));
    }

    /**
     * Prefills existing sections in saved order, adds remaining names, and excludes add-ons.
     */
    public function testCategoryOrderPrefillMerge(): void
    {
        $saved_categories    = [' Drinks ', 'Removed', 'food', 'Add-ons'];
        $existing_categories = ['Food', 'Snacks', 'Drinks', 'Add-ons', '', '   '];

        $this->assertSame(
            ['Drinks', 'Food', 'Snacks'],
            Till_layout::merge_category_order($saved_categories, $existing_categories, ' Add-ons '),
        );
    }

    /**
     * Matches saved names to categories whose accented letters changed case.
     */
    public function testSavedOrderMatchesAccentedNamesInAnyCase(): void
    {
        $categories = [
            'Burgers' => ['b'],
            'CAFÉ'    => ['c'],
        ];
        $config = ['till_layout' => 'restaurant', 'till_category_order' => "Café\nBurgers"];

        $this->assertSame(['CAFÉ', 'Burgers'], array_keys(Till_layout::order_categories($categories, $config)));
        $this->assertSame(['CAFÉ', 'Burgers'], Till_layout::merge_category_order(['Café', 'Burgers'], ['Burgers', 'CAFÉ']));
    }

    /**
     * Keeps items without a category unlisted even when the saved order names the Uncategorized label.
     */
    public function testEmptyCategoryStaysUnlisted(): void
    {
        $categories = [
            ''       => ['none'],
            'Drinks' => ['d'],
            'Food'   => ['f'],
        ];
        $config = ['till_layout' => 'restaurant', 'till_category_order' => "Uncategorized\nFood"];

        $this->assertSame(['Food', '', 'Drinks'], array_keys(Till_layout::order_categories($categories, $config)));
    }

    /**
     * Saves a short list, keeps the saved order for an unchanged long pre-fill, and rejects an edited long list.
     */
    public function testCategoryOrderToSave(): void
    {
        $long_order   = implode("\n", array_map(static fn (int $number): string => 'Section number ' . $number, range(1, 40)));
        $edited_order = "Extra\n" . $long_order;

        $this->assertSame("Food\nDrinks", Till_layout::category_order_to_save(" Food \n\nDrinks", $long_order, 'Old'));
        $this->assertSame('Old', Till_layout::category_order_to_save($long_order . "\n", $long_order, ' Old '));
        $this->assertNull(Till_layout::category_order_to_save($edited_order, $long_order, 'Old'));
    }
}
