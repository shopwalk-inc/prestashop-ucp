{*
 * Shopwalk UCP — admin dashboard.
 *}

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

    <dl class="well">
        <dt>Discovery</dt>
        <dd><a href="{$discovery_url}" target="_blank" rel="noopener">{$discovery_url}</a></dd>

        <dt>OAuth metadata</dt>
        <dd><a href="{$oauth_meta_url}" target="_blank" rel="noopener">{$oauth_meta_url}</a></dd>

        <dt>UCP v1 base</dt>
        <dd><code>{$ucp_base_url}</code></dd>
    </dl>

    <h3>Activity</h3>
    <table class="table">
        <tr><th>OAuth clients (agents)</th><td>{$stats.clients}</td></tr>
        <tr><th>Checkout sessions (all time)</th><td>{$stats.sessions_total}</td></tr>
        <tr><th>Open sessions</th><td>{$stats.sessions_open}</td></tr>
        <tr><th>Webhook subscriptions (active)</th><td>{$stats.subscriptions}</td></tr>
        <tr><th>Webhook queue (pending)</th><td>{$stats.queue_pending}</td></tr>
        <tr><th>Webhook queue (failed)</th><td>{$stats.queue_failed}</td></tr>
    </table>

    <h3>Webhook flush cron</h3>
    <p>Run this every minute so subscribed agents receive timely order events:</p>
    <pre>{$flush_cron_line}</pre>

    <h3>Self-test</h3>
    <table class="table table-bordered">
        <thead><tr><th>Check</th><th>Status</th><th>Notes</th></tr></thead>
        <tbody>
        {foreach from=$checks item=c}
            <tr>
                <td>{$c.label}</td>
                <td>
                    {if $c.status eq 'pass'}<span class="label label-success">pass</span>
                    {elseif $c.status eq 'warn'}<span class="label label-warning">warn</span>
                    {else}<span class="label label-danger">fail</span>
                    {/if}
                </td>
                <td>{$c.message}</td>
            </tr>
        {/foreach}
        </tbody>
    </table>
</div>
