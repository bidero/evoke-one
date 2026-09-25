<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Newsletter — wysyłka przez wp_mail() (1.233.0)
 *
 * Transport wybiera WordPress. Przy włączonym SMTP Evoke konfiguruje go
 * `phpmailer_init` z includes/tools/smtp.php — to jest transport domyślny.
 * Bez niego mail idzie tym, czym strona wysyła resztę poczty: inną wtyczką
 * SMTP, API dostawcy albo funkcją mail() serwera.
 *
 * Do 1.232.0 newsletter miał własnego PHPMailera spiętego na sztywno
 * z ustawieniami SMTP Evoke: przy wyłączonym SMTP Evoke kampania kończyła się
 * błędem „SMTP Evoke ONE jest wyłączony", a mail z potwierdzeniem zapisu
 * nie wychodził wcale — nawet gdy strona wysyłała pocztę inną wtyczką.
 */

function evk_nl_send_mail(array $subscriber, array $campaign, array $template, array $queue_row) {
    $fields    = json_decode($subscriber['fields_json'] ?? '{}', true) ?: [];
    $unsub_url = evk_nl_unsubscribe_url($subscriber['token']);

    $view_url = evk_nl_view_url((int) $campaign['id'], $subscriber['token']);

    $merge = array_merge([
        '{email}'            => $subscriber['email'],
        '{unsubscribe_url}'  => $unsub_url,
        '{site_name}'        => get_bloginfo('name'),
        '{site_url}'         => preg_replace('#^https?://#', '', home_url()),
        '{site_url_full}'     => home_url(),
        '{unsubscribe_url_plain}' => preg_replace('#^https?://#', '', $unsub_url),
        '{view_in_browser}'  => '<a href="' . esc_url($view_url) . '" style="color:#64748b;font-size:12px;">Zobacz w przeglądarce</a>',
        '{view_url}'         => $view_url,
        '{view_url_plain}'   => preg_replace('#^https?://#', '', $view_url),
    ], evk_nl_fields_to_merge_tags($fields));

    $subject = evk_nl_replace_merge_tags($template['subject'], $merge);
    $body    = evk_nl_replace_merge_tags(evk_nl_linki_adresu_strony($template['body_html']), $merge);

    // Załączniki PDF — dodaj linki w treści maila
    $attachment_ids = json_decode($template['attachments_json'] ?? '[]', true) ?: [];
    if (!empty($attachment_ids)) {
        $body = evk_nl_append_attachment_links($body, $attachment_ids, $subscriber['token'], !empty($campaign['tracking_enabled']), (int) $campaign['id']);
    }

    if (!empty($campaign['tracking_enabled'])) {
        $body = evk_nl_inject_tracking($body, $subscriber['token'], (int) $campaign['id']);
    }

    return evk_nl_wyslij([
        'to'          => $subscriber['email'],
        'subject'     => $subject,
        'body'        => $body,
        'attachments' => $attachment_ids,
        'unsub_url'   => $unsub_url,
        'kampania'    => true,
    ]);
}

/**
 * Czy trwa paczka kampanii. Przez ten czas połączenie SMTP zostaje otwarte
 * między mailami (SMTPKeepAlive) — jedno połączenie na paczkę zamiast osobnego
 * na każdy mail, jak przy własnym PHPMailerze do 1.232.0: dostawcy liczą
 * i ograniczają także połączenia, nie tylko maile. Zamyka evk_nl_mailer_close().
 */
function evk_nl_paczka(?bool $trwa = null): bool {
    static $stan = false;
    if ($trwa !== null) $stan = $trwa;
    return $stan;
}

/** Koniec paczki: zamyka połączenie SMTP, które paczka trzymała otwarte. */
function evk_nl_mailer_close(): void {
    global $phpmailer;
    evk_nl_paczka(false);
    if ($phpmailer instanceof PHPMailer\PHPMailer\PHPMailer) {
        $phpmailer->SMTPKeepAlive = false;
        $phpmailer->smtpClose();
    }
}

/** Wersja tekstowa maila HTML (część „text/plain" wiadomości). */
function evk_nl_tekst_z_html(string $html): string {
    $alt = preg_replace('/<br\s*\/?>\s*/i', "\n", $html);
    $alt = preg_replace('/<\/p>\s*/i', "\n\n", (string) $alt);
    $alt = preg_replace('/<\/tr>\s*/i', "\n", (string) $alt);
    $alt = preg_replace('/<\/td>\s*/i', "\t", (string) $alt);
    $alt = html_entity_decode(wp_strip_all_tags((string) $alt), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return (string) preg_replace("/\n{3,}/", "\n\n", trim($alt));
}

/**
 * Jeden mail newslettera przez wp_mail(). Zwraca true albo WP_Error
 * z komunikatem PHPMailera i — przy SMTP — faktyczną odpowiedzią serwera
 * (np. limit wysyłki), jak przy własnym PHPMailerze do 1.232.0.
 *
 * $args: to, subject, body (HTML), attachments (ID załączników),
 * unsub_url (nagłówki List-Unsubscribe), kampania (bool — maile kampanii nie
 * idą do logu SMTP, mają własny dziennik w Raportach).
 */
function evk_nl_wyslij(array $args) {
    $naglowki = ['Content-Type: text/html; charset=UTF-8'];

    // List-Unsubscribe + one-click (wymogi Gmail/Yahoo dla masowej wysyłki)
    if (!empty($args['unsub_url'])) {
        $nl_opts = get_option('evk_newsletter', []);
        $mailto  = trim((string) ($nl_opts['unsub_mailto'] ?? ''));
        $naglowki[] = 'List-Unsubscribe: ' . (($mailto !== '' && is_email($mailto))
            ? '<mailto:' . $mailto . '?subject=unsubscribe>, <' . $args['unsub_url'] . '>'
            : '<' . $args['unsub_url'] . '>');
        $naglowki[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
    }

    $zalaczniki = [];
    foreach ((array) ($args['attachments'] ?? []) as $attachment_id) {
        $plik = get_attached_file((int) $attachment_id);
        if ($plik && file_exists($plik)) $zalaczniki[] = $plik;
    }

    /* Na czas jednego maila: wersja tekstowa, ukryta nazwa biblioteki
       i połączenie otwarte do końca paczki. PHPMailer w WordPressie jest
       WSPÓLNY dla całego żądania — to, co tu ustawiamy, wraca potem do stanu
       sprzed maila, żeby nie wyciekło do poczty innych wtyczek. */
    $tekst = evk_nl_tekst_z_html((string) $args['body']);
    $przed = null;
    $przygotuj = static function ($pm) use ($tekst, &$przed) {
        $przed = ['XMailer' => $pm->XMailer, 'SMTPKeepAlive' => $pm->SMTPKeepAlive];
        $pm->AltBody = $tekst;
        $pm->XMailer = ' ';   // nie ujawniaj biblioteki (jak Gmail)
        if (evk_nl_paczka()) $pm->SMTPKeepAlive = true;
    };
    /* Nadawca „WordPress" (domyślna nazwa z wp_mail) w skrzynce subskrybenta
       wygląda jak spam — w jej miejsce nazwa strony. Nazwę ustawioną przez
       SMTP Evoke albo inną wtyczkę pocztową zostawiamy. */
    $nazwa = static function ($n) { return $n === 'WordPress' ? get_bloginfo('name') : $n; };
    $blad = null;
    $przechwyc = static function ($e) use (&$blad) { $blad = $e; };
    $pomin_log = !empty($args['kampania']);

    add_action('phpmailer_init', $przygotuj, 99);
    add_filter('wp_mail_from_name', $nazwa);
    add_action('wp_mail_failed', $przechwyc);
    if ($pomin_log) add_filter('evk_smtp_log_pomin', '__return_true');
    try {
        $wyslano = wp_mail($args['to'], (string) $args['subject'], (string) $args['body'], $naglowki, $zalaczniki);
    } finally {
        remove_action('phpmailer_init', $przygotuj, 99);
        remove_filter('wp_mail_from_name', $nazwa);
        remove_action('wp_mail_failed', $przechwyc);
        if ($pomin_log) remove_filter('evk_smtp_log_pomin', '__return_true');
    }

    global $phpmailer;
    $pm = $phpmailer instanceof PHPMailer\PHPMailer\PHPMailer ? $phpmailer : null;
    if ($pm && $przed !== null) {
        $pm->XMailer = $przed['XMailer'];
        if (!evk_nl_paczka()) $pm->SMTPKeepAlive = $przed['SMTPKeepAlive'];
    }
    if ($wyslano) return true;

    // "data not accepted" itp. to ogólne komunikaty PHPMailera — dopisz
    // faktyczną odpowiedź serwera SMTP (kod + powód, np. limit wysyłki)
    $msg  = is_wp_error($blad) ? trim($blad->get_error_message()) : 'Nie udało się wysłać wiadomości.';
    $kod  = 'mail_error';
    $conn = $pm ? $pm->getSMTPInstance() : null;
    if ($conn) {
        $err   = $conn->getError();
        /* Odbicie („skrzynka nie istnieje", 5.1.x) — widać je tylko w paczce:
           przy otwartym połączeniu PHPMailer po odmowie RCPT wysyła RSET, który
           błędu nie czyści; bez paczki QUIT go czyści. Pojedyncze maile
           (potwierdzenie zapisu) i tak nie mają kogo wyłączać. */
        if (evk_nl_to_odbicie((string) ($err['smtp_code_ex'] ?? ''))) $kod = 'odbity';
        $extra = trim((string) ($err['detail'] ?? ''));
        if ($extra === '') $extra = trim((string) ($err['error'] ?? ''));
        if ($extra === '') $extra = trim((string) $conn->getLastReply());
        if ($extra !== '' && stripos($msg, $extra) === false) {
            $msg .= ' | Odpowiedź serwera: ' . $extra;
        }
    }
    // Po błędzie połączenie może być w złym stanie — zamknij,
    // kolejny mail otworzy świeże
    if ($pm) $pm->smtpClose();
    return new WP_Error($kod, $msg);
}

// =========================================================================
// MERGE TAGI
// =========================================================================

function evk_nl_fields_to_merge_tags(array $fields): array {
    $tags = [];
    foreach ($fields as $key => $val) {
        $tags['{' . sanitize_key($key) . '}'] = (string) $val;
    }
    return $tags;
}

function evk_nl_replace_merge_tags(string $text, array $merge): string {
    return str_replace(array_keys($merge), array_values($merge), $text);
}

/**
 * Adres strony z tagów {site_url} i {site_url_full} jako link, który przejdzie
 * przez śledzenie kliknięć (1.233.2). Działa na treści szablonu PRZED
 * podmianą tagów — przy wysyłce i w podglądzie w przeglądarce.
 *
 * Zgłoszenie: linki z tymi tagami nie trafiały do statystyk. Zmierzone
 * w prawdziwym edytorze szablonu (TinyMCE z WordPressa 7.1):
 *   — przycisk tagu wstawia TEKST. Z „stara.test" czy „https://stara.test"
 *     link robi dopiero klient poczty, prowadzący wprost na stronę — tracker
 *     przepisuje wyłącznie <a href>;
 *   — `href="{site_url}"` wpisany w zakładce „Tekst" to adres bez protokołu,
 *     czyli link względny: w poczcie nie działa, a tracker go pomija;
 *   — okno linku dokleja „http://" do wszystkiego bez protokołu: z tagiem
 *     {site_url_full} wychodziło „http://https://…", a z {site_url} zawsze
 *     http, także na stronie z https.
 *
 * Stąd dwa kroki. W href tag na początku adresu (z protokołem albo bez)
 * zamienia się w {site_url_full} — adres strony z jej własnym protokołem.
 * Tag w tekście, poza istniejącym linkiem, staje się linkiem z TYM SAMYM
 * napisem co dotąd. Ścieżka wpisana zaraz po tagu („{site_url}/kontakt/")
 * wchodzi do linku, kropka kończąca zdanie — nie.
 */
function evk_nl_linki_adresu_strony(string $html): string {
    if (strpos($html, '{site_url') === false) return $html;

    $html = (string) preg_replace('/(\bhref\s*=\s*["\']\s*)(?:https?:\/\/)?\{site_url(?:_full)?\}/i', '$1{site_url_full}', $html);

    /* Tylko tekst między znacznikami. W środku linku (link w linku to
       zepsuty HTML), stylu, skryptu i nagłówka dokumentu tag zostaje
       zwykłym tekstem. */
    $czesci = preg_split('/(<!--.*?-->|<[^>]*>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($czesci)) return $html;
    $wewnatrz = 0;
    foreach ($czesci as $i => $c) {
        if ($c === '') continue;
        if ($c[0] === '<') {
            if (preg_match('/^<(\/?)(a|head|style|script|title|textarea)\b/i', $c, $m)) {
                $wewnatrz = $m[1] === '' ? $wewnatrz + 1 : max(0, $wewnatrz - 1);
            }
            continue;
        }
        if ($wewnatrz > 0 || strpos($c, '{site_url') === false) continue;
        $nowy = preg_replace_callback(
            '/\{site_url(_full)?\}((?:[\/?#](?:(?!&nbsp;|&#160;)[^\s<>"\'\x{00A0}])*)?)/u',
            static function ($m) {
                $sciezka = $m[2];
                $ogon    = preg_match('/[.,;:!?)\]]+$/', $sciezka, $k) ? $k[0] : '';
                if ($ogon !== '') $sciezka = substr($sciezka, 0, -strlen($ogon));
                return '<a href="{site_url_full}' . $sciezka . '">{site_url' . $m[1] . '}' . $sciezka . '</a>' . $ogon;
            },
            $c
        );
        if (is_string($nowy)) $czesci[$i] = $nowy;
    }
    return implode('', $czesci);
}

// =========================================================================
// LINKI DO ZAŁĄCZNIKÓW W TREŚCI
// =========================================================================

function evk_nl_append_attachment_links(string $body, array $attachment_ids, string $token, bool $track = true, int $campaign_id = 0): string {
    $links = [];

    foreach ($attachment_ids as $att_id) {
        $att_id  = (int) $att_id;
        $url     = wp_get_attachment_url($att_id);
        $name    = get_the_title($att_id) ?: basename(get_attached_file($att_id) ?: '');
        $mime    = get_post_mime_type($att_id) ?: '';

        if (!$url) continue;

        // Ikona wg typu MIME
        $icon = match (true) {
            str_contains($mime, 'pdf')        => '📄',
            str_contains($mime, 'word')       => '📝',
            str_contains($mime, 'excel')      => '📊',
            str_contains($mime, 'spreadsheet')=> '📊',
            str_contains($mime, 'zip')        => '🗜',
            str_contains($mime, 'image')      => '🖼',
            default                           => '📎',
        };

        // Przez tracker kliknięć jeśli tracking włączony
        $href = $track ? evk_nl_click_url($token, $url, $campaign_id) : $url;

        $links[] = '<a href="' . esc_url($href) . '" '
                 . 'style="display:inline-block;margin:4px 8px 4px 0;padding:8px 16px;'
                 . 'background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;'
                 . 'color:#2563eb;text-decoration:none;font-size:13px;font-family:sans-serif;">'
                 . $icon . ' ' . esc_html($name)
                 . '</a>';
    }

    if (empty($links)) return $body;

    $block = '<div style="margin:24px 0 8px;padding:16px;background:#f8fafc;'
           . 'border:1px solid #e2e8f0;border-radius:8px;font-family:sans-serif;">'
           . '<p style="margin:0 0 10px;font-size:12px;color:#64748b;font-weight:600;'
           . 'text-transform:uppercase;letter-spacing:.05em;">Załączniki</p>'
           . implode('', $links)
           . '</div>';

    // Wstaw przed </body> jeśli jest, wpp na końcu
    if (stripos($body, '</body>') !== false) {
        return str_ireplace('</body>', $block . '</body>', $body);
    }

    return $body . $block;
}

// =========================================================================
// TRACKING
// =========================================================================

function evk_nl_inject_tracking(string $body, string $token, int $campaign_id): string {
    $body = preg_replace_callback(
        '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i',
        function ($m) use ($token, $campaign_id) {
            /* Atrybut HTML, nie adres: TinyMCE zapisuje `&` jako `&amp;`
               (i tak ma być w HTML-u). Do 1.230.0 tracker opakowywał surowy
               tekst atrybutu, więc cel dostawał `amp;utm_medium` zamiast
               `utm_medium`, a przy zwykłych odnośnikach (bez rewrite) adres
               urywał się na pierwszym `&` i link zewnętrzny lądował na stronie
               głównej. Audyt 1.229.6, tools/audyt/sondy/nl-amp.php. */
            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // Napraw podwójny protokół (TinyMCE bug)
            $url = preg_replace('#^https?://https?://#i', 'https://', $url);
            $url = preg_replace('#^https?://http://#i', 'http://', $url);

            if (
                strpos($url, '#') === 0 ||
                strpos($url, 'mailto:') === 0 ||
                strpos($url, '/nl/click/') !== false ||
                strpos($url, '/nl/unsub/') !== false ||
                strpos($url, '/nl/open/') !== false ||
                !wp_http_validate_url($url)
            ) {
                return $m[0];
            }

            // Z powrotem do atrybutu — jako HTML (`&` → `&#038;`).
            $track_url = evk_nl_click_url($token, $url, $campaign_id);
            return str_replace($m[1], esc_url($track_url), $m[0]);
        },
        $body
    );

    $pixel_url = evk_nl_open_url($token, $campaign_id);
    $pixel     = '<img src="' . esc_url($pixel_url) . '" width="1" height="1" border="0" alt="" '
               . 'style="display:block;width:1px;height:1px;max-width:1px;max-height:1px;'
               . 'margin:0;padding:0;line-height:0;border:none;" />';

    if (stripos($body, '<body') !== false) {
        $body = preg_replace('/(<body[^>]*>)/i', '$1' . $pixel, $body, 1);
    } else {
        $body .= $pixel;
    }

    return $body;
}

// =========================================================================
// TRANSPORT — czym strona wyśle newsletter
// =========================================================================

function evk_nl_smtp_is_configured(): bool {
    $s = evk_smtp_get();
    return !empty($s['enabled']) && !empty($s['host']) && !empty($s['username']);
}

/**
 * Czym WordPress wyśle pocztę — do komunikatu w panelu newslettera.
 *
 * `evoke`: SMTP Evoke (transport domyślny). `inny`: inna wtyczka pocztowa —
 * rozpoznana po tym, że podpina się pod `phpmailer_init` albo `pre_wp_mail`
 * albo podmienia samo `wp_mail()`. `mail`: nic z tego, czyli funkcja mail()
 * serwera — przy wysyłce masowej zwykle prosto do spamu.
 *
 * `inne` to nazwy katalogów wtyczek (albo plików), które podpinają pocztę;
 * przy `evoke` niepusta lista znaczy, że pocztę ustawiają DWIE wtyczki naraz.
 */
function evk_nl_transport(): array {
    global $wp_filter;
    $wlasny = wp_normalize_path(dirname(__DIR__, 2));
    $inne   = [];
    $kto = static function ($cb) use ($wlasny): string {
        try {
            if (is_array($cb)) {
                $r = new ReflectionMethod(is_object($cb[0]) ? get_class($cb[0]) : (string) $cb[0], (string) $cb[1]);
            } elseif (is_string($cb) && strpos($cb, '::') !== false) {
                $r = new ReflectionMethod($cb);
            } elseif (is_string($cb) || $cb instanceof Closure) {
                $r = new ReflectionFunction($cb);
            } elseif (is_object($cb) && method_exists($cb, '__invoke')) {
                $r = new ReflectionMethod($cb, '__invoke');
            } else {
                return '';
            }
        } catch (ReflectionException $e) {
            return '';
        }
        $plik = wp_normalize_path((string) $r->getFileName());
        if ($plik === '' || strpos($plik, $wlasny . '/') === 0) return '';     // nasze
        if (strpos($plik, wp_normalize_path(ABSPATH . WPINC) . '/') === 0) return '';   // rdzeń WP
        $wtyczki = wp_normalize_path(WP_PLUGIN_DIR) . '/';
        if (strpos($plik, $wtyczki) === 0) {
            $katalog = (string) strtok(substr($plik, strlen($wtyczki)), '/');
            // Nasza wtyczka ładowana spod dowiązania — ta sama nazwa katalogu.
            return $katalog === basename($wlasny) ? '' : $katalog;
        }
        return basename($plik);
    };
    foreach (['phpmailer_init', 'pre_wp_mail'] as $hak) {
        foreach ((array) ($wp_filter[$hak]->callbacks ?? []) as $funkcje) {
            foreach ($funkcje as $f) {
                $n = $kto($f['function']);
                if ($n !== '') $inne[$n] = true;
            }
        }
    }
    try {
        $n = $kto('wp_mail');
        if ($n !== '') $inne[$n] = true;   // podmienione wp_mail() (funkcja „pluggable")
    } catch (Throwable $e) {}
    $inne = array_keys($inne);

    if (evk_nl_smtp_is_configured()) return ['rodzaj' => 'evoke', 'inne' => $inne];
    return ['rodzaj' => $inne ? 'inny' : 'mail', 'inne' => $inne];
}

/**
 * Ostrzeżenie o transporcie do panelu — pusty łańcuch, gdy nie ma o czym
 * mówić (SMTP Evoke albo inna wtyczka pocztowa, bez zderzenia).
 *
 * Do 1.232.0 panel ostrzegał „SMTP nie jest skonfigurowany — wysyłka nie
 * będzie działać" także na stronach, które wysyłały pocztę inną wtyczką —
 * i wtedy było to prawdą tylko dlatego, że newsletter umiał wyłącznie SMTP
 * Evoke.
 */
function evk_nl_ostrzezenie_transportu(): string {
    $t   = evk_nl_transport();
    $smtp = esc_url(admin_url('options-general.php?page=evoke-one&tab=narzedzia&sub=smtp'));
    if ($t['rodzaj'] === 'mail') {
        return '<div class="evo-info-box is-warn evo-mb" data-evk-nl-transport="mail"><span class="dashicons dashicons-warning evo-warn-tx"></span><div>'
             . '<strong>Newsletter wyśle maile funkcją mail() serwera.</strong> Bez SMTP wysyłka masowa zwykle ląduje w spamie albo zatrzymuje się na limicie hostingu. '
             . '<a href="' . $smtp . '">Skonfiguruj SMTP →</a></div></div>';
    }
    if ($t['rodzaj'] === 'evoke' && $t['inne']) {
        return '<div class="evo-info-box is-warn evo-mb" data-evk-nl-transport="dwa"><span class="dashicons dashicons-warning evo-warn-tx"></span><div>'
             . '<strong>Pocztę ustawiają dwie wtyczki:</strong> SMTP Evoke i ' . esc_html(implode(', ', $t['inne'])) . '. '
             . 'Wysyła ta, która podpina się później — zostaw jedną.</div></div>';
    }
    return '';
}
