<?php
/**
 * ObjectModel for ps_ucp_webhook_queue + delivery helpers.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpWebhookQueue extends ObjectModel
{
    /** @var string */ public $event_id;
    /** @var string */ public $subscription_id;
    /** @var string */ public $event_type;
    /** @var string */ public $payload;
    /** @var int    */ public $attempts;
    /** @var string */ public $next_attempt_at;
    /** @var string|null */ public $delivered_at;
    /** @var string|null */ public $failed_at;
    /** @var string|null */ public $last_error;
    /** @var string */ public $date_add;

    public static $definition = [
        'table'   => 'ucp_webhook_queue',
        'primary' => 'id_ucp_webhook_queue',
        'fields'  => [
            'event_id'        => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 64, 'required' => true],
            'subscription_id' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 64, 'required' => true],
            'event_type'      => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 64, 'required' => true],
            'payload'         => ['type' => self::TYPE_HTML,   'required' => true],
            'attempts'        => ['type' => self::TYPE_INT,    'validate' => 'isUnsignedInt'],
            'next_attempt_at' => ['type' => self::TYPE_DATE,   'validate' => 'isDate', 'required' => true],
            'delivered_at'    => ['type' => self::TYPE_DATE,   'validate' => 'isDate'],
            'failed_at'       => ['type' => self::TYPE_DATE,   'validate' => 'isDate'],
            'last_error'      => ['type' => self::TYPE_HTML],
            'date_add'        => ['type' => self::TYPE_DATE,   'validate' => 'isDate', 'required' => true],
        ],
    ];

    public static function enqueue(string $subscriptionId, string $eventType, array $payload): self
    {
        $m = new self();
        $m->event_id        = 'evt_' . bin2hex(random_bytes(12));
        $m->subscription_id = $subscriptionId;
        $m->event_type      = $eventType;
        $m->payload         = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $m->attempts        = 0;
        $m->next_attempt_at = date('Y-m-d H:i:s');
        $m->date_add        = date('Y-m-d H:i:s');
        $m->save();
        return $m;
    }

    public static function pending(int $limit = 50): array
    {
        $rows = Db::getInstance()->executeS('
            SELECT * FROM `' . _DB_PREFIX_ . 'ucp_webhook_queue`
             WHERE `delivered_at` IS NULL
               AND `failed_at` IS NULL
               AND `next_attempt_at` <= NOW()
             ORDER BY `next_attempt_at` ASC
             LIMIT ' . (int) $limit
        );
        $out = [];
        foreach ($rows ?: [] as $r) {
            $m = new self();
            $m->hydrate($r);
            $m->id = (int) $r['id_ucp_webhook_queue'];
            $out[] = $m;
        }
        return $out;
    }

    public function markDelivered(): void
    {
        $this->delivered_at = date('Y-m-d H:i:s');
        $this->save();
    }

    public function markFailed(string $err, int $maxAttempts = 5): void
    {
        $this->attempts  = (int) $this->attempts + 1;
        $this->last_error = Tools::substr($err, 0, 1024);
        if ($this->attempts >= $maxAttempts) {
            $this->failed_at = date('Y-m-d H:i:s');
        } else {
            $delay = min(3600, 30 * (2 ** ($this->attempts - 1)));
            $this->next_attempt_at = date('Y-m-d H:i:s', time() + $delay);
        }
        $this->save();
    }
}
