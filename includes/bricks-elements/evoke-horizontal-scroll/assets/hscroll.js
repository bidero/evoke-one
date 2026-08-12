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
			scrub        : (cfg.scrub == null) ? 1 : (parseFloat(cfg.scrub) === 0 ? true : parseFloat(cfg.scrub)),
			startOffset  : cfg.startOffset || 'top top',
			snap         : cfg.snap !== false,
			snapDuration : parseFloat(cfg.snapDuration) || 0.5,
			disableBelow : parseInt(cfg.disableBelow, 10) || 0,
			progressBar  : cfg.progressBar === true,
		};

		// Pasek postępu (opcjonalny).
		var bar = C.progressBar ? root.querySelector('.evk-hscroll__progress-bar') : null;

		gsap.registerPlugin(ScrollTrigger);

		function setWidths() {
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
					trigger            : root,
					start              : C.startOffset,
					end                : function () { return '+=' + getAmount(); },
					pin                : true,
					anticipatePin      : 1,
					scrub              : C.scrub,
					snap               : snapCfg,
					invalidateOnRefresh: true,
					onUpdate           : bar ? function (self) {
						bar.style.transform = 'scaleX(' + self.progress + ')';
					} : undefined,
				}
			});

			return function () {
				ScrollTrigger.removeEventListener('refreshInit', onRefreshInit);
				root.classList.remove('evk-hscroll--active');
				panels.forEach(function (p) { p.style.width = ''; });
				if (bar) { bar.style.transform = ''; }
			};
		});
	}

	function run() {
		gsap.registerPlugin(ScrollTrigger);
		document.querySelectorAll('.evk-hscroll[data-evk-hscroll]').forEach(initHScroll);
		window.addEventListener('load', function () { ScrollTrigger.refresh(); });
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
