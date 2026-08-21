/**
 * EVK Horizontal Scroll v1.1.0
 */
(function () {
	'use strict';

	/* Biblioteki jadą Z WŁASNEGO SERWERA — adres katalogu podaje PHP
	   (evk_gsap_url() w includes/89-gsap.php) tuż przed samym GSAP-em.
	   Wcześniej stały tu wpisane na sztywno adresy cdnjs.

	   Ta ścieżka jest awaryjna: skrypt deklaruje `evk-gsap` i `evk-scrolltrigger`
	   jako zależności, więc obie biblioteki są już na stronie. Zostaje na
	   wypadek wpięcia skryptu z ręki. */

	function loadScript(src, cb) {
		var existing = document.querySelector('script[src="' + src + '"]');
		if (existing) {
			if (existing.dataset.loaded) { cb(); }
			else { existing.addEventListener('load', cb); }
			return;
		}
		var s = document.createElement('script');
		s.src = src;
		s.addEventListener('load', function () { s.dataset.loaded = '1'; cb(); });
		document.head.appendChild(s);
	}

	function initHScroll(root) {
		if (root.dataset.evkInit) { return; }
		root.dataset.evkInit = '1';

		var raw = root.dataset.evkHscroll;
		if (!raw) { return; }
		var cfg;
		try { cfg = JSON.parse(raw); } catch (e) { return; }

		var track = root.querySelector('.evk-hscroll__track');
		if (!track) { return; }

		var panels = Array.prototype.slice.call(track.children);
		if (panels.length < 2) { return; }

		var C = {
			widthMode    : cfg.widthMode || 'fill',
			pinTarget    : cfg.pinTarget || 'self',
			pinSelector  : cfg.pinSelector || '',
			progressStyle: cfg.progressStyle || 'bar',
			scrub        : (cfg.scrub == null) ? 1 : (parseFloat(cfg.scrub) === 0 ? true : parseFloat(cfg.scrub)),
			startOffset  : cfg.startOffset || 'top top',
			snap         : cfg.snap !== false,
			snapDuration : parseFloat(cfg.snapDuration) || 0.5,
			disableBelow : parseInt(cfg.disableBelow, 10) || 0,
			progressBar  : cfg.progressBar === true,
		};

		/**
		 * Co przypinamy do ekranu.
		 *
		 * Domyślnie sam element — tak działał do 1.100.0 i tak zostaje każdemu,
		 * kto niczego nie zmieniał. Nowe jest przypinanie PRZODKA: sekcji
		 * z nagłówkiem, pod którym jedzie taśma kart. Nagłówek stoi wtedy
		 * w miejscu, a rusza się tylko taśma.
		 *
		 * `closest()`, NIE `document.querySelector()`. Chodzi o „mój przodek",
		 * a nie o „pierwszy taki element na stronie": przy dwóch takich sekcjach
		 * querySelector przypiąłby obu taśmom tę samą, pierwszą sekcję, i druga
		 * sekcja skakałaby przy przewijaniu pierwszej.
		 */
		function celPinu() {
			if (C.pinTarget === 'parent') {
				return root.parentElement || root;
			}
			if (C.pinTarget === 'selector' && C.pinSelector) {
				var znaleziony = root.closest(C.pinSelector);
				if (znaleziony) { return znaleziony; }
				/* Cisza byłaby tu najgorsza: element działa dalej, tylko przypina
				   nie to, co trzeba, i nie ma tego jak zgadnąć z ekranu. */
				console.warn('[EVK Horizontal Scroll] Żaden przodek nie pasuje do selektora '
					+ '„' + C.pinSelector + '" — przypinam sam element.', root);
			}
			return root;
		}

		var pinEl = celPinu();

		// Pasek postępu (opcjonalny).
		var progress = C.progressBar ? root.querySelector('.evk-hscroll__progress') : null;
		var bar      = (progress && C.progressStyle !== 'segments')
			? progress.querySelector('.evk-hscroll__progress-bar') : null;

		/**
		 * Segmenty wskaźnika — po jednym na panel, budowane W JS.
		 *
		 * PHP musiałoby liczyć panele z drzewa dzieci Bricksa i zgadywać, które
		 * z nich naprawdę wylądują na taśmie. Tutaj liczba jest już znana i jest
		 * prawdziwa.
		 */
		var segmenty = null;
		if (progress && C.progressStyle === 'segments') {
			progress.classList.add('evk-hscroll__progress--segments');
			var pasek = progress.querySelector('.evk-hscroll__progress-bar');
			if (pasek) { pasek.remove(); }
			segmenty = panels.map(function () {
				var seg = document.createElement('span');
				seg.className = 'evk-hscroll__progress-seg';
				progress.appendChild(seg);
				return seg;
			});
		}

		/** Który segment jest aktywny przy danym postępie. */
		function odswiezSegmenty(postep) {
			if (!segmenty || !segmenty.length) { return; }
			// `min` na końcu: przy postępie równym 1 indeks wyszedłby poza tablicę
			// i ostatnia karta zostawałaby bez podświetlenia.
			var i = Math.min(Math.floor(postep * segmenty.length), segmenty.length - 1);
			segmenty.forEach(function (seg, j) {
				seg.classList.toggle('is-active', j === i);
			});
		}

		gsap.registerPlugin(ScrollTrigger);

		function setWidths() {
			// Tryb „z buildera": ani szerokości, ani wysokości. Karty stylujesz
			// w Bricksie, a skrypt liczy tylko, o ile przesunąć taśmę.
			if (C.widthMode === 'auto') {
				return;
			}
			if (C.widthMode === 'viewport') {
				panels.forEach(function (p) { p.style.width = '100vw'; });
			} else {
				var w = root.clientWidth;
				panels.forEach(function (p) { p.style.width = w + 'px'; });
			}
		}

		function getAmount() {
			return Math.max(0, track.scrollWidth - root.clientWidth);
		}

		var mm = gsap.matchMedia();
		var query = '(min-width: ' + (C.disableBelow > 0 ? C.disableBelow : 0) + 'px)';

		// Redukcja ruchu wpina się w istniejący mechanizm zamiast obok niego:
		// dopisana klauzula sprawia, że blok się nie uruchamia, a gsap.matchMedia
		// sam posprząta po pinie — treść wraca do pionowego przepływu i pozostaje
		// w całości dostępna. Wspólna polityka: includes/anim/motion.php.
		if (!window.evkMotion || window.evkMotion.respect !== false) {
			query += ' and (prefers-reduced-motion: no-preference)';
		}

		mm.add(query, function () {
			root.classList.add('evk-hscroll--active');
			// Klasa wyłącza w arkuszu narzucanie wysokości korzeniowi, taśmie
			// i panelom — w tym trybie rozmiary należą do buildera.
			if (C.widthMode === 'auto') { root.classList.add('evk-hscroll--auto'); }
			setWidths();

			var onRefreshInit = function () { setWidths(); };
			ScrollTrigger.addEventListener('refreshInit', onRefreshInit);

			var snapCfg = false;
			if (C.snap) {
				var amt = getAmount();
				var points = panels.map(function (p) {
					return amt ? Math.min(p.offsetLeft / amt, 1) : 0;
				});
				snapCfg = {
					snapTo   : points,
					duration : { min: 0.15, max: C.snapDuration },
					ease     : 'power1.inOut'
				};
			}

			gsap.to(track, {
				x    : function () { return -getAmount(); },
				ease : 'none',
				scrollTrigger: {
					/* Wyzwalacz TAM, GDZIE PIN — i to nie jest kosmetyka. Zostawiony
					   na taśmie ruszałby dopiero, gdy jej górna krawędź dojedzie do
					   góry ekranu, czyli po przewinięciu nagłówka poza kadr. */
					trigger            : pinEl,
					start              : C.startOffset,
					end                : function () { return '+=' + getAmount(); },
					/* Jawnie `pinEl`, choć przy dzisiejszym wyzwalaczu `true` znaczy
					   dokładnie to samo — sprawdzone mutacją, żadne sprawdzenie ich
					   nie odróżnia. Forma jawna zostaje, bo trzyma pin przy właściwym
					   elemencie NIEZALEŻNIE od wyzwalacza: gdyby ten kiedyś wrócił na
					   taśmę, `true` po cichu przypięłoby taśmę. */
					pin                : pinEl,
					anticipatePin      : 1,
					scrub              : C.scrub,
					snap               : snapCfg,
					invalidateOnRefresh: true,
					onUpdate           : (bar || segmenty) ? function (self) {
						if (bar) { bar.style.transform = 'scaleX(' + self.progress + ')'; }
						odswiezSegmenty(self.progress);
					} : undefined,
				}
			});

			return function () {
				ScrollTrigger.removeEventListener('refreshInit', onRefreshInit);
				root.classList.remove('evk-hscroll--active', 'evk-hscroll--auto');
				panels.forEach(function (p) { p.style.width = ''; });
				if (bar) { bar.style.transform = ''; }
				if (segmenty) { segmenty.forEach(function (s) { s.classList.remove('is-active'); }); }
			};
		});
	}

	function run() {
		gsap.registerPlugin(ScrollTrigger);
		document.querySelectorAll('.evk-hscroll[data-evk-hscroll]').forEach(initHScroll);
		/* Przez wspólny helper: `refresh()` zapisuje pozycję przewijania, a na iOS
		   zapis w trakcie bezwładności ją kasuje — includes/89-gsap.php. */
		window.addEventListener('load', function () {
			if (window.evkOdswiez) window.evkOdswiez();
			else ScrollTrigger.refresh();
		});
	}

	function boot() {
		if (typeof gsap !== 'undefined' && typeof ScrollTrigger !== 'undefined') {
			run();
			return;
		}

		var base = window.evkGsapBase;
		/* Bez adresu nie ma czego dociągnąć. Zgadywanie ścieżki względnej dałoby
		   żądanie pod adres, którego nie ma, i ciszę w konsoli zamiast wskazówki. */
		if (!base) {
			console.warn('[EVK Horizontal Scroll] Brak GSAP-a i brak window.evkGsapBase — '
				+ 'skrypt wpięty poza kolejką WordPressa?');
			return;
		}

		if (typeof gsap !== 'undefined') {
			loadScript(base + 'ScrollTrigger.min.js', run);
		} else {
			loadScript(base + 'gsap.min.js', function () {
				loadScript(base + 'ScrollTrigger.min.js', run);
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
