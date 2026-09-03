<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Tab: seo (loader)
 */
?>
<?php
            $sub = sanitize_key($_GET['sub'] ?? 'meta');
            /* Paska podzakładek tu nie ma od 1.139.1 — te same ekrany pokazuje
               pasek boczny, obie listy z `evoke_one_ekrany()`. */
            ?>

            <?php if ($sub === 'meta'): ?>
                <?php require __DIR__ . '/seo/tab-meta.php'; ?>
            <?php elseif ($sub === 'sitemap'): ?>
                <?php require __DIR__ . '/seo/tab-sitemap.php'; ?>
            <?php elseif ($sub === 'schema'): ?>
                <?php require __DIR__ . '/seo/tab-schema.php'; ?>
            <?php elseif ($sub === 'og'): ?>
                <?php require __DIR__ . '/seo/tab-og.php'; ?>
            <?php endif; ?>
