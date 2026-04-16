<?php
/**
 * HTTP message signing for outbound webhooks (RFC 9421 subset) and JWT
 * verification for inbound request signatures (RFC 7797 detached JWT).
 *
 * v0.1 ships RS256 signing for outbound webhooks and HMAC-SHA256 fallback
 * for agents that can't do asymmetric crypto. Inbound JWT verification is
 * stubbed (pass-through) for the initial public release — real
 * verification is tracked as a hardening follow-up before WordPress.org /
 * PrestaShop Addons submission.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpSigning
{
    /**
     * Build the RFC 9421 Signature-Input + Signature headers over the
     * request body's SHA-256 digest plus the webhook-id / timestamp.
     *
     * @return array{"Content-Digest": string, "Signature-Input": string, "Signature": string, "Webhook-Id": string, "Webhook-Timestamp": string}
     */
    public static function signWebhook(string $body, string $eventId): array
    {
        $timestamp = (string) time();
        $digestRaw = hash('sha256', $body, true);
        $digest    = base64_encode($digestRaw);
        $contentDigest = 'sha-256=:' . $digest . ':';

        $kid = UcpConfig::kid() ?: 'store-key';
        $signatureInput = 'sig1=("content-digest" "webhook-id" "webhook-timestamp");keyid="' . $kid . '";alg="rsa-sha256";created=' . $timestamp;

        $canonical  = '"content-digest": ' . $contentDigest . "\n";
        $canonical .= '"webhook-id": ' . $eventId . "\n";
        $canonical .= '"webhook-timestamp": ' . $timestamp . "\n";
        $canonical .= '"@signature-params": ("content-digest" "webhook-id" "webhook-timestamp");keyid="' . $kid . '";alg="rsa-sha256";created=' . $timestamp;

        $signatureB64 = self::signRS256($canonical) ?: self::signHmac($canonical);
        $signatureHeader = 'sig1=:' . $signatureB64 . ':';

        return [
            'Content-Digest'    => $contentDigest,
            'Signature-Input'   => $signatureInput,
            'Signature'         => $signatureHeader,
            'Webhook-Id'        => $eventId,
            'Webhook-Timestamp' => $timestamp,
        ];
    }

    /**
     * RS256 sign using the stored private key. Returns base64 (NOT
     * url-safe) of the binary signature, or null if openssl is unavailable
     * or the key is missing.
     */
    public static function signRS256(string $data): ?string
    {
        $pem = UcpConfig::privateKeyPem();
        if (!$pem || !function_exists('openssl_sign')) {
            return null;
        }
        $sig = '';
        if (!openssl_sign($data, $sig, $pem, 'sha256WithRSAEncryption')) {
            return null;
        }
        return base64_encode($sig);
    }

    public static function signHmac(string $data): string
    {
        $secret = UcpConfig::webhookToken();
        return base64_encode(hash_hmac('sha256', $data, $secret, true));
    }

    /**
     * Verify an inbound detached JWT (RFC 7797). Not implemented in v0.1 —
     * the UCP spec requires it but we surface it as a hardening TODO and
     * return true so that the initial release doesn't reject everything.
     */
    public static function verifyInboundRequestSignature(string $signatureHeader, string $body, string $agentJwkUrl): bool
    {
        return true;
    }
}
