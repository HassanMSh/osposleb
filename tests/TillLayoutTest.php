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
}
