/* Inhaltsseiten: „Auf dieser Seite“ (aktiver Abschnitt in Randspalte und Sprungleiste) und „Nach oben“.
 * Ohne JavaScript bleiben alle Links nutzbar; es fehlt nur die Hervorhebung bzw. der Knopf. */
(function () {
	'use strict';
	var navs = document.querySelectorAll('.kw-toc, .kw-toc-bar');
	var links = [];
	navs.forEach(function (nav) { links = links.concat([].slice.call(nav.querySelectorAll('a[href^="#"]'))); });
	var targets = {};
	links.forEach(function (a) {
		var id = decodeURIComponent(a.getAttribute('href').slice(1));
		var el = id && document.getElementById(id);
		if (el) { (targets[id] = targets[id] || { el: el, links: [] }).links.push(a); }
	});
	var ids = Object.keys(targets);
	var current = '';
	var setCurrent = function (id) {
		if (id === current) { return; }
		current = id;
		links.forEach(function (a) { a.removeAttribute('aria-current'); });
		if (!targets[id]) { return; }
		targets[id].links.forEach(function (a) {
			a.setAttribute('aria-current', 'true');
			var bar = a.closest('.kw-toc-bar');
			if (bar && bar.scrollWidth > bar.clientWidth) {
				var list = bar.querySelector('ol') || bar;
				list.scrollTo({ left: Math.max(0, a.offsetLeft - 16), behavior: 'auto' });
			}
		});
	};
	if (ids.length && 'IntersectionObserver' in window) {
		var update = function () {
			var line = window.innerHeight * 0.3;
			var best = '';
			ids.forEach(function (id) { if (targets[id].el.getBoundingClientRect().top <= line) { best = id; } });
			setCurrent(best);
		};
		var io = new IntersectionObserver(update, { rootMargin: '0px 0px -60% 0px' });
		ids.forEach(function (id) { io.observe(targets[id].el); });
		window.addEventListener('scroll', function () { window.requestAnimationFrame(update); }, { passive: true });
		update();
	}

	// Desktop: Nach der Randspalte (Artikel am Seitenanfang) übernimmt die klebende Sprungleiste.
	var bar = document.querySelector('.kw-toc-bar.has-rail');
	var railSection = document.querySelector('.kw-article.has-rail');
	if (bar && railSection) {
		var past = function () { bar.classList.toggle('is-past-rail', railSection.getBoundingClientRect().bottom < 0); };
		window.addEventListener('scroll', function () { window.requestAnimationFrame(past); }, { passive: true });
		past();
	}

	// „Nach oben“ ab dem dritten Bildschirm.
	var btn = document.createElement('button');
	btn.type = 'button';
	btn.className = 'kw-totop';
	btn.hidden = true;
	btn.setAttribute('aria-label', 'Nach oben');
	btn.innerHTML = '<span class="kw-icon kw-icon-arrow" aria-hidden="true"></span>';
	var footer = document.querySelector('footer, .kw-footer');
	(footer && footer.parentNode ? footer.parentNode : document.body).insertBefore(btn, footer || null);
	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	btn.addEventListener('click', function () {
		window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
		var target = document.querySelector('h1');
		if (target) { target.setAttribute('tabindex', '-1'); target.focus({ preventScroll: true }); }
	});
	var toggle = function () { btn.hidden = window.scrollY < window.innerHeight * 2; };
	window.addEventListener('scroll', function () { window.requestAnimationFrame(toggle); }, { passive: true });
	toggle();
})();
