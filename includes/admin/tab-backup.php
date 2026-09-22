<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Tab: Kopie zapasowe
 *
 * Kopia ręczna z paskiem postępu, lista kopii (pobierz / przypnij / usuń),
 * ustawienia, które w tej wersji naprawdę działają (retencja, wykluczenia,
 * tryb konserwacji na czas zrzutu), i sprawdzenie środowiska. Harmonogram
 * nocny i przywracanie dochodzą w kolejnych wydaniach — zakładka mówi to
 * wprost, zamiast pokazywać ustawienia, które niczego nie robią.
 */

$bk_on     = evk_backup_enabled();
/* `$bk_facts` z zewnątrz podaje wyłącznie sonda testowa (tests/php/
   backup-srodowisko.php) — plik ładuje się przez require i dziedziczy jej
   zmienne. W panelu zmiennej nie ma i fakty czytamy z serwera. */
$bk_checks = evk_backup_environment_checks($bk_facts ?? evk_backup_environment_facts());
$bk_block  = evk_backup_environment_blocked($bk_checks);
$bk_silnik = $bk_on && function_exists('evk_backup_render_list');
$bk_s      = evk_backup_get_settings();
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
            <input type="checkbox"
                   data-option="evk_backup"
                   data-field="enabled"
                   value="1"
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
    <div>Moduł właśnie włączony — <a href="">odśwież stronę</a>, żeby pojawił się przycisk kopii i lista.</div>
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
    <div class="evo-info-box evo-mt" data-evk-backup-msg hidden><span class="dashicons dashicons-info-outline"></span><div></div></div>
</div>

<!-- LISTA KOPII -->
<div class="evo-box evo-mt">
    <h3>Kopie na serwerze</h3>
    <p class="evo-muted evo-mb">Katalog: <code><?php echo esc_html('wp-content/' . basename(evk_backup_dir())); ?></code>.
    Przypięte kopie nie są usuwane przez retencję. Przywracanie — w kolejnym wydaniu.</p>
    <div data-evk-backup-list><?php echo evk_backup_render_list(); // phpcs:ignore WordPress.Security.EscapeOutput -- zbudowane z esc_* ?></div>
</div>

<!-- USTAWIENIA -->
<form method="post" action="options.php" class="evo-box evo-mt">
    <?php settings_fields('evoke_one_backup'); ?>
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
    <div class="evo-field">
        <label for="evk-backup-wykluczenia">Wykluczenia (względem wp-content, jedno na linię)</label>
        <textarea id="evk-backup-wykluczenia" name="evk_backup[exclusions]" rows="8" class="large-text code"><?php echo esc_textarea((string) $bk_s['exclusions']); ?></textarea>
        <div class="evo-desc"><code>cache/</code> to wyłącznie <code>wp-content/cache</code> (katalog o tej nazwie w środku wtyczki zostaje — bywa w nim kod).
        Wzorzec bez ukośnika, np. <code>*.log</code>, łapie nazwę w każdym miejscu. Katalogi kopii tej wtyczki są wykluczone zawsze.</div>
    </div>
    <?php evoke_one_pasek_zapisu(); ?>
</form>
<?php else: ?>
<div class="evo-info-box evo-mt">
    <span class="dashicons dashicons-info-outline"></span>
    <div>Włącz moduł powyżej — pojawi się przycisk kopii, lista kopii i ustawienia. W tej wersji: kopie ręczne z pobieraniem.
    Harmonogram nocny i przywracanie dochodzą w kolejnych wydaniach.</div>
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
