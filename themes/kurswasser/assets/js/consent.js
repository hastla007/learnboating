/* Cookie-Einwilligung (Kurswasser). Ohne Abhängigkeiten; nicht-modales Banner, Tastatur-bedienbar.
 * Seiten-Cache-fest: Der Server liefert allen dieselbe Seite; Banner, Häkchen und die WooCommerce-
 * Bestellzuordnung richten sich nur nach dem Cookie im Browser. Den Start der Bestellzuordnung regelt ein
 * Inline-Script vor order-attribution.js (inc/consent.php); hier wird sie nur bei einer Änderung der Wahl
 * umgeschaltet (setOrderTracking(true) zweimal würde Seitenaufrufe doppelt zählen). */
(function () {
	'use strict';
	var cfg = window.kwConsent || {};
	var root = document.querySelector('[data-kw-consent]');
	if (!root || !cfg.cookie) { return; }

	var form = root.querySelector('[data-kw-consent-settings]');
	var toggle = root.querySelector('[data-kw-consent-toggle]');
	var save = root.querySelector('[data-kw-consent-save]');
	var stats = root.querySelector('#kw-consent-cat-statistik');
	var html = document.documentElement;
	var returnFocus = null;
	var observer = null;

	/** Gespeicherte Entscheidung: null (keine gültige) oder Liste der Kategorien. */
	function read() {
		var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + cfg.cookie + '=([^;]*)'));
		if (!m) { return null; }
		var raw;
		try { raw = decodeURIComponent(m[1]); } catch (e) { return null; }
		var parts = raw.split('|');
		if (parts.length < 2 || parseInt(parts[0], 10) !== Number(cfg.version)) { return null; }
		var time = parseInt(parts[2], 10);
		if (time > 0 && cfg.maxAge && Date.now() / 1000 - time > Number(cfg.maxAge)) { return null; }
		return parts[1].split(',');
	}

	function hasStats(cats) { return !!cats && cats.indexOf('statistik') !== -1; }

	function removeSbjs() {
		var host = location.hostname;
		document.cookie.split(';').forEach(function (c) {
			var name = c.split('=')[0].trim();
			if (name.indexOf('sbjs_') !== 0) { return; }
			['', '; domain=' + host, '; domain=.' + host].forEach(function (d) {
				document.cookie = name + '=; path=/; max-age=0' + d;
			});
		});
	}

	/**
	 * WooCommerce-Bestellzuordnung schalten, aber nur, wenn sich ihr Zustand ändert: true startet
	 * sourcebuster.js (sbjs_*-Cookies), false löscht die Cookies und leert die Bestellfelder.
	 */
	function applyTracking(allow) {
		var wc = window.wc_order_attribution;
		if (wc && wc.params && typeof wc.setOrderTracking === 'function' && !!wc.params.allowTracking !== allow) {
			try { wc.setOrderTracking(allow); } catch (e) { /* Zuordnung ist verzichtbar */ }
		}
		if (!allow) { removeSbjs(); }
	}

	/** Aktueller Wert einer Kategorie im Cookie der WP Consent API ('' = nicht gesetzt). */
	function apiValue(category) {
		var prefix = (window.consent_api && window.consent_api.cookie_prefix) || 'wp_consent';
		var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + prefix + '_' + category + '=([^;]*)'));
		return m ? m[1] : '';
	}

	/**
	 * WP Consent API (falls vorhanden): Bestellzuordnung zählt als „statistics“, „marketing“ nie freigegeben.
	 * statistik === null: keine gültige Entscheidung – einwilligungspflichtige Kategorien ausdrücklich ablehnen.
	 * Nur geänderte Kategorien setzen (jede Änderung löst bei WooCommerce setOrderTracking() aus).
	 */
	function applyConsentApi(statistik) {
		if (typeof window.wp_set_consent !== 'function') { return; }
		var want = { 'statistics': statistik ? 'allow' : 'deny', 'statistics-anonymous': statistik ? 'allow' : 'deny', 'marketing': 'deny' };
		if (statistik !== null) { want.functional = 'allow'; }
		Object.keys(want).forEach(function (c) {
			if (apiValue(c) !== want[c]) { window.wp_set_consent(c, want[c]); }
		});
	}

	/** Kurze Meldung für Screenreader, wenn der Fokus nach dem Schließen nicht zurückspringen kann. */
	function announce(text) {
		if (!text) { return; }
		var region = document.getElementById('kw-consent-status');
		if (!region) {
			region = document.createElement('div');
			region.id = 'kw-consent-status';
			region.className = 'kw-consent-status';
			region.setAttribute('role', 'status');
			document.body.appendChild(region);
		}
		region.textContent = '';
		setTimeout(function () { region.textContent = text; }, 50);
		setTimeout(function () { region.textContent = ''; }, 6000);
	}

	function store(statistik) {
		var cats = statistik ? 'notwendig,statistik' : 'notwendig';
		var value = encodeURIComponent(cfg.version + '|' + cats + '|' + Math.floor(Date.now() / 1000));
		document.cookie = cfg.cookie + '=' + value + '; path=' + (cfg.path || '/') + '; max-age=' + (365 * 24 * 60 * 60) + '; SameSite=Lax' + (cfg.secure ? '; Secure' : '');
		applyConsentApi(statistik);
		applyTracking(statistik);
		close(true);
	}

	function setSettings(open) {
		form.hidden = !open;
		save.hidden = !open;
		toggle.hidden = open;
		toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		if (open) { stats.focus(); }
	}

	/* WCAG 2.4.11: Das fixierte Banner darf fokussierte Elemente nicht verdecken. */
	function updatePadding() {
		var height = root.hidden ? 0 : root.offsetHeight;
		html.style.scrollPaddingBottom = height ? (height + 16) + 'px' : '';
	}

	/*
	 * Modale Dialoge anderer Teile (Mini-Cart-Drawer, Lern-App, Funkgerät-Vollbild): Banner solange ausblenden.
	 * CSS erledigt das per :has(); die Klasse ist der Ersatz für Browser ohne :has(). Beobachtet wird nur,
	 * solange das Banner offen ist.
	 */
	var MODAL = '[aria-modal="true"], dialog[open]:modal, .wc-block-components-drawer__screen-overlay:not(.wc-block-components-drawer__screen-overlay--is-hidden)';
	var modalObserver = null;
	function modalOpen() {
		try { return !!document.querySelector(MODAL); } catch (e) { return !!document.querySelector('[aria-modal="true"]'); }
	}
	function syncModal() {
		var open = modalOpen();
		if (html.classList.contains('kw-consent-behind-modal') !== open) {
			html.classList.toggle('kw-consent-behind-modal', open);
			updatePadding();
		}
	}
	function watchModals(on) {
		if (typeof window.MutationObserver !== 'function') { return; }
		if (on && !modalObserver) {
			modalObserver = new window.MutationObserver(syncModal);
			modalObserver.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['aria-modal', 'open', 'class'] });
		} else if (!on && modalObserver) {
			modalObserver.disconnect();
			modalObserver = null;
		}
		syncModal();
	}

	function show(focus) {
		stats.checked = hasStats(read());
		setSettings(false);
		root.hidden = false;
		updatePadding();
		if (!observer && typeof window.ResizeObserver === 'function') {
			observer = new window.ResizeObserver(updatePadding);
		}
		if (observer) { observer.observe(root); }
		watchModals(true);
		if (focus) { root.focus(); }
	}

	function close(saved) {
		var active = document.activeElement;
		var hadFocus = !active || active === document.body || root.contains(active);
		root.hidden = true;
		if (observer) { observer.disconnect(); }
		watchModals(false);
		updatePadding();
		if (returnFocus && document.contains(returnFocus)) {
			returnFocus.focus();
		} else if (hadFocus) {
			// Kein Auslöser zum Zurückspringen: Fokus an den Seitenanfang des Hauptinhalts.
			var target = document.querySelector('main, #wp--skip-link--target, [role="main"]') || document.body;
			if (!target.hasAttribute('tabindex') && target !== document.body) { target.setAttribute('tabindex', '-1'); }
			target.focus({ preventScroll: true });
		}
		if (saved && !(returnFocus && document.contains(returnFocus))) { announce(cfg.saved); }
		returnFocus = null;
	}

	root.querySelector('[data-kw-consent-accept]').addEventListener('click', function () { store(true); });
	root.querySelector('[data-kw-consent-reject]').addEventListener('click', function () { store(false); });
	toggle.addEventListener('click', function () { setSettings(true); });
	form.addEventListener('submit', function (e) { e.preventDefault(); store(stats.checked); });

	// ESC, solange das Banner offen ist: Einstellungen zuklappen, sonst Banner für diesen Seitenaufruf
	// schließen (ohne Entscheidung = nur notwendige). Nur wenn der Fokus im Banner oder auf body/main liegt
	// und kein modaler Dialog bzw. Drawer offen ist – deren eigenes Escape (Mini-Cart, Lern-App-Popover,
	// Funkgerät-Vollbild) hat Vorrang, das Banner bleibt dann stehen.
	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape' || root.hidden || e.defaultPrevented) { return; }
		var active = document.activeElement;
		var neutral = !active || active === document.body || active === document.documentElement || (active.matches && active.matches('main, [role="main"], #wp--skip-link--target'));
		if (!(neutral || root.contains(active)) || modalOpen() || document.fullscreenElement || document.querySelector('.fg-pseudo-full')) { return; }
		e.preventDefault();
		if (!form.hidden && root.contains(document.activeElement)) { setSettings(false); toggle.focus(); } else { close(false); }
	});

	// Erneut öffnen: [data-kw-consent-open] oder Links auf #kw-consent.
	document.addEventListener('click', function (e) {
		var opener = e.target.closest && e.target.closest('[data-kw-consent-open], a[href$="#kw-consent"]');
		if (!opener) { return; }
		e.preventDefault();
		returnFocus = opener;
		show(true);
	});

	// Öffner (ohne JavaScript nutzlos, daher hidden ausgeliefert) samt Listenpunkt/Absatz einblenden.
	Array.prototype.forEach.call(document.querySelectorAll('[data-kw-consent-open]'), function (el) {
		el.hidden = false;
		var wrap = el.closest('[data-kw-consent-wrap]');
		if (wrap) { wrap.hidden = false; }
	});

	// Gespeicherte Entscheidung anwenden (die Seite selbst ist für alle gleich). Die Bestellzuordnung hat das
	// Inline-Script vor order-attribution.js schon passend gestartet; applyTracking() greift nur, falls nicht.
	var current = read();
	applyTracking(hasStats(current));
	applyConsentApi(current === null ? null : hasStats(current));
	if (current === null) {
		// Erster Besuch: Banner zeigen, ohne den Fokus zu verschieben (steht früh in der Tab-Reihenfolge).
		show(false);
	}
}());
