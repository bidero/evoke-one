/**
 * Evoke ONE — Narzędzia: rewizje.
 *
 * Dwa kroki, i kolejność jest tu istotą ekranu: najpierw „policz, ile zniknie",
 * dopiero potem pojawia się „skasuj". Przycisk kasowania narysowany od razu
 * byłby przyciskiem „skasuj nie wiadomo ile", a tego nie da się cofnąć.
 *
 * Kasowanie leci PARTIAMI. Na stronie z kilkudziesięcioma tysiącami rewizji
 * jedno żądanie nie ma szans dobiec do końca, więc skrypt wysyła kolejne, aż
 * serwer powie, że nie zostało nic — pokazując po drodze, ile już zniknęło.
 */
(function () {
    'use strict';

    var box = document.querySelector('.evo-rewizje');
    if (!box) return;

    var cfg   = window.evkRewizje || {};
    var wynik = box.querySelector('.evo-rewizje-podsumowanie');
    var pole  = box.querySelector('#evk-rewizje-zostaw');

    function zaznaczone() {
        return Array.prototype.map.call(
            box.querySelectorAll('.evk-rewizje-typ:checked'),
            function (i) { return i.value; }
        );
    }

    function zostaw() { return Math.max(0, parseInt(pole.value, 10) || 0); }

    /** Wysyła żądanie do panelu. `typy` jako tablica — stąd `typy[]` w kluczu. */
    function wyslij(akcja, dane) {
        var body = new FormData();
        body.append('action', akcja);
        body.append('nonce', box.dataset.nonce);
        body.append('zostaw', dane.zostaw);
        dane.typy.forEach(function (t) { body.append('typy[]', t); });
        return fetch(cfg.ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (o) { return o.json(); });
    }

    function odmiana(n, jedna, kilka, wiele) {
        if (n === 1) return jedna;
        var d = n % 10, s = n % 100;
        return (d >= 2 && d <= 4 && (s < 10 || s >= 20)) ? kilka : wiele;
    }

    /** Odświeża liczby w tabeli po skasowaniu partii. */
    function odswiezTabele(przeglad) {
        if (!przeglad) return;
        var wg = {};
        przeglad.forEach(function (w) { wg[w.typ] = w.ile; });
        Array.prototype.forEach.call(box.querySelectorAll('tr[data-typ]'), function (tr) {
            var k = tr.querySelector('.evk-rewizje-ile');
            if (k) k.textContent = (wg[tr.dataset.typ] || 0).toLocaleString('pl-PL');
        });
    }

    // ── Krok 1: policz ─────────────────────────────────────────────────────
    box.addEventListener('click', function (e) {
        if (!e.target.classList.contains('evk-rewizje-policz')) return;

        var typy = zaznaczone();
        if (!typy.length) {
            wynik.innerHTML = '<p class="evo-hint">Nie zaznaczono żadnego typu.</p>';
            wynik.hidden = false;
            return;
        }

        wynik.innerHTML = '<p class="evo-hint">Liczenie…</p>';
        wynik.hidden = false;

        wyslij('evk_rewizje_podsumowanie', { typy: typy, zostaw: zostaw() })
            .then(function (odp) {
                if (!odp || !odp.success) {
                    wynik.innerHTML = '<p class="evo-hint">Nie udało się policzyć.</p>';
                    return;
                }
                var n = odp.data.razem || 0;
                if (!n) {
                    wynik.innerHTML = '<p class="evo-wersja-info">Nie ma czego kasować — '
                        + 'przy tych typach nic nie przekracza zadanej liczby.</p>';
                    return;
                }
                /* Liczba stoi w zdaniu i w przycisku. Przycisk „Skasuj" bez
                   liczby wyglądałby tak samo niezależnie od tego, czy zniknie
                   dziesięć rewizji, czy czterdzieści tysięcy. */
                wynik.innerHTML =
                    '<div class="notice notice-warning inline evo-mb"><p><strong>Zniknie '
                    + n.toLocaleString('pl-PL') + ' ' + odmiana(n, 'rewizja', 'rewizje', 'rewizji')
                    + '.</strong> Tego nie da się cofnąć.</p></div>'
                    + '<button type="button" class="button button-primary evk-rewizje-kasuj">'
                    + 'Skasuj ' + n.toLocaleString('pl-PL') + ' '
                    + odmiana(n, 'rewizję', 'rewizje', 'rewizji') + '</button>'
                    + '<span class="evo-rewizje-postep evo-hint"></span>';
            })
            .catch(function () {
                wynik.innerHTML = '<p class="evo-hint">Nie udało się policzyć.</p>';
            });
    });

    // ── Krok 2: kasuj, partia po partii ────────────────────────────────────
    box.addEventListener('click', function (e) {
        if (!e.target.classList.contains('evk-rewizje-kasuj')) return;

        var przycisk = e.target;
        var typy     = zaznaczone();
        var ile      = zostaw();
        var postep   = wynik.querySelector('.evo-rewizje-postep');
        var razem    = 0;

        if (!window.confirm('Skasować rewizje? Tego nie da się cofnąć.')) return;

        przycisk.disabled = true;

        function partia() {
            return wyslij('evk_rewizje_kasuj', { typy: typy, zostaw: ile }).then(function (odp) {
                if (!odp || !odp.success) throw new Error('odmowa');
                razem += odp.data.skasowane || 0;
                odswiezTabele(odp.data.przeglad);
                postep.textContent = 'Skasowano ' + razem.toLocaleString('pl-PL')
                                   + ', zostało ' + (odp.data.zostalo || 0).toLocaleString('pl-PL') + '…';
                /* Warunek końca to `skasowane === 0`, a nie `zostalo === 0`.
                   Gdyby serwer z jakiegokolwiek powodu przestał kasować,
                   pętla oparta na „zostało" chodziłaby w kółko bez końca. */
                if ((odp.data.skasowane || 0) > 0 && (odp.data.zostalo || 0) > 0) return partia();
                return razem;
            });
        }

        partia()
            .then(function (n) {
                wynik.innerHTML = '<p class="evo-wersja-info">Gotowe. Skasowanych rewizji: '
                                + n.toLocaleString('pl-PL') + '.</p>';
            })
            .catch(function () {
                przycisk.disabled = false;
                postep.textContent = ' Przerwane — skasowano ' + razem.toLocaleString('pl-PL') + '.';
            });
    });
})();
