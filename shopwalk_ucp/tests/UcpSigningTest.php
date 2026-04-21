<?php
/**
 * Tests for UcpSigning — HMAC-SHA256 webhook fallback + store-key idempotence.
 *
 * RS256 asymmetric signing relies on openssl and a generated keypair; we
 * test the HMAC fallback path explicitly and assert that signWebhook emits
 * the expected RFC 9421 header set.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/UcpConfig.php';
require_once __DIR__ . '/../classes/UcpSigning.php';

final class UcpSigningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configuration::reset();
        // Seed a known HMAC secret so we can assert determinism.
        Configuration::updateValue(UcpConfig::K_WEBHOOK_TOKEN, 'test-webhook-secret');
    }

    public function testHmacSignIsDeterministic()
    {
        $a = UcpSigning::signHmac('hello world');
        $b = UcpSigning::signHmac('hello world');
        $this->assertSame($a, $b);
    }

    public function testHmacSignVariesWithPayload()
    {
        $a = UcpSigning::signHmac('hello');
        $b = UcpSigning::signHmac('world');
        $this->assertNotSame($a, $b);
    }

    public function testHmacSignVariesWithSecret()
    {
        $a = UcpSigning::signHmac('payload');
        Configuration::updateValue(UcpConfig::K_WEBHOOK_TOKEN, 'different-secret');
        $b = UcpSigning::signHmac('payload');
        $this->assertNotSame($a, $b);
    }

    public function testSignWebhookEmitsRequiredHeaders()
    {
        $headers = UcpSigning::signWebhook('{"hello":"world"}', 'evt_abc123');

        $this->assertArrayHasKey('Content-Digest', $headers);
        $this->assertArrayHasKey('Signature-Input', $headers);
        $this->assertArrayHasKey('Signature', $headers);
        $this->assertArrayHasKey('Webhook-Id', $headers);
        $this->assertArrayHasKey('Webhook-Timestamp', $headers);

        $this->assertSame('evt_abc123', $headers['Webhook-Id']);
        $this->assertMatchesRegularExpression('/^\d+$/', $headers['Webhook-Timestamp']);
        $this->assertStringStartsWith('sha-256=:', $headers['Content-Digest']);
        $this->assertStringStartsWith('sig1=(', $headers['Signature-Input']);
        $this->assertStringStartsWith('sig1=:', $headers['Signature']);
    }

    public function testContentDigestIsBase64SHA256OfBody()
    {
        $body    = '{"order_id":42}';
        $headers = UcpSigning::signWebhook($body, 'evt_1');

        $expected = 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':';
        $this->assertSame($expected, $headers['Content-Digest']);
    }

    public function testGenerateSigningKeysIsIdempotent()
    {
        if (!function_exists('openssl_pkey_new')) {
            $this->markTestSkipped('openssl not available');
        }

        UcpConfig::generateSigningKeysIfMissing();
        $first = UcpConfig::privateKeyPem();

        // Calling again must NOT rotate.
        UcpConfig::generateSigningKeysIfMissing();
        $second = UcpConfig::privateKeyPem();

        $this->assertSame($first, $second, 'Signing keypair must not rotate on repeat calls');
        $this->assertNotEmpty($first);
    }

    public function testVerifyInboundIsStubbedPassthroughInV01()
    {
        // v0.1 stubs inbound verification — spec hardens it before public
        // marketplace submission. Test locks the behavior so a future PR
        // that implements real verification has to explicitly update it.
        $this->assertTrue(
            UcpSigning::verifyInboundRequestSignature('any', 'body', 'https://agent/.well-known/ucp')
        );
    }
}
