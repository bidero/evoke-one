<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Tab: Obrazy WebP/AVIF (Frontend)
 *
 * Ustawienia jadą formularzem (Settings API), przełącznik AJAX-em jak wszędzie.
 * Przerabianie biblioteki: krok po kroku z tej karty (`wp_ajax_evk_obrazy`),
 * a po jej zamknięciu dokańcza cron (includes/91-obrazy.php).
 */

$evk_o      = evk_obrazy_ustawienia();
$evk_webp   = evk_obrazy_obslugiwany('webp');
$evk_avif   = evk_obrazy_obslugiwany('avif');
$evk_stan   = evk_obrazy_dla_panelu();
$evk_l      = $evk_stan['liczby'];
$evk_prog   = (int) apply_filters('big_image_size_threshold', 2560, [0, 0], '', 0);
$evk_format = static function (bool $ok, string $fmt): string {
    if (!$ok) return '<strong class="evo-danger-tx">nie</strong>';
    $ed = evk_obrazy_edytor($fmt);
    return '<strong>tak</strong>' . ($ed !== '' ? ' <span class="evo-muted">(' . esc_html($ed) . ')</span>' : '');
};
?>
            <form method="post" action="options.php">
                <?php settings_fields('evoke_one_obrazy'); ?>

                <div class="evo-status-card">
                    <div class="evo-status-icon <?php echo !empty($evk_o['enabled']) ? 'on' : 'off'; ?>">
                        <span class="dashicons dashicons-format-image"></span>
                    </div>
                    <div class="evo-status-text">
                        <h3>Obrazy WebP/AVIF: <?php echo !empty($evk_o['enabled']) ? 'WŁĄCZONE' : 'WYŁĄCZONE'; ?></h3>
                        <p>Przy wgrywaniu obok każdego zdjęcia JPEG i PNG powstają lżejsze wersje WebP i AVIF.
                           Strona podaje je przeglądarkom, które je obsługują — pozostałe dostają oryginał.</p>
                    </div>
                    <div class="evo-status-actions">
                        <span class="evo-toggle-label"><?php echo !empty($evk_o['enabled']) ? 'Włączone' : 'Wyłączone'; ?></span>
                        <label class="evo-toggle">
                            <input aria-label="Obrazy WebP/AVIF" type="checkbox" data-option="evk_obrazy" data-field="enabled" value="1" <?php checked(!empty($evk_o['enabled'])); ?>>
                            <span class="evo-slider"></span>
                        </label>
                    </div>
                </div>

                <div class="evo-box">
                    <h3>Co umie ten serwer</h3>
                    <p class="evo-desc evo-mb-0">
                        WebP: <?php echo $evk_format($evk_webp, 'webp'); ?> ·
                        AVIF: <?php echo $evk_format($evk_avif, 'avif'); ?>
                    </p>
                    <?php if (!$evk_avif): ?>
                    <p class="evo-desc evk-obrazy-bez-avif">
                        Serwer nie zapisze AVIF: potrzebny jest Imagick z obsługą AVIF albo PHP 8.1+ z GD
                        skompilowanym z AVIF (<code>imageavif</code>). Bez tego powstają same wersje WebP —
                        nic się nie psuje, a AVIF zacznie powstawać, gdy hosting go doda.
                    </p>
                    <?php endif; ?>
                    <?php if (!$evk_webp): ?>
                    <p class="evo-desc">
                        Serwer nie zapisze też WebP — moduł nie ma czego tworzyć. Zapytaj hosting o GD albo Imagick z WebP.
                    </p>
                    <?php endif; ?>
                </div>

                <div class="evo-box">
                    <h3>Formaty i jakość</h3>

                    <label class="evo-check">
                        <input type="checkbox" name="evk_obrazy[webp]" value="1" <?php checked(!empty($evk_o['webp'])); ?>>
                        <span>Twórz i podawaj WebP</span>
                    </label>
                    <div class="evo-field">
                        <label for="evo-f-evk_obrazy-jakosc_webp">Jakość WebP (1–100)</label>
                        <input id="evo-f-evk_obrazy-jakosc_webp" type="number" min="1" max="100" step="1" class="evo-w" style="--evo-w:120px"
                               name="evk_obrazy[jakosc_webp]" value="<?php echo esc_attr((string) $evk_o['jakosc_webp']); ?>">
                    </div>

                    <label class="evo-check">
                        <input type="checkbox" name="evk_obrazy[avif]" value="1" <?php checked(!empty($evk_o['avif'])); ?>>
                        <span>Twórz i podawaj AVIF<?php echo $evk_avif ? '' : ' (gdy serwer zacznie go obsługiwać)'; ?></span>
                    </label>
                    <div class="evo-field">
                        <label for="evo-f-evk_obrazy-jakosc_avif">Jakość AVIF (1–100)</label>
                        <input id="evo-f-evk_obrazy-jakosc_avif" type="number" min="1" max="100" step="1" class="evo-w" style="--evo-w:120px"
                               name="evk_obrazy[jakosc_avif]" value="<?php echo esc_attr((string) $evk_o['jakosc_avif']); ?>">
                        <div class="evo-desc">
                            Domyślnie WebP 80, AVIF 60 — AVIF przy tej samej liczbie wygląda lepiej, więc liczby nie są do siebie
                            porównywalne. Zmiana jakości działa na nowe obrazy; istniejące przerobisz niżej („Wszystkie od nowa”).
                        </div>
                    </div>
                </div>

                <div class="evo-box">
                    <h3>Zmniejszanie przy wgrywaniu</h3>
                    <div class="evo-field">
                        <label for="evo-f-evk_obrazy-max_bok">Najdłuższy bok (px), 0 = bez zmniejszania</label>
                        <input id="evo-f-evk_obrazy-max_bok" type="number" min="0" step="1" class="evo-w" style="--evo-w:140px"
                               name="evk_obrazy[max_bok]" value="<?php echo esc_attr((string) $evk_o['max_bok']); ?>">
                        <div class="evo-desc">
                            To próg samego WordPressa (<code>big_image_size_threshold</code>, domyślnie 2560 px), nie drugi mechanizm obok:
                            większe zdjęcie zostaje zmniejszone do pliku „-scaled”, a oryginał zostaje na serwerze obok.
                            EXIF zostaje taki, jak zostawia go WordPress. Teraz obowiązuje:
                            <strong><?php echo $evk_prog > 0 ? esc_html($evk_prog . ' px') : 'bez zmniejszania'; ?></strong>.
                        </div>
                    </div>
                </div>

                <?php evoke_one_pasek_zapisu('Zapisz ustawienia'); ?>
            </form>

            <div class="evo-box evk-obrazy-biblioteka" data-nonce="<?php echo esc_attr(wp_create_nonce('evk_obrazy')); ?>"
                 data-stan="<?php echo esc_attr((string) wp_json_encode($evk_stan)); ?>">
                <h3>Istniejąca biblioteka</h3>
                <p class="evo-desc">
                    Zdjęć JPEG i PNG w bibliotece: <strong class="evk-obrazy-wszystkie"><?php echo (int) $evk_l['wszystkie']; ?></strong>,
                    z wersjami WebP/AVIF: <strong class="evk-obrazy-z-wersjami"><?php echo (int) $evk_l['z_wersjami']; ?></strong>.
                </p>
                <div role="radiogroup" aria-labelledby="evk-obrazy-tryb-tytul">
                    <p id="evk-obrazy-tryb-tytul"><strong>Co przerobić</strong></p>
                    <label class="evo-check-row"><input type="radio" name="evk-obrazy-tryb" value="brakujace" checked> Tylko obrazy bez wersji WebP/AVIF</label>
                    <label class="evo-check-row"><input type="radio" name="evk-obrazy-tryb" value="wszystkie"> Wszystkie od nowa (np. po zmianie jakości)</label>
                </div>
                <p>
                    <button type="button" class="button button-primary evk-obrazy-start"<?php disabled(empty($evk_o['enabled'])); ?>>Przerób bibliotekę</button>
                    <button type="button" class="button evk-obrazy-stop" disabled>Zatrzymaj</button>
                </p>
                <div class="evk-obrazy-postep" hidden>
                    <label for="evk-obrazy-pasek">Postęp przerabiania</label>
                    <progress id="evk-obrazy-pasek" class="evo-w" style="--evo-w:100%" max="100" value="0"></progress>
                </div>
                <p class="evk-obrazy-info" role="status"></p>
                <p class="evo-desc">
                    Przerabianie działa przy włączonym module. Kartę można zamknąć: resztę dokończy WordPress w tle (WP-Cron, przy kolejnych odwiedzinach strony).
                    Po powrocie tutaj pasek pokaże, ile zostało.
                </p>
            </div>

<script>
(function () {
    var AJAX = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    var box = document.querySelector('.evk-obrazy-biblioteka');
    if (!box) return;
    var start = box.querySelector('.evk-obrazy-start');
    var stop = box.querySelector('.evk-obrazy-stop');
    var postep = box.querySelector('.evk-obrazy-postep');
    var pasek = box.querySelector('#evk-obrazy-pasek');
    var info = box.querySelector('.evk-obrazy-info');
    var zatrzymaj = false;
    var pracuje = false;

    function wyslij(dane) {
        var fd = new FormData();
        fd.append('action', 'evk_obrazy');
        fd.append('nonce', box.getAttribute('data-nonce'));
        Object.keys(dane).forEach(function (k) { fd.append(k, dane[k]); });
        return fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    function pokaz(d) {
        var p = d.przebieg, l = d.liczby;
        box.querySelector('.evk-obrazy-wszystkie').textContent = l.wszystkie;
        box.querySelector('.evk-obrazy-z-wersjami').textContent = l.z_wersjami;
        if (p.stan === 'brak') return;
        postep.hidden = false;
        var proc = p.wszystkie > 0 ? Math.min(100, Math.round(p.przejrzane * 100 / p.wszystkie)) : 100;
        if (p.stan === 'gotowe') proc = 100;
        pasek.value = proc;
        var opis = { trwa: 'Przerabiam', gotowe: 'Gotowe', zatrzymany: 'Zatrzymane' }[p.stan] || p.stan;
        var tekst = opis + ': ' + p.przejrzane + ' z ' + p.wszystkie + ' (' + proc + '%) — przerobione ' + p.zrobione
            + ', pominięte (już miały wersje) ' + p.pominiete + ', z błędem ' + p.bledy + '.';
        if (p.kroki_cron > 0) tekst += ' W tle (cron): ' + p.kroki_cron + ' porcji.';
        if (p.ostatnie_bledy && p.ostatnie_bledy.length) tekst += ' Ostatni błąd: ' + p.ostatnie_bledy[p.ostatnie_bledy.length - 1];
        info.textContent = tekst;
    }

    function koniec() {
        pracuje = false;
        start.disabled = false;
        stop.disabled = true;
    }

    function petla() {
        if (zatrzymaj) {
            return wyslij({ co: 'stop' }).then(function (r) { if (r.success) pokaz(r.data); koniec(); });
        }
        return wyslij({ co: 'krok' }).then(function (r) {
            if (!r.success) { info.textContent = r.data || 'Błąd.'; koniec(); return; }
            pokaz(r.data);
            if (r.data.przebieg.stan === 'trwa') return petla();
            koniec();
        }).catch(function () { info.textContent = 'Błąd połączenia — przebieg dokończy cron w tle.'; koniec(); });
    }

    function ruszaj() {
        pracuje = true;
        zatrzymaj = false;
        start.disabled = true;
        stop.disabled = false;
        return petla();
    }

    start.addEventListener('click', function () {
        if (pracuje) return;
        var tryb = box.querySelector('input[name="evk-obrazy-tryb"]:checked');
        start.disabled = true;
        wyslij({ co: 'start', tryb: tryb ? tryb.value : 'brakujace' }).then(function (r) {
            if (!r.success) { info.textContent = r.data || 'Błąd.'; start.disabled = false; return; }
            pokaz(r.data);
            ruszaj();
        }).catch(function () { info.textContent = 'Błąd połączenia.'; start.disabled = false; });
    });
    stop.addEventListener('click', function () {
        zatrzymaj = true;
        stop.disabled = true;
        info.textContent = 'Zatrzymuję po bieżącej porcji…';
    });

    /* Przełącznik u góry jedzie AJAX-em, bez przeładowania — przycisk idzie za nim. */
    var wlacznik = document.querySelector('input[data-option="evk_obrazy"][data-field="enabled"]');
    if (wlacznik) wlacznik.addEventListener('change', function () { if (!pracuje) start.disabled = !wlacznik.checked; });

    /* Przebieg zaczęty wcześniej (karta zamknięta, cron w trakcie): pokaż stan
       i pracuj dalej z tej karty — szybciej niż cron przy odwiedzinach. */
    var stan = JSON.parse(box.getAttribute('data-stan') || '{}');
    if (stan.przebieg) {
        pokaz(stan);
        if (stan.przebieg.stan === 'trwa' && !start.disabled) ruszaj();
    }
})();
</script>
