/**
 * EVK Horizontal Scroll v1.6.0
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
			progressTarget: cfg.progressTarget || '',
			currentRest  : cfg.currentRest || 'hide',
			peekNext     : cfg.peekNext === true,
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

		/* ── Wskaźnik postępu ───────────────────────────────────────────────
		 *
		 * Do 1.100.0 wskaźnik mógł stać tylko WEWNĄTRZ elementu, przyklejony do
		 * jego górnej albo dolnej krawędzi. Od 1.101.0 kontener wskazuje się
		 * selektorem i może leżeć gdziekolwiek — choćby w nagłówku sekcji, obok
		 * akapitu z opisem.
		 *
		 * `document.querySelector`, NIE `closest` — i to jest odwrotnie niż przy
		 * przypinaniu wyżej. Tam szukaliśmy PRZODKA, tutaj cel leży POZA
		 * elementem, zwykle w zupełnie innej gałęzi drzewa, więc `closest` nie
		 * miałby czego znaleźć.
		 */
		function kontenerWskaznika() {
			var wewnetrzny = root.querySelector('.evk-hscroll__progress');
			if (!C.progressTarget) { return wewnetrzny; }

			var zewnetrzny = document.querySelector(C.progressTarget);
			if (zewnetrzny) {
				/*
				 * Kreska w kontenerze z WŁASNĄ treścią to sprzeczność.
				 *
				 * Do 1.106.0 skrypt dokładał wtedy pasek jako kolejne dziecko
				 * obok cudzych elementów, a klasa bazowa ściskała cały blok do
				 * czterech pikseli. Zgłoszone z użycia: przy stylu „kreska"
				 * wskaźnik w zewnętrznym divie po prostu nie działał.
				 *
				 * Sprawdzenie musi paść TU, przed usunięciem zapasowego węzła —
				 * inaczej nie byłoby już do czego wrócić.
				 */
				if (C.progressStyle === 'bar' && zewnetrzny !== wewnetrzny
					&& zewnetrzny.children.length) {
					console.warn('[EVK Horizontal Scroll] Styl „jedna kreska" potrzebuje PUSTEGO '
						+ 'kontenera, a „' + C.progressTarget + '" ma własną treść — wskaźnik '
						+ 'zostaje wewnątrz elementu. Do własnej treści nadają się style '
						+ '„segmenty" i „tylko bieżący".', zewnetrzny);
					return wewnetrzny;
				}

				/* Wewnętrzny wskaźnik drukuje PHP ZAWSZE — nie wie przecież, czy
				   selektor trafi, więc zostawia go jako zapas. Gdy trafił, zapas
				   staje się śmieciem, i to widocznym: `--top` to `position:
				   absolute` z własnym tłem i `z-index: 10`, czyli ciemna wstęga
				   leżąca NA górnej krawędzi kart. Zgłoszone z użycia: „nad boksami
				   mam cały czas linię poprzedniego paska postępu".

				   Warunek na tożsamość nie jest ozdobą: selektorem wolno wskazać
				   TEN SAM wewnętrzny węzeł, a wtedy usunięcie zostawiłoby wskaźnik
				   jadący w elemencie oderwanym od dokumentu. */
				if (wewnetrzny && zewnetrzny !== wewnetrzny) {
					wewnetrzny.remove();
					/* Razem z węzłem schodzi modyfikator odsuwający taśmę. Bez tego
					   karty trzymałyby odstęp od wskaźnika, którego już tam nie ma. */
					root.classList.remove('evk-hscroll--prog-top', 'evk-hscroll--prog-bottom');
				}
				return zewnetrzny;
			}

			/* Cisza byłaby tu najgorsza: wskaźnik zostaje w środku elementu,
			   a z ekranu nie ma jak zgadnąć, że selektor w ogóle nie trafił. */
			console.warn('[EVK Horizontal Scroll] Żaden element nie pasuje do selektora wskaźnika '
				+ '„' + C.progressTarget + '" — wskaźnik zostaje wewnątrz elementu.', root);
			return wewnetrzny;
		}

		/* Zmienne, którymi arkusz maluje wskaźnik. Kontrolki `css` z pustym
		   selektorem Bricks zapisuje NA KORZENIU elementu; kontener spoza
		   elementu nie jest jego potomkiem i nie dziedziczy po nim niczego, więc
		   trzeba je przepisać. Ten sam problem rozwiązuje offcanvas przy portalu
		   do <body> (offcanvas-menu.js). */
		var ZMIENNE = [
			'--evk-prog-h', '--evk-prog-bg', '--evk-prog-fill',
			'--evk-prog-pad', '--evk-prog-radius',
			'--evk-seg-gap', '--evk-seg-off', '--evk-seg-on',
			'--evk-seg-h', '--evk-seg-radius',
			'--evk-seg-len', '--evk-seg-len-active',
			'--evk-num-size', '--evk-num-weight'
		];

		var progress = C.progressBar ? kontenerWskaznika() : null;
		var stylKorzenia = progress ? getComputedStyle(root) : null;

		if (progress && !root.contains(progress)) {
			/* Klasa bazowa niesie wysokość paska i jego tło — sensowne dla paska,
			   zabójcze dla cudzego bloku, który ma własny układ. Dostaje ją więc
			   tylko kontener, w którym naprawdę rysujemy pasek; rzędy kresek
			   i numerów stylują się własnymi klasami, niezależnymi od bazowej. */
			if (C.progressStyle === 'bar') {
				progress.classList.add('evk-hscroll__progress');
			}
			ZMIENNE.forEach(function (v) {
				var val = stylKorzenia.getPropertyValue(v);
				if (val && val.trim()) { progress.style.setProperty(v, val.trim()); }
			});
		}

		var bar      = null;
		var segmenty = null;

		if (progress) {
			var pasek = progress.querySelector('.evk-hscroll__progress-bar');

			if (C.progressStyle === 'bar') {
				/* Wewnątrz elementu kreskę drukuje PHP. W kontenerze zewnętrznym
				   nie ma jej skąd wziąć — więc powstaje tutaj. */
				if (!pasek) {
					pasek = document.createElement('div');
					pasek.className = 'evk-hscroll__progress-bar';
					progress.appendChild(pasek);
				}
				bar = pasek;
			} else {
				// Przy kreskach i przy numerach pasek z PHP-a jest tylko przeszkodą.
				if (pasek) { pasek.remove(); }
				progress.classList.add(C.progressStyle === 'current'
					? 'evk-hscroll__progress--current'
					: 'evk-hscroll__progress--segments');

				/* Chowanie ma WŁASNĄ klasę, oddzieloną od `--current`. `--current`
				   niesie układ i treść (rząd, numery), `--chowaj` samo chowanie —
				   dzięki temu „przygaś" pokazuje wszystkie numery z podświetlonym
				   bieżącym, nie ruszając reszty stylu. */
				if (C.progressStyle === 'current' && C.currentRest !== 'dim') {
					progress.classList.add('evk-hscroll__progress--chowaj');
				}

				/*
				 * DWIE DROGI, zależnie od tego, co jest w kontenerze.
				 *
				 * Pusty — skrypt rysuje sam: kreski przy „segmentach", NUMERY
				 * kart przy „tylko bieżącym" (goła kreska nie niosłaby tam żadnej
				 * informacji). Liczbę paneli zna tylko JS; PHP musiałby ją zgadywać
				 * z drzewa dzieci Bricksa.
				 *
				 * Z własną treścią — skrypt nie rusza niczego i tylko wpina
				 * `is-active` w bieżące dziecko. Tędy robi się „01 · ROZMOWA"
				 * zamiast kresek.
				 */
				var wlasne = Array.prototype.slice.call(progress.children);

				if (wlasne.length) {
					segmenty = wlasne;
					if (wlasne.length !== panels.length) {
						/* Przy czterech kartach i dwóch dzieciach dwa ostatnie stany
						   nie mają czego podświetlić — z ekranu wygląda to jak
						   zacinający się wskaźnik. */
						console.warn('[EVK Horizontal Scroll] Wskaźnik ma ' + wlasne.length
							+ ' dzieci przy ' + panels.length + ' panelach — część stanów '
							+ 'nie ma czego podświetlić.', progress);
					}
				} else {
					segmenty = panels.map(function (p, i) {
						var seg = document.createElement('span');
						if (C.progressStyle === 'current') {
							seg.className = 'evk-hscroll__progress-num';
							seg.textContent = String(i + 1);
						} else {
							seg.className = 'evk-hscroll__progress-seg';
						}
						progress.appendChild(seg);
						return seg;
					});
				}

				/* Stała długość kresek zamiast dzielenia szerokości po równo.
				   Przełącza KLASA, nie sama zmienna: CSS nie umie zapytać „czy
				   zmienna jest ustawiona", a `flex: 0 0 var(--evk-seg-len)`
				   z domyślnym `flex-grow: 1` dalej by rozciągało. */
				var dlugosc = stylKorzenia.getPropertyValue('--evk-seg-len');
				if (dlugosc && dlugosc.trim()) {
					progress.classList.add('evk-hscroll__progress--stala');
				}

				/* Kolory bieżącej i nieaktywnych pozycji — tym samym wzorcem.
				   Klasa tylko wtedy, gdy kontrolka ma wartość, bo inaczej reguła
				   nadpisałaby kolor ustawiony w builderze. Dotyczy zwłaszcza
				   kontenera z WŁASNĄ treścią: tam nie ma naszych kresek, więc
				   barwić trzeba cudze dzieci. */
				['off', 'on'].forEach(function (stan) {
					var v = stylKorzenia.getPropertyValue('--evk-seg-' + stan);
					if (v && v.trim()) {
						progress.classList.add('evk-hscroll__progress--barwi-' + stan);
					}
				});
			}
		}

		/** Które dziecko wskaźnika jest bieżące przy danym postępie. */
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

			/* ── Treść pod spodem stoi tuż pod przypiętą sekcją ──────────────
			 *
			 * Bez tego następna sekcja wjeżdża dopiero na koniec przewijania
			 * kart, a pod przypiętą sekcją zieje pustka. Powód jest w geometrii,
			 * nie w ustawieniach: ScrollTrigger wstawia `pin-spacer` o wysokości
			 * `H + D` (wysokość sekcji + droga taśmy), więc następna sekcja stoi
			 * w dokumencie o `D` niżej i po przewinięciu o `d` jej górna krawędź
			 * jest na `H + D − d`. To maleje zawsze — żaden margines ani
			 * `position: sticky` tego nie odwróci, bo treść jest NAPRAWDĘ
			 * oddalona o `D`.
			 *
			 * Dlatego rodzeństwo za spacerem jedzie `y` od `−D` do `0` tym samym
			 * scrubem. Wtedy jego położenie to `(docTop − S − d) + (d − D)`,
			 * czyli `docTop − S − D` — wartość STAŁA. Przed przypięciem treść
			 * jest przyklejona pod sekcją (luka `D` nie zdąży się pokazać),
			 * w trakcie stoi, a na końcu `y` wraca do zera i nic nie skacze.
			 *
			 * Widać dokładnie tyle, ile zostaje ekranu pod sekcją.
			 */
			function zbudujPodglad(spacer) {
				var pod = [];
				for (var n = spacer.nextElementSibling; n; n = n.nextElementSibling) {
					pod.push(n);
				}
				if (!pod.length) { return; }

				/* Transformacja tworzy blok zawierający dla `position: fixed` —
				   ta sama pułapka, którą offcanvas omija portalem do <body>.
				   ScrollTrigger przypina właśnie przez `position: fixed`, więc
				   drugi przypinany element pod spodem PRZESTAŁBY działać.
				   Lepiej nie włączyć podglądu i powiedzieć o tym, niż po cichu
				   zepsuć tamtą sekcję. */
				var kolizja = pod.some(function (el) {
					return el.matches('[data-evk-hscroll]') || el.querySelector('[data-evk-hscroll]');
				});
				if (kolizja) {
					console.warn('[EVK Horizontal Scroll] Podgląd treści pod sekcją wyłączony: niżej '
						+ 'na stronie jest drugi przypinany element, a przesuwanie treści rozłożyłoby '
						+ 'jego przypięcie.', root);
					return;
				}

				if (pinEl.offsetHeight >= window.innerHeight) {
					console.warn('[EVK Horizontal Scroll] Podgląd treści pod sekcją włączony, ale sekcja '
						+ 'zajmuje cały ekran — nie ma czego pokazać.', pinEl);
				}

				/*
				 * ŻADNEGO `z-index` na przypiętej sekcji — próbowane i zmierzone.
				 *
				 * Kusi, żeby ustawić jej `z-index: 1`, bo element z transformacją
				 * maluje się jak `z-index: 0` i przy nachodzeniu wygrywa z sekcją
				 * (równa waga, decyduje kolejność w drzewie). Tyle że
				 * ScrollTrigger KOPIUJE styl przypiętego elementu na `pin-spacer`
				 * — a ten ma wysokość sekcji plus całą drogę taśmy. Zmierzone:
				 * spacer dostawał `position: relative; z-index: 1` i przechwytywał
				 * kliknięcia na wystającej treści, bo leżał nad nią na całej swojej
				 * wysokości.
				 *
				 * Nachodzenie brało się z opóźnienia kompensacji, nie z warstw —
				 * przy `scrub: true` sekcja i treść po prostu się stykają.
				 */
				gsap.fromTo(pod,
					{ y: function () { return -getAmount(); } },
					{
						y   : 0,
						ease: 'none',
						scrollTrigger: {
							trigger            : pinEl,
							start              : C.startOffset,
							end                : function () { return '+=' + getAmount(); },
							/*
							 * `true`, czyli BEZ WYGŁADZANIA — i to jest jedyna
							 * poprawna wartość, mimo że taśma obok jedzie na
							 * `C.scrub`.
							 *
							 * Pin nie jest wygładzany: trzyma się przewijania co
							 * do piksela. Ta animacja ma go dokładnie zniwelować,
							 * więc każde opóźnienie zostaje na ekranie jako
							 * różnica. Przy domyślnym `scrub: 1` transformacja
							 * nie nadążała, była bardziej ujemna niż trzeba,
							 * treść podjeżdżała nad sekcję i opadała po
							 * zatrzymaniu. Zgłoszone z użycia: „podnosi się
							 * i opuszcza — faluje, nachodzi na pole z hs".
							 */
							scrub              : true,
							invalidateOnRefresh: true,
							/* Za zakresem transformacja ZNIKA. Zostawiona — nawet
							   zerowa — dalej tworzyłaby blok zawierający na resztę
							   strony. Przed zakresem musi zostać: bez niej treść
							   skacze o `D` przy dojeżdżaniu do sekcji. */
							onLeave: function () { gsap.set(pod, { clearProps: 'transform' }); }
						}
					});
			}

			/* Spacer istnieje już TERAZ: ScrollTrigger zakłada pin w swoim
			   konstruktorze, także wtedy, gdy blok powstaje w trakcie
			   odświeżania. Sprawdzone mutacją — druga próba po `refresh`
			   nigdy nie wchodziła. */
			var spacer = pinEl.parentElement;
			if (C.peekNext && spacer && spacer.classList.contains('pin-spacer')) {
				zbudujPodglad(spacer);
			}

			/* Podglądu NIE MA w sprzątaniu niżej i to nie jest przeoczenie:
			   `gsap.matchMedia()` cofa wszystko, co powstało w jego bloku —
			   razem z animacją, jej wyzwalaczem i nałożoną transformacją.
			   Sprawdzone mutacją: ręczny `kill()` z `clearProps` dawał się
			   wyciąć bez jednego czerwonego sprawdzenia. Klasy i style poniżej
			   sprząta się z ręki, bo ich GSAP nie zakładał. */
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
