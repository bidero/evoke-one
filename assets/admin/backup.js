/* Evoke ONE — Kopie zapasowe: kopia teraz, postęp, lista, test pracy w tle.
 *
 * Bez jQuery: fetch + FormData do admin-ajax. Odpytywanie stanu co 1 s —
 * serwer przy okazji POPYCHA zadanie, którego od kilku sekund nikt nie ruszył
 * (ajax.php, evk_backup_status), więc przy otwartej karcie kopia idzie dalej
 * nawet wtedy, gdy żądania serwera do siebie są blokowane. */
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
    var timer     = null;

    function post(action, dane) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', evkBackup.nonce);
        Object.keys(dane || {}).forEach(function (k) { fd.append(k, dane[k]); });
        return fetch(evkBackup.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }

    function komunikat(tekst, rodzaj) {
        if (!msg) return;
        msg.hidden = !tekst;
        msg.className = 'evo-info-box evo-mt' + (rodzaj ? ' is-' + rodzaj : '');
        msg.querySelector('div').textContent = tekst || '';
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

    function trwa(job) { return job && (job.status === 'queued' || job.status === 'running'); }

    function ustawPrzyciski(praca) {
        if (btnStart) btnStart.disabled = praca;
        if (btnCancel) btnCancel.hidden = !praca;
    }

    function odswiezListe(html) {
        if (lista && typeof html === 'string') lista.innerHTML = html;
    }

    function odpytuj() {
        clearTimeout(timer);
        post('evk_backup_status', { id: jobId }).then(function (r) {
            if (!r || !r.success) { komunikat('Nie udało się odczytać stanu kopii.', 'warn'); ustawPrzyciski(false); return; }
            var job = r.data.job;
            pokaz(job);
            /* Co sekundę: silnik zapisuje postęp po każdej porcji (≤ 1 s),
               więc rzadsze odpytywanie gubiłoby zmiany paska. */
            if (trwa(job)) { timer = setTimeout(odpytuj, 1000); return; }
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
        if (!jobId || !window.confirm('Anulować tworzenie kopii? Niedokończone archiwum zostanie usunięte.')) return;
        post('evk_backup_cancel', { id: jobId }).then(function () { odpytuj(); });
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
        if (e.target.closest('[data-evk-backup-delete]')) {
            if (!window.confirm('Usunąć kopię ' + nazwa + '? Tego nie da się cofnąć.')) return;
            post('evk_backup_delete', { archive: nazwa })
                .then(function (r) { if (r && r.success) odswiezListe(r.data.html); });
        }
    });

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
