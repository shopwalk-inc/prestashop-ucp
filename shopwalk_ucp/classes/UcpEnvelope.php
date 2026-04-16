<?php
/**
 * Every UCP response body includes a `ucp` envelope.
 * See UCP_SPEC_COMPLIANCE.md §2.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpEnvelope
{
    public static function ok(array $body, array $capabilities = []): array
    {
        return array_merge([
            'ucp' => [
                'version'      => Shopwalk_Ucp::UCP_SPEC_VERSION,
                'capabilities' => $capabilities,
                'status'       => 'ok',
            ],
        ], $body);
    }

    public static function error(string $code, string $message, string $severity = 'unrecoverable', int $statusCode = 400): array
    {
        return [
            'ucp' => [
                'version' => Shopwalk_Ucp::UCP_SPEC_VERSION,
                'status'  => 'error',
            ],
            'messages' => [
                [
                    'type'     => 'error',
                    'code'     => $code,
                    'content'  => $message,
                    'severity' => $severity,
                ],
            ],
        ];
    }

    /**
     * Convenience: write JSON + UCP envelope and exit. Used from
     * ModuleFrontController controllers which can't return structured data.
     */
    public static function respond(array $payload, int $httpStatus = 200): void
    {
        http_response_code($httpStatus);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-UCP-Spec-Version: ' . Shopwalk_Ucp::UCP_SPEC_VERSION);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function respondError(string $code, string $message, int $httpStatus, string $severity = 'unrecoverable'): void
    {
        self::respond(self::error($code, $message, $severity, $httpStatus), $httpStatus);
    }
}
