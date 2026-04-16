<?php
/**
 * "Pay via UCP" — a PaymentOption registered with the core Payment module
 * so UCP-originated carts have a payment method to bind to during order
 * creation.
 *
 * The v0.1 module defers actual charge capture to the store's native
 * checkout page (UCP Direct Checkout pattern) — the agent surfaces the
 * resulting order permalink as `payment_url`, and the buyer completes
 * payment via whatever gateway the store has already configured.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

if (!class_exists('PrestaShop\\PrestaShop\\Core\\Payment\\PaymentOption')) {
    return;
}

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class UcpPaymentModule
{
    public static function getPaymentOptions(Module $module, array $params): array
    {
        if (empty($params['cart']) || !($params['cart'] instanceof Cart)) {
            return [];
        }
        $option = new PaymentOption();
        $option->setCallToActionText('Pay via UCP');
        $option->setLogo('');
        $option->setAction(Context::getContext()->link->getPageLink(
            'order-confirmation',
            true,
            null,
            [
                'id_cart'   => (int) $params['cart']->id,
                'id_module' => (int) $module->id,
                'key'       => $params['cart']->secure_key,
            ]
        ));
        return [$option];
    }
}
