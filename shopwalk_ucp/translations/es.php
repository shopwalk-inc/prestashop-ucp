<?php
// Spanish translations for Shopwalk UCP.
global $_MODULE;
$_MODULE = is_array($_MODULE) ? $_MODULE : [];

// Module metadata
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_displayname'] = 'Shopwalk UCP';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_description'] = 'Adaptador del Universal Commerce Protocol para PrestaShop. Expone checkout UCP, identidad OAuth, pedidos y webhooks para que cualquier agente de compras de IA pueda transaccionar con la tienda.';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_confirm_uninstall'] = '¿Está seguro? Desinstalar elimina los endpoints UCP, clientes OAuth, tokens, sesiones y suscripciones a webhooks. Los pedidos completados permanecen en PrestaShop.';

// Admin tab
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_admin_tab'] = 'Shopwalk UCP';

// Payment method
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_pay_via_ucp'] = 'Pagar vía UCP';

// Self-test check labels
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_signing_keys'] = 'Par de claves de firma generado';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_webhook_token'] = 'Token de vaciado de webhooks';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_admin_tab'] = 'Pestaña de administración instalada';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_openssl'] = 'Extensión OpenSSL';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_friendly_urls'] = 'URLs amigables activadas';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_ssl'] = 'SSL activado en esta tienda';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_discovery'] = '/.well-known/ucp accesible';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_oauth_metadata'] = 'Endpoint OAuth authorize accesible';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_payment_registered'] = 'Método de pago «Pagar vía UCP» registrado';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_adapter_ready'] = 'Al menos un UcpPaymentAdapter listo';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_check_cron_flush'] = 'Cron de vaciado de webhooks accesible';

// Error messages
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_err_invalid_token'] = 'Se requiere token Bearer';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_err_invalid_license'] = 'Se requiere un encabezado X-License-Key válido. Esta tienda no ha activado Shopwalk Direct Checkout.';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_err_method_not_allowed'] = 'Combinación de ruta / método no admitida';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_err_session_not_found'] = 'Sesión no encontrada';
$_MODULE['<{shopwalk_ucp}prestashop>shopwalk_ucp_err_session_not_ready'] = 'Llame a PUT para proporcionar buyer + fulfillment primero';
