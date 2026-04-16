<?php
/**
 * ObjectModel wrapper for ps_ucp_oauth_clients.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpOAuthClient extends ObjectModel
{
    /** @var string */ public $client_id;
    /** @var string */ public $client_secret_hash;
    /** @var string */ public $name;
    /** @var string */ public $redirect_uris;
    /** @var string */ public $scopes_allowed;
    /** @var string */ public $signing_jwk;
    /** @var string */ public $ucp_profile_url;
    /** @var string */ public $date_add;
    /** @var string */ public $date_upd;

    public static $definition = [
        'table'   => 'ucp_oauth_clients',
        'primary' => 'id_ucp_oauth_client',
        'fields'  => [
            'client_id'          => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 64,  'required' => true],
            'client_secret_hash' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 255, 'required' => true],
            'name'               => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 255, 'required' => true],
            'redirect_uris'      => ['type' => self::TYPE_HTML,   'required' => true],
            'scopes_allowed'     => ['type' => self::TYPE_HTML,   'required' => true],
            'signing_jwk'        => ['type' => self::TYPE_HTML,   'required' => false],
            'ucp_profile_url'    => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 512, 'required' => false],
            'date_add'           => ['type' => self::TYPE_DATE,   'validate' => 'isDate',   'required' => true],
            'date_upd'           => ['type' => self::TYPE_DATE,   'validate' => 'isDate',   'required' => true],
        ],
    ];

    public static function findByClientId(string $clientId): ?self
    {
        $row = Db::getInstance()->getRow('
            SELECT * FROM `' . _DB_PREFIX_ . 'ucp_oauth_clients`
             WHERE `client_id` = "' . pSQL($clientId) . '"
             LIMIT 1
        ');
        if (!$row) {
            return null;
        }
        $c = new self();
        $c->hydrate($row);
        $c->id = (int) $row['id_ucp_oauth_client'];
        return $c;
    }

    public static function register(string $name, array $redirectUris, array $scopes, ?string $profileUrl = null, ?string $jwk = null): array
    {
        $clientId     = 'cl_' . bin2hex(random_bytes(12));
        $clientSecret = bin2hex(random_bytes(24));

        $client = new self();
        $client->client_id          = $clientId;
        $client->client_secret_hash = password_hash($clientSecret, PASSWORD_BCRYPT);
        $client->name               = $name;
        $client->redirect_uris      = json_encode($redirectUris, JSON_UNESCAPED_SLASHES);
        $client->scopes_allowed     = json_encode($scopes);
        $client->ucp_profile_url    = $profileUrl ?: '';
        $client->signing_jwk        = $jwk ?: '';
        $client->date_add           = date('Y-m-d H:i:s');
        $client->date_upd           = date('Y-m-d H:i:s');
        $client->save();

        return [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'id'            => (int) $client->id,
        ];
    }

    public function verifySecret(string $candidate): bool
    {
        return is_string($this->client_secret_hash)
            && password_verify($candidate, $this->client_secret_hash);
    }

    public function redirectUris(): array
    {
        $data = json_decode((string) $this->redirect_uris, true);
        return is_array($data) ? $data : [];
    }

    public function scopes(): array
    {
        $data = json_decode((string) $this->scopes_allowed, true);
        return is_array($data) ? $data : [];
    }
}
