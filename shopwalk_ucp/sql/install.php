<?php
/**
 * Shopwalk UCP — table creation on module install.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

$sql = [];

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ucp_oauth_clients` (
    `id_ucp_oauth_client`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`             VARCHAR(64)  NOT NULL,
    `client_secret_hash`    VARCHAR(255) NOT NULL,
    `name`                  VARCHAR(255) NOT NULL,
    `redirect_uris`         TEXT         NOT NULL,
    `scopes_allowed`        TEXT         NOT NULL,
    `signing_jwk`           TEXT                   DEFAULT NULL,
    `ucp_profile_url`       VARCHAR(512)           DEFAULT NULL,
    `date_add`              DATETIME     NOT NULL,
    `date_upd`              DATETIME     NOT NULL,
    PRIMARY KEY (`id_ucp_oauth_client`),
    UNIQUE KEY `idx_client_id` (`client_id`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ucp_oauth_tokens` (
    `id_ucp_oauth_token`    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `token_type`            ENUM(\'access\',\'refresh\',\'authorization_code\') NOT NULL,
    `token_hash`            VARCHAR(128) NOT NULL,
    `client_id`             VARCHAR(64)  NOT NULL,
    `id_customer`           INT UNSIGNED          DEFAULT NULL,
    `scopes`                TEXT         NOT NULL,
    `code_challenge`        VARCHAR(128)          DEFAULT NULL,
    `code_challenge_method` VARCHAR(16)           DEFAULT NULL,
    `redirect_uri`          VARCHAR(512)          DEFAULT NULL,
    `expires_at`            DATETIME     NOT NULL,
    `revoked_at`            DATETIME              DEFAULT NULL,
    `date_add`              DATETIME     NOT NULL,
    PRIMARY KEY (`id_ucp_oauth_token`),
    UNIQUE KEY `idx_token_hash` (`token_hash`),
    KEY `idx_client_customer` (`client_id`, `id_customer`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ucp_checkout_sessions` (
    `id_ucp_checkout_session` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id`            VARCHAR(64)  NOT NULL,
    `client_id`             VARCHAR(64)  NOT NULL,
    `id_customer`           INT UNSIGNED          DEFAULT NULL,
    `id_cart`               INT UNSIGNED          DEFAULT NULL,
    `id_order`              INT UNSIGNED          DEFAULT NULL,
    `status`                VARCHAR(32)  NOT NULL,
    `currency`              CHAR(3)      NOT NULL,
    `line_items`            LONGTEXT     NOT NULL,
    `buyer`                 LONGTEXT              DEFAULT NULL,
    `fulfillment`           LONGTEXT              DEFAULT NULL,
    `payment`               LONGTEXT              DEFAULT NULL,
    `totals`                LONGTEXT              DEFAULT NULL,
    `messages`              LONGTEXT              DEFAULT NULL,
    `idempotency_keys`      LONGTEXT              DEFAULT NULL,
    `date_add`              DATETIME     NOT NULL,
    `date_upd`              DATETIME     NOT NULL,
    `expires_at`            DATETIME     NOT NULL,
    PRIMARY KEY (`id_ucp_checkout_session`),
    UNIQUE KEY `idx_session_id` (`session_id`),
    KEY `idx_status` (`status`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ucp_webhook_subscriptions` (
    `id_ucp_webhook_subscription` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subscription_id`       VARCHAR(64)  NOT NULL,
    `client_id`             VARCHAR(64)  NOT NULL,
    `callback_url`          VARCHAR(512) NOT NULL,
    `event_types`           TEXT         NOT NULL,
    `secret_hash`           VARCHAR(255) NOT NULL,
    `active`                TINYINT(1)   NOT NULL DEFAULT 1,
    `date_add`              DATETIME     NOT NULL,
    PRIMARY KEY (`id_ucp_webhook_subscription`),
    UNIQUE KEY `idx_subscription_id` (`subscription_id`),
    KEY `idx_client` (`client_id`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ucp_webhook_queue` (
    `id_ucp_webhook_queue`  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_id`              VARCHAR(64)  NOT NULL,
    `subscription_id`       VARCHAR(64)  NOT NULL,
    `event_type`            VARCHAR(64)  NOT NULL,
    `payload`               LONGTEXT     NOT NULL,
    `attempts`              INT UNSIGNED NOT NULL DEFAULT 0,
    `next_attempt_at`       DATETIME     NOT NULL,
    `delivered_at`          DATETIME              DEFAULT NULL,
    `failed_at`             DATETIME              DEFAULT NULL,
    `last_error`            TEXT                  DEFAULT NULL,
    `date_add`              DATETIME     NOT NULL,
    PRIMARY KEY (`id_ucp_webhook_queue`),
    KEY `idx_next_attempt` (`next_attempt_at`),
    KEY `idx_subscription` (`subscription_id`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

foreach ($sql as $query) {
    if (!Db::getInstance()->execute($query)) {
        return false;
    }
}

return true;
