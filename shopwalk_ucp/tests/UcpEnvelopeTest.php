<?php
/**
 * Tests for UcpEnvelope — the `ucp` wrapper on every response body.
 *
 * Pure helper; no PS runtime required beyond the stubs in bootstrap.php.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/UcpEnvelope.php';

final class UcpEnvelopeTest extends TestCase
{
    public function testOkWrapsInEnvelopeWithSpecVersion()
    {
        $result = UcpEnvelope::ok(['foo' => 'bar']);

        $this->assertArrayHasKey('ucp', $result);
        $this->assertSame(Shopwalk_Ucp::UCP_SPEC_VERSION, $result['ucp']['version']);
        $this->assertSame('ok', $result['ucp']['status']);
        $this->assertSame('bar', $result['foo']);
    }

    public function testOkDefaultsToEmptyCapabilities()
    {
        $result = UcpEnvelope::ok([]);
        $this->assertSame([], $result['ucp']['capabilities']);
    }

    public function testOkAcceptsCustomCapabilities()
    {
        $result = UcpEnvelope::ok([], ['dev.ucp.shopping.orders', 'dev.ucp.shopping.webhooks']);
        $this->assertSame(
            ['dev.ucp.shopping.orders', 'dev.ucp.shopping.webhooks'],
            $result['ucp']['capabilities']
        );
    }

    public function testErrorIncludesMessagesArray()
    {
        $err = UcpEnvelope::error('invalid_request', 'missing field', 'unrecoverable', 422);

        $this->assertSame('error', $err['ucp']['status']);
        $this->assertSame(Shopwalk_Ucp::UCP_SPEC_VERSION, $err['ucp']['version']);
        $this->assertSame('invalid_request', $err['messages'][0]['code']);
        $this->assertSame('missing field', $err['messages'][0]['content']);
        $this->assertSame('unrecoverable', $err['messages'][0]['severity']);
    }

    public function testErrorSupportsRecoverableSeverity()
    {
        $err = UcpEnvelope::error('insufficient_stock', 'Low stock', 'recoverable');
        $this->assertSame('recoverable', $err['messages'][0]['severity']);
    }

    public function testOkMergesAdditionalBodyKeysAtTopLevel()
    {
        $result = UcpEnvelope::ok([
            'id'     => 'chk_abc',
            'status' => 'incomplete',
        ]);

        $this->assertSame('chk_abc', $result['id']);
        $this->assertSame('incomplete', $result['status']);
    }
}
