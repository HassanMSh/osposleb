<?php

namespace Tests;

use App\Controllers\Items;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Covers the item name and price limits used by item saves and CSV imports.
 *
 * @internal
 */
final class ItemsEntryValidationTest extends CIUnitTestCase
{
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
}
