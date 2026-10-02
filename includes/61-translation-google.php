<?php
if (!defined('ABSPATH')) exit;
/**
 * Google Cloud Translation v3 (Advanced) jako piąty dostawca tłumaczenia AI
 * (1.280.0). Tylko tłumaczy — jak DeepL (61-translation-deepl.php), z którym
 * dzieli ochronę tagów `{…}` i shortcodów oraz wpisy słowniczka.
 *
 * Decyzje zgłaszającego (02.10): v3, bo darmowy limit jest ten sam co w v2
 * (500 000 znaków miesięcznie jako kredyt 10 $), a v3 ma glosariusz;
 * słowniczek jako glosariusz przez Cloud Storage.
 *
 * Dostęp: v3 NIE przyjmuje klucza API. „Klucz” to treść pliku JSON konta
 * usługi (role: Cloud Translation API Editor, Storage Object Admin). Token
 * OAuth Evoke składa sam: JWT podpisany RS256 kluczem prywatnym z pliku,
 * wymieniany w `token_uri` na token dostępu (transient na czas ważności).
 *
 * Glosariusz w v3 powstaje WYŁĄCZNIE z pliku w Cloud Storage. Evoke wgrywa
 * TSV słowniczka do zasobnika z ustawień i zleca utworzenie glosariusza
 * (operacja długotrwała, region us-central1). Do jej końca krok hurtu dostaje
 * „czekaj”; bez zasobnika albo po błędzie tłumaczy bez glosariusza z uwagą.
 */

const EVK_TL_GOOGLE_GLOSARIUSZE = 'evk_tl_google_glosariusze';
/** Glosariusze Google działają tylko w tym regionie — tłumaczenie idzie tam samo. */
const EVK_TL_GOOGLE_REGION = 'us-central1';
const EVK_TL_GOOGLE_ZAKRES = 'https://www.googleapis.com/auth/cloud-translation https://www.googleapis.com/auth/devstorage.read_write';

/** Adresy usług; filtr podmienia je w testach (atrapa). */
function evk_tl_google_adresy(): array {
    return (array) apply_filters('evk_tl_google_adresy', [
        'tlumacz' => 'https://translation.googleapis.com',
        'magazyn' => 'https://storage.googleapis.com',
        'token'   => 'https://oauth2.googleapis.com/token',
    ]);
}

/**
 * Konto usługi z treści pliku JSON — tylko z polami, których Evoke używa.
 *
 * @return array{client_email:string,private_key:string,project_id:string,private_key_id:string}|null
 */
function evk_tl_google_konto(string $json): ?array {
    $d = json_decode($json, true);
    if (!is_array($d) || ($d['type'] ?? '') !== 'service_account') return null;
    foreach (['client_email', 'private_key', 'project_id'] as $p) if (!is_string($d[$p] ?? null) || $d[$p] === '') return null;
    if (strpos((string) $d['private_key'], 'PRIVATE KEY') === false) return null;
    return ['client_email' => (string) $d['client_email'], 'private_key' => (string) $d['private_key'], 'project_id' => (string) $d['project_id'],
        'private_key_id' => (string) ($d['private_key_id'] ?? '')];
}

/** Treść pliku do zapisu: sprawdzona i bez zbędnych pól (null — zły plik). */
function evk_tl_google_do_zapisu(string $json): ?string {
    $k = evk_tl_google_konto(trim($json));
    if ($k === null) return null;
    return (string) wp_json_encode(['type' => 'service_account'] + $k, JSON_UNESCAPED_SLASHES);
}

function evk_tl_google_b64(string $t): string {
    return rtrim(strtr(base64_encode($t), '+/', '-_'), '=');
}

/**
 * Token dostępu: z pamięci (transient) albo nowy — JWT RS256 wymieniony
 * w punkcie tokenów Google.
 *
 * @return array{token:string,blad:string,kod:int}
 */
function evk_tl_google_token(array $k): array {
    $tr = 'evk_tl_google_token_' . substr(md5($k['client_email'] . '|' . $k['private_key_id']), 0, 12);
    $t = get_transient($tr);
    if (is_string($t) && $t !== '') return ['token' => $t, 'blad' => '', 'kod' => 200];
    if (!function_exists('openssl_sign')) return ['token' => '', 'blad' => 'Serwer nie ma rozszerzenia OpenSSL — Google wymaga podpisu tokenu.', 'kod' => 0];
    $teraz = time();
    $adres = evk_tl_google_adresy()['token'];
    $jwt = evk_tl_google_b64((string) wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT'] + ($k['private_key_id'] !== '' ? ['kid' => $k['private_key_id']] : [])))
        . '.' . evk_tl_google_b64((string) wp_json_encode(['iss' => $k['client_email'], 'scope' => EVK_TL_GOOGLE_ZAKRES, 'aud' => $adres,
            'iat' => $teraz, 'exp' => $teraz + 3600]));
    $podpis = '';
    $klucz = openssl_pkey_get_private($k['private_key']);
    if ($klucz === false || !openssl_sign($jwt, $podpis, $klucz, OPENSSL_ALGO_SHA256)) {
        return ['token' => '', 'blad' => 'Klucz prywatny z pliku JSON nie podpisuje — wgraj plik konta usługi jeszcze raz.', 'kod' => 0];
    }
    $odp = wp_remote_post($adres, ['timeout' => 30, 'body' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt . '.' . evk_tl_google_b64($podpis)]]);
    if (is_wp_error($odp)) return ['token' => '', 'blad' => 'Brak połączenia z Google: ' . $odp->get_error_message(), 'kod' => 0];
    $kod = (int) wp_remote_retrieve_response_code($odp);
    $o = json_decode((string) wp_remote_retrieve_body($odp), true);
    $token = is_array($o) ? (string) ($o['access_token'] ?? '') : '';
    if ($kod !== 200 || $token === '') {
        $opis = is_array($o) ? (string) ($o['error_description'] ?? $o['error'] ?? '') : '';
        return ['token' => '', 'blad' => 'Google odrzucił konto usługi (' . $kod . ($opis !== '' ? ': ' . mb_substr($opis, 0, 200) : '') . ').', 'kod' => $kod];
    }
    set_transient($tr, $token, max(60, (int) ($o['expires_in'] ?? 3600) - 120));
    return ['token' => $token, 'blad' => '', 'kod' => 200];
}

/** Żądanie do usługi Google. @return array{kod:int,o:mixed,retry:string,blad?:string} */
function evk_tl_google_http(string $metoda, string $url, string $token, $cialo = null, string $typ = 'application/json'): array {
    $args = ['method' => $metoda, 'timeout' => 120, 'headers' => ['Authorization' => 'Bearer ' . $token]];
    if ($cialo !== null) {
        $args['headers']['Content-Type'] = $typ;
        $args['body'] = is_string($cialo) ? $cialo : (string) wp_json_encode($cialo);
    }
    $odp = wp_remote_request($url, $args);
    if (is_wp_error($odp)) return ['kod' => 0, 'o' => null, 'retry' => '', 'blad' => $odp->get_error_message()];
    $retry = wp_remote_retrieve_header($odp, 'retry-after');
    return ['kod' => (int) wp_remote_retrieve_response_code($odp), 'o' => json_decode((string) wp_remote_retrieve_body($odp), true),
        'retry' => is_string($retry) ? $retry : ''];
}

/**
 * Kod języka Google z kodu HTML języka. Angielski i niemiecki bez wariantów
 * (Google ich nie ma); portugalski bez regionu — europejski (jak w DeepL:
 * decyzja z 02.10), pt-BR — brazylijski („pt” w Google); chiński po regionie.
 */
function evk_tl_google_jezyk(string $lang): string {
    $j = tl_get_languages()[$lang] ?? [];
    $html = strtolower(str_replace('_', '-', (string) ($j['html'] ?? $lang)));
    $cz = explode('-', $html);
    $baza = $cz[0] !== '' ? $cz[0] : strtolower($lang);
    $region = $cz[1] ?? '';
    if ($baza === 'pt') return $region === 'br' ? 'pt' : 'pt-PT';
    if ($baza === 'zh') return in_array($region, ['tw', 'hk', 'mo', 'hant'], true) ? 'zh-TW' : 'zh-CN';
    if ($baza === 'fr' && $region === 'ca') return 'fr-CA';
    if ($baza === 'nb' || $baza === 'nn') return 'no';
    return $baza;
}

/** Projekt i region w ścieżce v3. */
function evk_tl_google_rodzic(array $k): string {
    return 'projects/' . rawurlencode($k['project_id']) . '/locations/' . EVK_TL_GOOGLE_REGION;
}

/**
 * Glosariusz dla języka. Gotowy — nazwa; w przygotowaniu — `czekaj`; bez
 * słowniczka — pusto; bez zasobnika albo po błędzie — pusto z uwagą
 * (tłumaczenie idzie bez glosariusza).
 *
 * @return array{id:string,uwaga:string,czekaj:int}
 */
function evk_tl_google_glosariusz(array $u, array $k, string $token, string $lang): array {
    $wpisy = evk_tl_deepl_wpisy($u, $lang);
    $jg = evk_tl_google_jezyk($lang);
    $pamiec = get_option(EVK_TL_GOOGLE_GLOSARIUSZE, []);
    $pamiec = is_array($pamiec) ? $pamiec : [];
    $kp = substr(md5($k['client_email'] . '|' . $k['project_id']), 0, 8) . ':' . $jg;
    $tsv = '';
    foreach ($wpisy as $a => $b) $tsv .= $a . "\t" . $b . "\n";
    $skrot = md5($tsv . '|' . (string) ($u['zasobnik'] ?? ''));
    $byl = is_array($pamiec[$kp] ?? null) ? $pamiec[$kp] : null;
    $a = evk_tl_google_adresy();
    $zapisz = static function () use (&$pamiec): void { update_option(EVK_TL_GOOGLE_GLOSARIUSZE, $pamiec, false); };
    if ($byl && ($byl['skrot'] ?? '') === $skrot) {
        if (!empty($byl['gotowy'])) return ['id' => (string) $byl['id'], 'uwaga' => '', 'czekaj' => 0];
        /* Operacja tworzenia: koniec — gotowy albo błąd; w toku — czekamy. */
        $r = evk_tl_google_http('GET', rtrim($a['tlumacz'], '/') . '/v3/' . $byl['operacja'], $token);
        if ($r['kod'] === 200 && is_array($r['o']) && empty($r['o']['done'])) return ['id' => '', 'uwaga' => '', 'czekaj' => 5];
        if ($r['kod'] === 200 && is_array($r['o']) && !isset($r['o']['error'])) {
            $pamiec[$kp]['gotowy'] = true;
            $zapisz();
            return ['id' => (string) $byl['id'], 'uwaga' => '', 'czekaj' => 0];
        }
        unset($pamiec[$kp]);
        $zapisz();
        $opis = is_array($r['o']) ? (string) ($r['o']['error']['message'] ?? '') : '';
        return ['id' => '', 'uwaga' => 'Glosariusz Google nie powstał' . ($opis !== '' ? ' (' . mb_substr($opis, 0, 200) . ')' : '') . ' — tłumaczę bez niego.', 'czekaj' => 0];
    }
    if ($byl && !empty($byl['id'])) evk_tl_google_http('DELETE', rtrim($a['tlumacz'], '/') . '/v3/' . $byl['id'], $token);
    unset($pamiec[$kp]);
    $zapisz();
    if (!$wpisy) return ['id' => '', 'uwaga' => '', 'czekaj' => 0];
    $zasobnik = (string) ($u['zasobnik'] ?? '');
    if ($zasobnik === '') return ['id' => '', 'uwaga' => 'Słowniczek bez glosariusza Google: wpisz zasobnik Cloud Storage w ustawieniach — tłumaczę bez niego.', 'czekaj' => 0];
    $plik = 'evoke-slowniczek-' . $jg . '-' . substr($skrot, 0, 8) . '.tsv';
    $w = evk_tl_google_http('POST', rtrim($a['magazyn'], '/') . '/upload/storage/v1/b/' . rawurlencode($zasobnik) . '/o?uploadType=media&name=' . rawurlencode($plik),
        $token, $tsv, 'text/tab-separated-values');
    if ($w['kod'] !== 200) {
        return ['id' => '', 'uwaga' => 'Słowniczek nie trafił do zasobnika „' . $zasobnik . '” (' . $w['kod'] . ') — sprawdź nazwę i rolę Storage Object Admin; tłumaczę bez glosariusza.', 'czekaj' => 0];
    }
    $id = evk_tl_google_rodzic($k) . '/glossaries/evoke-' . preg_replace('/[^a-z0-9-]/', '-', strtolower($jg)) . '-' . substr($skrot, 0, 8);
    $g = evk_tl_google_http('POST', rtrim($a['tlumacz'], '/') . '/v3/' . evk_tl_google_rodzic($k) . '/glossaries', $token, [
        'name' => $id, 'languagePair' => ['sourceLanguageCode' => 'pl', 'targetLanguageCode' => $jg],
        'inputConfig' => ['gcsSource' => ['inputUri' => 'gs://' . $zasobnik . '/' . $plik]]]);
    $op = is_array($g['o']) ? (string) ($g['o']['name'] ?? '') : '';
    if ($g['kod'] !== 200 || $op === '') {
        $opis = is_array($g['o']) ? (string) ($g['o']['error']['message'] ?? '') : '';
        return ['id' => '', 'uwaga' => 'Glosariusz Google nie powstał (' . $g['kod'] . ($opis !== '' ? ': ' . mb_substr($opis, 0, 200) : '') . ') — tłumaczę bez niego.', 'czekaj' => 0];
    }
    $pamiec[$kp] = ['id' => $id, 'operacja' => $op, 'skrot' => $skrot, 'gotowy' => false];
    $zapisz();
    return ['id' => '', 'uwaga' => '', 'czekaj' => 5];
}

/** Błąd HTTP Google → komunikat, czekanie albo stop. @return array{blad:string,czekaj:int,stop:bool} */
function evk_tl_google_blad(int $kod, $o, string $retry): array {
    $opis = is_array($o) ? (string) ($o['error']['message'] ?? '') : '';
    $opis = $opis !== '' ? ' ' . mb_substr($opis, 0, 300) : '';
    if ($kod === 429) return ['blad' => 'Limit zapytań Google — czekam.' . $opis, 'czekaj' => max(5, min(300, (int) $retry ?: 60)), 'stop' => false];
    if ($kod === 401 || $kod === 403) return ['blad' => 'Google odmówił dostępu (' . $kod . ') — rola Cloud Translation API Editor, włączone API, płatności?' . $opis, 'czekaj' => 0, 'stop' => true];
    if ($kod >= 500) return ['blad' => 'Google przeciążony (' . $kod . ') — czekam.', 'czekaj' => max(5, min(300, (int) $retry ?: 20)), 'stop' => false];
    return ['blad' => 'Błąd Google Translation (' . $kod . ').' . $opis, 'czekaj' => 0, 'stop' => true];
}

/**
 * Porcja przez Google v3 — ten sam wynik co evk_tl_ai_wyslij(). Tryb HTML
 * (`text/html`): znaczniki i `translate="no"` zostają nietknięte.
 *
 * @param array<string,array<string,mixed>> $porcja klucz krótki → tekst (`pl`)
 * @return array{ok:bool,tlumaczenia?:array<string,string>,blad?:string,czekaj?:int,stop?:bool,uwaga?:string,przygotowanie?:bool}
 */
function evk_tl_google_porcja(array $u, string $lang, string $tytul, array $kontekst, array $porcja): array {
    $k = evk_tl_google_konto(evk_tl_ai_klucz($u));
    if ($k === null) return ['ok' => false, 'blad' => 'Brak pliku JSON konta usługi Google — wklej go w ustawieniach Tłumaczenia AI.', 'czekaj' => 0, 'stop' => true];
    $t = evk_tl_google_token($k);
    if ($t['token'] === '') return ['ok' => false, 'blad' => $t['blad'], 'czekaj' => $t['kod'] === 0 ? 15 : 0, 'stop' => $t['kod'] !== 0 || strpos($t['blad'], 'OpenSSL') !== false || strpos($t['blad'], 'Klucz prywatny') !== false];
    /* Próba połączenia (1.283.0): bez glosariusza — niczego nie tworzy ani nie kasuje. */
    $g = empty($u['_proba']) ? evk_tl_google_glosariusz($u, $k, $t['token'], $lang) : ['id' => '', 'czekaj' => 0, 'uwaga' => ''];
    if ($g['czekaj'] > 0) return ['ok' => false, 'blad' => 'Google przygotowuje glosariusz ze słowniczka — czekam.', 'czekaj' => $g['czekaj'], 'stop' => false, 'przygotowanie' => true];
    $klucze = array_keys($porcja);
    $cialo = ['contents' => array_map(static function ($w) { return evk_tl_deepl_zabezpiecz((string) $w['pl']); }, array_values($porcja)),
        'sourceLanguageCode' => 'pl', 'targetLanguageCode' => evk_tl_google_jezyk($lang), 'mimeType' => 'text/html'];
    if ($g['id'] !== '') $cialo['glossaryConfig'] = ['glossary' => $g['id']];
    $r = evk_tl_google_http('POST', rtrim(evk_tl_google_adresy()['tlumacz'], '/') . '/v3/' . evk_tl_google_rodzic($k) . ':translateText', $t['token'], $cialo);
    if ($r['kod'] === 0) return ['ok' => false, 'blad' => 'Brak połączenia z Google: ' . ($r['blad'] ?? ''), 'czekaj' => 15, 'stop' => false];
    if ($r['kod'] !== 200) return ['ok' => false] + evk_tl_google_blad($r['kod'], $r['o'], $r['retry']);
    /* Z glosariuszem Google oddaje oba warianty — liczy się ten z glosariuszem. */
    $pole = $g['id'] !== '' && is_array($r['o']['glossaryTranslations'] ?? null) ? 'glossaryTranslations' : 'translations';
    $tl = is_array($r['o']) && is_array($r['o'][$pole] ?? null) ? array_values($r['o'][$pole]) : null;
    if ($tl === null || count($tl) !== count($klucze)) return ['ok' => false, 'blad' => 'Odpowiedź Google nie pasuje do porcji.', 'czekaj' => 0, 'stop' => false];
    $out = [];
    foreach ($klucze as $i => $kr) {
        if (is_array($tl[$i]) && is_string($tl[$i]['translatedText'] ?? null)) $out[$kr] = evk_tl_deepl_przywroc($tl[$i]['translatedText'], (string) $porcja[$kr]['pl']);
    }
    return ['ok' => true, 'tlumaczenia' => $out] + ($g['uwaga'] !== '' ? ['uwaga' => $g['uwaga']] : []);
}
