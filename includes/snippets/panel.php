<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — snippety: lista wpisów i edytor.
 *
 * Zastępuje cztery stałe okna z lat sprzed 1.139.0. Ekran ma dwa stany:
 * LISTA (co masz i co pracuje) oraz EDYTOR jednego wpisu. Logi błędów
 * i tryb Advanced zostają tam, gdzie były.
 */

/** Adres zakładki — jedno miejsce, bo składa go i lista, i każdy formularz. */
function evk_snippety_url(array $args = []): string {
    return add_query_arg($args, admin_url('options-general.php?page=evoke-one&tab=narzedzia&sub=snippets'));
}

function evk_snippets_render_tab(): void {
    if (!current_user_can('manage_options')) return;

    $wylaczone_stala = defined('EVK_CODE_DISABLE') && EVK_CODE_DISABLE;
    $wlaczone        = (int) get_option(EVK_SNIPPETS_ENABLED_OPTION, 0);
    $adv             = (int) get_option(EVK_SNIPPETS_ADVANCED_ENABLED, 0);
    $fatal           = get_transient(EVK_SNIPPETS_FATAL_TRANSIENT);
    $logi            = (array) get_option(EVK_SNIPPETS_LOG_OPTION, []);

    $widok = sanitize_key($_GET['evk_widok'] ?? 'lista');
    if (!in_array($widok, ['lista', 'edytor', 'logi', 'advanced'], true)) $widok = 'lista';
    if ($widok === 'advanced' && !$adv) $widok = 'lista';

    if (!empty($_GET['evk_zapisano'])) {
        printf('<div class="updated notice is-dismissible"><p>%s</p></div>',
            esc_html([
                'wpis'    => 'Snippet zapisany.',
                'usuniety'=> 'Snippet usunięty.',
                'stan'    => 'Stan snippetu zmieniony.',
                'logi'    => 'Logi wyczyszczone.',
            ][$_GET['evk_zapisano']] ?? 'Zapisano.'));
    }

    // ── Stan modułu ───────────────────────────────────────────────────────
    ?>
    <div class="evo-status-card evo-mb">
        <div class="evo-status-icon <?php echo $wlaczone ? 'on' : 'off'; ?>">
            <span class="dashicons dashicons-editor-code"></span>
        </div>
        <div class="evo-status-text">
            <h3>Snippety: <?php echo $wlaczone ? 'włączone' : 'wyłączone'; ?></h3>
            <p><?php echo $wlaczone
                ? 'Wpisy oznaczone jako włączone są wykonywane.'
                : 'Nic się nie wykonuje, niezależnie od stanu pojedynczych wpisów.'; ?></p>
        </div>
        <div class="evo-status-actions">
            <span class="evo-toggle-label"><?php echo $wlaczone ? 'Włączone' : 'Wyłączone'; ?></span>
            <label class="evo-toggle">
                <input type="checkbox" data-option="evk_snippets_enabled" data-field="_scalar"
                       value="1" <?php checked(1, $wlaczone); ?>>
                <span class="evo-slider"></span>
            </label>
        </div>
    </div>

    <?php if ($wylaczone_stala): ?>
    <div class="notice notice-error inline evo-mb">
        <p><strong>Wykonywanie wyłączone stałą <code>EVK_CODE_DISABLE</code>.</strong>
           Usuń ją z <code>wp-config.php</code>, żeby włączyć z powrotem.</p>
    </div>
    <?php endif; ?>

    <?php if (!$wlaczone && is_array($fatal)): ?>
    <div class="notice notice-error inline evo-mb">
        <p><strong>Wykonywanie wyłączyło się samo po błędzie krytycznym.</strong></p>
        <p class="evo-mono-xs"><?php echo esc_html($fatal['message'] ?? ''); ?>
           <?php if (!empty($fatal['slug']) && $fatal['slug'] !== 'unknown'): ?>
           — <?php echo esc_html($fatal['slug']); ?><?php endif; ?></p>
    </div>
    <?php endif; ?>

    <div class="evk-subtabs">
        <?php
        $zakladki = ['lista' => 'Wpisy', 'logi' => 'Logi błędów'];
        if ($adv) $zakladki['advanced'] = 'Tryb Advanced';
        foreach ($zakladki as $klucz => $etykieta):
            $aktywna = ($widok === $klucz) || ($widok === 'edytor' && $klucz === 'lista');
        ?>
        <a href="<?php echo esc_url(evk_snippety_url(['evk_widok' => $klucz])); ?>"
           class="evk-subtab<?php echo $aktywna ? ' is-active' : ''; ?>">
            <?php echo esc_html($etykieta); ?><?php if ($klucz === 'logi' && $logi): ?>
            <span class="evo-count-badge"><?php echo count($logi); ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php
    if ($widok === 'edytor')        evk_snippety_edytor();
    elseif ($widok === 'logi')      evk_snippety_logi($logi);
    elseif ($widok === 'advanced')  evk_snippety_advanced();
    else                            evk_snippety_lista();
}

// =========================================================================
// LISTA
// =========================================================================

function evk_snippety_lista(): void {
    $wpisy   = evk_snippety_wszystkie();
    $rodzaje = evk_snippet_rodzaje();
    $miejsca = evk_snippet_miejsca();

    $sortuj = sanitize_key($_GET['evk_sort'] ?? '');
    if (in_array($sortuj, ['rodzaj', 'grupa', 'tytul'], true)) {
        usort($wpisy, function ($a, $b) use ($sortuj) {
            return strcasecmp((string) $a[$sortuj], (string) $b[$sortuj]);
        });
    }
    ?>
    <div class="evo-row-between evo-mb">
        <p class="evo-hint evo-m0">
            Rodzaj decyduje, czym wpis jest i w co system go owija — przy CSS-ie
            i skrypcie nie wpisujesz już <code>&lt;style&gt;</code> ani
            <code>&lt;script&gt;</code>, a przy PHP <code>&lt;?php</code>.
        </p>
        <a href="<?php echo esc_url(evk_snippety_url(['evk_widok' => 'edytor', 'evk_wpis' => 'nowy'])); ?>"
           class="button button-primary">+ Nowy snippet</a>
    </div>

    <?php if (!$wpisy): ?>
    <div class="evo-empty">
        <p>Nie ma jeszcze żadnego wpisu.</p>
    </div>
    <?php else: ?>
    <table class="wp-list-table widefat fixed striped">
        <thead><tr>
            <th class="evo-w" style="--evo-w:60px">Stan</th>
            <th><a href="<?php echo esc_url(evk_snippety_url(['evk_sort' => 'tytul'])); ?>">Nazwa</a></th>
            <th class="evo-w" style="--evo-w:130px"><a href="<?php echo esc_url(evk_snippety_url(['evk_sort' => 'rodzaj'])); ?>">Rodzaj</a></th>
            <th class="evo-w" style="--evo-w:190px">Miejsce</th>
            <th class="evo-w" style="--evo-w:150px"><a href="<?php echo esc_url(evk_snippety_url(['evk_sort' => 'grupa'])); ?>">Grupa</a></th>
            <th class="evo-w" style="--evo-w:70px">Kolejność</th>
            <th class="evo-w" style="--evo-w:150px">Akcje</th>
        </tr></thead>
        <tbody>
        <?php foreach ($wpisy as $w): ?>
        <tr>
            <td>
                <form method="post" class="evo-inline-block">
                    <?php wp_nonce_field('evk_snippets_save', 'evk_snippets_nonce_field'); ?>
                    <input type="hidden" name="evk_przelacz_wpis" value="<?php echo (int) $w['id']; ?>">
                    <button type="submit" class="evo-stan-btn <?php echo $w['wlaczony'] ? 'is-on' : 'is-off'; ?>"
                            title="<?php echo $w['wlaczony'] ? 'Wyłącz' : 'Włącz'; ?>">
                        <?php echo $w['wlaczony'] ? 'wł.' : 'wył.'; ?>
                    </button>
                </form>
            </td>
            <td><strong><?php echo esc_html($w['tytul']); ?></strong></td>
            <td><span class="evo-badge"><?php echo esc_html($rodzaje[$w['rodzaj']]['label'] ?? $w['rodzaj']); ?></span></td>
            <td class="evo-hint"><?php echo esc_html($miejsca[$w['miejsce']]['label'] ?? $w['miejsce']); ?></td>
            <td class="evo-hint"><?php echo $w['grupa'] !== '' ? esc_html($w['grupa']) : '—'; ?></td>
            <td class="evo-hint"><?php echo (int) $w['kolejnosc']; ?></td>
            <td>
                <a href="<?php echo esc_url(evk_snippety_url(['evk_widok' => 'edytor', 'evk_wpis' => $w['id']])); ?>"
                   class="button button-small">Edytuj</a>
                <form method="post" class="evo-inline-block"
                      onsubmit="return confirm('Usunąć snippet „<?php echo esc_js($w['tytul']); ?>”?');">
                    <?php wp_nonce_field('evk_snippets_save', 'evk_snippets_nonce_field'); ?>
                    <input type="hidden" name="evk_usun_wpis" value="<?php echo (int) $w['id']; ?>">
                    <button type="submit" class="button button-small evo-danger-tx">Usuń</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <?php
}

// =========================================================================
// EDYTOR
// =========================================================================

function evk_snippety_edytor(): void {
    $id = $_GET['evk_wpis'] ?? 'nowy';
    $id = ($id === 'nowy') ? 0 : (int) $id;

    $wpis = ['id' => 0, 'tytul' => '', 'kod' => '', 'rodzaj' => 'php',
             'miejsce' => 'head', 'grupa' => '', 'wlaczony' => 1, 'kolejnosc' => 0];
    if ($id) {
        foreach (evk_snippety_wszystkie() as $w) if ($w['id'] === $id) $wpis = $w;
    }

    $rodzaje = evk_snippet_rodzaje();
    $miejsca = evk_snippet_miejsca();
    ?>
    <a href="<?php echo esc_url(evk_snippety_url()); ?>" class="button evo-mb">← Wróć do listy</a>

    <form method="post">
        <?php wp_nonce_field('evk_snippets_save', 'evk_snippets_nonce_field'); ?>
        <input type="hidden" name="evk_wpis_id" value="<?php echo (int) $wpis['id']; ?>">

        <div class="evo-grid" style="--evo-col:220px;--evo-gap:16px">
            <div class="evo-field">
                <label for="evk-tytul">Nazwa</label>
                <input type="text" id="evk-tytul" name="evk_tytul" required
                       value="<?php echo esc_attr($wpis['tytul']); ?>" placeholder="np. Sticky header">
            </div>
            <div class="evo-field">
                <label for="evk-rodzaj">Rodzaj</label>
                <select id="evk-rodzaj" name="evk_rodzaj">
                    <?php foreach ($rodzaje as $k => $r): ?>
                    <option value="<?php echo esc_attr($k); ?>" <?php selected($k, $wpis['rodzaj']); ?>>
                        <?php echo esc_html($r['label']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="evo-desc" id="evk-opis-rodzaju"></div>
            </div>
            <div class="evo-field">
                <label for="evk-miejsce">Miejsce</label>
                <select id="evk-miejsce" name="evk_miejsce">
                    <?php foreach ($miejsca as $k => $m): ?>
                    <option value="<?php echo esc_attr($k); ?>" <?php selected($k, $wpis['miejsce']); ?>>
                        <?php echo esc_html($m['label']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="evo-field">
                <label for="evk-grupa">Grupa</label>
                <input type="text" id="evk-grupa" name="evk_grupa"
                       value="<?php echo esc_attr($wpis['grupa']); ?>" placeholder="np. SEO, klient X">
                <div class="evo-desc">Tylko do Waszego porządku — sortowanie i szukanie.</div>
            </div>
            <div class="evo-field">
                <label for="evk-kolejnosc">Kolejność</label>
                <input type="number" id="evk-kolejnosc" name="evk_kolejnosc"
                       value="<?php echo (int) $wpis['kolejnosc']; ?>" step="10">
                <div class="evo-desc">Mniejsza liczba wykonuje się wcześniej.</div>
            </div>
            <div class="evo-field">
                <label class="evo-check">
                    <input type="checkbox" name="evk_wlaczony" value="1" <?php checked(1, $wpis['wlaczony']); ?>>
                    <span class="evo-strong-500">Włączony</span>
                </label>
            </div>
        </div>

        <div class="evo-field evo-mt-lg">
            <label for="evk-kod">Kod</label>
            <textarea id="evk-kod" name="evk_kod" rows="24" class="evo-mono"
                      spellcheck="false"><?php echo esc_textarea($wpis['kod']); ?></textarea>
        </div>

        <div class="evo-save-bar">
            <?php submit_button('Zapisz snippet', 'primary', 'evk_zapisz_wpis', false); ?>
            <a href="<?php echo esc_url(evk_snippety_url()); ?>" class="button">Anuluj</a>
        </div>
    </form>

    <script>
    /* Opis rodzaju pod wyborem — żeby nie trzeba było zgadywać, co system
       zrobi z treścią. Dane z PHP, bo to on jest źródłem listy rodzajów. */
    (function () {
        var opisy = <?php echo wp_json_encode(array_map(function ($r) { return $r['opis']; }, $rodzaje)); ?>;
        var wybor = document.getElementById('evk-rodzaj');
        var opis  = document.getElementById('evk-opis-rodzaju');
        if (!wybor || !opis) return;
        var pokaz = function () { opis.innerHTML = opisy[wybor.value] || ''; };
        wybor.addEventListener('change', pokaz);
        pokaz();
    })();
    </script>
    <?php
}

// =========================================================================
// LOGI
// =========================================================================

function evk_snippety_logi(array $logi): void {
    if (!$logi): ?>
        <div class="evo-empty"><p>Brak zapisanych błędów.</p></div>
    <?php return; endif; ?>

    <table class="wp-list-table widefat fixed striped evo-hint-sm">
        <thead><tr>
            <th class="evo-w" style="--evo-w:140px">Kiedy</th>
            <th class="evo-w" style="--evo-w:130px">Rodzaj</th>
            <th class="evo-w" style="--evo-w:180px">Snippet</th>
            <th>Komunikat</th>
        </tr></thead>
        <tbody>
        <?php foreach (array_reverse($logi) as $log): ?>
        <tr>
            <td><?php echo esc_html($log['time'] ?? ''); ?></td>
            <td><span class="evo-badge"><?php echo esc_html($log['type'] ?? ''); ?></span></td>
            <td class="evo-mono-xs"><?php echo esc_html($log['slug'] ?? '—'); ?></td>
            <td>
                <?php echo esc_html($log['message'] ?? ''); ?>
                <?php if (!empty($log['line'])): ?>
                <span class="evo-hint">— linia <?php echo (int) $log['line']; ?></span>
                <?php endif; ?>
                <?php if (!empty($log['context'])): ?>
                <pre class="evo-mono-xs evo-inset"><?php echo esc_html($log['context']); ?></pre>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <form method="post" class="evo-mt-lg" onsubmit="return confirm('Wyczyścić wszystkie logi?');">
        <?php wp_nonce_field('evk_snippets_save', 'evk_snippets_nonce_field'); ?>
        <button type="submit" name="evk_clear_logs" class="button">Wyczyść logi</button>
    </form>
    <?php
}

// =========================================================================
// TRYB ADVANCED — jedno pole wykonywane bez podziału na wpisy
// =========================================================================

function evk_snippety_advanced(): void { ?>
    <div class="notice notice-error inline evo-mb">
        <p><strong>Kod z tego pola wykonuje się bez podziału na wpisy</strong>,
           więc błąd tutaj gasi całe wykonywanie, a nie jeden snippet.
           Zwykłe wpisy są bezpieczniejsze — to pole zostaje dla przypadków,
           które muszą zdążyć przed wszystkim innym.</p>
    </div>
    <form method="post">
        <?php wp_nonce_field('evk_snippets_save', 'evk_snippets_nonce_field'); ?>
        <div class="evo-field">
            <label for="evk_advanced_code">Kod PHP</label>
            <textarea id="evk_advanced_code" name="evk_advanced_code" rows="24" class="evo-mono"
                      spellcheck="false"><?php echo esc_textarea(evk_snippets_advanced_get()); ?></textarea>
        </div>
        <div class="evo-save-bar">
            <?php submit_button('Zapisz', 'primary', 'evk_zapisz_advanced', false); ?>
        </div>
    </form>
    <?php
}
