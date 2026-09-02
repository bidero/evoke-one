<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Tab: seo (loader)
 */
?>
<?php
            $sub      = sanitize_key($_GET['sub'] ?? 'meta');
            $sub_base = add_query_arg(['tab' => 'strona'], $base);
            /* Lista mieszka w evoke_one_ekrany() (includes/admin/helpers.php), bo
               czytają ją także pasek boczny i wyszukiwarka — patrz komentarz tam. */
            $subs = evoke_one_ekrany()['strona'];
            ?>

            <?php evoke_one_render_subtabs($subs, $sub, $sub_base); ?>

            <?php if ($sub === 'meta'): ?>
                <?php require __DIR__ . '/seo/tab-meta.php'; ?>
            <?php elseif ($sub === 'sitemap'): ?>
                <?php require __DIR__ . '/seo/tab-sitemap.php'; ?>
            <?php elseif ($sub === 'schema'): ?>
                <?php require __DIR__ . '/seo/tab-schema.php'; ?>
            <?php elseif ($sub === 'og'): ?>
                <?php require __DIR__ . '/seo/tab-og.php'; ?>
            <?php endif; ?>
