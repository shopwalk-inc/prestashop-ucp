<?php
// French translations for Shopwalk UCP.
global $_MODULE;
$_MODULE = is_array($_MODULE) ? $_MODULE : [];

// Module metadata
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_displayname'] = 'Shopwalk UCP';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_description'] = 'Adaptateur Universal Commerce Protocol pour PrestaShop. Expose un checkout UCP, une identité OAuth, des commandes et des webhooks pour que tout agent d\'achat IA puisse transacter avec la boutique.';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_confirm_uninstall'] = 'Êtes-vous sûr ? La désinstallation supprime les endpoints UCP, clients OAuth, jetons, sessions et abonnements aux webhooks. Les commandes confirmées restent dans PrestaShop.';

// Admin tab
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_admin_tab'] = 'Shopwalk UCP';

// Payment method
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_pay_via_ucp'] = 'Payer via UCP';

// Self-test check labels
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_signing_keys'] = 'Paire de clés de signature générée';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_webhook_token'] = 'Jeton de purge des webhooks';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_admin_tab'] = 'Onglet d\'administration installé';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_openssl'] = 'Extension OpenSSL';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_friendly_urls'] = 'URL simplifiées activées';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_ssl'] = 'SSL activé sur cette boutique';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_discovery'] = '/.well-known/ucp accessible';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_oauth_metadata'] = 'Endpoint OAuth authorize accessible';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_payment_registered'] = 'Méthode de paiement « Payer via UCP » enregistrée';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_adapter_ready'] = 'Au moins un adaptateur de paiement UCP prêt';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_cron_flush'] = 'Cron de purge des webhooks accessible';

// Error messages
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_err_invalid_token'] = 'Jeton Bearer requis';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_err_invalid_license'] = 'Un en-tête X-License-Key valide est requis. Cette boutique n\'a pas activé Shopwalk Direct Checkout.';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_err_method_not_allowed'] = 'Combinaison route / méthode non supportée';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_err_session_not_found'] = 'Session introuvable';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_err_session_not_ready'] = 'Appelez PUT pour fournir les données acheteur + fulfillment d\'abord';
