<?php

namespace Tests;

use App\Controllers\Items;
use CodeIgniter\Config\Factories;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\OSPOS;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * Covers the item name and price limits used by item saves and CSV imports.
 *
 * @internal
 */
final class ItemsEntryValidationTest extends CIUnitTestCase
{
    /**
     * Loads parsing helpers and configures the plain LBP input format used by item saves.
     */
    protected function setUp(): void
    {
        parent::setUp();

        helper(['currency', 'locale']);

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
     * Rejects a negative wholesale cost.
     */
    public function testNegativeCostPriceIsRejected(): void
    {
        $this->assertSame(
            lang('Items.cost_price_non_negative'),
            Items::getItemEntryValidationError('Coffee', -1.0, 2.0),
        );
    }

    /**
     * Rejects a negative retail price.
     */
    public function testNegativeUnitPriceIsRejected(): void
    {
        $this->assertSame(
            lang('Items.unit_price_non_negative'),
            Items::getItemEntryValidationError('Coffee', 1.0, -1.0),
        );
    }

    /**
     * Allows zero for both item prices.
     */
    public function testZeroPricesAreAllowed(): void
    {
        $this->assertNull(Items::getItemEntryValidationError('Coffee', 0.0, 0.0));
    }

    /**
     * Reports a required-field error for an empty wholesale price.
     */
    public function testEmptyCostPriceIsRejectedAsRequired(): void
    {
        $this->assertSame(
            lang('Items.cost_price_required'),
            Items::getItemEntryValidationError('Coffee', '', 2.0),
        );
    }

    /**
     * Reports a required-field error for an empty retail price.
     */
    public function testEmptyUnitPriceIsRejectedAsRequired(): void
    {
        $this->assertSame(
            lang('Items.unit_price_required'),
            Items::getItemEntryValidationError('Coffee', 1.0, ''),
        );
    }

    /**
     * Rejects unparseable wholesale and retail prices.
     */
    public function testUnparseablePricesAreRejectedAsNumbers(): void
    {
        $this->assertSame(
            lang('Items.cost_price_number'),
            Items::getItemEntryValidationError('Coffee', false, 2.0),
        );
        $this->assertSame(
            lang('Items.cost_price_number'),
            Items::getItemEntryValidationError('Coffee', 'abc', 2.0),
        );
        $this->assertSame(
            lang('Items.unit_price_number'),
            Items::getItemEntryValidationError('Coffee', 1.0, false),
        );
    }

    /**
     * Allows an Arabic item name that is exactly 255 characters long.
     */
    public function testTwoHundredFiftyFiveCharacterArabicNameIsAllowed(): void
    {
        $name = str_repeat('أ', 255);

        $this->assertNull(Items::getItemEntryValidationError($name, 1.0, 2.0));
    }

    /**
     * Rejects an item name that is 256 characters long.
     */
    public function testTwoHundredFiftySixCharacterNameIsRejected(): void
    {
        $name = str_repeat('أ', 256);

        $this->assertSame(
            lang('Items.name_max_length'),
            Items::getItemEntryValidationError($name, 1.0, 2.0),
        );
    }

    /**
     * Rejects a sanitized register name when its stored form exceeds the limit.
     */
    public function testSanitizedRegisterNameLengthIsChecked(): void
    {
        $name = filter_var(str_repeat('<', 64), FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        $this->assertGreaterThan(255, mb_strlen($name, 'UTF-8'));
        $this->assertSame(lang('Items.name_max_length'), Items::getItemNameValidationError($name));
    }

    /**
     * Allows a CSV update row with a blank retail price, which keeps the item's current price.
     */
    public function testCsvUpdateRowAllowsBlankUnitPrice(): void
    {
        $row = ['name' => 'Coffee', 'cost_price' => '', 'unit_price' => ''];

        $this->assertNull(Items::getCsvRowValidationError($row, true));
    }

    /**
     * Requires a retail price on a CSV row that creates a new item.
     */
    public function testCsvNewRowRequiresUnitPrice(): void
    {
        $row = ['name' => 'Coffee', 'cost_price' => '1.00', 'unit_price' => ''];

        $this->assertSame(lang('Items.unit_price_required'), Items::getCsvRowValidationError($row, false));
    }

    /**
     * Rejects a negative price on a CSV update row.
     */
    public function testCsvUpdateRowRejectsNegativeUnitPrice(): void
    {
        $row = ['name' => 'Coffee', 'cost_price' => '', 'unit_price' => '-1'];

        $this->assertSame(lang('Items.unit_price_non_negative'), Items::getCsvRowValidationError($row, true));
    }

    /**
     * Keeps an empty CSV cost at zero before shared validation, as the importer did before.
     */
    public function testEmptyCsvCostRemainsZeroForValidation(): void
    {
        $this->assertNull(Items::getItemEntryValidationError('Coffee', (float) '', 2.0));
    }

    /**
     * Allows a valid CSV row with numeric price strings through shared validation.
     */
    public function testValidCsvPricesPassSharedValidation(): void
    {
        $this->assertNull(Items::getItemEntryValidationError('Coffee', '1.25', '2.50'));
    }

    /**
     * Allows a normal item name and positive prices.
     */
    public function testNormalItemValuesAreAllowed(): void
    {
        $this->assertNull(Items::getItemEntryValidationError('Coffee', 1.25, 2.50));
    }

    /**
     * Refuses an item save when its exchange rate is missing, invalid, or not positive.
     */
    #[DataProvider('invalidExchangeRateProvider')]
    public function testItemSaveRefusesAnInvalidExchangeRate(?string $rate): void
    {
        $controller = (new ReflectionClass(Items::class))->newInstanceWithoutConstructor();
        $request    = new IncomingRequest(new App(), new URI('/items/save'), null, new UserAgent());
        $post       = [
            'name'               => 'Coffee',
            'cost_price'         => '100000',
            'unit_price'         => '110000',
            'receiving_quantity' => '1',
        ];
        $request->setGlobal('post', $post);
        $request->setGlobal('request', $post);

        $this->setControllerProperty($controller, 'request', $request);
        $this->setControllerProperty($controller, 'config', $rate === null ? [] : ['lbp_exchange_rate' => $rate]);

        ob_start();

        try {
            $controller->postSave(NEW_ENTRY);
            $response = json_decode((string) ob_get_contents(), true);
        } finally {
            ob_end_clean();
        }

        $this->assertFalse($response['success']);
        $this->assertSame(lang('Common.lbp_rate_missing'), $response['message']);
    }

    /**
     * Supplies rate values that must stop item saves before any item data is written.
     */
    public static function invalidExchangeRateProvider(): array
    {
        return [
            'missing'      => [null],
            'not a number' => ['bad-rate'],
            'zero'         => ['0'],
            'negative'     => ['-1'],
        ];
    }

    /**
     * Sets a private or inherited controller property for a focused request test.
     */
    private function setControllerProperty(object $controller, string $name, mixed $value): void
    {
        $property = (new ReflectionClass($controller))->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($controller, $value);
    }
}
