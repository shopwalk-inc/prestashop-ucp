# prestashop-ucp

**Universal Commerce Protocol adapter for PrestaShop.**

Makes any PrestaShop 1.7.8+ / 8.x store fully purchasable by UCP-compliant AI shopping agents (Shopwalk, OpenAI, Anthropic, LangChain, custom agents). Implements the [Universal Commerce Protocol](https://ucp.dev) spec version **2026-04-08** — checkout sessions, OAuth 2.0 identity, orders, webhooks.

Vendor-neutral: the module runs without any signup or connection to any specific agent. Any UCP-compliant agent can transact with a PrestaShop store running this module.

---

## What it does

- Serves `/.well-known/ucp` and `/.well-known/oauth-authorization-server` discovery documents.
- Exposes the full UCP v1 REST surface at `/ucp/v1/*`:
  - OAuth 2.0 server (authorize / token / revoke / userinfo)
  - Checkout session lifecycle (create / update / complete / cancel)
  - Orders (list / detail / fulfillment events)
  - Webhook subscriptions with signed outbound delivery
- Registers a "Pay via UCP" payment method so agent-originated orders settle natively.
- Ships an admin dashboard with UCP status, active-agent log, payment-adapter status, and a self-test runner.
- **Payment router architecture** — agents pass a gateway id (e.g. `stripe`) and an already-tokenized credential; the router dispatches to a platform-side adapter (`UcpPaymentAdapterStripe`) that reuses the merchant's existing PS Stripe module keys. Third-party modules extend support for additional gateways via `UcpPaymentRouter::addAdapter('ppcp', 'My_PPCP_Adapter')`.

---

## Installation

```bash
git clone https://github.com/shopwalk-inc/prestashop-ucp.git
cd prestashop-ucp
zip -r shopwalk_ucp.zip shopwalk_ucp
```

Upload `shopwalk_ucp.zip` in PrestaShop's **Modules → Module Manager → Upload a module**, then click **Install** and **Configure**.

Alternatively, composer install for PS 8.x:

```bash
composer require shopwalk-inc/prestashop-ucp
```

After install, enable friendly URLs in **Shop Parameters → Traffic & SEO** so the `/ucp/v1/*` routes and `.well-known` discovery URIs resolve.

---

## Compatibility

| | |
|---|---|
| PrestaShop | 1.7.8.0 – 8.x |
| PHP | 7.4 – 8.3 |
| UCP Spec | 2026-04-08 |
| License | GPL-2.0-or-later |

---

## Module layout

```
shopwalk_ucp/
├── shopwalk_ucp.php                        # Module class, install, hooks, routes
├── config.xml                              # PS module metadata
├── composer.json
├── sql/
│   ├── install.php                         # Table creation
│   └── uninstall.php                       # Cleanup
├── classes/                                # Domain logic (autoloaded classmap)
│   ├── UcpBootstrap.php                    # Activation helper
│   ├── UcpConfig.php                       # Configuration helpers, keypair gen
│   ├── UcpEnvelope.php                     # Spec `ucp` response wrapper
│   ├── UcpDiscovery.php                    # /.well-known/ucp builder
│   ├── UcpSigning.php                      # JWT + RFC 9421 signatures
│   ├── UcpAddress.php                      # schema.org address mapping
│   ├── UcpTotals.php                       # Minor-unit typed totals
│   ├── UcpOAuthServer.php                  # Authorization code + refresh grant
│   ├── UcpOAuthClient.php                  # ObjectModel for oauth_clients
│   ├── UcpOAuthToken.php                   # ObjectModel for oauth_tokens
│   ├── UcpCheckoutSession.php              # ObjectModel for checkout_sessions
│   ├── UcpCheckoutEngine.php               # Session state machine
│   ├── UcpFulfillment.php                  # Shipping option calculation
│   ├── UcpOrderMapper.php                  # PS Order → UCP Order Object
│   ├── UcpWebhookSubscription.php          # ObjectModel
│   ├── UcpWebhookQueue.php                 # ObjectModel + delivery queue
│   ├── UcpWebhookDispatcher.php            # Enqueue + flush
│   ├── UcpPaymentModule.php                # "Pay via UCP" payment option
│   └── UcpSelfTest.php                     # Dashboard diagnostics
├── controllers/
│   ├── admin/
│   │   └── AdminShopwalkUcpController.php  # Admin tab
│   └── front/
│       ├── discovery.php                   # /.well-known/ucp
│       ├── oauthmetadata.php               # /.well-known/oauth-authorization-server
│       ├── oauthauthorize.php
│       ├── oauthtoken.php
│       ├── oauthrevoke.php
│       ├── oauthuserinfo.php
│       ├── checkoutsessions.php            # POST / GET / PUT / complete / cancel
│       ├── orders.php                      # GET list / detail / events
│       ├── webhooksubscriptions.php        # POST / GET / DELETE
│       └── webhookflush.php                # Cron target for queue flush
├── views/
│   ├── templates/admin/dashboard.tpl
│   ├── templates/front/oauth_authorize.tpl
│   ├── css/admin.css
│   └── js/admin.js
├── translations/
│   ├── en.php
│   ├── fr.php
│   └── es.php
└── tests/
```

---

## Endpoints

### Discovery

| Method | Path |
|---|---|
| GET | `/.well-known/ucp` |
| GET | `/.well-known/oauth-authorization-server` |

### OAuth 2.0

| Method | Path |
|---|---|
| GET | `/ucp/v1/oauth/authorize` |
| POST | `/ucp/v1/oauth/token` |
| POST | `/ucp/v1/oauth/revoke` |
| GET | `/ucp/v1/oauth/userinfo` |

### Checkout

| Method | Path |
|---|---|
| POST | `/ucp/v1/checkout-sessions` |
| GET | `/ucp/v1/checkout-sessions/{id}` |
| PUT | `/ucp/v1/checkout-sessions/{id}` |
| POST | `/ucp/v1/checkout-sessions/{id}/complete` |
| POST | `/ucp/v1/checkout-sessions/{id}/cancel` |

### Orders

| Method | Path |
|---|---|
| GET | `/ucp/v1/orders` |
| GET | `/ucp/v1/orders/{id}` |
| GET | `/ucp/v1/orders/{id}/events` |

### Webhook subscriptions (agents subscribing to order events)

| Method | Path |
|---|---|
| POST | `/ucp/v1/webhooks/subscriptions` |
| GET | `/ucp/v1/webhooks/subscriptions/{id}` |
| DELETE | `/ucp/v1/webhooks/subscriptions/{id}` |

### Shopwalk Direct Checkout (Tier 2 — optional)

| Method | Path | Auth |
|---|---|---|
| POST | `/shopwalk-ucp/v1/checkout` | `X-License-Key` |

Not part of the UCP spec — a one-shot order-creation fallback for agents that prefer a pay-at-store handoff over the full UCP session state machine. Returns a `payment_url` the buyer completes on the store's native checkout page.

All requests from agents must include:

- `Authorization: Bearer <oauth_access_token>`
- `UCP-Agent: profile="https://agent.example.com/.well-known/ucp"`
- `Idempotency-Key: <uuid>` on POST / PUT

---

## Cron — webhook delivery

Set up a system cron to flush the outbound webhook queue every minute:

```
* * * * * curl -fsS "https://yourstore.com/ucp/v1/internal/webhooks/flush?token=SECRET" > /dev/null
```

The flush token is generated on install and shown in the admin dashboard. Delivery uses exponential backoff (5 attempts) and marks failed entries for manual inspection.

---

## Database tables

All prefixed with `ps_ucp_` (or your configured DB prefix):

- `ucp_oauth_clients` — registered agents (one per UCP agent profile)
- `ucp_oauth_tokens` — access / refresh / authorization_code tokens
- `ucp_checkout_sessions` — UCP Checkout Object state (30-min TTL)
- `ucp_webhook_subscriptions` — agent subscriptions to order events
- `ucp_webhook_queue` — outbound delivery queue with retry state

Uninstall drops every table.

---

## Part of the UCP adapter family

Companion plugins for other open-source platforms:

- [woocommerce-ucp](https://github.com/shopwalk-inc/woocommerce-ucp) — WooCommerce
- [magento-ucp](https://github.com/shopwalk-inc/magento-ucp) — Magento 2
- prestashop-ucp — this repo
- opencart-ucp — coming

Each implements the same UCP spec mapped to its platform's primitives.

---

## License

GPL-2.0-or-later. See `LICENSE`.
