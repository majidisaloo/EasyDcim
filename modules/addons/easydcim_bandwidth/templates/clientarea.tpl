{if $error}
<div class="alert alert-danger">{$error}</div>
{else}
<h2>Bandwidth Usage for Service #{$serviceid}</h2>
<p><strong>Cycle:</strong> {$cycle.start} → {$cycle.end}</p>
<div class="row">
    <div class="col-md-4"><div class="well"><strong>Status:</strong> {$result.status}</div></div>
    <div class="col-md-4"><div class="well"><strong>Used (GB):</strong> {$result.used_gb|string_format:"%.2f"}</div></div>
    <div class="col-md-4"><div class="well"><strong>Remaining (GB):</strong> {$result.remaining_gb|string_format:"%.2f"}</div></div>
</div>

<h3>Current Cycle Graph (AggregateTraffic)</h3>
<pre style="max-height: 260px; overflow: auto;">{$graph|@json_encode:128}</pre>

<h3>Buy Additional Traffic (One-Time, Current Cycle)</h3>
<form method="post">
    <input type="hidden" name="bw_action" value="buy_package">
    <select name="package_id" class="form-control" required>
        <option value="">Select package...</option>
        {foreach from=$packages item=package}
        <option value="{$package->id}">{$package->name} - {$package->size_gb} GB - {$package->price}</option>
        {/foreach}
    </select>
    <br>
    <button type="submit" class="btn btn-primary">Buy Additional Traffic</button>
</form>
{/if}
