<?php
if (!defined('ABSPATH')) exit;

$o = evk_cleanup_opts();
$items = [
    'disable_xmlrpc' => ['label' => 'Wyłącz XML-RPC', 'desc' => 'Blokuje xmlrpc.php — częsty wektor brute-force i nadużyć pingback.', 'icon' => 'dashicons-shield'],
    'remove_rss'     => ['label' => 'Usuń linki RSS z <head>', 'desc' => 'Usuwa odnośniki feedów (rsd, feed_links, wlwmanifest) z kodu strony.', 'icon' => 'dashicons-rss'],
];
?>
<div class="evo-tab-content evo-stack">
    <?php foreach ($items as $key => $it): $on = !empty($o[$key]); ?>
    <div class="evo-status-card">
        <div class="evo-status-icon <?php echo $on ? 'on' : 'off'; ?>">
            <span class="dashicons <?php echo esc_attr($it['icon']); ?>"></span>
        </div>
        <div class="evo-status-text">
            <h3><?php echo esc_html($it['label']); ?>: <?php echo $on ? 'WŁĄCZONE' : 'WYŁĄCZONE'; ?></h3>
            <p><?php echo esc_html($it['desc']); ?></p>
        </div>
        <div class="evo-status-actions">
            <span class="evo-toggle-label"><?php echo $on ? 'Włączone' : 'Wyłączone'; ?></span>
            <label class="evo-toggle">
                <input type="checkbox" data-option="evk_cleanup" data-field="<?php echo esc_attr($key); ?>" value="1" <?php checked($on); ?>>
                <span class="evo-slider"></span>
            </label>
        </div>
    </div>
    <?php endforeach; ?>
</div>
