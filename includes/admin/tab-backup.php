<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Tab: Kopie zapasowe
 *
 * Kopia ręczna z paskiem postępu, lista kopii (pobierz / przypnij /
 * przywróć / usuń / wyślij na Dysk), wgrywanie z komputera i przez FTP,
 * Dysk Google, kopia nocna i powiadomienia, retencja, wykluczenia, tryb
 * konserwacji na czas zrzutu i sprawdzenie środowiska.
 */

$bk_on     = evk_backup_enabled();
/* `$bk_facts` z zewnątrz podaje wyłącznie sonda testowa (tests/php/
   backup-srodowisko.php) — plik ładuje się przez require i dziedziczy jej
   zmienne. W panelu zmiennej nie ma i fakty czytamy z serwera. */
$bk_checks = evk_backup_environment_checks($bk_facts ?? evk_backup_environment_facts());
$bk_block  = evk_backup_environment_blocked($bk_checks);
$bk_silnik = $bk_on && function_exists('evk_backup_render_list');
$bk_s      = evk_backup_get_settings();
// Kopie wgrane przez FTP — przeniesione z katalogu o stałej nazwie przy otwarciu zakładki.
$bk_wgrane = $bk_silnik && !$bk_block ? evk_backup_import_scan() : [];
if ($bk_silnik && !$bk_block) evk_backup_upload_cleanup();
$bk_ikony  = [
    'ok'   => 'dashicons-yes-alt',
    'warn' => 'dashicons-warning',
    'err'  => 'dashicons-dismiss',
    'info' => 'dashicons-info-outline',
];
?>

<!-- STATUS CARD -->
<div class="evo-status-card">
    <div class="evo-status-icon <?php echo $bk_on ? 'on' : 'off'; ?>">
        <span class="dashicons dashicons-backup evo-ico-lg"></span>
    </div>
    <div class="evo-status-text">
        <h3>Kopie zapasowe: <?php echo $bk_on ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?></h3>
        <p>Pełna kopia strony — baza i wp-content — z przywracaniem jednym kliknięciem, także na nowym serwerze.</p>
    </div>
    <div class="evo-status-actions">
        <span class="evo-toggle-label"><?php echo $bk_on ? 'Włączony' : 'Wyłączony'; ?></span>
        <label class="evo-toggle">
            <?php /* Przy błędzie środowiska włącznik jest nieaktywny — ale tylko
                     do WŁĄCZENIA. Moduł już włączony trzeba móc wyłączyć. */ ?>
            <input aria-label="Kopie zapasowe" type="checkbox"
                   data-option="evk_backup"
                   data-field="enabled"
                   value="1"
                   data-przeladuj="1"
                   <?php checked($bk_on); ?>
                   <?php disabled($bk_block && !$bk_on); ?>>
            <span class="evo-slider"></span>
        </label>
    </div>
</div>

<?php if ($bk_block): ?>
<div class="evo-info-box is-err evo-mt">
    <span class="dashicons dashicons-dismiss"></span>
    <div><strong>Ten serwer nie spełnia wymagań modułu.</strong> Szczegóły w tabeli niżej — wiersze oznaczone krzyżykiem.
    Moduł nie włączy się, dopóki tego nie naprawisz, bo kopia i tak by nie powstała, a dowiedziałbyś się o tym dopiero przy próbie przywrócenia.</div>
</div>
<?php endif; ?>

<?php if ($bk_on && !$bk_silnik): ?>
<div class="evo-info-box evo-mt">
    <span class="dashicons dashicons-update"></span>
    <div>Moduł właśnie włączony — <a href="">odśwież stronę</a>, jeśli przycisk kopii i lista nie pojawiły się same.</div>
</div>
<?php endif; ?>

<?php if ($bk_silnik && !$bk_block && trim((string) $bk_s['notify_address']) === ''): ?>
<div class="evo-info-box is-warn evo-mt" data-evk-backup-bez-maila>
    <span class="dashicons dashicons-email-alt"></span>
    <div><strong>Nie ma adresu do powiadomień.</strong> Nieudana kopia nocna nie wyśle maila —
    <a href="#evk-backup-adresy">wpisz adres w ustawieniach niżej</a>.</div>
</div>
<?php endif; ?>

<?php if ($bk_silnik && !$bk_block): ?>
<!-- KOPIA TERAZ -->
<div class="evo-box evo-mt" id="evk-backup-teraz">
    <h3>Kopia teraz</h3>
    <p class="evo-muted evo-mb">Baza i cały wp-content w jednym archiwum ZIP. Kopia idzie krokami w tle — możesz zamknąć tę kartę, praca się nie przerwie.</p>
    <div class="evo-inline" style="--evo-gap:10px">
        <button type="button" class="button button-primary" data-evk-backup-start>
            <span class="dashicons dashicons-backup evo-ico"></span> Utwórz kopię teraz
        </button>
        <button type="button" class="button" data-evk-backup-cancel hidden>Anuluj</button>
    </div>
    <div class="evk-backup-postep evo-mt" data-evk-backup-progress hidden>
        <div class="evk-backup-etap"><strong data-evk-backup-label>—</strong> <span class="evo-muted" data-evk-backup-step></span></div>
        <div class="evk-backup-pasek" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-evk-backup-bar><span></span></div>
        <p class="evo-muted evk-backup-procent" data-evk-backup-percent></p>
        <details class="evo-mt-xs"><summary>Dziennik</summary><pre class="evk-backup-log" data-evk-backup-log></pre></details>
    </div>
    <?php /* Miejsce na komunikat — ramkę tworzy backup.js dopiero z treścią (1.228.1). */ ?>
    <div data-evk-backup-msg-slot></div>
</div>

<!-- LISTA KOPII -->
<div class="evo-box evo-mt">
    <h3>Kopie na serwerze</h3>
    <p class="evo-muted evo-mb">Katalog: <code><?php echo esc_html('wp-content/' . basename(evk_backup_dir())); ?></code>.
    Przypięte kopie nie są usuwane przez retencję.</p>
    <?php if ($bk_wgrane): ?>
    <div class="evo-info-box is-ok evo-mb"><span class="dashicons dashicons-yes-alt"></span>
        <div>Przeniesione z katalogu FTP: <?php echo esc_html(implode(', ', $bk_wgrane)); ?>.</div></div>
    <?php endif; ?>
    <div data-evk-backup-list><?php echo evk_backup_render_list(); // phpcs:ignore WordPress.Security.EscapeOutput -- zbudowane z esc_* ?></div>

    <?php /* Wgrywanie z przeglądarki (upload.php) — kawałkami, wznawialne. */ ?>
    <div class="evk-backup-wgraj evo-mt" data-evk-upload>
        <input type="file" accept=".zip,application/zip" data-evk-upload-file hidden aria-label="Plik kopii (.zip)">
        <button type="button" class="button" data-evk-upload-pick>
            <span class="dashicons dashicons-upload evo-ico" aria-hidden="true"></span> Wgraj kopię z komputera
        </button>
        <div class="evk-backup-wgraj-postep" data-evk-upload-progress hidden>
            <div class="evk-backup-etap"><strong data-evk-upload-name></strong> <span class="evo-muted" data-evk-upload-detail></span></div>
            <div class="evk-backup-pasek" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-evk-upload-bar><span></span></div>
            <button type="button" class="button button-small" data-evk-upload-cancel>Anuluj wgrywanie</button>
        </div>
        <div data-evk-upload-msg-slot></div>
    </div>
    <div class="evk-backup-ftp evo-mt">
        <p class="evo-muted">Kopię z innego serwera wgraj przez FTP do
        <code>wp-content/<?php echo esc_html(basename(evk_backup_import_dir())); ?>/</code> i sprawdź katalog.
        Plik zmieniony w ostatniej minucie czeka — może się jeszcze wgrywać.</p>
        <button type="button" class="button" data-evk-backup-ftp>
            <span class="dashicons dashicons-update evo-ico" aria-hidden="true"></span> Sprawdź katalog FTP
        </button>
        <p class="evk-backup-ftp-wynik" data-evk-backup-ftp-result aria-live="polite"></p>
    </div>
</div>

<!-- DYSK GOOGLE (gdrive.php) -->
<?php $bk_dysk = evk_gdrive_state(); $bk_dysk_msg = evk_gdrive_flash_take(); ?>
<div class="evo-box evo-mt evk-gdrive" id="evk-gdrive" data-evk-gdrive data-connected="<?php echo !empty($bk_dysk['refresh']) ? '1' : '0'; ?>">
    <h3>Dysk Google</h3>
    <?php if ($bk_dysk_msg): ?>
    <div class="evo-info-box is-<?php echo esc_attr($bk_dysk_msg['kind'] === 'ok' ? 'ok' : 'err'); ?> evo-mb" data-evk-gdrive-msg role="status">
        <span class="dashicons <?php echo esc_attr($bk_dysk_msg['kind'] === 'ok' ? 'dashicons-yes-alt' : 'dashicons-dismiss'); ?>" aria-hidden="true"></span>
        <div><?php echo esc_html((string) $bk_dysk_msg['text']); ?></div>
    </div>
    <?php endif; ?>
    <?php if (empty($bk_dysk['refresh'])): ?>
    <p class="evo-muted evo-mb">Kopia poza serwerem — na wypadek awarii całego hostingu. Po połączeniu kopie nocne trafiają na Dysk same,
    a pozostałe wyślesz przyciskiem z listy. Wtyczka widzi na Dysku wyłącznie pliki, które sama utworzyła.</p>
    <a class="button button-primary" data-evk-gdrive-connect
       href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=evk_backup_gdrive_connect'), 'evk_backup_gdrive_connect')); ?>">
        <span class="dashicons dashicons-cloud evo-ico" aria-hidden="true"></span> Połącz z Dyskiem Google</a>
    <?php else: ?>
    <div class="evk-gdrive-konto">
        <p class="evk-gdrive-kto">Połączono<?php echo !empty($bk_dysk['email']) ? ': <strong data-evk-gdrive-email>' . esc_html((string) $bk_dysk['email']) . '</strong>' : ''; ?>.
        Folder: <code>Evoke ONE — <?php echo esc_html(evk_gdrive_site_key()); ?></code>.
        <span class="evo-muted" data-evk-gdrive-quota></span></p>
        <button type="button" class="button" data-evk-gdrive-disconnect>Rozłącz</button>
    </div>
    <p class="evo-muted evo-mt-xs"><?php echo !empty($bk_s['gdrive_auto_schedule']) ? 'Kopie nocne trafiają na Dysk same.' : 'Wysyłka kopii nocnych na Dysk wyłączona w ustawieniach.'; ?>
    Kopie starsze niż <?php echo (int) $bk_s['gdrive_retention_days']; ?> dni znikają z Dysku (przypięte zostają).</p>
    <h4 class="evk-backup-podtytul">Kopie na Dysku</h4>
    <div data-evk-gdrive-list aria-live="polite"><p class="evo-muted">Czytam listę z Dysku…</p></div>
    <?php endif; ?>
</div>

<!-- PRZYWRACANIE: okno potwierdzenia (wypełnia backup.js danymi z manifestu kopii) -->
<dialog class="evk-backup-dialog" data-evk-restore-dialog aria-labelledby="evk-restore-tytul">
    <h3 id="evk-restore-tytul">Przywróć kopię</h3>
    <div class="evk-restore-info" data-evk-restore-info aria-live="polite"><p class="evo-muted">Czytam kopię…</p></div>
    <fieldset class="evk-restore-zakres" data-evk-restore-scope>
        <legend>Co przywrócić</legend>
        <label><input type="radio" name="evk-restore-scope" value="all" checked> Całość — baza i pliki</label>
        <label><input type="radio" name="evk-restore-scope" value="db"> Tylko baza <span class="evo-muted">(treści, ustawienia, użytkownicy — bez wtyczek i motywów)</span></label>
        <label><input type="radio" name="evk-restore-scope" value="files"> Tylko pliki <span class="evo-muted">(wp-content — np. po włamaniu; bez cofania zamówień)</span></label>
    </fieldset>
    <label class="evk-restore-opcja"><input type="checkbox" data-evk-restore-mirror>
        Usuń pliki, których nie ma w kopii <span class="evo-muted">— wp-content wygląda potem dokładnie jak w kopii. Wykluczenia kopii (cache, logi) i ta wtyczka zostają.</span></label>
    <label class="evk-restore-opcja"><input type="checkbox" data-evk-restore-snapshot>
        Najpierw zrób kopię obecnego stanu <span class="evo-muted">— droga powrotu, jeśli przywrócona wersja okaże się zła. Wydłuża przywracanie.</span></label>
    <div class="evo-info-box is-warn evk-restore-uwaga"><span class="dashicons dashicons-warning"></span>
        <div data-evk-restore-warning>Przywrócenie bazy podmienia użytkowników i hasła — po zakończeniu zaloguj się kontem ze strony z kopii. Na czas podmiany strona jest w trybie konserwacji.</div></div>
    <label class="evk-restore-potwierdz">Wpisz <strong>PRZYWRÓĆ</strong>, żeby potwierdzić
        <input type="text" data-evk-restore-confirm autocomplete="off" spellcheck="false"></label>
    <div class="evk-restore-przyciski">
        <button type="button" class="button" data-evk-restore-close>Anuluj</button>
        <button type="button" class="button button-primary" data-evk-restore-go disabled>Przywróć</button>
    </div>
</dialog>

<!-- USTAWIENIA -->
<form method="post" action="options.php" class="evo-box evo-mt">
    <?php settings_fields('evoke_one_backup'); ?>
    <?php /* Znacznik: tylko w zapisie z formularza brak checkboxa = odznaczony (settings.php). */ ?>
    <input type="hidden" name="evk_backup[_formularz]" value="1">
    <?php /* 'enabled' idzie przełącznikiem AJAX — sanitizer zachowuje go przy zapisie formularza. */ ?>
    <h3>Ustawienia</h3>
    <div class="evo-grid evo-mb" style="--evo-col:240px">
        <div class="evo-field">
            <label for="evk-backup-retencja">Ile kopii trzymać</label>
            <input type="number" id="evk-backup-retencja" name="evk_backup[retention_count]" min="1" max="100" class="evo-w-xs"
                   value="<?php echo esc_attr((string) $bk_s['retention_count']); ?>">
            <div class="evo-desc">Po każdej udanej kopii najstarsze ponad ten limit są usuwane. Nie liczą się przypięte, sprzed przywrócenia i wgrane.</div>
        </div>
        <div class="evo-field">
            <label class="checkbox-label">
                <input type="checkbox" name="evk_backup[maintenance_db]" value="1" <?php checked(!empty($bk_s['maintenance_db'])); ?>>
                Tryb konserwacji na czas zrzutu bazy
            </label>
            <div class="evo-desc">Zrzut w kilku krokach nie jest migawką — zamówienie złożone w trakcie może trafić do kopii częściowo. Przy sklepie warto włączyć; strona zasłania się na czas zrzutu bazy (zwykle sekundy).</div>
        </div>
    </div>
    <h4 class="evk-backup-podtytul">Kopia nocna</h4>
    <div class="evo-grid evo-mb" style="--evo-col:240px">
        <div class="evo-field">
            <label class="checkbox-label">
                <input type="checkbox" name="evk_backup[schedule_enabled]" value="1" <?php checked(!empty($bk_s['schedule_enabled'])); ?>>
                Kopia codziennie o godzinie
            </label>
            <input type="time" name="evk_backup[schedule_time]" class="evo-w-xs" aria-label="Godzina kopii nocnej"
                   value="<?php echo esc_attr((string) $bk_s['schedule_time']); ?>">
            <div class="evo-desc">Czas strony (<?php echo esc_html(wp_timezone_string()); ?>).
            <?php $bk_plan = get_option('evk_backup_sched'); if (!empty($bk_s['schedule_enabled']) && is_array($bk_plan) && !empty($bk_plan['at'])): ?>
                Następna: <strong data-evk-backup-next><?php echo esc_html(wp_date('Y-m-d H:i', (int) $bk_plan['at'])); ?></strong>.
            <?php endif; ?>
            Jeśli nikt nie odwiedzi strony o tej porze, kopia ruszy przy pierwszej wizycie.</div>
        </div>
        <div class="evo-field">
            <label for="evk-backup-adresy">E-mail o nieudanej kopii</label>
            <input type="text" id="evk-backup-adresy" name="evk_backup[notify_address]" class="regular-text"
                   placeholder="adres@domena.pl" value="<?php echo esc_attr((string) $bk_s['notify_address']); ?>">
            <div class="evo-desc">Kilka adresów po przecinku. Puste pole — bez maili.</div>
            <label class="checkbox-label evo-mt-xs">
                <input type="checkbox" name="evk_backup[notify_notice]" value="1" <?php checked(!empty($bk_s['notify_notice'])); ?>>
                Komunikat w panelu WordPressa o nieudanej kopii
            </label>
            <div class="evo-desc">Znika po następnej udanej kopii albo po zamknięciu.</div>
        </div>
    </div>
    <h4 class="evk-backup-podtytul">Dysk Google</h4>
    <div class="evo-grid evo-mb" style="--evo-col:240px">
        <div class="evo-field">
            <label class="checkbox-label">
                <input type="checkbox" name="evk_backup[gdrive_auto_schedule]" value="1" <?php checked(!empty($bk_s['gdrive_auto_schedule'])); ?>>
                Wysyłaj kopie nocne na Dysk
            </label>
            <div class="evo-desc">Po każdej udanej kopii nocnej, gdy Dysk jest połączony. Nieudana wysyłka nie rusza kopii na serwerze — idzie powiadomienie.</div>
        </div>
        <div class="evo-field">
            <label for="evk-gdrive-retencja">Ile dni trzymać kopie na Dysku</label>
            <input type="number" id="evk-gdrive-retencja" name="evk_backup[gdrive_retention_days]" min="1" max="365" class="evo-w-xs"
                   value="<?php echo esc_attr((string) $bk_s['gdrive_retention_days']); ?>">
            <div class="evo-desc">Starsze znikają z Dysku po następnej wysyłce. Przypięte zostają — przypięcie na liście działa też na Dysku.</div>
        </div>
    </div>
    <details class="evo-acc evo-mb evk-backup-cron">
        <summary>Cron systemowy <span class="evo-acc-count"><?php echo defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'WP-Cron wyłączony' : 'zalecany przy małym ruchu'; ?></span></summary>
        <div class="evo-acc-body">
            <p class="evo-muted">WP-Cron uruchamia zadania przy odwiedzinach strony. Przy małym ruchu kopia nocna ruszy z opóźnieniem
            — dostaniesz wtedy powiadomienie. Pewniej jest dodać w panelu hostingu zadanie cron co 15 minut:</p>
            <pre class="evk-backup-kod" data-evk-backup-cron-cmd>*/15 * * * * wget -q -O - "<?php echo esc_html(site_url('wp-cron.php?doing_wp_cron')); ?>" &gt;/dev/null 2&gt;&amp;1</pre>
            <p class="evo-muted">Po jego dodaniu można wyłączyć uruchamianie przy odwiedzinach — w <code>wp-config.php</code>:
            <code>define('DISABLE_WP_CRON', true);</code><?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON): ?>
            <strong>Na tej stronie jest już wyłączone</strong> — bez crona systemowego kopia nocna nie ruszy wcale.<?php endif; ?></p>
        </div>
    </details>

    <h4 class="evk-backup-podtytul">Co wchodzi do kopii</h4>
    <div class="evo-field">
        <label for="evk-backup-wykluczenia">Wykluczenia (względem wp-content, jedno na linię)</label>
        <textarea id="evk-backup-wykluczenia" name="evk_backup[exclusions]" rows="8" class="large-text code"><?php echo esc_textarea((string) $bk_s['exclusions']); ?></textarea>
        <button type="button" class="button button-small evo-mt-xs" data-evk-backup-domyslne
                data-wartosc="<?php echo esc_attr(implode("\n", evk_backup_default_exclusions())); ?>">Przywróć domyślne wykluczenia</button>
        <div class="evo-desc"><code>cache/</code> to wyłącznie <code>wp-content/cache</code> (katalog o tej nazwie w środku wtyczki zostaje — bywa w nim kod).
        Wzorzec bez ukośnika, np. <code>*.log</code>, łapie nazwę w każdym miejscu. Katalogi kopii tej wtyczki są wykluczone zawsze.</div>
    </div>
    <?php evoke_one_pasek_zapisu(); ?>
</form>
<?php else: ?>
<div class="evo-info-box evo-mt">
    <span class="dashicons dashicons-info-outline"></span>
    <div>Włącz moduł powyżej — pojawi się przycisk kopii, lista kopii z przywracaniem, kopia nocna i ustawienia.</div>
</div>
<?php endif; ?>

<!-- ŚRODOWISKO — zwinięte; otwarte, gdy coś blokuje moduł -->
<?php
$bk_uwag = count(array_filter($bk_checks, static function ($c) { return in_array($c['status'], ['warn', 'err'], true); }));
$bk_podsum = $bk_block ? 'blokuje moduł' : ($bk_uwag ? $bk_uwag . ' ' . ($bk_uwag === 1 ? 'uwaga' : ($bk_uwag < 5 ? 'uwagi' : 'uwag')) : 'wszystko w porządku');
?>
<details class="evo-acc evo-mt evk-backup-srodowisko"<?php echo $bk_block ? ' open' : ''; ?>>
    <summary>Środowisko serwera <span class="evo-acc-count"><?php echo esc_html($bk_podsum); ?></span></summary>
    <div class="evo-acc-body">
    <p class="evo-muted evo-mb">Odczytane z tego serwera przy otwarciu zakładki. Wiersze z krzyżykiem blokują moduł, z trójkątem — warto o nich wiedzieć.</p>
    <div class="evo-tbl-wrap"><table class="evo-table evo-srodowisko">
        <thead>
            <tr><th>Sprawdzenie</th><th>Wartość</th><th>Uwagi</th></tr>
        </thead>
        <tbody>
            <?php foreach ($bk_checks as $c): ?>
            <tr class="is-<?php echo esc_attr($c['status']); ?>">
                <td><span class="dashicons <?php echo esc_attr($bk_ikony[$c['status']] ?? 'dashicons-info-outline'); ?> evo-srodowisko-ico"></span> <?php echo esc_html($c['label']); ?></td>
                <td><strong><?php echo esc_html($c['value']); ?></strong></td>
                <td class="evo-muted"><?php echo esc_html($c['note']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if ($bk_silnik): ?>
    <div class="evk-backup-tlo evo-mt" data-evk-backup-probe>
        <h4>Praca w tle</h4>
        <p class="evo-muted">Kopia robi się w tle: serwer sam zleca sobie kolejne kroki. Niektóre serwery
        (np. LiteSpeed bez reguły <code>noabort</code>) przerywają taką pracę po chwili. Test trwa 30&nbsp;sekund
        i pokazuje, jak jest na tym serwerze.</p>
        <button type="button" class="button" data-evk-backup-probe-start>Sprawdź pracę w tle</button>
        <p class="evk-backup-tlo-wynik" data-evk-backup-probe-result aria-live="polite"></p>
    </div>
    <?php endif; ?>
    </div>
</details>
