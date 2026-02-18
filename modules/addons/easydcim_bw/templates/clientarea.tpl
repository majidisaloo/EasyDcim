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
        <h3>Current Cycle</h3>
        <div class="edbw-kpi">Used: <strong>{$used_gb|string_format:"%.2f"} GB</strong></div>
        <div class="edbw-kpi">Remaining: <strong>{$remaining_gb|string_format:"%.2f"} GB</strong></div>
        <div class="edbw-kpi">Mode: <strong>{$mode}</strong></div>
        <div class="edbw-kpi">Status: <strong>{$status}</strong></div>
        <div class="edbw-kpi">Cycle: {$cycle_start} → {$cycle_end}</div>
        <div class="edbw-kpi">Reset at: {$reset_at}</div>
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
      <h3>Buy Additional Traffic (One-time for Current Cycle)</h3>
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
      <h3>Purchases in Current Cycle</h3>
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
