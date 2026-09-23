<?php

namespace Tests;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Covers the register's F12 sale shortcut and its help entry.
 *
 * @internal
 */
final class TillShortcutTest extends CIUnitTestCase
{
    /**
     * Requires F12 handling on keydown and keeps its shortcut help entry current.
     */
    public function testF12CompletesSaleAndIsDocumentedAsRegisterShortcut(): void
    {
        $registerSource = file_get_contents(APPPATH . 'Views/sales/register.php');
        $helpSource     = file_get_contents(APPPATH . 'Views/sales/help.php');

        $this->assertIsString($registerSource);
        $this->assertIsString($helpSource);

        $handlerStart = strpos($registerSource, 'const handleF12Shortcut = function(event)');
        $this->assertNotFalse($handlerStart);

        $handlerEnd = strpos($registerSource, "document.addEventListener('keydown'", $handlerStart);
        $this->assertNotFalse($handlerEnd);

        $handlerSource   = substr($registerSource, $handlerStart, $handlerEnd - $handlerStart);
        $keydownListener = "document.addEventListener('keydown', handleF12Shortcut);";

        $this->assertStringContainsString($keydownListener, $registerSource);
        $this->assertStringContainsString("event.key !== 'F12'", $handlerSource);
        $this->assertStringContainsString('event.keyCode !== 123', $handlerSource);
        $this->assertStringContainsString('event.preventDefault();', $handlerSource);
        $this->assertStringContainsString('addItemInFlight', $handlerSource);
        $this->assertStringContainsString('pendingItemScans.length > 0', $handlerSource);
        $this->assertStringContainsString('f12ShortcutTriggered', $handlerSource);
        $this->assertStringContainsString('f12ShortcutEnabled', $handlerSource);
        $this->assertStringContainsString("$('.modal:visible').length > 0", $handlerSource);
        $this->assertStringContainsString('activeField.value !== activeField.defaultValue', $handlerSource);
        $this->assertStringContainsString('const f12ShortcutEnabled = <?= $pos_mode ? \'true\' : \'false\' ?>;', $registerSource);
        $this->assertStringNotContainsString('case 55', $registerSource);
        $this->assertStringNotContainsString('Alt + 7', $registerSource);
        $this->assertStringContainsString('<code>F12</code>', $helpSource);
        $this->assertStringNotContainsString('ALT + 7', $helpSource);
    }
}
