<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Narzędzia: Rewizje
 *
 * NAJPIERW LICZBY, POTEM PRZYCISK. Ekran otwiera się przeglądem tego, co jest
 * w bazie; „Skasuj" pojawia się dopiero po policzeniu, ile konkretnie zniknie.
 * Kasowania rewizji nie da się cofnąć, więc jego skutek ma być znany wcześniej.
 */

$rew      = evk_rewizje_ustawienia();
$przeglad = evk_rewizje_przeglad();
$razem    = array_sum(array_column($przeglad, 'ile'));
$bajtow   = array_sum(array_column($przeglad, 'bajtow'));
?>
<form method="post" action="options.php">
    <?php settings_fields('evoke_one_rewizje'); ?>

    <div class="evo-status-card evo-mb">
        <div class="evo-status-icon <?php echo $razem ? 'on' : 'off'; ?>">
            <span class="dashicons dashicons-backup"></span>
        </div>
        <div class="evo-status-text">
            <h3>Rewizje w bazie: <?php echo number_format_i18n($razem); ?></h3>
            <p><?php echo $razem
                ? 'Zajmują ' . size_format($bajtow) . ' samej treści, przy '
                  . number_format_i18n(array_sum(array_column($przeglad, 'wpisow'))) . ' wpisach.'
                : 'Nie ma czego sprzątać.'; ?></p>
        </div>
    </div>

    <?php if (!$przeglad): ?>
    <div class="evo-empty"><p>Baza nie ma ani jednej rewizji.</p></div>
    <?php else: ?>

    <div class="evo-box evo-rewizje" data-nonce="<?php echo esc_attr(wp_create_nonce('evk_rewizje_nonce')); ?>">
        <h3>Co jest w bazie</h3>
        <p class="evo-desc">
            Zaznacz typy, które mają zostać posprzątane. Liczba obok mówi, ile rewizji
            trzyma dziś każdy z nich.
        </p>

        <div class="evo-tbl-wrap">
        <table class="evo-tbl evo-rewizje-tbl">
            <thead><tr>
                <th>Sprzątać</th><th>Typ wpisu</th><th>Rewizji</th><th>Wpisów</th><th>Treści</th>
            </tr></thead>
            <tbody>
            <?php foreach ($przeglad as $w): ?>
            <tr data-typ="<?php echo esc_attr($w['typ']); ?>">
                <td>
                    <label class="evo-check">
                        <input type="checkbox" class="evk-rewizje-typ" value="<?php echo esc_attr($w['typ']); ?>" checked>
                        <span class="screen-reader-text"><?php echo esc_html($w['etykieta']); ?></span>
                    </label>
                </td>
                <td><?php echo esc_html($w['etykieta']); ?>
                    <?php if ($w['typ'] === EVK_REWIZJE_SIEROTY): ?>
                    <?php /* Sieroty rządzą się inaczej i ekran ma to powiedzieć,
                             zanim ktoś policzy i zdziwi się liczbą. */ ?>
                    <span class="evo-badge evo-badge-alarm">kasowane w całości</span>
                    <?php endif; ?>
                </td>
                <td class="evo-hint evk-rewizje-ile" data-etykieta="Rewizji"><?php echo number_format_i18n($w['ile']); ?></td>
                <td class="evo-hint" data-etykieta="Wpisów"><?php echo number_format_i18n($w['wpisow']); ?></td>
                <td class="evo-hint" data-etykieta="Treści"><?php echo size_format($w['bajtow']); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <div class="evo-rewizje-akcje evo-mt-lg">
            <label for="evk-rewizje-zostaw">Zostaw najnowszych wersji przy każdym wpisie</label>
            <input type="number" id="evk-rewizje-zostaw" value="10" min="0" step="1">
            <button type="button" class="button evk-rewizje-policz">Policz, ile zniknie</button>
        </div>

        <?php /* Podsumowanie i przycisk kasowania dokłada skrypt — dopiero po
                 policzeniu. Przycisk narysowany od razu byłby przyciskiem
                 „skasuj nie wiadomo ile". */ ?>
        <div class="evo-rewizje-podsumowanie evo-mt-lg" hidden></div>
    </div>

    <?php endif; ?>

    <div class="evo-box">
        <h3>Stały limit</h3>
        <p class="evo-desc">
            Sprzątanie wyżej jest jednorazowe — rewizje odrastają przy każdej edycji.
            Ten limit każe WordPressowi kasować najstarsze samemu, przy zapisie wpisu.
            Dotyczy <strong>całej witryny</strong>, nie tylko Evoke ONE, więc domyślnie
            jest wyłączony.
        </p>

        <div class="evo-row-between evo-mb">
            <label class="evo-check evo-m0">
                <input type="checkbox" name="evk_rewizje[limit_on]"
                       data-option="evk_rewizje" data-field="limit_on"
                       value="1" <?php checked(1, (int) $rew['limit_on']); ?>>
                <span class="evo-strong-500">Trzymaj najwyżej tyle rewizji na wpis</span>
            </label>
        </div>

        <div class="evo-field">
            <label for="evk-rewizje-limit">Liczba rewizji</label>
            <input type="number" id="evk-rewizje-limit" name="evk_rewizje[limit]"
                   value="<?php echo (int) $rew['limit']; ?>" min="0" step="1">
            <div class="evo-desc">
                Zero znaczy „nie trzymaj żadnej" — WordPress zapisze rewizję i skasuje ją
                przy następnym zapisie. Autozapisy zostają niezależnie od tej liczby.
            </div>
        </div>
    </div>

    <div class="evo-save-bar">
        <?php submit_button('Zapisz ustawienia', 'primary', 'submit', false); ?>
    </div>
</form>
