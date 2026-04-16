<?php
/**
 * Shopwalk UCP — cleanup on module uninstall.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

$tables = [
    'ucp_oauth_clients',
    'ucp_oauth_tokens',
    'ucp_checkout_sessions',
    'ucp_webhook_subscriptions',
    'ucp_webhook_queue',
];

foreach ($tables as $t) {
    Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $t . '`;');
}

return true;
