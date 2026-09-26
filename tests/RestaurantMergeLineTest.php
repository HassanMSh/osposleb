<?php

namespace Tests;

use App\Libraries\Sale_lib;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Covers when a restaurant tap may join the last cart line.
 *
 * @internal
 */
final class RestaurantMergeLineTest extends CIUnitTestCase
{
    /**
     * Builds one cart line with the fields the merge check reads.
     */
    private function line(int $line, int $item_id, string $price, string $discount = '0', int $discount_type = 0): array
    {
        return [
            'line'          => $line,
            'item_id'       => $item_id,
            'item_location' => 1,
            'price'         => $price,
            'discount'      => $discount,
            'discount_type' => $discount_type,
        ];
    }

    /**
     * Joins the last line when item, price and discount all match.
     */
    public function testMatchingLastLineIsMerged(): void
    {
        $items = [1 => $this->line(1, 7, '10.00')];

        $this->assertSame(1, Sale_lib::get_restaurant_item_merge_line($items, 7, 1, false, '10.00', '0', 0));
    }

    /**
     * Starts a new line when the price differs by less than one displayed unit.
     */
    public function testSubDisplayPriceDifferenceIsNotMerged(): void
    {
        $items = [1 => $this->line(1, 7, '10.00')];

        $this->assertNull(Sale_lib::get_restaurant_item_merge_line($items, 7, 1, false, '10.01', '0', 0));
    }

    /**
     * Starts a new line when the discount or discount type differs.
     */
    public function testDifferentDiscountIsNotMerged(): void
    {
        $items = [1 => $this->line(1, 7, '10.00', '10', 0)];

        $this->assertNull(Sale_lib::get_restaurant_item_merge_line($items, 7, 1, false, '10.00', '0', 0));
        $this->assertNull(Sale_lib::get_restaurant_item_merge_line($items, 7, 1, false, '10.00', '10', 1));
    }

    /**
     * Only the last line can be joined, and serialized items never merge.
     */
    public function testOnlyLastLineAndNeverSerialized(): void
    {
        $items = [1 => $this->line(1, 7, '10.00'), 2 => $this->line(2, 8, '5.00')];

        $this->assertNull(Sale_lib::get_restaurant_item_merge_line($items, 7, 1, false, '10.00', '0', 0));
        $this->assertNull(Sale_lib::get_restaurant_item_merge_line($items, 8, 1, true, '5.00', '0', 0));
        $this->assertSame(2, Sale_lib::get_restaurant_item_merge_line($items, 8, 1, false, '5.00', '0', 0));
    }
}
