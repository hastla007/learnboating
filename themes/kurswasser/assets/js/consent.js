/* Cookie-Einwilligung (Kurswasser). Ohne Abhängigkeiten; nicht-modales Banner, Tastatur-bedienbar. */
(function () {
	'use strict';
	var cfg = window.kwConsent || {};
	var root = document.querySelector('[data-kw-consent]');
	if (!root || !cfg.cookie) { return; }

	var form = root.querySelector('[data-kw-consent-settings]');
	var toggle = root.querySelector('[data-kw-consent-toggle]');
	var save = root.querySelector('[data-kw-consent-save]');
	var stats = root.querySelector('#kw-consent-cat-statistik');
	var returnFocus = null;

	/** Gespeicherte Entscheidung: null (keine gültige) oder Liste der Kategorien. */
	function read() {
		var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + cfg.cookie + '=([^;]*)'));
		if (!m) { return null; }
		var parts = decodeURIComponent(m[1]).split('|');
		if (parts.length < 2 || parseInt(parts[0], 10) !== Number(cfg.version)) { return null; }
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

	function store(statistik) {
		var before = read();
		var cats = statistik ? 'notwendig,statistik' : 'notwendig';
		var value = encodeURIComponent(cfg.version + '|' + cats + '|' + Math.floor(Date.now() / 1000));
		document.cookie = cfg.cookie + '=' + value + '; path=' + (cfg.path || '/') + '; max-age=' + (365 * 24 * 60 * 60) + '; SameSite=Lax' + (cfg.secure ? '; Secure' : '');

		// WP Consent API (falls vorhanden): WooCommerce prüft dort die Kategorie „marketing“.
		if (typeof window.wp_set_consent === 'function') {
			window.wp_set_consent('functional', 'allow');
			['statistics', 'statistics-anonymous', 'marketing'].forEach(function (c) {
				window.wp_set_consent(c, statistik ? 'allow' : 'deny');
			});
		}
		if (!statistik) { removeSbjs(); }
		close();
		// Serverseitige Einstellung greift erst beim nächsten Seitenaufruf: neu laden, wenn sich „Statistik“ ändert.
		if (hasStats(before) !== statistik) { location.reload(); }
	}

	function setSettings(open) {
		form.hidden = !open;
		save.hidden = !open;
		toggle.hidden = open;
		toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		if (open) { stats.focus(); }
	}

	function show(focus) {
		stats.checked = hasStats(read());
		setSettings(false);
		root.hidden = false;
		if (focus) { root.focus(); }
	}

	function close() {
		root.hidden = true;
		if (returnFocus && document.contains(returnFocus)) { returnFocus.focus(); }
		returnFocus = null;
	}

	root.querySelector('[data-kw-consent-accept]').addEventListener('click', function () { store(true); });
	root.querySelector('[data-kw-consent-reject]').addEventListener('click', function () { store(false); });
	toggle.addEventListener('click', function () { setSettings(true); });
	form.addEventListener('submit', function (e) { e.preventDefault(); store(stats.checked); });

	// ESC: Einstellungen zuklappen, sonst Banner für diesen Seitenaufruf schließen (ohne Einwilligung = nur notwendige).
	root.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') { return; }
		e.preventDefault();
		if (!form.hidden) { setSettings(false); toggle.focus(); } else { close(); }
	});

	// Erneut öffnen: [data-kw-consent-open] oder Links auf #kw-consent.
	document.addEventListener('click', function (e) {
		var opener = e.target.closest && e.target.closest('[data-kw-consent-open], a[href$="#kw-consent"]');
		if (!opener) { return; }
		e.preventDefault();
		returnFocus = opener;
		show(true);
	});

	// Erster Besuch: Banner zeigen, ohne den Fokus zu verschieben (steht früh in der Tab-Reihenfolge).
	if (read() === null) { show(false); }
}());
