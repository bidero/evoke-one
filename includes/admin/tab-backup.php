<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Tab: Kopie zapasowe
 *
 * Na razie szkielet: włącznik modułu i sprawdzenie środowiska serwera.
 * Tworzenie kopii, harmonogram i przywracanie dochodzą w kolejnych etapach —
 * zakładka mówi o tym wprost, zamiast pokazywać ustawienia, które jeszcze
 * niczego nie robią.
 */

$bk_on     = evk_backup_enabled();
/* `$bk_facts` z zewnątrz podaje wyłącznie sonda testowa (tests/php/
   backup-srodowisko.php) — plik ładuje się przez require i dziedziczy jej
   zmienne. W panelu zmiennej nie ma i fakty czytamy z serwera. */
$bk_checks = evk_backup_environment_checks($bk_facts ?? evk_backup_environment_facts());
$bk_block  = evk_backup_environment_blocked($bk_checks);
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

<div class="evo-info-box evo-mt">
    <span class="dashicons dashicons-hammer"></span>
    <div><strong>Moduł w budowie.</strong> W tej wersji: włącznik i sprawdzenie środowiska serwera.
    Tworzenie kopii, harmonogram nocny, lista kopii i przywracanie pojawią się tutaj w kolejnych wydaniach.</div>
</div>

<!-- ŚRODOWISKO -->
<div class="evo-box evo-mt">
    <h3>Środowisko serwera</h3>
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
</div>
