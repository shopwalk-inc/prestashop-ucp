{*
 * Shopwalk UCP — self-test results partial.
 * Rendered both on initial page load and via AJAX refresh.
 *}

<table class="table table-bordered sw-self-test">
    <thead><tr><th>Check</th><th>Status</th><th>Notes</th></tr></thead>
    <tbody>
    {foreach from=$checks item=c}
        <tr>
            <td>{$c.label}</td>
            <td>
                {if $c.status eq 'pass'}
                    <span class="sw-status sw-status-ok">&#9989; pass</span>
                {elseif $c.status eq 'warn'}
                    <span class="sw-status sw-status-warn">&#9888; warn</span>
                {else}
                    <span class="sw-status sw-status-fail">&#10060; fail</span>
                {/if}
            </td>
            <td>{$c.message}</td>
        </tr>
    {/foreach}
    </tbody>
</table>
