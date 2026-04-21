<?php
/**
 * PHPUnit bootstrap for the Shopwalk UCP PrestaShop module.
 *
 * Tests under tests/ target pure helpers (envelope, totals, signing,
 * payment router) that don't require a running PrestaShop installation.
 * This bootstrap defines the PS constants those helpers guard on, and
 * provides minimal stubs for the handful of PS classes they reference.
 *
 * Integration-level tests (CheckoutEngine, front controllers, ObjectModels)
 * require a real PrestaShop install and aren't covered here — they're part
 * of the CI self-test harness on a disposable PS container.
 */

if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.1.0-test');
}
if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}
if (!defined('_MYSQL_ENGINE_')) {
    define('_MYSQL_ENGINE_', 'InnoDB');
}

// ─── Configuration stub ────────────────────────────────────────────────
//
// The real PS Configuration reads from a DB table; the stub is an
// in-memory array so tests can set/get keys without a database.

if (!class_exists('Configuration')) {
    class Configuration
    {
        protected static $store = [];

        public static function get($key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
        {
            return array_key_exists($key, self::$store) ? self::$store[$key] : $default;
        }

        public static function updateValue($key, $value, $html = false, $idShopGroup = null, $idShop = null)
        {
            self::$store[$key] = $value;
            return true;
        }

        public static function deleteByName($key)
        {
            unset(self::$store[$key]);
            return true;
        }

        public static function reset()
        {
            self::$store = [];
        }
    }
}

// Shopwalk_Ucp is referenced by UcpEnvelope for spec version. Provide a
// minimal shim so tests don't need to load the module file itself.
if (!class_exists('Shopwalk_Ucp')) {
    class Shopwalk_Ucp
    {
        const UCP_SPEC_VERSION = '2026-04-08';
        const MODULE_VERSION   = '0.1.0';
    }
}

// Minimal Order stub so UcpPaymentRouter::authorize() signature is valid
// in tests (the router accepts anything that looks like an Order — the
// adapter is what actually reads fields off it).
if (!class_exists('Order')) {
    class Order
    {
        public $id = 0;
        public $total_paid = 0.0;
        public $id_currency = 0;
        public $payment = '';
        public $transaction_id = '';

        public function save()
        {
            return true;
        }
    }
}

// Every UCP class top-level guard is `defined('_PS_VERSION_')`; bootstrap
// covered that above. Nothing else to wire up — tests `require_once` the
// classes under test explicitly.
