<?php

namespace Tests;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Covers bootstrap-select setup after the register is replaced.
 *
 * @internal
 */
final class TillModePickerTest extends CIUnitTestCase
{
    /**
     * Removes the old select pickers before the AJAX response replaces the register, and sets up the new ones once after it.
     */
    public function testRegisterReplacementInitializesPickersOnce(): void
    {
        $registerSource = file_get_contents(APPPATH . 'Views/sales/register.php');

        $this->assertIsString($registerSource);

        $rendererStart = strpos($registerSource, 'const renderAddItemResponse = function(response)');
        $this->assertNotFalse($rendererStart);

        $rendererEnd = strpos($registerSource, 'const submitItemScan = function(item', $rendererStart);
        $this->assertNotFalse($rendererEnd);

        $rendererSource = substr($registerSource, $rendererStart, $rendererEnd - $rendererStart);
        $destroyCall    = "\$('#register_wrapper .selectpicker').selectpicker('destroy');";
        $replaceCall    = "\$('#register_wrapper').replaceWith(\$register);";
        $pickerCall     = "\$('#register_wrapper .selectpicker').selectpicker();";
        $bindCall       = 'bindRegisterHandlers();';

        $this->assertStringContainsString($replaceCall, $rendererSource);
        $this->assertSame(1, substr_count($rendererSource, $destroyCall));
        $this->assertLessThan(strpos($rendererSource, $replaceCall), strpos($rendererSource, $destroyCall));
        $this->assertSame(1, substr_count($rendererSource, $pickerCall));
        $this->assertStringContainsString($bindCall, $rendererSource);
        $this->assertLessThan(strpos($rendererSource, $pickerCall), strpos($rendererSource, $replaceCall));
        $this->assertLessThan(strpos($rendererSource, $bindCall), strpos($rendererSource, $pickerCall));
    }
}
