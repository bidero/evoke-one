<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Strona Skrzynki Wiadomości (mail-client UI)
 */

$s     = evk_inbox_get_settings();
$nonce = wp_create_nonce('evk_inbox_nonce');
$export_url = wp_nonce_url(admin_url('admin.php?action=evk_inbox_export'), 'evk_inbox_export');
$table_ok   = evk_inbox_table_exists();
?>
<div class="evk-inbox-app" data-nonce="<?php echo esc_attr($nonce); ?>" data-export-url="<?php echo esc_url($export_url); ?>">

<?php if (!$table_ok): ?>
<div class="evk-inbox-notice">
    <span class="dashicons dashicons-warning" style="color:#d97706;font-size:22px;"></span>
    <div>
        <strong>Brak tabeli zgłoszeń Bricks.</strong><br>
        Przejdź do <strong>Bricks → Ustawienia → Ogólne</strong> i włącz opcję <em>„Zapisuj zgłoszenia formularzy w bazie danych"</em>, a następnie w formularzach dodaj akcję <em>„Save Submission"</em>.
    </div>
</div>
<?php endif; ?>

<!-- TOPBAR -->
<div class="evk-inbox-topbar">
    <div class="evk-inbox-topbar-left">
        <select id="evk-form-filter" class="evk-inbox-select" <?php echo !$table_ok ? 'disabled' : ''; ?>>
            <option value="all">Wszystkie formularze</option>
        </select>
        <div class="evk-inbox-search-wrap">
            <span class="dashicons dashicons-search"></span>
            <input type="search" id="evk-inbox-search" placeholder="Szukaj w wiadomościach…" autocomplete="off" <?php echo !$table_ok ? 'disabled' : ''; ?>>
        </div>
    </div>
    <div class="evk-inbox-topbar-right">
        <button id="evk-bulk-toggle" class="evk-inbox-btn evk-inbox-btn-ghost" <?php echo !$table_ok ? 'disabled' : ''; ?>>
            <span class="dashicons dashicons-list-view"></span> Zaznacz
        </button>
        <button id="evk-bulk-delete-btn" class="evk-inbox-btn evk-inbox-btn-danger" style="display:none;">
            <span class="dashicons dashicons-trash"></span> Usuń zaznaczone
        </button>
        <input type="date" id="evk-export-from" title="Eksport od" style="height:32px;border:1px solid #cbd5e1;border-radius:6px;padding:0 6px;font-size:12px;color:#475569;">
        <input type="date" id="evk-export-to" title="Eksport do" style="height:32px;border:1px solid #cbd5e1;border-radius:6px;padding:0 6px;font-size:12px;color:#475569;">
        <button id="evk-export-btn" class="evk-inbox-btn evk-inbox-btn-ghost" <?php echo !$table_ok ? 'disabled' : ''; ?>>
            <span class="dashicons dashicons-download"></span> CSV
        </button>
    </div>
</div>

<!-- BODY: lista + detal -->
<div class="evk-inbox-body">
    <!-- SIDEBAR -->
    <div class="evk-inbox-sidebar">
        <div class="evk-inbox-list-wrap">
            <div id="evk-inbox-list" class="evk-inbox-list">
                <?php if (!$table_ok): ?>
                <div class="evk-inbox-empty"><span class="dashicons dashicons-email"></span><p>Brak danych</p></div>
                <?php else: ?>
                <div class="evk-inbox-loading"><span class="spinner is-active"></span></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="evk-inbox-pagination" id="evk-inbox-pagination"></div>
    </div>

    <!-- DETAIL -->
    <div class="evk-inbox-detail" id="evk-inbox-detail">
        <div class="evk-inbox-detail-empty">
            <span class="dashicons dashicons-email-alt2"></span>
            <p>Wybierz wiadomość z listy</p>
        </div>
    </div>
</div>

</div><!-- .evk-inbox-app -->

<style>
/* ============================================================
   EVK FORM INBOX — UI
   ============================================================ */
#wpcontent { padding-left: 0 !important; }
#wpbody-content { padding-bottom: 0; }

.evk-inbox-app {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 32px);
    background: #f0f2f5;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    font-size: 13px;
    color: #1e293b;
    overflow: hidden;
}

/* NOTICE */
.evk-inbox-notice {
    display: flex; align-items: flex-start; gap: 12px;
    background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px;
    padding: 14px 18px; margin: 16px 20px 0; font-size: 13px; line-height: 1.5;
}

/* TOPBAR */
.evk-inbox-topbar {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 10px 16px;
    background: #fff;
    border-bottom: 1px solid #e2e8f0;
    flex-shrink: 0;
    min-height: 52px;
}
.evk-inbox-topbar-left, .evk-inbox-topbar-right { display: flex; align-items: center; gap: 8px; }

.evk-inbox-select {
    height: 32px; border: 1px solid #d1d5db; border-radius: 6px;
    padding: 0 10px; font-size: 13px; background: #f8fafc;
    color: #374151; cursor: pointer; min-width: 160px;
}
.evk-inbox-select:focus { outline: none; border-color: #2563eb; }

.evk-inbox-search-wrap {
    position: relative; display: flex; align-items: center;
}
.evk-inbox-search-wrap .dashicons {
    position: absolute; left: 8px; color: #9ca3af; font-size: 16px; width: 16px; height: 16px; line-height: 1;
}
#evk-inbox-search {
    height: 32px; border: 1px solid #d1d5db; border-radius: 6px;
    padding: 0 10px 0 28px; font-size: 13px; width: 220px;
    background: #f8fafc; color: #374151;
    transition: border-color .15s, width .2s;
}
#evk-inbox-search:focus { outline: none; border-color: #2563eb; width: 280px; background: #fff; }

/* BUTTONS */
.evk-inbox-btn {
    display: inline-flex; align-items: center; gap: 5px;
    height: 32px; padding: 0 12px; border-radius: 6px; font-size: 12px; font-weight: 500;
    border: 1px solid transparent; cursor: pointer; transition: background .15s, border-color .15s;
    white-space: nowrap;
}
.evk-inbox-btn .dashicons { font-size: 14px; width: 14px; height: 14px; line-height: 1; }
.evk-inbox-btn-ghost { background: #f1f5f9; border-color: #e2e8f0; color: #475569; }
.evk-inbox-btn-ghost:hover { background: #e2e8f0; border-color: #cbd5e1; color: #1e293b; }
.evk-inbox-btn-ghost:disabled { opacity: .45; cursor: not-allowed; }
.evk-inbox-app a.evk-inbox-btn-primary,
.evk-inbox-app a.evk-inbox-btn-primary:link,
.evk-inbox-app a.evk-inbox-btn-primary:visited,
.evk-inbox-app a.evk-inbox-btn-primary:hover,
.evk-inbox-app a.evk-inbox-btn-primary:focus,
.evk-inbox-app a.evk-inbox-btn-primary:active,
.evk-inbox-btn-primary { background: #2563eb; border-color: #2563eb; }
.evk-inbox-btn-primary:hover, .evk-inbox-app a.evk-inbox-btn-primary:hover { background: #1d4ed8; }
.evk-inbox-btn-primary, .evk-inbox-btn-primary *,
.evk-inbox-app a.evk-inbox-btn-primary,
.evk-inbox-app a.evk-inbox-btn-primary * { color: #fff !important; }
.evk-inbox-btn-danger { background: #fef2f2; border-color: #fca5a5; color: #dc2626; }
.evk-inbox-btn-danger:hover { background: #fee2e2; }
.evk-inbox-btn.active { background: #2563eb; border-color: #2563eb; color: #fff; }

/* BODY */
.evk-inbox-body {
    display: flex; flex: 1; overflow: hidden;
}

/* SIDEBAR */
.evk-inbox-sidebar {
    width: 300px; min-width: 220px; flex-shrink: 0;
    display: flex; flex-direction: column;
    border-right: 1px solid #e2e8f0;
    background: #fff;
    overflow: hidden;
}
.evk-inbox-list-wrap { flex: 1; overflow-y: auto; }
.evk-inbox-list { }

/* LIST ITEMS */
.evk-inbox-item {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 12px 14px; border-bottom: 1px solid #f1f5f9;
    cursor: pointer; transition: background .1s;
    position: relative;
}
.evk-inbox-item:hover { background: #f8fafc; }
.evk-inbox-item.active { background: #eff6ff; border-left: 3px solid #2563eb; }
.evk-inbox-item.active .evk-inbox-item-inner { padding-left: 0; }

.evk-inbox-item-check {
    flex-shrink: 0; margin-top: 2px;
    display: none;
}
.evk-inbox-bulk-mode .evk-inbox-item-check { display: block; }
.evk-inbox-bulk-mode .evk-inbox-dot { display: none; }

.evk-inbox-dot {
    width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; margin-top: 5px;
    background: #2563eb;
}
.evk-inbox-item.is-read .evk-inbox-dot { background: transparent; }

.evk-inbox-item-inner { flex: 1; min-width: 0; }
.evk-inbox-item-header {
    display: flex; justify-content: space-between; align-items: baseline; gap: 4px; margin-bottom: 2px;
}
.evk-inbox-item-name {
    font-size: 13px; font-weight: 600; color: #1e293b;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.evk-inbox-item.is-read .evk-inbox-item-name { font-weight: 400; color: #64748b; }
.evk-inbox-item-date { font-size: 11px; color: #94a3b8; white-space: nowrap; flex-shrink: 0; }
.evk-inbox-item-form {
    font-size: 11px; color: #2563eb; background: #eff6ff;
    border-radius: 4px; padding: 1px 6px; display: inline-block; margin-bottom: 3px;
}
.evk-inbox-item-preview {
    font-size: 12px; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.evk-inbox-item-meta {
    font-size: 11px; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 1px;
}

/* PAGINATION */
.evk-inbox-pagination {
    padding: 8px 14px; display: flex; align-items: center; justify-content: space-between;
    border-top: 1px solid #f1f5f9; font-size: 12px; color: #6b7280;
    flex-shrink: 0; min-height: 40px;
}
.evk-inbox-pagination-btns { display: flex; gap: 4px; }
.evk-inbox-page-btn {
    height: 26px; min-width: 26px; padding: 0 6px; border: 1px solid #e2e8f0;
    border-radius: 4px; background: #fff; color: #374151; font-size: 12px; cursor: pointer;
    transition: background .1s;
}
.evk-inbox-page-btn:hover:not(:disabled) { background: #f1f5f9; }
.evk-inbox-page-btn:disabled { opacity: .4; cursor: not-allowed; }
.evk-inbox-page-btn.active { background: #2563eb; border-color: #2563eb; color: #fff; }

/* DETAIL */
.evk-inbox-detail {
    flex: 1; overflow-y: auto; padding: 24px 28px;
    background: #f8fafc;
}

.evk-inbox-detail-empty {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    height: 100%; color: #94a3b8; text-align: center; gap: 10px;
}
.evk-inbox-detail-empty .dashicons { font-size: 48px; width: 48px; height: 48px; line-height: 1; }
.evk-inbox-detail-empty p { font-size: 14px; margin: 0; }

.evk-inbox-detail-header {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
    margin-bottom: 20px;
}
.evk-inbox-detail-title h2 {
    font-size: 18px; font-weight: 700; color: #0f172a; margin: 0 0 4px;
}
.evk-inbox-detail-title .evk-inbox-meta-form {
    font-size: 12px; color: #2563eb; background: #eff6ff;
    border-radius: 4px; padding: 2px 8px; display: inline-block;
}
.evk-inbox-detail-subtitle {
    font-size: 14px; font-weight: 500; color: #334155; margin: 0 0 8px; line-height: 1.4;
}
.evk-inbox-detail-tags { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.evk-inbox-hline { font-size: 12px; color: #475569; margin-top: 6px; line-height: 1.4; }
.evk-inbox-hline-label { color: #94a3b8; font-weight: 600; }
.evk-inbox-detail-actions { display: flex; gap: 8px; flex-shrink: 0; }

.evk-inbox-meta-bar {
    display: flex; flex-wrap: wrap; gap: 8px 20px;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;
    padding: 12px 16px; margin-bottom: 20px; font-size: 12px; color: #6b7280;
}
.evk-inbox-meta-bar span { display: flex; align-items: center; gap: 5px; }
.evk-inbox-meta-bar .dashicons { font-size: 13px; width: 13px; height: 13px; line-height: 1; color: #94a3b8; }
.evk-inbox-meta-bar strong { color: #374151; }

.evk-inbox-fields {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
}
.evk-inbox-field {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px;
}
.evk-inbox-field-label {
    font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px;
    color: #94a3b8; margin-bottom: 5px;
}
.evk-inbox-field-value {
    font-size: 13px; color: #1e293b; line-height: 1.5; word-break: break-word;
    white-space: pre-wrap;
}
.evk-inbox-field.is-long { grid-column: 1 / -1; }

/* RENDERED TEMPLATE */
.evk-inbox-rendered {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;
    padding: 20px 24px; font-family: monospace; font-size: 13px; line-height: 1.8;
    color: #1e293b; white-space: pre-wrap; word-break: break-word;
}

/* LOADING & EMPTY */
.evk-inbox-loading { display: flex; align-items: center; justify-content: center; padding: 40px; }
.evk-inbox-empty { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 40px 20px; color: #94a3b8; text-align: center; }
.evk-inbox-empty .dashicons { font-size: 36px; width: 36px; height: 36px; line-height: 1; }
.evk-inbox-empty p { font-size: 13px; margin: 0; }

.evk-inbox-detail-email { font-size: 12px; color: #64748b; }

/* Przycisk powrotu do listy — istnieje tylko na wąskim ekranie, gdzie lista
   i szczegóły nie stoją obok siebie. Na szerokim jest zbędny i go nie ma. */
.evk-inbox-back { display: none; }

/* ============================================================
   WĄSKI EKRAN — lista i szczegóły jedno po drugim
   ============================================================
   Dwie kolumny obok siebie mają sens od jakichś 700 px w górę; niżej sidebar
   o stałej szerokości 300 px zjada połowę ekranu, a na szczegóły zostaje pas
   nie do czytania. Poniżej 782 px (ten sam próg, na którym WordPress przechodzi
   w tryb dotykowy) widać ALBO listę, ALBO szczegóły — klasę `is-detail`
   przełącza wybór wiadomości i przycisk powrotu.
   ============================================================ */
@media screen and (max-width: 782px) {
    .evk-inbox-app { height: calc(100vh - 46px); }   /* wyższy pasek admina */

    .evk-inbox-body { display: block; position: relative; height: 100%; }

    .evk-inbox-sidebar,
    .evk-inbox-detail {
        width: 100%;
        min-width: 0;
        height: 100%;
        border-right: 0;
        box-sizing: border-box;
    }
    .evk-inbox-detail { display: none; padding: 16px 14px; }

    .evk-inbox-app.is-detail .evk-inbox-sidebar { display: none; }
    .evk-inbox-app.is-detail .evk-inbox-detail  { display: block; }

    /* Powrót jest pierwszą rzeczą w kadrze — bez niego wejście w wiadomość
       na telefonie jest ślepą uliczką. */
    .evk-inbox-back {
        display: inline-flex; align-items: center; gap: 4px;
        height: 34px; margin-bottom: 14px; padding: 0 12px 0 8px;
        border: 1px solid #e2e8f0; border-radius: 6px;
        background: #fff; color: #475569; font-size: 13px; cursor: pointer;
    }
    .evk-inbox-back:hover { background: #f1f5f9; }
    .evk-inbox-back .dashicons { font-size: 16px; width: 16px; height: 16px; line-height: 1; }

    /* Pasek narzędzi łamie się na dwa rzędy zamiast wypychać eksport poza ekran. */
    .evk-inbox-topbar { flex-wrap: wrap; gap: 8px; padding: 10px 12px; }
    .evk-inbox-topbar-left,
    .evk-inbox-topbar-right { flex-wrap: wrap; width: 100%; }
    /* Wyszukiwarka dostaje własny wiersz. Dzielona z listą formularzy schodzi
       do ~180 px i ucina własną podpowiedź w połowie słowa. */
    .evk-inbox-search-wrap { flex: 1 1 100%; min-width: 0; }
    #evk-inbox-search { width: 100%; }
    #evk-inbox-search:focus { width: 100%; }
    .evk-inbox-select { flex: 1 1 100%; min-width: 0; }

    /* Cele dotykowe — przyciski paska miały 32 px. */
    .evk-inbox-btn { height: 38px; padding: 0 14px; font-size: 13px; }
    #evk-export-from, #evk-export-to { height: 38px !important; flex: 1; min-width: 0; }

    /* Wiersz listy: większy cel dotknięcia. */
    .evk-inbox-item { padding: 14px; }

    .evk-inbox-detail-header { flex-direction: column; gap: 12px; }
    .evk-inbox-detail-actions { width: 100%; }
    .evk-inbox-detail-actions .evk-inbox-btn { flex: 1; justify-content: center; }
}
</style>

<script>
(function($) {
    'use strict';

    const APP     = document.querySelector('.evk-inbox-app');
    const NONCE   = APP.dataset.nonce;
    const EXPORT_URL = APP.dataset.exportUrl;
    const AJAX    = window.ajaxurl;

    let state = {
        form_id   : 'all',
        search    : '',
        page      : 1,
        active_id : null,
        bulk_mode : false,
        selected  : new Set(),
        timer     : null,
    };

    // ── Helpers ─────────────────────────────────────────────
    function ajax(action, data, method = 'GET') {
        const opts = { url: AJAX, data: Object.assign({ action, nonce: NONCE }, data) };
        if (method === 'POST') { opts.method = 'POST'; }
        else { opts.type = 'GET'; }
        return $.ajax(opts);
    }

    function esc(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Load forms (sidebar dropdown) ───────────────────────
    function loadForms() {
        ajax('evk_inbox_forms').done(function(r) {
            if (!r.success) return;
            const sel = document.getElementById('evk-form-filter');
            // Zachowaj "all"
            while (sel.options.length > 1) sel.remove(1);
            r.data.forms.forEach(function(f) {
                const opt = document.createElement('option');
                opt.value = f.form_id;
                opt.textContent = (f.form_label || f.form_id) + (f.unread ? ' (' + f.unread + ')' : '');
                sel.appendChild(opt);
            });
        });
    }

    // ── Load list ────────────────────────────────────────────
    function loadList(resetPage) {
        if (resetPage) state.page = 1;

        $('#evk-inbox-list').html('<div class="evk-inbox-loading"><span class="spinner is-active"></span></div>');
        $('#evk-inbox-pagination').html('');

        ajax('evk_inbox_list', {
            form_id : state.form_id,
            search  : state.search,
            page    : state.page,
        }).done(function(r) {
            if (!r.success) { $('#evk-inbox-list').html('<div class="evk-inbox-empty"><span class="dashicons dashicons-warning"></span><p>' + (r.data || 'Błąd') + '</p></div>'); return; }

            const d = r.data;
            if (!d.items.length) {
                $('#evk-inbox-list').html('<div class="evk-inbox-empty"><span class="dashicons dashicons-email"></span><p>Brak wiadomości</p></div>');
                return;
            }

            let html = '';
            d.items.forEach(function(item) {
                const readCls  = item.is_read ? 'is-read' : '';
                const activeCls = item.id === state.active_id ? 'active' : '';
                html += `<div class="evk-inbox-item ${readCls} ${activeCls}" data-id="${item.id}">
                    <input type="checkbox" class="evk-inbox-item-check" data-id="${item.id}">
                    <div class="evk-inbox-dot"></div>
                    <div class="evk-inbox-item-inner">
                        <div class="evk-inbox-item-header">
                            <span class="evk-inbox-item-name">${esc(item.name)}</span>
                            <span class="evk-inbox-item-date">${esc(item.date)}</span>
                        </div>
                        <div class="evk-inbox-item-form">${esc(item.form_label || item.form_id)}</div>
                        ${(item.lines || []).map(function(l){ return '<div class="evk-inbox-item-' + (l.type === 'meta' ? 'meta' : 'preview') + '">' + esc(l.text) + '</div>'; }).join('')}
                    </div>
                </div>`;
            });
            $('#evk-inbox-list').html(html);

            // Pagination
            if (d.pages > 1) {
                let pgHtml = `<span>${d.page} / ${d.pages} (${d.total})</span><div class="evk-inbox-pagination-btns">`;
                pgHtml += `<button class="evk-inbox-page-btn" data-page="${d.page - 1}" ${d.page <= 1 ? 'disabled' : ''}>&lsaquo;</button>`;
                // max 5 page buttons
                const start = Math.max(1, d.page - 2);
                const end   = Math.min(d.pages, start + 4);
                for (let i = start; i <= end; i++) {
                    pgHtml += `<button class="evk-inbox-page-btn ${i === d.page ? 'active' : ''}" data-page="${i}">${i}</button>`;
                }
                pgHtml += `<button class="evk-inbox-page-btn" data-page="${d.page + 1}" ${d.page >= d.pages ? 'disabled' : ''}>&rsaquo;</button>`;
                pgHtml += '</div>';
                $('#evk-inbox-pagination').html(pgHtml);
            } else {
                $('#evk-inbox-pagination').html(`<span>${d.total} wiadomości</span>`);
            }

            // ŚWIADOMIE bez auto-otwierania pierwszej wiadomości.
            //
            // loadDetail() oznacza wiadomość jako przeczytaną, więc samo wejście
            // na stronę gasiło kropkę przy pierwszym zgłoszeniu — a nikt go nie
            // czytał. Prawy panel pokazuje teraz stan pusty („Wybierz wiadomość
            // z listy") do chwili kliknięcia.
            //
            // Na wąskim ekranie to jest przy okazji jedyne sensowne zachowanie:
            // najpierw widać listę, a szczegóły wchodzą po wybraniu.
        });
    }

    // ── Load detail ──────────────────────────────────────────
    function loadDetail(id) {
        state.active_id = id;
        // Poniżej 782 px lista i szczegóły nie mieszczą się obok siebie —
        // ta klasa przełącza widok na szczegóły. Na szerokim ekranie nic
        // nie zmienia, bo reguła obowiązuje tylko w media query.
        APP.classList.add('is-detail');
        $('.evk-inbox-item').removeClass('active');
        $(`.evk-inbox-item[data-id="${id}"]`).addClass('active').addClass('is-read').find('.evk-inbox-dot').css('background', 'transparent');

        const detail = document.getElementById('evk-inbox-detail');
        detail.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;"><span class="spinner is-active"></span></div>';

        ajax('evk_inbox_detail', { id }).done(function(r) {
            if (!r.success) { detail.innerHTML = '<div class="evk-inbox-detail-empty"><span class="dashicons dashicons-warning"></span><p>Błąd ładowania</p></div>'; return; }

            const d = r.data;
            let metaHtml = '';
            if (d.meta.date)     metaHtml += `<span><span class="dashicons dashicons-calendar-alt"></span> <strong>${esc(d.meta.date)}</strong></span>`;
            if (d.meta.ip)       metaHtml += `<span><span class="dashicons dashicons-location"></span> ${esc(d.meta.ip)}</span>`;
            if (d.meta.browser)  metaHtml += `<span><span class="dashicons dashicons-desktop"></span> ${esc(d.meta.browser)}${d.meta.os ? ' / ' + esc(d.meta.os) : ''}</span>`;
            if (d.meta.referrer) metaHtml += `<span><span class="dashicons dashicons-external"></span> <a href="${esc(d.meta.referrer)}" target="_blank" style="color:#2563eb;">${esc(d.meta.referrer.replace(/^https?:\/\//, '').substring(0, 60))}</a></span>`;
            if (d.meta.user)     metaHtml += `<span><span class="dashicons dashicons-admin-users"></span> ${esc(d.meta.user)}</span>`;

            // Użyj szablonu jeśli dostępny, inaczej auto-karty
            let fieldsHtml = '';
            if (d.has_template && d.rendered) {
                fieldsHtml = `<div class="evk-inbox-rendered">${d.rendered}</div>`;
            } else {
                d.fields.forEach(function(f) {
                    const isLong = f.value.length > 80 || f.value.includes('\n');
                    fieldsHtml += `<div class="evk-inbox-field ${isLong ? 'is-long' : ''}">
                        <div class="evk-inbox-field-label">${esc(f.label)}</div>
                        <div class="evk-inbox-field-value">${esc(f.value) || '<span style="color:#94a3b8;">—</span>'}</div>
                    </div>`;
                });
                fieldsHtml = `<div class="evk-inbox-fields">${fieldsHtml}</div>`;
            }

            const replyBtn = d.email
                ? `<a href="mailto:${esc(d.email)}" class="evk-inbox-btn evk-inbox-btn-primary"><span class="dashicons dashicons-email"></span> Odpowiedz</a>`
                : '';

            const subtitleHtml = d.subtitle
                ? `<div class="evk-inbox-detail-subtitle">${esc(d.subtitle)}</div>`
                : '';
            let headerLinesHtml = '';
            (d.header_lines || []).forEach(function(l) {
                const lbl = l.label ? `<span class="evk-inbox-hline-label">${esc(l.label)}</span> ` : '';
                headerLinesHtml += `<div class="evk-inbox-hline">${lbl}${esc(l.value)}</div>`;
            });

            detail.innerHTML = `
                <button type="button" class="evk-inbox-back">
                    <span class="dashicons dashicons-arrow-left-alt2"></span> Wszystkie wiadomości
                </button>
                <div class="evk-inbox-detail-header">
                    <div class="evk-inbox-detail-title">
                        <h2>${esc(d.name)}</h2>
                        ${subtitleHtml}
                        <div class="evk-inbox-detail-tags">
                            <span class="evk-inbox-meta-form">${esc(d.form_label || d.form_id)}</span>
                            ${d.email ? `<span class="evk-inbox-detail-email">${esc(d.email)}</span>` : ''}
                        </div>
                        ${headerLinesHtml}
                    </div>
                    <div class="evk-inbox-detail-actions">
                        ${replyBtn}
                        <button class="evk-inbox-btn evk-inbox-btn-ghost evk-mark-btn" data-id="${d.id}" data-state="unread">
                            <span class="dashicons dashicons-marker"></span> Nieprzeczytane
                        </button>
                        <button class="evk-inbox-btn evk-inbox-btn-ghost evk-delete-single-btn" data-id="${d.id}">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </div>
                </div>
                ${metaHtml ? `<div class="evk-inbox-meta-bar">${metaHtml}</div>` : ''}
                ${fieldsHtml}
            `;
        });
    }

    // ── Powrót do listy (wąski ekran) ────────────────────────
    // Nie zerujemy state.active_id: wiadomość zostaje zaznaczona na liście,
    // więc widać, gdzie się było. Wracamy tylko widokiem.
    $(document).on('click', '.evk-inbox-back', function() {
        APP.classList.remove('is-detail');
    });

    // ── Delete ───────────────────────────────────────────────
    function deleteIds(ids, confirmed) {
        if (!confirmed && !confirm(`Usunąć ${ids.length} wiadomość(i)?`)) return;
        ajax('evk_inbox_delete', { ids }, 'POST').done(function(r) {
            if (!r.success) return;
            ids.forEach(function(id) { $(`.evk-inbox-item[data-id="${id}"]`).remove(); });
            if (state.active_id && ids.includes(state.active_id)) {
                state.active_id = null;
                document.getElementById('evk-inbox-detail').innerHTML = '<div class="evk-inbox-detail-empty"><span class="dashicons dashicons-email-alt2"></span><p>Wybierz wiadomość z listy</p></div>';
            }
            state.selected = new Set();
            loadForms();
            // Reload list if empty
            if ($('.evk-inbox-item').length === 0) loadList(false);
        });
    }

    // ── Events ───────────────────────────────────────────────

    // Form filter
    document.getElementById('evk-form-filter').addEventListener('change', function() {
        state.form_id = this.value; loadList(true);
    });

    // Search (debounced)
    document.getElementById('evk-inbox-search').addEventListener('input', function() {
        clearTimeout(state.timer);
        state.search = this.value;
        state.timer  = setTimeout(function() { loadList(true); }, 350);
    });

    // Click item
    $(document).on('click', '.evk-inbox-item', function(e) {
        if ($(e.target).is('input[type=checkbox]')) return;
        const id = parseInt($(this).data('id'));
        if (state.bulk_mode) {
            const cb = $(this).find('.evk-inbox-item-check')[0];
            cb.checked = !cb.checked;
            if (cb.checked) state.selected.add(id); else state.selected.delete(id);
            updateBulkCount();
            return;
        }
        loadDetail(id);
    });

    // Checkbox change
    $(document).on('change', '.evk-inbox-item-check', function() {
        const id = parseInt($(this).data('id'));
        if (this.checked) state.selected.add(id); else state.selected.delete(id);
        updateBulkCount();
    });

    // Pagination
    $(document).on('click', '.evk-inbox-page-btn:not(:disabled)', function() {
        state.page = parseInt($(this).data('page'));
        loadList(false);
    });

    // Mark unread
    $(document).on('click', '.evk-mark-btn', function() {
        const id    = parseInt($(this).data('id'));
        const st    = $(this).data('state');
        ajax('evk_inbox_mark', { ids: [id], state: st }, 'POST').done(function() {
            if (st === 'unread') { $(`.evk-inbox-item[data-id="${id}"]`).removeClass('is-read').find('.evk-inbox-dot').css('background', '#2563eb'); }
        });
    });

    // Delete single
    $(document).on('click', '.evk-delete-single-btn', function() {
        deleteIds([parseInt($(this).data('id'))], false);
    });

    // Bulk toggle
    document.getElementById('evk-bulk-toggle').addEventListener('click', function() {
        state.bulk_mode = !state.bulk_mode;
        state.selected  = new Set();
        $(this).toggleClass('active', state.bulk_mode);
        $('.evk-inbox-list').toggleClass('evk-inbox-bulk-mode', state.bulk_mode);
        $('.evk-inbox-item-check').prop('checked', false);
        $('#evk-bulk-delete-btn').toggle(state.bulk_mode);
        if (!state.bulk_mode) updateBulkCount();
    });

    // Bulk delete
    document.getElementById('evk-bulk-delete-btn').addEventListener('click', function() {
        if (!state.selected.size) return;
        deleteIds([...state.selected], false);
    });

    function updateBulkCount() {
        const n   = state.selected.size;
        const btn = document.getElementById('evk-bulk-delete-btn');
        btn.querySelector('.dashicons').nextSibling.textContent = ` Usuń (${n})`;
        btn.disabled = n === 0;
    }

    // Export CSV
    document.getElementById('evk-export-btn').addEventListener('click', function() {
        let url = EXPORT_URL + '&form_id=' + encodeURIComponent(state.form_id);
        const efrom = document.getElementById('evk-export-from').value;
        const eto   = document.getElementById('evk-export-to').value;
        if (efrom) url += '&from=' + encodeURIComponent(efrom);
        if (eto)   url += '&to=' + encodeURIComponent(eto);
        window.location.href = url;
    });

    // Keyboard navigation (↑↓)
    document.addEventListener('keydown', function(e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        const items = [...document.querySelectorAll('.evk-inbox-item')];
        if (!items.length) return;
        const cur = items.findIndex(el => parseInt(el.dataset.id) === state.active_id);
        if (e.key === 'ArrowDown' && cur < items.length - 1) loadDetail(parseInt(items[cur + 1].dataset.id));
        if (e.key === 'ArrowUp'   && cur > 0)               loadDetail(parseInt(items[cur - 1].dataset.id));
        if (e.key === 'Delete' && state.active_id)          deleteIds([state.active_id], false);
    });

    // ── Init ─────────────────────────────────────────────────
    <?php if ($table_ok): ?>
    loadForms();
    loadList(true);
    <?php endif; ?>

})(jQuery);
</script>
