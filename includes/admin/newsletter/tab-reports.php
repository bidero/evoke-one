<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Newsletter — UI: Raporty i logi
 */

$campaigns   = evk_nl_get_campaigns();
$nonce       = wp_create_nonce('evk_nl_nonce');
$campaign_id = (int) ($_GET['campaign_id'] ?? ($campaigns[0]['id'] ?? 0));
$filter_ev   = sanitize_key($_GET['event_filter'] ?? '');

$current_camp = $campaign_id ? evk_nl_get_campaign($campaign_id) : null;
$stats        = $campaign_id ? evk_nl_campaign_stats($campaign_id) : null;
$logs         = $campaign_id ? evk_nl_get_logs($campaign_id, $filter_ev, 100) : [];

// Wypisani
$unsubs = [];
if ($campaign_id) {
    global $wpdb;
    $sl = evk_nl_table('subscribers');
    $ll = evk_nl_table('logs');
    $unsubs = $wpdb->get_results($wpdb->prepare(
        "SELECT s.email, s.unsubscribed_at FROM $sl s
         INNER JOIN $ll l ON l.subscriber_id=s.id
         WHERE l.campaign_id=%d AND l.event='unsubscribe'
         ORDER BY s.unsubscribed_at DESC", $campaign_id
    ), ARRAY_A) ?: [];
}



?>

<div class="evk-nl-split is-narrow" style="--evo-gap:20px">

    <!-- Wybór kampanii -->
    <div>
        <div class="evk-nl-card">
            <div class="evk-nl-card-head">
                <strong class="evk-nl-13">Kampanie</strong>
            </div>
            <?php if (empty($campaigns)): ?>
            <p class="evk-nl-muted">Brak kampanii.</p>
            <?php else: ?>
            <ul class="evk-nl-plain-list">
                <?php foreach ($campaigns as $c): ?>
                <li class="evk-nl-hr">
                    <a href="<?php echo esc_url(add_query_arg(['subtab' => 'reports', 'campaign_id' => $c['id']], evk_nl_base_url())); ?>"
                       class="evk-nl-tpl-item<?php echo (int) $c['id'] === $campaign_id ? ' is-active' : ''; ?>">
                        <?php echo esc_html($c['name']); ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- Raport -->
    <div>
        <?php if ($current_camp && $stats): ?>

        <!-- Statystyki -->
        <div class="evk-nl-stats">
            <?php
            // Klucz zamiast koloru — barwę kafelka niesie klasa `evk-nl-k-{klucz}`.
            $stat_items = [
                ['label' => 'Wysłane',   'val' => $stats['sent'],    'key' => 'sent'],
                ['label' => 'Otwarte',   'val' => $stats['opened'],  'key' => 'opened'],
                ['label' => 'Kliknięte', 'val' => $stats['clicked'], 'key' => 'clicked'],
                ['label' => 'Błędy',     'val' => $stats['failed'],  'key' => 'failed'],
                ['label' => 'Wypisy',    'val' => $stats['unsubs'],  'key' => 'unsubs'],
            ];
            foreach ($stat_items as $si):
                $pct = $stats['total'] > 0 ? round($si['val'] / $stats['total'] * 100, 1) : 0;
            ?>
            <div class="evk-nl-card is-padded evo-center">
                <div class="evk-nl-stat-num evk-nl-k-<?php echo esc_attr($si['key']); ?>"><?php echo esc_html($si['val']); ?></div>
                <div class="evk-nl-stat-label"><?php echo esc_html($si['label']); ?></div>
                <div class="evk-nl-stat-label evk-nl-k-<?php echo esc_attr($si['key']); ?>"><?php echo $pct; ?>%</div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Pending info -->
        <?php if ($stats['pending'] > 0): ?>
        <div class="notice notice-info inline evo-mb">
            <p style="margin:0;">⏳ Oczekuje na wysyłkę: <strong><?php echo esc_html($stats['pending']); ?></strong> wiadomości.</p>
        </div>
        <?php endif; ?>

        <!-- Wykres statystyk kampanii — te same wartości co karty -->
        <?php if ($stats): ?>
        <div class="evk-nl-card is-padded">
            <h4 class="evk-nl-h evo-mb">Podsumowanie kampanii</h4>
            <canvas id="evk-nl-stats-chart" height="80"></canvas>
        </div>
        <script>
        (function() {
            var script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js';
            script.onload = function() {
                new Chart(document.getElementById('evk-nl-stats-chart'), {
                    type: 'bar',
                    data: {
                        labels: ['Wysłane', 'Otwarte', 'Kliknięte', 'Błędy', 'Wypisy'],
                        datasets: [{
                            data: [
                                <?php echo (int)$stats['sent']; ?>,
                                <?php echo (int)$stats['opened']; ?>,
                                <?php echo (int)$stats['clicked']; ?>,
                                <?php echo (int)$stats['failed']; ?>,
                                <?php echo (int)$stats['unsubs']; ?>
                            ],
                            backgroundColor: ['#2563eb99','#16a34a99','#f59e0b99','#dc262699','#f9731699'],
                            borderColor:     ['#2563eb',  '#16a34a',  '#f59e0b',  '#dc2626',  '#f97316'],
                            borderWidth: 2,
                            borderRadius: 4,
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function(ctx) {
                                        var total = <?php echo (int)$stats['total']; ?>;
                                        var pct = total > 0 ? Math.round(ctx.raw / total * 100) : 0;
                                        return ctx.raw + ' (' + pct + '%)';
                                    }
                                }
                            }
                        },
                        scales: {
                            y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } }
                        }
                    }
                });
            };
            document.head.appendChild(script);
        })();
        </script>
        <?php endif; ?>

        <!-- Logi -->
        <div class="evk-nl-card is-padded">
            <div class="evk-nl-row-between evo-mb-sm" style="padding:0">
                <h4 class="evk-nl-h">Logi zdarzeń</h4>
                <div class="evo-inline" style="--evo-gap:8px">
                    <select id="evk-nl-event-filter" onchange="window.location='<?php echo esc_url_raw(add_query_arg(['subtab' => 'reports', 'campaign_id' => $campaign_id], evk_nl_base_url())); ?>&event_filter='+this.value" class="evo-hint">
                        <option value="" <?php selected('', $filter_ev); ?>>Wszystkie</option>
                        <?php foreach (['sent','open','click','unsubscribe','error','bounce'] as $ev): ?>
                        <option value="<?php echo esc_attr($ev); ?>" <?php selected($ev, $filter_ev); ?>><?php echo esc_html($ev); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" target="_blank" style="display:inline;">
                        <input type="hidden" name="action" value="evk_nl_export_logs">
                        <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
                        <input type="hidden" name="campaign_id" value="<?php echo (int) $campaign_id; ?>">
                        <input type="hidden" name="event" value="<?php echo esc_attr($filter_ev); ?>">
                        <button class="button button-small" type="submit">Eksport CSV</button>
                    </form>
                    <button class="button button-small evo-danger-tx" id="evk-nl-clear-all-logs"
                            data-id="<?php echo (int) $campaign_id; ?>"
                            title="Usuń wszystkie logi tej kampanii">Wyczyść logi</button>
                </div>
            </div>

            <?php if (empty($logs)): ?>
            <p class="evo-faint evk-nl-13">Brak logów dla tej kampanii.</p>
            <?php else: ?>
            <div class="evk-nl-tbl-wrap">
                <table class="wp-list-table widefat fixed striped evo-hint-sm">
                    <thead><tr><th>Zdarzenie</th><th>Subscriber ID</th><th>Dane</th><th>Czas</th></tr></thead>
                    <tbody>
                        <?php foreach ($logs as $log):
                        ?>
                        <tr>
                            <td>
                                <span class="evk-nl-badge evk-nl-badge-sm evk-nl-e-<?php echo esc_attr(sanitize_key($log['event'])); ?>">
                                    <?php echo esc_html($log['event']); ?>
                                </span>
                            </td>
                            <td><?php echo $log['subscriber_id'] ? esc_html($log['subscriber_id']) : '—'; ?></td>
                            <td class="evo-mono evk-nl-url">
                                <?php echo esc_html($log['data_json'] ?? ''); ?>
                            </td>
                            <td><?php echo esc_html($log['created_at']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Wypisani -->
        <?php if (!empty($unsubs)): ?>
        <div class="evk-nl-card is-padded" style="margin-bottom:0">
            <h4 class="evk-nl-h evo-mb-sm">Wypisani subskrybenci (<?php echo count($unsubs); ?>)</h4>
            <table class="wp-list-table widefat fixed striped evo-hint">
                <thead><tr><th>Email</th><th>Data wypisu</th></tr></thead>
                <tbody>
                    <?php foreach ($unsubs as $u): ?>
                    <tr>
                        <td><?php echo esc_html($u['email']); ?></td>
                        <td><?php echo esc_html($u['unsubscribed_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="evk-nl-empty">
            <span class="dashicons dashicons-chart-bar evo-ico-xl"></span>
            <p class="evo-muted" style="margin:12px 0 0">Wybierz kampanię z listy aby zobaczyć raport.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
jQuery(function($) {
    $('#evk-nl-clear-all-logs').on('click', function() {
        if (!confirm('Wyczyścić wszystkie logi tej kampanii? Statystyki zostaną skasowane.')) return;
        var id = $(this).data('id');
        $.post(ajaxurl, {
            action: 'evk_nl_bulk_campaigns',
            nonce: '<?php echo esc_js(wp_create_nonce('evk_nl_nonce')); ?>',
            bulk_action: 'clear_logs',
            ids: JSON.stringify([id])
        }, function(res) {
            if (res.success) location.reload();
        });
    });
});
</script>
