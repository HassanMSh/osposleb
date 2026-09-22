<?php

namespace Tests;

use App\Libraries\Sale_lib;
use App\Libraries\Tax_lib;
use App\Models\Appconfig;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Item_taxes;
use App\Models\Sale;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\OSPOS;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * Covers Phase 3 part A TVA resolution and rounding rules.
 *
 * @internal
 */
final class TvaModelTest extends CIUnitTestCase
{
    /**
     * Sets stable currency settings for the tax-library unit tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        bcscale(8);
    }

    /**
     * An inheriting item uses the current global rate and name.
     */
    public function testInheritingItemUsesTheGlobalRateAndName(): void
    {
        $taxLib = $this->makeTaxLibrary(
            ['default_tax_1_rate' => '11'],
            [1 => ['taxable' => 1]],
        );
        $cart = [$this->makeCartLine(1, 100)];

        $tax = $this->firstTax($taxLib->get_taxes($cart));

        $this->assertSame('VAT', $tax['tax_group']);
        $this->assertSame('11', $tax['tax_rate']);
        $this->assertSame(11.0, $tax['sale_tax_amount']);
    }

    /**
     * A rate saved through app configuration is used by the next calculation.
     */
    public function testInheritingItemUsesTheChangedGlobalRateSavedInSettings(): void
    {
        try {
            $database = Database::connect();
            $database->initialize();
            $database->query('SELECT 1');
        } catch (Throwable $exception) {
            $this->markTestSkipped('The test database is unavailable: ' . $exception->getMessage());
        }

        $appconfig             = model(Appconfig::class);
        $had_rate              = $appconfig->exists('default_tax_1_rate');
        $original_rate         = $appconfig->get_value('default_tax_1_rate');
        $had_tax_included      = $appconfig->exists('tax_included');
        $original_tax_included = $appconfig->get_value('tax_included');
        $item_info             = [1 => ['taxable' => 1]];
        $cart                  = [$this->makeCartLine(1, 100)];

        try {
            $this->assertTrue($appconfig->save(['tax_included' => false]));
            $this->assertTrue($appconfig->save(['default_tax_1_rate' => '11']));
            $firstTaxLib = $this->makeTaxLibrary([], $item_info, [], [], true);

            $this->assertSame(11.0, $this->firstTax($firstTaxLib->get_taxes($cart))['sale_tax_amount']);

            $this->assertTrue($appconfig->save(['default_tax_1_rate' => '12']));
            $nextTaxLib = $this->makeTaxLibrary([], $item_info, [], [], true);

            $this->assertSame(12.0, $this->firstTax($nextTaxLib->get_taxes($cart))['sale_tax_amount']);
        } finally {
            if ($had_rate) {
                $appconfig->save(['default_tax_1_rate' => $original_rate]);
            } else {
                $appconfig->delete('default_tax_1_rate');
                config(OSPOS::class)->update_settings();
            }

            if ($had_tax_included) {
                $appconfig->save(['tax_included' => $original_tax_included]);
            } else {
                $appconfig->delete('tax_included');
                config(OSPOS::class)->update_settings();
            }
        }
    }

    /**
     * An item with stored rates ignores the global rate.
     */
    public function testOwnItemRateOverridesTheGlobalRate(): void
    {
        $taxLib = $this->makeTaxLibrary(
            ['default_tax_1_rate' => '11'],
            [1 => ['taxable' => 1]],
            [1 => [['name' => 'Own TVA', 'percent' => '5']]],
        );
        $cart = [$this->makeCartLine(1, 100)];

        $tax = $this->firstTax($taxLib->get_taxes($cart));

        $this->assertSame('5', $tax['tax_rate']);
        $this->assertSame(5.0, $tax['sale_tax_amount']);
    }

    /**
     * A switched-off item records its reason and carries no TVA.
     */
    public function testUntaxedItemIsRecordedAsExemptWithoutTva(): void
    {
        $taxLib = $this->makeTaxLibrary(
            ['default_tax_1_rate' => '11'],
            [1 => ['taxable' => 0, 'tax_exemption_reason' => 'exempt']],
        );
        $cart = [$this->makeCartLine(1, 100)];

        $details = $taxLib->get_taxes($cart);
        $tax     = $this->firstTax($details);

        $this->assertSame('exempt', $tax['name']);
        $this->assertSame(0.0, $tax['sale_tax_amount']);
        $this->assertSame('exempt', $details[1][0]['name']);
        $this->assertSame(lang('Sales.nontaxed_ind'), $cart[0]['taxed_flag']);
    }

    /**
     * A tax-inclusive price splits into the expected net and TVA amounts.
     */
    public function testTaxInclusiveLineMath(): void
    {
        $taxLib = $this->makeTaxLibrary(
            ['default_tax_1_rate' => '11', 'tax_included' => true],
            [1 => ['taxable' => 1]],
        );
        $cart = [$this->makeCartLine(1, 111)];

        $tax = $this->firstTax($taxLib->get_taxes($cart));

        $this->assertSame(111.0, (float) $tax['sale_tax_basis']);
        $this->assertSame(11.0, (float) $tax['sale_tax_amount']);
        $this->assertSame(100.0, (float) $tax['sale_tax_basis'] - (float) $tax['sale_tax_amount']);
    }

    /**
     * The ADR 0005 basket keeps its total, pound display, TVA, and exempt line.
     */
    public function testAdrBasketKeepsTotalsAndExemptLine(): void
    {
        helper('currency');

        $ospos           = (new ReflectionClass(OSPOS::class))->newInstanceWithoutConstructor();
        $ospos->settings = ['lbp_exchange_rate' => '89500'];
        Factories::injectMock('config', OSPOS::class, $ospos);

        $taxLib = $this->makeTaxLibrary(
            ['default_tax_1_rate' => '11', 'tax_included' => true],
            [
                1 => ['taxable' => 1],
                2 => ['taxable' => 1],
                3 => ['taxable' => 0, 'tax_exemption_reason' => 'exempt'],
                4 => ['taxable' => 1],
            ],
        );
        $cart = [
            $this->makeCartLine(1, 1.10, 1, 2),
            $this->makeCartLine(2, 2.50, 2),
            $this->makeCartLine(3, 1.00, 3),
            $this->makeCartLine(4, 40.00, 4),
        ];

        $details = $taxLib->get_taxes($cart);
        $taxes   = array_values($details[0]);
        $total   = 2 * 1.10 + 2.50 + 1.00 + 40.00;
        $tax     = $this->findTaxGroup($taxes, 'VAT');
        $exempt  = $this->findTaxGroup($taxes, 'exempt');

        $this->assertSame(45.70, round($total, 2));
        $this->assertSame(4_090_000, to_lbp('45.70'));
        $this->assertSame(4.43, (float) $tax['sale_tax_amount']);
        $this->assertSame(0.0, (float) $exempt['sale_tax_amount']);
        $this->assertSame('0', $exempt['tax_rate']);
    }

    /**
     * Full-precision inclusive lines are rounded once after aggregation.
     */
    public function testAdrRoundingCaseProducesFourteenPointEightyFive(): void
    {
        $taxLib = $this->makeTaxLibrary(
            ['default_tax_1_rate' => '11', 'tax_included' => true],
            [1 => ['taxable' => 1]],
        );
        $cart = [];

        for ($line = 1; $line <= 14; $line++) {
            $cart[] = $this->makeCartLine(1, 10.70, $line);
        }

        $tax = $this->firstTax($taxLib->get_taxes($cart));

        $this->assertSame(149.80, (float) $tax['sale_tax_basis']);
        $this->assertSame(14.85, (float) $tax['sale_tax_amount']);
    }

    /**
     * Historical tax rows remain stable after a global rate change.
     */
    public function testHistoricalSaleUsesItsStoredTvaAfterRateChange(): void
    {
        $taxLib = $this->makeTaxLibrary(
            ['default_tax_1_rate' => '20', 'tax_included' => true],
            [1 => ['taxable' => 1]],
            [1 => [['name' => 'VAT', 'percent' => '25']]],
            [1 => [['name' => 'VAT', 'percent' => '11']]],
        );
        $cart = [$this->makeCartLine(1, 111)];

        $tax = $this->firstTax($taxLib->get_taxes($cart, 42));

        $this->assertSame('11', $tax['tax_rate']);
        $this->assertSame(11.0, (float) $tax['sale_tax_amount']);
    }

    /**
     * Returns, voids, and discounts reuse the original sale's TVA rate.
     */
    public function testReturnsVoidsAndDiscountsUseOriginalSaleTva(): void
    {
        $taxLib = $this->makeTaxLibrary(
            ['default_tax_1_rate' => '20', 'tax_included' => true],
            [1 => ['taxable' => 1]],
            [1 => [['name' => 'VAT', 'percent' => '25']]],
            [1 => [['name' => 'VAT', 'percent' => '11']]],
        );

        $returnCart   = [$this->makeCartLine(1, 111, 1, -1)];
        $voidCart     = [$this->makeCartLine(1, 111)];
        $discountCart = [$this->makeCartLine(1, 111, 1, 1, 10)];

        $this->assertSame('-1', $returnCart[0]['quantity']);
        $this->assertSame(-11.0, (float) $this->firstTax($taxLib->get_taxes($returnCart, 42))['sale_tax_amount']);
        $this->assertSame(11.0, (float) $this->firstTax($taxLib->get_taxes($voidCart, 42))['sale_tax_amount']);
        $this->assertSame(9.9, (float) $this->firstTax($taxLib->get_taxes($discountCart, 42))['sale_tax_amount']);
    }

    /**
     * The migration contains the one-query untaxed-item preservation update.
     */
    public function testMigrationPreservesCurrentlyUntaxedItems(): void
    {
        $migration = file_get_contents(APPPATH . 'Database/Migrations/20260920000000_tva_model.php');

        $this->assertIsString($migration);
        $this->assertStringContainsString('SET taxable = 0', $migration);
        $this->assertStringContainsString('WHERE NOT EXISTS', $migration);
        $this->assertStringContainsString('items_taxes.item_id = items.item_id', $migration);
    }

    /**
     * Creates a tax library with test doubles for its data sources.
     *
     * @param bool $useApplicationConfig Build the library through its constructor so it reads live app settings.
     */
    private function makeTaxLibrary(
        array $configOverrides = [],
        array $itemInfo = [],
        array $itemTaxInfo = [],
        array $storedTaxInfo = [],
        bool $useApplicationConfig = false,
    ): Tax_lib {
        $saleLib = $this->createMock(Sale_lib::class);
        $saleLib->method('get_mode')->willReturn('sale');
        $saleLib->method('get_customer')->willReturn(-1);
        $saleLib->method('get_item_total')->willReturnCallback(
            static function (string $quantity, string $price, string $discount, int $discountType, bool $includeDiscount): string {
                $total = bcmul($quantity, $price, 8);

                if (! $includeDiscount) {
                    return $total;
                }

                $discountAmount = $discountType === PERCENT
                    ? round((float) bcmul($total, bcdiv($discount, '100', 8), 8), 2, PHP_ROUND_HALF_UP)
                    : round((float) bcmul($quantity, $discount, 8), 2, PHP_ROUND_HALF_UP);

                return bcsub($total, (string) $discountAmount, 8);
            },
        );

        $customer = $this->createMock(Customer::class);
        $customer->method('get_info')->willReturn((object) [
            'taxable'           => 1,
            'city'              => '',
            'state'             => '',
            'sales_tax_code_id' => 0,
        ]);

        $item = $this->createMock(Item::class);
        $item->method('get_info')->willReturnCallback(
            static fn (int $itemId): object => (object) array_merge(
                ['taxable' => 1, 'tax_exemption_reason' => 'exempt'],
                $itemInfo[$itemId] ?? [],
            ),
        );

        $itemTaxes = $this->createMock(Item_taxes::class);
        $itemTaxes->method('get_info')->willReturnCallback(
            static fn (int $itemId): array => $itemTaxInfo[$itemId] ?? [],
        );

        $sale = $this->createMock(Sale::class);
        $sale->method('get_sales_item_taxes')->willReturnCallback(
            static fn (int $saleId, int $itemId): array => $storedTaxInfo[$itemId] ?? [],
        );

        $taxLib = $useApplicationConfig
            ? new Tax_lib()
            : (new ReflectionClass(Tax_lib::class))->newInstanceWithoutConstructor();
        $this->writePrivateProperty($taxLib, 'sale_lib', $saleLib);
        $this->writePrivateProperty($taxLib, 'customer', $customer);
        $this->writePrivateProperty($taxLib, 'item', $item);
        $this->writePrivateProperty($taxLib, 'item_taxes', $itemTaxes);
        $this->writePrivateProperty($taxLib, 'sale', $sale);
        if (! $useApplicationConfig) {
            $this->writePrivateProperty($taxLib, 'config', array_merge([
                'use_destination_based_tax' => false,
                'tax_included'              => false,
                'currency_decimals'         => '2',
                'tax_decimals'              => '2',
                'default_tax_1_rate'        => '11',
                'default_tax_1_name'        => 'VAT',
            ], $configOverrides));
        }

        return $taxLib;
    }

    /**
     * Creates one cart line for the tax-library tests.
     */
    private function makeCartLine(int $itemId, float $price, int $line = 1, int $quantity = 1, float $discount = 0): array
    {
        return [
            'item_id'         => $itemId,
            'line'            => $line,
            'quantity'        => (string) $quantity,
            'price'           => number_format($price, 2, '.', ''),
            'discount'        => number_format($discount, 2, '.', ''),
            'discount_type'   => PERCENT,
            'tax_category_id' => null,
        ];
    }

    /**
     * Returns the first sale-level tax row.
     */
    private function firstTax(array $details): array
    {
        return array_values($details[0])[0];
    }

    /**
     * Finds one tax group by its displayed name.
     */
    private function findTaxGroup(array $taxes, string $taxGroup): array
    {
        foreach ($taxes as $tax) {
            if ($tax['tax_group'] === $taxGroup) {
                return $tax;
            }
        }

        $this->fail("Tax group {$taxGroup} was not found.");
    }

    /**
     * Writes a private property for a controlled test double setup.
     */
    private function writePrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setValue($object, $value);
    }
}
