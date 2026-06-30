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

<div style="display:grid;grid-template-columns:220px 1fr;gap:20px;">

    <!-- Wybór kampanii -->
    <div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;">
            <div style="padding:14px 16px;border-bottom:1px solid #e2e8f0;">
                <strong style="font-size:13px;">Kampanie</strong>
            </div>
            <?php if (empty($campaigns)): ?>
            <p style="padding:16px;color:#94a3b8;font-size:13px;margin:0;">Brak kampanii.</p>
            <?php else: ?>
            <ul style="margin:0;padding:0;list-style:none;">
                <?php foreach ($campaigns as $c): ?>
                <li style="border-bottom:1px solid #f1f5f9;">
                    <a href="<?php echo esc_url(add_query_arg(['subtab' => 'reports', 'campaign_id' => $c['id']], evk_nl_base_url())); ?>"
                       style="display:block;padding:10px 14px;text-decoration:none;font-size:12px;
                              color:<?php echo (int)$c['id'] === $campaign_id ? '#2563eb' : '#374151'; ?>;
                              font-weight:<?php echo (int)$c['id'] === $campaign_id ? '600' : '400'; ?>;
                              background:<?php echo (int)$c['id'] === $campaign_id ? '#eff6ff' : 'transparent'; ?>;">
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
        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px;">
            <?php
            $stat_items = [
                ['label' => 'Wysłane',   'val' => $stats['sent'],    'color' => '#2563eb'],
                ['label' => 'Otwarte',   'val' => $stats['opened'],  'color' => '#16a34a'],
                ['label' => 'Kliknięte', 'val' => $stats['clicked'], 'color' => '#f59e0b'],
                ['label' => 'Błędy',     'val' => $stats['failed'],  'color' => '#dc2626'],
                ['label' => 'Wypisy',    'val' => $stats['unsubs'],  'color' => '#f97316'],
            ];
            foreach ($stat_items as $si):
                $pct = $stats['total'] > 0 ? round($si['val'] / $stats['total'] * 100, 1) : 0;
            ?>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;text-align:center;">
                <div style="font-size:28px;font-weight:700;color:<?php echo esc_attr($si['color']); ?>;"><?php echo esc_html($si['val']); ?></div>
                <div style="font-size:11px;color:#64748b;margin-top:2px;"><?php echo esc_html($si['label']); ?></div>
                <div style="font-size:11px;color:<?php echo esc_attr($si['color']); ?>;margin-top:2px;"><?php echo $pct; ?>%</div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Pending info -->
        <?php if ($stats['pending'] > 0): ?>
        <div class="notice notice-info inline" style="margin-bottom:16px;">
            <p style="margin:0;">⏳ Oczekuje na wysyłkę: <strong><?php echo esc_html($stats['pending']); ?></strong> wiadomości.</p>
        </div>
        <?php endif; ?>

        <!-- Wykres statystyk kampanii — te same wartości co karty -->
        <?php if ($stats): ?>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:20px;">
            <h4 style="margin:0 0 16px;font-size:13px;font-weight:600;">Podsumowanie kampanii</h4>
            <canvas id="evk-nl-stats-chart" height="80"></canvas>
        </div>
        <script>
        (function() {
            var script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
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
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:20px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <h4 style="margin:0;font-size:13px;font-weight:600;">Logi zdarzeń</h4>
                <div style="display:flex;gap:8px;align-items:center;">
                    <select id="evk-nl-event-filter" onchange="window.location='<?php echo esc_js(add_query_arg(['subtab' => 'reports', 'campaign_id' => $campaign_id], evk_nl_base_url())); ?>&event_filter='+this.value" style="font-size:12px;">
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
                    <button class="button button-small" id="evk-nl-clear-all-logs"
                            data-id="<?php echo (int) $campaign_id; ?>"
                            style="color:#dc2626;" title="Usuń wszystkie logi tej kampanii">Wyczyść logi</button>
                </div>
            </div>

            <?php if (empty($logs)): ?>
            <p style="color:#94a3b8;font-size:13px;">Brak logów dla tej kampanii.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="wp-list-table widefat fixed striped" style="font-size:11px;">
                    <thead><tr><th>Zdarzenie</th><th>Subscriber ID</th><th>Dane</th><th>Czas</th></tr></thead>
                    <tbody>
                        <?php foreach ($logs as $log):
                            $ev_colors = ['sent'=>'#2563eb','open'=>'#16a34a','click'=>'#f59e0b','unsubscribe'=>'#f97316','error'=>'#dc2626','bounce'=>'#64748b'];
                            $ev_color  = $ev_colors[$log['event']] ?? '#94a3b8';
                        ?>
                        <tr>
                            <td>
                                <span style="background:<?php echo esc_attr($ev_color); ?>20;color:<?php echo esc_attr($ev_color); ?>;padding:1px 6px;border-radius:99px;font-weight:600;font-size:10px;">
                                    <?php echo esc_html($log['event']); ?>
                                </span>
                            </td>
                            <td><?php echo $log['subscriber_id'] ? esc_html($log['subscriber_id']) : '—'; ?></td>
                            <td style="font-family:monospace;font-size:10px;max-width:200px;overflow:hidden;text-overflow:ellipsis;">
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
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;">
            <h4 style="margin:0 0 12px;font-size:13px;font-weight:600;">Wypisani subskrybenci (<?php echo count($unsubs); ?>)</h4>
            <table class="wp-list-table widefat fixed striped" style="font-size:12px;">
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
        <div style="padding:60px;text-align:center;background:#f8fafc;border-radius:10px;border:1px dashed #cbd5e1;">
            <span class="dashicons dashicons-chart-bar" style="font-size:40px;width:40px;height:40px;color:#94a3b8;"></span>
            <p style="color:#64748b;margin:12px 0 0;">Wybierz kampanię z listy aby zobaczyć raport.</p>
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
