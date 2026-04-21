<?php
/**
 * Tests for UcpPaymentRouter — adapter lookup, dispatch, and discovery hints.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/UcpPaymentRouter.php';

// Test doubles — one ready, one not-ready, both record calls for assertions.

class ReadyTestAdapter implements UcpPaymentAdapterInterface
{
    public static $authorizeCalls = 0;
    public static $lastOrder      = null;
    public static $lastPayment    = [];

    public function id()
    {
        return 'ready_test';
    }

    public function isReady()
    {
        return true;
    }

    public function discoveryHint()
    {
        return ['gateway' => 'ready_test'];
    }

    public function authorize(Order $order, array $payment)
    {
        self::$authorizeCalls++;
        self::$lastOrder   = $order;
        self::$lastPayment = $payment;
        return ['ok' => true];
    }

    public static function reset()
    {
        self::$authorizeCalls = 0;
        self::$lastOrder      = null;
        self::$lastPayment    = [];
    }
}

class NotReadyTestAdapter implements UcpPaymentAdapterInterface
{
    public function id()
    {
        return 'not_ready_test';
    }

    public function isReady()
    {
        return false;
    }

    public function discoveryHint()
    {
        return [];
    }

    public function authorize(Order $order, array $payment)
    {
        return ['ok' => true]; // should never be called
    }
}

final class UcpPaymentRouterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        UcpPaymentRouter::resetAdapters();
        Configuration::reset();
        ReadyTestAdapter::reset();
    }

    public function testAuthorizeRequiresGateway()
    {
        $result = UcpPaymentRouter::authorize(new Order(), []);

        $this->assertFalse($result['ok']);
        $this->assertSame('missing_gateway', $result['error']);
        $this->assertSame(400, $result['status']);
    }

    public function testAuthorizeRejectsUnregisteredGateway()
    {
        UcpPaymentRouter::addAdapter('ready_test', 'ReadyTestAdapter');

        $result = UcpPaymentRouter::authorize(new Order(), ['gateway' => 'nope']);

        $this->assertFalse($result['ok']);
        $this->assertSame('unsupported_gateway', $result['error']);
        $this->assertSame(422, $result['status']);
        // Error message must list what IS supported so the agent knows what to retry with.
        $this->assertStringContainsString('ready_test', $result['message']);
    }

    public function testAuthorizeRejectsNotReadyAdapter()
    {
        UcpPaymentRouter::addAdapter('not_ready_test', 'NotReadyTestAdapter');

        $result = UcpPaymentRouter::authorize(new Order(), ['gateway' => 'not_ready_test']);

        $this->assertFalse($result['ok']);
        $this->assertSame('gateway_not_ready', $result['error']);
        $this->assertSame(503, $result['status']);
    }

    public function testAuthorizeDispatchesToMatchingAdapter()
    {
        UcpPaymentRouter::addAdapter('ready_test', 'ReadyTestAdapter');

        $order   = new Order();
        $payment = ['gateway' => 'ready_test', 'payment_method_id' => 'pm_xxx'];

        $result = UcpPaymentRouter::authorize($order, $payment);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, ReadyTestAdapter::$authorizeCalls);
        $this->assertSame($order, ReadyTestAdapter::$lastOrder);
        $this->assertSame('pm_xxx', ReadyTestAdapter::$lastPayment['payment_method_id']);
    }

    public function testRegistryDropsNonExistentClasses()
    {
        UcpPaymentRouter::addAdapter('ready_test', 'ReadyTestAdapter');
        UcpPaymentRouter::addAdapter('ghost', 'Class_That_Does_Not_Exist_Anywhere');

        $registry = UcpPaymentRouter::registry();

        $this->assertArrayHasKey('ready_test', $registry);
        $this->assertArrayNotHasKey('ghost', $registry);
    }

    public function testDiscoveryHintsOnlyIncludeReadyAdapters()
    {
        UcpPaymentRouter::addAdapter('ready_test', 'ReadyTestAdapter');
        UcpPaymentRouter::addAdapter('not_ready_test', 'NotReadyTestAdapter');

        $hints = UcpPaymentRouter::discoveryHints();

        $this->assertArrayHasKey('ready_test', $hints);
        $this->assertArrayNotHasKey('not_ready_test', $hints);
        $this->assertSame('ready_test', $hints['ready_test']['gateway']);
    }

    public function testRegistryReadsConfigurationOverride()
    {
        Configuration::updateValue(
            UcpPaymentRouter::EXTRA_ADAPTERS_CONFIG_KEY,
            json_encode(['override_test' => 'ReadyTestAdapter'])
        );

        $registry = UcpPaymentRouter::registry();

        $this->assertArrayHasKey('override_test', $registry);
        $this->assertSame('ReadyTestAdapter', $registry['override_test']);
    }
}
