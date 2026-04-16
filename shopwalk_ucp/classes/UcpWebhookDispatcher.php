<?php
/**
 * Enqueue UCP events + flush outbound queue to subscribed agents.
 *
 * Enqueueing happens synchronously inside PS hooks; flushing runs via the
 * webhookflush controller hit by a system cron.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpWebhookDispatcher
{
    public static function enqueueOrderCreatedEvent(int $idOrder): void
    {
        self::enqueueForOrder($idOrder, 'order.created');
    }

    public static function enqueueOrderStatusEvent(int $idOrder, OrderState $state): void
    {
        $type = self::mapPsStateToEventType((int) $state->id);
        if ($type) {
            self::enqueueForOrder($idOrder, $type);
        }
    }

    protected static function mapPsStateToEventType(int $psStateId): ?string
    {
        if ($psStateId === (int) Configuration::get('PS_OS_CANCELED')) {
            return 'order.canceled';
        }
        if ($psStateId === (int) Configuration::get('PS_OS_SHIPPING')) {
            return 'order.shipped';
        }
        if ($psStateId === (int) Configuration::get('PS_OS_DELIVERED')) {
            return 'order.delivered';
        }
        if ($psStateId === (int) Configuration::get('PS_OS_REFUND')) {
            return 'order.refunded';
        }
        if ($psStateId === (int) Configuration::get('PS_OS_PAYMENT')
            || $psStateId === (int) Configuration::get('PS_OS_PREPARATION')) {
            return 'order.processing';
        }
        return null;
    }

    protected static function enqueueForOrder(int $idOrder, string $eventType): void
    {
        require_once __DIR__ . '/UcpWebhookSubscription.php';
        require_once __DIR__ . '/UcpWebhookQueue.php';
        require_once __DIR__ . '/UcpOrderMapper.php';

        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        $subs = UcpWebhookSubscription::activeForEvent($eventType);
        if (!$subs) {
            return;
        }
        $payload = UcpOrderMapper::map($order, true);
        foreach ($subs as $sub) {
            UcpWebhookQueue::enqueue($sub->subscription_id, $eventType, $payload);
        }
    }

    /**
     * Flush up to $limit queue entries. Called by the webhookflush
     * controller (hit by system cron every minute).
     */
    public static function flush(int $limit = 50): array
    {
        require_once __DIR__ . '/UcpWebhookSubscription.php';
        require_once __DIR__ . '/UcpWebhookQueue.php';

        $items = UcpWebhookQueue::pending($limit);
        $delivered = 0;
        $failed    = 0;

        foreach ($items as $item) {
            $sub = UcpWebhookSubscription::findBySubscriptionId($item->subscription_id);
            if (!$sub || !$sub->active) {
                $item->markFailed('subscription inactive or deleted');
                $failed++;
                continue;
            }
            $body = (string) $item->payload;
            $headers = UcpSigning::signWebhook($body, $item->event_id);
            $headers['UCP-Agent'] = 'profile="' . rtrim(UcpConfig::storeUrl(), '/') . '/.well-known/ucp"';
            $headers['Content-Type'] = 'application/json';
            $ok = self::post($sub->callback_url, $body, $headers);
            if ($ok) {
                $item->markDelivered();
                $delivered++;
            } else {
                $item->markFailed('delivery failed');
                $failed++;
            }
        }
        return ['delivered' => $delivered, 'failed' => $failed, 'processed' => count($items)];
    }

    protected static function post(string $url, string $body, array $headers): bool
    {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $h,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code >= 200 && $code < 300;
        }
        // Fallback: file_get_contents + stream context
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $h),
                'content'       => $body,
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
        ]);
        $result = @file_get_contents($url, false, $ctx);
        if (!isset($http_response_header[0])) {
            return false;
        }
        preg_match('#\s(\d{3})\s#', $http_response_header[0], $m);
        $code = isset($m[1]) ? (int) $m[1] : 0;
        return $code >= 200 && $code < 300;
    }
}
