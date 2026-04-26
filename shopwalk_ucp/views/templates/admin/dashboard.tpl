{*
 * Shopwalk UCP — admin dashboard.
 *
 * Shows UCP status (tables, signing keys, discovery URLs, active-agent
 * count), the Payments card (registered adapters + deep-link to PS
 * payment module settings), a live self-test runner, and the License
 * card (Shopwalk integration CTA — v0.1 is a static CTA only).
 *}

<div class="shopwalk-ucp-dashboard">

    <div class="panel">
        <div class="panel-heading">
            <i class="icon-globe"></i> Shopwalk UCP
            <span class="badge">v{$module_version}</span>
        </div>

        <h3>UCP compliance</h3>
        <p>
            This store implements the
            <a href="https://ucp.dev" target="_blank" rel="noopener">Universal Commerce Protocol</a>
            spec version <strong>{$ucp_spec_version}</strong>.
        </p>

        <dl class="sw-grid">
            <dt>Discovery</dt>
            <dd>
                <a href="{$discovery_url}" target="_blank" rel="noopener">{$discovery_url}</a>
                <button type="button" class="sw-probe" data-target="discovery">Probe</button>
            </dd>

            <dt>OAuth metadata</dt>
            <dd>
                <a href="{$oauth_meta_url}" target="_blank" rel="noopener">{$oauth_meta_url}</a>
                <button type="button" class="sw-probe" data-target="oauth">Probe</button>
            </dd>

            <dt>UCP v1 base</dt>
            <dd><code>{$ucp_base_url}</code></dd>
        </dl>
        <div id="sw-probe-out" class="sw-probe-out"></div>
    </div>

    <div class="panel">
        <div class="panel-heading"><i class="icon-bar-chart"></i> Activity</div>
        <table class="table">
            <tr><th>OAuth clients (agents)</th><td>{$stats.clients}</td></tr>
            <tr><th>Active agents (last 7d)</th><td>{$stats.agents_active_7d}</td></tr>
            <tr><th>Checkout sessions (all time)</th><td>{$stats.sessions_total}</td></tr>
            <tr><th>Open sessions</th><td>{$stats.sessions_open}</td></tr>
            <tr><th>Webhook subscriptions (active)</th><td>{$stats.subscriptions}</td></tr>
            <tr><th>Webhook queue (pending)</th><td>{$stats.queue_pending}</td></tr>
            <tr><th>Webhook queue (failed)</th><td>{$stats.queue_failed}</td></tr>
        </table>
    </div>

    <div class="panel">
        <div class="panel-heading"><i class="icon-credit-card"></i> Payments</div>
        <p>
            UCP payment adapters reuse your existing PrestaShop payment modules — the UCP plugin
            itself never stores any payment credentials. Agents submit payment method ids
            tokenized against your gateway's own JS SDK.
        </p>

        <table class="table sw-payments">
            <thead><tr><th>Gateway</th><th>Status</th><th>Configure</th></tr></thead>
            <tbody id="sw-payments-body">
                {foreach from=$payment_adapters item=a}
                    <tr>
                        <td><strong>{$a.id}</strong></td>
                        <td>
                            {if $a.ready}
                                <span class="sw-status sw-status-ok">&#9989; {$a.status_label}</span>
                            {else}
                                <span class="sw-status sw-status-off">&#9744; {$a.status_label}</span>
                            {/if}
                        </td>
                        <td>
                            {if $a.configure_url}
                                <a href="{$a.configure_url}">Open settings</a>
                            {else}
                                &mdash;
                            {/if}
                        </td>
                    </tr>
                {foreachelse}
                    <tr><td colspan="3">No adapters registered.</td></tr>
                {/foreach}
            </tbody>
        </table>
        <button type="button" class="btn btn-default" id="sw-refresh-payments">Refresh payment status</button>
    </div>

    <div class="panel">
        <div class="panel-heading"><i class="icon-clock-o"></i> Webhook flush cron</div>
        <p>Run this every minute so subscribed agents receive timely order events:</p>
        <pre>{$flush_cron_line}</pre>
    </div>

    <div class="panel">
        <div class="panel-heading"><i class="icon-stethoscope"></i> Self-test</div>
        <button type="button" class="btn btn-primary" id="sw-run-self-test">Run self-test</button>
        <div id="sw-self-test-partial">
            {include file="./self_test.tpl"}
        </div>
    </div>

    <div class="panel">
        <div class="panel-heading"><i class="icon-link"></i> Shopwalk integration (optional)</div>
        {if $license_active}
            <p><span class="sw-status sw-status-ok">&#9989; Connected</span>
                &mdash; your store is pushing real-time product updates to Shopwalk.</p>

            <div class="sw-discovery-toggle">
                <label style="display:flex;gap:10px;align-items:center;cursor:pointer">
                    <input type="checkbox"
                           id="sw-discovery-toggle"
                           {if !$discovery_paused}checked{/if}>
                    <strong>Allow Shopwalk to surface my store in AI discovery</strong>
                </label>
                <p style="margin:6px 0 0;color:#6b7280;font-size:12px">
                    When off, your store and products are hidden from AI search, shopping flows, and store pages within ~2 minutes. The plugin stays connected; existing orders are unaffected.
                </p>
                <span id="sw-discovery-status" style="color:#6b7280;font-size:12px"></span>
            </div>
        {else}
            <p>
                Your store is UCP-compliant on its own. Connect to Shopwalk for a faster index,
                partner-portal access, and analytics widgets — <strong>$19/mo, no commission</strong>.
            </p>
            <a class="btn btn-success" href="https://shopwalk.com/partner/connect?from=prestashop" target="_blank" rel="noopener">
                Connect to Shopwalk &rarr;
            </a>
        {/if}
    </div>

</div>

<script>
    window.SHOPWALK_UCP = {
        selfTestUrl: {$self_test_ajax|json_encode},
        paymentsStatusUrl: {$payments_status_ajax|json_encode},
        probeUrl: {$probe_ajax|json_encode},
        toggleDiscoveryUrl: {$toggle_discovery_ajax|json_encode}
    };
</script>
