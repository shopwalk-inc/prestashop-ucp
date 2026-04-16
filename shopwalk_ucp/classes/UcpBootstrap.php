<?php
/**
 * Install-time bootstrap: seed defaults, register cron tokens, etc.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpBootstrap
{
    public static function onInstall(Module $module)
    {
        // Webhook flush cron token — merchant sets up a system cron hitting
        // /ucp/v1/internal/webhooks/flush?token=... every minute.
        if (!Configuration::get('SHOPWALK_UCP_WEBHOOK_TOKEN')) {
            Configuration::updateValue('SHOPWALK_UCP_WEBHOOK_TOKEN', bin2hex(random_bytes(24)));
        }

        // Session TTL (seconds). 30 min default, admin configurable.
        if (!Configuration::get('SHOPWALK_UCP_SESSION_TTL')) {
            Configuration::updateValue('SHOPWALK_UCP_SESSION_TTL', 1800);
        }

        return true;
    }
}
