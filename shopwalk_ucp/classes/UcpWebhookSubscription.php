<?php
/**
 * ObjectModel for ps_ucp_webhook_subscriptions.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpWebhookSubscription extends ObjectModel
{
    /** @var string */ public $subscription_id;
    /** @var string */ public $client_id;
    /** @var string */ public $callback_url;
    /** @var string */ public $event_types;
    /** @var string */ public $secret_hash;
    /** @var int    */ public $active;
    /** @var string */ public $date_add;

    public static $definition = [
        'table'   => 'ucp_webhook_subscriptions',
        'primary' => 'id_ucp_webhook_subscription',
        'fields'  => [
            'subscription_id' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 64,  'required' => true],
            'client_id'       => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 64,  'required' => true],
            'callback_url'    => ['type' => self::TYPE_STRING, 'validate' => 'isUrl',    'size' => 512, 'required' => true],
            'event_types'     => ['type' => self::TYPE_HTML,   'required' => true],
            'secret_hash'     => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 255, 'required' => true],
            'active'          => ['type' => self::TYPE_BOOL,   'validate' => 'isBool',   'required' => true],
            'date_add'        => ['type' => self::TYPE_DATE,   'validate' => 'isDate',   'required' => true],
        ],
    ];

    public static function findBySubscriptionId(string $id): ?self
    {
        $row = Db::getInstance()->getRow('
            SELECT * FROM `' . _DB_PREFIX_ . 'ucp_webhook_subscriptions`
             WHERE `subscription_id` = "' . pSQL($id) . '"
             LIMIT 1
        ');
        if (!$row) {
            return null;
        }
        $m = new self();
        $m->hydrate($row);
        $m->id = (int) $row['id_ucp_webhook_subscription'];
        return $m;
    }

    public static function activeForEvent(string $eventType): array
    {
        $rows = Db::getInstance()->executeS('
            SELECT * FROM `' . _DB_PREFIX_ . 'ucp_webhook_subscriptions`
             WHERE `active` = 1
        ');
        $out = [];
        foreach ($rows ?: [] as $r) {
            $types = json_decode((string) $r['event_types'], true);
            if (!is_array($types)) {
                continue;
            }
            if (in_array($eventType, $types, true) || in_array('*', $types, true)) {
                $m = new self();
                $m->hydrate($r);
                $m->id = (int) $r['id_ucp_webhook_subscription'];
                $out[] = $m;
            }
        }
        return $out;
    }

    public static function register(string $clientId, string $callbackUrl, array $events): array
    {
        $subId  = 'wh_' . bin2hex(random_bytes(12));
        $secret = bin2hex(random_bytes(24));

        $m = new self();
        $m->subscription_id = $subId;
        $m->client_id       = $clientId;
        $m->callback_url    = $callbackUrl;
        $m->event_types     = json_encode($events);
        $m->secret_hash     = password_hash($secret, PASSWORD_BCRYPT);
        $m->active          = 1;
        $m->date_add        = date('Y-m-d H:i:s');
        $m->save();

        return [
            'id'     => $subId,
            'secret' => $secret,
        ];
    }

    public function getEventTypes(): array
    {
        $d = json_decode((string) $this->event_types, true);
        return is_array($d) ? $d : [];
    }
}
