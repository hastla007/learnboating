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
		} else if (parts.length === 1) {
			view = viewCourse(parts[0]);
		} else if (parts[1] === 'lernen') {
			view = viewLearn(parts[0], query.get('modul'), query.get('modus') || 'smart');
		} else if (parts[1] === 'pruefung' && parts[2]) {
			view = viewExam(parts[0], parseInt(parts[2], 10));
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
					c.progress ? progressBar(c.progress) : el('p', { class: 'bp-muted', text: t('locked_short') }),
					el('p', {}, [link(c.slug + '/', t('open_course'), 'bp-button')])
				]));
			});
			mount([
				el('header', { class: 'bp-header' }, [
					el('h1', { text: t('overview_title') }),
					el('p', { class: 'bp-muted', text: t('overview_intro', { name: cfg.user }) })
				]),
				courses.length ? grid : el('p', { text: t('no_courses') })
			], t('overview_title'));
		});
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
					list.appendChild(el('li', { class: 'bp-card' }, [
						el('h3', { text: m.name }),
						progressBar(m),
						el('p', {}, [link(slug + '/lernen/?modul=' + m.id, t('learn_module'), 'bp-button')])
					]));
				});
				nodes.push(el('section', { class: 'bp-section', 'aria-labelledby': 'bp-learn-h' }, [
					el('h2', { id: 'bp-learn-h', text: t('section_learn') }),
					el('p', { class: 'bp-muted', text: t('smart_explain') }),
					modes,
					list
				]));
			} else {
				nodes.push(el('section', { class: 'bp-card bp-locked' }, [el('h2', { text: t('section_learn') }), el('p', { text: t('locked_feature') })]));
			}

			nodes.push(c.exam ? examSection(slug, c.exam) : el('section', { class: 'bp-card bp-locked' }, [el('h2', { text: t('section_exam') }), el('p', { text: t('locked_feature') })]));
			mount(nodes, c.name);
		});
	}

	function examSection(slug, exam) {
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
		var start = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: exam.running_simulation ? t('exam_resume') : t('exam_start') });
		start.addEventListener('click', function () {
			start.disabled = true;
			startExam(slug, null).catch(function (e) { start.disabled = false; alertMessage(section, e.message); });
		});
		section.appendChild(el('div', { class: 'bp-actions' }, [start]));

		if (exam.papers.length) {
			section.appendChild(el('h3', { text: t('papers_title') }));
			var grid = el('ul', { class: 'bp-paper-grid' });
			exam.papers.forEach(function (p) {
				var state = p.last ? (p.last.status === 'running' ? 'running' : (p.last.passed ? 'passed' : 'failed')) : 'open';
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
