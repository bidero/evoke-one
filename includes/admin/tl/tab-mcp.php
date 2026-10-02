<?php
if (!defined('ABSPATH')) exit;
/**
 * Podzakładka „Claude Desktop” (1.278.0) — połączenie Claude Desktop ze
 * stroną przez MCP (63-translation-mcp.php). Decyzje zgłaszającego (02.10):
 * własna instrukcja w Evoke dla macOS, przycisk „Zainstaluj” MCP Adaptera,
 * hasło aplikacji i gotowy wpis do claude_desktop_config.json.
 *
 * Hasło aplikacji pokazuje się RAZ — w odpowiedzi AJAX, nigdy w HTML strony.
 */
if (!function_exists('evk_tl_mcp_adapter_stan')) return;
$evk_m_api = evk_tl_mcp_api();
$evk_m_stan = evk_tl_mcp_adapter_stan();
$evk_m_admin = current_user_can('manage_options');
$evk_m_user = wp_get_current_user();
$evk_m_hasla = wp_is_application_passwords_available_for_user($evk_m_user);
?>
<div class="evo-box tl-mcp" data-nonce="<?php echo esc_attr(wp_create_nonce('evk_tl_mcp')); ?>">
    <h3>Claude Desktop</h3>
    <p class="evo-desc">Tłumaczenie bez klucza API: Claude w aplikacji Claude Desktop (w ramach Twojej subskrypcji) pobiera teksty strony,
    tłumaczy je i zapisuje z powrotem. Działa jak hurt w zakładce „Tłumaczenie AI”: te same części stron, słowniczek, opis strony
    i wskazówki języków; tłumaczenia trafiają na listę „Do sprawdzenia”, a znaczniki HTML, tagi <code>{…}</code> i shortcody
    muszą zostać nietknięte. „Sprawdzone” zaznaczasz Ty — Claude tego nie robi.</p>
</div>

<div class="evo-box tl-mcp-kroki">
    <h3>1. Wymagania na stronie</h3>
    <ul class="tl-mcp-lista">
        <li class="tl-mcp-wym" data-ok="<?php echo $evk_m_api ? '1' : '0'; ?>"><?php echo $evk_m_api ? '✓' : '✗'; ?>
            WordPress z Abilities API (6.9 lub nowszy)<?php if (!$evk_m_api): ?> — zaktualizuj WordPressa<?php endif; ?>.</li>
        <li class="tl-mcp-wym" data-ok="<?php echo $evk_m_hasla ? '1' : '0'; ?>"><?php echo $evk_m_hasla ? '✓' : '✗'; ?>
            Hasła aplikacji<?php if (!$evk_m_hasla): ?> — WordPress daje je tylko przez HTTPS (albo są wyłączone)<?php endif; ?>.</li>
        <li id="tl-mcp-adapter-stan" class="tl-mcp-wym" data-ok="<?php echo $evk_m_stan['aktywny'] ? '1' : '0'; ?>"><?php
            if ($evk_m_stan['aktywny']) {
                echo '✓ MCP Adapter ' . esc_html($evk_m_stan['wersja']) . ' działa.';
            } elseif ($evk_m_stan['plik'] !== '') {
                echo '✗ MCP Adapter jest zainstalowany, ale wyłączony.';
            } else {
                echo '✗ Brak wtyczki MCP Adapter (WordPress/mcp-adapter — zalecana też przez Bricks).';
            } ?></li>
    </ul>
    <?php if (!$evk_m_stan['aktywny']): ?>
    <?php if ($evk_m_admin): ?>
    <p><button type="button" class="button button-primary" id="tl-mcp-adapter"><?php echo $evk_m_stan['plik'] !== '' ? 'Włącz MCP Adapter' : 'Zainstaluj MCP Adapter'; ?></button>
    <span class="tl-mcp-wynik" id="tl-mcp-adapter-wynik" role="status"></span></p>
    <?php else: ?>
    <p class="evo-desc">MCP Adapter instaluje administrator.</p>
    <?php endif; ?>
    <?php endif; ?>

    <h3>2. Hasło aplikacji</h3>
    <p class="evo-desc">Claude Desktop loguje się jako Ty (<code><?php echo esc_html($evk_m_user->user_login); ?></code>) hasłem aplikacji —
    osobnym od zwykłego hasła, do odwołania w profilu (<a href="<?php echo esc_url(admin_url('profile.php#application-passwords-section')); ?>">Profil → Hasła aplikacji</a>).
    Hasło pokaże się tylko raz, od razu w gotowej konfiguracji poniżej.</p>
    <p><button type="button" class="button" id="tl-mcp-haslo" <?php disabled(!$evk_m_hasla); ?>>Utwórz hasło aplikacji</button>
    <span class="tl-mcp-wynik" id="tl-mcp-haslo-wynik" role="status"></span></p>

    <h3>3. Claude Desktop na macOS</h3>
    <ol class="tl-mcp-lista">
        <li>Zainstaluj <a href="https://nodejs.org/" target="_blank" rel="noopener">Node.js</a> (wersja LTS) — Claude Desktop uruchamia nim pośrednika
            <code>@automattic/mcp-wordpress-remote</code>.</li>
        <li>W Claude Desktop: <strong>Settings → Developer → Edit Config</strong>. Otworzy się plik
            <code>~/Library/Application Support/Claude/claude_desktop_config.json</code>.</li>
        <li>Wklej konfigurację (gdy plik ma już <code>mcpServers</code>, dopisz tylko wpis serwera) i uruchom Claude Desktop ponownie.</li>
    </ol>
    <div class="evo-field">
        <label for="tl-mcp-npx">Pełna ścieżka do npx (gdy Claude Desktop nie znajduje Node)</label>
        <input type="text" id="tl-mcp-npx" spellcheck="false" placeholder="np. /usr/local/bin/npx — wynik polecenia: which npx">
        <p class="evo-desc">Puste — samo <code>npx</code>. Ze ścieżką konfiguracja dostaje ją w <code>command</code> i katalog Node w <code>PATH</code>.</p>
    </div>
    <label for="tl-mcp-konfiguracja">Konfiguracja</label>
    <textarea id="tl-mcp-konfiguracja" class="large-text code tl-mcp-json" rows="14" readonly><?php
        echo esc_textarea(evk_tl_mcp_konfiguracja($evk_m_user->user_login, 'HASŁO APLIKACJI Z KROKU 2')); ?></textarea>
    <p><button type="button" class="button" id="tl-mcp-kopiuj">Kopiuj konfigurację</button>
    <span class="tl-mcp-wynik" id="tl-mcp-kopiuj-wynik" role="status"></span></p>
    <p class="evo-desc">Adres serwera: <code><?php echo esc_html(evk_tl_mcp_adres()); ?></code>. To ten sam serwer, którego używa Bricks
    (zakładka AI Bricksa) — gdy jest już w konfiguracji, nie dodawaj drugiego: narzędzia Evoke pojawią się w nim same.</p>

    <details class="tl-mcp-pomoc">
        <summary>Gdy serwer w Claude Desktop ma stan „Failed” albo „Server disconnected”</summary>
        <p class="evo-desc">Log: <code>~/Library/Logs/Claude/mcp-server-wordpress-….log</code> (w Terminalu: <code>tail -40</code> i ścieżka).</p>
        <ul class="tl-mcp-lista">
            <li><code>Failed to spawn process: No such file or directory</code> — w <code>command</code> jest ścieżka, której nie ma.
                W Terminalu <code>which npx</code> i wpisz wynik w polu wyżej.</li>
            <li><code>dyld: Library not loaded … /usr/local/bin/node</code> — Node z Homebrew stracił bibliotekę po aktualizacji.
                <code>brew reinstall node</code> albo instalator LTS z nodejs.org.</li>
            <li><code>env: node: No such file or directory</code> — Claude Desktop nie widzi katalogu Node: wpisz pełną ścieżkę do npx w polu wyżej.</li>
            <li>401 albo <code>rest_forbidden</code> — złe hasło aplikacji albo login; utwórz nowe hasło (krok 2).</li>
            <li>Po każdej zmianie konfiguracji zamknij Claude Desktop całkiem (Cmd+Q) i otwórz ponownie.</li>
        </ul>
    </details>

    <h3>4. Polecenia</h3>
    <p class="evo-desc">W rozmowie: <strong>+ → Dodaj z „wordpress-…”</strong> — trzy gotowe polecenia: „Przetłumacz stronę”,
    „Przetłumacz wszystkie braki”, „Sprawdź tłumaczenia AI”. Albo własnymi słowami, np.:</p>
    <ul class="tl-mcp-lista">
        <li><em>Przetłumacz stronę „O nas” na angielski.</em></li>
        <li><em>Przetłumacz wszystkie brakujące teksty na niemiecki, trzymaj się słowniczka.</em></li>
        <li><em>Sprawdź tłumaczenia AI na angielski na stronie głównej i popraw te, które brzmią nienaturalnie.</em></li>
    </ul>
</div>

<style>
    .tl-mcp-lista { margin:8px 0 14px 18px; }
    .tl-mcp-lista li { margin:4px 0; }
    ul.tl-mcp-lista { list-style:none; margin-left:0; }
    .tl-mcp-wym[data-ok="1"] { color:var(--evo-on-dark, #166534); }
    .tl-mcp-wym[data-ok="0"] { color:#b42318; }
    .tl-mcp-json { font-size:13px; white-space:pre; overflow-x:auto; }
    .tl-mcp-wynik { margin-left:8px; }
    .tl-mcp-pomoc { margin:12px 0 16px; }
    .tl-mcp-pomoc summary { cursor:pointer; font-weight:600; min-height:24px; }
    @media (max-width: 782px) { .tl-mcp-json { font-size:16px; } .tl-mcp-wynik { display:block; margin:8px 0 0; } }
</style>

<script>
(function () {
    var AJAX = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    var box = document.querySelector('.tl-mcp');
    if (!box) return;
    var NONCE = box.getAttribute('data-nonce');
    function wyslij(akcja) {
        var fd = new FormData();
        fd.append('action', akcja);
        fd.append('nonce', NONCE);
        return fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function wynik(id, tekst, blad) {
        var e = document.getElementById(id);
        if (!e) return;
        e.textContent = tekst;
        e.style.color = blad ? '#b42318' : '';
    }
    var adapter = document.getElementById('tl-mcp-adapter');
    if (adapter) adapter.addEventListener('click', function () {
        adapter.disabled = true;
        wynik('tl-mcp-adapter-wynik', 'Instaluję…');
        wyslij('evk_tl_mcp_adapter').then(function (r) {
            if (!r || !r.success) { adapter.disabled = false; wynik('tl-mcp-adapter-wynik', (r && r.data) || 'Błąd.', true); return; }
            wynik('tl-mcp-adapter-wynik', r.data.komunikat);
            var stan = document.getElementById('tl-mcp-adapter-stan');
            if (stan) { stan.setAttribute('data-ok', '1'); stan.textContent = '✓ ' + r.data.komunikat; }
            adapter.remove();
        }).catch(function () { adapter.disabled = false; wynik('tl-mcp-adapter-wynik', 'Błąd połączenia.', true); });
    });
    var haslo = document.getElementById('tl-mcp-haslo');
    if (haslo) haslo.addEventListener('click', function () {
        haslo.disabled = true;
        wyslij('evk_tl_mcp_haslo').then(function (r) {
            if (!r || !r.success) { haslo.disabled = false; wynik('tl-mcp-haslo-wynik', (r && r.data) || 'Błąd.', true); return; }
            document.getElementById('tl-mcp-konfiguracja').value = r.data.konfiguracja;
            przepiszSciezke();
            wynik('tl-mcp-haslo-wynik', 'Hasło utworzone i wpisane w konfigurację (krok 3). Skopiuj ją teraz — drugi raz się nie pokaże.');
        }).catch(function () { haslo.disabled = false; wynik('tl-mcp-haslo-wynik', 'Błąd połączenia.', true); });
    });
    /* Pełna ścieżka do npx (1.280.0): `command` i katalog Node na początku PATH. */
    var npx = document.getElementById('tl-mcp-npx');
    var konfiguracja = document.getElementById('tl-mcp-konfiguracja');
    function przepiszSciezke() {
        var d;
        try { d = JSON.parse(konfiguracja.value); } catch (e) { return; }
        var nazwa = Object.keys(d.mcpServers || {})[0];
        if (!nazwa) return;
        var s = d.mcpServers[nazwa], p = (npx.value || '').trim();
        s.command = p || 'npx';
        if (p && p.indexOf('/') !== -1) s.env.PATH = p.replace(/\/[^\/]*$/, '') + ':/usr/bin:/bin';
        else delete s.env.PATH;
        konfiguracja.value = JSON.stringify(d, null, 4);
    }
    if (npx) npx.addEventListener('input', przepiszSciezke);
    var kopiuj = document.getElementById('tl-mcp-kopiuj');
    if (kopiuj) kopiuj.addEventListener('click', function () {
        var pole = document.getElementById('tl-mcp-konfiguracja');
        var zrobione = function () { wynik('tl-mcp-kopiuj-wynik', 'Skopiowano.'); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(pole.value).then(zrobione, function () { pole.select(); document.execCommand('copy'); zrobione(); });
        } else { pole.select(); document.execCommand('copy'); zrobione(); }
    });
})();
</script>
