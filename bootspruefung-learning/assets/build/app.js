/*
 * Lernbereich /bootsscheine/ – Kursübersicht, Lernmodus/Smarttrainer, Prüfungssimulation.
 * Ohne Build-Schritt; alle Texte kommen übersetzt aus PHP (bpLearning.i18n).
 * Sicherheit: Inhalte werden ausschließlich per textContent gesetzt, nie als HTML.
 */
(function () {
	'use strict';

	var cfg = window.bpLearning;
	var root = document.getElementById('bp-app');
	if (!cfg || !root) {
		return;
	}
	var T = cfg.i18n;
	var base = cfg.base.replace(/\/?$/, '/');
	var timers = [];

	// ------------------------------------------------------------------ Helfer

	function t(key, vars) {
		var s = T[key] || key;
		if (vars) {
			Object.keys(vars).forEach(function (k) {
				s = s.split('{' + k + '}').join(String(vars[k]));
			});
		}
		return s;
	}

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) {
			var value = attrs[key];
			if (value === null || value === undefined || value === false) {
				return;
			}
			if (key === 'text') {
				node.textContent = value;
			} else if (key === 'class') {
				node.className = value;
			} else if (key.slice(0, 2) === 'on') {
				node.addEventListener(key.slice(2), value);
			} else if (value === true) {
				node.setAttribute(key, '');
			} else {
				node.setAttribute(key, value);
			}
		});
		(children || []).forEach(function (child) {
			if (child === null || child === undefined || child === false) {
				return;
			}
			node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
		});
		return node;
	}

	function multiline(tag, text, cls) {
		var node = el(tag, { class: cls });
		String(text || '').split(/\n/).forEach(function (line, i) {
			if (i > 0) {
				node.appendChild(document.createElement('br'));
			}
			node.appendChild(document.createTextNode(line));
		});
		return node;
	}

	function link(path, text, cls) {
		return el('a', { href: base + path, class: cls, 'data-bp-link': '1', text: text });
	}

	function uuid() {
		if (window.crypto && window.crypto.randomUUID) {
			return window.crypto.randomUUID();
		}
		var b = new Uint8Array(16);
		window.crypto.getRandomValues(b);
		b[6] = (b[6] & 15) | 64;
		b[8] = (b[8] & 63) | 128;
		var h = Array.prototype.map.call(b, function (x) { return (x + 256).toString(16).slice(1); }).join('');
		return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
	}

	function api(method, path, body) {
		var options = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' } };
		if (body !== undefined) {
			options.headers['Content-Type'] = 'application/json';
			options.body = JSON.stringify(body);
		}
		return fetch(cfg.root + path, options).then(function (response) {
			return response.json().catch(function () { return {}; }).then(function (data) {
				if (!response.ok) {
					var error = new Error((data && data.message) || t('error_generic'));
					error.status = response.status;
					throw error;
				}
				return data;
			});
		});
	}

	function clearTimers() {
		timers.forEach(function (id) { clearInterval(id); });
		timers = [];
	}

	function mount(nodes, title) {
		clearTimers();
		root.textContent = '';
		nodes.forEach(function (n) { if (n) { root.appendChild(n); } });
		var heading = root.querySelector('h1');
		if (heading) {
			heading.setAttribute('tabindex', '-1');
			heading.focus({ preventScroll: true });
		}
		if (title) {
			document.title = title;
		}
		window.scrollTo(0, Math.min(window.scrollY, root.getBoundingClientRect().top + window.scrollY - 16));
	}

	function showError(error) {
		mount([el('div', { class: 'bp-card bp-error', role: 'alert' }, [
			el('h1', { text: error.status === 403 ? t('locked_title') : t('error_title') }),
			el('p', { text: error.message }),
			el('p', {}, [link('', t('back_overview'), 'bp-button')])
		])]);
	}

	function loading() {
		root.textContent = '';
		root.appendChild(el('p', { class: 'bp-loading', text: t('loading') }));
	}

	function progressBar(p, label) {
		var total = Math.max(p.questions, 1);
		var bar = el('div', { class: 'bp-progress', role: 'img', 'aria-label': t('progress_aria', { mastered: p.mastered, learning: p.learning, unsure: p.unsure, total: p.questions }) });
		[['mastered', p.mastered], ['learning', p.learning], ['unsure', p.unsure]].forEach(function (seg) {
			if (seg[1] > 0) {
				bar.appendChild(el('span', { class: 'bp-progress-' + seg[0], style: 'width:' + (seg[1] * 100 / total) + '%' }));
			}
		});
		return el('div', { class: 'bp-progress-wrap' }, [
			bar,
			el('p', { class: 'bp-progress-label', text: label || t('progress_label', { percent: p.percent, mastered: p.mastered, total: p.questions }) })
		]);
	}

	function badge(text, kind) {
		return el('span', { class: 'bp-badge bp-badge-' + (kind || 'neutral'), text: text });
	}

	function typeLabel(type) {
		return t('course_type_' + type);
	}

	function minutes(seconds) {
		return Math.round(seconds / 60);
	}

	// ------------------------------------------------------------------ Router

	// Das Geräte-JavaScript der Quelle hängt Tastatur-Listener und einen Takt an das Dokument und lässt sich
	// nicht abbauen. Nach dem Verlassen des Simulators die Zielseite daher frisch laden.
	var simLoaded = false;

	function route() {
		var path = window.location.pathname;
		var rel = path.indexOf(base) === 0 ? path.slice(base.length) : '';
		if (simLoaded && !/\/funk\/geraet\/?$/.test(rel)) {
			window.location.reload();
			return;
		}
		var parts = rel.split('/').filter(Boolean);
		var query = new URLSearchParams(window.location.search);
		loading();
		var view;
		if (parts.length === 0) {
			view = viewOverview();
		} else if (parts[0] === 'gruppe' && parts[1]) {
			view = viewGroupJoin(parts[1]);
		} else if (parts[0] === 'gruppen') {
			view = viewGroups();
		} else if (parts[0] === 'tipps') {
			view = viewTips();
		} else if (parts.length === 1) {
			view = viewCourse(parts[0]);
		} else if (parts[1] === 'pruefung-anmelden') {
			view = viewExamGuide(parts[0], query.get('teil'));
		} else if (parts[1] === 'lernen') {
			view = viewLearn(parts[0], query.get('modul'), query.get('modus') || 'smart');
		} else if (parts[1] === 'pruefung' && parts[2]) {
			view = viewExam(parts[0], parseInt(parts[2], 10));
		} else if (parts[1] === 'pruefung') {
			view = viewExamHub(parts[0]);
		} else if (parts[1] === 'themen') {
			view = viewTopics(parts[0]);
		} else if (parts[1] === 'downloads') {
			view = viewDownloads(parts[0]);
		} else if (parts[1] === 'navigation') {
			view = parts[2] ? viewNavigationTask(parts[0], parseInt(parts[2], 10)) : viewNavigation(parts[0]);
		} else if (parts[1] === 'praxis') {
			view = viewPraxis(parts[0], query.get('kategorie'));
		} else if (parts[1] === 'video') {
			view = parts[2] ? viewVideoLesson(parts[0], parseInt(parts[2], 10)) : viewVideos(parts[0]);
		} else if (parts[1] === 'funk' && parts[2] === 'text' && parts[3]) {
			view = viewRadioText(parts[0], parts[3], query.get('richtung') || 'de-en');
		} else if (parts[1] === 'funk' && parts[2] === 'praxis' && parts[3]) {
			view = viewRadioPractice(parts[0], parts[3]);
		} else if (parts[1] === 'funk' && parts[2] === 'geraet') {
			view = viewDeviceSim(parts[0]);
		} else if (parts[1] === 'funk' && parts[2] === 'vokabeln') {
			view = viewVocabulary(parts[0], query.get('katalog') || 'SRC', query.get('kategorie'));
		} else if (parts[1] === 'funk') {
			view = viewRadio(parts[0]);
		} else {
			view = Promise.reject(Object.assign(new Error(t('not_found')), { status: 404 }));
		}
		view.catch(showError);
	}

	function navigate(path) {
		window.history.pushState({}, '', base + path);
		route();
	}

	document.addEventListener('click', function (event) {
		var a = event.target.closest && event.target.closest('a[data-bp-link]');
		if (!a || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) {
			return;
		}
		event.preventDefault();
		window.history.pushState({}, '', a.getAttribute('href'));
		route();
	});
	window.addEventListener('popstate', route);

	// ------------------------------------------------------------------ Übersicht

	// Kursfoto aus dem Theme (Filter bootspruefung_course_images), sonst Farbverlauf.
	function courseImage(slug, size) {
		var imgs = cfg.courseImages || {};
		return imgs[slug] ? (imgs[slug][size] || imgs[slug].card || null) : null;
	}

	function courseCard(c) {
		var img = courseImage(c.slug, 'card');
		var media = el('a', { class: 'bp-cc-media' + (img ? '' : ' is-plain'), href: base + c.slug + '/', 'data-bp-link': '1', tabindex: '-1', 'aria-hidden': 'true' }, [
			img ? el('img', { src: img, alt: '', loading: 'lazy' }) : null,
			el('span', { class: 'bp-cc-type bp-cc-type-' + c.type, text: typeLabel(c.type) })
		]);
		var body = [el('h3', { class: 'bp-cc-title' }, [link(c.slug + '/', c.name)])];
		if (c.progress) {
			body.push(el('p', { class: 'bp-cc-pct' }, [el('strong', { text: c.progress.percent + ' %' }), ' ' + t('course_hero_mastered')]));
			body.push(progressBar(c.progress, t('progress_short', { mastered: c.progress.mastered, total: c.progress.questions })));
			body.push(el('p', { class: 'bp-cc-actions' }, [link(c.slug + '/', c.progress.mastered + c.progress.learning + c.progress.unsure > 0 ? t('dash_continue') : t('course_cta_learn'), 'bp-button bp-button-primary bp-cc-go')]));
		} else {
			body.push(el('p', { class: 'bp-muted bp-cc-locked', text: t('locked_short') }));
			body = body.concat(c.shop && c.shop.question_bank ? offerNodes(c.shop.question_bank) : []);
		}
		return el('li', { class: 'bp-card bp-course-card' + (c.progress ? '' : ' is-locked') }, [media, el('div', { class: 'bp-cc-body' }, body)]);
	}

	function viewOverview() {
		return api('GET', 'me/courses').then(function (courses) {
			var mine = courses.filter(function (c) { return !!c.progress; });
			var others = courses.filter(function (c) { return !c.progress; });
			var focus = null;
			mine.forEach(function (c) {
				var touched = c.progress.mastered + c.progress.learning + c.progress.unsure;
				if (touched > 0 && (!focus || touched > focus.touched)) { focus = { c: c, touched: touched }; }
			});
			var hero = el('section', { class: 'bp-hero bp-dash-hero' }, [
				el('div', { class: 'bp-hero-text' }, [
					el('p', { class: 'bp-hero-eyebrow', text: t('dash_eyebrow') }),
					el('h1', { text: cfg.user ? t('dash_hello', { name: cfg.user }) : t('overview_title') }),
					el('p', { class: 'bp-hero-lead', text: t('dash_intro') }),
					el('ul', { class: 'bp-hero-facts' }, [
						el('li', {}, [el('strong', { text: String(mine.length) }), ' ' + t('dash_stat_courses')])
					])
				]),
				focus ? el('div', { class: 'bp-hero-focus' }, [
					el('p', { class: 'bp-hero-focus-label', text: t('dash_focus') }),
					el('p', { class: 'bp-hero-focus-title', text: focus.c.name }),
					progressBar(focus.c.progress),
					el('p', {}, [link(focus.c.slug + '/lernen/', t('dash_continue'), 'bp-button bp-button-signal')])
				]) : null
			]);
			var nodes = [hero];
			[['full', t('dash_group_full')], ['combo', t('dash_group_combo')], ['addon', t('dash_group_addon')]].forEach(function (g) {
				var list = mine.filter(function (c) { return c.type === g[0]; });
				if (list.length) {
					nodes.push(el('section', { class: 'bp-section bp-dash-group' }, [el('h2', { text: g[1] }), el('ul', { class: 'bp-grid bp-cc-grid' }, list.map(courseCard))]));
				}
			});
			var rest = mine.filter(function (c) { return ['full', 'combo', 'addon'].indexOf(c.type) === -1; });
			if (rest.length) {
				nodes.push(el('section', { class: 'bp-section bp-dash-group' }, [el('ul', { class: 'bp-grid bp-cc-grid' }, rest.map(courseCard))]));
			}
			if (others.length) {
				nodes.push(el('section', { class: 'bp-section bp-dash-group bp-dash-more' }, [el('h2', { text: mine.length ? t('dash_more_courses') : t('dash_all_courses') }), el('p', { class: 'bp-muted', text: t('dash_more_intro') }), el('ul', { class: 'bp-grid bp-cc-grid' }, others.map(courseCard))]));
			}
			if (!courses.length) {
				nodes.push(el('p', { text: t('no_courses') }));
			}
			nodes.push(el('ul', { class: 'bp-link-cards' }, [
				el('li', {}, [el('a', { href: base + 'tipps/', 'data-bp-link': '1', class: 'bp-link-card' }, [el('span', { class: 'bp-ico bp-ico-bulb', 'aria-hidden': 'true' }), el('span', {}, [el('strong', { text: t('tips_title') }), el('span', { class: 'bp-muted', text: t('dash_tips_text') })])])]),
				cfg.groups ? el('li', {}, [el('a', { href: base + 'gruppen/', 'data-bp-link': '1', class: 'bp-link-card' }, [el('span', { class: 'bp-ico bp-ico-group', 'aria-hidden': 'true' }), el('span', {}, [el('strong', { text: t('groups_title') }), el('span', { class: 'bp-muted', text: t('dash_groups_text') })])])]) : null
			]));
			mount(nodes, t('overview_title'));
		});
	}

	// ------------------------------------------------------------------ Tipps & Tricks

	function viewTips() {
		return api('GET', 'tips').then(function (groups) {
			var sections = groups.map(function (g) {
				return el('section', { class: 'bp-tips-group' }, [
					el('h2', { text: g.name }),
					el('div', { class: 'bp-tips' }, g.tips.map(function (tip) {
						var body = el('div', { class: 'bp-tip-body' });
						body.innerHTML = tip.html; // serverseitig mit wp_kses_post bereinigt
						return el('details', { class: 'bp-card bp-tip' }, [el('summary', { text: tip.title }), body]);
					}))
				]);
			});
			mount([
				el('nav', { class: 'bp-breadcrumb', 'aria-label': t('breadcrumb') }, [link('', t('overview_title')), ' › ', el('span', { text: t('tips_title') })]),
				el('header', { class: 'bp-header' }, [el('h1', { text: t('tips_title') }), el('p', { class: 'bp-muted', text: t('tips_intro') })]),
				groups.length ? el('div', {}, sections) : el('p', { text: t('tips_empty') })
			], t('tips_title'));
		});
	}

	// ------------------------------------------------------------------ Gruppenlizenzen

	function scopeList(scopes) {
		return el('ul', { class: 'bp-scopes' }, scopes.map(function (s) { return el('li', { text: s.course + ' · ' + s.feature }); }));
	}

	function viewGroupJoin(token) {
		return api('GET', 'groups/join/' + encodeURIComponent(token)).then(function (g) {
			var box = el('section', { class: 'bp-card' }, [
				el('h1', { text: t('group_join_title', { name: g.name }) }),
				g.owner ? el('p', { class: 'bp-muted', text: t('group_owner', { name: g.owner }) }) : null,
				scopeList(g.scopes),
				g.valid_until ? el('p', { class: 'bp-muted', text: t('group_valid_until', { date: fmtDate(g.valid_until) }) }) : null
			]);
			if (g.status !== 'active' || g.free_seats < 1) {
				box.appendChild(el('p', { class: 'bp-inline-error', text: g.status !== 'active' ? t('group_inactive') : t('group_full') }));
			} else {
				var join = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: t('group_join') });
				join.addEventListener('click', function () {
					join.disabled = true;
					api('POST', 'groups/join', { token: token }).then(function () { navigate(''); }).catch(function (e) { join.disabled = false; alertMessage(box, e.message); });
				});
				box.appendChild(el('div', { class: 'bp-actions' }, [join]));
			}
			mount([el('nav', { class: 'bp-breadcrumb', 'aria-label': t('breadcrumb') }, [link('', t('overview_title'))]), box], t('group_join_title', { name: g.name }));
		});
	}

	function viewGroups() {
		return api('GET', 'me/groups').then(function (groups) {
			var nodes = [
				el('nav', { class: 'bp-breadcrumb', 'aria-label': t('breadcrumb') }, [link('', t('overview_title')), ' › ', el('span', { text: t('groups_title') })]),
				el('header', { class: 'bp-header' }, [el('h1', { text: t('groups_title') }), el('p', { class: 'bp-muted', text: t('groups_intro') })])
			];
			if (!groups.length) {
				nodes.push(el('p', { text: t('groups_none') }));
			}
			groups.forEach(function (g) { nodes.push(groupCard(g)); });
			mount(nodes, t('groups_title'));
		});
	}

	function groupCard(g) {
		var card = el('section', { class: 'bp-card bp-group', 'aria-label': g.name });
		function render(data) {
			g = data;
			card.textContent = '';
			var name = el('input', { type: 'text', value: g.name, 'aria-label': t('group_name'), class: 'bp-input' });
			var save = el('button', { type: 'button', class: 'bp-button', text: t('save') });
			save.addEventListener('click', function () { api('PUT', 'me/groups/' + g.id, { name: name.value }).then(render).catch(function (e) { alertMessage(card, e.message); }); });
			card.appendChild(el('div', { class: 'bp-actions' }, [name, save]));
			card.appendChild(el('p', {}, [badge(t('group_status_' + g.status), g.status === 'active' ? 'ok' : 'warn'), ' ', el('span', { text: t('group_seats', { used: g.seats - g.free_seats, total: g.seats }) }), g.valid_until ? el('span', { class: 'bp-muted', text: ' · ' + t('group_valid_until', { date: fmtDate(g.valid_until) }) }) : null]));
			card.appendChild(scopeList(g.scopes));
			if (g.join_url) {
				var url = el('input', { type: 'text', readonly: 'readonly', value: g.join_url, 'aria-label': t('group_link'), class: 'bp-input' });
				var copy = el('button', { type: 'button', class: 'bp-button', text: t('group_copy') });
				copy.addEventListener('click', function () { url.select(); if (navigator.clipboard) { navigator.clipboard.writeText(url.value); } });
				var renew = el('button', { type: 'button', class: 'bp-button', text: t('group_new_link') });
				renew.addEventListener('click', function () { api('POST', 'me/groups/' + g.id + '/token', {}).then(render); });
				card.appendChild(el('h3', { text: t('group_link') }));
				card.appendChild(el('p', { class: 'bp-muted', text: t('group_link_explain') }));
				card.appendChild(el('div', { class: 'bp-actions' }, [url, copy, renew]));
				var emails = el('textarea', { rows: 3, class: 'bp-input', 'aria-label': t('group_invite_emails'), placeholder: 'name@example.de' });
				var send = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: t('group_invite') });
				send.addEventListener('click', function () {
					var list = emails.value.split(/[\s,;]+/).filter(Boolean);
					if (!list.length) { return; }
					send.disabled = true;
					api('POST', 'me/groups/' + g.id + '/invite', { emails: list }).then(function (r) {
						render(r.group);
						if (r.skipped.length) { alertMessage(card, t('group_skipped', { list: r.skipped.join(', ') })); }
					}).catch(function (e) { send.disabled = false; alertMessage(card, e.message); });
				});
				card.appendChild(el('h3', { text: t('group_invite_emails') }));
				card.appendChild(el('div', { class: 'bp-actions' }, [emails, send]));
			}
			card.appendChild(el('h3', { text: t('group_members') }));
			card.appendChild(g.members.length ? el('ul', { class: 'bp-members' }, g.members.map(function (m) {
				var remove = el('button', { type: 'button', class: 'bp-button bp-button-small', text: t('group_remove') });
				remove.addEventListener('click', function () {
					var reason = window.prompt(t('group_remove_confirm', { name: m.name || m.email }));
					if (reason === null) { return; }
					api('DELETE', 'me/groups/' + g.id + '/members/' + m.id, { reason: reason }).then(render).catch(function (e) { alertMessage(card, e.message); });
				});
				return el('li', {}, [el('span', { text: (m.name || m.email || '–') + (m.name && m.email ? ' <' + m.email + '>' : '') + ' · ' + t('group_member_' + m.status) }), ' ', remove]);
			})) : el('p', { class: 'bp-muted', text: t('group_no_members') }));
		}
		render(g);
		return card;
	}

	// ------------------------------------------------------------------ Kurs

	function courseHero(slug, c) {
		var img = courseImage(slug, 'hero');
		var p = c.progress ? c.progress.total : null;
		var hero = el('section', { class: 'bp-hero bp-course-hero bp-hub-hero' + (img ? ' has-image' : '') }, [
			img ? el('img', { class: 'bp-hero-img', src: img, alt: '' }) : null,
			el('div', { class: 'bp-hero-text' }, [
				el('nav', { class: 'bp-breadcrumb', 'aria-label': t('breadcrumb') }, [link('', t('overview_title')), ' › ', el('span', { text: c.name })]),
				el('p', { class: 'bp-eyebrow' }, [el('span', { class: 'bp-cc-type bp-cc-type-' + c.type, text: typeLabel(c.type) })]),
				el('h1', { text: c.name }),
				el('p', { class: 'bp-hero-lead', text: p ? t('hub_intro_progress', { percent: p.percent, mastered: p.mastered, total: p.questions }) : t('hub_intro') })
			])
		]);
		return hero;
	}

	function modeTile(href, label, count, desc, icon, primary) {
		var name = count === null ? label : label + ' (' + count + ')';
		return el('li', {}, [el('a', { href: base + href, 'data-bp-link': '1', class: 'bp-mode' + (primary ? ' is-primary' : ''), 'aria-label': name }, [
			el('span', { class: 'bp-ico bp-ico-' + icon, 'aria-hidden': 'true' }),
			el('span', { class: 'bp-mode-text', 'aria-hidden': 'true' }, [el('strong', { text: label }), el('span', { text: desc })]),
			count === null ? el('span', { class: 'bp-mode-arrow', 'aria-hidden': 'true', text: '→' }) : el('span', { class: 'bp-mode-count', 'aria-hidden': 'true', text: String(count) })
		])]);
	}

	// Kursseite = zentrale Navigation: nächster Schritt, Fortschritt, Lernbereiche. Inhalte auf Detailseiten.
	function ring(percent) {
		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS(ns, 'svg');
		svg.setAttribute('viewBox', '0 0 120 120');
		svg.setAttribute('class', 'bp-hub-ring');
		svg.setAttribute('aria-hidden', 'true');
		[['bp-hub-ring-track', 100], ['bp-hub-ring-value', percent]].filter(function (c) { return c[1] > 0; }).forEach(function (c) {
			var circle = document.createElementNS(ns, 'circle');
			circle.setAttribute('cx', '60');
			circle.setAttribute('cy', '60');
			circle.setAttribute('r', '50');
			circle.setAttribute('class', c[0]);
			circle.setAttribute('pathLength', '100');
			circle.setAttribute('stroke-dasharray', Math.max(0, Math.min(100, c[1])) + ' 100');
			svg.appendChild(circle);
		});
		return svg;
	}

	function hubLink(slug, path, text, cls) {
		return link(slug + '/' + path, text, cls);
	}

	// Teilkurse der Kombis: oben je ein Videokurs-Kärtchen (Kombi selbst hat keine eigene Video-Reihenfolge).
	var COURSE_PARTS = { 'SBF-SEE-BIN': ['SBF-SEE', 'SBF-BIN-MOTOR'], 'SRC-UBI': ['SRC', 'UBI'] };

	function videoParts(c) {
		var codes = COURSE_PARTS[c.code] || [c.code];
		return Promise.all(codes.map(function (code) {
			var partSlug = code.toLowerCase();
			var info = code === c.code ? Promise.resolve(c) : api('GET', 'courses/' + encodeURIComponent(partSlug)).catch(function () { return null; });
			var videos = api('GET', 'courses/' + encodeURIComponent(partSlug) + '/videos').catch(function () { return null; });
			return Promise.all([info, videos]).then(function (r) { return { slug: partSlug, course: r[0], videos: r[1] }; });
		})).then(function (parts) {
			return parts.filter(function (x) { return x.course && x.videos && x.videos.lessons > 0; });
		});
	}

	function videoCard(part, index) {
		var v = part.videos;
		var percent = Math.floor(v.completed * 100 / Math.max(v.lessons, 1));
		var bar = el('div', { class: 'bp-hub-bar', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(percent), 'aria-label': t('trainer_video_meta', { done: v.completed, total: v.lessons }) }, [el('span', { style: 'width:' + percent + '%' })]);
		var img = courseImage(part.slug, 'hero');
		return el('article', { class: 'bp-hub-card bp-hub-card-video' + (img ? ' has-image' : '') }, [
			img ? el('img', { class: 'bp-hub-card-img', src: img, alt: '', loading: 'lazy' }) : el('span', { class: 'bp-hub-card-art bp-hub-art-' + (index % 2 ? 'anchor' : 'compass'), 'aria-hidden': 'true' }),
			el('p', { class: 'bp-hub-eyebrow', text: index === 0 ? t('hub_next') : t('hub_part') }),
			el('h2', { text: part.course.name.replace(/\s+(Vollkurs|Kombikurs)$/, '') }),
			el('p', { class: 'bp-hub-card-text', text: t('hub_video_for', { name: part.course.name.replace(/\s+(Vollkurs|Kombikurs)$/, '') }) }),
			el('p', { class: 'bp-hub-card-meta' }, [t('hub_video_progress', { percent: percent }), el('span', { text: ' · ' + t('trainer_video_meta', { done: v.completed, total: v.lessons }) })]),
			bar,
			el('a', { href: base + part.slug + '/video/', 'data-bp-link': '1', class: 'bp-button bp-button-light bp-hub-card-go' }, [v.completed > 0 ? t('hub_video_continue') : t('hub_video_start'), el('span', { class: 'bp-arrow', 'aria-hidden': 'true', text: '→' })])
		]);
	}

	function viewCourse(slug) {
		return api('GET', 'courses/' + encodeURIComponent(slug)).then(function (c) {
			return videoParts(c).then(function (parts) { renderCourseHub(slug, c, parts); });
		});
	}

	function renderCourseHub(slug, c, parts) {
		{
			var shop = c.shop || {};
			var p = c.progress ? c.progress.total : null;
			var qb = c.features.question_bank;
			var nodes = [courseHero(slug, c)];

			// Freischalten (gesperrt oder kostenloser Teilumfang)
			if ((!qb || (c.progress && c.progress.limit)) && shop.question_bank) {
				nodes.push(el('section', { class: 'bp-hub-unlock bp-locked' }, [
					el('span', { class: 'bp-ico bp-ico-lock', 'aria-hidden': 'true' }),
					el('div', {}, [el('h2', { text: qb ? t('hub_unlock_partial') : t('hub_unlock_title') }), el('p', { class: 'bp-muted', text: qb ? t('free_limit_questions', { n: c.progress.limit }) : t('locked_feature') })].concat(offerNodes(shop.question_bank)))
				]));
			}

			// Nächster Schritt + Prüfung | Fortschritt
			var next = el('article', { class: 'bp-hub-card bp-hub-card-next' }, [
				el('p', { class: 'bp-hub-eyebrow', text: t('hub_next') }),
				el('h2', { text: t('mode_smart') }),
				el('p', { class: 'bp-hub-card-text', text: p ? (p.new + p.unsure > 0 ? t('hub_next_text', { new: p.new, wrong: p.unsure }) : t('hub_next_done')) : t('locked_short') }),
				p ? el('div', { class: 'bp-hub-bar', 'aria-hidden': 'true' }, [el('span', { style: 'width:' + p.percent + '%' })]) : null,
				p ? el('p', { class: 'bp-hub-card-meta', text: t('progress_short', { mastered: p.mastered, total: p.questions }) }) : null,
				hubLink(slug, qb ? 'lernen/' : 'themen/', qb ? t('dash_continue') : t('sim_unlock'), 'bp-button bp-button-light')
			]);
			var lastExam = c.exam && c.exam.history && c.exam.history.length ? c.exam.history[0] : null;
			var exam = el('article', { class: 'bp-hub-card bp-hub-card-exam' }, [
				el('p', { class: 'bp-hub-eyebrow', text: t('hub_exam_eyebrow') }),
				el('h2', { text: t('simulation_title') }),
				el('p', { class: 'bp-hub-card-text', text: lastExam ? t('hub_exam_last', { score: lastExam.score, max: lastExam.max, verdict: lastExam.passed ? t('passed') : t('failed') }) : (c.exam && c.exam.available ? t('exam_rule_line', { questions: c.exam.rule.questions, passing: c.exam.rule.passing }) : t('exam_unavailable')) }),
				hubLink(slug, 'pruefung/', t('hub_exam_open'), 'bp-button bp-button-light')
			]);
			var progress = p ? el('aside', { class: 'bp-hub-progress', 'aria-labelledby': 'bp-hub-progress-h' }, [
				el('h2', { id: 'bp-hub-progress-h', text: t('hub_progress') }),
				el('div', { class: 'bp-hub-progress-body' }, [
					el('div', { class: 'bp-hub-ring-wrap' }, [ring(p.percent), el('p', {}, [el('strong', { text: p.percent + ' %' }), el('span', { text: t('course_hero_mastered') })])]),
					el('ul', { class: 'bp-hub-legend' }, [
						['mastered', t('status_mastered'), p.mastered], ['learning', t('status_learning'), p.learning], ['unsure', t('status_unsure'), p.unsure], ['new', t('hub_open'), p.new]
					].map(function (r) { return el('li', { class: 'is-' + r[0] }, [el('span', { class: 'bp-hub-dot', 'aria-hidden': 'true' }), el('span', { text: r[1] }), el('strong', { text: String(r[2]) })]); }))
				]),
				el('p', { class: 'bp-hub-hint' }, [el('span', { class: 'bp-ico bp-ico-flag', 'aria-hidden': 'true' }), p.mastered >= p.questions ? t('hub_hint_done') : t('hub_hint', { n: p.questions - p.mastered })]),
				el('div', { class: 'bp-hub-shortcuts' }, [
					el('a', { href: base + slug + '/lernen/?modus=wrong', 'data-bp-link': '1', 'aria-label': t('mode_wrong') + ' (' + p.unsure + ')' }, [el('span', { class: 'bp-ico bp-ico-repeat', 'aria-hidden': 'true' }), el('span', { 'aria-hidden': 'true', text: t('mode_wrong') }), el('strong', { 'aria-hidden': 'true', text: String(p.unsure) })]),
					el('a', { href: base + slug + '/lernen/?modus=favorites', 'data-bp-link': '1', 'aria-label': t('mode_favorites') + ' (' + c.favorites + ')' }, [el('span', { class: 'bp-ico bp-ico-bookmark', 'aria-hidden': 'true' }), el('span', { 'aria-hidden': 'true', text: t('mode_favorites') }), el('strong', { 'aria-hidden': 'true', text: String(c.favorites) })])
				])
			]) : null;
			// Oben immer die Videokurse (Kombi: je Teilkurs); ohne Videos: nächster Schritt + Prüfung.
			var cards = parts.length ? parts.map(videoCard) : [next, exam];
			nodes.push(el('div', { class: 'bp-hub-top' + (progress ? '' : ' is-single') }, [el('div', { class: 'bp-hub-cards' }, cards), progress]));

			// Lernbereiche
			var areas = [];
			var tr = c.trainers;
			var pct = function (a, b) { return b > 0 ? Math.floor(a * 100 / b) : null; };
			areas.push({ path: qb ? 'lernen/' : 'themen/', icon: 'spark', title: t('mode_smart'), meta: t('hub_smart_meta'), locked: !qb });
			areas.push({ path: 'themen/', icon: 'layers', title: t('hub_topics'), meta: c.progress ? t('hub_topics_meta', { n: c.progress.modules.length }) : t('locked_short'), locked: !qb, percent: p ? p.percent : null });
			areas.push({ path: 'pruefung/', icon: 'timer', title: t('section_exam'), meta: c.exam && c.exam.papers.length ? t('hub_exam_meta', { n: c.exam.papers.length }) : (c.exam ? t('hub_exam_meta_random') : t('locked_short')), locked: !c.exam });
			if (tr && tr.navigation.tasks > 0) {
				areas.push({ path: 'navigation/', icon: 'compass', title: t('trainer_navigation'), meta: t('trainer_navigation_meta', { done: tr.navigation.done, total: tr.navigation.questions, tasks: tr.navigation.tasks }), locked: c.features.navigation_trainer === false, percent: pct(tr.navigation.done, tr.navigation.questions) });
			}
			if (tr && tr.praxis.cards > 0) {
				areas.push({ path: 'praxis/', icon: 'knot', title: t('trainer_praxis'), meta: t('trainer_praxis_meta', { known: tr.praxis.known, total: tr.praxis.cards }), locked: c.features.praxis_trainer === false, percent: pct(tr.praxis.known, tr.praxis.cards) });
			}
			if (tr && tr.video.lessons > 0) {
				areas.push({ path: 'video/', icon: 'play', title: t('trainer_video'), meta: t('trainer_video_meta', { done: tr.video.completed, total: tr.video.lessons }), locked: c.features.video_course === false, percent: pct(tr.video.completed, tr.video.lessons) });
			}
			if (tr && tr.radio.texts + tr.radio.vocabulary > 0) {
				areas.push({ path: 'funk/', icon: 'radio', title: t('trainer_radio'), meta: t('trainer_radio_meta', { texts: tr.radio.texts, vocabulary: tr.radio.vocabulary }), locked: c.features.radio_training === false });
			}
			if (['SRC', 'UBI', 'SRC-UBI', 'ADD-UBI-TO-SRC'].indexOf(c.code) !== -1) {
				areas.push({ path: 'funk/geraet/', icon: 'radio', title: t('device_title'), meta: c.features.device_simulation ? t('device_tile_full') : t('sim_free_short'), badge: c.features.device_simulation ? null : t('sim_free_badge') });
			}
			if ((c.downloads || []).length) {
				areas.push({ path: 'downloads/', icon: 'download', title: t('section_downloads'), meta: t('hub_downloads_meta', { n: c.downloads.length }) });
			}
			if (c.exam_guide) {
				areas.push({ path: 'pruefung-anmelden/', icon: 'flag', title: t('guide_title'), meta: t('hub_guide_meta') });
			}
			nodes.push(el('section', { class: 'bp-section bp-hub-areas', 'aria-labelledby': 'bp-areas-h' }, [
				el('div', { class: 'bp-section-head' }, [el('h2', { id: 'bp-areas-h', text: t('hub_areas') }), el('p', { class: 'bp-muted', text: t('hub_areas_hint') })]),
				el('ul', { class: 'bp-areas' }, areas.map(function (a) {
					return el('li', { class: 'bp-area' + (a.locked ? ' bp-locked is-locked' : '') }, [
						el('span', { class: 'bp-ico bp-ico-' + a.icon, 'aria-hidden': 'true' }),
						el('div', { class: 'bp-area-text' }, [
							el('h3', {}, [hubLink(slug, a.path, a.title)]),
							el('p', { class: 'bp-area-meta' }, [a.meta, a.locked ? el('span', { class: 'bp-area-badge', text: t('locked_badge') }) : null, a.badge ? el('span', { class: 'bp-area-badge is-free', text: a.badge }) : null]),
							a.percent !== null && a.percent !== undefined && !a.locked ? el('span', { class: 'bp-area-bar', 'aria-hidden': 'true' }, [el('span', { style: 'width:' + a.percent + '%' })]) : null
						]),
						el('span', { class: 'bp-area-chevron', 'aria-hidden': 'true' })
					]);
				}))
			]));
			mount(nodes, c.name);
		}
	}

	function detailHeader(slug, c, title, intro) {
		return [crumbs(slug, c.name, [[title]]), el('header', { class: 'bp-header bp-detail-head' }, [el('p', { class: 'bp-learn-course', text: c.name }), el('h1', { text: title }), intro ? el('p', { class: 'bp-muted', text: intro }) : null])];
	}

	function viewTopics(slug) {
		return api('GET', 'courses/' + encodeURIComponent(slug)).then(function (c) {
			var nodes = detailHeader(slug, c, t('hub_topics'), t('smart_explain'));
			if (c.features.question_bank) {
				var modes = el('ul', { class: 'bp-modes' }, [
					modeTile(slug + '/lernen/', t('mode_smart'), null, t('mode_smart_desc'), 'spark', true),
					modeTile(slug + '/lernen/?modus=new', t('mode_new'), c.progress.total.new, t('mode_new_desc'), 'plus'),
					modeTile(slug + '/lernen/?modus=wrong', t('mode_wrong'), c.progress.total.unsure, t('mode_wrong_desc'), 'repeat'),
					modeTile(slug + '/lernen/?modus=favorites', t('mode_favorites'), c.favorites, t('mode_favorites_desc'), 'bookmark')
				]);
				var list = el('ul', { class: 'bp-module-list' });
				c.progress.modules.forEach(function (m) {
					list.appendChild(el('li', { class: 'bp-card bp-module' + (m.locked ? ' bp-locked' : '') }, [
						el('h4', { text: m.name }),
						m.locked ? el('p', { class: 'bp-muted', text: t('unlocks_on', { date: fmtDate(m.unlock_at) }) }) : el('p', { class: 'bp-module-pct' }, [el('strong', { text: m.percent + ' %' }), ' ' + t('course_hero_mastered')]),
						m.locked ? null : progressBar(m, t('progress_short', { mastered: m.mastered, total: m.questions })),
						m.locked ? null : el('p', { class: 'bp-module-go' }, [link(slug + '/lernen/?modul=' + m.id, t('learn_module'), 'bp-button')])
					]));
				});
				var limitNote = c.progress.limit ? el('div', { class: 'bp-card bp-locked' }, [el('p', { text: t('free_limit_questions', { n: c.progress.limit }) })].concat(offerNodes((c.shop || {}).question_bank))) : null;
				nodes.push(el('section', { class: 'bp-section bp-section-first' }, [modes, limitNote, el('h2', { class: 'bp-subhead', text: t('modules_title') }), list]));
			} else {
				nodes.push(lockedBox(t('section_learn'), (c.shop || {}).question_bank));
			}
			mount(nodes, t('hub_topics') + ' – ' + c.name);
		});
	}

	function viewExamHub(slug) {
		return api('GET', 'courses/' + encodeURIComponent(slug)).then(function (c) {
			mount(detailHeader(slug, c, t('simulation_title'), t('hub_exam_intro')).concat([c.exam ? examSection(slug, c.exam, c.shop || {}) : lockedBox(t('section_exam'), (c.shop || {}).exam_simulation)]), t('simulation_title') + ' – ' + c.name);
		});
	}

	function viewDownloads(slug) {
		return api('GET', 'courses/' + encodeURIComponent(slug)).then(function (c) {
			mount(detailHeader(slug, c, t('section_downloads')).concat([downloadSection(c.downloads || [], (c.shop || {}).downloads) || el('p', { text: t('hub_downloads_none') })]), t('section_downloads') + ' – ' + c.name);
		});
	}

	function examSection(slug, exam, shop) {
		shop = shop || {};
		var section = el('section', { class: 'bp-section bp-panel bp-exam-panel', id: 'bp-exam', 'aria-labelledby': 'bp-exam-h' }, [el('h2', { id: 'bp-exam-h' }, [el('span', { class: 'bp-ico bp-ico-timer', 'aria-hidden': 'true' }), t('section_exam')])]);
		if (!exam.available) {
			section.appendChild(el('p', { text: t('exam_unavailable') }));
			return section;
		}
		section.appendChild(el('p', { class: 'bp-exam-rule' }, [
			exam.live ? badge(t('exam_live'), 'ok') : badge(t('exam_practice'), 'warn'),
			' ',
			el('span', { text: t('exam_rule_line', { questions: exam.rule.questions, passing: exam.rule.passing }) })
		]));
		if (!exam.live) {
			section.appendChild(el('p', { class: 'bp-muted', text: t('exam_practice_explain') }));
		}
		if (exam.rule.note) {
			section.appendChild(el('p', { class: 'bp-note', text: exam.rule.note }));
		}
		if (exam.simulation_locked) {
			section.appendChild(el('div', { class: 'bp-card bp-locked' }, [el('p', { text: t('free_limit_papers', { n: exam.limit }) })].concat(offerNodes(shop.exam_simulation))));
		} else {
			var start = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: exam.running_simulation ? t('exam_resume') : t('exam_start') });
			start.addEventListener('click', function () {
				start.disabled = true;
				startExam(slug, null).catch(function (e) { start.disabled = false; alertMessage(section, e.message); });
			});
			section.appendChild(el('div', { class: 'bp-actions' }, [start]));
		}

		if (exam.papers.length) {
			section.appendChild(el('h3', { text: t('papers_title') }));
			var grid = el('ul', { class: 'bp-paper-grid' });
			exam.papers.forEach(function (p) {
				var state = p.last ? (p.last.status === 'running' ? 'running' : (p.last.passed ? 'passed' : 'failed')) : 'open';
				if (p.locked) {
					grid.appendChild(el('li', {}, [el('span', { class: 'bp-paper bp-paper-locked', 'aria-label': t('paper_aria', { number: p.number, state: t('locked_short') }) }, [el('span', { class: 'bp-paper-number', text: String(p.number) }), el('span', { class: 'bp-paper-state', text: t('locked_badge') })])]));
					return;
				}
				var btn = el('button', { type: 'button', class: 'bp-paper bp-paper-' + state, 'aria-label': t('paper_aria', { number: p.number, state: t('paper_state_' + state) }) }, [
					el('span', { class: 'bp-paper-number', text: String(p.number) }),
					el('span', { class: 'bp-paper-state', text: p.last && p.last.status !== 'running' ? p.last.score + '/' + p.last.max : t('paper_state_' + state) })
				]);
				btn.addEventListener('click', function () {
					btn.disabled = true;
					startExam(slug, p.id).catch(function (e) { btn.disabled = false; alertMessage(section, e.message); });
				});
				grid.appendChild(el('li', {}, [btn]));
			});
			section.appendChild(grid);
		}
		if (exam.history.length) {
			section.appendChild(el('h3', { text: t('history_title') }));
			var list = el('ul', { class: 'bp-history' });
			exam.history.forEach(function (h) {
				list.appendChild(el('li', {}, [
					link(slug + '/pruefung/' + h.session_id + '/', (h.paper_id ? t('paper_label') : t('simulation_label')) + ' · ' + h.score + '/' + h.max),
					' ',
					badge(h.passed ? t('passed') : t('failed'), h.passed ? 'ok' : 'bad')
				]));
			});
			section.appendChild(list);
		}
		return section;
	}

	function alertMessage(container, message) {
		var old = container.querySelector('.bp-inline-error');
		if (old) {
			old.remove();
		}
		container.appendChild(el('p', { class: 'bp-inline-error', role: 'alert', text: message }));
	}

	function startExam(slug, paperId) {
		return api('POST', 'courses/' + encodeURIComponent(slug) + '/exam-sessions', { paper_id: paperId }).then(function (r) {
			navigate(slug + '/pruefung/' + r.session_id + '/');
		});
	}

	// ------------------------------------------------------------------ Lernmodus

	function viewLearn(slug, moduleId, mode) {
		return api('GET', 'courses/' + encodeURIComponent(slug)).then(function (course) {
			var moduleName = null;
			if (moduleId && course.progress) {
				course.progress.modules.forEach(function (m) { if (String(m.id) === String(moduleId)) { moduleName = m.name; } });
			}
			var heading = moduleName || t('mode_' + mode);
			var container = el('div', { class: 'bp-learn' });
			mount([
				el('nav', { class: 'bp-breadcrumb', 'aria-label': t('breadcrumb') }, [link('', t('overview_title')), ' › ', link(slug + '/', course.name), ' › ', el('span', { text: heading })]),
				el('header', { class: 'bp-header bp-learn-head' }, [
					el('div', {}, [el('p', { class: 'bp-learn-course', text: course.name + (moduleName ? ' · ' + t('mode_' + mode) : '') }), el('h1', { text: heading })]),
					course.progress ? el('div', { class: 'bp-learn-progress' }, [el('p', {}, [el('strong', { text: course.progress.total.percent + ' %' }), ' ' + t('course_hero_mastered')]), progressBar(course.progress.total, t('progress_short', { mastered: course.progress.total.mastered, total: course.progress.total.questions }))]) : null
				]),
				container
			], heading + ' – ' + course.name);
			loadQuestion(slug, moduleId, mode, container);
		});
	}

	function loadQuestion(slug, moduleId, mode, container) {
		var params = new URLSearchParams({ mode: mode });
		if (moduleId) {
			params.set('module', moduleId);
		}
		container.textContent = '';
		container.appendChild(el('p', { class: 'bp-loading', text: t('loading') }));
		api('GET', 'courses/' + encodeURIComponent(slug) + '/next-question?' + params.toString()).then(function (r) {
			container.textContent = '';
			if (r.done) {
				container.appendChild(el('div', { class: 'bp-card bp-done' }, [
					el('h2', { text: t('done_title') }),
					el('p', { text: t('done_' + mode) }),
					el('p', {}, [link(slug + '/', t('back_course'), 'bp-button bp-button-primary'), ' ', mode !== 'smart' ? link(slug + '/lernen/' + (moduleId ? '?modul=' + moduleId : ''), t('mode_smart'), 'bp-button') : null])
				]));
				return;
			}
			container.appendChild(questionCard(slug, moduleId, mode, r.question, container));
		}).catch(function (e) {
			container.textContent = '';
			container.appendChild(el('p', { class: 'bp-inline-error', role: 'alert', text: e.message }));
		});
	}

	function questionMedia(media) {
		if (!media || !media.length) {
			return null;
		}
		return el('div', { class: 'bp-media' }, media.map(function (m) {
			return el('figure', {}, [el('img', { src: m.url, alt: m.alt, loading: 'lazy' }), m.caption ? el('figcaption', { text: m.caption }) : null]);
		}));
	}

	function answerList(q, name, selected, disabled) {
		var multi = q.type === 'multi_choice';
		var fieldset = el('fieldset', { class: 'bp-answers' }, [el('legend', { class: 'screen-reader-text bp-sr', text: multi ? t('choose_multi') : t('choose_single') })]);
		q.answers.forEach(function (a) {
			var input = el('input', { type: multi ? 'checkbox' : 'radio', name: name, value: String(a.id), id: name + '-' + a.id, disabled: disabled });
			if (selected && selected.indexOf(a.id) !== -1) {
				input.checked = true;
			}
			fieldset.appendChild(el('div', { class: 'bp-answer', 'data-answer': String(a.id) }, [
				input,
				el('label', { for: name + '-' + a.id }, [el('span', { class: 'bp-answer-key' + (multi ? ' is-multi' : ''), text: a.label }), el('span', { class: 'bp-answer-text', text: a.text })])
			]));
		});
		if (multi) {
			fieldset.appendChild(el('p', { class: 'bp-muted bp-hint', text: t('choose_multi') }));
		}
		return fieldset;
	}

	function selectedIds(fieldset) {
		return Array.prototype.map.call(fieldset.querySelectorAll('input:checked'), function (i) { return parseInt(i.value, 10); });
	}

	function markAnswers(fieldset, correctIds, chosenIds) {
		Array.prototype.forEach.call(fieldset.querySelectorAll('.bp-answer'), function (row) {
			var id = parseInt(row.getAttribute('data-answer'), 10);
			var isCorrect = correctIds.indexOf(id) !== -1;
			var chosen = chosenIds.indexOf(id) !== -1;
			if (isCorrect) {
				row.classList.add('bp-answer-correct');
				row.appendChild(el('span', { class: 'bp-answer-mark', text: t('mark_correct') }));
			} else if (chosen) {
				row.classList.add('bp-answer-wrong');
				row.appendChild(el('span', { class: 'bp-answer-mark', text: t('mark_wrong') }));
			}
			var input = row.querySelector('input');
			if (input) {
				input.disabled = true;
			}
		});
	}

	function favoriteButton(q) {
		var on = !!q.favorite;
		var label = el('span', { text: on ? t('fav_remove') : t('fav_add') });
		var btn = el('button', { type: 'button', class: 'bp-fav', 'aria-pressed': on ? 'true' : 'false' }, [el('span', { class: 'bp-ico bp-ico-bookmark', 'aria-hidden': 'true' }), label]);
		btn.addEventListener('click', function () {
			btn.disabled = true;
			api(on ? 'DELETE' : 'PUT', 'favorites/' + q.question_id).then(function () {
				on = !on;
				q.favorite = on;
				btn.setAttribute('aria-pressed', on ? 'true' : 'false');
				label.textContent = on ? t('fav_remove') : t('fav_add');
			}).finally(function () { btn.disabled = false; });
		});
		return btn;
	}

	function questionCard(slug, moduleId, mode, q, container) {
		var started = Date.now();
		var key = uuid();
		var name = 'bp-q-' + q.question_id;
		var meta = el('p', { class: 'bp-question-meta' }, [
			q.official_number ? el('span', { class: 'bp-pill bp-pill-strong', text: t('question_number', { number: q.official_number }) }) : null,
			q.topic ? el('span', { class: 'bp-pill', text: q.topic }) : null,
			el('span', { class: 'bp-pill bp-reason', text: q.reason }),
			q.remaining !== null && q.remaining !== undefined ? el('span', { class: 'bp-remaining', text: t('remaining', { n: q.remaining }) }) : null
		]);
		var card = el('article', { class: 'bp-card bp-question', 'aria-labelledby': name + '-text' }, [
			meta,
			multiline('h2', q.text, 'bp-question-text'),
			questionMedia(q.media)
		]);
		card.querySelector('h2').id = name + '-text';
		var feedback = el('div', { class: 'bp-feedback', 'aria-live': 'polite' });
		var actions = el('div', { class: 'bp-actions' });
		var next = el('button', { type: 'button', class: 'bp-button bp-button-primary bp-next' }, [t('next_question'), el('span', { class: 'bp-arrow', 'aria-hidden': 'true', text: '→' })]);
		next.addEventListener('click', function () { loadQuestion(slug, moduleId, mode, container); });

		function showResult(r, fieldset) {
			if (fieldset) {
				markAnswers(fieldset, r.correct_ids, r.selected_ids);
			}
			feedback.textContent = '';
			feedback.className = 'bp-feedback ' + (r.correct ? 'bp-feedback-ok' : 'bp-feedback-bad');
			feedback.appendChild(el('div', { class: 'bp-feedback-head' }, [el('span', { class: 'bp-feedback-icon', 'aria-hidden': 'true' }), el('p', { class: 'bp-feedback-title', text: r.correct ? t('result_correct') : t('result_wrong') })]));
			if (r.explanation) {
				feedback.appendChild(multiline('p', r.explanation, 'bp-explanation'));
			} else if (r.explanation_locked) {
				feedback.appendChild(el('p', { class: 'bp-muted', text: t('explanation_locked') }));
			}
			if (r.progress) {
				var dots = el('span', { class: 'bp-streak-dots', 'aria-hidden': 'true' });
				for (var i = 0; i < 3; i++) {
					dots.appendChild(el('span', { class: i < Math.min(r.progress.streak, 3) ? 'is-on' : '' }));
				}
				feedback.appendChild(el('p', { class: 'bp-feedback-status' }, [el('span', { class: 'bp-pill bp-status-' + r.progress.status, text: t('status_' + r.progress.status) }), dots, el('span', { class: 'bp-muted', text: t('streak', { n: r.progress.streak }) })]));
			}
			actions.textContent = '';
			actions.appendChild(next);
			actions.appendChild(favoriteButton(q));
			next.focus();
		}

		function send(body, fieldset, button) {
			button.disabled = true;
			body.token = q.token;
			body.idempotency_key = key;
			body.response_ms = Date.now() - started;
			return api('POST', 'answers', body).then(function (r) { showResult(r, fieldset); }).catch(function (e) {
				button.disabled = false;
				alertMessage(feedback, e.message);
			});
		}

		if (q.type === 'open_text') {
			var notes = el('textarea', { class: 'bp-notes', rows: '4', 'aria-label': t('own_answer'), placeholder: t('own_answer') });
			var reveal = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: t('reveal_answer') });
			card.appendChild(el('p', { class: 'bp-muted', text: t('open_explain') }));
			card.appendChild(notes);
			reveal.addEventListener('click', function () {
				reveal.disabled = true;
				api('POST', 'answers/reveal', { token: q.token }).then(function (r) {
					card.appendChild(el('div', { class: 'bp-model' }, [el('p', { class: 'bp-model-title', text: t('model_answer') }), multiline('p', r.model_answer)]));
					actions.textContent = '';
					var yes = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: t('known_yes') });
					var no = el('button', { type: 'button', class: 'bp-button', text: t('known_no') });
					yes.addEventListener('click', function () { no.disabled = true; send({ known: true }, null, yes); });
					no.addEventListener('click', function () { yes.disabled = true; send({ known: false }, null, no); });
					actions.appendChild(yes);
					actions.appendChild(no);
					yes.focus();
				}).catch(function (e) { reveal.disabled = false; alertMessage(feedback, e.message); });
			});
			actions.appendChild(reveal);
		} else {
			var fieldset = answerList(q, name, null, false);
			var check = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: t('check_answer'), disabled: true });
			fieldset.addEventListener('change', function () { check.disabled = selectedIds(fieldset).length === 0; });
			check.addEventListener('click', function () { send({ answer_ids: selectedIds(fieldset) }, fieldset, check); });
			card.appendChild(fieldset);
			actions.appendChild(check);
		}
		actions.appendChild(favoriteButton(q));
		card.appendChild(feedback);
		card.appendChild(actions);
		return card;
	}

	// ------------------------------------------------------------------ Spezialtrainer

	// Sperrhinweis mit passenden Shop-Produkten (Kap. 7.4); ohne Shop nur der Hinweis.
	function lockedBox(title, offers) {
		var nodes = [el('h2', { text: title }), el('p', { text: t('locked_feature') })];
		nodes = nodes.concat(offerNodes(offers));
		return el('section', { class: 'bp-card bp-locked' }, nodes);
	}

	function offerNodes(offers) {
		if (!offers || !offers.length) {
			return cfg.accountUrl ? [el('p', {}, [el('a', { href: cfg.accountUrl, text: t('my_access') })])] : [];
		}
		return [
			el('p', { class: 'bp-muted', text: t('unlock_with') }),
			el('ul', { class: 'bp-offers' }, offers.map(function (o) {
				return el('li', {}, [el('a', { href: o.url, class: 'bp-button', text: o.name }), o.price ? el('span', { class: 'bp-muted', text: ' ' + o.price }) : null]);
			}))
		];
	}

	// ------------------------------------------------------------------ Prüfung anmelden (Anleitung, Prüfungsausschüsse)

	var GUIDE_LINKS = { exam: '', navigation: 'navigation/', 'funk-oral': 'funk/', 'funk-praxis': 'funk/' };

	function viewExamGuide(slug, teil) {
		return withCourse(slug, function (c) {
			return api('GET', 'courses/' + encodeURIComponent(slug) + '/pruefung-anmelden').then(function (g) {
				var page = g.tabs.filter(function (x) { return x.key === teil; })[0] || g.tabs[0];
				var nodes = [crumbs(slug, c.name, [[t('guide_title')]])];
				if (g.tabs.length > 1) {
					nodes.push(el('ul', { class: 'bp-chips', 'aria-label': t('guide_tabs') }, g.tabs.map(function (x) {
						return el('li', {}, [link(slug + '/pruefung-anmelden/?teil=' + encodeURIComponent(x.key), x.tab, 'bp-chip' + (x === page ? ' is-active' : ''))]);
					})));
				}
				nodes.push(el('header', { class: 'bp-header' }, [el('h1', { text: page.title }), el('p', { class: 'bp-muted', text: page.intro })]));
				var focus = page.variants[0];
				if (focus.note) {
					nodes.push(el('p', { class: 'bp-note', text: focus.note }));
				}
				nodes.push(el('ul', { class: 'bp-stats' }, [
					el('li', {}, [el('strong', { text: t('guide_fee') }), ' ' + t('guide_approx', { value: page.fee }), page.fee_note ? el('small', { class: 'bp-muted', text: ' (' + page.fee_note + ')' }) : null]),
					page.lead_time ? el('li', {}, [el('strong', { text: t('guide_lead_time') }), ' ' + page.lead_time]) : null,
					el('li', {}, [el('strong', { text: t('guide_min_age') }), ' ' + page.min_age])
				]));
				var steps = el('ol', { class: 'bp-steps' }, [
					el('li', {}, [el('strong', { text: t('guide_step_apply') }), el('p', { text: page.apply })]),
					el('li', {}, [el('strong', { text: t('guide_step_documents') }), el('ul', {}, page.documents.map(function (d) { return el('li', { text: d }); }))]),
					el('li', {}, [el('strong', { text: t('guide_step_deadline') }), el('p', { text: page.deadline })]),
					el('li', {}, [el('strong', { text: t('guide_step_day') }), el('p', { text: t('guide_step_day_text') })])
				]);
				nodes.push(el('section', { class: 'bp-card' }, [el('h2', { text: t('guide_how') }), steps]));
				nodes.push(el('section', { class: 'bp-card' }, [el('h2', { text: t('guide_variants') })].concat(page.variants.map(function (v) {
					return el('div', { class: 'bp-variant' }, [
						el('h3', { text: v.label }),
						el('p', { text: v.scope + (v.minutes ? ' · ' + t('guide_minutes', { n: v.minutes }) : '') }),
						v.pass_text ? el('p', { class: 'bp-muted', text: t('guide_pass', { pass: v.pass_text }) }) : null,
						v.extra ? el('p', { class: 'bp-muted', text: v.extra }) : null,
						v !== focus && v.note ? el('p', { class: 'bp-muted', text: v.note }) : null
					]);
				}))));
				nodes.push(el('section', { class: 'bp-card' }, [el('h2', { text: t('guide_parts') }), el('ul', { class: 'bp-module-list' }, page.exam_parts.map(function (p) {
					var practice = p.link !== null && GUIDE_LINKS[p.link] !== undefined ? link(slug + '/' + GUIDE_LINKS[p.link], t('guide_practice'), 'bp-button') : null;
					return el('li', { class: 'bp-card bp-tile' }, [el('h3', { text: p.title }), el('p', { class: 'bp-muted', text: p.text }), practice]);
				}))]));
				nodes.push(committeeSection(page, g.committees));
				nodes.push(el('section', { class: 'bp-card' }, [el('h2', { text: t('guide_faq') })].concat(page.faq.map(function (f) {
					return el('details', { class: 'bp-faq' }, [el('summary', { text: f.question }), el('p', { text: f.answer })]);
				}))));
				nodes.push(el('p', { class: 'bp-muted', text: t('guide_disclaimer') }));
				mount(nodes, page.title);
			});
		});
	}

	function committeeSection(page, committees) {
		var list = el('ul', { class: 'bp-committees' });
		var input = el('input', { type: 'search', class: 'bp-input', 'aria-label': t('guide_committee_search'), placeholder: t('guide_committee_search') });
		function render() {
			var q = input.value.trim().toLowerCase();
			list.textContent = '';
			committees.filter(function (c) {
				return !q || [c.label, c.address, c.postal_code, c.city].join(' ').toLowerCase().indexOf(q) !== -1;
			}).forEach(function (c) {
				var contact = [c.phone ? t('guide_phone', { v: c.phone }) : null, c.office_hours].filter(Boolean).join(' · ');
				list.appendChild(el('li', { class: 'bp-card' }, [
					el('h3', { text: c.label }),
					c.address ? el('p', { text: c.address }) : null,
					c.contact_person ? el('p', { class: 'bp-muted', text: c.contact_person }) : null,
					contact ? el('p', { class: 'bp-muted', text: contact }) : null,
					el('p', {}, [c.email ? el('a', { href: 'mailto:' + c.email, text: c.email }) : null, c.email && c.website ? ' · ' : null, c.website ? el('a', { href: c.website, target: '_blank', rel: 'noopener', text: t('guide_website') }) : null]),
					c.note ? el('p', { class: 'bp-muted', text: c.note }) : null
				]));
			});
			if (!list.children.length) {
				list.appendChild(el('li', { class: 'bp-muted', text: t('guide_committee_none') }));
			}
		}
		input.addEventListener('input', render);
		render();
		return el('section', { class: 'bp-card' }, [
			el('h2', { text: t('guide_book') }),
			el('p', { class: 'bp-muted', text: t('guide_book_text') }),
			el('ul', { class: 'bp-providers' }, page.providers.map(function (p) {
				return el('li', { class: 'bp-card' }, [el('strong', { text: p.name }), el('span', { class: 'bp-muted', text: ' ' + p.full }), el('p', { text: p.text }), el('a', { href: p.url, target: '_blank', rel: 'noopener', class: 'bp-button', text: t('guide_to_provider', { name: p.name }) })]);
			})),
			el('h3', { text: t('guide_committees', { n: committees.length }) }),
			input,
			list
		]);
	}

	function downloadSection(items, offers) {
		if (!items.length) {
			return null;
		}
		var locked = items.filter(function (d) { return d.locked; }).length;
		return el('section', { class: 'bp-section', 'aria-labelledby': 'bp-downloads-h' }, [
			el('h2', { id: 'bp-downloads-h', text: t('section_downloads') }),
			el('ul', { class: 'bp-downloads' }, items.map(function (d) {
				var meta = (d.size ? ' · ' + Math.max(1, Math.round(d.size / 1024)) + ' KB' : '');
				return d.locked
					? el('li', { class: 'is-locked' }, [el('span', { text: d.title }), el('span', { class: 'bp-muted', text: ' · ' + t('locked_badge') })])
					: el('li', {}, [el('a', { href: d.url, target: '_blank', rel: 'noopener', text: d.title }), el('span', { class: 'bp-muted', text: meta })]);
			})),
			locked ? lockedNote(offers, t('locked_more', { n: locked })) : null
		]);
	}

	function lockedNote(offers, text) {
		return el('div', { class: 'bp-card bp-locked' }, [el('p', { text: text || t('locked_free') })].concat(offerNodes(offers)));
	}

	function lockedPaper(number) {
		return el('span', { class: 'bp-paper bp-paper-locked', 'aria-label': t('locked_free') }, [el('span', { class: 'bp-paper-number', text: String(number) }), el('span', { class: 'bp-paper-state', text: t('locked_badge') })]);
	}

	function fmtDate(utc) {
		if (!utc) {
			return '';
		}
		var d = new Date(String(utc).replace(' ', 'T') + 'Z');
		return isNaN(d.getTime()) ? String(utc) : d.toLocaleDateString(document.documentElement.lang || 'de-DE');
	}

	var TRAINER_FEATURES = { 'navigation/': 'navigation_trainer', 'praxis/': 'praxis_trainer', 'video/': 'video_course', 'funk/': 'radio_training' };

	function trainerSection(slug, tr, features, shop, code) {
		if (!tr) {
			return null;
		}
		var tiles = [];
		var icons = { 'navigation/': 'compass', 'praxis/': 'knot', 'video/': 'play', 'funk/': 'radio', 'funk/geraet/': 'spark' };
		if (tr.navigation.tasks > 0) {
			tiles.push(['navigation/', t('trainer_navigation'), t('trainer_navigation_meta', { done: tr.navigation.done, total: tr.navigation.questions, tasks: tr.navigation.tasks })]);
		}
		if (tr.praxis.cards > 0) {
			tiles.push(['praxis/', t('trainer_praxis'), t('trainer_praxis_meta', { known: tr.praxis.known, total: tr.praxis.cards })]);
		}
		if (tr.video.lessons > 0) {
			tiles.push(['video/', t('trainer_video'), t('trainer_video_meta', { done: tr.video.completed, total: tr.video.lessons })]);
		}
		if (tr.radio.texts + tr.radio.vocabulary > 0) {
			tiles.push(['funk/', t('trainer_radio'), t('trainer_radio_meta', { texts: tr.radio.texts, vocabulary: tr.radio.vocabulary })]);
		}
		// Funkgerät-Simulator: in allen Funkkursen, auch ohne Funktraining (z. B. nur Simulator-Pass gekauft).
		if (['SRC', 'UBI', 'SRC-UBI', 'ADD-UBI-TO-SRC'].indexOf(code) !== -1) {
			tiles.push(['funk/geraet/', t('device_title'), features && features.device_simulation ? t('device_tile_full') : t('sim_free_short')]);
		}
		if (!tiles.length) {
			return null;
		}
		return el('section', { class: 'bp-section', 'aria-labelledby': 'bp-trainer-h' }, [
			el('h2', { id: 'bp-trainer-h', text: t('section_trainers') }),
			el('ul', { class: 'bp-module-list' }, tiles.map(function (tile) {
				var feature = TRAINER_FEATURES[tile[0]];
				if (features && feature && features[feature] === false) {
					return el('li', { class: 'bp-card bp-tile bp-locked' }, [el('span', { class: 'bp-ico bp-ico-' + icons[tile[0]], 'aria-hidden': 'true' }), el('h3', { text: tile[1] }), el('p', { class: 'bp-muted', text: t('locked_short') })].concat(offerNodes(shop[feature])));
				}
				return el('li', { class: 'bp-card bp-tile' }, [el('span', { class: 'bp-ico bp-ico-' + icons[tile[0]], 'aria-hidden': 'true' }), el('div', { class: 'bp-tile-text' }, [el('h3', {}, [link(slug + '/' + tile[0], tile[1])]), el('p', { class: 'bp-muted', text: tile[2] })]), el('span', { class: 'bp-tile-arrow', 'aria-hidden': 'true', text: '→' })]);
			}))
		]);
	}

	function crumbs(slug, courseName, parts) {
		var nodes = [link('', t('overview_title')), ' › ', link(slug + '/', courseName)];
		parts.forEach(function (p) {
			nodes.push(' › ');
			nodes.push(p[1] ? link(p[1], p[0]) : el('span', { text: p[0] }));
		});
		return el('nav', { class: 'bp-breadcrumb', 'aria-label': t('breadcrumb') }, nodes);
	}

	function withCourse(slug, fn) {
		return api('GET', 'courses/' + encodeURIComponent(slug)).then(fn);
	}

	// Navigation
	function viewNavigation(slug) {
		return withCourse(slug, function (c) {
			return api('GET', 'courses/' + encodeURIComponent(slug) + '/navigation').then(function (tasks) {
				var list = el('ol', { class: 'bp-module-list' }, tasks.map(function (task) {
					if (task.locked) {
						return el('li', { class: 'bp-card bp-locked' }, [el('h2', { text: t('nav_task_title', { number: task.number }) }), el('p', { class: 'bp-muted', text: t('locked_free') })]);
					}
					return el('li', { class: 'bp-card' }, [
						el('h2', {}, [link(slug + '/navigation/' + task.id + '/', t('nav_task_title', { number: task.number }))]),
						el('p', { class: 'bp-muted', text: task.teaser + ' …' }),
						progressBar({ questions: task.questions, mastered: task.done, learning: 0, unsure: 0, percent: Math.floor(task.done * 100 / Math.max(task.questions, 1)) }, t('nav_progress', { done: task.done, total: task.questions }))
					]);
				}));
				var hasLocked = tasks.some(function (x) { return x.locked; });
				mount([crumbs(slug, c.name, [[t('trainer_navigation')]]), el('header', { class: 'bp-header' }, [el('h1', { text: t('trainer_navigation') }), el('p', { class: 'bp-muted', text: t('nav_intro') })]), chartNote(c), hasLocked ? lockedNote((c.shop || {}).navigation_trainer) : null, list], t('trainer_navigation') + ' – ' + c.name);
			});
		});
	}

	// Seekarten (BSH): Link nach Lizenz, sonst Hinweis auf die gedruckte Karte.
	function chartNote(c) {
		var charts = c.charts || [];
		if (!charts.length) {
			return null;
		}
		return el('div', { class: 'bp-card bp-chart-note' }, charts.map(function (ch) {
			return ch.url
				? el('p', {}, [el('a', { class: 'bp-button', href: ch.url, target: '_blank', rel: 'noopener', text: t('nav_chart_open', { title: ch.title }) })])
				: el('p', { class: 'bp-muted', text: t('nav_chart_pending', { title: ch.title }) });
		}));
	}

	function viewNavigationTask(slug, taskId) {
		return withCourse(slug, function (c) {
			return api('GET', 'courses/' + encodeURIComponent(slug) + '/navigation/' + taskId).then(function (task) {
				var items = el('ol', { class: 'bp-review' });
				task.questions.forEach(function (q) { items.appendChild(navigationQuestion(slug, q)); });
				var title = t('nav_task_title', { number: task.number });
				mount([
					crumbs(slug, c.name, [[t('trainer_navigation'), slug + '/navigation/'], [title]]),
					el('header', { class: 'bp-header' }, [el('h1', { text: title })]),
					el('div', { class: 'bp-card' }, [el('h2', { text: t('nav_scenario') }), multiline('p', task.scenario)]),
					task.hint ? el('p', { class: 'bp-note', text: task.hint }) : null,
					items,
					el('div', { class: 'bp-actions' }, [
						task.previous ? link(slug + '/navigation/' + task.previous + '/', t('nav_previous'), 'bp-button') : null,
						task.next ? link(slug + '/navigation/' + task.next + '/', t('nav_next'), 'bp-button bp-button-primary') : link(slug + '/navigation/', t('nav_all'), 'bp-button')
					]),
					task.source ? el('p', { class: 'bp-muted bp-source', text: t('source', { source: task.source }) }) : null
				], title + ' – ' + c.name);
			});
		});
	}

	function navigationQuestion(slug, q) {
		var item = el('li', { class: 'bp-card bp-nav-question' + (q.done ? ' is-correct' : '') });
		function render() {
			item.textContent = '';
			item.appendChild(el('p', { class: 'bp-question-meta' }, [el('span', { text: t('nav_question', { number: q.number }) }), q.self_assessment ? badge(t('assess_' + q.self_assessment), q.self_assessment === 'correct' ? 'ok' : (q.self_assessment === 'wrong' ? 'bad' : 'warn')) : null]));
			item.appendChild(multiline('h3', q.text, 'bp-question-text'));
			var area = el('textarea', { class: 'bp-notes', rows: '2', 'aria-label': t('nav_own_solution'), placeholder: t('nav_own_solution') });
			area.value = q.my_answer || '';
			item.appendChild(area);
			if (q.answer === null) {
				var reveal = el('button', { type: 'button', class: 'bp-button', text: t('nav_show_solution') });
				reveal.addEventListener('click', function () {
					reveal.disabled = true;
					api('POST', 'courses/' + encodeURIComponent(slug) + '/navigation-questions/' + q.id, { answer: area.value || null }).then(function (r) {
						q.answer = r.answer;
						q.my_answer = area.value;
						render();
					}).catch(function (e) { reveal.disabled = false; alertMessage(item, e.message); });
				});
				item.appendChild(el('div', { class: 'bp-actions' }, [reveal]));
				return;
			}
			item.appendChild(el('div', { class: 'bp-model' }, [el('p', { class: 'bp-model-title', text: t('nav_official') }), multiline('p', q.answer)]));
			var group = el('div', { class: 'bp-actions', role: 'group', 'aria-label': t('self_assessment') });
			['correct', 'partly', 'wrong'].forEach(function (a) {
				var b = el('button', { type: 'button', class: 'bp-button' + (q.self_assessment === a ? ' bp-button-primary' : ''), 'aria-pressed': q.self_assessment === a ? 'true' : 'false', text: t('assess_' + a) });
				b.addEventListener('click', function () {
					api('POST', 'courses/' + encodeURIComponent(slug) + '/navigation-questions/' + q.id, { answer: area.value || null, self_assessment: a }).then(function () {
						q.self_assessment = a;
						q.done = true;
						q.my_answer = area.value;
						item.classList.add('is-correct');
						render();
					}).catch(function (e) { alertMessage(item, e.message); });
				});
				group.appendChild(b);
			});
			item.appendChild(group);
		}
		render();
		return item;
	}

	// Praxis
	function viewPraxis(slug, category) {
		return withCourse(slug, function (c) {
			return api('GET', 'courses/' + encodeURIComponent(slug) + '/praxis').then(function (data) {
				var cats = data.categories;
				var active = cats.filter(function (x) { return x.name === category; })[0] || null;
				var nav = el('ul', { class: 'bp-chips', 'aria-label': t('praxis_categories') }, [el('li', {}, [link(slug + '/praxis/', t('praxis_all'), 'bp-chip' + (active ? '' : ' is-active'))])].concat(cats.map(function (x) {
					return el('li', {}, [link(slug + '/praxis/?kategorie=' + encodeURIComponent(x.name), x.name + ' (' + x.known + '/' + x.cards.length + ')', 'bp-chip' + (active === x ? ' is-active' : ''))]);
				})));
				var allCards = active ? active.cards : [].concat.apply([], cats.map(function (x) { return x.cards; }));
				var cards = allCards.filter(function (x) { return !x.locked; });
				var lockedCount = allCards.length - cards.length;
				var stage = el('div', { class: 'bp-praxis-stage' });
				var onlyOpen = el('input', { type: 'checkbox', id: 'bp-only-open' });
				var index = 0;
				function pool() {
					return onlyOpen.checked ? cards.filter(function (x) { return x.status !== 'known'; }) : cards;
				}
				function show() {
					var list = pool();
					stage.textContent = '';
					if (!list.length) {
						stage.appendChild(el('div', { class: 'bp-card bp-done' }, [el('h2', { text: t('done_title') }), el('p', { text: t('praxis_all_known') })]));
						return;
					}
					index = index % list.length;
					var card = list[index];
					var box = el('article', { class: 'bp-card bp-question' }, [
						el('p', { class: 'bp-question-meta' }, [el('span', { text: t('praxis_card_of', { n: index + 1, total: list.length }) }), card.reference ? el('span', { text: card.reference }) : null, card.status ? badge(t('praxis_status_' + card.status), card.status === 'known' ? 'ok' : 'warn') : null]),
						card.title ? el('h2', { text: card.title }) : null,
						card.image ? el('div', { class: 'bp-media' }, [el('img', { src: card.image.url, alt: card.image.alt || '', loading: 'lazy' })]) : null,
						multiline('p', card.question, 'bp-question-text')
					]);
					var actions = el('div', { class: 'bp-actions' });
					var reveal = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: t('praxis_show_answer') });
					reveal.addEventListener('click', function () {
						reveal.remove();
						box.insertBefore(el('div', { class: 'bp-model' }, [el('p', { class: 'bp-model-title', text: t('model_answer') }), multiline('p', card.answer), card.explanation ? multiline('p', card.explanation, 'bp-explanation') : null]), actions);
						[['known', t('praxis_known')], ['practice', t('praxis_practice')]].forEach(function (opt) {
							var b = el('button', { type: 'button', class: 'bp-button' + (opt[0] === 'known' ? ' bp-button-primary' : ''), text: opt[1] });
							b.addEventListener('click', function () {
								api('PUT', 'courses/' + encodeURIComponent(slug) + '/praxis/' + card.id, { status: opt[0] }).then(function () {
									card.status = opt[0];
									if (!(onlyOpen.checked && opt[0] === 'known')) {
										index++;
									}
									show();
								});
							});
							actions.appendChild(b);
						});
					});
					actions.appendChild(reveal);
					box.appendChild(actions);
					if (card.source) {
						box.appendChild(el('p', { class: 'bp-muted bp-source', text: t('source', { source: card.source }) }));
					}
					stage.appendChild(box);
				}
				onlyOpen.addEventListener('change', function () { index = 0; show(); });
				mount([
					crumbs(slug, c.name, [[t('trainer_praxis')]]),
					el('header', { class: 'bp-header' }, [el('h1', { text: active ? active.name : t('trainer_praxis') }), el('p', { class: 'bp-muted', text: t('trainer_praxis_meta', { known: data.known, total: data.total }) })]),
					lockedCount ? lockedNote((c.shop || {}).praxis_trainer, t('locked_more', { n: lockedCount })) : null,
					nav,
					el('p', {}, [onlyOpen, ' ', el('label', { for: 'bp-only-open', text: t('praxis_only_open') })]),
					stage
				], t('trainer_praxis') + ' – ' + c.name);
				show();
			});
		});
	}

	// Video
	function duration(sec) {
		if (sec === null || sec === undefined) {
			return '';
		}
		return Math.floor(sec / 60) + ':' + ('0' + (sec % 60)).slice(-2);
	}

	// Videokurs: Kapitelmenü links (Fortschritt, aufklappbare Kapitel), Player rechts.
	// /video/ öffnet die nächste offene Lektion, /video/<id>/ eine bestimmte.
	function viewVideos(slug) {
		return videoPlayer(slug, null);
	}

	function viewVideoLesson(slug, lessonId) {
		return videoPlayer(slug, lessonId);
	}

	function videoPlayer(slug, lessonId) {
		return withCourse(slug, function (c) {
			return api('GET', 'courses/' + encodeURIComponent(slug) + '/videos').then(function (v) {
				var firstOpen = null;
				v.modules.forEach(function (m) { m.lessons.forEach(function (l) { if (!firstOpen && !l.locked) { firstOpen = l.id; } }); });
				var id = lessonId || v.next_lesson || firstOpen;
				if (!id) {
					mount([crumbs(slug, c.name, [[t('trainer_video')]]), videoLayout(slug, c, v, null, el('div', { class: 'bp-vc-main' }, [el('h1', { text: t('trainer_video') }), el('p', { class: 'bp-muted', text: t('video_none') })]))], t('trainer_video') + ' – ' + c.name);
					return null;
				}
				return api('GET', 'courses/' + encodeURIComponent(slug) + '/videos/' + id).then(function (l) {
					renderLesson(slug, c, v, l);
				});
			});
		});
	}

	var VC_COLLAPSED = 'bpVideoMenuCollapsed';

	function videoLayout(slug, c, v, activeId, main) {
		var percent = Math.floor(v.completed * 100 / Math.max(v.lessons, 1));
		var fill = el('span', { class: 'bp-vc-bar-fill' });
		fill.style.width = percent + '%';
		var count = el('span', { class: 'bp-vc-count', text: t('video_lessons_count', { done: v.completed, total: v.lessons }) });
		var pct = el('span', { class: 'bp-vc-pct', text: percent + ' %' });
		var layout = el('div', { class: 'bp-vc' });
		var collapsed = false;
		try { collapsed = window.localStorage.getItem(VC_COLLAPSED) === '1'; } catch (e) { collapsed = false; }
		var toggle = el('button', { type: 'button', class: 'bp-vc-toggle', 'aria-expanded': collapsed ? 'false' : 'true', 'aria-controls': 'bp-vc-chapters', title: t('video_toggle_menu') }, [el('span', { class: 'bp-sr', text: t('video_toggle_menu') })]);
		var chapters = el('div', { class: 'bp-vc-chapters', id: 'bp-vc-chapters' }, v.modules.map(function (m) {
			var done = m.lessons.length > 0 && m.lessons.every(function (l) { return l.completed; });
			var hasActive = m.lessons.some(function (l) { return l.id === activeId; });
			return el('details', { class: 'bp-vc-chapter' + (done ? ' is-done' : '') + (m.locked ? ' is-locked' : ''), open: hasActive || (!activeId && !m.locked) }, [
				el('summary', {}, [el('span', { class: 'bp-vc-dot', 'aria-hidden': 'true' }), el('span', { class: 'bp-vc-chapter-title', text: m.title }), done ? el('span', { class: 'bp-sr', text: t('video_done') }) : null]),
				m.locked ? el('p', { class: 'bp-vc-note', text: t('unlocks_on', { date: fmtDate(m.unlock_at) }) }) : null,
				el('ol', { class: 'bp-vc-lessons' }, m.lessons.map(function (l) {
					var inner = [el('span', { class: 'bp-vc-dot', 'aria-hidden': 'true' }), el('span', { class: 'bp-vc-lesson-title', text: l.title }), el('span', { class: 'bp-vc-time', text: l.locked ? t('locked_badge') : duration(l.duration) })];
					if (l.completed) {
						inner.push(el('span', { class: 'bp-lesson-done bp-sr', text: t('video_done') }));
					}
					var cls = 'bp-vc-lesson' + (l.completed ? ' is-done' : '') + (l.id === activeId ? ' is-active' : '') + (l.locked ? ' is-locked' : '');
					if (l.locked) {
						return el('li', {}, [el('span', { class: cls, title: m.locked ? null : t('locked_free') }, inner)]);
					}
					var a = el('a', { href: base + slug + '/video/' + l.id + '/', class: cls, 'data-bp-link': '1', 'data-lesson': String(l.id), title: l.title, 'aria-current': l.id === activeId ? 'page' : null }, inner);
					return el('li', {}, [a]);
				}))
			]);
		}));
		var side = el('aside', { class: 'bp-vc-side', 'aria-label': t('video_chapters') }, [
			el('div', { class: 'bp-vc-head' }, [el('span', { class: 'bp-vc-icon', 'aria-hidden': 'true' }), el('div', { class: 'bp-vc-head-text' }, [el('p', { class: 'bp-vc-kicker', text: t('trainer_video') }), el('p', { class: 'bp-vc-course', text: c.name })]), toggle]),
			el('div', { class: 'bp-vc-progress' }, [el('div', { class: 'bp-vc-bar', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(percent), 'aria-label': t('trainer_video_meta', { done: v.completed, total: v.lessons }) }, [fill]), el('p', { class: 'bp-vc-meta' }, [count, pct])]),
			chapters
		]);
		function apply() {
			layout.classList.toggle('is-collapsed', collapsed);
			toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
		}
		toggle.addEventListener('click', function () {
			collapsed = !collapsed;
			try { window.localStorage.setItem(VC_COLLAPSED, collapsed ? '1' : '0'); } catch (e) { /* egal */ }
			apply();
		});
		apply();
		layout.appendChild(side);
		layout.appendChild(main);
		// Fortschritt nach „abgeschlossen“ ohne Neuladen aktualisieren.
		layout.setDone = function (lessonId, isDone) {
			var item = layout.querySelector('[data-lesson="' + lessonId + '"]');
			if (!item || item.classList.contains('is-done') === isDone) {
				return;
			}
			item.classList.toggle('is-done', isDone);
			v.completed += isDone ? 1 : -1;
			var p = Math.floor(v.completed * 100 / Math.max(v.lessons, 1));
			fill.style.width = p + '%';
			pct.textContent = p + ' %';
			count.textContent = t('video_lessons_count', { done: v.completed, total: v.lessons });
			var bar = layout.querySelector('.bp-vc-bar');
			bar.setAttribute('aria-valuenow', String(p));
			bar.setAttribute('aria-label', t('trainer_video_meta', { done: v.completed, total: v.lessons }));
			var doneText = item.querySelector('.bp-lesson-done');
			if (isDone && !doneText) {
				item.appendChild(el('span', { class: 'bp-lesson-done bp-sr', text: t('video_done') }));
			} else if (!isDone && doneText) {
				doneText.remove();
			}
			var chapter = item.closest('details');
			chapter.classList.toggle('is-done', chapter.querySelectorAll('.bp-vc-lesson').length === chapter.querySelectorAll('.bp-vc-lesson.is-done').length);
		};
		return layout;
	}

	function renderLesson(slug, c, v, l) {
		var path = 'courses/' + encodeURIComponent(slug) + '/videos/' + l.id + '/progress';
		var video = l.video_url ? el('video', { class: 'bp-video', controls: true, preload: 'metadata', src: l.video_url }) : null;
		var status = el('span', { class: 'bp-vc-status', text: l.completed ? t('video_status_done') : t('video_status_open') });
		var doneBtn = el('button', { type: 'button', class: 'bp-vc-mark', 'aria-pressed': l.completed ? 'true' : 'false', text: l.completed ? t('video_done') : t('video_mark_done') });
		var layout;
		function setDone(done) {
			l.completed = done;
			doneBtn.textContent = done ? t('video_done') : t('video_mark_done');
			doneBtn.setAttribute('aria-pressed', done ? 'true' : 'false');
			status.textContent = done ? t('video_status_done') : t('video_status_open');
			status.classList.toggle('is-done', done);
			layout.setDone(l.id, done);
		}
		status.classList.toggle('is-done', !!l.completed);
		function saveProgress(completed) {
			return api('PUT', path, { position: video ? Math.floor(video.currentTime || 0) : 0, completed: completed });
		}
		doneBtn.addEventListener('click', function () {
			saveProgress(!l.completed).then(function (r) { setDone(!!r.completed); });
		});
		if (video) {
			video.addEventListener('loadedmetadata', function () {
				if (l.position > 0 && l.position < (video.duration || 0) - 2) {
					video.currentTime = l.position;
				}
			});
			video.addEventListener('pause', function () { saveProgress(null); });
			video.addEventListener('ended', function () {
				saveProgress(true).then(function () { setDone(true); });
			});
			timers.push(setInterval(function () { if (!video.paused) { saveProgress(null); } }, 15000));
		}
		// Titelkarte über dem Video (Kapitel, Lektion, Kurs) bis zum Start
		var poster = null;
		if (video) {
			poster = el('button', { type: 'button', class: 'bp-vc-poster', 'aria-label': t('video_play', { title: l.title }) }, [
				el('span', { class: 'bp-vc-poster-kicker', text: t('video_chapter') }),
				el('span', { class: 'bp-vc-poster-title', text: l.module }),
				el('span', { class: 'bp-vc-poster-sub', text: c.name }),
				el('span', { class: 'bp-vc-poster-play', 'aria-hidden': 'true' })
			]);
			poster.addEventListener('click', function () {
				var p = video.play();
				if (p && p.catch) { p.catch(function () { /* Wiedergabe blockiert: Steuerung bleibt sichtbar */ }); }
				poster.hidden = true;
				video.focus();
			});
			video.addEventListener('play', function () { poster.hidden = true; });
		}
		var quiz = null;
		var cta = null;
		if (l.quiz.length) {
			quiz = el('section', { class: 'bp-section bp-vc-quiz', id: 'bp-vc-quiz', tabindex: '-1' }, [el('h2', { text: t('video_quiz') })].concat(l.quiz.map(function (q) { return quizQuestion(q); })));
			cta = el('button', { type: 'button', class: 'bp-button bp-button-primary bp-vc-cta' }, [t('video_to_quiz', { n: l.quiz.length }), el('span', { class: 'bp-vc-arrow', 'aria-hidden': 'true', text: '→' })]);
			cta.addEventListener('click', function () {
				quiz.scrollIntoView({ behavior: 'smooth', block: 'start' });
				quiz.focus({ preventScroll: true });
			});
		} else if (l.next) {
			cta = el('a', { href: base + slug + '/video/' + l.next + '/', class: 'bp-button bp-button-primary bp-vc-cta', 'data-bp-link': '1' }, [t('video_next'), el('span', { class: 'bp-vc-arrow', 'aria-hidden': 'true', text: '→' })]);
		}
		var main = el('div', { class: 'bp-vc-main' }, [
			el('p', { class: 'bp-vc-module', text: l.module }),
			el('h1', { class: 'bp-vc-title', text: l.title }),
			l.placeholder ? el('p', { class: 'bp-note', text: t('video_placeholder') }) : null,
			video ? el('div', { class: 'bp-video-wrap' }, [video, poster]) : el('p', { class: 'bp-vc-missing', text: t('video_missing') }),
			el('div', { class: 'bp-vc-bar-row' }, [el('div', { class: 'bp-vc-state' }, [status, doneBtn]), cta]),
			l.steps.length ? el('section', { class: 'bp-section' }, [el('h2', { text: t('video_steps') }), el('ol', { class: 'bp-steps' }, l.steps.map(function (st) { return el('li', { text: st.title }); }))]) : null,
			quiz,
			(l.previous || l.next) ? el('nav', { class: 'bp-vc-pager', 'aria-label': t('video_chapters') }, [
				l.previous ? link(slug + '/video/' + l.previous + '/', '← ' + t('prev'), 'bp-button') : el('span'),
				l.next ? link(slug + '/video/' + l.next + '/', t('video_next') + ' →', 'bp-button') : null
			]) : null
		]);
		layout = videoLayout(slug, c, v, l.id, main);
		mount([crumbs(slug, c.name, [[t('trainer_video'), slug + '/video/'], [l.title]]), layout], l.title + ' – ' + c.name);
		var active = layout.querySelector('.bp-vc-lesson.is-active');
		var list = layout.querySelector('.bp-vc-chapters');
		if (active && list && list.scrollHeight > list.clientHeight) {
			list.scrollTop = Math.max(0, active.offsetTop - list.offsetTop - 80);
		}
	}

	function quizQuestion(q) {
		var key = uuid();
		var started = Date.now();
		var name = 'bp-quiz-' + q.question_id;
		var card = el('article', { class: 'bp-card bp-question' }, [multiline('h3', q.text, 'bp-question-text'), questionMedia(q.media)]);
		var feedback = el('div', { class: 'bp-feedback', 'aria-live': 'polite' });
		var actions = el('div', { class: 'bp-actions' });
		function result(r, fieldset) {
			if (fieldset) {
				markAnswers(fieldset, r.correct_ids, r.selected_ids);
			}
			feedback.className = 'bp-feedback ' + (r.correct ? 'bp-feedback-ok' : 'bp-feedback-bad');
			feedback.textContent = '';
			feedback.appendChild(el('p', { class: 'bp-feedback-title', text: r.correct ? t('result_correct') : t('result_wrong') }));
			if (r.explanation) {
				feedback.appendChild(multiline('p', r.explanation, 'bp-explanation'));
			}
			actions.textContent = '';
		}
		function send(body, fieldset, button) {
			button.disabled = true;
			body.token = q.token;
			body.idempotency_key = key;
			body.response_ms = Date.now() - started;
			api('POST', 'answers', body).then(function (r) { result(r, fieldset); }).catch(function (e) { button.disabled = false; alertMessage(feedback, e.message); });
		}
		if (q.type === 'open_text') {
			var reveal = el('button', { type: 'button', class: 'bp-button', text: t('reveal_answer') });
			reveal.addEventListener('click', function () {
				reveal.disabled = true;
				api('POST', 'answers/reveal', { token: q.token }).then(function (r) {
					card.insertBefore(el('div', { class: 'bp-model' }, [el('p', { class: 'bp-model-title', text: t('model_answer') }), multiline('p', r.model_answer)]), actions);
					actions.textContent = '';
					var yes = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: t('known_yes') });
					var no = el('button', { type: 'button', class: 'bp-button', text: t('known_no') });
					yes.addEventListener('click', function () { send({ known: true }, null, yes); });
					no.addEventListener('click', function () { send({ known: false }, null, no); });
					actions.appendChild(yes);
					actions.appendChild(no);
				});
			});
			actions.appendChild(reveal);
		} else {
			var fieldset = answerList(q, name, null, false);
			var check = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: t('check_answer'), disabled: true });
			fieldset.addEventListener('change', function () { check.disabled = selectedIds(fieldset).length === 0; });
			check.addEventListener('click', function () { send({ answer_ids: selectedIds(fieldset) }, fieldset, check); });
			card.appendChild(fieldset);
			actions.appendChild(check);
		}
		card.appendChild(actions);
		card.appendChild(feedback);
		return card;
	}

	// Funk
	function speakButton(text, lang) {
		if (cfg.voice === 'off' || !('speechSynthesis' in window)) {
			return null;
		}
		var b = el('button', { type: 'button', class: 'bp-button', text: t('radio_read_aloud') });
		b.addEventListener('click', function () {
			window.speechSynthesis.cancel();
			var u = new window.SpeechSynthesisUtterance(text);
			u.lang = lang;
			u.rate = 0.9;
			window.speechSynthesis.speak(u);
		});
		return b;
	}

	function viewRadio(slug) {
		return withCourse(slug, function (c) {
			return api('GET', 'courses/' + encodeURIComponent(slug) + '/radio').then(function (o) {
				var nodes = [crumbs(slug, c.name, [[t('trainer_radio')]]), el('header', { class: 'bp-header' }, [el('h1', { text: t('trainer_radio') }), el('p', { class: 'bp-muted', text: t('radio_intro') })])];
				if (o.device && o.device.lessons > 0) {
					nodes.push(el('section', { class: 'bp-card bp-tile' }, [
						el('h2', {}, [link(slug + '/funk/geraet/', t('device_title')), o.device.premium === false ? ' ' : null, o.device.premium === false ? badge(t('sim_free_badge'), 'warn') : null]),
						el('p', { class: 'bp-muted', text: o.device.premium === false ? t('sim_free_short') : t('device_meta', { lessons: o.device.lessons, mastered: o.device.mastered }) }),
						el('p', {}, [link(slug + '/funk/geraet/', t('device_open'), 'bp-button bp-button-primary')])
					]));
				}
				(o.practice || []).forEach(function (g) {
					var done = g.tasks.filter(function (x) { return x.done; }).length;
					nodes.push(el('section', { class: 'bp-section' }, [
						el('h2', { text: t('practice_title', { catalog: g.catalog }) }),
						el('p', { class: 'bp-muted', text: t('practice_explain') + ' ' + t('radio_done_count', { done: done, total: g.tasks.length }) }),
						el('ul', { class: 'bp-paper-grid' }, g.tasks.map(function (x) {
							if (x.locked) {
								return el('li', {}, [lockedPaper(x.number)]);
							}
							return el('li', {}, [el('a', { href: base + slug + '/funk/praxis/' + x.id + '/', 'data-bp-link': '1', class: 'bp-paper' + (x.done ? ' bp-paper-passed' : (x.evaluated ? ' bp-paper-running' : '')), title: x.teaser, 'aria-label': t('practice_aria', { number: x.number, kind: t('kind_' + x.kind), state: x.done ? t('radio_done') : t('paper_state_open') }) }, [el('span', { class: 'bp-paper-number', text: String(x.number) }), el('span', { class: 'bp-paper-state', text: t('kind_' + x.kind) })])]);
						}))
					]));
				});
				o.texts.forEach(function (g) {
					var done = g.items.filter(function (i) { return i.done; }).length;
					nodes.push(el('section', { class: 'bp-section' }, [
						el('h2', { text: t('radio_type_' + g.type, { catalog: g.catalog }) }),
						el('p', { class: 'bp-muted', text: t('radio_type_' + g.type + '_explain') + ' ' + t('radio_done_count', { done: done, total: g.items.length }) }),
						el('ul', { class: 'bp-paper-grid' }, g.items.map(function (i) {
							if (i.locked) {
								return el('li', {}, [lockedPaper(i.number)]);
							}
							return el('li', {}, [el('a', { href: base + slug + '/funk/text/' + i.id + '/', 'data-bp-link': '1', class: 'bp-paper' + (i.done ? ' bp-paper-passed' : ''), title: i.teaser, 'aria-label': t('radio_text_aria', { number: i.number, state: i.done ? t('radio_done') : t('paper_state_open') }) }, [el('span', { class: 'bp-paper-number', text: String(i.number) }), el('span', { class: 'bp-paper-state', text: i.done ? t('radio_done') : (i.direction ? t('radio_direction_' + i.direction) : '') })])]);
						}))
					]));
				});
				if (o.vocabulary.length) {
					nodes.push(el('section', { class: 'bp-section' }, [
						el('h2', { text: t('radio_vocabulary') }),
						el('ul', { class: 'bp-module-list' }, o.vocabulary.map(function (v) {
							return el('li', { class: 'bp-card' }, [
								el('h3', { text: v.category }),
								progressBar({ questions: v.total, mastered: v.learned, learning: 0, unsure: 0, percent: Math.floor(v.learned * 100 / Math.max(v.total, 1)) }, t('radio_learned', { learned: v.learned, total: v.total })),
								el('p', {}, [link(slug + '/funk/vokabeln/?katalog=' + v.catalog + '&kategorie=' + encodeURIComponent(v.category), t('radio_practice'), 'bp-button')])
							]);
						}))
					]));
				}
				mount(nodes, t('trainer_radio') + ' – ' + c.name);
			});
		});
	}

	function evaluationBox(label, ev) {
		if (!ev) {
			return null;
		}
		return el('div', { class: 'bp-card' }, [
			el('p', { class: 'bp-feedback-title', text: label + ': ' + t('radio_percent', { percent: ev.percent }) }),
			ev.missing.length ? el('p', { class: 'bp-muted', text: t('radio_missing', { words: ev.missing.join(', ') }) }) : el('p', { class: 'bp-muted', text: t('radio_complete') })
		]);
	}

	function viewRadioText(slug, key, direction) {
		return withCourse(slug, function (c) {
			return api('GET', 'courses/' + encodeURIComponent(slug) + '/radio/texts/' + encodeURIComponent(key)).then(function (x) {
				var title = t('radio_type_' + x.type, { catalog: x.catalog }) + ' ' + x.number;
				var body = el('div', { class: 'bp-card' });
				var result = el('div', { class: 'bp-radio-result', 'aria-live': 'polite' });
				var doneBtn = el('button', { type: 'button', class: 'bp-button', 'aria-pressed': x.done ? 'true' : 'false', text: x.done ? t('radio_done') : t('radio_mark_done') });
				doneBtn.addEventListener('click', function () {
					api('PUT', 'courses/' + encodeURIComponent(slug) + '/radio/texts/' + x.id + '/done', { done: !x.done }).then(function (r) {
						x.done = r.done;
						doneBtn.textContent = x.done ? t('radio_done') : t('radio_mark_done');
						doneBtn.setAttribute('aria-pressed', x.done ? 'true' : 'false');
					});
				});
				if (x.type === 'spelling') {
					body.appendChild(el('p', { class: 'bp-muted', text: t('radio_type_spelling_explain') }));
					var list = el('ol', { class: 'bp-spelling' });
					x.lines.forEach(function (line) {
						var li = el('li', {}, [el('span', { class: 'bp-spell-line', text: line.text })]);
						line.spell.forEach(function (s) {
							var hidden = el('span', { class: 'bp-spell-solution', hidden: true, text: s.word + ': ' + s.spelled });
							li.appendChild(el('br'));
							li.appendChild(el('mark', { text: s.word }));
							li.appendChild(document.createTextNode(' '));
							li.appendChild(hidden);
						});
						list.appendChild(li);
					});
					var show = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: t('radio_show_spelling') });
					show.addEventListener('click', function () {
						Array.prototype.forEach.call(list.querySelectorAll('.bp-spell-solution'), function (n) { n.hidden = false; });
						show.remove();
					});
					body.appendChild(list);
					body.appendChild(el('div', { class: 'bp-actions' }, [show]));
				} else {
					var translation = x.type === 'translation';
					var from = translation && direction === 'en-de' ? x.text_en : x.text_de;
					var lang = translation && direction === 'en-de' ? 'en-GB' : 'de-DE';
					var answers = translation ? ((x.answers && x.answers[direction]) || {}) : (x.answers || {});
					if (translation) {
						body.appendChild(el('p', { class: 'bp-actions' }, [
							link(slug + '/funk/text/' + x.id + '/?richtung=de-en', t('radio_direction_de_en'), 'bp-chip' + (direction === 'de-en' ? ' is-active' : '')),
							link(slug + '/funk/text/' + x.id + '/?richtung=en-de', t('radio_direction_en_de'), 'bp-chip' + (direction === 'en-de' ? ' is-active' : ''))
						]));
					}
					body.appendChild(el('p', { class: 'bp-muted', text: t('radio_type_' + x.type + '_explain') }));
					var source = multiline('p', from, 'bp-radio-source');
					source.hidden = cfg.voice !== 'off';
					var toggle = el('button', { type: 'button', class: 'bp-button', text: t('radio_show_text') });
					toggle.addEventListener('click', function () { source.hidden = !source.hidden; toggle.textContent = source.hidden ? t('radio_show_text') : t('radio_hide_text'); });
					body.appendChild(el('div', { class: 'bp-actions' }, [speakButton(from, lang), cfg.voice !== 'off' ? toggle : null]));
					body.appendChild(source);
					var transcript = el('textarea', { class: 'bp-notes', rows: '4', id: 'bp-transcript' });
					transcript.value = answers.transcript || '';
					body.appendChild(el('label', { for: 'bp-transcript', class: 'bp-label', text: t('radio_transcript') }));
					body.appendChild(transcript);
					var trans = null;
					if (translation) {
						trans = el('textarea', { class: 'bp-notes', rows: '4', id: 'bp-translation' });
						trans.value = answers.translation || '';
						body.appendChild(el('label', { for: 'bp-translation', class: 'bp-label', text: t('radio_translation') }));
						body.appendChild(trans);
					}
					var evalBtn = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: t('radio_evaluate') });
					evalBtn.addEventListener('click', function () {
						evalBtn.disabled = true;
						api('POST', 'courses/' + encodeURIComponent(slug) + '/radio/texts/' + x.id + '/evaluate', { direction: translation ? direction : '', transcript: transcript.value, translation: trans ? trans.value : '' }).then(function (r) {
							result.textContent = '';
							result.appendChild(evaluationBox(t('radio_transcript'), r.transcript) || el('span'));
							var tr = evaluationBox(t('radio_translation'), r.translation);
							if (tr) {
								result.appendChild(tr);
							}
							result.appendChild(el('div', { class: 'bp-model' }, [el('p', { class: 'bp-model-title', text: t('radio_solution') }), multiline('p', r.solution.from), r.solution.to ? multiline('p', r.solution.to) : null]));
						}).catch(function (e) { alertMessage(result, e.message); }).finally(function () { evalBtn.disabled = false; });
					});
					body.appendChild(el('div', { class: 'bp-actions' }, [evalBtn]));
				}
				mount([
					crumbs(slug, c.name, [[t('trainer_radio'), slug + '/funk/'], [title]]),
					el('header', { class: 'bp-header bp-header-compact' }, [el('h1', { text: title }), x.direction ? el('p', { class: 'bp-muted', text: t('radio_direction_' + x.direction) }) : null]),
					body,
					result,
					el('div', { class: 'bp-actions' }, [doneBtn, x.previous ? link(slug + '/funk/text/' + x.previous + '/', t('prev'), 'bp-button') : null, x.next ? link(slug + '/funk/text/' + x.next + '/', t('next'), 'bp-button') : null])
				], title + ' – ' + c.name);
			});
		});
	}

	function viewRadioPractice(slug, key) {
		return withCourse(slug, function (c) {
			return api('GET', 'courses/' + encodeURIComponent(slug) + '/radio/practice/' + encodeURIComponent(key)).then(function (x) {
				var title = t('practice_task', { catalog: x.catalog, number: x.number });
				var english = false;
				var situation = multiline('p', x.situation_de, 'bp-question-text');
				var langToggle = null;
				if (x.situation_en) {
					langToggle = el('button', { type: 'button', class: 'bp-chip', text: t('practice_english') });
					langToggle.addEventListener('click', function () {
						english = !english;
						var next = multiline('p', english ? x.situation_en : x.situation_de, 'bp-question-text');
						next.setAttribute('lang', english ? 'en' : 'de');
						situation.replaceWith(next);
						situation = next;
						langToggle.textContent = english ? t('practice_german') : t('practice_english');
					});
				}
				var form = el('form', { class: 'bp-card bp-practice-form' });
				var inputs = {};
				x.fields.forEach(function (f) {
					var id = 'bp-field-' + f.name;
					var input = f.multiline ? el('textarea', { id: id, rows: '6', class: 'bp-notes', placeholder: f.placeholder }) : el('input', { id: id, type: 'text', class: 'bp-notes', placeholder: f.placeholder });
					input.value = (x.answers && x.answers[f.name]) || '';
					inputs[f.name] = input;
					var row = el('div', { class: 'bp-field' }, [el('label', { for: id, class: 'bp-label', text: f.label }), input]);
					if (f.multiline && (window.SpeechRecognition || window.webkitSpeechRecognition)) {
						var mic = el('button', { type: 'button', class: 'bp-button', text: t('practice_dictate') });
						mic.addEventListener('click', function () {
							var Rec = window.SpeechRecognition || window.webkitSpeechRecognition;
							var rec = new Rec();
							rec.lang = x.catalog === 'UBI' ? 'de-DE' : 'en-GB';
							rec.interimResults = false;
							mic.disabled = true;
							mic.textContent = t('practice_listening');
							rec.onresult = function (e) { input.value = (input.value ? input.value + ' ' : '') + e.results[0][0].transcript; };
							rec.onend = function () { mic.disabled = false; mic.textContent = t('practice_dictate'); };
							rec.start();
						});
						row.appendChild(el('div', { class: 'bp-actions' }, [mic, el('span', { class: 'bp-muted', text: t('practice_dictate_note') })]));
					}
					form.appendChild(row);
				});
				var result = el('div', { class: 'bp-practice-result', 'aria-live': 'polite' });
				var submit = el('button', { type: 'submit', class: 'bp-button bp-button-primary', text: t('radio_evaluate') });
				form.appendChild(el('div', { class: 'bp-actions' }, [submit]));
				form.addEventListener('submit', function (e) {
					e.preventDefault();
					var answers = {};
					Object.keys(inputs).forEach(function (k) { answers[k] = inputs[k].value; });
					submit.disabled = true;
					api('POST', 'courses/' + encodeURIComponent(slug) + '/radio/practice/' + x.id + '/evaluate', { answers: answers }).then(function (r) {
						result.textContent = '';
						result.appendChild(el('p', { class: 'bp-feedback-title', text: t('practice_score', { passed: r.passed, total: r.total }) }));
						r.fields.forEach(function (f) {
							result.appendChild(el('div', { class: 'bp-card' }, [
								el('h3', { text: f.label }),
								el('ul', { class: 'bp-checklist' }, f.checks.map(function (ch) { return el('li', { class: ch.ok ? 'is-ok' : 'is-missing', text: (ch.ok ? '✓ ' : '✗ ') + ch.label }); })),
								el('div', { class: 'bp-model' }, [el('p', { class: 'bp-model-title', text: t('radio_solution') }), multiline('p', f.solution)])
							]));
						});
						result.scrollIntoView({ behavior: 'smooth', block: 'start' });
					}).catch(function (err) { alertMessage(result, err.message); }).finally(function () { submit.disabled = false; });
				});
				var doneBtn = el('button', { type: 'button', class: 'bp-button', 'aria-pressed': x.done ? 'true' : 'false', text: x.done ? t('radio_done') : t('radio_mark_done') });
				doneBtn.addEventListener('click', function () {
					api('PUT', 'courses/' + encodeURIComponent(slug) + '/radio/practice/' + x.id + '/done', { done: !x.done }).then(function (r) {
						x.done = r.done;
						doneBtn.textContent = x.done ? t('radio_done') : t('radio_mark_done');
						doneBtn.setAttribute('aria-pressed', x.done ? 'true' : 'false');
					});
				});
				mount([
					crumbs(slug, c.name, [[t('trainer_radio'), slug + '/funk/'], [title]]),
					el('header', { class: 'bp-header bp-header-compact' }, [el('p', { class: 'bp-eyebrow' }, [badge(t('kind_' + x.kind))]), el('h1', { text: title })]),
					el('div', { class: 'bp-card' }, [el('h2', { text: t('practice_situation') }), situation, langToggle ? el('p', {}, [langToggle]) : null]),
					form,
					result,
					el('div', { class: 'bp-actions' }, [doneBtn, x.previous ? link(slug + '/funk/praxis/' + x.previous + '/', t('prev'), 'bp-button') : null, x.next ? link(slug + '/funk/praxis/' + x.next + '/', t('next'), 'bp-button') : null])
				], title + ' – ' + c.name);
			});
		});
	}

	function viewDeviceSim(slug) {
		return withCourse(slug, function (c) {
			return api('GET', 'courses/' + encodeURIComponent(slug) + '/device-sim').then(function (config) {
				var host = el('div', { id: 'bp-device-sim', 'data-geraetesimulation': '1' });
				var free = config.tier === 'free';
				var paywall = free ? simPaywall(config) : null;
				mount([
					crumbs(slug, c.name, [[t('trainer_radio'), slug + '/funk/'], [t('device_title')]]),
					el('header', { class: 'bp-header bp-header-compact' }, [el('h1', { text: t('device_title') }), el('p', { class: 'bp-muted', text: t('device_intro') })]),
					free ? simBanner(paywall) : null,
					host,
					paywall ? paywall.node : null
				], t('device_title') + ' – ' + c.name);
				if (free) {
					simGuard(host, paywall);
				}
				var freeEvents = free ? import(cfg.deviceSim + 'funkgeraet/free-practice.js?ver=' + encodeURIComponent(cfg.version)).then(function (m) { return m.FREE_EVENTS || []; }).catch(function () { return []; }) : null;
				if (!document.getElementById('bp-device-sim-css')) {
					document.head.appendChild(el('link', { id: 'bp-device-sim-css', rel: 'stylesheet', href: cfg.deviceSim + 'device-sim.css?ver=' + encodeURIComponent(cfg.version) }));
				}
				return import(cfg.deviceSim + 'funkgeraet/app.js?ver=' + encodeURIComponent(cfg.version)).then(function (module) {
					simLoaded = true;
					module.mount(host, config);
					if (free) {
						return freeEvents.then(function (events) { simLockedUi(host, config, paywall, events); });
					}
					return null;
				});
			});
		});
	}

	// Testversion: Sperren vor dem unveränderten Geräte-JavaScript (Erfassung vor dem Bedienteil).
	function simGuard(host, paywall) {
		function blockKey(e) {
			var key = e.target.closest && e.target.closest('[data-key="distress"]');
			if (!key) {
				return false;
			}
			if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') {
				return false;
			}
			e.preventDefault();
			e.stopPropagation();
			if (e.type !== 'pointerup' && e.type !== 'mousedown' && e.type !== 'touchstart') {
				paywall.open('distress');
			}
			return true;
		}
		['pointerdown', 'mousedown', 'touchstart', 'pointerup', 'keydown'].forEach(function (type) {
			host.addEventListener(type, blockKey, { capture: true, passive: false });
		});
	}

	var SIM_LEVELS = ['Mit Hilfe', 'Nur Hinweis', 'Allein'];

	// Testversion: gesperrte Lektionen, Prüfungsreihen und Freies Üben ausgegraut in der Seitenleiste des
	// Geräts zeigen. Das Original-JavaScript baut die Leiste bei jeder Aktion neu; ein Beobachter ergänzt sie.
	function simLockedUi(host, config, paywall, freeEvents) {
		var locked = config.locked || {};
		locked.units = locked.units || [];
		locked.series = locked.series || [];
		var view = null; // 'exam' | 'free' | null

		function lockNote(title, text) {
			var b = el('button', { type: 'button', class: 'bp-button bp-button-signal bp-sim-unlock', text: t('sim_unlock') });
			b.addEventListener('click', function (e) { e.stopPropagation(); paywall.open(view === 'exam' ? 'exam' : 'scenario'); });
			return el('div', { class: 'bp-sim-lock-note' }, [el('span', { class: 'bp-sim-lock', 'aria-hidden': 'true' }), el('div', {}, [el('p', { class: 'bp-sim-lock-title', text: title }), el('p', { text: text }), b])]);
		}

		function greyButton(label) {
			return el('button', { type: 'button', class: 'text-[11px] px-2 py-1 rounded border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300', 'data-bp-locked': '1', text: label });
		}

		function lessonRow(title) {
			return el('div', { class: 'py-2 border-b border-slate-100 dark:border-slate-700 bp-sim-locked-row', 'data-bp-locked': '1' }, [
				el('div', { class: 'flex items-center gap-2' }, [el('span', { class: 'bp-sim-lock', 'aria-hidden': 'true' }), el('span', { class: 'flex-1 font-medium text-slate-800 dark:text-slate-100', text: title }), el('span', { class: 'bp-sim-pill', text: t('sim_full_only') })]),
				el('div', { class: 'flex gap-1 mt-1 pl-5' }, SIM_LEVELS.map(greyButton))
			]);
		}

		function lockedUnits() {
			return el('div', { class: 'space-y-3 bp-sim-locked-units' }, [lockNote(t('sim_locked_title'), t('sim_locked_meta', { lessons: locked.lessons, tasks: locked.tasks, series: locked.series.length }))].concat(locked.units.map(function (u) {
				return el('details', { class: 'group bp-sim-locked-unit' }, [
					el('summary', { class: 'cursor-pointer text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-1' }, [u.unit + ' · ', el('span', { class: 'bp-sim-lock bp-sim-lock-inline', 'aria-hidden': 'true' }), ' ' + u.lessons.length]),
					el('div', {}, u.lessons.map(lessonRow))
				]);
			})));
		}

		function examPreview() {
			return el('div', { class: 'space-y-3 bp-sim-preview' }, [
				lockNote(t('sim_paywall_title'), t('sim_exam_preview', { tasks: locked.tasks })),
				el('div', { class: 'bp-sim-grey space-y-3', 'data-bp-locked': '1' }, [el('p', { class: 'text-slate-600 dark:text-slate-300', text: t('sim_exam_intro') })].concat(locked.series.map(function (x) {
					return el('div', { class: 'p-3 rounded-lg border border-slate-200 dark:border-slate-700 space-y-2' }, [
						el('div', { class: 'flex items-center gap-2' }, [el('span', { class: 'font-semibold text-slate-800 dark:text-white flex-1', text: x.title }), el('span', { class: 'bp-sim-lock', 'aria-hidden': 'true' })]),
						el('ol', { class: 'list-decimal pl-5 text-xs text-slate-600 dark:text-slate-300' }, (x.lessons || []).map(function (title) { return el('li', { text: title }); })),
						greyButton(t('sim_series_start', { title: x.title }))
					]);
				})))
			]);
		}

		function freePreview() {
			var cats = config.catalogs || ['SRC'];
			return el('div', { class: 'space-y-3 bp-sim-preview' }, [
				lockNote(t('sim_paywall_title_more'), t('sim_free_preview')),
				el('div', { class: 'bp-sim-grey space-y-3', 'data-bp-locked': '1' }, [el('p', { class: 'text-slate-600 dark:text-slate-300', text: t('sim_free_intro') })].concat((freeEvents || []).filter(function (g) { return cats.indexOf(g.catalog) !== -1; }).map(function (g) {
					return el('div', { class: 'space-y-1' }, [
						el('h3', { class: 'text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400', text: g.title }),
						el('div', { class: 'flex flex-wrap gap-1' }, g.items.map(function (item) { return greyButton(item.label); }))
					]);
				})))
			]);
		}

		function tabs() {
			return Array.prototype.slice.call(host.querySelectorAll('[role="tab"]'));
		}

		function lockedTab(tab) {
			var label = tab.textContent.trim();
			return label === 'Prüfung' ? 'exam' : (label === 'Freies Üben' ? 'free' : null);
		}

		var scheduled = false;
		function decorate() {
			scheduled = false;
			var list = tabs();
			if (!list.length) {
				return;
			}
			var tablist = list[0].parentElement;
			list.forEach(function (tab) {
				var kind = lockedTab(tab);
				if (view) {
					tab.setAttribute('aria-selected', kind === view ? 'true' : 'false');
				}
				tab.classList.toggle('bp-sim-tab-locked', !!kind);
				tab.classList.toggle('bp-sim-tab-on', !!kind && kind === view);
				tab.classList.toggle('bp-sim-tab-off', !kind && !!view);
			});
			var panel = tablist.nextElementSibling;
			while (panel && panel.classList.contains('bp-sim-preview')) {
				panel = panel.nextElementSibling;
			}
			var preview = tablist.parentElement.querySelector(':scope > .bp-sim-preview');
			if (view) {
				if (panel) { panel.hidden = true; }
				if (!preview || preview.getAttribute('data-view') !== view) {
					if (preview) { preview.remove(); }
					preview = view === 'exam' ? examPreview() : freePreview();
					preview.setAttribute('data-view', view);
					tablist.after(preview);
				}
				return;
			}
			if (preview) { preview.remove(); }
			if (panel) {
				panel.hidden = false;
				var counter = Array.prototype.filter.call(panel.querySelectorAll('p'), function (p) { return /selbst geschafft/.test(p.textContent); })[0];
				if (counter && !panel.querySelector('.bp-sim-locked-units')) {
					counter.parentElement.appendChild(lockedUnits());
				}
			}
		}
		function schedule() {
			if (!scheduled) {
				scheduled = true;
				window.requestAnimationFrame(decorate);
			}
		}
		new MutationObserver(function (records) {
			for (var i = 0; i < records.length; i++) {
				if (!(records[i].target instanceof SVGElement)) { schedule(); return; }
			}
		}).observe(host, { childList: true, subtree: true });

		host.addEventListener('click', function (e) {
			var tab = e.target.closest && e.target.closest('[role="tab"]');
			if (tab) {
				var kind = lockedTab(tab);
				if (kind) {
					e.preventDefault();
					e.stopPropagation();
					view = kind;
				} else {
					view = null;
				}
				decorate();
				return;
			}
			if (e.target.closest && e.target.closest('[data-bp-locked]') && !e.target.closest('summary')) {
				e.preventDefault();
				e.stopPropagation();
				paywall.open(view === 'exam' ? 'exam' : 'scenario');
			}
		}, true);
		decorate();
	}

	function simBanner(paywall) {
		var unlock = el('button', { type: 'button', class: 'bp-button bp-button-signal', text: t('sim_unlock') });
		unlock.addEventListener('click', function () { paywall.open('general'); });
		return el('div', { class: 'bp-sim-banner' }, [
			el('span', { class: 'bp-ico bp-ico-spark', 'aria-hidden': 'true' }),
			el('div', { class: 'bp-sim-banner-text' }, [el('p', { class: 'bp-sim-banner-title', text: t('sim_free_title') }), el('p', { text: t('sim_free_text') })]),
			unlock
		]);
	}

	function simPaywall(config) {
		var locked = config.locked || { tasks: 0 };
		var title = el('h2', { id: 'bp-paywall-h' });
		var close = el('button', { type: 'button', class: 'bp-paywall-close', 'aria-label': t('sim_close') }, [el('span', { 'aria-hidden': 'true', text: '×' })]);
		var subs = { 'SIM-PASS-14D': t('sim_offer_14d'), 'SIM-PASS-3M': t('sim_offer_3m'), 'SIM-PASS-12M': t('sim_offer_12m') };
		var offers = config.offers || [];
		var main = offers.filter(function (o) { return o.sku !== 'SIM-PASS-12M'; });
		var extra = offers.filter(function (o) { return o.sku === 'SIM-PASS-12M'; })[0];
		if (!main.length && extra) {
			main = [extra];
			extra = null;
		}
		var list = offers.length ? el('ul', { class: 'bp-paywall-offers' }, main.map(function (o) {
			var rec = o.sku === 'SIM-PASS-3M';
			return el('li', {}, [el('a', { href: o.url, class: 'bp-paywall-offer' + (rec ? ' is-recommended' : ''), rel: 'nofollow' }, [
				rec ? el('span', { class: 'bp-paywall-flag', text: t('sim_recommended') }) : null,
				el('span', { class: 'bp-paywall-offer-head' }, [el('strong', { text: o.name.replace(/\s*\(.*\)$/, '') }), el('span', { class: 'bp-paywall-price', text: t('sim_only', { price: o.price }) })]),
				el('span', { class: 'bp-paywall-offer-sub', text: subs[o.sku] || '' })
			])]);
		})) : el('p', { class: 'bp-note', text: t('sim_no_offers') });
		var dialog = el('dialog', { class: 'bp-paywall', 'aria-labelledby': 'bp-paywall-h' }, [el('div', { class: 'bp-paywall-inner' }, [
			close,
			el('span', { class: 'bp-paywall-icon', 'aria-hidden': 'true' }),
			title,
			el('p', { text: t('sim_paywall_text', { tasks: locked.tasks }) }),
			offers.length ? el('p', { class: 'bp-paywall-choose', text: t('sim_paywall_choose') }) : null,
			list,
			extra ? el('p', { class: 'bp-paywall-extra' }, [el('a', { href: extra.url, rel: 'nofollow', text: t('sim_offer_12m_link', { price: extra.price }) })]) : null,
			el('p', { class: 'bp-paywall-once', text: t('sim_once') }),
			el('div', { class: 'bp-paywall-benefits' }, [el('p', {}, [el('strong', { text: t('sim_benefits_title') })]), el('ul', {}, String(t('sim_benefits')).split('\n').map(function (x) { return el('li', { text: x }); }))])
		])]);
		close.addEventListener('click', function () { dialog.close(); });
		dialog.addEventListener('click', function (e) { if (e.target === dialog) { dialog.close(); } });
		return {
			node: dialog,
			open: function (reason) {
				title.textContent = reason === 'scenario' || reason === 'general' ? t('sim_paywall_title_more') : t('sim_paywall_title');
				if (!dialog.open) {
					if (dialog.showModal) { dialog.showModal(); } else { dialog.setAttribute('open', ''); }
				}
			}
		};
	}

	function viewVocabulary(slug, catalog, category) {
		return withCourse(slug, function (c) {
			var params = new URLSearchParams({ catalog: catalog });
			if (category) {
				params.set('category', category);
			}
			return api('GET', 'courses/' + encodeURIComponent(slug) + '/radio/vocabulary?' + params.toString()).then(function (v) {
				var cards = v.cards.slice().sort(function (a, b) { return (a.learned - b.learned) || (a.streak - b.streak); });
				var index = 0;
				var learned = v.learned;
				var stage = el('div', { class: 'bp-vocab-stage' });
				var counter = el('p', { class: 'bp-muted', 'aria-live': 'polite' });
				function updateCounter() {
					counter.textContent = t('radio_learned', { learned: learned, total: cards.length });
				}
				function show() {
					stage.textContent = '';
					if (!cards.length) {
						return;
					}
					var card = cards[index % cards.length];
					var box = el('article', { class: 'bp-card bp-vocab' }, [
						el('p', { class: 'bp-question-meta' }, [el('span', { text: card.category }), card.learned ? badge(t('radio_learned_badge'), 'ok') : el('span', { text: t('streak', { n: card.streak }) })]),
						el('p', { class: 'bp-vocab-term', lang: 'de', text: card.de })
					]);
					var actions = el('div', { class: 'bp-actions' });
					var reveal = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: t('radio_show_translation') });
					reveal.addEventListener('click', function () {
						reveal.remove();
						box.insertBefore(el('p', { class: 'bp-vocab-solution', lang: 'en', text: card.en + (card.hint ? ' (' + card.hint + ')' : '') }), actions);
						var sp = speakButton(card.en, 'en-GB');
						[[true, t('known_yes')], [false, t('known_no')]].forEach(function (opt) {
							var b = el('button', { type: 'button', class: 'bp-button' + (opt[0] ? ' bp-button-primary' : ''), text: opt[1] });
							b.addEventListener('click', function () {
								api('POST', 'courses/' + encodeURIComponent(slug) + '/radio/vocabulary/' + card.id, { known: opt[0] }).then(function (r) {
									if (r.learned && !card.learned) {
										learned++;
									}
									card.streak = r.streak;
									card.learned = r.learned;
									updateCounter();
									index++;
									show();
								});
							});
							actions.appendChild(b);
						});
						if (sp) {
							actions.appendChild(sp);
						}
					});
					actions.appendChild(reveal);
					box.appendChild(actions);
					stage.appendChild(box);
				}
				mount([
					crumbs(slug, c.name, [[t('trainer_radio'), slug + '/funk/'], [category || t('radio_vocabulary')]]),
					el('header', { class: 'bp-header bp-header-compact' }, [el('h1', { text: category || t('radio_vocabulary') }), el('p', { class: 'bp-muted', text: t('radio_vocab_explain', { n: v.streak_to_learn }) }), counter]),
					stage
				], (category || t('radio_vocabulary')) + ' – ' + c.name);
				updateCounter();
				show();
			});
		});
	}

	// ------------------------------------------------------------------ Prüfung

	function viewExam(slug, sessionId) {
		return api('GET', 'exam-sessions/' + sessionId).then(function (s) {
			if (s.status === 'running') {
				examRun(slug, s);
			} else {
				examResult(slug, s);
			}
		});
	}

	function examTitle(s) {
		return s.paper_number ? t('paper_title', { number: s.paper_number }) : t('simulation_title');
	}

	function examRun(slug, s) {
		var current = 0;
		var questions = s.questions;
		var deadline = s.remaining_seconds !== null ? Date.now() + s.remaining_seconds * 1000 : null;
		var timeText = el('strong', { class: 'bp-timer-time', text: '–:––' });
		var timer = el('p', { class: 'bp-timer', role: 'timer', 'aria-live': 'off' }, [el('span', { class: 'bp-timer-label', text: t('time_left_label') }), timeText]);
		var doneCount = el('span', { class: 'bp-exam-count' });
		var doneFill = el('span', { class: 'bp-exam-bar-fill' });
		var status = el('p', { class: 'bp-save-status', 'aria-live': 'polite' });
		var nav = el('ol', { class: 'bp-exam-nav', 'aria-label': t('exam_nav') });
		var stage = el('div', { class: 'bp-exam-stage' });
		var submitting = false;

		function answered(q) {
			return q.type === 'open_text' ? q.known !== null : q.selected_ids.length > 0;
		}

		function renderNav() {
			var n = questions.filter(answered).length;
			doneCount.textContent = t('exam_progress', { n: n, total: questions.length });
			doneFill.style.width = (n * 100 / Math.max(questions.length, 1)) + '%';
			nav.textContent = '';
			questions.forEach(function (q, i) {
				var b = el('button', { type: 'button', class: 'bp-exam-nav-item' + (answered(q) ? ' is-answered' : '') + (i === current ? ' is-current' : ''), 'aria-current': i === current ? 'step' : null, 'aria-label': t('exam_nav_item', { n: q.position, state: answered(q) ? t('answered') : t('unanswered') }), text: String(q.position) });
				b.addEventListener('click', function () { current = i; renderQuestion(); });
				nav.appendChild(el('li', {}, [b]));
			});
		}

		function save(q, body) {
			status.textContent = t('saving');
			return api('PUT', 'exam-sessions/' + s.id + '/answers/' + q.position, body).then(function () {
				status.textContent = t('saved');
				renderNav();
			}).catch(function (e) {
				status.textContent = e.message;
				if (e.status === 422 && /abgelaufen|abgeschlossen/i.test(e.message)) {
					viewExam(slug, s.id).catch(showError);
				}
			});
		}

		function renderQuestion() {
			var q = questions[current];
			stage.textContent = '';
			var name = 'bp-e-' + q.position;
			var card = el('article', { class: 'bp-card bp-question' }, [
				el('p', { class: 'bp-question-meta' }, [el('span', { class: 'bp-pill bp-pill-strong', text: t('exam_question_of', { n: q.position, total: questions.length }) }), q.official_number ? el('span', { class: 'bp-pill', text: t('question_number', { number: q.official_number }) }) : null]),
				multiline('h2', q.text, 'bp-question-text'),
				questionMedia(q.media)
			]);
			if (q.type === 'open_text') {
				var area = el('div', { class: 'bp-open' });
				if (q.model_answer) {
					area.appendChild(el('div', { class: 'bp-model' }, [el('p', { class: 'bp-model-title', text: t('model_answer') }), multiline('p', q.model_answer)]));
					var group = el('div', { class: 'bp-actions', role: 'group', 'aria-label': t('self_assessment') });
					[[true, t('known_yes')], [false, t('known_no')]].forEach(function (opt) {
						var b = el('button', { type: 'button', class: 'bp-button' + (q.known === opt[0] ? ' bp-button-primary' : ''), 'aria-pressed': q.known === opt[0] ? 'true' : 'false', text: opt[1] });
						b.addEventListener('click', function () {
							q.known = opt[0];
							save(q, { known: opt[0] }).then(renderQuestion);
						});
						group.appendChild(b);
					});
					area.appendChild(group);
				} else {
					area.appendChild(el('p', { class: 'bp-muted', text: t('open_exam_explain') }));
					var reveal = el('button', { type: 'button', class: 'bp-button', text: t('reveal_answer') });
					reveal.addEventListener('click', function () {
						reveal.disabled = true;
						api('POST', 'exam-sessions/' + s.id + '/answers/' + q.position + '/reveal').then(function (r) {
							q.model_answer = r.model_answer;
							renderQuestion();
						}).catch(function (e) { reveal.disabled = false; status.textContent = e.message; });
					});
					area.appendChild(reveal);
				}
				card.appendChild(area);
			} else {
				var fieldset = answerList(q, name, q.selected_ids, false);
				fieldset.addEventListener('change', function () {
					var ids = selectedIds(fieldset);
					q.selected_ids = ids;
					if (ids.length) {
						save(q, { answer_ids: ids });
					}
				});
				card.appendChild(fieldset);
			}
			var prev = el('button', { type: 'button', class: 'bp-button', disabled: current === 0 }, [el('span', { class: 'bp-arrow', 'aria-hidden': 'true', text: '←' }), t('prev')]);
			var nextB = el('button', { type: 'button', class: 'bp-button bp-button-primary', disabled: current === questions.length - 1 }, [t('next'), el('span', { class: 'bp-arrow', 'aria-hidden': 'true', text: '→' })]);
			prev.addEventListener('click', function () { current--; renderQuestion(); });
			nextB.addEventListener('click', function () { current++; renderQuestion(); });
			card.appendChild(el('div', { class: 'bp-actions bp-exam-pager' }, [prev, nextB]));
			stage.appendChild(card);
			renderNav();
		}

		function submit(auto) {
			if (submitting) {
				return;
			}
			var open = questions.filter(function (q) { return !answered(q); }).length;
			if (!auto && !window.confirm(open > 0 ? t('confirm_submit_open', { n: open }) : t('confirm_submit'))) {
				return;
			}
			submitting = true;
			api('POST', 'exam-sessions/' + s.id + '/submit').then(function (r) { examResult(slug, r); }).catch(function (e) {
				submitting = false;
				status.textContent = e.message;
				if (auto) {
					clearTimers(); // nicht jede Sekunde erneut abgeben; Knopf bleibt zum erneuten Versuch
				}
			});
		}

		var submitButton = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: t('submit_exam') });
		submitButton.addEventListener('click', function () { submit(false); });

		mount([
			el('header', { class: 'bp-hero bp-exam-hero' }, [
				el('div', { class: 'bp-hero-text' }, [
					el('nav', { class: 'bp-breadcrumb', 'aria-label': t('breadcrumb') }, [link('', t('overview_title')), ' › ', link(slug + '/', s.course.name), ' › ', el('span', { text: examTitle(s) })]),
					el('h1', { text: examTitle(s) }),
					el('p', { class: 'bp-exam-rule' }, [s.live ? badge(t('exam_live'), 'ok') : badge(t('exam_practice'), 'warn'), el('span', { text: s.rule.passing })])
				]),
				deadline ? timer : null
			]),
			el('div', { class: 'bp-exam-layout' }, [
				stage,
				el('aside', { class: 'bp-exam-side', 'aria-label': t('exam_overview') }, [
					el('div', { class: 'bp-exam-progress' }, [doneCount, el('span', { class: 'bp-exam-bar', 'aria-hidden': 'true' }, [doneFill])]),
					nav,
					el('p', { class: 'bp-exam-legend', 'aria-hidden': 'true' }, [el('span', { class: 'is-answered' }), t('answered'), el('span'), t('unanswered')]),
					el('div', { class: 'bp-exam-footer' }, [submitButton, status])
				])
			])
		], examTitle(s));
		renderQuestion();

		if (deadline) {
			var tick = function () {
				var left = Math.max(0, Math.round((deadline - Date.now()) / 1000));
				var m = Math.floor(left / 60);
				var sec = left % 60;
				timeText.textContent = m + ':' + (sec < 10 ? '0' : '') + sec;
				timer.setAttribute('aria-label', t('time_left', { time: timeText.textContent }));
				timer.classList.toggle('is-low', left <= 300);
				if (left === 300) {
					status.textContent = t('five_minutes');
				}
				if (left <= 0) {
					clearTimers();
					submit(true);
				}
			};
			tick();
			timers.push(setInterval(tick, 1000));
		}
	}

	function scoreRing(ratio, passed) {
		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS(ns, 'svg');
		svg.setAttribute('viewBox', '0 0 120 120');
		svg.setAttribute('class', 'bp-ring' + (passed ? ' is-passed' : ' is-failed'));
		svg.setAttribute('aria-hidden', 'true');
		[['bp-ring-track', 1], ['bp-ring-value', ratio]].forEach(function (c) {
			var circle = document.createElementNS(ns, 'circle');
			circle.setAttribute('cx', '60');
			circle.setAttribute('cy', '60');
			circle.setAttribute('r', '52');
			circle.setAttribute('class', c[0]);
			circle.setAttribute('pathLength', '100');
			circle.setAttribute('stroke-dasharray', (Math.max(0, Math.min(1, c[1])) * 100) + ' 100');
			svg.appendChild(circle);
		});
		return svg;
	}

	function meter(value, total, need, ok) {
		var bar = el('span', { class: 'bp-meter' + (ok ? ' is-ok' : ' is-bad'), 'aria-hidden': 'true' }, [el('span', { class: 'bp-meter-fill' })]);
		bar.firstChild.style.width = (value * 100 / Math.max(total, 1)) + '%';
		if (need !== null) {
			var mark = el('span', { class: 'bp-meter-need' });
			mark.style.left = (need * 100) + '%';
			bar.appendChild(mark);
		}
		return bar;
	}

	function examResult(slug, s) {
		var r = s.result;
		var list = el('ol', { class: 'bp-review' });
		var onlyWrong = el('input', { type: 'checkbox', id: 'bp-only-wrong' });
		var wrong = 0;
		r.questions.forEach(function (q) {
			if (!q.correct) { wrong++; }
			var item = el('li', { class: 'bp-card bp-review-item ' + (q.correct ? 'is-correct' : 'is-wrong') }, [
				el('p', { class: 'bp-question-meta' }, [el('span', { class: 'bp-pill bp-pill-strong', text: t('exam_question_of', { n: q.position, total: r.questions.length }) }), badge(q.correct ? t('mark_correct') : (q.answered ? t('mark_wrong') : t('unanswered')), q.correct ? 'ok' : 'bad')]),
				multiline('h3', q.text, 'bp-question-text'),
				questionMedia(q.media)
			]);
			if (q.type === 'open_text') {
				item.appendChild(el('div', { class: 'bp-model' }, [el('p', { class: 'bp-model-title', text: t('model_answer') }), multiline('p', q.model_answer)]));
				item.appendChild(el('p', { class: 'bp-muted', text: q.known === null ? t('unanswered') : (q.known ? t('self_known') : t('self_unknown')) }));
			} else {
				var fieldset = answerList(q, 'bp-r-' + q.position, q.selected_ids, true);
				markAnswers(fieldset, q.correct_ids, q.selected_ids);
				item.appendChild(fieldset);
			}
			if (q.explanation) {
				item.appendChild(el('div', { class: 'bp-review-explain' }, [multiline('p', q.explanation, 'bp-explanation')]));
			}
			list.appendChild(item);
		});
		onlyWrong.addEventListener('change', function () { list.classList.toggle('bp-only-wrong', onlyWrong.checked); });

		var parts = null;
		if (r.parts.length) {
			parts = el('section', { class: 'bp-section', 'aria-labelledby': 'bp-parts-h' }, [
				el('h2', { id: 'bp-parts-h', text: t('exam_parts_title') }),
				el('ul', { class: 'bp-part-cards' }, r.parts.map(function (p) {
					return el('li', { class: 'bp-card bp-part' + (p.passed ? ' is-passed' : ' is-failed') }, [
						el('p', { class: 'bp-part-head' }, [el('strong', { text: p.label }), badge(p.passed ? t('passed') : t('failed'), p.passed ? 'ok' : 'bad')]),
						el('p', { class: 'bp-part-score' }, [el('strong', { text: p.correct + '/' + p.total }), ' ' + t('correct')]),
						meter(p.correct, p.total, p.total ? p.min_correct / p.of : null, p.passed),
						el('p', { class: 'bp-muted bp-part-need', text: t('part_required', { n: p.min_correct, of: p.of }) })
					]);
				}))
			]);
		}
		var topics = el('ul', { class: 'bp-topic-bars' }, Object.keys(r.topics).map(function (k) {
			var tp = r.topics[k];
			return el('li', {}, [
				el('span', { class: 'bp-topic-name', text: k }),
				meter(tp.correct, tp.total, null, tp.correct === tp.total),
				el('span', { class: 'bp-topic-score', text: tp.correct + '/' + tp.total })
			]);
		}));

		var percent = Math.round((r.ratio || 0) * 100);
		mount([
			el('header', { class: 'bp-hero bp-result-hero' + (r.passed ? ' is-passed' : ' is-failed') }, [
				el('div', { class: 'bp-hero-text' }, [
					el('nav', { class: 'bp-breadcrumb', 'aria-label': t('breadcrumb') }, [link('', t('overview_title')), ' › ', link(slug + '/', s.course.name), ' › ', el('span', { text: examTitle(s) })]),
					el('p', { class: 'bp-hero-eyebrow', text: t('result_eyebrow') }),
					el('h1', { text: t('result_title', { title: examTitle(s) }) }),
					el('div', { class: 'bp-verdict ' + (r.passed ? 'is-passed' : 'is-failed'), role: 'status' }, [
						el('p', { class: 'bp-verdict-title' }, [el('span', { class: 'bp-verdict-icon', 'aria-hidden': 'true' }), r.passed ? t('verdict_passed') : t('verdict_failed')]),
						el('p', { class: 'bp-verdict-score', text: t('score_line', { score: r.score, max: r.max, percent: percent }) }),
						r.expired ? el('p', { class: 'bp-verdict-note', text: t('expired_note') }) : null,
						!s.live ? el('p', { class: 'bp-verdict-note', text: t('practice_result_note') }) : null,
						r.open_text.total > 0 ? el('p', { class: 'bp-verdict-note', text: t('open_text_note', { n: r.open_text.correct, total: r.open_text.total }) }) : null
					]),
					el('div', { class: 'bp-hero-actions' }, [
						wrong ? link(slug + '/lernen/?modus=wrong', t('learn_wrong'), 'bp-button bp-button-signal') : null,
						link(slug + '/', t('back_course'), 'bp-button bp-button-ghost')
					])
				]),
				el('div', { class: 'bp-result-ring' }, [scoreRing(r.ratio || 0, r.passed), el('p', {}, [el('strong', { text: percent + ' %' }), el('span', { text: r.score + ' / ' + r.max })])])
			]),
			s.rule.note ? el('p', { class: 'bp-note', text: s.rule.note }) : null,
			parts,
			el('section', { class: 'bp-section bp-panel', 'aria-labelledby': 'bp-topics-h' }, [el('h2', { id: 'bp-topics-h', text: t('topics_title') }), topics]),
			el('section', { class: 'bp-section' }, [
				el('div', { class: 'bp-section-head' }, [
					el('h2', { text: t('review_title') }),
					el('p', { class: 'bp-switch' }, [onlyWrong, el('label', { for: 'bp-only-wrong', text: t('only_wrong') })])
				]),
				list
			])
		], t('result_title', { title: examTitle(s) }));
	}

	route();
}());
