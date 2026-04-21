<?php
// English translations for Shopwalk UCP.
global $_MODULE;
$_MODULE = is_array($_MODULE) ? $_MODULE : [];

// Module metadata
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_displayname'] = 'Shopwalk UCP';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_description'] = 'Universal Commerce Protocol adapter for PrestaShop. Exposes UCP-compliant checkout, OAuth identity, orders and webhooks so any AI shopping agent can transact with this store.';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_confirm_uninstall'] = 'Are you sure? Uninstalling removes UCP endpoints, OAuth clients, tokens, sessions and webhook subscriptions. Completed orders stay in PrestaShop.';

// Admin tab
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_admin_tab'] = 'Shopwalk UCP';

// Payment method
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_pay_via_ucp'] = 'Pay via UCP';

// Self-test check labels
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_signing_keys'] = 'Signing keypair generated';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_webhook_token'] = 'Webhook flush token';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_admin_tab'] = 'Admin tab installed';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_openssl'] = 'OpenSSL extension';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_friendly_urls'] = 'Friendly URLs enabled';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_ssl'] = 'SSL enabled on this shop';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_discovery'] = '/.well-known/ucp reachable';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_oauth_metadata'] = 'OAuth authorize endpoint reachable';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_payment_registered'] = 'Payment gateway "Pay via UCP" registered';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_adapter_ready'] = 'At least one UcpPaymentAdapter ready';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_cron_flush'] = 'Webhook flush cron reachable';

// Error messages
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_err_invalid_token'] = 'Bearer token required';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_err_invalid_license'] = 'A valid X-License-Key header is required. This store has not activated Shopwalk Direct Checkout.';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_err_method_not_allowed'] = 'Route / method combination not supported';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_err_session_not_found'] = 'Session not found';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_err_session_not_ready'] = 'Call PUT to supply buyer + fulfillment first';
