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

	function route() {
		var path = window.location.pathname;
		var rel = path.indexOf(base) === 0 ? path.slice(base.length) : '';
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

	function viewOverview() {
		return api('GET', 'me/courses').then(function (courses) {
			var grid = el('ul', { class: 'bp-grid' });
			courses.forEach(function (c) {
				grid.appendChild(el('li', { class: 'bp-card bp-course-card' }, [
					el('p', { class: 'bp-eyebrow' }, [badge(typeLabel(c.type))]),
					el('h2', {}, [link(c.slug + '/', c.name)]),
					c.progress ? progressBar(c.progress) : el('div', {}, [el('p', { class: 'bp-muted', text: t('locked_short') })].concat(c.shop && c.shop.question_bank ? offerNodes(c.shop.question_bank) : [])),
					el('p', {}, [link(c.slug + '/', t('open_course'), 'bp-button')])
				]));
			});
			mount([
				el('header', { class: 'bp-header' }, [
					el('h1', { text: t('overview_title') }),
					el('p', { class: 'bp-muted', text: t('overview_intro', { name: cfg.user }) })
				]),
				courses.length ? grid : el('p', { text: t('no_courses') }),
				el('p', {}, [link('tipps/', t('tips_title'), 'bp-button'), cfg.groups ? ' ' : null, cfg.groups ? link('gruppen/', t('groups_title'), 'bp-button') : null])
			], t('overview_title'));
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

	function viewCourse(slug) {
		return api('GET', 'courses/' + encodeURIComponent(slug)).then(function (c) {
			var nodes = [
				el('nav', { class: 'bp-breadcrumb', 'aria-label': t('breadcrumb') }, [link('', t('overview_title')), ' › ', el('span', { text: c.name })]),
				el('header', { class: 'bp-header' }, [
					el('p', { class: 'bp-eyebrow' }, [badge(typeLabel(c.type))]),
					el('h1', { text: c.name }),
					c.progress ? progressBar(c.progress.total) : null
				])
			];

			if (c.features.question_bank) {
				var modes = el('div', { class: 'bp-actions' }, [
					link(slug + '/lernen/', t('mode_smart'), 'bp-button bp-button-primary'),
					link(slug + '/lernen/?modus=new', t('mode_new') + ' (' + c.progress.total.new + ')', 'bp-button'),
					link(slug + '/lernen/?modus=wrong', t('mode_wrong') + ' (' + c.progress.total.unsure + ')', 'bp-button'),
					link(slug + '/lernen/?modus=favorites', t('mode_favorites') + ' (' + c.favorites + ')', 'bp-button')
				]);
				var list = el('ul', { class: 'bp-module-list' });
				c.progress.modules.forEach(function (m) {
					list.appendChild(el('li', { class: 'bp-card' + (m.locked ? ' bp-locked' : '') }, [
						el('h3', { text: m.name }),
						m.locked ? el('p', { class: 'bp-muted', text: t('unlocks_on', { date: fmtDate(m.unlock_at) }) }) : progressBar(m),
						m.locked ? null : el('p', {}, [link(slug + '/lernen/?modul=' + m.id, t('learn_module'), 'bp-button')])
					]));
				});
				var limitNote = c.progress.limit ? el('div', { class: 'bp-card bp-locked' }, [el('p', { text: t('free_limit_questions', { n: c.progress.limit }) })].concat(offerNodes((c.shop || {}).question_bank))) : null;
				nodes.push(el('section', { class: 'bp-section', 'aria-labelledby': 'bp-learn-h' }, [
					el('h2', { id: 'bp-learn-h', text: t('section_learn') }),
					el('p', { class: 'bp-muted', text: t('smart_explain') }),
					modes,
					limitNote,
					list
				]));
			} else {
				nodes.push(lockedBox(t('section_learn'), (c.shop || {}).question_bank));
			}

			nodes.push(c.exam ? examSection(slug, c.exam, c.shop || {}) : lockedBox(t('section_exam'), (c.shop || {}).exam_simulation));
			nodes.push(trainerSection(slug, c.trainers, c.features, c.shop || {}));
			nodes.push(downloadSection(c.downloads || [], (c.shop || {}).downloads));
			if (c.exam_guide) {
				nodes.push(el('section', { class: 'bp-section', 'aria-labelledby': 'bp-guide-h' }, [
					el('h2', { id: 'bp-guide-h', text: t('guide_title') }),
					el('p', { class: 'bp-muted', text: t('guide_teaser') }),
					el('p', {}, [link(slug + '/pruefung-anmelden/', t('guide_open'), 'bp-button')])
				]));
			}
			mount(nodes, c.name);
		});
	}

	function examSection(slug, exam, shop) {
		shop = shop || {};
		var section = el('section', { class: 'bp-section', 'aria-labelledby': 'bp-exam-h' }, [el('h2', { id: 'bp-exam-h', text: t('section_exam') })]);
		if (!exam.available) {
			section.appendChild(el('p', { text: t('exam_unavailable') }));
			return section;
		}
		section.appendChild(el('p', {}, [
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
				el('header', { class: 'bp-header bp-header-compact' }, [el('h1', { text: heading }), el('p', { class: 'bp-muted', text: course.name + (moduleName ? ' · ' + t('mode_' + mode) : '') })]),
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
				el('label', { for: name + '-' + a.id }, [el('span', { class: 'bp-answer-key', text: a.label }), el('span', { class: 'bp-answer-text', text: a.text })])
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
		var btn = el('button', { type: 'button', class: 'bp-fav', 'aria-pressed': on ? 'true' : 'false', text: on ? t('fav_remove') : t('fav_add') });
		btn.addEventListener('click', function () {
			btn.disabled = true;
			api(on ? 'DELETE' : 'PUT', 'favorites/' + q.question_id).then(function () {
				on = !on;
				btn.setAttribute('aria-pressed', on ? 'true' : 'false');
				btn.textContent = on ? t('fav_remove') : t('fav_add');
			}).finally(function () { btn.disabled = false; });
		});
		return btn;
	}

	function questionCard(slug, moduleId, mode, q, container) {
		var started = Date.now();
		var key = uuid();
		var name = 'bp-q-' + q.question_id;
		var meta = el('p', { class: 'bp-question-meta' }, [
			q.official_number ? el('span', { text: t('question_number', { number: q.official_number }) }) : null,
			q.topic ? el('span', { text: q.topic }) : null,
			el('span', { class: 'bp-reason', text: q.reason })
		]);
		var card = el('article', { class: 'bp-card bp-question', 'aria-labelledby': name + '-text' }, [
			meta,
			multiline('h2', q.text, 'bp-question-text'),
			questionMedia(q.media)
		]);
		card.querySelector('h2').id = name + '-text';
		var feedback = el('div', { class: 'bp-feedback', 'aria-live': 'polite' });
		var actions = el('div', { class: 'bp-actions' });
		var next = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: t('next_question') });
		next.addEventListener('click', function () { loadQuestion(slug, moduleId, mode, container); });

		function showResult(r, fieldset) {
			if (fieldset) {
				markAnswers(fieldset, r.correct_ids, r.selected_ids);
			}
			feedback.textContent = '';
			feedback.className = 'bp-feedback ' + (r.correct ? 'bp-feedback-ok' : 'bp-feedback-bad');
			feedback.appendChild(el('p', { class: 'bp-feedback-title', text: r.correct ? t('result_correct') : t('result_wrong') }));
			if (r.explanation) {
				feedback.appendChild(multiline('p', r.explanation, 'bp-explanation'));
			} else if (r.explanation_locked) {
				feedback.appendChild(el('p', { class: 'bp-muted', text: t('explanation_locked') }));
			}
			if (r.progress) {
				feedback.appendChild(el('p', { class: 'bp-muted', text: t('status_' + r.progress.status) + ' · ' + t('streak', { n: r.progress.streak }) }));
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
		card.appendChild(actions);
		card.appendChild(feedback);
		if (q.remaining !== null && q.remaining !== undefined) {
			card.appendChild(el('p', { class: 'bp-muted bp-remaining', text: t('remaining', { n: q.remaining }) }));
		}
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

	function trainerSection(slug, tr, features, shop) {
		if (!tr) {
			return null;
		}
		var tiles = [];
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
		if (!tiles.length) {
			return null;
		}
		return el('section', { class: 'bp-section', 'aria-labelledby': 'bp-trainer-h' }, [
			el('h2', { id: 'bp-trainer-h', text: t('section_trainers') }),
			el('ul', { class: 'bp-module-list' }, tiles.map(function (tile) {
				var feature = TRAINER_FEATURES[tile[0]];
				if (features && feature && features[feature] === false) {
					return el('li', { class: 'bp-card bp-tile bp-locked' }, [el('h3', { text: tile[1] }), el('p', { class: 'bp-muted', text: t('locked_short') })].concat(offerNodes(shop[feature])));
				}
				return el('li', { class: 'bp-card bp-tile' }, [el('h3', {}, [link(slug + '/' + tile[0], tile[1])]), el('p', { class: 'bp-muted', text: tile[2] })]);
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

	function viewVideos(slug) {
		return withCourse(slug, function (c) {
			return api('GET', 'courses/' + encodeURIComponent(slug) + '/videos').then(function (v) {
				var nodes = [crumbs(slug, c.name, [[t('trainer_video')]]), el('header', { class: 'bp-header' }, [
					el('h1', { text: t('trainer_video') }),
					progressBar({ questions: v.lessons, mastered: v.completed, learning: 0, unsure: 0, percent: Math.floor(v.completed * 100 / Math.max(v.lessons, 1)) }, t('trainer_video_meta', { done: v.completed, total: v.lessons })),
					v.next_lesson ? el('p', {}, [link(slug + '/video/' + v.next_lesson + '/', t('video_continue'), 'bp-button bp-button-primary')]) : null
				])];
				var lastGroup = null;
				v.modules.forEach(function (m) {
					if (m.group && m.group !== lastGroup) {
						nodes.push(el('h2', { class: 'bp-group', text: m.group }));
						lastGroup = m.group;
					}
					nodes.push(el('section', { class: 'bp-card' + (m.locked ? ' bp-locked' : '') }, [
						el('h3', { text: m.title }),
						m.locked ? el('p', { class: 'bp-muted', text: t('unlocks_on', { date: fmtDate(m.unlock_at) }) }) : null,
						el('ol', { class: 'bp-lessons' }, m.lessons.map(function (l) {
							if (l.locked) {
								return el('li', { class: 'is-locked' }, [el('span', { text: l.title }), el('span', { class: 'bp-muted', text: ' · ' + t('locked_badge') }), m.locked ? null : el('span', { class: 'bp-muted', text: ' · ' + t('locked_free') })]);
							}
							return el('li', { class: l.completed ? 'is-done' : '' }, [
								link(slug + '/video/' + l.id + '/', l.title),
								el('span', { class: 'bp-muted', text: ' ' + duration(l.duration) }),
								l.completed ? el('span', { class: 'bp-lesson-done', text: ' ✓ ' + t('video_done') }) : null,
								l.quiz ? el('span', { class: 'bp-muted', text: ' · ' + t('video_quiz_count', { n: l.quiz }) }) : null
							]);
						}))
					]));
				});
				mount(nodes, t('trainer_video') + ' – ' + c.name);
			});
		});
	}

	function viewVideoLesson(slug, lessonId) {
		return withCourse(slug, function (c) {
			return api('GET', 'courses/' + encodeURIComponent(slug) + '/videos/' + lessonId).then(function (l) {
				var path = 'courses/' + encodeURIComponent(slug) + '/videos/' + l.id + '/progress';
				var video = l.video_url ? el('video', { class: 'bp-video', controls: true, preload: 'metadata', src: l.video_url }) : null;
				var doneBtn = el('button', { type: 'button', class: 'bp-button' + (l.completed ? '' : ' bp-button-primary'), 'aria-pressed': l.completed ? 'true' : 'false', text: l.completed ? t('video_done') : t('video_mark_done') });
				function saveProgress(completed) {
					return api('PUT', path, { position: video ? Math.floor(video.currentTime || 0) : 0, completed: completed });
				}
				doneBtn.addEventListener('click', function () {
					saveProgress(!l.completed).then(function (r) {
						l.completed = r.completed;
						doneBtn.textContent = l.completed ? t('video_done') : t('video_mark_done');
						doneBtn.setAttribute('aria-pressed', l.completed ? 'true' : 'false');
						doneBtn.classList.toggle('bp-button-primary', !l.completed);
					});
				});
				if (video) {
					video.addEventListener('loadedmetadata', function () {
						if (l.position > 0 && l.position < (video.duration || 0) - 2) {
							video.currentTime = l.position;
						}
					});
					video.addEventListener('pause', function () { saveProgress(null); });
					video.addEventListener('ended', function () {
						saveProgress(true).then(function () {
							l.completed = true;
							doneBtn.textContent = t('video_done');
							doneBtn.classList.remove('bp-button-primary');
						});
					});
					timers.push(setInterval(function () { if (!video.paused) { saveProgress(null); } }, 15000));
				}
				var quiz = null;
				if (l.quiz.length) {
					quiz = el('section', { class: 'bp-section' }, [el('h2', { text: t('video_quiz') })].concat(l.quiz.map(function (q) { return quizQuestion(q); })));
				}
				mount([
					crumbs(slug, c.name, [[t('trainer_video'), slug + '/video/'], [l.title]]),
					el('header', { class: 'bp-header bp-header-compact' }, [el('p', { class: 'bp-eyebrow bp-muted', text: l.module }), el('h1', { text: l.title })]),
					l.placeholder ? el('p', { class: 'bp-note', text: t('video_placeholder') }) : null,
					video ? el('div', { class: 'bp-video-wrap' }, [video]) : el('p', { text: t('video_missing') }),
					el('div', { class: 'bp-actions' }, [
						doneBtn,
						l.previous ? link(slug + '/video/' + l.previous + '/', t('prev'), 'bp-button') : null,
						l.next ? link(slug + '/video/' + l.next + '/', t('next'), 'bp-button') : null
					]),
					l.steps.length ? el('section', { class: 'bp-section' }, [el('h2', { text: t('video_steps') }), el('ol', { class: 'bp-steps' }, l.steps.map(function (st) { return el('li', { text: st.title }); }))]) : null,
					quiz
				], l.title + ' – ' + c.name);
			});
		});
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
						el('h2', {}, [link(slug + '/funk/geraet/', t('device_title'))]),
						el('p', { class: 'bp-muted', text: t('device_meta', { lessons: o.device.lessons, mastered: o.device.mastered }) }),
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
				mount([
					crumbs(slug, c.name, [[t('trainer_radio'), slug + '/funk/'], [t('device_title')]]),
					el('header', { class: 'bp-header bp-header-compact' }, [el('h1', { text: t('device_title') }), el('p', { class: 'bp-muted', text: t('device_intro') })]),
					host
				], t('device_title') + ' – ' + c.name);
				if (!document.getElementById('bp-device-sim-css')) {
					document.head.appendChild(el('link', { id: 'bp-device-sim-css', rel: 'stylesheet', href: cfg.deviceSim + 'device-sim.css?ver=' + encodeURIComponent(cfg.version) }));
				}
				return import(cfg.deviceSim + 'funkgeraet/app.js?ver=' + encodeURIComponent(cfg.version)).then(function (module) {
					module.mount(host, config);
				});
			});
		});
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
		var timer = el('p', { class: 'bp-timer', role: 'timer', 'aria-live': 'off' });
		var status = el('p', { class: 'bp-save-status', 'aria-live': 'polite' });
		var nav = el('ol', { class: 'bp-exam-nav', 'aria-label': t('exam_nav') });
		var stage = el('div', { class: 'bp-exam-stage' });
		var submitting = false;

		function answered(q) {
			return q.type === 'open_text' ? q.known !== null : q.selected_ids.length > 0;
		}

		function renderNav() {
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
				el('p', { class: 'bp-question-meta' }, [el('span', { text: t('exam_question_of', { n: q.position, total: questions.length }) }), q.official_number ? el('span', { text: t('question_number', { number: q.official_number }) }) : null]),
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
			var prev = el('button', { type: 'button', class: 'bp-button', text: t('prev'), disabled: current === 0 });
			var nextB = el('button', { type: 'button', class: 'bp-button', text: t('next'), disabled: current === questions.length - 1 });
			prev.addEventListener('click', function () { current--; renderQuestion(); });
			nextB.addEventListener('click', function () { current++; renderQuestion(); });
			card.appendChild(el('div', { class: 'bp-actions' }, [prev, nextB]));
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
			});
		}

		var submitButton = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: t('submit_exam') });
		submitButton.addEventListener('click', function () { submit(false); });

		mount([
			el('nav', { class: 'bp-breadcrumb', 'aria-label': t('breadcrumb') }, [link('', t('overview_title')), ' › ', link(slug + '/', s.course.name), ' › ', el('span', { text: examTitle(s) })]),
			el('header', { class: 'bp-header bp-exam-header' }, [
				el('div', {}, [
					el('h1', { text: examTitle(s) }),
					el('p', {}, [s.live ? badge(t('exam_live'), 'ok') : badge(t('exam_practice'), 'warn'), ' ', el('span', { class: 'bp-muted', text: s.rule.passing })])
				]),
				timer
			]),
			nav,
			stage,
			el('div', { class: 'bp-actions bp-exam-footer' }, [submitButton, status])
		], examTitle(s));
		renderQuestion();

		if (deadline) {
			var tick = function () {
				var left = Math.max(0, Math.round((deadline - Date.now()) / 1000));
				var m = Math.floor(left / 60);
				var sec = left % 60;
				timer.textContent = t('time_left', { time: m + ':' + (sec < 10 ? '0' : '') + sec });
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

	function examResult(slug, s) {
		var r = s.result;
		var list = el('ol', { class: 'bp-review' });
		var onlyWrong = el('input', { type: 'checkbox', id: 'bp-only-wrong' });
		r.questions.forEach(function (q) {
			var item = el('li', { class: 'bp-card bp-review-item ' + (q.correct ? 'is-correct' : 'is-wrong') }, [
				el('p', { class: 'bp-question-meta' }, [el('span', { text: t('exam_question_of', { n: q.position, total: r.questions.length }) }), badge(q.correct ? t('mark_correct') : (q.answered ? t('mark_wrong') : t('unanswered')), q.correct ? 'ok' : 'bad')]),
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
				item.appendChild(multiline('p', q.explanation, 'bp-explanation'));
			}
			list.appendChild(item);
		});
		onlyWrong.addEventListener('change', function () { list.classList.toggle('bp-only-wrong', onlyWrong.checked); });

		var parts = null;
		if (r.parts.length) {
			parts = el('table', { class: 'bp-table' }, [
				el('thead', {}, [el('tr', {}, [el('th', { text: t('part') }), el('th', { text: t('correct') }), el('th', { text: t('required') }), el('th', { text: t('result') })])]),
				el('tbody', {}, r.parts.map(function (p) {
					return el('tr', {}, [el('td', { text: p.label }), el('td', { text: p.correct + '/' + p.total }), el('td', { text: p.min_correct + '/' + p.of }), el('td', {}, [badge(p.passed ? t('passed') : t('failed'), p.passed ? 'ok' : 'bad')])]);
				}))
			]);
		}
		var topics = el('ul', { class: 'bp-topics' }, Object.keys(r.topics).map(function (k) {
			return el('li', { text: k + ': ' + r.topics[k].correct + '/' + r.topics[k].total });
		}));

		mount([
			el('nav', { class: 'bp-breadcrumb', 'aria-label': t('breadcrumb') }, [link('', t('overview_title')), ' › ', link(slug + '/', s.course.name), ' › ', el('span', { text: examTitle(s) })]),
			el('header', { class: 'bp-header' }, [
				el('h1', { text: t('result_title', { title: examTitle(s) }) }),
				el('div', { class: 'bp-card bp-verdict ' + (r.passed ? 'is-passed' : 'is-failed'), role: 'status' }, [
					el('p', { class: 'bp-verdict-title', text: r.passed ? t('verdict_passed') : t('verdict_failed') }),
					el('p', { text: t('score_line', { score: r.score, max: r.max, percent: Math.round((r.ratio || 0) * 100) }) }),
					r.expired ? el('p', { class: 'bp-muted', text: t('expired_note') }) : null,
					!s.live ? el('p', { class: 'bp-muted', text: t('practice_result_note') }) : null,
					r.open_text.total > 0 ? el('p', { class: 'bp-muted', text: t('open_text_note', { n: r.open_text.correct, total: r.open_text.total }) }) : null,
					s.rule.note ? el('p', { class: 'bp-note', text: s.rule.note }) : null
				])
			]),
			parts,
			el('section', { class: 'bp-section' }, [el('h2', { text: t('topics_title') }), topics]),
			el('div', { class: 'bp-actions' }, [link(slug + '/lernen/?modus=wrong', t('learn_wrong'), 'bp-button bp-button-primary'), link(slug + '/', t('back_course'), 'bp-button')]),
			el('section', { class: 'bp-section' }, [
				el('h2', { text: t('review_title') }),
				el('p', {}, [onlyWrong, ' ', el('label', { for: 'bp-only-wrong', text: t('only_wrong') })]),
				list
			])
		], t('result_title', { title: examTitle(s) }));
	}

	route();
}());
