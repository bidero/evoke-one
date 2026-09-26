<?php
/**
 * Evoke ONE — odinstalowanie (usunięcie wtyczki z listy wtyczek).
 *
 * DOMYŚLNIE NIE KASUJE NICZEGO. Dane znikają tylko wtedy, gdy na stronie
 * włączono „Usuń dane przy odinstalowaniu" (Narzędzia → Eksport / Import,
 * opcja `evk_usun_dane`). Kto usuwa wtyczkę, żeby wgrać ją od nowa, nie może
 * przy okazji stracić ustawień, subskrybentów i kopii.
 *
 * Z włączoną opcją znika (decyzja zgłaszającego, 1.232.0): ustawienia i logi,
 * newsletter (listy, subskrybenci, kampanie, statystyki), snippety
 * i przekierowania, kopie zapasowe z serwera (te na Dysku Google zostają),
 * role utworzone w Role Managerze — ich użytkownicy dostają rolę domyślną
 * strony.
 *
 * Tylko DOKŁADNE nazwy ze spisu w includes/dane-wtyczki.php. Evoke Fields
 * używa tego samego przedrostka `evk_` i jego dane mają zostać nietknięte.
 *
 * WordPress wołając ten plik NIE ładuje wtyczki (usunąć da się tylko
 * wyłączoną), więc wszystko tu jest samodzielne: $wpdb i funkcje rdzenia.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

if (!function_exists('evk_odinstaluj_rmdir')) {
    /** Katalog z zawartością, bez podążania za dowiązaniami (dowiązanie znika samo). */
    function evk_odinstaluj_rmdir(string $dir): void {
        if ($dir === '' || !is_dir($dir) || is_link($dir)) return;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $p = $f->getPathname();
            (is_dir($p) && !is_link($p)) ? @rmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}

if (!function_exists('evk_odinstaluj_strone')) {
    /** Jedna strona (na multisite woła się ją dla każdej). */
    function evk_odinstaluj_strone(array $dane): void {
        global $wpdb;
        if (!get_option('evk_usun_dane')) return;

        // ── Role z Role Managera: użytkownicy dostają rolę domyślną ────────
        $domyslna = (string) get_option('default_role', 'subscriber');
        foreach ((array) get_option('evk_role_utworzone', []) as $rola) {
            $rola = (string) $rola;
            if ($rola === '' || in_array($rola, ['administrator', 'editor', 'author', 'contributor', 'subscriber'], true)) continue;
            foreach (get_users(['role' => $rola, 'fields' => 'all']) as $u) {
                $u->remove_role($rola);
                if (!$u->roles && $domyslna !== $rola) $u->add_role($domyslna);
            }
            remove_role($rola);
        }

        // ── Uprawnienia wtyczki na wszystkich rolach ─────────────────────
        foreach (wp_roles()->role_objects as $obiekt) {
            foreach ($dane['uprawnienia'] as $cap) {
                if ($obiekt->has_cap($cap)) $obiekt->remove_cap($cap);
            }
        }

        // ── Wpisy wtyczki (snippety, przekierowania, logi) z rewizjami i meta
        $typy = implode(',', array_map(static function ($t) use ($wpdb) { return $wpdb->prepare('%s', $t); }, $dane['typy_wpisow']));
        foreach ((array) $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($typy)") as $id) {
            wp_delete_post((int) $id, true);
        }
        foreach ($dane['meta_wpisow'] as $klucz) delete_post_meta_by_key($klucz);
        foreach ($dane['meta_uzytkownikow'] as $klucz) delete_metadata('user', 0, $klucz, '', true);

        // ── Tabele ────────────────────────────────────────────────────────
        foreach ($dane['tabele'] as $tabela) {
            $wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '', $wpdb->prefix . $tabela) . '`');
        }
        /* Tabel roboczych przerwanego przywracania (evkr…/evko…) NIE ruszamy:
           nie mają przedrostka strony, więc przy bazie wspólnej dla kilku
           instalacji mogą należeć do przywracania, które trwa obok. */

        // ── Katalogi: kopie (TEN katalog, nie każdy evk-backups-*), obrazki OG
        $sufiks = (string) get_option('evk_backup_dir', '');
        if (preg_match('/^[a-f0-9]{20}$/', $sufiks)) evk_odinstaluj_rmdir(WP_CONTENT_DIR . '/evk-backups-' . $sufiks);
        evk_odinstaluj_rmdir(WP_CONTENT_DIR . '/evk-backups-import');
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['basedir'])) evk_odinstaluj_rmdir($uploads['basedir'] . '/og-images');

        // ── Opcje i transienty ────────────────────────────────────────────
        foreach ($dane['opcje'] as $opcja) delete_option($opcja);
        foreach (($dane['opcje_dawne'] ?? []) as $opcja) delete_option($opcja);
        foreach ($dane['opcje_przedrostki'] as $p) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($p) . '%'));
        }
        foreach ($dane['transienty'] as $t) delete_transient($t);
        foreach ($dane['transienty_przedrostki'] as $p) {
            foreach (['_transient_', '_transient_timeout_'] as $rodzaj) {
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($rodzaj . $p) . '%'));
            }
        }

        foreach ($dane['haki_crona'] as $hak) wp_unschedule_hook($hak);
        delete_option('rewrite_rules');   // WordPress zbuduje reguły od nowa, już bez naszych
        wp_cache_delete('alloptions', 'options');   // po kasowaniu przedrostkami wprost w SQL
    }
}

/* DRUGA KOPIA ZOSTAJE — danych nie ruszamy (1.233.1). Na stronie bywają dwa
   katalogi z Evoke ONE naraz (ręcznie wgrana kopia z gałęzi obok
   „evoke-one-main"). Usunięcie jednej z nich to sprzątanie plików, nie
   rezygnacja z wtyczki: ustawienia, subskrybenci i kopie należą dalej do
   kopii, która zostaje — także przy włączonym „Usuń dane". get_plugins()
   jest na pewno: ten plik woła uninstall_plugin() z tego samego pliku
   rdzenia (wp-admin/includes/plugin.php). */
$evk_inne_kopie = array_filter(array_keys(get_plugins()), static function ($p) {
    return $p !== WP_UNINSTALL_PLUGIN && preg_match('#(^|/)evoke-one\.php$#', (string) $p);
});
if ($evk_inne_kopie) return;

$evk_dane = require __DIR__ . '/includes/dane-wtyczki.php';
if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $evk_strona) {
        switch_to_blog((int) $evk_strona);
        evk_odinstaluj_strone($evk_dane);
        restore_current_blog();
    }
} else {
    evk_odinstaluj_strone($evk_dane);
}
