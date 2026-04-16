<?php
/**
 * ObjectModel for ps_ucp_checkout_sessions.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpCheckoutSession extends ObjectModel
{
    const STATUS_INCOMPLETE           = 'incomplete';
    const STATUS_READY_FOR_COMPLETE   = 'ready_for_complete';
    const STATUS_COMPLETED            = 'completed';
    const STATUS_CANCELED             = 'canceled';
    const STATUS_REQUIRES_ESCALATION  = 'requires_escalation';

    /** @var string */ public $session_id;
    /** @var string */ public $client_id;
    /** @var int|null */ public $id_customer;
    /** @var int|null */ public $id_cart;
    /** @var int|null */ public $id_order;
    /** @var string */ public $status;
    /** @var string */ public $currency;
    /** @var string */ public $line_items;
    /** @var string|null */ public $buyer;
    /** @var string|null */ public $fulfillment;
    /** @var string|null */ public $payment;
    /** @var string|null */ public $totals;
    /** @var string|null */ public $messages;
    /** @var string|null */ public $idempotency_keys;
    /** @var string */ public $date_add;
    /** @var string */ public $date_upd;
    /** @var string */ public $expires_at;

    public static $definition = [
        'table'   => 'ucp_checkout_sessions',
        'primary' => 'id_ucp_checkout_session',
        'fields'  => [
            'session_id'       => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 64,  'required' => true],
            'client_id'        => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 64,  'required' => true],
            'id_customer'      => ['type' => self::TYPE_INT,    'validate' => 'isUnsignedId'],
            'id_cart'          => ['type' => self::TYPE_INT,    'validate' => 'isUnsignedId'],
            'id_order'         => ['type' => self::TYPE_INT,    'validate' => 'isUnsignedId'],
            'status'           => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 32, 'required' => true],
            'currency'         => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 3,  'required' => true],
            'line_items'       => ['type' => self::TYPE_HTML,   'required' => true],
            'buyer'            => ['type' => self::TYPE_HTML],
            'fulfillment'      => ['type' => self::TYPE_HTML],
            'payment'          => ['type' => self::TYPE_HTML],
            'totals'           => ['type' => self::TYPE_HTML],
            'messages'         => ['type' => self::TYPE_HTML],
            'idempotency_keys' => ['type' => self::TYPE_HTML],
            'date_add'         => ['type' => self::TYPE_DATE,   'validate' => 'isDate', 'required' => true],
            'date_upd'         => ['type' => self::TYPE_DATE,   'validate' => 'isDate', 'required' => true],
            'expires_at'       => ['type' => self::TYPE_DATE,   'validate' => 'isDate', 'required' => true],
        ],
    ];

    public static function findBySessionId(string $sessionId): ?self
    {
        $row = Db::getInstance()->getRow('
            SELECT * FROM `' . _DB_PREFIX_ . 'ucp_checkout_sessions`
             WHERE `session_id` = "' . pSQL($sessionId) . '"
             LIMIT 1
        ');
        if (!$row) {
            return null;
        }
        $s = new self();
        $s->hydrate($row);
        $s->id = (int) $row['id_ucp_checkout_session'];
        return $s;
    }

    public static function newSession(string $clientId, string $currency): self
    {
        $s = new self();
        $s->session_id  = 'chk_' . bin2hex(random_bytes(12));
        $s->client_id   = $clientId;
        $s->status      = self::STATUS_INCOMPLETE;
        $s->currency    = $currency;
        $s->line_items  = '[]';
        $s->date_add    = date('Y-m-d H:i:s');
        $s->date_upd    = date('Y-m-d H:i:s');
        $s->expires_at  = date('Y-m-d H:i:s', time() + UcpConfig::sessionTtl());
        return $s;
    }

    public function toUcpObject(): array
    {
        $body = [
            'id'          => $this->session_id,
            'status'      => $this->status,
            'currency'    => $this->currency,
            'line_items'  => self::decodeJson($this->line_items),
            'buyer'       => self::decodeJson($this->buyer),
            'fulfillment' => self::decodeJson($this->fulfillment),
            'payment'     => self::decodeJson($this->payment),
            'totals'      => self::decodeJson($this->totals),
            'messages'    => self::decodeJson($this->messages) ?: [],
        ];
        if ($this->id_order) {
            $body['order_id'] = (string) $this->id_order;
        }
        return UcpEnvelope::ok($body, ['dev.ucp.shopping.checkout']);
    }

    protected static function decodeJson($str)
    {
        if (!$str) {
            return null;
        }
        $d = json_decode((string) $str, true);
        return $d === null ? null : $d;
    }
}
