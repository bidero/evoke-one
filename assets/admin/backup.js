/* Evoke ONE — Kopie zapasowe: kopia teraz, przywracanie, postęp, lista,
 * test pracy w tle.
 *
 * Bez jQuery: fetch + FormData do admin-ajax. Odpytywanie stanu co 1 s —
 * serwer przy okazji POPYCHA zadanie, którego od kilku sekund nikt nie ruszył
 * (ajax.php, evk_backup_status), więc przy otwartej karcie kopia idzie dalej
 * nawet wtedy, gdy żądania serwera do siebie są blokowane.
 *
 * PRZYWRACANIE pyta o stan TOKENEM zadania, nie sesją: podmiana bazy
 * podmienia użytkowników, więc od tej chwili nikt tu nie jest zalogowany,
 * a pasek ma dojść do końca (ajax.php, evk_restore_status_handler). */
(function () {
    'use strict';
    if (typeof evkBackup === 'undefined') return;

    var $ = function (sel, root) { return (root || document).querySelector(sel); };
    var btnStart  = $('[data-evk-backup-start]');
    var btnCancel = $('[data-evk-backup-cancel]');
    var box       = $('[data-evk-backup-progress]');
    var msg       = $('[data-evk-backup-msg]');
    var lista     = $('[data-evk-backup-list]');
    var jobId     = 0;
    var token     = evkBackup.token || '';
    var timer     = null;

    function post(action, dane) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', evkBackup.nonce);
        Object.keys(dane || {}).forEach(function (k) { fd.append(k, dane[k]); });
        return fetch(evkBackup.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }

    function komunikat(tekst, rodzaj, link) {
        if (!msg) return;
        msg.hidden = !tekst;
        msg.className = 'evo-info-box evo-mt' + (rodzaj ? ' is-' + rodzaj : '');
        var cel = msg.querySelector('div');
        cel.textContent = tekst || '';
        if (link) {
            var a = document.createElement('a');
            a.href = link.href;
            a.textContent = link.tekst;
            a.setAttribute('data-evk-backup-login', '');
            cel.appendChild(document.createTextNode(' '));
            cel.appendChild(a);
        }
    }

    function pokaz(job) {
        if (!job || !box) return;
        box.hidden = false;
        $('[data-evk-backup-label]').textContent = job.label.charAt(0).toUpperCase() + job.label.slice(1);
        $('[data-evk-backup-step]').textContent = job.status === 'done' ? '' : 'etap ' + job.step + ' z ' + job.steps;
        var pasek = $('[data-evk-backup-bar]');
        pasek.firstElementChild.style.width = job.percent + '%';
        pasek.setAttribute('aria-valuenow', String(job.percent));
        $('[data-evk-backup-percent]').textContent = job.percent + '%' + (job.detail ? ' · ' + job.detail : '')
            + ' · kroków: ' + job.ticks + ' · długość kroku: ' + job.budget_s + ' s';
        $('[data-evk-backup-log]').textContent = (job.log || []).join('\n');
    }

    function trwa(job) { return job && (job.status === 'queued' || job.status === 'running' || job.status === 'waiting'); }

    function ustawPrzyciski(praca) {
        if (btnStart) btnStart.disabled = praca;
        if (btnCancel) btnCancel.hidden = !praca;
    }

    function odswiezListe(html) {
        if (lista && typeof html === 'string') lista.innerHTML = html;
    }

    function koniecPrzywracania(job) {
        token = '';
        if (job.status === 'done' && job.scope !== 'files') {
            // Sesja zniknęła razem ze starą bazą — przyciski i tak by nie zadziałały.
            if (btnStart) btnStart.disabled = true;
            if (lista) lista.hidden = true;
            komunikat('Kopia przywrócona. Zaloguj się kontem ze strony z kopii.', 'ok',
                { href: evkBackup.login, tekst: 'Przejdź do logowania' });
            return;
        }
        ustawPrzyciski(false);
        if (job.status === 'done') {
            post('evk_backup_list').then(function (l) { if (l && l.success) odswiezListe(l.data.html); });
            komunikat('Pliki przywrócone z kopii.', 'ok');
        } else if (job.status === 'failed') {
            komunikat('Przywracanie nie powiodło się: ' + job.error, 'err');
        } else if (job.status === 'cancelled') {
            komunikat('Przywracanie anulowane — strona bez zmian.', '');
        }
    }

    function odpytuj() {
        clearTimeout(timer);
        var zapytanie = token ? post('evk_backup_restore_status', { id: jobId, token: token }) : post('evk_backup_status', { id: jobId });
        zapytanie.then(function (r) {
            if (!r || !r.success) { komunikat('Nie udało się odczytać stanu zadania.', 'warn'); ustawPrzyciski(false); return; }
            var job = r.data.job;
            pokaz(job);
            /* Co sekundę: silnik zapisuje postęp po każdej porcji (≤ 1 s),
               więc rzadsze odpytywanie gubiłoby zmiany paska. */
            if (trwa(job)) { timer = setTimeout(odpytuj, 1000); return; }
            if (job.type === 'restore') { koniecPrzywracania(job); return; }
            ustawPrzyciski(false);
            if (job.status === 'done') {
                /* Najpierw lista, potem komunikat — pojawiają się razem, zamiast
                   „Kopia gotowa" nad listą, w której kopii jeszcze nie ma. */
                post('evk_backup_list').then(function (l) {
                    if (l && l.success) odswiezListe(l.data.html);
                    komunikat('Kopia gotowa: ' + job.archive, 'ok');
                }, function () { komunikat('Kopia gotowa: ' + job.archive, 'ok'); });
            } else if (job.status === 'failed') {
                komunikat('Kopia nie powiodła się: ' + job.error, 'err');
            } else if (job.status === 'cancelled') {
                komunikat('Kopia anulowana.', '');
            }
        }).catch(function () { timer = setTimeout(odpytuj, 4000); });
    }

    if (btnStart) btnStart.addEventListener('click', function () {
        ustawPrzyciski(true);
        komunikat('');
        post('evk_backup_start').then(function (r) {
            if (!r || !r.success) { komunikat((r && r.data && r.data.msg) || 'Nie udało się rozpocząć kopii.', 'err'); ustawPrzyciski(false); return; }
            jobId = r.data.job.id;
            pokaz(r.data.job);
            timer = setTimeout(odpytuj, 700);
        });
    });

    if (btnCancel) btnCancel.addEventListener('click', function () {
        var pytanie = token ? 'Anulować przywracanie? Strona zostanie bez zmian.'
            : 'Anulować tworzenie kopii? Niedokończone archiwum zostanie usunięte.';
        if (!jobId || !window.confirm(pytanie)) return;
        post('evk_backup_cancel', { id: jobId }).then(function (r) {
            // Przywracanie, które podmienia już pliki albo bazę, odmawia — przerwane zostawiłoby stronę w połowie.
            if (r && !r.success) komunikat(r.data && r.data.msg ? r.data.msg : 'Tego zadania nie da się już anulować.', 'warn');
            odpytuj();
        });
    });

    // Lista: przypnij / usuń (delegacja — lista jest podmieniana w całości).
    if (lista) lista.addEventListener('click', function (e) {
        var wiersz = e.target.closest('tr[data-archive]');
        if (!wiersz) return;
        var nazwa = wiersz.getAttribute('data-archive');
        var pin = e.target.closest('[data-evk-backup-pin]');
        if (pin) {
            post('evk_backup_pin', { archive: nazwa, pinned: pin.getAttribute('data-evk-backup-pin') === '1' ? 1 : '' })
                .then(function (r) { if (r && r.success) odswiezListe(r.data.html); });
            return;
        }
        if (e.target.closest('[data-evk-backup-restore]')) { otworzPrzywracanie(nazwa); return; }
        if (e.target.closest('[data-evk-backup-delete]')) {
            if (!window.confirm('Usunąć kopię ' + nazwa + '? Tego nie da się cofnąć.')) return;
            post('evk_backup_delete', { archive: nazwa })
                .then(function (r) { if (r && r.success) odswiezListe(r.data.html); });
        }
    });

    // ── Okno przywracania ──────────────────────────────────────────────────
    var dlg = $('[data-evk-restore-dialog]');
    var dlgArchiwum = '';
    var dlgGotowe = false;

    function pozycja(dl, etykieta, wartosc, klasa) {
        var dt = document.createElement('dt');
        dt.textContent = etykieta;
        var dd = document.createElement('dd');
        dd.textContent = wartosc;
        if (klasa) dd.className = klasa;
        dl.appendChild(dt);
        dl.appendChild(dd);
    }

    function zakres() {
        var z = dlg.querySelector('input[name="evk-restore-scope"]:checked');
        return z ? z.value : 'all';
    }

    function odswiezOkno() {
        var z = zakres();
        var lustro = $('[data-evk-restore-mirror]', dlg);
        lustro.disabled = z === 'db';
        if (z === 'db') lustro.checked = false;
        $('[data-evk-restore-warning]', dlg).textContent = z === 'files'
            ? 'Pliki w wp-content zostaną podmienione na te z kopii. Baza i logowanie bez zmian.'
            : 'Przywrócenie bazy podmienia użytkowników i hasła — po zakończeniu zaloguj się kontem ze strony z kopii. Na czas podmiany strona jest w trybie konserwacji.';
        $('[data-evk-restore-go]', dlg).disabled = !(dlgGotowe && $('[data-evk-restore-confirm]', dlg).value.trim() === 'PRZYWRÓĆ');
    }

    function pokazInfo(d) {
        var info = $('[data-evk-restore-info]', dlg);
        info.textContent = '';
        var dl = document.createElement('dl');
        var data = d.created_at ? new Date(d.created_at) : null;
        pozycja(dl, 'Kopia', (data && !isNaN(data) ? data.toLocaleString('pl-PL') : d.created_at) + ' — ' + d.from_url);
        pozycja(dl, 'Przywracana na', d.to_url + (d.pairs ? ' (adresy i ścieżki zostaną podmienione)' : ''));
        if (d.from_prefix !== d.to_prefix) pozycja(dl, 'Prefiks tabel', d.from_prefix + ' → ' + d.to_prefix);
        if (d.from_wp !== d.to_wp) pozycja(dl, 'WordPress', d.from_wp + ' w kopii, ' + d.to_wp + ' tutaj');
        pozycja(dl, 'Zawartość', (d.has_db ? d.db_rows.toLocaleString('pl-PL') + ' wierszy bazy, ' : 'bez bazy, ')
            + d.files.toLocaleString('pl-PL') + ' plików (' + d.files_size + ')');
        if (d.root_files && d.root_files.length) {
            pozycja(dl, 'Katalog główny', d.root_files.join(', ') + ' — tylko do podglądu w archiwum, nie są przywracane');
        }
        if (d.missing_constants && d.missing_constants.length) {
            pozycja(dl, 'Brakujące stałe', 'W wp-config.php tego serwera nie ma: ' + d.missing_constants.join(', '), 'is-warn');
        }
        info.appendChild(dl);
        if (!d.has_db) {
            dlg.querySelectorAll('input[name="evk-restore-scope"]').forEach(function (r) { r.disabled = r.value !== 'files'; r.checked = r.value === 'files'; });
        }
        dlgGotowe = true;
        odswiezOkno();
    }

    function otworzPrzywracanie(nazwa) {
        if (!dlg) return;
        dlgArchiwum = nazwa;
        dlgGotowe = false;
        dlg.querySelectorAll('input[name="evk-restore-scope"]').forEach(function (r) { r.disabled = false; r.checked = r.value === 'all'; });
        $('[data-evk-restore-mirror]', dlg).checked = false;
        $('[data-evk-restore-snapshot]', dlg).checked = false;
        $('[data-evk-restore-confirm]', dlg).value = '';
        $('[data-evk-restore-info]', dlg).textContent = 'Czytam kopię ' + nazwa + '…';
        odswiezOkno();
        dlg.showModal();
        post('evk_backup_restore_info', { archive: nazwa }).then(function (r) {
            if (dlgArchiwum !== nazwa) return;
            if (!r || !r.success) {
                $('[data-evk-restore-info]', dlg).textContent = 'Tej kopii nie da się przywrócić: '
                    + ((r && r.data && r.data.msg) || 'nie udało się jej odczytać.');
                return;
            }
            pokazInfo(r.data);
        });
    }

    if (dlg) {
        dlg.addEventListener('input', odswiezOkno);
        dlg.addEventListener('change', odswiezOkno);
        $('[data-evk-restore-close]', dlg).addEventListener('click', function () { dlg.close(); });
        $('[data-evk-restore-go]', dlg).addEventListener('click', function () {
            var go = this;
            go.disabled = true;
            post('evk_backup_restore_start', {
                archive: dlgArchiwum, scope: zakres(), confirm: $('[data-evk-restore-confirm]', dlg).value.trim(),
                mirror: $('[data-evk-restore-mirror]', dlg).checked ? 1 : '',
                snapshot: $('[data-evk-restore-snapshot]', dlg).checked ? 1 : ''
            }).then(function (r) {
                if (!r || !r.success) {
                    $('[data-evk-restore-info]', dlg).textContent = (r && r.data && r.data.msg) || 'Nie udało się rozpocząć przywracania.';
                    return;
                }
                dlg.close();
                token = r.data.token;
                jobId = r.data.job.id;
                komunikat('');
                ustawPrzyciski(true);
                pokaz(r.data.job);
                if (box && box.scrollIntoView) box.scrollIntoView({ block: 'center' });
                timer = setTimeout(odpytuj, 700);
            });
        });
    }

    // Test pracy w tle: ile żyje żądanie serwera do samego siebie.
    var probe = $('[data-evk-backup-probe-start]');
    var probeWynik = $('[data-evk-backup-probe-result]');
    function wynikTla(tekst, rodzaj) {
        probeWynik.textContent = tekst;
        probeWynik.className = 'evk-backup-tlo-wynik' + (rodzaj ? ' is-' + rodzaj : '');
    }
    if (probe) probe.addEventListener('click', function () {
        probe.disabled = true;
        wynikTla('Test trwa…', '');
        post('evk_backup_probe_start').then(function (r) {
            if (!r || !r.success || !r.data.sent) {
                wynikTla('Serwer nie może wysłać żądania do samego siebie. Kopia i tak się zrobi — przez WP-Cron i otwartą kartę, wolniej.', 'warn');
                probe.disabled = false;
                return;
            }
            var koniec = r.data.seconds + 6;
            (function sprawdz() {
                post('evk_backup_probe_status').then(function (s) {
                    var d = s && s.data ? s.data : {};
                    wynikTla('Test trwa… praca w tle: ' + d.alive_s + ' s z ' + d.seconds + ' s.', '');
                    if (d.done || d.since_s >= koniec) {
                        probe.disabled = false;
                        if (d.done) {
                            wynikTla('Praca w tle działa: test przetrwał pełne ' + d.seconds + ' s. Kopia zrobi się także po zamknięciu karty.', 'ok');
                        } else if (d.alive_s < 2) {
                            wynikTla('Serwer przerwał pracę w tle po ' + d.alive_s + ' s. Kopia i tak się zrobi, ale wolniej — przez WP-Cron i otwartą kartę. Na LiteSpeed pomaga reguła noabort w .htaccess.', 'warn');
                        } else {
                            wynikTla('Praca w tle trwała ' + d.alive_s + ' s z ' + d.seconds + ' s. Kopia sama dopasuje długość kroków do tego serwera.', 'warn');
                        }
                        return;
                    }
                    setTimeout(sprawdz, 2000);
                });
            })();
        });
    });

    // Zadanie trwało przy wejściu na stronę — podejmujemy odpytywanie.
    if (trwa(evkBackup.job)) {
        jobId = evkBackup.job.id;
        ustawPrzyciski(true);
        pokaz(evkBackup.job);
        timer = setTimeout(odpytuj, 500);
    }
})();
