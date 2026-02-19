<link rel="stylesheet" href="modules/addons/easydcim_bw/assets/client.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="edbw-client-wrap">
  {if !$has_service}
    <div class="edbw-panel">
      <h3>Traffic Overview</h3>
      <p>{$message}</p>
    </div>
  {else}
    {if $flash}
      <div class="alert alert-info">{$flash}</div>
    {/if}
    <div class="edbw-grid">
      <div class="edbw-panel">
        <h3>{$i18n.current_cycle}</h3>
        <div class="edbw-kpi">{if $is_fa}مصرف:{else}Used:{/if} <strong>{$used_gb|string_format:"%.2f"} GB</strong></div>
        <div class="edbw-kpi">{if $is_fa}باقی‌مانده:{else}Remaining:{/if} <strong>{$remaining_gb|string_format:"%.2f"} GB</strong></div>
        <div class="edbw-kpi">{if $is_fa}حالت:{else}Mode:{/if} <strong>{$mode}</strong></div>
        <div class="edbw-kpi">{if $is_fa}وضعیت:{else}Status:{/if} <strong>{$status}</strong></div>
        <div class="edbw-kpi">{if $is_fa}سیکل:{else}Cycle:{/if} {$cycle_start} → {$cycle_end}</div>
        <div class="edbw-kpi">{if $is_fa}ریست:{else}Reset at:{/if} {$reset_at} ({$days_to_reset} {if $is_fa}روز{/if}{if !$is_fa}days{/if})</div>
      </div>

      <div class="edbw-panel edbw-chart-panel">
        <div class="edbw-chart-top">
          <h3>Traffic Graph</h3>
          <select id="edbw-mode-select">
            <option value="TOTAL">TOTAL</option>
            <option value="IN">IN</option>
            <option value="OUT">OUT</option>
          </select>
        </div>
        <canvas id="edbw-chart" height="110"></canvas>
      </div>
    </div>

    <div class="edbw-panel">
      <h3>{$i18n.buy_additional} ({if $is_fa}فقط برای همین سیکل{/if}{if !$is_fa}One-time for Current Cycle{/if})</h3>
      <form method="post">
        <div class="edbw-buy-row">
          <select name="buy_package_id" required>
            <option value="">Select package</option>
            {foreach $packages as $pkg}
              <option value="{$pkg.id}">{$pkg.name} - {$pkg.size_gb}GB - {$pkg.price}</option>
            {/foreach}
          </select>
          <button class="btn btn-primary" type="submit">Create Invoice</button>
        </div>
      </form>
    </div>

    <div class="edbw-panel">
      <h3>{$i18n.autobuy_title}</h3>
      <form method="post">
        <input type="hidden" name="save_autobuy" value="1">
        <div class="edbw-buy-row">
          <label>{if $is_fa}فعال{/if}{if !$is_fa}Enabled{/if}</label>
          <input type="checkbox" name="autobuy_enabled" value="1" {if $autobuy_pref.autobuy_enabled == 1}checked{/if}>
          <label>{if $is_fa}آستانه (GB){/if}{if !$is_fa}Threshold (GB){/if}</label>
          <input type="number" step="0.01" min="0" name="autobuy_threshold_gb" value="{$autobuy_pref.autobuy_threshold_gb|default:''}">
          <label>{if $is_fa}پکیج{/if}{if !$is_fa}Package{/if}</label>
          <select name="autobuy_package_id">
            <option value="">{if $is_fa}انتخاب پکیج{/if}{if !$is_fa}Select package{/if}</option>
            {foreach $packages as $pkg}
              <option value="{$pkg.id}" {if $autobuy_pref.autobuy_package_id == $pkg.id}selected{/if}>{$pkg.name} - {$pkg.size_gb}GB</option>
            {/foreach}
          </select>
          <label>{if $is_fa}حداکثر در سیکل{/if}{if !$is_fa}Max / Cycle{/if}</label>
          <input type="number" min="1" name="autobuy_max_per_cycle" value="{$autobuy_pref.autobuy_max_per_cycle|default:''}">
          <button class="btn btn-default" type="submit">{$i18n.save}</button>
        </div>
      </form>
    </div>

    <div class="edbw-panel">
      <h3>{$i18n.purchases_cycle}</h3>
      <table class="table table-striped">
        <thead>
          <tr>
            <th>ID</th>
            <th>Invoice</th>
            <th>Package GB</th>
            <th>Price</th>
            <th>Cycle</th>
            <th>Reset</th>
            <th>Purchased At</th>
          </tr>
        </thead>
        <tbody>
        {foreach $purchases as $p}
          <tr>
            <td>{$p.id}</td>
            <td>{$p.invoiceid}</td>
            <td>{$p.size_gb}</td>
            <td>{$p.price}</td>
            <td>{$p.cycle_start} → {$p.cycle_end}</td>
            <td>{$p.reset_at}</td>
            <td>{$p.created_at}</td>
          </tr>
        {/foreach}
        </tbody>
      </table>
    </div>

    <script>
      (function() {
        var data = {$chart_json nofilter};
        var labels = data.labels || [];
        var datasets = data.datasets || [];

        function toSeries(nameSet) {
          return datasets.filter(function(ds) {
            var n = (ds.label || ds.name || '').toLowerCase();
            return nameSet.some(function(key) { return n.indexOf(key) !== -1; });
          }).map(function(ds) {
            return {
              label: ds.label || ds.name || 'Traffic',
              data: ds.data || [],
              borderWidth: 2,
              borderColor: '#1d4ed8',
              backgroundColor: 'rgba(29,78,216,.15)',
              tension: 0.25,
              fill: true
            };
          });
        }

        var ctx = document.getElementById('edbw-chart').getContext('2d');
        var chart = new Chart(ctx, {
          type: 'line',
          data: { labels: labels, datasets: toSeries(['total']) },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true } }
          }
        });

        document.getElementById('edbw-mode-select').addEventListener('change', function(e) {
          var mode = e.target.value;
          var keys = mode === 'IN' ? ['inbound', 'in'] : (mode === 'OUT' ? ['outbound', 'out'] : ['total']);
          chart.data.datasets = toSeries(keys);
          chart.update();
        });
      })();
    </script>
  {/if}
</div>
