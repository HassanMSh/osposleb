<?php

namespace Tests;

use App\Libraries\Sale_lib;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionClass;

/**
 * Covers the receipt line order used by the restaurant till.
 *
 * @internal
 */
final class RestaurantReceiptOrderTest extends CIUnitTestCase
{
    /**
     * Builds a sale library with only the settings the sort reads.
     */
    private function makeSaleLibrary(array $config): Sale_lib
    {
        $sale_library = (new ReflectionClass(Sale_lib::class))->newInstanceWithoutConstructor();
        $property     = (new ReflectionClass(Sale_lib::class))->getProperty('config');
        $property->setValue($sale_library, $config);

        return $sale_library;
    }

    /**
     * Builds one printable cart line.
     */
    private function line(int $line, string $name, int $stock_type): array
    {
        return [
            'line'         => $line,
            'name'         => $name,
            'description'  => '',
            'stock_type'   => $stock_type,
            'price'        => 1,
            'discount'     => 0,
            'print_option' => PRINT_YES,
        ];
    }

    /**
     * Returns the line names in the order the receipt prints them.
     */
    private function sortedNames(array $config): array
    {
        $cart = [
            1 => $this->line(1, 'Burger', 0),
            2 => $this->line(2, 'Extra cheese', 1),
            3 => $this->line(3, 'Cola', 0),
        ];

        return array_column($this->makeSaleLibrary($config)->sort_and_filter_cart($cart), 'name');
    }

    /**
     * Restaurant receipts keep entry order whatever the line sequence setting says.
     */
    public function testRestaurantAlwaysUsesEntryOrder(): void
    {
        foreach (['0', '1', '2', '3'] as $line_sequence) {
            $this->assertSame(
                ['Burger', 'Extra cheese', 'Cola'],
                $this->sortedNames(['till_layout' => 'restaurant', 'line_sequence' => $line_sequence]),
                "line_sequence {$line_sequence}",
            );
        }
    }

    /**
     * The shop still follows the line sequence setting.
     */
    public function testShopFollowsTheSetting(): void
    {
        $this->assertSame(['Burger', 'Extra cheese', 'Cola'], $this->sortedNames(['line_sequence' => '0']));
        $this->assertNotSame(['Burger', 'Extra cheese', 'Cola'], $this->sortedNames(['line_sequence' => '1']));
    }
}
