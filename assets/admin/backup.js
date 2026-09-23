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
    var msgSlot   = $('[data-evk-backup-msg-slot]');
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

    /* RAMKA KOMUNIKATU powstaje tu, dopiero z treścią, i znika przy pustej.
       Do 1.228.0 stała w HTML-u z atrybutem `hidden`, a chowała ją reguła
       w admin.css — na evoke.pl (zgłoszone zrzutem) była widoczna pusta od
       razu po wejściu, choć w testowym panelu miała display: none. Czego nie
       ma w DOM, tego żaden stary ani cudzy CSS nie odsłoni.
       `akcja`: {tekst, attr, href?, klik?} — przycisk po prawej, w jednej
       linii z ikoną i tekstem. */
    var IKONY = { ok: 'yes-alt', err: 'dismiss', warn: 'warning', '': 'info-outline' };
    function ramka(slot, attr, tekst, rodzaj, akcja) {
        if (!slot) return;
        var stara = slot.querySelector('[' + attr + ']');
        if (stara) stara.parentNode.removeChild(stara);
        if (!tekst) return;
        var r = document.createElement('div');
        r.className = 'evo-info-box evo-mt' + (rodzaj ? ' is-' + rodzaj : '') + (akcja ? ' evk-msg-z-akcja' : '');
        r.setAttribute(attr, '');
        r.setAttribute('role', rodzaj === 'err' ? 'alert' : 'status');
        var ik = document.createElement('span');
        ik.className = 'dashicons dashicons-' + (IKONY[rodzaj || ''] || IKONY['']);
        ik.setAttribute('aria-hidden', 'true');
        var tresc = document.createElement('div');
        tresc.className = 'evk-msg-tresc';
        var t = document.createElement('span');
        t.className = 'evk-msg-tekst';
        t.textContent = tekst;
        tresc.appendChild(t);
        if (akcja) {
            var b = document.createElement(akcja.href ? 'a' : 'button');
            b.className = 'button evk-msg-akcja';
            if (akcja.href) b.href = akcja.href; else b.type = 'button';
            b.setAttribute(akcja.attr, '');
            b.textContent = akcja.tekst;
            if (akcja.klik) b.addEventListener('click', akcja.klik);
            tresc.appendChild(b);
        }
        r.appendChild(ik);
        r.appendChild(tresc);
        slot.appendChild(r);
    }

    function komunikat(tekst, rodzaj, link) {
        ramka(msgSlot, 'data-evk-backup-msg', tekst, rodzaj,
            link ? { tekst: link.tekst, href: link.href, attr: 'data-evk-backup-login' } : null);
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

    // ── Wgrywanie kopii z komputera — kawałkami, wznawialne ────────────────
    /* Serwer dopisuje kawałek tylko na jego miejsce (offset = rozmiar części)
       i oddaje rzeczywisty offset, gdy się nie zgadza — wtedy przeskakujemy.
       Ten sam plik wybrany drugi raz ma ten sam identyfikator, więc wgrywanie
       rusza od miejsca przerwania (includes/backup/upload.php). */
    var wg = $('[data-evk-upload]');
    var wgPrzerwij = false;
    var wgId = '';
    function wgKomunikat(tekst, rodzaj, przywroc) {
        ramka($('[data-evk-upload-msg-slot]', wg), 'data-evk-upload-msg', tekst, rodzaj, przywroc ? {
            tekst: 'Przywróć teraz', attr: 'data-evk-upload-restore',
            klik: function () { otworzPrzywracanie(przywroc); }
        } : null);
    }
    function mb(b) { return (b / 1048576).toLocaleString('pl-PL', { maximumFractionDigits: 1 }) + ' MB'; }
    function wgPostep(plik, offset, t0, od) {
        var proc = plik.size ? Math.floor(100 * offset / plik.size) : 0;
        var bar = $('[data-evk-upload-bar]', wg);
        bar.firstElementChild.style.width = proc + '%';
        bar.setAttribute('aria-valuenow', String(proc));
        var s = (Date.now() - t0) / 1000;
        var tempo = s > 0.5 ? (offset - od) / s : 0;
        var reszta = tempo > 0 ? Math.round((plik.size - offset) / tempo) : 0;
        $('[data-evk-upload-detail]', wg).textContent = proc + '% · ' + mb(offset) + ' z ' + mb(plik.size)
            + (tempo > 0 ? ' · ' + mb(tempo) + '/s' + (reszta > 0 ? ' · zostało ok. ' + (reszta > 90 ? Math.round(reszta / 60) + ' min' : reszta + ' s') : '') : '');
    }
    function wgKawalek(plik, offset, proba) {
        var dl = Math.min(evkBackup.chunk || 1048576, plik.size - offset);
        var fd = new FormData();
        fd.append('action', 'evk_backup_upload_chunk');
        fd.append('nonce', evkBackup.nonce);
        fd.append('id', wgId);
        fd.append('offset', String(offset));
        fd.append('chunk', plik.slice(offset, offset + dl), 'kawalek');
        return fetch(evkBackup.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (r) {
                if (r && r.success) return r.data;
                if (r && r.data && r.data.retry && proba < 3) throw new Error('ponów');
                var e = new Error((r && r.data && r.data.msg) || 'Serwer odrzucił kawałek.');
                e.koniec = true;
                throw e;
            })
            .catch(function (e) {
                // Sieć albo chwilowy błąd serwera: do trzech ponowień z rosnącą przerwą.
                if (e.koniec || proba >= 3) throw e;
                return new Promise(function (ok) { setTimeout(ok, [1000, 3000, 8000][proba]); })
                    .then(function () { return wgKawalek(plik, offset, proba + 1); });
            });
    }
    function wgraj(plik) {
        wgPrzerwij = false;
        wgKomunikat('');
        $('[data-evk-upload-pick]', wg).disabled = true;
        $('[data-evk-upload-progress]', wg).hidden = false;
        $('[data-evk-upload-name]', wg).textContent = plik.name;
        $('[data-evk-upload-detail]', wg).textContent = 'Przygotowanie…';
        var koniec = function () { $('[data-evk-upload-pick]', wg).disabled = false; $('[data-evk-upload-progress]', wg).hidden = true; };
        post('evk_backup_upload_start', { name: plik.name, size: plik.size, mtime: Math.floor(plik.lastModified / 1000) }).then(function (r) {
            if (!r || !r.success) { koniec(); wgKomunikat((r && r.data && r.data.msg) || 'Nie udało się rozpocząć wgrywania.', 'err'); return; }
            wgId = r.data.id;
            evkBackup.chunk = r.data.chunk;
            var t0 = Date.now();
            var od = r.data.offset;
            if (od > 0) wgKomunikat('Wznawiam od ' + mb(od) + ' — ta część była już wgrana.', '');
            (function dalej(offset) {
                wgPostep(plik, offset, t0, od);
                if (wgPrzerwij) return;
                wgKawalek(plik, offset, 0).then(function (d) {
                    if (d.done) {
                        koniec();
                        if (typeof d.html === 'string') odswiezListe(d.html);
                        wgKomunikat('Kopia wgrana: ' + d.archive + '.', 'ok', d.archive);
                        return;
                    }
                    dalej(d.offset);   // przy niezgodności serwer podaje, skąd ciągnąć
                }, function (e) {
                    koniec();
                    wgKomunikat('Wgrywanie przerwane: ' + e.message + ' Wybierz ten sam plik jeszcze raz, żeby dokończyć.', 'err');
                });
            })(r.data.offset);
        }, function () { koniec(); wgKomunikat('Nie udało się rozpocząć wgrywania.', 'err'); });
    }
    if (wg) {
        var wgPlik = $('[data-evk-upload-file]', wg);
        $('[data-evk-upload-pick]', wg).addEventListener('click', function () { wgPlik.value = ''; wgPlik.click(); });
        wgPlik.addEventListener('change', function () { if (wgPlik.files && wgPlik.files[0]) wgraj(wgPlik.files[0]); });
        $('[data-evk-upload-cancel]', wg).addEventListener('click', function () {
            wgPrzerwij = true;
            post('evk_backup_upload_cancel', { id: wgId }).then(function () {
                $('[data-evk-upload-pick]', wg).disabled = false;
                $('[data-evk-upload-progress]', wg).hidden = true;
                wgKomunikat('Wgrywanie anulowane — wgrana część usunięta.', '');
            });
        });
    }

    // ── Katalog FTP: sprawdzenie bez przeładowania zakładki ────────────────
    var btnFtp = $('[data-evk-backup-ftp]');
    var ftpWynik = $('[data-evk-backup-ftp-result]');
    if (btnFtp) btnFtp.addEventListener('click', function () {
        btnFtp.disabled = true;
        ftpWynik.textContent = 'Sprawdzam…';
        post('evk_backup_list').then(function (r) {
            btnFtp.disabled = false;
            if (!r || !r.success) { ftpWynik.textContent = 'Nie udało się sprawdzić katalogu.'; return; }
            odswiezListe(r.data.html);
            var d = r.data;
            var tekst = d.moved.length ? 'Przeniesione na listę: ' + d.moved.join(', ') + '.' : 'Nowych kopii w katalogu nie ma.';
            if (d.waiting.length) tekst += ' Czeka (zmienione przed chwilą, mogą się jeszcze wgrywać): ' + d.waiting.join(', ') + ' — sprawdź za minutę.';
            ftpWynik.textContent = tekst;
        }, function () { btnFtp.disabled = false; ftpWynik.textContent = 'Nie udało się sprawdzić katalogu.'; });
    });

    // Domyślne wykluczenia — do pola; zapis jak każda zmiana, przyciskiem formularza.
    var btnDomyslne = $('[data-evk-backup-domyslne]');
    if (btnDomyslne) btnDomyslne.addEventListener('click', function () {
        var pole = $('#evk-backup-wykluczenia');
        if (!pole) return;
        pole.value = btnDomyslne.getAttribute('data-wartosc');
        pole.dispatchEvent(new Event('input', { bubbles: true }));
        pole.dispatchEvent(new Event('change', { bubbles: true }));
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
