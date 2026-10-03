<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — 2FA w Profilu kokpitu (1.287.0).
 *
 * Własny profil: trzy kroki na jednym ekranie — kod QR (SVG z własnego
 * kodera ISO/IEC 18004 z opengraph/qr.php, bez zewnętrznych serwisów i bez
 * skryptu w przeglądarce)
 * z kluczem do przepisania, pole kodu i „Włącz”, potem 10 kodów zapasowych
 * pokazanych raz (Pobierz .txt / Kopiuj / Drukuj). Po włączeniu: nowe kody,
 * zapamiętane urządzenia, wyłączenie (kodem — nie samym zalogowaniem; przy
 * wymuszonej roli wyłączyć się nie da).
 * Cudzy profil (administrator): stan i „Wyłącz 2FA tego konta” — wpis
 * w dzienniku. Lista Użytkownicy: kolumna „2FA”.
 */

/** Sekret oczekujący na potwierdzenie (krok 1) — tworzony raz, aż do włączenia. */
function evk_2fa_oczekujacy(int $id): string {
    $k = evk_2fa_konto($id);
    $s = evk_2fa_odszyfruj($k['oczekujacy']);
    if ($s === '') {
        $s = evk_2fa_nowy_sekret();
        $k['oczekujacy'] = evk_2fa_zaszyfruj($s);
        evk_2fa_zapisz_konto($id, $k);
    }
    return $s;
}

/** Ile kodów zapasowych zostało. */
function evk_2fa_ile_kodow(int $id): int {
    return count(evk_2fa_konto($id)['kody']);
}

/**
 * Kod QR jako SVG — koder z modułu OpenGraph (ten sam, który rysuje warstwę QR
 * obrazka OG; poprawności pilnuje og-layers-qr). Moduły ciemne jednym
 * <path>, strefa ciszy 4 moduły, ostre krawędzie przy każdym powiększeniu.
 */
function evk_2fa_qr_svg(string $dane): string {
    if (!function_exists('evk_qr_koduj')) require_once dirname(__DIR__) . '/opengraph/qr.php';
    $kod = evk_qr_koduj($dane, 'M');
    if (!$kod) return '';
    $n = count($kod['moduly']);
    $d = '';
    foreach ($kod['moduly'] as $y => $wiersz) {
        foreach ($wiersz as $x => $v) if ($v) $d .= 'M' . ($x + 4) . ' ' . ($y + 4) . 'h1v1h-1z';
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . ($n + 8) . ' ' . ($n + 8) . '" shape-rendering="crispEdges" aria-hidden="true" focusable="false">'
        . '<rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' . $d . '"/></svg>';
}

add_action('admin_notices', function (): void {
    global $pagenow;
    if ($pagenow === 'profile.php' && evk_2fa_musi_wlaczyc()) {
        echo '<div class="notice notice-warning"><p><strong>Twoja rola wymaga logowania dwuetapowego.</strong> Włącz je w sekcji „Logowanie dwuetapowe” niżej — do tego czasu reszta kokpitu jest niedostępna.</p></div>';
    }
    if (evk_2fa_awaryjnie() && current_user_can('manage_options')) {
        echo '<div class="notice notice-error"><p><strong>Logowanie dwuetapowe jest WYŁĄCZONE stałą EVK_2FA_WYLACZ w wp-config.php.</strong> Każde konto loguje się samym hasłem. Usuń stałą, gdy tylko odzyskasz dostęp.</p></div>';
    }
});

/** Sekcja w profilu (własnym i cudzym). */
function evk_2fa_render_profil(WP_User $u): void {
    if (!evk_2fa_wlaczone()) return;
    $wlasny = $u->ID === get_current_user_id();
    if (!$wlasny && !current_user_can('manage_options')) return;
    $ma = evk_2fa_ma($u->ID);
    $k = evk_2fa_konto($u->ID);
    $nonce = wp_create_nonce('evk_2fa_' . $u->ID);
    ?>
    <h2 id="evk-2fa">Logowanie dwuetapowe</h2>
    <style>
        #evk-2fa-box { max-width: 760px; }
        #evk-2fa-box .evk-2fa-krok { display: flex; flex-wrap: wrap; gap: 16px 24px; align-items: flex-start; margin: 0 0 18px; }
        #evk-2fa-box .evk-2fa-krok > div { flex: 1 1 280px; min-width: 0; }
        #evk-2fa-box .evk-2fa-qr { flex: 0 0 auto; width: 200px; height: 200px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 4px; box-sizing: border-box; }
        #evk-2fa-box .evk-2fa-qr svg { width: 100%; height: 100%; display: block; }
        /* Łamanie tylko między grupami po 4 znaki (spacje) — „6XA / O F5PP” przepisywało się źle. */
        #evk-2fa-box .evk-2fa-klucz { font: 600 16px/1.6 ui-monospace, Menlo, Consolas, monospace; letter-spacing: .08em; word-break: normal; overflow-wrap: normal; }
        #evk-2fa-box .evk-2fa-kod { font: 600 24px/1.2 ui-monospace, Menlo, Consolas, monospace; letter-spacing: .3em; width: 11ch; max-width: 100%; padding: 6px 10px; }
        #evk-2fa-box .evk-2fa-wiersz { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 6px 0; }
        #evk-2fa-box .evk-2fa-kody { display: grid; grid-template-columns: repeat(2, max-content); gap: 6px 28px; font: 600 16px/1.6 ui-monospace, Menlo, Consolas, monospace; margin: 10px 0; }
        #evk-2fa-box .evk-2fa-zapasowe { background: #fff; border: 1px solid #dcdcde; border-left: 4px solid #dba617; border-radius: 4px; padding: 12px 16px; margin: 0 0 16px; }
        #evk-2fa-box .evk-2fa-stan { margin: 0 0 12px; }
        #evk-2fa-box .evk-2fa-stan strong { color: #1a7f37; }
    </style>
    <div id="evk-2fa-box" data-user="<?php echo (int) $u->ID; ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
    <?php if (!$wlasny): ?>
        <p class="evk-2fa-stan"><?php echo $ma ? '<strong>Włączone</strong> od ' . esc_html(wp_date('j.m.Y', $k['od'])) . ', kodów zapasowych: ' . (int) count($k['kody']) . '.' : 'Wyłączone.'; ?>
            <?php if (!$ma && evk_2fa_wymagane($u)): ?> Rola tego konta wymaga 2FA — zostanie poproszone o włączenie przy następnym logowaniu.<?php endif; ?></p>
        <?php if ($ma): ?>
        <p><button type="button" class="button evk-2fa-akcja" data-akcja="reset">Wyłącz 2FA tego konta</button>
            <span class="description">Na wypadek zgubionego telefonu i kodów. Wpis trafia do dziennika.</span></p>
        <?php endif; ?>
    <?php elseif (!$ma): $sekret = evk_2fa_oczekujacy($u->ID); ?>
        <p>Drugi krok po haśle: 6 cyfr z aplikacji w telefonie. Nawet ktoś, kto zna hasło, bez telefonu się nie zaloguje.</p>
        <div class="evk-2fa-krok">
            <div class="evk-2fa-qr" role="img" aria-label="Kod QR do zeskanowania aplikacją uwierzytelniającą"><?php echo evk_2fa_qr_svg(evk_2fa_adres_otpauth($sekret, $u)); // phpcs:ignore — SVG z liczb ?></div>
            <div>
                <p><strong>1. Zeskanuj kod aplikacją</strong> (Google Authenticator, Microsoft Authenticator, 1Password, Bitwarden…) albo wpisz klucz ręcznie:</p>
                <p class="evk-2fa-wiersz"><span class="evk-2fa-klucz"><?php echo esc_html(trim(chunk_split($sekret, 4, ' '))); ?></span>
                    <button type="button" class="button button-small evk-2fa-kopiuj" data-tekst="<?php echo esc_attr($sekret); ?>">Kopiuj klucz</button></p>
                <p><strong>2. Wpisz 6 cyfr z aplikacji</strong></p>
                <p class="evk-2fa-wiersz">
                    <label class="screen-reader-text" for="evk-2fa-kod">Kod z aplikacji</label>
                    <input type="text" id="evk-2fa-kod" class="evk-2fa-kod" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123456">
                    <button type="button" class="button button-primary evk-2fa-akcja" data-akcja="wlacz">Włącz</button>
                </p>
                <p class="evk-2fa-komunikat" role="status"></p>
            </div>
        </div>
    <?php else: ?>
        <p class="evk-2fa-stan"><strong>Włączone</strong> od <?php echo esc_html(wp_date('j.m.Y', $k['od'])); ?>. Kodów zapasowych zostało: <span class="evk-2fa-ile"><?php echo (int) count($k['kody']); ?></span>.</p>
        <p class="evk-2fa-wiersz"><button type="button" class="button evk-2fa-akcja" data-akcja="kody">Nowe kody zapasowe</button>
            <span class="description">Stare przestaną działać.</span></p>
        <?php $urz = array_values(array_filter($k['urzadzenia'], static function ($x) { return (int) ($x['do'] ?? 0) > time(); })); ?>
        <p><strong>Zapamiętane urządzenia</strong> (logują się bez kodu przez <?php echo (int) EVK_2FA_URZADZENIE_DNI; ?> dni):
            <?php echo $urz ? esc_html(implode(', ', array_map(static function ($x) { return $x['nazwa'] . ' (od ' . wp_date('j.m', (int) $x['od']) . ')'; }, $urz))) : 'brak'; ?></p>
        <?php if ($urz): ?><p><button type="button" class="button evk-2fa-akcja" data-akcja="zapomnij">Zapomnij wszystkie urządzenia</button></p><?php endif; ?>
        <?php if (!evk_2fa_wymagane($u)): ?>
        <p class="evk-2fa-wiersz">
            <label for="evk-2fa-kod-wyl">Kod z aplikacji albo zapasowy</label>
            <input type="text" id="evk-2fa-kod-wyl" class="evk-2fa-kod" inputmode="numeric" autocomplete="one-time-code" maxlength="9">
            <button type="button" class="button evk-2fa-akcja" data-akcja="wylacz">Wyłącz logowanie dwuetapowe</button>
        </p>
        <?php else: ?>
        <p class="description">Twoja rola wymaga logowania dwuetapowego — nie da się go wyłączyć.</p>
        <?php endif; ?>
        <p class="evk-2fa-komunikat" role="status"></p>
    <?php endif; ?>
        <div class="evk-2fa-zapasowe" hidden>
            <p><strong>Kody zapasowe — zapisz je teraz, więcej ich nie zobaczysz.</strong> Każdy działa raz, gdy nie masz telefonu.</p>
            <div class="evk-2fa-kody"></div>
            <p class="evk-2fa-wiersz"><button type="button" class="button evk-2fa-pobierz">Pobierz .txt</button>
                <button type="button" class="button evk-2fa-kopiuj-kody">Kopiuj</button>
                <button type="button" class="button evk-2fa-drukuj">Drukuj</button></p>
            <p class="evk-2fa-wiersz"><label><input type="checkbox" class="evk-2fa-zapisalem"> Zapisałem kody</label>
                <button type="button" class="button button-primary evk-2fa-gotowe" disabled>Gotowe</button></p>
        </div>
    </div>
    <script>
    (function () {
        var box = document.getElementById('evk-2fa-box');
        if (!box) return;
        var kom = box.querySelector('.evk-2fa-komunikat'), kody = [];
        function napisz(t) { if (kom) kom.textContent = t; }
        function kopiuj(t, b) {
            (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () { b.textContent = 'Skopiowano'; }, function () { window.prompt('Skopiuj:', t); });
        }
        box.querySelectorAll('.evk-2fa-kopiuj').forEach(function (b) { b.addEventListener('click', function () { kopiuj(b.getAttribute('data-tekst'), b); }); });
        function pokazKody(lista) {
            kody = lista;
            var z = box.querySelector('.evk-2fa-zapasowe'), s = box.querySelector('.evk-2fa-kody');
            s.textContent = '';
            lista.forEach(function (k) { var e = document.createElement('span'); e.textContent = k; s.appendChild(e); });
            z.hidden = false;
            z.scrollIntoView({ block: 'center' });
        }
        var tekstKodow = function () { return 'Kody zapasowe — ' + location.host + '\n\n' + kody.join('\n') + '\n\nKażdy kod działa raz.\n'; };
        box.querySelector('.evk-2fa-pobierz').addEventListener('click', function () {
            var a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([tekstKodow()], { type: 'text/plain' }));
            a.download = 'kody-zapasowe-' + location.host + '.txt';
            document.body.appendChild(a); a.click(); a.remove();
        });
        box.querySelector('.evk-2fa-kopiuj-kody').addEventListener('click', function (e) { kopiuj(tekstKodow(), e.currentTarget); });
        box.querySelector('.evk-2fa-drukuj').addEventListener('click', function () {
            var w = window.open('', '_blank', 'width=480,height=640');
            if (!w) return;
            var pre = w.document.createElement('pre');
            pre.textContent = tekstKodow();
            pre.style.font = '16px/1.6 monospace';
            w.document.body.appendChild(pre);
            w.print();
        });
        box.querySelector('.evk-2fa-zapisalem').addEventListener('change', function (e) { box.querySelector('.evk-2fa-gotowe').disabled = !e.target.checked; });
        box.querySelector('.evk-2fa-gotowe').addEventListener('click', function () { location.href = location.pathname + (location.search.indexOf('user_id') > -1 ? location.search.replace(/&?evk_2fa=[^&]*/, '') : '') + '#evk-2fa'; });
        box.querySelectorAll('.evk-2fa-akcja').forEach(function (b) {
            b.addEventListener('click', function () {
                var akcja = b.getAttribute('data-akcja'), pole = box.querySelector(akcja === 'wylacz' ? '#evk-2fa-kod-wyl' : '#evk-2fa-kod');
                if (akcja === 'reset' && !window.confirm('Wyłączyć logowanie dwuetapowe tego konta?')) return;
                if (akcja === 'kody' && !window.confirm('Utworzyć nowe kody? Stare przestaną działać.')) return;
                var dane = new FormData();
                dane.append('action', 'evk_2fa');
                dane.append('akcja', akcja);
                dane.append('user', box.getAttribute('data-user'));
                dane.append('nonce', box.getAttribute('data-nonce'));
                if (pole) dane.append('kod', pole.value);
                b.disabled = true;
                napisz('Chwileczkę…');
                fetch(window.ajaxurl, { method: 'POST', body: dane, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
                    b.disabled = false;
                    if (!r || !r.success) { napisz((r && r.data) || 'Błąd.'); if (pole) pole.focus(); return; }
                    napisz(r.data.komunikat || '');
                    if (r.data.kody) pokazKody(r.data.kody); else location.reload();
                }).catch(function () { b.disabled = false; napisz('Błąd połączenia z serwerem.'); });
            });
        });
        var pole = box.querySelector('#evk-2fa-kod');
        if (pole) pole.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); box.querySelector('[data-akcja="wlacz"]').click(); } });
    })();
    </script>
    <?php
}
add_action('show_user_profile', 'evk_2fa_render_profil', 5);
add_action('edit_user_profile', 'evk_2fa_render_profil', 5);

/* Akcje sekcji (AJAX): włącz, nowe kody, zapomnij urządzenia, wyłącz (własne, kodem), reset (administrator, cudze). */
add_action('wp_ajax_evk_2fa', function (): void {
    $id = (int) ($_POST['user'] ?? 0);
    check_ajax_referer('evk_2fa_' . $id, 'nonce');
    if (!evk_2fa_wlaczone()) wp_send_json_error('Logowanie dwuetapowe jest wyłączone.');
    $akcja = sanitize_key($_POST['akcja'] ?? '');
    $kod = (string) wp_unslash($_POST['kod'] ?? '');
    $ja = get_current_user_id();
    if ($akcja === 'reset') {
        if (!current_user_can('manage_options') || $id === $ja || !current_user_can('edit_user', $id)) wp_send_json_error('Brak uprawnień.');
        evk_2fa_wylacz($id);
        evk_2fa_dziennik('reset', $id);
        wp_send_json_success(['komunikat' => 'Wyłączono.']);
    }
    if ($id !== $ja) wp_send_json_error('Brak uprawnień.');
    $k = evk_2fa_konto($id);
    if ($akcja === 'wlacz') {
        $sekret = evk_2fa_odszyfruj($k['oczekujacy']);
        $l = evk_2fa_sprawdz_totp($sekret, $kod, 0);
        if ($l < 0) wp_send_json_error('Kod się nie zgadza. Sprawdź, czy zegar telefonu jest ustawiony automatycznie, i wpisz nowy kod.');
        $k['sekret'] = $k['oczekujacy'];
        $k['oczekujacy'] = '';
        $k['od'] = time();
        $k['licznik'] = $l;
        evk_2fa_zapisz_konto($id, $k);
        evk_2fa_dziennik('wlaczenie', $id);
        wp_send_json_success(['komunikat' => '✓ Zapisano: logowanie dwuetapowe włączone.', 'kody' => evk_2fa_nowe_kody($id)]);
    }
    if (!evk_2fa_ma($id)) wp_send_json_error('Logowanie dwuetapowe nie jest włączone.');
    if ($akcja === 'kody') {
        evk_2fa_dziennik('nowe-kody', $id);
        wp_send_json_success(['komunikat' => '✓ Zapisano: nowe kody zapasowe.', 'kody' => evk_2fa_nowe_kody($id)]);
    }
    if ($akcja === 'zapomnij') {
        evk_2fa_zapomnij_urzadzenia($id);
        wp_send_json_success(['komunikat' => '✓ Zapisano: urządzenia zapomniane.']);
    }
    if ($akcja === 'wylacz') {
        if (evk_2fa_wymagane(wp_get_current_user())) wp_send_json_error('Twoja rola wymaga logowania dwuetapowego.');
        if (evk_2fa_weryfikuj($id, $kod) === '') wp_send_json_error('Kod się nie zgadza.');
        evk_2fa_wylacz($id);
        evk_2fa_dziennik('wylaczenie', $id);
        wp_send_json_success(['komunikat' => '✓ Zapisano: logowanie dwuetapowe wyłączone.']);
    }
    wp_send_json_error('Nieznana akcja.');
});

/* Lista Użytkownicy: kolumna „2FA”. */
add_filter('manage_users_columns', function (array $k): array {
    if (evk_2fa_wlaczone() && current_user_can('manage_options')) $k['evk_2fa'] = '2FA';
    return $k;
});
add_filter('manage_users_custom_column', function ($w, string $kol, int $id) {
    if ($kol !== 'evk_2fa') return $w;
    if (evk_2fa_ma($id)) return '<span aria-hidden="true">✓</span> włączone';
    $u = get_userdata($id);
    return $u && evk_2fa_wymagane($u) ? '<strong>wymagane, brak</strong>' : '—';
}, 10, 3);
