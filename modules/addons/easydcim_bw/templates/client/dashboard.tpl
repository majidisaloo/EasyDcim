<h2>Bandwidth Manager</h2>
<p>Cycle: {$cycle_start} → {$cycle_end}</p>

{if $state}
<div class="alert alert-info">
    Used: {$state->last_used_gb|default:0} GB | Remaining: {$state->last_remaining_gb|default:0} GB | Status: {$state->last_status|default:'unknown'}
</div>
{/if}

<h3>Buy Additional Traffic</h3>
<form method="post">
    <input type="hidden" name="token" value="{$token}">
    <input type="hidden" name="action" value="buy-package">
    <select name="package_id" required>
        {foreach from=$packages item=pkg}
            <option value="{$pkg->id}">{$pkg->name} - {$pkg->size_gb} GB ({$pkg->price})</option>
        {/foreach}
    </select>
    <button type="submit" class="btn btn-primary">Buy</button>
</form>

<h3>Traffic Graph (Cycle)</h3>
<pre style="max-height:300px;overflow:auto">{$graph_data|@json_encode:JSON_PRETTY_PRINT}</pre>
