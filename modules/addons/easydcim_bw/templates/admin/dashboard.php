<h2>EasyDCIM Bandwidth Manager</h2>

<h3>Product Defaults</h3>
<form method="post" action="addonmodules.php?module=easydcim_bw&action=save-product-default">
    <input type="number" name="pid" placeholder="PID" required>
    <input type="text" name="default_quota_gb" placeholder="Default Quota GB" required>
    <select name="default_mode"><option>IN</option><option>OUT</option><option selected>TOTAL</option></select>
    <select name="default_action"><option value="disable_ports">Disable Ports</option><option value="suspend">Suspend</option><option value="both">Both</option></select>
    <label><input type="checkbox" name="enabled" checked> Enabled</label>
    <button type="submit">Save</button>
</form>
<table class="datatable">
<tr><th>PID</th><th>Quota</th><th>Mode</th><th>Action</th><th>Enabled</th></tr>
<?php foreach ($defaults as $row): ?>
<tr>
    <td><?= (int) $row->pid ?></td><td><?= htmlspecialchars((string) $row->default_quota_gb) ?></td><td><?= htmlspecialchars((string) $row->default_mode) ?></td><td><?= htmlspecialchars((string) $row->default_action) ?></td><td><?= (int) $row->enabled ?></td>
</tr>
<?php endforeach; ?>
</table>

<h3>Service Overrides</h3>
<form method="post" action="addonmodules.php?module=easydcim_bw&action=save-override">
    <input type="number" name="serviceid" placeholder="Service ID" required>
    <input type="text" name="override_base_quota_gb" placeholder="Override Quota GB">
    <select name="override_mode"><option value="">--mode--</option><option>IN</option><option>OUT</option><option>TOTAL</option></select>
    <select name="override_action"><option value="">--action--</option><option value="disable_ports">Disable Ports</option><option value="suspend">Suspend</option><option value="both">Both</option></select>
    <button type="submit">Save Override</button>
</form>

<h3>Traffic Packages</h3>
<form method="post" action="addonmodules.php?module=easydcim_bw&action=save-package">
    <input type="text" name="name" placeholder="Package name" required>
    <input type="text" name="size_gb" placeholder="Size GB" required>
    <input type="text" name="price" placeholder="Price" required>
    <label><input type="checkbox" name="taxed" checked> Taxed</label>
    <input type="text" name="available_for_pids" placeholder="PIDs: 1,2,3">
    <input type="text" name="available_for_gids" placeholder="GIDs: 5,6">
    <button type="submit">Save Package</button>
</form>
<table class="datatable">
<tr><th>ID</th><th>Name</th><th>Size GB</th><th>Price</th></tr>
<?php foreach ($packages as $pkg): ?>
<tr><td><?= (int) $pkg->id ?></td><td><?= htmlspecialchars((string) $pkg->name) ?></td><td><?= htmlspecialchars((string) $pkg->size_gb) ?></td><td><?= htmlspecialchars((string) $pkg->price) ?></td></tr>
<?php endforeach; ?>
</table>

<h3>Recent Logs</h3>
<table class="datatable">
<tr><th>Time</th><th>Level</th><th>Context</th></tr>
<?php foreach ($logs as $log): ?>
<tr><td><?= htmlspecialchars((string) $log->created_at) ?></td><td><?= htmlspecialchars((string) $log->level) ?></td><td><pre><?= htmlspecialchars((string) $log->context_json) ?></pre></td></tr>
<?php endforeach; ?>
</table>
