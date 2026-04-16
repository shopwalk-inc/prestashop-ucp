<?php
/**
 * ObjectModel for ps_ucp_oauth_tokens.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpOAuthToken extends ObjectModel
{
    const TYPE_ACCESS = 'access';
    const TYPE_REFRESH = 'refresh';
    const TYPE_AUTH_CODE = 'authorization_code';

    /** @var string */ public $token_type;
    /** @var string */ public $token_hash;
    /** @var string */ public $client_id;
    /** @var int|null */ public $id_customer;
    /** @var string */ public $scopes;
    /** @var string|null */ public $code_challenge;
    /** @var string|null */ public $code_challenge_method;
    /** @var string|null */ public $redirect_uri;
    /** @var string */ public $expires_at;
    /** @var string|null */ public $revoked_at;
    /** @var string */ public $date_add;

    public static $definition = [
        'table'   => 'ucp_oauth_tokens',
        'primary' => 'id_ucp_oauth_token',
        'fields'  => [
            'token_type'            => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 32,  'required' => true],
            'token_hash'            => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 128, 'required' => true],
            'client_id'             => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 64,  'required' => true],
            'id_customer'           => ['type' => self::TYPE_INT,    'validate' => 'isUnsignedId'],
            'scopes'                => ['type' => self::TYPE_HTML,   'required' => true],
            'code_challenge'        => ['type' => self::TYPE_STRING, 'size' => 128],
            'code_challenge_method' => ['type' => self::TYPE_STRING, 'size' => 16],
            'redirect_uri'          => ['type' => self::TYPE_STRING, 'size' => 512],
            'expires_at'            => ['type' => self::TYPE_DATE,   'validate' => 'isDate', 'required' => true],
            'revoked_at'            => ['type' => self::TYPE_DATE,   'validate' => 'isDate'],
            'date_add'              => ['type' => self::TYPE_DATE,   'validate' => 'isDate', 'required' => true],
        ],
    ];

    public static function mint(string $type, string $clientId, ?int $idCustomer, array $scopes, int $ttlSeconds, array $extras = []): array
    {
        $token = bin2hex(random_bytes(24));
        $hash  = hash('sha256', $token);

        $m = new self();
        $m->token_type            = $type;
        $m->token_hash            = $hash;
        $m->client_id             = $clientId;
        $m->id_customer           = $idCustomer;
        $m->scopes                = json_encode($scopes);
        $m->code_challenge        = $extras['code_challenge']        ?? null;
        $m->code_challenge_method = $extras['code_challenge_method'] ?? null;
        $m->redirect_uri          = $extras['redirect_uri']          ?? null;
        $m->expires_at            = date('Y-m-d H:i:s', time() + $ttlSeconds);
        $m->date_add              = date('Y-m-d H:i:s');
        $m->save();

        return [
            'token'      => $token,
            'hash'       => $hash,
            'expires_in' => $ttlSeconds,
            'id'         => (int) $m->id,
        ];
    }

    public static function findValid(string $token, string $type): ?self
    {
        $hash = hash('sha256', $token);
        $row  = Db::getInstance()->getRow('
            SELECT * FROM `' . _DB_PREFIX_ . 'ucp_oauth_tokens`
             WHERE `token_hash` = "' . pSQL($hash) . '"
               AND `token_type` = "' . pSQL($type) . '"
               AND `revoked_at` IS NULL
               AND `expires_at` > NOW()
             LIMIT 1
        ');
        if (!$row) {
            return null;
        }
        $m = new self();
        $m->hydrate($row);
        $m->id = (int) $row['id_ucp_oauth_token'];
        return $m;
    }

    public function revoke(): void
    {
        $this->revoked_at = date('Y-m-d H:i:s');
        $this->save();
    }

    public function getScopes(): array
    {
        $data = json_decode((string) $this->scopes, true);
        return is_array($data) ? $data : [];
    }
}
