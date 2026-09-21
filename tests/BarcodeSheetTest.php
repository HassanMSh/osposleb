<?php

namespace Tests;

use App\Libraries\Barcode_lib;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Covers barcode-sheet rendering and item-code selection.
 *
 * @internal
 */
final class BarcodeSheetTest extends CIUnitTestCase
{
    /**
     * Returns generated SVG markup instead of the literal barcode variable token.
     */
    public function testDisplayBarcodeContainsSvgWithoutLiteralBarcodeToken(): void
    {
        $markup = (new Barcode_lib())->display_barcode(
            ['item_id' => 'INTERNAL-ID', 'item_number' => 'NUMBER-CODE', 'name' => 'Coffee'],
            $this->barcodeConfig(),
        );

        $this->assertStringContainsString('<svg', $markup);
        $this->assertStringNotContainsString('$barcode', $markup);
    }

    /**
     * Prints the item number when barcode content is configured as number.
     */
    public function testDisplayBarcodePrintsItemNumberForNumberContent(): void
    {
        $markup = (new Barcode_lib())->display_barcode(
            ['item_id' => 'INTERNAL-ID', 'item_number' => 'NUMBER-CODE', 'name' => 'Coffee'],
            $this->barcodeConfig('number'),
        );

        $this->assertStringContainsString('NUMBER-CODE', $markup);
        $this->assertStringContainsString('<desc>NUMBER-CODE</desc>', $markup);
        $this->assertStringNotContainsString('INTERNAL-ID', $markup);
    }

    /**
     * Prints the internal item ID when barcode content is configured as id.
     */
    public function testDisplayBarcodePrintsItemIdForIdContent(): void
    {
        $markup = (new Barcode_lib())->display_barcode(
            ['item_id' => 'INTERNAL-ID', 'item_number' => 'NUMBER-CODE', 'name' => 'Coffee'],
            $this->barcodeConfig('id'),
        );

        $this->assertStringContainsString('INTERNAL-ID', $markup);
        $this->assertStringContainsString('<desc>INTERNAL-ID</desc>', $markup);
        $this->assertStringNotContainsString('NUMBER-CODE', $markup);
    }

    /**
     * Escapes item text while preserving the generated SVG barcode.
     */
    public function testDisplayBarcodeEscapesItemNumberAndKeepsSvg(): void
    {
        $markup = (new Barcode_lib())->display_barcode(
            ['item_id' => 'INTERNAL-ID', 'item_number' => '<img src=x onerror=alert(1)>', 'name' => 'Coffee'],
            $this->barcodeConfig(),
        );

        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $markup);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $markup);
        $this->assertStringContainsString('<svg', $markup);
    }

    /**
     * Truncates a long row with a real ellipsis rather than a double-escaped entity.
     */
    public function testDisplayBarcodeTruncatesLongRowWithRealEllipsis(): void
    {
        $markup = (new Barcode_lib())->display_barcode(
            ['item_id' => 'INTERNAL-ID', 'item_number' => 'NUMBER-CODE', 'name' => str_repeat('Long name ', 10)],
            $this->barcodeConfig('number', 'name'),
        );

        $this->assertStringContainsString('…', $markup);
        $this->assertStringNotContainsString('&amp;#8230;', $markup);
        $this->assertStringNotContainsString('&#8230;', $markup);
    }

    /**
     * Rejects an item that has neither an item number nor a name.
     */
    public function testDisplayBarcodeRejectsItemWithoutNumberOrName(): void
    {
        $markup = (new Barcode_lib())->display_barcode(
            ['item_id' => 'INTERNAL-ID'],
            $this->barcodeConfig(),
        );

        $this->assertSame('Item number or Item ID not found in the item array.', $markup);
    }

    /**
     * Rejects an item that has no internal item ID.
     */
    public function testDisplayBarcodeRejectsItemWithoutItemId(): void
    {
        $markup = (new Barcode_lib())->display_barcode(
            ['item_number' => 'NUMBER-CODE', 'name' => 'Coffee'],
            $this->barcodeConfig(),
        );

        $this->assertSame('Item number or Item ID not found in the item array.', $markup);
    }

    /**
     * Provides the minimum barcode settings needed by display_barcode().
     *
     * @param string $barcode_content Whether rows print the item number or the internal item ID.
     * @param string $row_layout      The layout type used for all three text rows.
     */
    private function barcodeConfig(string $barcode_content = 'number', string $row_layout = 'item_code'): array
    {
        return [
            'company'                   => 'Test shop',
            'barcode_content'           => $barcode_content,
            'barcode_type'              => 'C128',
            'barcode_height'            => 50,
            'barcode_first_row'         => $row_layout,
            'barcode_second_row'        => $row_layout,
            'barcode_third_row'         => $row_layout,
            'barcode_generate_if_empty' => '0',
        ];
    }
}
