<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — TL: tl_render_page()
 * Scaffold strony + ładuje tab content z includes/admin/tl/tab-*.php
 * Zmienne dostępne w tab-*.php przez inherit scope: $data $langs $codes $tab $base $nonce $ajax_url $stats
 */

function tl_render_page() {
    $data          = get_option('tl_translations', ['groups' => []]);
    $images        = get_option('tl_images', []);
    $url_slugs     = get_option('tl_url_slugs', []);
    $sitemap_settings = tl_get_sitemap_settings();
    $dd_keys       = get_option('tl_dd_keys', []);
    $dd_by_phrase  = [];
    foreach ($dd_keys as $key => $phrase) {
        $phrase = trim((string) $phrase);
        if ($phrase !== '') $dd_by_phrase[$phrase] = $key;
    }
    $langs         = tl_get_languages();
    $codes         = array_keys($langs);
    $tab           = sanitize_key($_GET['tab'] ?? 'translations');
    $base          = tl_base_url();
    $menu_location = get_option('tl_menu_location', 'options-general.php');
    $stats         = tl_coverage_stats();
    $nonce         = wp_create_nonce('tl_ajax_nonce');
    $ajax_url      = admin_url('admin-ajax.php');
    ?>
    <style>
        /* Tłumaczenia — design system Evoke FIELDS (akcent #2563eb) */
        #wpbody-content .wrap { max-width:1280px; }
        #wpbody-content .wrap > h1 { display:flex; align-items:center; gap:10px; margin:18px 0 14px; font-size:23px; letter-spacing:0; color:#111827; }

        /* Zakładki — pille EVK */
        .tl-tabs { display:flex; gap:6px; padding:6px; margin:14px 0 0; border:1px solid #d7dde7; border-radius:10px 10px 0 0; background:#eef2f7; flex-wrap:wrap; }
        .tl-tab { border:0; margin:0; border-radius:7px; padding:9px 13px; font-size:13px; font-weight:500; color:#4b5563; text-decoration:none; background:transparent; display:flex; align-items:center; gap:6px; transition:background .15s,color .15s,box-shadow .15s; }
        .tl-tab:hover { color:#1d4ed8; background:#fff; }
        .tl-tab.active { background:#fff; color:#111827; font-weight:600; box-shadow:0 1px 3px rgba(15,23,42,.12); }
        .tl-badge-pct { font-size:10px; font-weight:700; padding:1px 6px; border-radius:999px; background:#dbeafe; color:#1d4ed8; }
        .tl-panel { background:#fff; border:1px solid #d7dde7; border-top:0; border-radius:0 0 10px 10px; padding:24px; box-shadow:0 12px 30px rgba(15,23,42,.06); }

        /* Toolbar / pasek zapisu */
        .tl-toolbar,.tl-footer,.tl-save-bar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .tl-toolbar { margin-bottom:16px; padding:12px; border:1px solid #e5e7eb; border-radius:10px; background:#f8fafc; }
        .tl-search-wrap { display:flex; align-items:center; gap:6px; background:#fff; border:1px solid #d7dde7; border-radius:8px; padding:7px 12px; flex:1; min-width:200px; max-width:420px; }
        .tl-search-wrap input { flex:1; border:none; background:transparent; font-size:13px; outline:none; }
        #tl-search-count { font-size:12px; color:#94a3b8; white-space:nowrap; }

        /* Pokrycie tłumaczeń */
        .tl-coverage { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
        .tl-cov-item { font-size:12px; display:flex; align-items:center; gap:6px; }
        .tl-cov-bar { width:80px; height:6px; background:#e2e8f0; border-radius:3px; overflow:hidden; }
        .tl-cov-fill { height:100%; border-radius:3px; background:#2563eb; transition:width .3s; }
        .tl-cov-fill.good { background:linear-gradient(90deg,#16a34a,#68de7c); }
        .tl-cov-fill.mid { background:linear-gradient(90deg,#dba617,#f0c33c); }

        /* Grupa fraz */
        .tl-group { border:1px solid #d7dde7; border-radius:10px; margin-bottom:14px; box-shadow:0 1px 2px rgba(15,23,42,.05); overflow:hidden; }
        .tl-group-header { background:#f8fafc; padding:10px 14px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; gap:8px; user-select:none; cursor:pointer; }
        .tl-group-header.collapsed { border-bottom:none; border-radius:10px; }
        .tl-group-toggle { flex:1; display:flex; align-items:center; gap:8px; min-width:0; pointer-events:none; }
        .tl-group-toggle-icon { color:#94a3b8; transition:transform .2s; flex-shrink:0; font-size:12px; }
        .tl-group-toggle-icon.open { transform:rotate(90deg); }
        .tl-group-name-input { font-weight:600; font-size:14px; flex:0 1 800px; max-width:800px; border:1px solid transparent; background:transparent; padding:3px 7px; min-width:0; border-radius:6px; pointer-events:auto; cursor:text; }
        .tl-group-name-input:focus { border-color:#2563eb; background:#fff; outline:none; box-shadow:0 0 0 3px rgba(37,99,235,.12); }
        .badge-count { background:#2563eb; color:#fff; font-size:11px; font-weight:700; margin-left:auto; margin-right:10px; width:32px; height:32px; line-height:32px; text-align:center; border-radius:50%; flex-shrink:0; padding:0; }
        .tl-group-actions { display:flex; gap:4px; flex-shrink:0; pointer-events:auto; }
        .tl-group-actions .button-icon { width:30px; height:30px; display:flex !important; align-items:center; justify-content:center; padding:0 !important; color:#2563eb; border-color:#cbd5e1; }
        .tl-group-actions .button-icon:hover { background:#eff6ff; border-color:#93c5fd; }
        .tl-group-actions .button-link-delete { color:#dc2626; border-color:#fca5a5; }
        .tl-group-body { display:none; }
        .tl-group-body.open { display:block; }

        /* Wiersz frazy */
        .tl-row { border-bottom:1px solid #f0f0f1; }
        .tl-row:last-child { border-bottom:none; }
        .tl-row-header { display:flex; align-items:center; gap:8px; padding:9px 14px; cursor:pointer; background:#fff; }
        .tl-row-header:hover { background:#f8fafc; }
        .tl-row-header.open { background:#2563eb; border-bottom:1px solid #e2eaf3; color:#fff; }
        .tl-row-header.open span { color:#fff; }
        .tl-row-pl-preview { flex:1; font-size:13px; color:#111827; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .tl-lang-pills { display:flex; gap:4px; flex-shrink:0; }
        .tl-pill { font-size:10px; font-weight:700; padding:2px 6px; border-radius:999px; background:#f1f5f9; color:#64748b; border:1px solid #e2e8f0; }
        .tl-pill.filled { background:#dcfce7; color:#15803d; border-color:#86efac; }
        .tl-chevron { color:#94a3b8; transition:transform .2s; font-size:11px; flex-shrink:0; }
        .tl-row-header.open .tl-chevron { transform:rotate(90deg); }
        .tl-row-body { display:none; padding:14px 16px; background:#fafafa; }
        .tl-row-body.open { display:block; }
        .tl-field,.tl-dd-field { margin-bottom:11px; }
        .tl-field label,.tl-dd-field label { display:block; font-size:10px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:4px; letter-spacing:.4px; }
        .tl-field textarea { width:100%; min-height:120px; border:1px solid #d1d5db; border-radius:6px; padding:6px 8px; font-size:13px; resize:vertical; box-sizing:border-box; }
        .tl-field textarea:focus,.tl-dd-key-input:focus { border-color:#2563eb; outline:none; box-shadow:0 0 0 3px rgba(37,99,235,.12); }
        .tl-dd-field { background:#eff6ff; border:1px solid #d7dde7; border-radius:8px; padding:8px 10px; }
        .tl-dd-field-row { display:flex; align-items:center; gap:8px; }
        .tl-dd-prefix { font-family:monospace; font-size:12px; color:#475569; white-space:nowrap; }
        .tl-dd-key-input { flex:1; min-width:0; border:1px solid #d1d5db; border-radius:6px; padding:6px 8px; font-size:13px; font-family:monospace; background:#fff; }
        .tl-row-footer { display:flex; gap:6px; padding-top:4px; }

        /* Sticky pasek zapisu */
        .tl-save-bar { position:sticky; bottom:0; background:rgba(255,255,255,.94); backdrop-filter:saturate(180%) blur(8px); border-top:1px solid #e5e7eb; padding:14px 24px; margin:22px -24px -24px; z-index:100; }
        .tl-save-status { font-size:13px; color:#047857; display:none; }
        .tl-save-status.err { color:#dc2626; }

        /* Drag & drop sortowanie */
        .drag-handle { color:#cbd5e1; cursor:move !important; font-size:16px; flex-shrink:0; line-height:1; padding:4px; user-select:none; -webkit-user-select:none; }
        .drag-handle:hover { color:#94a3b8; }
        .tl-row .drag-handle { font-size:14px; padding:2px 4px; }
        .button-icon { padding:4px 8px !important; line-height:1.4 !important; }
        .tl-highlight { background:#fff3cd !important; }
        .sortable-placeholder { background:#eff6ff !important; border:2px dashed #93c5fd !important; border-radius:8px; visibility:visible !important; min-height:40px; }
        .tl-group.sortable-placeholder { min-height:60px; }
        .tl-group.ui-sortable-helper,.tl-row.ui-sortable-helper { background:#fff; box-shadow:0 4px 12px rgba(0,0,0,.15); opacity:0.95; }
        .ui-sortable-helper * { user-select:none !important; -webkit-user-select:none !important; }

        /* Obrazki wielojęzyczne */
        .tl-img-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:14px; margin-top:4px; }
        .tl-img-card { border:1px solid #d7dde7; border-radius:10px; padding:14px; background:#fafafa; box-shadow:0 1px 2px rgba(15,23,42,.04); }
        .tl-img-card-header,.tl-img-lang-row { display:flex; align-items:center; gap:8px; }
        .tl-img-card-header { margin-bottom:12px; }
        .tl-img-lang-row { margin-bottom:8px; }
        .tl-img-lang-label { width:45px; font-size:10px; font-weight:700; text-transform:uppercase; color:#64748b; flex-shrink:0; }
        .tl-img-preview { width:48px; height:36px; object-fit:cover; border-radius:6px; border:1px solid #d7dde7; background:#f0f0f0; flex-shrink:0; cursor:pointer; }
        .tl-img-preview-empty { width:48px; height:36px; border-radius:6px; border:1px dashed #cbd5e1; background:#f8fafc; flex-shrink:0; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#cbd5e1; font-size:18px; }

        /* Import / Export */
        .tl-io-section { margin-bottom:28px; }
        .tl-drop-zone { border:2px dashed #cbd5e1; border-radius:10px; padding:32px; text-align:center; color:#64748b; font-size:14px; cursor:pointer; transition:all .2s; background:#fafafa; }
        .tl-drop-zone.drag-over { border-color:#2563eb; background:#eff6ff; color:#2563eb; }
        .tl-drop-zone input[type=file] { display:none; }
        .tl-import-status { margin-top:10px; padding:8px 14px; border-radius:6px; display:none; font-size:13px; }
        .tl-import-status.ok { background:#dcfce7; color:#15803d; border:1px solid #86efac; display:block; }
        .tl-import-status.err { background:#fef2f2; color:#b42318; border:1px solid #fecaca; display:block; }

        /* Ustawienia menu / języków */
        .tl-menu-settings { background:#f8fafc; border:1px solid #d7dde7; padding:20px; border-radius:10px; max-width:100%; margin-bottom:20px; }
        .tl-menu-settings h3,.tl-io-section h3 { margin-top:0; font-size:16px; color:#111827; }
        .lang-table, .slug-table { width:100%; border-collapse:collapse; max-width:100%; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; background:#fff; }
        .lang-table th, .slug-table th { background:#f8fafc; padding:8px 10px; font-size:11px; text-align:left; border-bottom:1px solid #e5e7eb; color:#475569; }
        .lang-table td, .slug-table td { padding:7px 8px; border-bottom:1px solid #eef2f7; }
        .lang-table input[type=text], .slug-table input[type=text] { width:100%; border:1px solid #d1d5db; border-radius:6px; padding:5px 7px; }
        .lang-table tr.ui-sortable-helper, .slug-table tr.ui-sortable-helper { background:#fff; box-shadow:0 4px 12px rgba(0,0,0,.15); display:table; width:100%; }
        .lang-table tr.sortable-placeholder, .slug-table tr.sortable-placeholder { height:42px; }

        /* Info box (styl FIELDS) */
        .tl-info-box { background:#f8fafc; border:1px solid #d7dde7; border-radius:10px; padding:14px 16px; margin-bottom:16px; font-size:13px; color:#334155; box-shadow:0 1px 2px rgba(15,23,42,.04); }
        .tl-info-box code { background:#eef2ff; color:#3730a3; padding:1px 4px; border-radius:4px; }

        /* Przyciski */
        .button.button-primary { background:#2563eb; border-color:#2563eb; }
        .button.button-primary:hover { background:#1d4ed8; border-color:#1d4ed8; }
        .button-link-delete { color:#b42318 !important; }
    </style>

    <div class="wrap">
        <h1><?php echo esc_html(TL_MENU_TITLE); ?> <span style="font-size:11px;color:#94a3b8;font-weight:400;">v<?php echo esc_html(TL_VERSION); ?></span></h1>
        <?php
        $pct_html = '';
        foreach ($stats['by_lang'] as $code => $pct) {
            $pct_html .= ' <span class="tl-badge-pct">' . esc_html(strtoupper($code)) . ' ' . esc_html($pct) . '%</span>';
        }
        ?>
        <div class="tl-tabs">
            <a href="<?php echo esc_url(add_query_arg('tab','translations',$base)); ?>" class="tl-tab <?php echo $tab==='translations'?'active':''; ?>">EVOKE Tłumaczenia <?php echo $pct_html; ?></a>
            <a href="<?php echo esc_url(add_query_arg('tab','images',$base)); ?>" class="tl-tab <?php echo $tab==='images'?'active':''; ?>">Obrazki</a>
            <a href="<?php echo esc_url(add_query_arg('tab','slugs',$base)); ?>" class="tl-tab <?php echo $tab==='slugs'?'active':''; ?>">Slugi URL</a>
            <a href="<?php echo esc_url(add_query_arg('tab','dd',$base)); ?>" class="tl-tab <?php echo $tab==='dd'?'active':''; ?>">Dane Dynamiczne</a>
            <a href="<?php echo esc_url(add_query_arg('tab','languages',$base)); ?>" class="tl-tab <?php echo $tab==='languages'?'active':''; ?>">Jezyki i Ustawienia</a>
        </div>

        <div class="tl-panel">
        <?php if ($tab === 'translations'): ?>
            <?php require __DIR__ . '/tab-translations.php'; ?>

        <?php elseif ($tab === 'images'): ?>
            <?php require __DIR__ . '/tab-images.php'; ?>

        <?php elseif ($tab === 'slugs'): ?>
            <?php require __DIR__ . '/tab-slugs.php'; ?>

        <?php elseif ($tab === 'sitemap'): ?>
            <?php require __DIR__ . '/tab-sitemap.php'; ?>

        <?php elseif ($tab === 'dd'): ?>
            <?php require __DIR__ . '/tab-dd.php'; ?>

        <?php elseif ($tab === 'languages'): ?>
            <?php require __DIR__ . '/tab-languages.php'; ?>

        <?php elseif ($tab === 'io'): ?>
            <?php require __DIR__ . '/tab-io.php'; ?>
        <?php endif; ?>
        </div>
    </div>
    <?php
}
