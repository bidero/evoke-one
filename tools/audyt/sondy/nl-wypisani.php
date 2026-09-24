<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
// Czy import listy przywraca na listę osobę, która się wypisała?
$_SERVER['HTTP_HOST'] = 'stara.test';
require (getenv('EVK_WP_PATH') ?: getenv('HOME') . '/.cache/evk-testowy-wp') . '/wp-load.php';
evk_nl_create_tables();
$lista = evk_nl_create_list('Audyt ' . wp_rand());
// 1. zapis z formularza z double opt-in i potwierdzenie
$r = evk_nl_add_pending_subscriber($lista, 'anna@example.com', ['_consent_at' => '2026-01-10 10:00:00', '_consent_ip' => '1.2.3.4', '_consent_text' => 'Zgadzam się…']);
evk_nl_confirm_subscriber($r['token']);
// 2. wypis linkiem z maila
evk_nl_unsubscribe_by_token($r['token']);
$przed = evk_nl_get_subscriber_by_token($r['token']);
echo "po wypisie:  status={$przed['status']} unsubscribed_at={$przed['unsubscribed_at']} fields=" . $przed['fields_json'] . "\n";
// 3. administrator importuje starą listę CSV, na której jest ten adres
$wynik = evk_nl_import_emails($lista, ['anna@example.com', 'nowy@example.com']);
echo "wynik importu: " . json_encode($wynik) . "\n";
$po = evk_nl_get_subscriber_by_token($r['token']);
echo "po imporcie: status={$po['status']} unsubscribed_at=" . var_export($po['unsubscribed_at'], true) . " fields=" . $po['fields_json'] . "\n";
evk_nl_delete_list($lista);
