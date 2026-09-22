<?php

namespace Tests;

use App\Models\Attribute;
use App\Models\Item;
use App\Models\Item_taxes;
use App\Models\Tax_category;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;
use ReflectionClass;

/**
 * Covers the display-only TVA states shown in the items list.
 *
 * @internal
 */
final class ItemsListTvaTest extends CIUnitTestCase
{
    private array $itemTaxInfo     = [];
    private array $taxCategoryInfo = [];

    /**
     * Loads the item-list helpers and replaces tax-row lookups with test data.
     */
    protected function setUp(): void
    {
        parent::setUp();

        helper(['currency', 'locale', 'tabular']);
        service('request')->setLocale('en');
        service('language')->setLocale('en');

        $attribute = $this->createMock(Attribute::class);
        $attribute->method('get_definitions_by_flags')->willReturn([]);

        $itemTaxes = $this->createMock(Item_taxes::class);
        $itemTaxes->method('get_info')->willReturnCallback(
            fn (int $item_id): array => $this->itemTaxInfo[$item_id] ?? [],
        );

        $taxCategory = $this->createMock(Tax_category::class);
        $taxCategory->method('get_info')->willReturnCallback(
            fn (int $tax_category_id): object => (object) [
                'tax_category' => $this->taxCategoryInfo[$tax_category_id] ?? '',
            ],
        );

        Factories::injectMock('models', Attribute::class, $attribute);
        Factories::injectMock('models', Item_taxes::class, $itemTaxes);
        Factories::injectMock('models', Tax_category::class, $taxCategory);

        $this->setSettings('11');
    }

    /**
     * Removes injected model doubles after each item-list test.
     */
    protected function tearDown(): void
    {
        Factories::reset('models');

        parent::tearDown();
    }

    /**
     * Keeps a stored item rate unchanged in the items table row.
     */
    public function testItemWithOwnRateStillRendersThatRate(): void
    {
        $this->itemTaxInfo = [1 => [['percent' => '10']]];

        $row = get_item_data_row($this->makeItem());

        $this->assertSame('10.00%', $row['tax_percents']);
    }

    /**
     * Shows the current global rate and marks it as inherited for an item without rows.
     */
    public function testTaxableItemWithoutRowsRendersTheInheritedGlobalRate(): void
    {
        $this->setSettings('11');

        $firstRow = get_item_data_row($this->makeItem());

        $this->setSettings('12');

        $secondRow = get_item_data_row($this->makeItem());

        $this->assertStringContainsString('Inherited global rate', $firstRow['tax_percents']);
        $this->assertStringContainsString('<span dir="ltr">11.00%</span>', $firstRow['tax_percents']);
        $this->assertStringContainsString('<span dir="ltr">12.00%</span>', $secondRow['tax_percents']);
        $this->assertNotSame($firstRow['tax_percents'], $secondRow['tax_percents']);
    }

    /**
     * Reuses the item-form wording when a taxable item has no global rate to inherit.
     */
    public function testTaxableItemWithoutRowsAndGlobalRateUsesTheNoRateWording(): void
    {
        $this->setSettings('');

        $row = get_item_data_row($this->makeItem());

        $this->assertSame(lang('Items.tax_mode_inherit_none'), $row['tax_percents']);
    }

    /**
     * Uses the shared exemption label when an item is not taxable.
     */
    public function testNonTaxableItemUsesTheExemptionWording(): void
    {
        $row = get_item_data_row($this->makeItem(0, 'exempt'));

        $this->assertSame(format_tax_group_label('exempt'), $row['tax_percents']);
        $this->assertNotSame('-', $row['tax_percents']);
    }

    /**
     * Escapes a stored exemption reason before returning the item-list cell.
     */
    public function testNonTaxableItemEscapesStoredExemptionReason(): void
    {
        $reason = '<img src=x onerror=alert(document.domain)>';
        $row    = get_item_data_row($this->makeItem(0, $reason));

        $this->assertStringContainsString(
            '&lt;img src=x onerror=alert(document.domain)&gt;',
            $row['tax_percents'],
        );
        $this->assertStringNotContainsString($reason, $row['tax_percents']);
    }

    /**
     * Escapes a stored destination tax-category name before returning the item-list cell.
     */
    public function testDestinationTaxCategoryEscapesStoredName(): void
    {
        $category = '<img src=x onerror=alert(document.domain)>';

        $this->taxCategoryInfo = [7 => $category];
        $this->setSettings('11', true);

        $row = get_item_data_row($this->makeItem(1, 'exempt', 7));

        $this->assertSame('&lt;img src=x onerror=alert(document.domain)&gt;', $row['tax_percents']);
        $this->assertStringNotContainsString($category, $row['tax_percents']);
    }

    /**
     * Escapes an ampersand in a destination tax-category name exactly once.
     */
    public function testDestinationTaxCategoryEscapesAmpersandOnce(): void
    {
        $this->taxCategoryInfo = [7 => 'TVA & Local'];
        $this->setSettings('', true);

        $row = get_item_data_row($this->makeItem(1, 'exempt', 7));

        $this->assertSame('TVA &amp; Local', $row['tax_percents']);
    }

    /**
     * Keeps HTML enabled for the item tax column so inherited rates retain their direction markup.
     */
    public function testItemTaxColumnKeepsHtmlEnabled(): void
    {
        $headers    = json_decode(get_items_manage_table_headers(), true, 512, JSON_THROW_ON_ERROR);
        $taxHeaders = array_values(array_filter(
            $headers,
            static fn (array $header): bool => $header['field'] === 'tax_percents',
        ));

        $this->assertCount(1, $taxHeaders);
        $this->assertFalse($taxHeaders[0]['escape']);
    }

    /**
     * Keeps browser escaping disabled for the destination tax-category column.
     */
    public function testDestinationTaxColumnKeepsHtmlEnabled(): void
    {
        $this->setSettings('', true);

        $headers    = json_decode(get_items_manage_table_headers(), true, 512, JSON_THROW_ON_ERROR);
        $taxHeaders = array_values(array_filter(
            $headers,
            static fn (array $header): bool => $header['field'] === 'tax_percents',
        ));

        $this->assertCount(1, $taxHeaders);
        $this->assertFalse($taxHeaders[0]['escape']);
    }

    /**
     * Ensures item searches return the taxability fields needed by the list formatter.
     */
    public function testItemSearchReturnsTaxabilityFieldsForListRows(): void
    {
        $filters = [
            'start_date'        => '2000-01-01',
            'end_date'          => '2100-01-01',
            'stock_location_id' => -1,
            'empty_upc'         => false,
            'low_inventory'     => false,
            'is_serialized'     => false,
            'no_description'    => false,
            'search_custom'     => false,
            'is_deleted'        => false,
            'temporary'         => false,
            'definition_ids'    => [],
        ];

        $rows = (new Item())->search('', $filters, 1)->getResult();

        $this->assertNotEmpty($rows);
        $this->assertObjectHasProperty('taxable', $rows[0]);
        $this->assertObjectHasProperty('tax_exemption_reason', $rows[0]);
    }

    /**
     * Creates an item record with the fields used by the table-row formatter.
     */
    private function makeItem(int $taxable = 1, string $taxExemptionReason = 'exempt', ?int $taxCategoryId = null): object
    {
        return (object) [
            'category'             => '',
            'company_name'         => '',
            'cost_price'           => '1.00',
            'item_id'              => 1,
            'item_number'          => '',
            'name'                 => 'Coffee',
            'pack_name'            => 'Each',
            'pic_filename'         => null,
            'quantity'             => '1',
            'tax_category_id'      => $taxCategoryId,
            'tax_exemption_reason' => $taxExemptionReason,
            'taxable'              => $taxable,
            'unit_price'           => '2.00',
        ];
    }

    /**
     * Injects the settings needed by the item row and number formatters.
     */
    private function setSettings(string $globalRate, bool $useDestinationBasedTax = false): void
    {
        $ospos           = (new ReflectionClass(OSPOS::class))->newInstanceWithoutConstructor();
        $ospos->settings = [
            'currency_decimals'         => '2',
            'currency_symbol'           => '$',
            'date_or_time_format'       => '',
            'default_tax_1_name'        => 'TVA',
            'default_tax_1_rate'        => $globalRate,
            'multi_pack_enabled'        => false,
            'number_locale'             => 'en_US',
            'quantity_decimals'         => '0',
            'tax_decimals'              => '2',
            'tax_included'              => true,
            'thousands_separator'       => '1',
            'use_destination_based_tax' => $useDestinationBasedTax,
        ];
        Factories::injectMock('config', OSPOS::class, $ospos);
    }
}
