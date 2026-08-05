/**
 * EVK Horizontal Scroll v1.1.0
 */
(function () {
	'use strict';

	var GSAP_JS = 'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.15.0/gsap.min.js';
	var ST_JS   = 'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.15.0/ScrollTrigger.min.js';

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
		} else if (typeof gsap !== 'undefined') {
			loadScript(ST_JS, run);
		} else {
			loadScript(GSAP_JS, function () { loadScript(ST_JS, run); });
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
