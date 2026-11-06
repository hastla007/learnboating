/* Reiter der Angebots-Sektion (ARIA-Tabs, Pfeiltasten). Ohne JS bleibt der erste Reiter sichtbar. */
document.querySelectorAll('[data-kw-tabs]').forEach(function (root) {
	var tabs = Array.prototype.slice.call(root.querySelectorAll('[role="tab"]'));
	function select(tab, focus) {
		tabs.forEach(function (t) {
			var on = t === tab;
			t.setAttribute('aria-selected', on ? 'true' : 'false');
			t.tabIndex = on ? 0 : -1;
			var panel = document.getElementById(t.getAttribute('aria-controls'));
			if (panel) { panel.hidden = !on; }
		});
		if (focus) { tab.focus(); }
	}
	tabs.forEach(function (tab, i) {
		tab.addEventListener('click', function () { select(tab, false); });
		tab.addEventListener('keydown', function (e) {
			var next = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : e.key === 'Home' ? 0 : e.key === 'End' ? tabs.length - 1 : null;
			if (next === null) { return; }
			e.preventDefault();
			select(tabs[(next + tabs.length) % tabs.length], true);
		});
	});
});
