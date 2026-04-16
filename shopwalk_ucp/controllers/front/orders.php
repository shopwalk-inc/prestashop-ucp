<?php
/**
 * GET /ucp/v1/orders                       — list authenticated buyer's orders
 * GET /ucp/v1/orders/{id}                  — order detail
 * GET /ucp/v1/orders/{id}/events           — fulfillment event log
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Shopwalk_UcpOrdersModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $ssl  = true;

    public function initContent()
    {
        parent::initContent();

        $tok = UcpOAuthServer::resolveBearer();
        if (!$tok || !$tok->id_customer) {
            UcpEnvelope::respondError('invalid_token', 'Bearer token required', 401);
        }

        $id  = (int) Tools::getValue('id');
        $sub = (string) Tools::getValue('sub');

        if (!$id) {
            $this->listForCustomer((int) $tok->id_customer);
            return;
        }

        $order = new Order($id);
        if (!Validate::isLoadedObject($order) || (int) $order->id_customer !== (int) $tok->id_customer) {
            UcpEnvelope::respondError('not_found', 'Order not found for this buyer', 404);
        }

        if ($sub === 'events') {
            $mapped = UcpOrderMapper::map($order, true);
            UcpEnvelope::respond([
                'ucp'    => $mapped['ucp'],
                'id'     => $mapped['id'],
                'events' => $mapped['fulfillment']['events'] ?? [],
            ]);
            return;
        }

        UcpEnvelope::respond(UcpOrderMapper::map($order, true));
    }

    protected function listForCustomer(int $idCustomer): void
    {
        $orders = Order::getCustomerOrders($idCustomer);
        $out = [];
        foreach (array_slice($orders, 0, 50) as $row) {
            $o = new Order((int) $row['id_order']);
            if (Validate::isLoadedObject($o)) {
                $out[] = UcpOrderMapper::map($o, false);
            }
        }
        UcpEnvelope::respond(UcpEnvelope::ok([
            'orders' => $out,
            'count'  => count($out),
        ], ['dev.ucp.shopping.order']));
    }
}
