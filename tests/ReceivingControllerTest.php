<?php

namespace Tests;

use App\Controllers\Receivings;
use App\Libraries\Receiving_lib;
use App\Models\Inventory;
use App\Models\Receiving;
use App\Models\Stock_location;
use CodeIgniter\Config\Factories;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\OSPOS;
use ReflectionClass;
use RuntimeException;

/**
 * Covers receiving line validation and text saved by receiving actions.
 *
 * @internal
 */
final class ReceivingControllerTest extends CIUnitTestCase
{
    /**
     * Loads currency helpers and the exchange-rate settings used by the receiving edit action.
     */
    protected function setUp(): void
    {
        parent::setUp();

        helper(['currency', 'locale']);

        $ospos           = (new ReflectionClass(OSPOS::class))->newInstanceWithoutConstructor();
        $ospos->settings = [
            'currency_decimals'   => '2',
            'lbp_exchange_rate'   => '89500',
            'number_locale'       => 'en_US',
            'quantity_decimals'   => '0',
            'thousands_separator' => '1',
        ];
        Factories::injectMock('config', OSPOS::class, $ospos);
    }

    /**
     * Clears injected models after each receiving controller test.
     */
    protected function tearDown(): void
    {
        Factories::reset('models');

        parent::tearDown();
    }

    /**
     * Converts a posted LL unit price to dollars and keeps description text unencoded.
     */
    public function testReceivingLineEditConvertsPoundPriceAndKeepsTextAsTyped(): void
    {
        [$controller, $receiving_lib, $reload_exception] = $this->makeEditController(
            $this->validEditPost(['description' => ' A&B ', 'serialnumber' => ' S&1 ']),
            readsBeforeReload: 2,
        );
        $edit_arguments = [];
        $receiving_lib->expects($this->once())
            ->method('edit_item')
            ->willReturnCallback(static function (...$arguments) use (&$edit_arguments): bool {
                $edit_arguments = $arguments;

                return true;
            });

        $this->runEditUntilReload($controller, $reload_exception);

        $this->assertSame('A&B', $edit_arguments[1]);
        $this->assertSame('S&1', $edit_arguments[2]);
        $this->assertSame(4.75, (float) $edit_arguments[6]);
    }

    /**
     * Allows a zero unit price because receiving prices may be free.
     */
    public function testReceivingLineEditAllowsZeroPrice(): void
    {
        [$controller, $receiving_lib, $reload_exception] = $this->makeEditController(
            $this->validEditPost(['price' => '0']),
            readsBeforeReload: 2,
        );
        $edit_arguments = [];
        $receiving_lib->expects($this->once())
            ->method('edit_item')
            ->willReturnCallback(static function (...$arguments) use (&$edit_arguments): bool {
                $edit_arguments = $arguments;

                return true;
            });

        $this->runEditUntilReload($controller, $reload_exception);

        $this->assertSame(0.0, (float) $edit_arguments[6]);
    }

    /**
     * Refuses negative LL prices before editing the receiving line.
     */
    public function testReceivingLineEditRefusesNegativePrice(): void
    {
        [$controller, $receiving_lib, $reload_exception] = $this->makeEditController(
            $this->validEditPost(['price' => '-1']),
        );
        $receiving_lib->expects($this->never())->method('edit_item');

        $this->runEditUntilReload($controller, $reload_exception);
    }

    /**
     * Refuses quantities that round to zero while allowing valid negative return quantities.
     */
    public function testReceivingLineEditRefusesZeroAndKeepsNegativeReturnQuantities(): void
    {
        foreach (['0', '0.0004', '-0.0004'] as $quantity) {
            [$controller, $receiving_lib, $reload_exception] = $this->makeEditController(
                $this->validEditPost(['quantity' => $quantity]),
            );
            $receiving_lib->expects($this->never())->method('edit_item');

            $this->runEditUntilReload($controller, $reload_exception);
        }

        [$controller, $receiving_lib, $reload_exception] = $this->makeEditController(
            $this->validEditPost(['quantity' => '-1']),
            readsBeforeReload: 2,
        );
        $edit_arguments = [];
        $receiving_lib->expects($this->once())
            ->method('edit_item')
            ->willReturnCallback(static function (...$arguments) use (&$edit_arguments): bool {
                $edit_arguments = $arguments;

                return true;
            });

        $this->runEditUntilReload($controller, $reload_exception);

        $this->assertSame(-1.0, (float) $edit_arguments[3]);
    }

    /**
     * Refuses missing and non-string line fields through the normal edit-error reload path.
     */
    public function testReceivingLineEditRefusesMissingOrNonStringFields(): void
    {
        foreach (['quantity', 'discount', 'receiving_quantity', 'description', 'serialnumber'] as $field) {
            $missing = $this->validEditPost();
            unset($missing[$field]);

            foreach ([$missing, $this->validEditPost([$field => ['bad']])] as $post) {
                [$controller, $receiving_lib, $reload_exception] = $this->makeEditController($post);
                $receiving_lib->expects($this->never())->method('edit_item');

                $this->runEditUntilReload($controller, $reload_exception);
            }
        }
    }

    /**
     * Stores receiving comments and references as typed, trimmed text.
     */
    public function testReceivingCommentAndReferenceAreStoredAsTyped(): void
    {
        $request = $this->makeRequest([
            'comment'        => '  Note A&B  ',
            'recv_reference' => '  A&B  ',
        ]);
        $receiving_lib = $this->createMock(Receiving_lib::class);
        $receiving_lib->expects($this->once())->method('set_comment')->with('Note A&B');
        $receiving_lib->expects($this->once())->method('set_reference')->with('A&B');

        $controller = (new ReflectionClass(Receivings::class))->newInstanceWithoutConstructor();
        $this->setControllerProperty($controller, 'request', $request);
        $this->setControllerProperty($controller, 'receiving_lib', $receiving_lib);

        $controller->postSetComment();
        $controller->postSetReference();
    }

    /**
     * Saves edited receiving comments and references as typed, trimmed text.
     */
    public function testReceivingEditSaveStoresCommentAndReferenceAsTyped(): void
    {
        $saved_data = [];
        $receiving  = $this->createMock(Receiving::class);
        $receiving->expects($this->once())
            ->method('update')
            ->with(8, $this->callback(static function (array $data) use (&$saved_data): bool {
                $saved_data = $data;

                return true;
            }))
            ->willReturn(true);
        $inventory = $this->createMock(Inventory::class);
        $inventory->expects($this->once())->method('update')->willReturn(true);

        $controller = (new ReflectionClass(Receivings::class))->newInstanceWithoutConstructor();
        $this->setControllerProperty($controller, 'request', $this->makeRequest([
            'date'        => '2026-09-28 10:00',
            'comment'     => '  Note A&B  ',
            'reference'   => '  R&A  ',
            'employee_id' => '1',
        ]));
        $this->setControllerProperty($controller, 'receiving', $receiving);
        $this->setControllerProperty($controller, 'inventory', $inventory);
        $this->setControllerProperty($controller, 'config', ['dateformat' => 'Y-m-d', 'timeformat' => 'H:i']);

        ob_start();
        $controller->postSave(8);
        ob_end_clean();

        $this->assertSame('Note A&B', $saved_data['comment']);
        $this->assertSame('R&A', $saved_data['reference']);
    }

    /**
     * Returns a receiving controller and throws when its edit action reaches the screen reload.
     *
     * @return array{0: Receivings, 1: \PHPUnit\Framework\MockObject\MockObject&Receiving_lib, 2: RuntimeException}
     */
    private function makeEditController(array $post, int $readsBeforeReload = 1): array
    {
        $reload_exception = new RuntimeException('receiving reload reached');
        $cart             = [1 => [
            'item_id'            => 7,
            'price'              => '0.95',
            'discount'           => '0',
            'discount_type'      => PERCENT,
            'quantity'           => '5',
            'receiving_quantity' => 1,
            'item_location'      => 1,
        ]];
        $cart_reads    = 0;
        $receiving_lib = $this->getMockBuilder(Receiving_lib::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_cart', 'get_stock_source', 'get_stock_destination', 'edit_item'])
            ->getMock();
        $receiving_lib->method('get_cart')->willReturnCallback(static function () use (&$cart_reads, $readsBeforeReload, $cart, $reload_exception): array {
            if ($cart_reads++ >= $readsBeforeReload) {
                throw $reload_exception;
            }

            return $cart;
        });
        $receiving_lib->method('get_stock_source')->willReturn(1);
        $receiving_lib->method('get_stock_destination')->willReturn('1');
        $stock_location = $this->getMockBuilder(Stock_location::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_allowed_locations', 'is_allowed_location'])
            ->getMock();
        $stock_location->method('get_allowed_locations')->willReturn([1 => 'Receiving test location']);
        $stock_location->method('is_allowed_location')->willReturn(true);
        $request    = $this->makeRequest($post);
        $controller = (new ReflectionClass(Receivings::class))->newInstanceWithoutConstructor();
        $this->setControllerProperty($controller, 'request', $request);
        $this->setControllerProperty($controller, 'receiving_lib', $receiving_lib);
        $this->setControllerProperty($controller, 'stock_location', $stock_location);
        $this->setControllerProperty($controller, 'config', config(OSPOS::class)->settings);

        return [$controller, $receiving_lib, $reload_exception];
    }

    /**
     * Returns a valid receiving line edit request with optional changed fields.
     *
     * @return array<string, string>
     */
    private function validEditPost(array $changes = []): array
    {
        return array_replace([
            'price'              => '425000',
            'quantity'           => '5',
            'receiving_quantity' => '1',
            'discount'           => '0',
            'discount_type'      => (string) PERCENT,
            'description'        => '',
            'serialnumber'       => '',
        ], $changes);
    }

    /**
     * Calls the receiving edit action and confirms it reached the reload after processing.
     */
    private function runEditUntilReload(Receivings $controller, RuntimeException $reload_exception): void
    {
        try {
            $controller->postEditItem('1');
        } catch (RuntimeException $exception) {
            $this->assertSame($reload_exception, $exception);

            return;
        }

        $this->fail('The receiving edit did not reach its reload point.');
    }

    /**
     * Builds an incoming request with the posted values used by a receiving action.
     */
    private function makeRequest(array $post): IncomingRequest
    {
        $request = new IncomingRequest(new App(), new URI('/receivings/editItem/1'), null, new UserAgent());
        $request->setGlobal('post', $post);
        $request->setGlobal('request', $post);

        return $request;
    }

    /**
     * Sets a private or inherited controller property for a focused action test.
     */
    private function setControllerProperty(object $controller, string $name, mixed $value): void
    {
        $property = (new ReflectionClass($controller))->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($controller, $value);
    }
}
