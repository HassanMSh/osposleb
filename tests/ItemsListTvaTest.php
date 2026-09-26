<?php

namespace Tests;

use App\Models\Attribute;
use App\Models\Item;
use App\Models\Item_taxes;
use App\Models\Tax_category;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\OSPOS;
use ReflectionClass;
use Throwable;

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
     * Shows stored item prices in LBP using the item's form rounding rules.
     */
    public function testItemPricesDisplayAsLbpAtConfiguredRate(): void
    {
        $this->setSettings('11', false, 'TVA', '89500');

        $row = get_item_data_row($this->makeItem());

        $this->assertSame('<span dir="ltr" class="text-nowrap">179,000 LL</span>', $row['unit_price']);
        $this->assertSame('<span dir="ltr" class="text-nowrap">89,500 LL</span>', $row['cost_price']);
    }

    /**
     * Rounds retail prices to thousands and cost prices to whole pounds in the list.
     */
    public function testItemPricesUseDifferentLbpRoundingRules(): void
    {
        $this->setSettings('11', false, 'TVA', '90000');
        $item             = $this->makeItem();
        $item->unit_price = '1.22';
        $item->cost_price = '1.23';

        $row = get_item_data_row($item);

        $this->assertSame('<span dir="ltr" class="text-nowrap">110,000 LL</span>', $row['unit_price']);
        $this->assertSame('<span dir="ltr" class="text-nowrap">110,700 LL</span>', $row['cost_price']);
    }

    /**
     * Keeps dollar prices in the list when the LBP exchange rate is zero.
     */
    public function testItemPricesFallBackToDollarsWhenLbpRateIsZero(): void
    {
        $this->setSettings('11', false, 'TVA', '0');

        $row = get_item_data_row($this->makeItem());

        $this->assertSame('<span dir="ltr" class="text-nowrap">$2.00</span>', $row['unit_price']);
        $this->assertSame('<span dir="ltr" class="text-nowrap">$1.00</span>', $row['cost_price']);
    }

    /**
     * Keeps dollar prices in the list when the LBP exchange-rate setting is missing.
     */
    public function testItemPricesFallBackToDollarsWhenLbpRateIsMissing(): void
    {
        $this->setSettings('11');
        $settings = config(OSPOS::class)->settings;
        unset($settings['lbp_exchange_rate']);
        config(OSPOS::class)->settings = $settings;

        $row = get_item_data_row($this->makeItem());

        $this->assertSame('<span dir="ltr" class="text-nowrap">$2.00</span>', $row['unit_price']);
        $this->assertSame('<span dir="ltr" class="text-nowrap">$1.00</span>', $row['cost_price']);
    }

    /**
     * Keeps item kit prices in dollars while item prices use the LBP exchange rate.
     */
    public function testItemKitPricesRemainInDollarsWithLbpRate(): void
    {
        $this->setSettings('11', false, 'TVA', '89500');
        $itemKit = (object) [
            'description'      => '',
            'item_kit_id'      => 1,
            'item_kit_number'  => '',
            'name'             => 'Combo',
            'total_cost_price' => '1.00',
            'total_unit_price' => '2.00',
        ];

        $row = get_item_kit_data_row($itemKit);

        $this->assertSame('$1.00', $row['total_cost_price']);
        $this->assertSame('$2.00', $row['total_unit_price']);
    }

    /**
     * Shows the inherited global rate as one left-to-right group for an item without rows.
     */
    public function testTaxableItemWithoutRowsRendersTheInheritedGlobalRate(): void
    {
        $this->setSettings('11');

        $firstRow = get_item_data_row($this->makeItem());

        $this->setSettings('12');

        $secondRow = get_item_data_row($this->makeItem());

        $this->assertStringContainsString('Inherited global rate', $firstRow['tax_percents']);
        $this->assertStringContainsString('<span dir="ltr" class="text-nowrap">(TVA 11.00%)</span>', $firstRow['tax_percents']);
        $this->assertStringContainsString('<span dir="ltr" class="text-nowrap">(TVA 12.00%)</span>', $secondRow['tax_percents']);
        $this->assertNotSame($firstRow['tax_percents'], $secondRow['tax_percents']);
    }

    /**
     * Escapes a stored global rate name exactly once inside the inherited rate group.
     */
    public function testInheritedGlobalRateEscapesNameOnce(): void
    {
        $this->setSettings('11', false, 'TVA & <Local>');

        $row = get_item_data_row($this->makeItem());

        $this->assertStringContainsString(
            '<span dir="ltr" class="text-nowrap">(TVA &amp; &lt;Local&gt; 11.00%)</span>',
            $row['tax_percents'],
        );
        $this->assertStringNotContainsString('TVA & <Local>', $row['tax_percents']);
        $this->assertStringNotContainsString('&amp;amp;', $row['tax_percents']);
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
     * Keeps the price cells' direction wrappers active in the browser table.
     */
    public function testItemPriceColumnsKeepHtmlEnabled(): void
    {
        $headers      = json_decode(get_items_manage_table_headers(), true, 512, JSON_THROW_ON_ERROR);
        $headersByKey = array_column($headers, null, 'field');

        $this->assertFalse($headersByKey['cost_price']['escape']);
        $this->assertFalse($headersByKey['unit_price']['escape']);
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
     * Inserts an item and matching inventory row, then checks the taxability fields returned by search.
     *
     * Skips the database-backed check when the test database is unavailable.
     */
    public function testItemSearchReturnsTaxabilityFieldsForListRows(): void
    {
        try {
            $database = Database::connect('tests');
            $database->initialize();
            $database->query('SELECT 1');
        } catch (Throwable $exception) {
            $this->markTestSkipped('The test database is unavailable: ' . $exception->getMessage());
        }

        $item_name    = 'TVA list fixture ' . bin2hex(random_bytes(4));
        $item_id      = null;
        $inventory_id = null;

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

        try {
            $database->table('items')->insert([
                'name'                  => $item_name,
                'category'              => 'Test',
                'supplier_id'           => null,
                'item_number'           => 'tva-list-' . bin2hex(random_bytes(4)),
                'description'           => 'Items list TVA test fixture',
                'cost_price'            => '1.00',
                'unit_price'            => '2.00',
                'reorder_level'         => 0,
                'receiving_quantity'    => 1,
                'allow_alt_description' => 0,
                'is_serialized'         => 0,
                'deleted'               => 0,
                'stock_type'            => HAS_STOCK,
                'item_type'             => ITEM,
                'tax_category_id'       => null,
                'taxable'               => 1,
                'tax_exemption_reason'  => 'exempt',
                'pic_filename'          => '',
                'qty_per_pack'          => 1,
                'pack_name'             => 'Each',
                'low_sell_item_id'      => 0,
                'hsn_code'              => '',
            ]);
            $item_id = (int) $database->insertID();

            $employee_id = $database->table('employees')->select('person_id')->orderBy('person_id')->get()->getRow('person_id');
            $location_id = $database->table('stock_locations')->select('location_id')->orderBy('location_id')->get()->getRow('location_id');

            $database->table('inventory')->insert([
                'trans_items'     => $item_id,
                'trans_user'      => $employee_id,
                'trans_date'      => date('Y-m-d H:i:s'),
                'trans_comment'   => 'Items list TVA test fixture',
                'trans_location'  => $location_id,
                'trans_inventory' => 1,
            ]);
            $inventory_id = (int) $database->insertID();

            $rows = (new Item())->search($item_name, $filters, 10)->getResult();

            $this->assertCount(1, $rows);
            $this->assertSame($item_id, (int) $rows[0]->item_id);
            $this->assertObjectHasProperty('taxable', $rows[0]);
            $this->assertObjectHasProperty('tax_exemption_reason', $rows[0]);
        } finally {
            if ($inventory_id !== null) {
                $database->table('inventory')->where('trans_id', $inventory_id)->delete();
            }

            if ($item_id !== null) {
                $database->table('items')->where('item_id', $item_id)->delete();
            }
        }
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
     * Injects the tax, LBP exchange-rate, display, and number settings needed by the item row formatter.
     */
    private function setSettings(string $globalRate, bool $useDestinationBasedTax = false, string $globalName = 'TVA', string $lbpExchangeRate = '0'): void
    {
        $ospos           = (new ReflectionClass(OSPOS::class))->newInstanceWithoutConstructor();
        $ospos->settings = [
            'currency_decimals'         => '2',
            'currency_symbol'           => '$',
            'date_or_time_format'       => '',
            'default_tax_1_name'        => $globalName,
            'default_tax_1_rate'        => $globalRate,
            'lbp_exchange_rate'         => $lbpExchangeRate,
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
