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
	// Die Hülle (templates/app.php) ist eine polite-Live-Region. Dann würde jeder Seitenaufbau komplett und jede
	// Fehlermeldung (role=alert) doppelt angesagt; Seitenwechsel meldet die App über den Fokus auf die Überschrift.
	root.removeAttribute('aria-live');
	var T = cfg.i18n;
	var base = cfg.base.replace(/\/?$/, '/');
	var timers = [];

	// ------------------------------------------------------------------ Helfer

	function t(key, vars) {
		// Einzahl automatisch: bei {n} = 1 die Variante <key>_one, falls vorhanden.
		if (vars && vars.n === 1 && T[key + '_one']) {
			key = key + '_one';
		}
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

	// Navigations-Zähler: Antworten einer verlassenen Ansicht bleiben liegen (kein mount/showError mehr).
	var routeSeq = 0;
	var noop = new Promise(function () { /* bleibt offen */ });
	var nonceRefresh = null;

	// Frischen wp_rest-Nonce holen (admin-ajax mit Cookie-Anmeldung; braucht selbst keinen Nonce).
	function refreshNonce() {
		if (!nonceRefresh) {
			nonceRefresh = fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' }, body: 'action=bp_rest_nonce' })
				.then(function (r) { return r.json().catch(function () { return null; }); })
				.then(function (d) {
					if (d && d.success && d.data && d.data.nonce) {
						cfg.nonce = d.data.nonce;
						return true;
					}
					return false;
				})
				.catch(function () { return 'offline'; })
				.then(function (ok) { nonceRefresh = null; return ok; });
		}
		return nonceRefresh;
	}

	function offlineError(cause) {
		var error = new Error(t('error_offline'));
		error.status = 0;
		error.code = 'offline';
		error.cause = cause;
		return error;
	}

	function sessionError() {
		var error = new Error(t('session_expired'));
		error.status = 401;
		error.code = 'session_expired';
		return error;
	}

	function api(method, path, body, retried) {
		var seq = routeSeq;
		var options = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' } };
		if (body !== undefined) {
			options.headers['Content-Type'] = 'application/json';
			options.body = JSON.stringify(body);
		}
		var request = fetch(cfg.root + path, options).catch(function (e) {
			// Netzwerkfehler (TypeError „Failed to fetch“): verständlich auf Deutsch.
			throw offlineError(e);
		}).then(function (response) {
			return response.json().catch(function () { return {}; }).then(function (data) {
				if (!response.ok) {
					var code = data && data.code ? String(data.code) : null;
					// Abgelaufene Anmeldung/Nonce: Nonce erneuern und die Anfrage einmal wiederholen.
					if (!retried && (response.status === 401 || code === 'rest_cookie_invalid_nonce')) {
						return refreshNonce().then(function (ok) {
							if (ok === 'offline') {
								throw offlineError(null);
							}
							if (!ok) {
								throw sessionError();
							}
							return api(method, path, body, true);
						});
					}
					if (response.status === 401 || code === 'rest_cookie_invalid_nonce') {
						throw sessionError();
					}
					var error = new Error((data && data.message) || t('error_generic'));
					error.status = response.status;
					error.code = code;
					throw error;
				}
				return data;
			});
		});
		if (retried) {
			return request;
		}
		// Nach einem Seitenwechsel nicht mehr auflösen: keine veralteten Ansichten oder Fehlermeldungen.
		return request.then(function (data) {
			return seq === routeSeq ? data : noop;
		}, function (error) {
			if (seq !== routeSeq) {
				return noop;
			}
			throw error;
		});
	}

	var cleanups = [];

	function clearTimers() {
		timers.forEach(function (id) { clearInterval(id); });
		timers = [];
		var run = cleanups;
		cleanups = [];
		run.forEach(function (fn) { try { fn(); } catch (e) { /* weiter */ } });
	}

	function reducedMotion() {
		return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
	}

	function scrollBehavior() {
		return reducedMotion() ? 'auto' : 'smooth';
	}

	// Knopf sperren, ohne den Fokus zu verlieren (statt disabled): aria-disabled + Sperre im Handler.
	function setBusy(btn, busy) {
		if (!btn) {
			return;
		}
		if (busy) {
			btn.setAttribute('aria-disabled', 'true');
		} else {
			btn.removeAttribute('aria-disabled');
		}
	}

	function isBusy(btn) {
		return btn.getAttribute('aria-disabled') === 'true';
	}

	// Element mit Abstand (scroll-margin) einscrollen, falls es nicht gut sichtbar ist.
	function bringIntoView(node) {
		if (!node || !node.getBoundingClientRect) {
			return;
		}
		var r = node.getBoundingClientRect();
		var vh = window.innerHeight || document.documentElement.clientHeight;
		if (r.top < 80 || r.top > vh * 0.6) {
			node.scrollIntoView({ block: 'start', behavior: scrollBehavior() });
		}
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

	function loginUrl() {
		var u = cfg.loginUrl || '/wp-login.php';
		return u + (u.indexOf('?') === -1 ? '?' : '&') + 'redirect_to=' + encodeURIComponent(window.location.href);
	}

	function showError(error) {
		var title = t('error_title');
		var actions = [link('', t('back_overview'), 'bp-button')];
		if (error.code === 'session_expired') {
			title = t('session_title');
			actions = [el('a', { href: loginUrl(), class: 'bp-button bp-button-primary', text: t('session_login') })];
		} else if (error.code === 'bp_forbidden') {
			title = t('locked_title');
		} else if (error.code === 'offline') {
			title = t('offline_title');
			var again = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: t('retry') });
			again.addEventListener('click', function () { route(); });
			actions.unshift(again);
		}
		mount([el('div', { class: 'bp-card bp-error', role: 'alert' }, [
			el('h1', { text: title }),
			el('p', { text: error.message }),
			el('p', { class: 'bp-actions' }, actions)
		])], title);
	}

	function loading() {
		clearTimers();
		root.textContent = '';
		root.appendChild(el('p', { class: 'bp-loading', role: 'status', text: t('loading') }));
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

	// Kinder ersetzen (ohne replaceChildren, für ältere Browser).
	function setChildren(node, children) {
		node.textContent = '';
		(children || []).forEach(function (c) { if (c) { node.appendChild(c); } });
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
	var learnCollection = null; // Sammlung im Lernmodus (?sammlung=)
	var learnExam = null; // Fehler einer Prüfung wiederholen (?pruefung=)

	function route() {
		var path = window.location.pathname;
		var rel = path.indexOf(base) === 0 ? path.slice(base.length) : '';
		if (simLoaded && !/\/funk\/geraet\/?$/.test(rel)) {
			window.location.reload();
			return;
		}
		routeSeq++;
		var seq = routeSeq;
		clearTimers(); // u. a. Prüfungs-Timer: nach dem Verlassen keine automatische Abgabe
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
			learnCollection = query.get('sammlung');
			learnExam = /^\d+$/.test(query.get('pruefung') || '') ? query.get('pruefung') : null;
			view = viewLearn(parts[0], query.get('modul'), query.get('modus') || 'smart', query.get('thema'));
		} else if (parts[1] === 'pruefung' && parts[2]) {
			view = viewExam(parts[0], parseInt(parts[2], 10));
		} else if (parts[1] === 'pruefung') {
			view = viewExamHub(parts[0]);
		} else if (parts[1] === 'knoten') {
			view = viewKnots(parts[0]);
		} else if (parts[1] === 'merkliste') {
			view = viewSaved(parts[0], query.get('sammlung'), query.get('ansicht'));
		} else if (parts[1] === 'smarttrainer' || parts[1] === 'themen') {
			if (parts[1] === 'themen') {
				try { history.replaceState(null, '', base + parts[0] + '/smarttrainer/'); } catch (e) { /* weiter */ }
			}
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
		} else if (parts[1] === 'funk' && parts[2] === 'buchstabiertafel') {
			view = viewRadioSpelling(parts[0]);
		} else if (parts[1] === 'funk' && parts[2] === 'muster') {
			view = viewRadioSamples(parts[0]);
		} else if (parts[1] === 'funk' && parts[2] === 'vokabeln') {
			view = viewVocabulary(parts[0], query.get('katalog') || 'SRC', query.get('kategorie'));
		} else if (parts[1] === 'funk') {
			view = viewRadio(parts[0]);
		} else {
			view = Promise.reject(Object.assign(new Error(t('not_found')), { status: 404 }));
		}
		view.catch(function (error) {
			if (seq === routeSeq) {
				showError(error);
			}
		});
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
			el('span', { class: 'bp-cc-type bp-cc-type-' + c.type, text: typeLabel(c.type) }),
			c.trial && !accessState(c) ? el('span', { class: 'bp-cc-trial', text: t('course_trial') }) : null
		]);
		var body = [el('h3', { class: 'bp-cc-title' }, [link(c.slug + '/', c.name)])];
		// Empfehlung: passt zu einem besessenen Kurs (Server: CourseRelations).
		var rec = c.recommended_for && c.recommended_for.length ? c.recommended_for : null;
		if (rec) {
			body.unshift(el('p', { class: 'bp-cc-rec' }, [badge(t('course_recommended'), 'ok')]));
			body.push(el('p', { class: 'bp-cc-rec-note', text: t('course_recommended_for', { name: rec.join(', ') }) }));
		}
		if (c.progress) {
			var started = c.progress.mastered + c.progress.learning + c.progress.unsure > 0;
			if (c.trial && !accessState(c)) { body.push(el('p', { class: 'bp-cc-trial-note', text: t('course_trial_note') })); }
			body = body.concat(accessCardNodes(c));
			body.push(el('p', { class: 'bp-cc-pct' }, [el('strong', { text: c.progress.percent + ' %' }), ' ' + t('course_hero_mastered')]));
			body.push(progressBar(c.progress, t('progress_short', { mastered: c.progress.mastered, total: c.progress.questions })));
			body.push(el('p', { class: 'bp-cc-actions' }, [link(c.slug + '/', c.trial ? t('course_trial_cta') : (started ? t('dash_continue') : t('course_cta_learn')), 'bp-button bp-button-primary bp-cc-go')]));
			var offer = c.trial && !accessState(c) && c.shop && c.shop.question_bank ? c.shop.question_bank[0] : null;
			if (offer) { body.push(el('p', { class: 'bp-cc-unlock' }, [el('a', { href: offer.url, text: t('course_trial_unlock', { price: offer.price }) })])); }
		} else {
			body.push(el('p', { class: 'bp-muted bp-cc-locked', text: t('locked_short') }));
			body = accessState(c) ? body.concat(accessCardNodes(c)) : body.concat(c.shop && c.shop.question_bank ? offerNodes(c.shop.question_bank) : []);
		}
		return el('li', { class: 'bp-card bp-course-card' + (c.progress ? '' : ' is-locked') + (rec ? ' is-recommended' : '') }, [media, el('div', { class: 'bp-cc-body' }, body)]);
	}

	// Hinweis je Kurs, dessen Zugang bald endet (ohne Verlängerung).
	function expiryNotice(c) {
		return el('div', { class: 'bp-infobox bp-expiry' }, [
			el('span', { class: 'bp-ico bp-ico-timer', 'aria-hidden': 'true' }),
			el('p', {}, [
				t('expiry_notice', { name: c.name, date: fmtDate(c.expires_at) }) + ' ',
				c.renew_url ? el('a', { href: c.renew_url, text: t('expiry_renew') }) : null
			])
		]);
	}

	// ------------------------------------------------------------------ Zugangsstatus: Zahlung offen, abgelaufen, gesperrt
	// Server: access.status (AccessStatus); der kostenlose Teilumfang bleibt nutzbar. Texte wie App-Screens 88 und 25.

	var ACCESS_NOTICES = ['pending', 'expired', 'suspended'];

	function accessState(c) {
		var s = c && c.access ? c.access.status : null;
		return ACCESS_NOTICES.indexOf(s) !== -1 ? s : null;
	}

	function accessNoticeParts(c) {
		var a = c.access;
		var s = accessState(c);
		if (s === 'pending') {
			var text = a.order ? t('access_pending_text', { number: a.order.number, name: c.name }) : t('access_pending_text_plain', { name: c.name });
			if (a.duration) { text += ' ' + t('access_pending_term', { term: a.duration }); }
			if (c.trial || (c.progress && c.progress.limit)) { text += ' ' + t('access_pending_free'); }
			var url = a.order && a.order.url ? a.order.url : cfg.accountUrl;
			return { status: s, title: t('access_pending_title'), text: text, icon: 'timer', url: url, link: t('access_pending_order') };
		}
		if (s === 'expired') {
			return { status: s, title: t('access_expired_title'), text: t('access_expired_text', { name: c.name, date: fmtDate(a.expired_at) }), icon: 'timer', url: a.renew_url, link: t('access_renew') };
		}
		if (s === 'suspended') {
			return { status: s, title: t('access_suspended_title'), text: t('access_suspended_text', { name: c.name }), icon: 'lock', url: a.contact_url, link: t('access_contact') };
		}
		return null;
	}

	// Übersicht: Hinweis wie „Zugang endet bald“ (je Kauf einmal, mitgekaufte Teilkurse nicht einzeln).
	function accessNotice(c) {
		var n = accessNoticeParts(c);
		return n ? el('div', { class: 'bp-infobox bp-expiry bp-access-notice is-' + n.status }, [
			el('span', { class: 'bp-ico bp-ico-' + n.icon, 'aria-hidden': 'true' }),
			el('p', {}, [el('strong', { text: n.title + ': ' }), n.text + ' ', n.url ? el('a', { href: n.url, text: n.link }) : null])
		]) : null;
	}

	// Kurs-Hub: Box an der Stelle von „Kurs freischalten“.
	function accessHubBox(c) {
		var n = accessNoticeParts(c);
		return n ? el('section', { class: 'bp-hub-unlock bp-locked bp-access-notice is-' + n.status }, [
			el('span', { class: 'bp-ico bp-ico-' + n.icon, 'aria-hidden': 'true' }),
			el('div', {}, [
				el('h2', { text: n.title }),
				el('p', { class: 'bp-muted', text: n.text }),
				n.url ? el('p', {}, [el('a', { href: n.url, class: 'bp-button' + (n.status === 'expired' ? ' bp-button-primary' : ''), text: n.link })]) : null
			])
		]) : null;
	}

	// Kurskachel: kurze Statuszeile statt „Vollzugang freischalten“ bzw. der Kaufangebote.
	function accessCardNodes(c) {
		var n = accessNoticeParts(c);
		if (!n) {
			return [];
		}
		var short = n.status === 'pending' ? t('access_pending_short') : (n.status === 'expired' ? t('access_expired_short', { date: fmtDate(c.access.expired_at) }) : t('access_suspended_short'));
		return [
			el('p', { class: 'bp-cc-trial-note bp-cc-access is-' + n.status }, [badge(short, n.status === 'pending' ? 'neutral' : 'warn')]),
			n.url ? el('p', { class: 'bp-cc-unlock' }, [el('a', { href: n.url, text: n.link })]) : null
		];
	}

	function viewOverview() {
		return api('GET', 'me/courses').then(function (courses) {
			// Ausgeblendet = nicht freigeschaltet und durch einen eigenen Kurs schon abgedeckt (bleibt per Link erreichbar).
			var visible = courses.filter(function (c) { return !c.hidden; });
			var mine = visible.filter(function (c) { return !!c.progress; });
			var trials = mine.filter(function (c) { return c.trial; }).length;
			var isRec = function (c) { return !!(c.recommended_for && c.recommended_for.length); };
			var locked = visible.filter(function (c) { return !c.progress; });
			// Empfohlene zuerst, sonst Reihenfolge des Katalogs.
			var others = locked.filter(isRec).concat(locked.filter(function (c) { return !isRec(c); }));
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
						mine.length - trials > 0 || !trials ? el('li', {}, [el('strong', { text: String(mine.length - trials) }), ' ' + t('dash_stat_courses', { n: mine.length - trials })]) : null,
						trials ? el('li', {}, [el('strong', { text: String(trials) }), ' ' + t('dash_stat_trial')]) : null
					])
				]),
				focus ? el('div', { class: 'bp-hero-focus' }, [
					el('p', { class: 'bp-hero-focus-label', text: t('dash_focus') }),
					el('p', { class: 'bp-hero-focus-title', text: focus.c.name }),
					progressBar(focus.c.progress),
					el('p', {}, [link(focus.c.slug + '/lernen/', t('dash_continue'), 'bp-button bp-button-signal')])
				]) : null
			]);
			var nodes = [hero].concat(courses.filter(function (c) { return c.expires_soon; }).map(expiryNotice))
				.concat(courses.filter(function (c) { return accessState(c) && c.access.notice; }).map(accessNotice));
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
			if (!visible.length) {
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
			var icons = ['spark', 'timer', 'flag', 'compass', 'bookmark', 'layers'];
			var total = groups.reduce(function (a, g) { return a + g.tips.length; }, 0);
			var sections = groups.map(function (g, gi) {
				return el('section', { class: 'bp-tips-group' }, [
					el('div', { class: 'bp-tips-head' }, [el('span', { class: 'bp-ico bp-ico-' + icons[gi % icons.length], 'aria-hidden': 'true' }), el('h2', { text: g.name }), el('span', { class: 'bp-pill', text: t('tips_count', { n: g.tips.length }) })]),
					el('div', { class: 'bp-tips' }, g.tips.map(function (tip) {
						var body = el('div', { class: 'bp-tip-body' });
						body.innerHTML = tip.html; // serverseitig mit wp_kses_post bereinigt
						return el('details', { class: 'bp-card bp-tip' }, [el('summary', { text: tip.title }), body]);
					}))
				]);
			});
			mount([
				pageHero({ icon: 'bulb', accent: '#F5B900', crumbs: el('nav', { class: 'bp-breadcrumb', 'aria-label': t('breadcrumb') }, [link('', t('overview_title')), ' › ', el('span', { text: t('tips_title') })]), eyebrow: t('dash_eyebrow'), title: t('tips_title'), intro: t('tips_intro'), stats: groups.length ? [[total, t('tips_stat_tips')], [groups.length, t('tips_stat_groups')]] : [] }),
				groups.length ? el('div', { class: 'bp-tips-wrap' }, sections) : el('p', { text: t('tips_empty') })
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
					api('POST', 'groups/join', { token: token }).then(function () { navigate(''); }).catch(function (e) { join.disabled = false; alertMessage(box, e); });
				});
				box.appendChild(el('div', { class: 'bp-actions' }, [join]));
			}
			mount([el('nav', { class: 'bp-breadcrumb', 'aria-label': t('breadcrumb') }, [link('', t('overview_title'))]), box], t('group_join_title', { name: g.name }));
		});
	}

	function viewGroups() {
		return api('GET', 'me/groups').then(function (groups) {
			var seats = groups.reduce(function (a, g) { return a + (g.seats || 0); }, 0);
			var used = groups.reduce(function (a, g) { return a + ((g.seats || 0) - (g.free_seats || 0)); }, 0);
			var nodes = [
				pageHero({ icon: 'group', accent: '#66DCA0', crumbs: el('nav', { class: 'bp-breadcrumb', 'aria-label': t('breadcrumb') }, [link('', t('overview_title')), ' › ', el('span', { text: t('groups_title') })]), eyebrow: t('dash_eyebrow'), title: t('groups_title'), intro: t('groups_intro'), stats: groups.length ? [[groups.length, t('groups_stat_groups')], [used + '/' + seats, t('groups_stat_seats')]] : [] })
			];
			if (!groups.length) {
				nodes.push(el('section', { class: 'bp-empty' }, [
					el('span', { class: 'bp-ico bp-ico-group', 'aria-hidden': 'true' }),
					el('h2', { text: t('groups_empty_title') }),
					el('p', { class: 'bp-muted', text: t('groups_none') }),
					cfg.groupsUrl ? el('p', {}, [el('a', { class: 'bp-button bp-button-primary', href: cfg.groupsUrl, text: t('groups_empty_cta') })]) : null
				]));
			}
			groups.forEach(function (g) { nodes.push(groupCard(g)); });
			mount(nodes, t('groups_title'));
			// Kachel „Plätze belegt“ nach Einladen/Freigeben aktualisieren (die Karten melden ihren neuen Stand).
			var byId = {};
			groups.forEach(function (g) { byId[g.id] = g; });
			if (viewGroups.onUpdate) { document.removeEventListener('bp-group-updated', viewGroups.onUpdate); }
			document.addEventListener('bp-group-updated', viewGroups.onUpdate = function (ev) {
				if (!ev.detail || !byId[ev.detail.id]) { return; }
				byId[ev.detail.id] = ev.detail;
				var all = Object.keys(byId).map(function (k) { return byId[k]; });
				var total = all.reduce(function (a, g) { return a + (g.seats || 0); }, 0);
				var taken = all.reduce(function (a, g) { return a + ((g.seats || 0) - (g.free_seats || 0)); }, 0);
				var stat = document.querySelector('.bp-page-stats li:nth-child(2) strong');
				if (stat) { stat.textContent = taken + '/' + total; }
			});
		});
	}

	function groupCard(g) {
		var card = el('section', { class: 'bp-card bp-group', 'aria-label': g.name });
		var first = true;
		function render(data) {
			g = data;
			if (!first) { document.dispatchEvent(new CustomEvent('bp-group-updated', { detail: g })); }
			first = false;
			card.textContent = '';
			var name = el('input', { type: 'text', value: g.name, 'aria-label': t('group_name'), class: 'bp-input' });
			var save = el('button', { type: 'button', class: 'bp-button', text: t('save') });
			save.addEventListener('click', function () { api('PUT', 'me/groups/' + g.id, { name: name.value }).then(render).catch(function (e) { alertMessage(card, e); }); });
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
					}).catch(function (e) { send.disabled = false; alertMessage(card, e); });
				});
				card.appendChild(el('h3', { text: t('group_invite_emails') }));
				card.appendChild(el('div', { class: 'bp-actions' }, [emails, send]));
			}
			card.appendChild(el('h3', { text: t('group_members') }));
			// Anonymisierte Einladungen (abgelaufen, Adresse gelöscht) nicht mehr anzeigen.
			var members = g.members.filter(function (m) { return m.name || m.email; });
			card.appendChild(members.length ? el('ul', { class: 'bp-members' }, members.map(function (m) {
				var label = el('span', { text: (m.name || m.email) + (m.name && m.email ? ' <' + m.email + '>' : '') + ' · ' + t('group_member_' + m.status) });
				// Abgelaufene Einladungen belegen keinen Platz – nichts freizugeben.
				if (m.status === 'expired') { return el('li', {}, [label]); }
				var remove = el('button', { type: 'button', class: 'bp-button bp-button-small', text: t('group_remove') });
				remove.addEventListener('click', function () {
					var reason = window.prompt(t('group_remove_confirm', { name: m.name || m.email }));
					if (reason === null) { return; }
					api('DELETE', 'me/groups/' + g.id + '/members/' + m.id, { reason: reason }).then(render).catch(function (e) { alertMessage(card, e); });
				});
				return el('li', {}, [label, ' ', remove]);
			})) : el('p', { class: 'bp-muted', text: t('group_no_members') }));
		}
		render(g);
		return card;
	}

	// ------------------------------------------------------------------ Kurs

	// Kopfbereich der Kursseite im Stil der Produktbilder: Navy, Wellen, Liniensymbol, Akzentfarbe je Kurs.
	var HERO_STYLE = {
		'SBF-SEE': ['anchor', '#40C4FF'], 'SBF-BIN-MOTOR': ['anchor', '#66DCA0'], 'SBF-BIN-SEGEL': ['sail', '#66DCA0'], 'SBF-SEE-BIN': ['anchor', '#8EA8FF'],
		'ADD-SEE-TO-BIN': ['plus', '#66DCA0'], 'ADD-BIN-TO-SEE': ['plus', '#40C4FF'], 'ADD-BIN-SEGEL': ['plus', '#66DCA0'], 'ADD-UBI-TO-SRC': ['plus', '#40C4FF'], 'ADD-FKN-TO-SKN': ['plus', '#FF8C5A'],
		'SRC': ['radio', '#40C4FF'], 'UBI': ['radio', '#66DCA0'], 'SRC-UBI': ['radio', '#8EA8FF'], 'SKN': ['flare', '#FF785A'], 'FKN': ['flare', '#FFA05A']
	};
	var HERO_ICONS = {
		anchor: '<circle cx="60" cy="22" r="11"/><path d="M60 33v70"/><path d="M36 52h48"/><path d="M22 74c4 18 20 29 38 29s34-11 38-29"/>',
		radio: '<rect x="54" y="58" width="12" height="50" rx="2" fill="currentColor" stroke="none"/><path d="M44 46a22 22 0 0 1 32 0"/><path d="M33 35a38 38 0 0 1 54 0"/><path d="M22 24a54 54 0 0 1 76 0"/>',
		sail: '<path d="M56 12v74H22z" fill="currentColor" stroke="none"/><path d="M64 26v60h30z" fill="currentColor" stroke="none"/><path d="M18 94h84l-10 14H28z" fill="currentColor" stroke="none"/>',
		flare: '<rect x="53" y="62" width="14" height="46" rx="2" fill="currentColor" stroke="none"/><path d="M60 40V8M60 40l23-23M60 40h32M60 40l23 23M60 40l-23-23M60 40H28M60 40 37 63"/><circle cx="60" cy="40" r="10" fill="#fff" stroke="none"/>',
		plus: '<circle cx="60" cy="60" r="44"/><path d="M60 36v48M36 60h48" stroke-width="10"/>',
		compass: '<circle cx="60" cy="60" r="46"/><path d="M78 42 68 68 42 78 52 52z" fill="currentColor" stroke="none"/><path d="M60 8v10M60 102v10M8 60h10M102 60h10"/>',
		cards: '<rect x="18" y="34" width="62" height="74" rx="8"/><path d="M40 14h54a8 8 0 0 1 8 8v66"/>',
		bulb: '<path d="M44 92h32M48 106h24"/><path d="M60 12a32 32 0 0 0-19 57c4 3 6 8 6 13h26c0-5 2-10 6-13a32 32 0 0 0-19-57z"/>',
		group: '<circle cx="44" cy="40" r="15"/><path d="M14 100a30 30 0 0 1 60 0"/><circle cx="84" cy="46" r="12"/><path d="M80 72a26 26 0 0 1 28 28"/>',
		flag: '<path d="M28 110V14"/><path d="M28 16h58l-12 22 12 22H28" fill="currentColor" fill-opacity=".25"/>',
		bookmark: '<path d="M34 12h52a6 6 0 0 1 6 6v92L60 90 28 110V18a6 6 0 0 1 6-6z"/><path d="M60 34l6 12 13 2-9.5 9 2.3 13L60 64l-11.8 6 2.3-13-9.5-9 13-2z" fill="currentColor" stroke="none"/>',
		timer: '<circle cx="60" cy="66" r="40"/><path d="M60 44v22l14 14"/><path d="M46 14h28M60 14v12"/>'
	};

	function heroArt(kind) {
		var wrap = el('span', { class: 'bp-brand-art', 'aria-hidden': 'true' });
		wrap.innerHTML = '<svg viewBox="0 0 120 120" fill="none" stroke="currentColor" stroke-width="8" stroke-linecap="round" stroke-linejoin="round">' + (HERO_ICONS[kind] || HERO_ICONS.anchor) + '</svg>'; // feste Symbole, keine Nutzerdaten
		return wrap;
	}

	function heroWaves() {
		var wrap = el('span', { class: 'bp-brand-waves', 'aria-hidden': 'true' });
		wrap.innerHTML = '<svg viewBox="0 0 1200 120" preserveAspectRatio="none"><path d="M0 40 C150 10 300 70 450 40 S750 10 900 40 S1100 70 1200 40 V120 H0Z" fill="rgba(120,190,255,0.18)"/><path d="M0 70 C200 45 350 95 600 68 S1000 45 1200 72 V120 H0Z" fill="rgba(120,190,255,0.14)"/><path d="M0 95 C250 80 450 110 700 92 S1050 80 1200 98 V120 H0Z" fill="rgba(120,190,255,0.12)"/></svg>';
		return wrap;
	}

	// Kopf der Detailseiten (gleicher Stil wie die Kursseite, kompakter): Brotkrümel, Titel, Kennzahlen, Fortschritt.
	function pageHero(opts) {
		var style = opts.code ? (HERO_STYLE[opts.code] || ['anchor', '#40C4FF']) : ['anchor', opts.accent || '#40C4FF'];
		var kind = opts.icon || style[0];
		var stats = (opts.stats || []).filter(Boolean);
		return el('section', { class: 'bp-hero bp-brand-hero bp-page-hero', style: '--bp-hero-accent:' + style[1] }, [
			heroArt(kind),
			heroWaves(),
			el('div', { class: 'bp-hero-text' }, [
				opts.crumbs,
				opts.eyebrow ? el('p', { class: 'bp-brand-eyebrow', text: opts.eyebrow }) : null,
				el('h1', { text: opts.title }),
				opts.intro ? el('p', { class: 'bp-hero-lead', text: opts.intro }) : null,
				stats.length ? el('ul', { class: 'bp-page-stats' }, stats.map(function (x) { return el('li', {}, [el('strong', { text: String(x[0]) }), el('span', { text: x[1] })]); })) : null,
				opts.percent !== undefined && opts.percent !== null ? el('div', { class: 'bp-page-progress' }, [el('span', { class: 'bp-page-bar', 'aria-hidden': 'true' }, [el('span', { style: 'width:' + opts.percent + '%' })]), el('span', { class: 'bp-page-pct', text: t('page_pct', { percent: opts.percent }) + (opts.percentLabel ? ' ' + opts.percentLabel : '') })]) : null,
				opts.extra || null
			])
		]);
	}

	// Kennzahlen und Fortschritt eines pageHero ohne Neuaufbau aktualisieren (Fokus bleibt erhalten).
	function updateHero(hero, stats, percent, percentLabel) {
		if (!hero) {
			return;
		}
		var items = hero.querySelectorAll('.bp-page-stats li');
		(stats || []).filter(Boolean).forEach(function (x, i) {
			var strong = items[i] ? items[i].querySelector('strong') : null;
			if (strong) { strong.textContent = String(x[0]); }
		});
		if (percent !== undefined && percent !== null) {
			var bar = hero.querySelector('.bp-page-bar > span');
			var pct = hero.querySelector('.bp-page-pct');
			if (bar) { bar.style.width = percent + '%'; }
			if (pct) { pct.textContent = t('page_pct', { percent: percent }) + (percentLabel ? ' ' + percentLabel : ''); }
		}
	}

	function courseHero(slug, c) {
		var p = c.progress ? c.progress.total : null;
		var style = HERO_STYLE[c.code] || ['anchor', '#40C4FF'];
		var hero = el('section', { class: 'bp-hero bp-course-hero bp-hub-hero bp-brand-hero', style: '--bp-hero-accent:' + style[1] }, [
			heroArt(style[0]),
			heroWaves(),
			el('div', { class: 'bp-hero-text' }, [
				el('nav', { class: 'bp-breadcrumb', 'aria-label': t('breadcrumb') }, [link('', t('overview_title')), ' › ', el('span', { text: c.name })]),
				el('p', { class: 'bp-brand-eyebrow', text: t('overview_title') + ' · ' + typeLabel(c.type) }),
				el('h1', { text: c.name }),
				el('div', { class: 'bp-brand-foot' }, [
					p ? el('span', { class: 'bp-brand-pill', text: t('hub_pill', { percent: p.percent }) }) : null,
					el('span', { class: 'bp-brand-tag', text: t('hub_tagline') })
				])
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

	function videoParts(c, ownVideos) {
		var codes = COURSE_PARTS[c.code] || [c.code];
		return Promise.all(codes.map(function (code) {
			var partSlug = code.toLowerCase();
			var info = code === c.code ? Promise.resolve(c) : api('GET', 'courses/' + encodeURIComponent(partSlug)).catch(function () { return null; });
			// Eigene Videos des Kurses nur einmal laden (wird auch für die Knoten-Kachel gebraucht).
			var videos = code === c.code ? ownVideos : api('GET', 'courses/' + encodeURIComponent(partSlug) + '/videos').catch(function () { return null; });
			return Promise.all([info, videos]).then(function (r) { return { slug: partSlug, course: r[0], videos: r[1] }; });
		})).then(function (parts) {
			return parts.filter(function (x) { return x.course && x.videos && x.videos.lessons > 0; });
		});
	}

	function videoCard(part, index, c) {
		var v = part.videos;
		// Videokurs nicht (voll) freigeschaltet, z. B. Theorie-Stufe: erstes Angebot (Upgrade) direkt auf der Karte.
		var locked = c && ((c.limits && c.limits.video_course) || c.features.video_course === false);
		var offer = locked && c.shop && c.shop.video_course ? c.shop.video_course[0] : null;
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
			el('div', { class: 'bp-hub-card-actions' }, [
				el('a', { href: base + part.slug + '/video/', 'data-bp-link': '1', class: 'bp-button bp-button-light bp-hub-card-go' }, [v.completed > 0 ? t('hub_video_continue') : (locked ? t('hub_video_try') : t('hub_video_start')), el('span', { class: 'bp-arrow', 'aria-hidden': 'true', text: '→' })]),
				offer ? el('a', { href: offer.url, class: 'bp-hub-card-unlock' }, [t('hub_video_unlock'), offer.price ? el('span', { text: ' · ' + offer.price }) : null]) : null
			])
		]);
	}

	function viewCourse(slug) {
		return api('GET', 'courses/' + encodeURIComponent(slug)).then(function (c) {
			var own = api('GET', 'courses/' + encodeURIComponent(slug) + '/videos').catch(function () { return null; });
			// Kunden-FAQ nur für Kunden des Kurses (Anzahl > 0); bei einem Fehler entfällt der Abschnitt.
			var faqs = c.customer_faqs > 0 ? api('GET', 'courses/' + encodeURIComponent(slug) + '/customer-faqs').catch(function () { return null; }) : null;
			return Promise.all([videoParts(c, own), own, faqs]).then(function (r) { renderCourseHub(slug, c, r[0], r[1], r[2]); });
		});
	}

	// Videokapitel „Knoten“ eines Kurses (SBF See, SBF Binnen, Kombi).
	function knotModule(videos) {
		return videos ? (videos.modules || []).filter(function (m) { return /^Knoten$/i.test(m.title); })[0] || null : null;
	}

	// „Fragen & Antworten zu deinem Kurs“ (Kunden-FAQ): je Schein (nur bei mehreren mit Überschrift), darunter die
	// Unterkategorien als Zwischenüberschriften (Tiefe aus depth) und die Fragen als aufklappbare Karten.
	function customerFaqSection(groups) {
		if (!Array.isArray(groups)) {
			return null;
		}
		var total = 0;
		var multi = groups.length > 1;
		var list = function (faqs) {
			if (!faqs || !faqs.length) {
				return null;
			}
			total += faqs.length;
			return el('div', { class: 'bp-tips' }, faqs.map(function (f) {
				var body = el('div', { class: 'bp-tip-body' });
				body.innerHTML = f.answer_html; // serverseitig mit wp_kses_post bereinigt
				return el('details', { class: 'bp-card bp-tip' }, [el('summary', { text: f.question }), body]);
			}));
		};
		var blocks = groups.map(function (g) {
			var count = (g.faqs || []).length + (g.subcategories || []).reduce(function (n, s) { return n + s.faqs.length; }, 0);
			var nodes = multi ? [el('div', { class: 'bp-tips-head' }, [el('span', { class: 'bp-ico bp-ico-layers', 'aria-hidden': 'true' }), el('h3', { text: g.name }), el('span', { class: 'bp-pill', text: t('hub_faq_count', { n: count }) })])] : [];
			nodes.push(list(g.faqs));
			(g.subcategories || []).forEach(function (s) {
				var level = Math.min(6, (multi ? 3 : 2) + Math.max(1, s.depth));
				nodes.push(el('h' + level, { class: 'bp-hub-faq-sub' + (s.depth > 1 ? ' is-nested' : ''), text: s.name }));
				nodes.push(list(s.faqs));
			});
			return el('div', { class: 'bp-tips-group bp-hub-faq-group' }, nodes);
		});
		if (!total) {
			return null;
		}
		return el('section', { class: 'bp-section bp-hub-faq', 'aria-labelledby': 'bp-hub-faq-h' }, [
			el('div', { class: 'bp-section-head' }, [el('h2', { id: 'bp-hub-faq-h', text: t('hub_faq_title') }), el('p', { class: 'bp-muted', text: t('hub_faq_hint') })]),
			el('div', { class: 'bp-tips-wrap' }, blocks)
		]);
	}

	function renderCourseHub(slug, c, parts, ownVideos, faqs) {
		{
			var shop = c.shop || {};
			var p = c.progress ? c.progress.total : null;
			var qb = c.features.question_bank;
			var nodes = [courseHero(slug, c)];

			// Zahlung offen, abgelaufen oder gesperrt – sonst Freischalten (gesperrt oder kostenloser Teilumfang)
			var accessBox = accessHubBox(c);
			if (accessBox) {
				nodes.push(accessBox);
			} else if ((!qb || (c.progress && c.progress.limit)) && shop.question_bank) {
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
				hubLink(slug, qb ? 'lernen/' : 'smarttrainer/', qb ? t('dash_continue') : t('sim_unlock'), 'bp-button bp-button-light')
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
					el('a', { href: base + slug + '/merkliste/', 'data-bp-link': '1', 'aria-label': t('mode_favorites') + ' (' + c.favorites + ')' }, [el('span', { class: 'bp-ico bp-ico-bookmark', 'aria-hidden': 'true' }), el('span', { 'aria-hidden': 'true', text: t('mode_favorites') }), el('strong', { 'aria-hidden': 'true', text: String(c.favorites) })])
				])
			]) : null;
			// Oben immer die Videokurse (Kombi: je Teilkurs); ohne Videos: nächster Schritt + Prüfung.
			var cards = parts.length ? parts.map(function (part, i) { return videoCard(part, i, c); }) : [next, exam];
			nodes.push(el('div', { class: 'bp-hub-top' + (progress ? '' : ' is-single') }, [el('div', { class: 'bp-hub-cards' + (cards.length === 1 ? ' is-one' : '') }, cards), progress]));

			// Lernbereiche
			var areas = [];
			var tr = c.trainers;
			var pct = function (a, b) { return b > 0 ? Math.floor(a * 100 / b) : null; };
			areas.push({ path: 'smarttrainer/', icon: 'spark', title: t('mode_smart'), meta: c.progress ? (function (n) { return t(n === 1 ? 'hub_topics_meta_one' : 'hub_topics_meta', { n: n }); })(c.progress.modules.reduce(function (n, m) { return n + ((m.topics && m.topics.length) ? m.topics.length : 1); }, 0)) : t('locked_short'), locked: !qb, percent: p ? p.percent : null });
			var knots = knotModule(ownVideos);
			if (knots && knots.lessons.length) {
				var seen = knots.lessons.filter(function (l) { return l.completed; }).length;
				areas.push({ path: 'knoten/', icon: 'rope', title: t('knots_title'), meta: t('knots_meta', { done: seen, total: knots.lessons.length }), locked: c.features.video_course === false, percent: pct(seen, knots.lessons.length) });
			}
			areas.push({ path: 'merkliste/', icon: 'bookmark', title: t('saved_title'), meta: qb ? t('saved_meta', { n: c.favorites }) : t('locked_short'), locked: !qb });
			areas.push({ path: 'pruefung/', icon: 'timer', title: t('section_exam'), meta: c.exam && c.exam.papers.length ? t('hub_exam_meta', { n: c.exam.papers.length }) : (c.exam ? t('hub_exam_meta_random') : t('locked_short')), locked: !c.exam });
			if (tr && tr.navigation.tasks > 0) {
				areas.push({ path: 'navigation/', icon: 'compass', title: t('trainer_navigation'), meta: t('trainer_navigation_meta', { done: tr.navigation.done, total: tr.navigation.questions, tasks: tr.navigation.tasks }), locked: c.features.navigation_trainer === false, percent: pct(tr.navigation.done, tr.navigation.questions) });
			}
			if (tr && tr.praxis.cards > 0) {
				areas.push({ path: 'praxis/', icon: 'cards', title: t('trainer_praxis'), meta: t('trainer_praxis_meta', { known: tr.praxis.known, total: tr.praxis.cards }), locked: c.features.praxis_trainer === false, percent: pct(tr.praxis.known, tr.praxis.cards) });
			}
			// Videokurs nicht als Kachel: er steht oben als große Karte(n).
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
			// Kunden-FAQ nach den Lernbereichen (nur mit mindestens einer Frage).
			nodes.push(customerFaqSection(faqs));
			mount(nodes, c.name);
		}
	}

	function detailHeader(slug, c, title, intro) {
		return [crumbs(slug, c.name, [[title]]), el('header', { class: 'bp-header bp-detail-head' }, [el('p', { class: 'bp-learn-course', text: c.name }), el('h1', { text: title }), intro ? el('p', { class: 'bp-muted', text: intro }) : null])];
	}

	function viewKnots(slug) {
		return withCourse(slug, function (c) {
			return api('GET', 'courses/' + encodeURIComponent(slug) + '/videos').catch(function () { return null; }).then(function (v) {
				var m = knotModule(v);
				var nodes = detailHeader(slug, c, t('knots_title'), t('knots_intro'));
				if (!m) {
					nodes.push(c.features.video_course === false ? lockedBox(t('knots_title'), (c.shop || {}).video_course) : el('p', { text: t('knots_none') }));
					mount(nodes, t('knots_title') + ' – ' + c.name);
					return;
				}
				var done = m.lessons.filter(function (l) { return l.completed; }).length;
				var pctDone = Math.floor(done * 100 / Math.max(m.lessons.length, 1));
				nodes.push(el('div', { class: 'bp-knots-progress' }, [
					el('p', {}, [el('strong', { text: t('knots_meta', { done: done, total: m.lessons.length }) })]),
					el('div', { class: 'bp-hub-bar bp-knots-bar', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(pctDone), 'aria-label': t('knots_meta', { done: done, total: m.lessons.length }) }, [el('span', { style: 'width:' + pctDone + '%' })])
				]));
				if (m.locked) {
					nodes.push(el('p', { class: 'bp-note', text: t('unlocks_on', { date: fmtDate(m.unlock_at) }) }));
				}
				nodes.push(el('ol', { class: 'bp-knots' }, m.lessons.map(function (l, i) {
					var state = l.locked ? 'locked' : (l.completed ? 'done' : 'open');
					return el('li', { class: 'bp-knot is-' + state }, [
						el('span', { class: 'bp-knot-no', 'aria-hidden': 'true', text: String(i + 1) }),
						el('div', { class: 'bp-knot-text' }, [
							el('h2', {}, [l.locked ? el('span', { text: l.title }) : link(slug + '/video/' + l.id + '/', l.title)]),
							el('p', { class: 'bp-knot-meta' }, [
								l.steps ? t('knots_steps', { n: l.steps }) : null,
								l.duration ? ' · ' + duration(l.duration) : null,
								el('span', { class: 'bp-knot-state', text: state === 'done' ? t('knots_done') : (state === 'locked' ? t('locked_badge') : t('knots_open')) })
							])
						]),
						el('span', { class: 'bp-area-chevron', 'aria-hidden': 'true' })
					]);
				})));
				mount(nodes, t('knots_title') + ' – ' + c.name);
			});
		});
	}

	// Kleiner Fortschrittsring mit Prozentzahl (Themen-Kacheln, Prüfungsbögen).
	function miniRing(percent, label) {
		return el('span', { class: 'bp-mini-ring', 'aria-hidden': 'true' }, [ring(percent), el('span', { class: 'bp-mini-ring-val', text: label !== undefined ? label : percent + ' %' })]);
	}

	function topicHref(slug, moduleId, topic) {
		return slug + '/lernen/?modul=' + moduleId + (topic ? '&thema=' + encodeURIComponent(topic) : '');
	}

	function topicTile(href, name, p) {
		return el('li', {}, [el('a', { href: base + href, 'data-bp-link': '1', class: 'bp-topic', 'aria-label': t('topics_tile_aria', { name: name, mastered: p.mastered, total: p.questions }) }, [
			miniRing(p.percent),
			el('span', { class: 'bp-topic-text', 'aria-hidden': 'true' }, [el('strong', { text: name }), el('span', { text: t('topics_count', { mastered: p.mastered, total: p.questions }) })]),
			el('span', { class: 'bp-ico bp-ico-bulb bp-topic-ico', 'aria-hidden': 'true' })
		])]);
	}

	function viewTopics(slug) {
		return api('GET', 'courses/' + encodeURIComponent(slug)).then(function (c) {
			var title = t('mode_smart');
			if (!c.features.question_bank) {
				mount(detailHeader(slug, c, title, t('smart_explain')).concat([lockedBox(t('section_learn'), (c.shop || {}).question_bank)]), title + ' – ' + c.name);
				return;
			}
			var p = c.progress.total;
			var topicCount = 0;
			c.progress.modules.forEach(function (m) { topicCount += (m.topics && m.topics.length) ? m.topics.length : 1; });
			var nodes = [pageHero({
				code: c.code, icon: 'bulb', crumbs: crumbs(slug, c.name, [[title]]), eyebrow: c.name, title: title,
				stats: [[p.questions, t('topics_stat_questions')], [topicCount, t('topics_stat_topics')], [p.unsure, t('mode_wrong')]],
				percent: p.percent, percentLabel: t('course_hero_mastered')
			})];

			nodes.push(el('div', { class: 'bp-infobox' }, [el('span', { class: 'bp-ico bp-ico-bulb', 'aria-hidden': 'true' }), el('p', { text: t('smart_explain') })]));
			if (c.progress.limit) {
				nodes.push(el('div', { class: 'bp-card bp-locked' }, [el('p', { text: t('free_limit_questions', { n: c.progress.limit }) })].concat(offerNodes((c.shop || {}).question_bank))));
			}

			// Hervorgehoben: Smarttrainer und Merkliste, darunter weitere Lernmodi.
			nodes.push(el('div', { class: 'bp-feature-cards' }, [
				el('a', { href: base + slug + '/lernen/', 'data-bp-link': '1', class: 'bp-feature-card is-smart', 'aria-label': t('topics_smart_aria') }, [
					miniRing(p.percent),
					el('span', { class: 'bp-feature-text', 'aria-hidden': 'true' }, [el('strong', { text: t('mode_smart') }), el('span', { text: t('progress_short', { mastered: p.mastered, total: p.questions }) }), el('span', { class: 'bp-feature-cta', text: t('topics_smart_cta') + ' →' })]),
					el('span', { class: 'bp-ico bp-ico-spark bp-feature-ico', 'aria-hidden': 'true' })
				]),
				el('a', { href: base + slug + '/merkliste/', 'data-bp-link': '1', class: 'bp-feature-card is-saved', 'aria-label': t('mode_favorites') + ' (' + c.favorites + ')' }, [
					el('span', { class: 'bp-feature-count', 'aria-hidden': 'true', text: String(c.favorites) }),
					el('span', { class: 'bp-feature-text', 'aria-hidden': 'true' }, [el('strong', { text: t('topics_saved') }), el('span', { text: c.favorites ? t('topics_saved_text') : t('topics_saved_empty') }), el('span', { class: 'bp-feature-cta', text: t('topics_saved_cta') + ' →' })]),
					el('span', { class: 'bp-ico bp-ico-bookmark bp-feature-ico', 'aria-hidden': 'true' })
				])
			]));
			nodes.push(el('ul', { class: 'bp-modes bp-modes-2' }, [
				modeTile(slug + '/lernen/?modus=new', t('mode_new'), p.new, t('mode_new_desc'), 'plus'),
				modeTile(slug + '/lernen/?modus=wrong', t('mode_wrong'), p.unsure, t('mode_wrong_desc'), 'repeat')
			]));

			// Module als Gruppen, darin die Themen als Kacheln.
			var list = el('ul', { class: 'bp-module-list bp-topic-groups' });
			c.progress.modules.forEach(function (m) {
				var head = el('summary', {}, [
					el('span', { class: 'bp-topic-group-title' }, [el('strong', { text: m.name }), m.locked ? el('span', { text: t('unlocks_on', { date: fmtDate(m.unlock_at) }) }) : el('span', { text: t('topics_group_meta', { mastered: m.mastered, total: m.questions, percent: m.percent }) })]),
					m.locked ? badge(t('locked_badge'), 'warn') : el('span', { class: 'bp-topic-group-bar', 'aria-hidden': 'true' }, [el('span', { style: 'width:' + m.percent + '%' })]),
					el('span', { class: 'bp-topic-chevron', 'aria-hidden': 'true' })
				]);
				var body = el('div', { class: 'bp-topic-group-body' });
				if (!m.locked) {
					if (m.topics && m.topics.length) {
						body.appendChild(el('ul', { class: 'bp-topic-tiles' }, m.topics.map(function (tp) { return topicTile(topicHref(slug, m.id, tp.name), tp.name, tp); })));
						body.appendChild(el('p', { class: 'bp-module-go' }, [link(topicHref(slug, m.id, null), t('learn_module_all', { name: m.name }), 'bp-button')]));
					} else {
						body.appendChild(el('ul', { class: 'bp-topic-tiles' }, [topicTile(topicHref(slug, m.id, null), m.name, m)]));
					}
				}
				var details = m.locked ? el('div', { class: 'bp-topic-group is-locked' }, [el('div', { class: 'bp-topic-group-head' }, Array.prototype.slice.call(head.childNodes))]) : el('details', { class: 'bp-topic-group' }, [head, body]);
				details.open = !m.locked;
				list.appendChild(el('li', { class: 'bp-card bp-module' + (m.locked ? ' bp-locked' : '') }, [details]));
			});
			nodes.push(el('section', { class: 'bp-section bp-topics-section', 'aria-labelledby': 'bp-topics-h' }, [el('h2', { class: 'bp-subhead', id: 'bp-topics-h', text: t('modules_title') }), list]));
			mount(nodes, title + ' – ' + c.name);
		});
	}

	// ------------------------------------------------------------------ Favoriten & Fehler (Sammlungen)

	function viewSaved(slug, collectionParam, tabParam) {
		return api('GET', 'courses/' + encodeURIComponent(slug)).then(function (c) {
			var title = t('saved_title');
			if (!c.features.question_bank) {
				mount(detailHeader(slug, c, title, t('saved_intro')).concat([lockedBox(t('section_learn'), (c.shop || {}).question_bank)]), title + ' – ' + c.name);
				return;
			}
			var state = { filter: collectionParam && /^\d+$/.test(collectionParam) ? parseInt(collectionParam, 10) : 'all', tab: tabParam === 'fehler' ? 'wrong' : 'fav', creating: false, message: null, data: null };
			var hero = el('div');
			var live = el('p', { class: 'bp-sr', role: 'status' });
			var body = el('section', { class: 'bp-section bp-saved', 'aria-labelledby': 'bp-saved-h' });
			mount([hero, el('div', { class: 'bp-infobox' }, [el('span', { class: 'bp-ico bp-ico-bookmark', 'aria-hidden': 'true' }), el('p', { text: t('saved_info') })]), live, body], title + ' – ' + c.name);

			function load(message) {
				return api('GET', 'courses/' + encodeURIComponent(slug) + '/saved').then(function (d) {
					state.data = d;
					if (typeof state.filter === 'number' && !d.collections.some(function (x) { return x.id === state.filter; })) {
						state.filter = 'all';
					}
					if (collectionParam !== null && state.filter === 'all') {
						collectionParam = null;
						syncUrl(); // ungültige oder gelöschte Sammlung aus der Adresse entfernen
					}
					state.message = message || null;
					live.textContent = message || '';
					render();
					// Erster Aufbau: Überschrift steht erst jetzt im Hero – wie bei mount() dorthin fokussieren.
					if (!state.focused) {
						state.focused = true;
						var h1 = hero.querySelector('h1');
						if (h1 && (!document.activeElement || document.activeElement === document.body)) {
							h1.setAttribute('tabindex', '-1');
							h1.focus({ preventScroll: true });
						}
					}
				});
			}

			function fail(e) {
				state.message = null;
				render();
				alertMessage(body, e);
			}

			// Formularfehler: Eingabe behalten, Fehler am Feld (aria-describedby), Fokus zurück ins Feld.
			function formFail(key, e) {
				state.message = null;
				state.formError = { key: key, error: e };
				state.focusKey = key === 'new' ? 'new-input' : 'rename';
				render();
			}

			function formErrorNode(id, e) {
				var n = alertMessage(el('div'), e);
				n.id = id;
				return n;
			}

			function syncUrl() {
				var q = [];
				if (typeof state.filter === 'number') { q.push('sammlung=' + state.filter); }
				if (state.tab === 'wrong') { q.push('ansicht=fehler'); }
				try { history.replaceState(null, '', base + slug + '/merkliste/' + (q.length ? '?' + q.join('&') : '')); } catch (e) { /* ohne History-API weiter */ }
			}

			function inFilter(item) {
				if (state.filter === 'all') { return true; }
				return item.collections.indexOf(state.filter) !== -1;
			}

			function chip(label, value, count, icon) {
				var on = state.filter === value;
				var b = el('button', { type: 'button', class: 'bp-chip' + (on ? ' is-active' : ''), 'aria-pressed': on ? 'true' : 'false', 'data-key': 'chip-' + value }, [icon ? el('span', { class: 'bp-ico bp-ico-' + icon, 'aria-hidden': 'true' }) : null, label, count !== null ? ' ' : null, count !== null ? el('span', { class: 'bp-chip-count', text: String(count) }) : null]);
				b.addEventListener('click', function () { state.filter = value; state.creating = false; state.formError = null; state.message = null; syncUrl(); render(); });
				return b;
			}

			function render() {
				var activeKey = document.activeElement && body.contains(document.activeElement) ? document.activeElement.getAttribute('data-key') : null;
				var d = state.data;
				var items = d.items;
				var favCount = items.filter(function (i) { return i.favorite; }).length;
				var wrongCount = items.filter(function (i) { return i.wrong; }).length;
				setChildren(hero, [pageHero({ code: c.code, icon: 'bookmark', crumbs: crumbs(slug, c.name, [[title]]), eyebrow: c.name, title: title, intro: t('saved_intro'), stats: [[favCount, t('saved_favorites')], [wrongCount, t('saved_tab_wrong')], [d.collections.length, t('saved_stat_collections')]] })]);

				var nodes = [el('h2', { id: 'bp-saved-h', class: 'bp-sr', text: t('saved_collections') })];
				if (state.message) {
					nodes.push(el('p', { class: 'bp-saved-msg', text: state.message }));
				}
				var chips = [chip(t('saved_all_fav'), 'all', favCount, 'star')];
				d.collections.forEach(function (col) { chips.push(chip(col.name, col.id, col.count)); });
				var add = el('button', { type: 'button', class: 'bp-chip bp-chip-add', 'aria-expanded': state.creating ? 'true' : 'false', 'data-key': 'chip-add', text: '+ ' + t('saved_new') });
				add.addEventListener('click', function () { state.creating = !state.creating; state.formError = null; render(); if (state.creating) { body.querySelector('#bp-saved-new').focus(); } });
				chips.push(add);
				nodes.push(el('div', { class: 'bp-chips', role: 'group', 'aria-label': t('saved_collections') }, chips));

				if (state.creating) {
					var newErr = state.formError && state.formError.key === 'new' ? state.formError : null;
					var input = el('input', { type: 'text', id: 'bp-saved-new', maxlength: '60', placeholder: t('saved_new_placeholder'), 'aria-label': t('saved_new_label'), 'data-key': 'new-input', 'aria-invalid': newErr ? 'true' : null, 'aria-describedby': newErr ? 'bp-saved-new-err' : null });
					input.value = state.draftNew || '';
					var form = el('form', { class: 'bp-saved-form' }, [input, el('button', { type: 'submit', class: 'bp-button bp-button-primary', text: t('saved_create') })]);
					form.addEventListener('submit', function (ev) {
						ev.preventDefault();
						var submit = form.querySelector('button[type=submit]');
						if (isBusy(submit)) { return; }
						setBusy(submit, true);
						state.draftNew = input.value;
						api('POST', 'courses/' + encodeURIComponent(slug) + '/collections', { name: input.value }).then(function (col) {
							state.creating = false;
							state.draftNew = '';
							state.formError = null;
							state.filter = col.id;
							state.focusKey = 'chip-' + col.id;
							syncUrl();
							return load(t('saved_created'));
						}).catch(function (e) { formFail('new', e); });
					});
					nodes.push(form);
					if (newErr) {
						nodes.push(formErrorNode('bp-saved-new-err', newErr.error));
					}
				}
				var current = typeof state.filter === 'number' ? d.collections.filter(function (x) { return x.id === state.filter; })[0] : null;
				if (current) {
					var renameErr = state.formError && state.formError.key === 'rename' ? state.formError : null;
					var nameInput = el('input', { type: 'text', maxlength: '60', 'aria-label': t('saved_rename_label'), 'data-key': 'rename', 'aria-invalid': renameErr ? 'true' : null, 'aria-describedby': renameErr ? 'bp-saved-rename-err' : null });
					nameInput.value = renameErr && state.draftRename !== undefined ? state.draftRename : current.name;
					var renameForm = el('form', { class: 'bp-saved-form bp-saved-edit' }, [
						nameInput,
						el('button', { type: 'submit', class: 'bp-link-button', 'data-key': 'rename-submit', text: t('saved_rename') }),
						el('button', { type: 'button', class: 'bp-link-button is-danger', text: t('saved_delete'), onclick: function () {
							if (!window.confirm(t('saved_delete_confirm', { name: current.name }))) { return; }
							api('DELETE', 'courses/' + encodeURIComponent(slug) + '/collections/' + current.id).then(function () {
								state.filter = 'all';
								state.focusKey = 'chip-all';
								syncUrl();
								return load(t('saved_deleted'));
							}).catch(fail);
						} })
					]);
					renameForm.addEventListener('submit', function (ev) {
						ev.preventDefault();
						// Nach dem Neuzeichnen zurück auf den Sammlungs-Chip (neuer Name), nicht auf <body>.
						state.focusKey = 'chip-' + current.id;
						state.draftRename = nameInput.value;
						api('PUT', 'courses/' + encodeURIComponent(slug) + '/collections/' + current.id, { name: nameInput.value }).then(function () { state.formError = null; return load(t('saved_renamed')); }).catch(function (e) { formFail('rename', e); });
					});
					nodes.push(renameForm);
					if (renameErr) {
						nodes.push(formErrorNode('bp-saved-rename-err', renameErr.error));
					}
				}

				var visible = items.filter(inFilter);
				var favList = visible.filter(function (i) { return i.favorite; });
				var wrongList = visible.filter(function (i) { return i.wrong; });
				var tabs = el('div', { class: 'bp-tabs', role: 'tablist', 'aria-label': title }, [['fav', t('saved_tab_fav'), favList.length], ['wrong', t('saved_tab_wrong'), wrongList.length]].map(function (tb) {
					var on = state.tab === tb[0];
					var b = el('button', { type: 'button', role: 'tab', id: 'bp-saved-tab-' + tb[0], 'data-key': 'tab-' + tb[0], 'aria-selected': on ? 'true' : 'false', 'aria-controls': 'bp-saved-panel', tabindex: on ? '0' : '-1', class: 'bp-tab' + (on ? ' is-active' : '') }, [tb[1], ' ', el('span', { class: 'bp-chip-count', text: String(tb[2]) })]);
					b.addEventListener('click', function () { state.tab = tb[0]; syncUrl(); render(); body.querySelector('#bp-saved-tab-' + tb[0]).focus(); });
					b.addEventListener('keydown', function (ev) {
						var to = ev.key === 'ArrowRight' || ev.key === 'ArrowLeft' ? (state.tab === 'fav' ? 'wrong' : 'fav') : (ev.key === 'Home' ? 'fav' : (ev.key === 'End' ? 'wrong' : null));
						if (to) { ev.preventDefault(); state.tab = to; syncUrl(); render(); body.querySelector('#bp-saved-tab-' + state.tab).focus(); }
					});
					return b;
				}));
				nodes.push(tabs);

				var list = state.tab === 'fav' ? favList : wrongList;
				var panel = el('div', { id: 'bp-saved-panel', role: 'tabpanel', 'aria-labelledby': 'bp-saved-tab-' + state.tab });
				if (list.length) {
					var learnHref = slug + '/lernen/?modus=' + (state.tab === 'wrong' ? 'wrong' : 'favorites') + (typeof state.filter === 'number' ? '&sammlung=' + state.filter : '');
					panel.appendChild(el('div', { class: 'bp-saved-actions' }, [
						el('p', { class: 'bp-muted', text: list.length === 1 ? t('saved_count_one') : t('saved_count', { n: list.length }) }),
						link(learnHref, t(state.tab === 'wrong' ? 'saved_learn_wrong' : 'saved_learn'), 'bp-button bp-button-primary')
					]));
					panel.appendChild(el('ul', { class: 'bp-saved-list' }, list.map(function (item) { return savedItem(item); })));
				} else {
					panel.appendChild(el('div', { class: 'bp-empty bp-saved-empty' }, [
						el('span', { class: 'bp-ico bp-ico-' + (state.tab === 'wrong' ? 'check' : 'bookmark'), 'aria-hidden': 'true' }),
						el('p', { text: state.tab === 'wrong' ? t('saved_empty_wrong') : (typeof state.filter === 'number' ? t('saved_empty_collection') : t('saved_empty_fav')) }),
						link(slug + '/lernen/', t('mode_smart'), 'bp-button')
					]));
				}
				nodes.push(panel);
				setChildren(body, nodes);
				var focusKey = state.focusKey || activeKey;
				var fallback = state.focusFallback || [];
				state.focusKey = null;
				state.focusFallback = null;
				if (focusKey) {
					// Ersatzfokus, falls das Element verschwunden ist (z. B. Favorit entfernt): Nachbar, sonst Reiter.
					var keys = [focusKey].concat(fallback, ['tab-' + state.tab]);
					for (var ki = 0; ki < keys.length; ki++) {
						var target = keys[ki] ? body.querySelector('[data-key="' + keys[ki] + '"]') : null;
						if (target) { target.focus(); break; }
					}
				}
			}

			function savedItem(item) {
				var star = el('button', { type: 'button', class: 'bp-icon-button bp-star' + (item.favorite ? ' is-on' : ''), 'data-key': 'star-' + item.question_id, 'aria-pressed': item.favorite ? 'true' : 'false', 'aria-label': (item.favorite ? t('fav_remove') : t('fav_add')) + ': ' + shorten(item.text) }, [el('span', { class: 'bp-ico bp-ico-star', 'aria-hidden': 'true' })]);
				star.addEventListener('click', function () {
					if (isBusy(star)) { return; }
					setBusy(star, true);
					state.focusKey = 'star-' + item.question_id;
					// Nachbarn merken: verschwindet der Eintrag aus der Liste, landet der Fokus dort.
					var row = star.closest('li');
					var sib = function (n) { var b = n ? n.querySelector('.bp-star') : null; return b ? b.getAttribute('data-key') : null; };
					state.focusFallback = [sib(row && row.nextElementSibling), sib(row && row.previousElementSibling)];
					assign(item, !item.favorite, item.favorite ? [] : item.collections, item.favorite ? t('saved_fav_removed') : t('saved_fav_added'));
				});
				var sortBtn = el('button', { type: 'button', class: 'bp-icon-button', 'data-key': 'sort-' + item.question_id, 'aria-expanded': 'false', 'aria-label': t('saved_sort_label') + ': ' + shorten(item.text) }, [el('span', { class: 'bp-ico bp-ico-folder', 'aria-hidden': 'true' })]);
				var li = el('li', { class: 'bp-saved-item' + (item.wrong ? ' is-wrong' : '') }, [
					el('span', { class: 'bp-saved-badge', 'aria-hidden': 'true' }, [el('span', { class: 'bp-ico bp-ico-' + (item.wrong && state.tab === 'wrong' ? 'repeat' : 'star') })]),
					el('div', { class: 'bp-saved-text' }, [
						el('p', { class: 'bp-saved-q', text: item.text }),
						el('p', { class: 'bp-saved-meta' }, [item.module, item.topic && item.topic !== item.module ? ' · ' + item.topic : null, item.number ? ' · ' + t('question_number', { number: item.number }) : null, item.wrong ? el('span', { class: 'bp-saved-tag', text: t('saved_tag_wrong') }) : null].concat(item.collections.map(function (cid) {
							var col = state.data.collections.filter(function (x) { return x.id === cid; })[0];
							return col ? el('span', { class: 'bp-saved-tag is-col', text: col.name }) : null;
						})))
					]),
					el('div', { class: 'bp-saved-tools' }, [star, item.favorite ? sortBtn : null])
				]);
				sortBtn.addEventListener('click', function () {
					toggleSortPop(li, sortBtn, { favorite: item.favorite, collections: item.collections, all: state.data.collections, onSave: function (fav, ids) { state.focusKey = 'sort-' + item.question_id; return assign(item, fav, ids, t('saved_updated')); } });
				});
				return li;
			}

			function assign(item, favorite, collections, message) {
				return api('PUT', 'courses/' + encodeURIComponent(slug) + '/saved/' + item.question_id, { favorite: favorite, collections: collections }).then(function () { return load(message); }).catch(function (e) { state.focusFallback = null; fail(e); });
			}

			return load();
		});
	}

	function shorten(text) {
		return text.length > 70 ? text.slice(0, 67) + '…' : text;
	}

	function viewExamHub(slug) {
		return api('GET', 'courses/' + encodeURIComponent(slug) + '?ergebnisse=1').then(function (c) {
			var title = t('simulation_title');
			var exam = c.exam;
			var stats = [];
			var best = null;
			if (exam && exam.available) {
				var passedPapers = exam.papers.filter(function (p) { return p.last && p.last.passed; }).length;
				var all = exam.results || exam.history;
				all.forEach(function (h) { if (h.max > 0 && (best === null || h.score / h.max > best.score / best.max)) { best = h; } });
				stats = [[exam.rule.questions, t('exam_stat_questions')], exam.papers.length ? [passedPapers + '/' + exam.papers.length, t('exam_stat_papers')] : null, [all.length, t('exam_stat_attempts')]];
			}
			mount([
				pageHero({ code: c.code, icon: 'timer', crumbs: crumbs(slug, c.name, [[title]]), eyebrow: c.name, title: title, intro: t('hub_exam_intro'), stats: stats, percent: best ? resultPct(best) : null, percentLabel: t('exam_best') }),
				sectionFiles(c, 'exam_simulation'),
				exam ? examSection(slug, exam, c.shop || {}) : lockedBox(t('section_exam'), (c.shop || {}).exam_simulation)
			], title + ' – ' + c.name);
		});
	}

	function viewDownloads(slug) {
		return api('GET', 'courses/' + encodeURIComponent(slug)).then(function (c) {
			mount(detailHeader(slug, c, t('section_downloads')).concat([downloadSection(c.downloads || [], (c.shop || {}).downloads) || el('p', { text: t('hub_downloads_none') })]), t('section_downloads') + ' – ' + c.name);
		});
	}

	function examSection(slug, exam, shop) {
		shop = shop || {};
		var section = el('section', { class: 'bp-section bp-exam-page', id: 'bp-exam', 'aria-labelledby': 'bp-exam-h' });
		var startCard = el('div', { class: 'bp-exam-start' }, [el('h2', { id: 'bp-exam-h' }, [el('span', { class: 'bp-ico bp-ico-timer', 'aria-hidden': 'true' }), t('section_exam')])]);
		section.appendChild(startCard);
		if (!exam.available) {
			startCard.appendChild(el('p', { text: t('exam_unavailable') }));
			return section;
		}
		startCard.appendChild(el('p', { class: 'bp-exam-rule' }, [
			exam.live ? badge(t('exam_live'), 'ok') : badge(t('exam_practice'), 'warn'),
			' ',
			el('span', { text: t('exam_rule_line', { questions: exam.rule.questions, passing: exam.rule.passing }) })
		]));
		startCard.appendChild(el('p', { class: 'bp-exam-start-text', text: t('exam_start_text') }));
		if (!exam.live) {
			startCard.appendChild(el('p', { class: 'bp-exam-start-text', text: t('exam_practice_explain') }));
		}
		if (exam.rule.note) {
			startCard.appendChild(el('p', { class: 'bp-note', text: exam.rule.note }));
		}
		if (exam.simulation_locked) {
			section.appendChild(el('div', { class: 'bp-card bp-locked' }, [el('p', { text: (Number(exam.limit) === 1 ? t('free_limit_paper_one') : t('free_limit_papers', { n: exam.limit })) })].concat(offerNodes(shop.exam_simulation))));
		} else {
			var start = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: exam.running_simulation ? t('exam_resume') : t('exam_start') });
			start.addEventListener('click', function () {
				if (isBusy(start)) { return; }
				setBusy(start, true);
				startExam(slug, null).catch(function (e) { setBusy(start, false); alertMessage(section, e); });
			});
			startCard.appendChild(el('div', { class: 'bp-actions' }, [start]));
		}

		if (exam.papers.length) {
			var passed = exam.papers.filter(function (p) { return p.last && p.last.passed; }).length;
			var grid = el('ul', { class: 'bp-paper-grid' });
			exam.papers.forEach(function (p) {
				var state = p.last ? (p.last.status === 'running' ? 'running' : (p.last.passed ? 'passed' : 'failed')) : 'open';
				if (p.locked) {
					// drip: Module noch nicht freigegeben → Datum statt Shop-Hinweis; trial: kostenloser Teilumfang.
					var drip = p.lock_reason === 'drip';
					grid.appendChild(el('li', {}, [el('span', { class: 'bp-paper bp-paper-locked' + (drip ? ' is-drip' : '') }, [
						el('span', { class: 'bp-paper-lock', 'aria-hidden': 'true' }),
						el('span', { class: 'bp-paper-text' }, [el('strong', { class: 'bp-paper-number', text: t('paper_short', { number: p.number }) }), el('span', { class: 'bp-paper-state', text: drip && p.unlock_at ? t('paper_unlocks', { date: fmtDate(p.unlock_at) }) : t('locked_badge') })])
					])]));
					return;
				}
				var scored = p.last && p.last.status !== 'running' && p.last.max;
				var btn = el('button', { type: 'button', class: 'bp-paper bp-paper-' + state, 'aria-label': t('paper_aria', { number: p.number, state: t('paper_state_' + state) }) + (p.rule ? ' · ' + p.rule : '') }, [
					scored ? miniRing(Math.floor(p.last.score * 100 / p.last.max), p.last.score + '/' + p.last.max) : el('span', { class: 'bp-paper-badge', 'aria-hidden': 'true', text: String(p.number) }),
					el('span', { class: 'bp-paper-text', 'aria-hidden': 'true' }, [
						el('strong', { class: 'bp-paper-number', text: t('paper_short', { number: p.number }) }),
						el('span', { class: 'bp-paper-state', text: t('paper_state_' + state) + (p.questions ? ' · ' + t('paper_questions', { n: p.questions }) : '') }),
						// Kombis: geltende Teilgrenzen und Zeit dieses Bogens
						p.rule ? el('span', { class: 'bp-paper-rule', text: p.rule }) : null
					])
				]);
				btn.addEventListener('click', function () {
					if (isBusy(btn)) { return; }
					setBusy(btn, true);
					startExam(slug, p.id).catch(function (e) { setBusy(btn, false); alertMessage(section, e); });
				});
				grid.appendChild(el('li', {}, [btn]));
			});
			section.appendChild(el('details', { class: 'bp-topic-group bp-paper-group', open: true }, [
				el('summary', {}, [
					el('span', { class: 'bp-topic-group-title' }, [el('strong', { text: t('papers_title') }), el('span', { text: t('papers_meta', { passed: passed, total: exam.papers.length }) })]),
					el('span', { class: 'bp-topic-group-bar', 'aria-hidden': 'true' }, [el('span', { style: 'width:' + Math.floor(passed * 100 / exam.papers.length) + '%' })]),
					el('span', { class: 'bp-topic-chevron', 'aria-hidden': 'true' })
				]),
				el('div', { class: 'bp-topic-group-body' }, [grid])
			]));
		}
		var results = exam.results || exam.history;
		if (results.length) {
			section.appendChild(examResults(slug, results));
		}
		return section;
	}

	// Einheitlich abgerundet (wie die Bestehensgrenze: knapp darunter ist nicht bestanden).
	function resultPct(h) {
		return h.max ? Math.floor(h.score * 100 / h.max) : 0;
	}

	function resultKind(h) {
		return h.paper_number ? t('paper_short', { number: h.paper_number }) : (h.paper_id ? t('paper_label') : t('simulation_label'));
	}

	function resultTime(h) {
		var d = h.submitted_at ? new Date(String(h.submitted_at).replace(' ', 'T') + 'Z').getTime() : NaN;
		return isNaN(d) ? null : d;
	}

	function localDay(ms) {
		var d = new Date(ms);
		return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
	}

	// Ergebnisse mit Filtern (Zeitraum von–bis, einzelner Test): Liste „Letzte Ergebnisse“ + Prüfungsverlauf.
	function examResults(slug, results) {
		// Neueste zuerst nach Abgabezeit (nicht nach ID), damit Liste und Linie chronologisch sind.
		results = results.slice().sort(function (a, b) { return (resultTime(b) || 0) - (resultTime(a) || 0); });
		var days = results.map(resultTime).filter(function (x) { return x !== null; }).map(localDay).sort();
		var minDay = days[0] || '';
		var maxDay = days[days.length - 1] || '';
		var tests = [];
		if (results.some(function (h) { return !h.paper_id; })) { tests.push(['sim', t('filter_test_sim')]); }
		var nums = {};
		results.forEach(function (h) { if (h.paper_id) { nums[h.paper_id] = h.paper_number || 0; } });
		Object.keys(nums).sort(function (a, b) { return nums[a] - nums[b]; }).forEach(function (id) { tests.push(['p' + id, nums[id] ? t('paper_title', { number: nums[id] }) : t('paper_label')]); });

		var from = el('input', { type: 'date', id: 'bp-res-from', min: minDay, max: maxDay, value: minDay });
		var to = el('input', { type: 'date', id: 'bp-res-to', min: minDay, max: maxDay, value: maxDay });
		var test = el('select', { id: 'bp-res-test' }, [el('option', { value: '', text: t('filter_test_all') })].concat(tests.map(function (o) { return el('option', { value: o[0], text: o[1] }); })));
		var reset = el('button', { type: 'button', class: 'bp-link-button', text: t('filter_reset') });
		var status = el('p', { class: 'bp-sr', role: 'status' });
		var body = el('div', { class: 'bp-exam-results' });
		var bar = el('div', { class: 'bp-filter-bar', role: 'group', 'aria-label': t('filter_label') }, [
			el('label', { for: 'bp-res-from' }, [el('span', { text: t('filter_from') }), from]),
			el('label', { for: 'bp-res-to' }, [el('span', { text: t('filter_to') }), to]),
			el('label', { for: 'bp-res-test' }, [el('span', { text: t('filter_test') }), test]),
			reset, status
		]);

		var page = 0;
		var PAGE = 5;

		function render() {
			var f = from.value || minDay;
			var tt = to.value || maxDay;
			if (f > tt) { var tmp = f; f = tt; tt = tmp; from.value = f; to.value = tt; }
			var sel = test.value;
			var list = results.filter(function (h) {
				var ms = resultTime(h);
				var day = ms === null ? null : localDay(ms);
				if (day !== null && (day < f || day > tt)) { return false; }
				if (sel === 'sim') { return !h.paper_id; }
				if (sel) { return 'p' + h.paper_id === sel; }
				return true;
			});
			var testName = sel ? test.options[test.selectedIndex].text : null;
			reset.hidden = from.value === minDay && to.value === maxDay && !sel;
			status.textContent = t('filter_status', { n: list.length });
			if (!list.length) {
				setChildren(body, [el('div', { class: 'bp-empty bp-results-empty' }, [el('p', { text: t('filter_empty') })])]);
				return;
			}
			var pages = Math.ceil(list.length / PAGE);
			page = Math.min(page, pages - 1);
			var ul = el('ul', { class: 'bp-history', id: 'bp-history-list' });
			list.slice(page * PAGE, page * PAGE + PAGE).forEach(function (h) {
				var pct = resultPct(h);
				ul.appendChild(el('li', { class: h.passed ? 'is-passed' : 'is-failed' }, [
					el('span', { class: 'bp-history-ico', 'aria-hidden': 'true' }),
					el('span', { class: 'bp-history-text' }, [
						link(slug + '/pruefung/' + h.session_id + '/', resultKind(h) + ' · ' + h.score + '/' + h.max),
						el('span', { class: 'bp-history-meta', text: (h.submitted_at ? fmtDate(h.submitted_at) + ' · ' : '') + pct + ' %' })
					]),
					badge(h.passed ? t('passed') : t('failed'), h.passed ? 'ok' : 'bad')
				]));
			});
			setChildren(body, [
				el('div', { class: 'bp-history-card' }, [el('h3', { text: t('history_title') }), ul, pages > 1 ? pager(pages, list.length) : null]),
				examChart(slug, list, testName, [new Date(f + 'T00:00:00').getTime(), new Date(tt + 'T00:00:00').getTime() + 86400000])
			]);
		}
		function pager(pages, total) {
			var prev = el('button', { type: 'button', class: 'bp-pager-btn', 'aria-controls': 'bp-history-list', disabled: page === 0 }, ['‹ ', t('pager_prev')]);
			var next = el('button', { type: 'button', class: 'bp-pager-btn', 'aria-controls': 'bp-history-list', disabled: page >= pages - 1 }, [t('pager_next'), ' ›']);
			prev.addEventListener('click', function () { page--; render(); announcePage(); focusPager('prev'); });
			next.addEventListener('click', function () { page++; render(); announcePage(); focusPager('next'); });
			return el('nav', { class: 'bp-pager', 'aria-label': t('pager_label') }, [
				prev,
				el('span', { class: 'bp-pager-info', text: t('pager_range', { from: page * PAGE + 1, to: Math.min(total, page * PAGE + PAGE), total: total }) }),
				next
			]);
		}

		function announcePage() {
			var info = body.querySelector('.bp-pager-info');
			if (info) { status.textContent = info.textContent; }
		}

		function focusPager(which) {
			var btns = body.querySelectorAll('.bp-pager-btn');
			var b = which === 'prev' ? btns[0] : btns[1];
			if (b && b.disabled) { b = which === 'prev' ? btns[1] : btns[0]; }
			if (b) { b.focus(); }
		}

		[from, to, test].forEach(function (c) { c.addEventListener('change', function () { page = 0; render(); }); });
		reset.addEventListener('click', function () { from.value = minDay; to.value = maxDay; test.value = ''; page = 0; render(); from.focus(); });
		render();
		return el('div', { class: 'bp-results-wrap' }, [bar, body]);
	}

	// Verlauf der Prüfungsergebnisse auf einer Zeitachse: x = Abgabezeitpunkt, y = Prozent der Punkte, Punktfarbe = bestanden/nicht bestanden.
	function examChart(slug, history, testName, range) {
		var runs = history.slice().reverse();
		var pcts = runs.map(resultPct);
		var times = runs.map(resultTime);
		var known = times.filter(function (x) { return x !== null; });
		var t0 = known.length ? Math.min.apply(null, known) : 0;
		var t1 = known.length ? Math.max.apply(null, known) : 0;
		// Achse über den gewählten Zeitraum, falls angegeben.
		if (range && range[1] > range[0] && !isNaN(range[0]) && !isNaN(range[1])) {
			t0 = Math.min(t0 || range[0], range[0]);
			t1 = Math.max(t1, range[1]);
		}
		var span = t1 - t0;
		var xs = runs.map(function (h, i) {
			if (span <= 0 || times[i] === null) { return runs.length === 1 ? 50 : 4 + i * 92 / (runs.length - 1); }
			return 4 + (times[i] - t0) * 92 / span;
		});
		var avg = Math.floor(pcts.reduce(function (a, b) { return a + b; }, 0) / pcts.length);
		var best = Math.max.apply(null, pcts);
		var last = pcts[pcts.length - 1];
		var locale = 'de-DE'; // Lernbereich ist deutsch, unabhängig von der Seitensprache
		var DAY = 86400000;
		var xOf = function (ms) { return span > 0 ? 4 + (ms - t0) * 92 / span : 50; };

		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS(ns, 'svg');
		svg.setAttribute('class', 'bp-chart-line');
		svg.setAttribute('viewBox', '0 0 100 100');
		svg.setAttribute('preserveAspectRatio', 'none');
		svg.setAttribute('aria-hidden', 'true');
		if (runs.length > 1) {
			var line = document.createElementNS(ns, 'polyline');
			line.setAttribute('points', xs.map(function (x, i) { return x + ',' + (100 - pcts[i]); }).join(' '));
			line.setAttribute('vector-effect', 'non-scaling-stroke');
			svg.appendChild(line);
		}
		var points = el('ol', { class: 'bp-chart-points' }, runs.map(function (h, i) {
			var label = t('chart_point_aria', { kind: resultKind(h), date: times[i] !== null ? new Date(times[i]).toLocaleDateString(locale) : '', score: h.score, max: h.max, pct: pcts[i], verdict: h.passed ? t('passed') : t('failed') });
			// Nur der letzte Versuch wird beschriftet (bestes Ergebnis steht in den Kennzahlen).
			var showValue = i === runs.length - 1;
			var edge = xs[i] < 35 ? ' is-left' : (xs[i] > 65 ? ' is-right' : '');
			return el('li', { style: 'left:' + xs[i] + '%;bottom:' + pcts[i] + '%' }, [
				el('a', { href: base + slug + '/pruefung/' + h.session_id + '/', 'data-bp-link': '1', tabindex: '-1', class: 'bp-chart-point ' + (h.passed ? 'is-passed' : 'is-failed') + edge, 'aria-label': label, 'data-tip': label }, [el('span', { class: 'bp-chart-dot', 'aria-hidden': 'true' })]),
				showValue ? el('span', { class: 'bp-chart-value', 'aria-hidden': 'true', text: pcts[i] + ' %' }) : null
			]);
		}));
		// Achsenmarken an Tagesgrenzen (höchstens 6), bei nur einem Tag Uhrzeiten; keine doppelten Beschriftungen.
		var ticks = [];
		if (span > 0) {
			var days = Math.round(span / DAY);
			if (days <= 1) {
				for (var hh = 0; hh <= 24; hh += 6) { ticks.push([xOf(t0 + hh * 3600000), new Date(t0 + hh * 3600000).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit', hour12: false })]); }
				ticks[ticks.length - 1][1] = '24:00';
			} else {
				var step = Math.max(1, Math.ceil(days / 5));
				var seen = {};
				for (var d0 = 0; d0 < days; d0 += step) {
					var ms = new Date(t0).setHours(0, 0, 0, 0) + d0 * DAY;
					var label = new Date(ms + 3600000 * 3).toLocaleDateString(locale, { day: '2-digit', month: '2-digit' });
					if (!seen[label] && ms <= t1) { seen[label] = true; ticks.push([xOf(ms), label]); }
				}
			}
		} else if (known.length) {
			ticks.push([50, new Date(t0).toLocaleDateString(locale, { day: '2-digit', month: '2-digit' })]);
		}
		return el('div', { class: 'bp-chart-card' }, [
			el('div', { class: 'bp-chart-head' }, [
				el('h3', { text: testName ? t('chart_title') + ' · ' + testName : t('chart_title') }),
				el('ul', { class: 'bp-chart-kpis' }, [[avg + ' %', t('chart_avg')], [best + ' %', t('chart_best')], [last + ' %', t('chart_last')]].map(function (k2) { return el('li', {}, [el('strong', { text: k2[0] }), el('span', { text: k2[1] })]); }))
			]),
			el('div', { class: 'bp-chart', role: 'group', 'aria-label': t('chart_title') }, [
				el('div', { class: 'bp-chart-grid', 'aria-hidden': 'true' }, ['100 %', '75 %', '50 %', '25 %', '0 %'].map(function (v) { return el('span', { text: v }); })),
				el('div', { class: 'bp-chart-plot' }, [svg, points]),
				el('div', { class: 'bp-chart-time', 'aria-hidden': 'true' }, ticks.map(function (tk) { return el('span', { style: 'left:' + tk[0] + '%', text: tk[1] }); }))
			]),
			el('p', { class: 'bp-chart-foot' }, [
				el('span', { class: 'bp-chart-key is-passed', text: t('passed') }),
				el('span', { class: 'bp-chart-key is-failed', text: t('failed') }),
				el('span', { class: 'bp-muted', text: t('chart_axis') })
			])
		]);
	}

	// Inline-Fehler (role=alert). Nicht in eine polite-Live-Region hängen (sonst doppelte Ansage): dann dahinter.
	// message: Text oder Fehlerobjekt (abgelaufene Sitzung → mit Anmelde-Link).
	function alertMessage(container, message) {
		var error = message && typeof message === 'object' ? message : null;
		var live = container.closest ? container.closest('[aria-live]') : null;
		var host = live && live.parentNode ? live.parentNode : container;
		if (host.bpInlineError && host.bpInlineError.parentNode) {
			host.bpInlineError.remove();
		}
		var old = container.querySelector(':scope > .bp-inline-error');
		if (old) {
			old.remove();
		}
		var node = el('p', { class: 'bp-inline-error', role: 'alert' }, [
			error ? error.message : String(message),
			error && error.code === 'session_expired' ? ' ' : null,
			error && error.code === 'session_expired' ? el('a', { href: loginUrl(), text: t('session_login') }) : null
		]);
		if (live && live.parentNode) {
			host.insertBefore(node, live.nextSibling);
		} else {
			host.appendChild(node);
		}
		host.bpInlineError = node;
		return node;
	}

	function clearAlert(container) {
		var live = container.closest ? container.closest('[aria-live]') : null;
		var host = live && live.parentNode ? live.parentNode : container;
		if (host.bpInlineError && host.bpInlineError.parentNode) {
			host.bpInlineError.remove();
		}
	}

	function startExam(slug, paperId) {
		return api('POST', 'courses/' + encodeURIComponent(slug) + '/exam-sessions', { paper_id: paperId }).then(function (r) {
			navigate(slug + '/pruefung/' + r.session_id + '/');
		});
	}

	// ------------------------------------------------------------------ Lernmodus

	// Lernansicht: Kontext (Kurs, Modul, Thema, Sammlung) für Kopf, Brotkrumen, Fertig-Hinweise.
	var learnCtx = null;

	function viewLearn(slug, moduleId, mode, topic) {
		var collectionId = learnCollection;
		// Unbekannter Modus per Deep-Link (?modus=xyz): wie Smarttrainer.
		if (['smart', 'new', 'wrong', 'favorites'].indexOf(mode) === -1) {
			mode = 'smart';
		}
		return Promise.all([
			api('GET', 'courses/' + encodeURIComponent(slug)),
			collectionId ? api('GET', 'courses/' + encodeURIComponent(slug) + '/saved').catch(function () { return null; }) : null
		]).then(function (res) {
			var course = res[0];
			// Ohne Fragenkatalog-Zugang (Deep-Link): Angebot wie im Smarttrainer statt nur Fehlermeldung.
			if (course.features && course.features.question_bank === false) {
				mount([crumbs(slug, course.name, [[t('mode_smart'), slug + '/smarttrainer/'], [t('section_learn')]]), lockedBox(t('section_learn'), (course.shop || {}).question_bank)], t('section_learn') + ' – ' + course.name);
				return;
			}
			var module = null;
			if (moduleId && course.progress) {
				course.progress.modules.forEach(function (m) { if (String(m.id) === String(moduleId)) { module = m; } });
			}
			// Unbekanntes Thema: im Modul lernen statt leerer „Geschafft“-Meldung.
			if (!module || (topic && !(module.topics || []).some(function (tp) { return tp.name === topic; }))) {
				topic = null;
			}
			var collection = null;
			if (collectionId && res[1]) {
				res[1].collections.forEach(function (c) { if (String(c.id) === String(collectionId)) { collection = c; } });
			}
			var heading = learnExam ? t('learn_exam_heading') : (collection ? collection.name : (topic || (module ? module.name : null) || t('mode_' + mode)));
			var parent = collectionId ? link(slug + '/merkliste/' + (collection ? '?sammlung=' + collection.id : ''), t('saved_title')) : link(slug + '/smarttrainer/', t('mode_smart'));
			var showHeading = heading !== t('mode_smart');
			var container = el('div', { class: 'bp-learn' });
			var progressBox = el('div', { class: 'bp-learn-progress' });
			learnCtx = { slug: slug, course: course, module: module, topic: topic, collection: collection, collectionId: collectionId, repeat: false, progressBox: progressBox };
			renderLearnProgress();
			mount([
				el('nav', { class: 'bp-breadcrumb', 'aria-label': t('breadcrumb') }, [link('', t('overview_title')), ' › ', link(slug + '/', course.name), ' › ', parent, showHeading ? ' › ' : null, showHeading ? el('span', { text: heading }) : null]),
				el('header', { class: 'bp-header bp-learn-head' }, [
					el('div', {}, [el('p', { class: 'bp-learn-course', text: course.name + (module || collection ? ' · ' + t('mode_' + mode) : '') }), el('h1', { text: heading })]),
					course.progress ? progressBox : null
				]),
				container
			], heading + ' – ' + course.name);
			loadQuestion(slug, moduleId, mode, container, topic);
		});
	}

	function renderLearnProgress() {
		var c = learnCtx && learnCtx.course;
		if (!c || !c.progress) {
			return;
		}
		var p = c.progress.total;
		setChildren(learnCtx.progressBox, [el('p', {}, [el('strong', { text: p.percent + ' %' }), ' ' + t('course_hero_mastered')]), progressBar(p, t('progress_short', { mastered: p.mastered, total: p.questions }))]);
	}

	// Nach jeder Antwort Fortschritt im Kopf (und für den Fertig-Hinweis) aktualisieren.
	function refreshLearnProgress(slug) {
		if (!learnCtx || learnCtx.slug !== slug) {
			return;
		}
		var ctx = learnCtx;
		api('GET', 'courses/' + encodeURIComponent(slug)).then(function (c) {
			if (learnCtx !== ctx) {
				return;
			}
			ctx.course = c;
			if (ctx.module) {
				(c.progress ? c.progress.modules : []).forEach(function (m) { if (m.id === ctx.module.id) { ctx.module = m; } });
			}
			renderLearnProgress();
		}).catch(function () { /* Anzeige bleibt beim letzten Stand */ });
	}

	// Ist der gewählte Bereich (Thema/Modul) vollständig gefestigt?
	function learnScopeMastered(mode) {
		if (!learnCtx || learnCtx.repeat || mode !== 'smart' || !learnCtx.module || learnCtx.collectionId) {
			return null;
		}
		var scope = learnCtx.module;
		if (learnCtx.topic) {
			scope = (learnCtx.module.topics || []).filter(function (tp) { return tp.name === learnCtx.topic; })[0] || null;
		}
		return scope && scope.questions > 0 && scope.mastered >= scope.questions ? scope : null;
	}

	function loadQuestion(slug, moduleId, mode, container, topic) {
		var params = new URLSearchParams({ mode: mode });
		if (moduleId) {
			params.set('module', moduleId);
		}
		if (moduleId && topic) {
			params.set('topic', topic);
		}
		if (learnCollection) {
			params.set('collection', learnCollection);
		}
		if (learnExam) {
			params.set('exam', learnExam);
		}
		// Tastatur/Screenreader: kam der Klick aus der Frage („Nächste Frage“), danach auf die neue Frage fokussieren.
		var refocus = container.contains(document.activeElement);
		var focusNew = function () {
			var h = container.querySelector('.bp-question-text, .bp-done h2');
			if (refocus && h) {
				h.setAttribute('tabindex', '-1');
				h.focus({ preventScroll: true });
			}
			// Mobil: neue Frage mit Abstand zum Kopf einscrollen (scroll-margin in app.css).
			if (refocus) {
				bringIntoView(container.querySelector('.bp-question, .bp-done'));
			}
		};
		container.textContent = '';
		var collectionId = learnCtx && learnCtx.collectionId;
		var backLinks = function () {
			return el('p', { class: 'bp-done-actions' }, [
				collectionId ? link(slug + '/merkliste/?sammlung=' + encodeURIComponent(collectionId), t('saved_back'), 'bp-button bp-button-primary') : link(slug + '/smarttrainer/', t('topics_back'), 'bp-button bp-button-primary'),
				' ',
				mode !== 'smart' && !collectionId ? link(moduleId ? topicHref(slug, moduleId, topic) : slug + '/lernen/', t('done_go_smart'), 'bp-button') : null
			]);
		};
		var mastered = learnScopeMastered(mode);
		if (mastered) {
			var again = el('button', { type: 'button', class: 'bp-button', text: t('done_repeat') });
			again.addEventListener('click', function () { learnCtx.repeat = true; loadQuestion(slug, moduleId, mode, container, topic); });
			container.appendChild(el('div', { class: 'bp-card bp-done' }, [
				el('h2', { text: t('done_mastered_title') }),
				el('p', { text: t('done_mastered', { n: mastered.questions }) }),
				el('p', { class: 'bp-done-actions' }, [link(slug + '/smarttrainer/', t('topics_back'), 'bp-button bp-button-primary'), ' ', again])
			]));
			return;
		}
		container.appendChild(el('p', { class: 'bp-loading', text: t('loading') }));
		api('GET', 'courses/' + encodeURIComponent(slug) + '/next-question?' + params.toString()).then(function (r) {
			container.textContent = '';
			if (r.done) {
				container.appendChild(el('div', { class: 'bp-card bp-done' }, [
					el('h2', { text: t('done_title') }),
					el('p', { text: collectionId && mode === 'favorites' ? t('done_collection') : t('done_' + mode) }),
					backLinks()
				]));
				focusNew();
				return;
			}
			container.appendChild(questionCard(slug, moduleId, mode, r.question, container, topic));
			focusNew();
		}).catch(function (e) {
			container.textContent = '';
			container.appendChild(el('div', { class: 'bp-card bp-done' }, [el('p', { class: 'bp-inline-error', role: 'alert', text: e.message }), backLinks()]));
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

	// Popover „In Sammlungen einsortieren“ (Favoriten-Seite).
	function toggleSortPop(host, trigger, opts) {
		var open = host.querySelector('.bp-sort-pop');
		document.querySelectorAll('.bp-sort-pop').forEach(function (p) { if (p.bpClose) { p.bpClose(false); } else { p.remove(); } });
		if (open) {
			return;
		}
		trigger.setAttribute('aria-expanded', 'true');
		var favBox = el('input', { type: 'checkbox' });
		favBox.checked = !!opts.favorite;
		var boxes = opts.all.map(function (col) {
			var cb = el('input', { type: 'checkbox', value: String(col.id) });
			cb.checked = opts.collections.indexOf(col.id) !== -1;
			return [cb, col.name];
		});
		function syncBoxes() {
			boxes.forEach(function (b) {
				b[0].disabled = !favBox.checked;
				if (!favBox.checked) { b[0].checked = false; }
			});
		}
		favBox.addEventListener('change', syncBoxes);
		var pop = el('form', { class: 'bp-sort-pop' }, [
			el('fieldset', {}, [el('legend', { text: t('saved_sort_title') }), el('label', {}, [favBox, ' ' + t('saved_favorites')])].concat(boxes.map(function (b) { return el('label', {}, [b[0], ' ' + b[1]]); }))),
			!opts.all.length ? el('p', { class: 'bp-muted', text: t('saved_sort_none') }) : null,
			el('button', { type: 'submit', class: 'bp-button bp-button-primary', text: t('saved_save') })
		]);
		function outside(ev) {
			if (!pop.contains(ev.target) && !trigger.contains(ev.target)) { close(false); }
		}
		function onKey(ev) {
			if (ev.key === 'Escape') { close(true); }
		}
		function close(refocus) {
			document.removeEventListener('pointerdown', outside, true);
			document.removeEventListener('focusin', outside, true);
			document.removeEventListener('keydown', onKey, true);
			pop.remove();
			trigger.setAttribute('aria-expanded', 'false');
			if (refocus && trigger.isConnected) { trigger.focus(); }
		}
		pop.bpClose = close;
		pop.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var ids = boxes.filter(function (b) { return favBox.checked && b[0].checked; }).map(function (b) { return parseInt(b[0].value, 10); });
			Promise.resolve(opts.onSave(favBox.checked, ids)).then(function () { if (pop.isConnected) { close(true); } }).catch(function (e) { alertMessage(pop, e); });
		});
		syncBoxes();
		host.appendChild(pop);
		document.addEventListener('pointerdown', outside, true);
		document.addEventListener('focusin', outside, true);
		document.addEventListener('keydown', onKey, true);
		favBox.focus();
	}

	// Umschalter mit fester Beschriftung; der Zustand steht in aria-pressed (nicht im Text).
	function favoriteButton(q) {
		var on = !!q.favorite;
		var btn = el('button', { type: 'button', class: 'bp-fav', 'aria-pressed': on ? 'true' : 'false' }, [el('span', { class: 'bp-ico bp-ico-bookmark', 'aria-hidden': 'true' }), el('span', { text: t('fav_label') })]);
		btn.addEventListener('click', function () {
			if (isBusy(btn)) { return; }
			setBusy(btn, true);
			api(on ? 'DELETE' : 'PUT', 'favorites/' + q.question_id).then(function () {
				on = !on;
				q.favorite = on;
				btn.setAttribute('aria-pressed', on ? 'true' : 'false');
				clearAlert(btn.parentNode || btn);
			}).catch(function (e) {
				alertMessage(btn.parentNode || btn, e);
			}).then(function () { setBusy(btn, false); });
		});
		return btn;
	}

	function questionCard(slug, moduleId, mode, q, container, topic) {
		var started = Date.now();
		var key = uuid();
		var name = 'bp-q-' + q.question_id;
		var meta = el('p', { class: 'bp-question-meta' }, [
			q.official_number ? el('span', { class: 'bp-pill bp-pill-strong', text: t('question_number', { number: q.official_number }) }) : null,
			q.topic ? el('span', { class: 'bp-pill', text: q.topic }) : null,
			el('span', { class: 'bp-pill bp-reason', text: q.reason }),
			q.remaining !== null && q.remaining !== undefined ? el('span', { class: 'bp-remaining', text: t(q.remaining === 1 ? 'remaining_one' : 'remaining', { n: q.remaining }) }) : null
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
		next.addEventListener('click', function () { loadQuestion(slug, moduleId, mode, container, topic); });

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
			if (r.correct && r.streak_counted === false) {
				// Richtig, aber zu kurz nach der letzten Antwort: zählt nicht für die Serie.
				feedback.appendChild(el('p', { class: 'bp-muted bp-streak-note', text: t('streak_not_counted') }));
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

		function send(body, fieldset, button, other) {
			if (isBusy(button)) { return null; }
			setBusy(button, true);
			setBusy(other, true);
			body.token = q.token;
			body.idempotency_key = key;
			body.response_ms = Date.now() - started;
			return api('POST', 'answers', body).then(function (r) { showResult(r, fieldset); refreshLearnProgress(slug); }).catch(function (e) {
				setBusy(button, false);
				setBusy(other, false);
				alertMessage(feedback, e);
			});
		}

		if (q.type === 'open_text') {
			var notes = el('textarea', { class: 'bp-notes', rows: '4', 'aria-label': t('own_answer'), placeholder: t('own_answer') });
			var reveal = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: t('reveal_answer') });
			card.appendChild(el('p', { class: 'bp-muted', text: t('open_explain') }));
			card.appendChild(notes);
			reveal.addEventListener('click', function () {
				if (isBusy(reveal)) { return; }
				setBusy(reveal, true);
				api('POST', 'answers/reveal', { token: q.token }).then(function (r) {
					card.insertBefore(el('div', { class: 'bp-model' }, [el('p', { class: 'bp-model-title', text: t('model_answer') }), multiline('p', r.model_answer)]), feedback);
					actions.textContent = '';
					var yes = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: t('known_yes') });
					var no = el('button', { type: 'button', class: 'bp-button', text: t('known_no') });
					yes.addEventListener('click', function () { if (!isBusy(no)) { send({ known: true }, null, yes, no); } });
					no.addEventListener('click', function () { if (!isBusy(yes)) { send({ known: false }, null, no, yes); } });
					actions.appendChild(yes);
					actions.appendChild(no);
					yes.focus();
				}).catch(function (e) { setBusy(reveal, false); alertMessage(feedback, e); });
			});
			actions.appendChild(reveal);
		} else {
			var fieldset = answerList(q, name, null, false);
			// aria-disabled statt disabled: der Knopf bleibt fokussierbar, die Sperre liegt im Handler.
			var check = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: t('check_answer'), 'aria-disabled': 'true' });
			var answeredOnce = false;
			fieldset.addEventListener('change', function () { if (!answeredOnce) { setBusy(check, selectedIds(fieldset).length === 0); } });
			check.addEventListener('click', function () {
				if (isBusy(check) || answeredOnce) { return; }
				var p = send({ answer_ids: selectedIds(fieldset) }, fieldset, check);
				if (p) {
					answeredOnce = true;
					p.then(function () { if (!feedback.className.match(/bp-feedback-(ok|bad)/)) { answeredOnce = false; setBusy(check, selectedIds(fieldset).length === 0); } });
				}
			});
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
				var nodes = [];
				if (g.tabs.length > 1) {
					nodes.push(el('ul', { class: 'bp-chips', 'aria-label': t('guide_tabs') }, g.tabs.map(function (x) {
						return el('li', {}, [link(slug + '/pruefung-anmelden/?teil=' + encodeURIComponent(x.key), x.tab, 'bp-chip' + (x === page ? ' is-active' : ''))]);
					})));
				}
				nodes.unshift(pageHero({ code: c.code, icon: 'flag', crumbs: crumbs(slug, c.name, [[t('guide_title')]]), eyebrow: t('guide_title'), title: page.title, intro: page.intro }));
				var focus = page.variants[0];
				if (focus.note) {
					nodes.push(el('p', { class: 'bp-note', text: focus.note }));
				}
				nodes.push(el('ul', { class: 'bp-stats bp-guide-facts' }, [
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
				nodes.push(el('section', { class: 'bp-card bp-guide-steps' }, [el('h2', { text: t('guide_how') }), steps]));
				nodes.push(el('section', { class: 'bp-card bp-guide-variants' }, [el('h2', { text: t('guide_variants') })].concat(page.variants.map(function (v) {
					return el('div', { class: 'bp-variant' }, [
						el('h3', { text: v.label }),
						el('p', { text: v.scope + (v.minutes ? ' · ' + t('guide_minutes', { n: v.minutes }) : '') }),
						v.pass_text ? el('p', { class: 'bp-muted', text: t('guide_pass', { pass: v.pass_text }) }) : null,
						v.extra ? el('p', { class: 'bp-muted', text: v.extra }) : null,
						v !== focus && v.note ? el('p', { class: 'bp-muted', text: v.note }) : null
					]);
				}))));
				nodes.push(el('section', { class: 'bp-card bp-guide-parts' }, [el('h2', { text: t('guide_parts') }), el('ul', { class: 'bp-module-list' }, page.exam_parts.map(function (p) {
					var practice = p.link !== null && GUIDE_LINKS[p.link] !== undefined ? link(slug + '/' + GUIDE_LINKS[p.link], t('guide_practice'), 'bp-button') : null;
					return el('li', { class: 'bp-card bp-tile' }, [el('h3', { text: p.title }), el('p', { class: 'bp-muted', text: p.text }), practice]);
				}))]));
				nodes.push(committeeSection(page, g.committees));
				nodes.push(el('section', { class: 'bp-card bp-guide-faq' }, [el('h2', { text: t('guide_faq') })].concat(page.faq.map(function (f) {
					return el('details', { class: 'bp-faq' }, [el('summary', { text: f.question }), el('p', { text: f.answer })]);
				}))));
				nodes.push(el('p', { class: 'bp-muted', text: t('guide_disclaimer') }));
				mount(nodes, page.title);
			});
		});
	}

	function committeeSection(page, committees) {
		var list = el('ul', { class: 'bp-committees' });
		var count = el('span', { class: 'bp-committee-count', 'aria-live': 'polite' });
		var input = el('input', { type: 'search', class: 'bp-input bp-committee-search', 'aria-label': t('guide_committee_search'), placeholder: t('guide_committee_search') });
		var selected = null; // ISO-Code des gewählten Bundeslands
		var mapApi = null;
		var stateName = function (code) { return mapApi ? mapApi.name(code) : code; };
		var short = function (label) { return String(label || '').replace(/\s+[–-]\s+(Geschäftsstelle|Postanschrift).*$/u, ''); };
		var suffix = function (label) { var m = String(label || '').match(/\s+[–-]\s+((Geschäftsstelle|Postanschrift).*)$/u); return m ? m[1] : null; };
		function row(c, i) {
			var id = 'bp-committee-' + i;
			var detail = el('div', { class: 'bp-committee-detail', id: id, hidden: true }, [
				c.address ? el('p', { class: 'bp-committee-line' }, [el('span', { class: 'bp-ico bp-ico-flag', 'aria-hidden': 'true' }), c.address]) : null,
				c.contact_person ? el('p', { class: 'bp-committee-line' }, [el('span', { class: 'bp-ico bp-ico-group', 'aria-hidden': 'true' }), c.contact_person]) : null,
				c.phone ? el('p', { class: 'bp-committee-line' }, [el('span', { class: 'bp-ico bp-ico-radio', 'aria-hidden': 'true' }), el('a', { href: 'tel:' + String(c.phone).replace(/[^+\d]/g, ''), text: c.phone }), c.office_hours ? el('span', { class: 'bp-muted', text: ' · ' + c.office_hours }) : null]) : (c.office_hours ? el('p', { class: 'bp-committee-line bp-muted', text: c.office_hours }) : null),
				c.note ? el('p', { class: 'bp-committee-line bp-muted', text: c.note }) : null,
				(c.email || c.website) ? el('p', { class: 'bp-committee-actions' }, [
					c.email ? el('a', { class: 'bp-button', href: 'mailto:' + c.email, text: t('guide_mail') }) : null,
					c.website && /^https?:\/\//i.test(c.website) ? el('a', { class: 'bp-button', href: c.website, target: '_blank', rel: 'noopener', text: t('guide_website') }) : null
				]) : null
			]);
			var toggle = el('button', { type: 'button', class: 'bp-committee-toggle', 'aria-expanded': 'false', 'aria-controls': id }, [el('span', { class: 'bp-sr', text: t('guide_contact_for', { name: short(c.label) }) }), el('span', { 'aria-hidden': 'true', text: t('guide_contact') })]);
			var li = el('li', { class: 'bp-card bp-committee', 'data-states': (c.states || []).join(' ') }, [
				el('div', { class: 'bp-committee-head' }, [
					el('div', { class: 'bp-committee-name' }, [
						el('h3', { text: c.label }),
						el('p', { class: 'bp-muted' }, [[c.postal_code, c.city].filter(Boolean).join(' '), suffix(c.label) ? ' · ' + suffix(c.label) : null])
					]),
					toggle
				]),
				detail
			]);
			li.addEventListener('mouseenter', function () { if (mapApi) { mapApi.hover(c.states || []); } });
			li.addEventListener('mouseleave', function () { if (mapApi) { mapApi.hover([]); } });
			toggle.addEventListener('click', function () {
				var open = toggle.getAttribute('aria-expanded') !== 'true';
				toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
				detail.hidden = !open;
				li.classList.toggle('is-open', open);
			});
			return li;
		}
		function render() {
			var q = input.value.trim().toLowerCase();
			list.textContent = '';
			var hits = committees.filter(function (c) {
				return (!q || [c.label, c.address, c.postal_code, c.city].join(' ').toLowerCase().indexOf(q) !== -1)
					&& (!selected || (c.states || []).indexOf(selected) !== -1);
			});
			hits.forEach(function (c, i) { list.appendChild(row(c, i)); });
			count.textContent = (q || selected) ? t('guide_committee_hits', { n: hits.length, total: committees.length }) : t('guide_committee_all', { n: committees.length });
			stateBar.hidden = !selected;
			stateLabel.textContent = selected ? t('guide_state_selected', { state: stateName(selected), n: hits.length }) : '';
			if (stateSelect.value !== (selected || '')) { stateSelect.value = selected || ''; }
			if (!hits.length) {
				list.appendChild(el('li', { class: 'bp-muted bp-committee-none', text: t('guide_committee_none') }));
			}
		}
		var stateLabel = el('strong');
		var resetState = el('button', { type: 'button', class: 'bp-button', text: t('guide_state_all') });
		var stateBar = el('div', { class: 'bp-state-bar', hidden: true }, [el('span', { class: 'bp-ico bp-ico-flag', 'aria-hidden': 'true' }), stateLabel, resetState]);
		var stateSelect = el('select', { class: 'bp-input bp-state-select', 'aria-label': t('guide_state_choose') }, [el('option', { value: '', text: t('guide_state_choose') })]);
		function choose(code) {
			selected = code || null;
			if (mapApi) { mapApi.select(selected); }
			render();
		}
		resetState.addEventListener('click', function () { choose(null); });
		stateSelect.addEventListener('change', function () { choose(stateSelect.value); });
		var mapBox = el('div', { class: 'bp-committee-map', 'aria-hidden': 'true' });
		input.addEventListener('input', render);
		render();
		// Karte der Bundesländer (lokal, Natural Earth): Klick wählt das Land, Zahl = Ausschüsse laut Name.
		fetch(cfg.maps + 'de-states.json?ver=' + encodeURIComponent(cfg.version), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (geo) {
			var ns = 'http://www.w3.org/2000/svg';
			var svg = document.createElementNS(ns, 'svg');
			svg.setAttribute('viewBox', geo.viewBox);
			svg.setAttribute('class', 'bp-de-map');
			var names = {};
			var shapes = {};
			var counts = {};
			committees.forEach(function (c) { (c.states || []).forEach(function (s2) { counts[s2] = (counts[s2] || 0) + 1; }); });
			var states = geo.states.slice().sort(function (a, b) { return b.d.length - a.d.length; }); // kleine Länder oben
			states.forEach(function (st) {
				names[st.code] = st.name;
				var g = document.createElementNS(ns, 'g');
				g.setAttribute('class', 'bp-de-state' + (counts[st.code] ? ' has-committee' : ''));
				var path = document.createElementNS(ns, 'path');
				path.setAttribute('d', st.d);
				var title = document.createElementNS(ns, 'title');
				title.textContent = st.name + ': ' + t('guide_committee_all', { n: counts[st.code] || 0 });
				g.appendChild(title);
				g.appendChild(path);
				g.addEventListener('click', function () { choose(selected === st.code ? null : st.code); });
				svg.appendChild(g);
				shapes[st.code] = g;
				stateSelect.appendChild(el('option', { value: st.code, text: st.name + ' (' + (counts[st.code] || 0) + ')' }));
			});
			// Zahlen-Marker zuletzt, damit sie über allen Flächen liegen
			states.forEach(function (st) {
				if (!counts[st.code] || ['DE-BE', 'DE-HB', 'DE-HH'].indexOf(st.code) !== -1) { return; }
				var m = document.createElementNS(ns, 'g');
				m.setAttribute('class', 'bp-de-count');
				m.setAttribute('transform', 'translate(' + st.cx + ' ' + st.cy + ')');
				var circle = document.createElementNS(ns, 'circle');
				circle.setAttribute('r', '15');
				var text = document.createElementNS(ns, 'text');
				text.setAttribute('text-anchor', 'middle');
				text.setAttribute('dy', '5');
				text.textContent = String(counts[st.code]);
				m.appendChild(circle);
				m.appendChild(text);
				m.addEventListener('click', function () { choose(selected === st.code ? null : st.code); });
				svg.appendChild(m);
			});
			var opts = Array.prototype.slice.call(stateSelect.options, 1).sort(function (a, b) { return a.text.localeCompare(b.text, 'de'); });
			opts.forEach(function (o) { stateSelect.appendChild(o); });
			mapBox.appendChild(svg);
			mapBox.appendChild(el('p', { class: 'bp-map-legend' }, [el('span', { class: 'bp-map-swatch' }), t('guide_map_legend')]));
			mapApi = {
				name: function (code) { return names[code] || code; },
				select: function (code) { Object.keys(shapes).forEach(function (k) { shapes[k].classList.toggle('is-selected', k === code); }); },
				hover: function (codes) { Object.keys(shapes).forEach(function (k) { shapes[k].classList.toggle('is-hover', codes.indexOf(k) !== -1); }); }
			};
			render();
		}).catch(function () { mapBox.hidden = true; });
		return el('section', { class: 'bp-card bp-guide-book' }, [
			el('h2', { text: t('guide_book') }),
			el('p', { class: 'bp-muted', text: t('guide_book_text') }),
			el('ul', { class: 'bp-providers' }, page.providers.map(function (p) {
				return el('li', { class: 'bp-card' }, [el('strong', { text: p.name }), el('span', { class: 'bp-muted', text: ' ' + p.full }), el('p', { text: p.text }), el('a', { href: p.url, target: '_blank', rel: 'noopener', class: 'bp-button', text: t('guide_to_provider', { name: p.name }) })]);
			})),
			el('div', { class: 'bp-committee-bar' }, [el('h3', { text: t('guide_committees', { n: committees.length }) }), el('div', { class: 'bp-committee-filter' }, [stateSelect, input, count])]),
			el('p', { class: 'bp-muted bp-committee-incomplete', text: t('guide_committee_incomplete') }),
			el('div', { class: 'bp-committee-layout' }, [
				el('div', { class: 'bp-committee-mapcol' }, [mapBox, el('p', { class: 'bp-muted bp-map-note', text: t('guide_map_note') })]),
				el('div', { class: 'bp-committee-listcol' }, [stateBar, list])
			])
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
				var meta = (d.size ? ' · ' + fileSize(d.size) : '');
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
		return el('span', { class: 'bp-paper bp-paper-locked', title: t('locked_free') }, [el('span', { class: 'bp-paper-number', text: String(number) }), el('span', { class: 'bp-paper-state', text: t('locked_badge') }), el('span', { class: 'bp-sr', text: ' – ' + t('locked_free') })]);
	}

	function fmtDate(utc) {
		if (!utc) {
			return '';
		}
		var d = new Date(String(utc).replace(' ', 'T') + 'Z');
		return isNaN(d.getTime()) ? String(utc) : d.toLocaleDateString('de-DE');
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

	// PDFs des Betreibers zu einem Lernbereich (Mediathek: „Kurs · Funktion“). Laden nur mit vollem Zugang.
	function sectionFiles(c, feature, slot) {
		var files = ((c.files || {})[feature] || []).filter(function (f) { return !slot || f.slot === slot; });
		if (!files.length) {
			return null;
		}
		var locked = files.some(function (f) { return f.locked; });
		var list = el('ul', { class: 'bp-pdf-list' }, files.map(function (f) {
			var inner = [
				el('span', { class: 'bp-pdf-ico', 'aria-hidden': 'true', text: 'PDF' }),
				el('span', { class: 'bp-pdf-text' }, [
					el('strong', { text: f.title }),
					el('span', { text: (f.description || t('pdf_desc')) + (f.size ? ' · ' + fileSize(f.size) : '') })
				]),
				f.locked ? el('span', { class: 'bp-pro-badge', text: t('pdf_pro') }) : el('span', { class: 'bp-pdf-action', 'aria-hidden': 'true', text: t('pdf_open') + ' ↓' })
			];
			return el('li', {}, [f.locked
				? el('div', { class: 'bp-pdf is-locked' }, inner.concat([el('span', { class: 'bp-sr', text: t('pdf_locked_sr') })]))
				: el('a', { class: 'bp-pdf', href: f.url, target: '_blank', rel: 'noopener' }, inner.concat([el('span', { class: 'bp-sr', text: t('pdf_open_sr') })]))]);
		}));
		return el('section', { class: 'bp-pdf-section', 'aria-label': t('pdf_section') }, [
			list,
			locked ? el('div', { class: 'bp-pdf-note' }, [el('p', { text: t('pdf_locked_note') })].concat(offerNodes((c.shop || {})[feature]))) : null
		]);
	}

	function fileSize(bytes) {
		return bytes >= 1048576 ? (Math.round(bytes / 104857.6) / 10).toString().replace('.', ',') + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
	}

	function withCourse(slug, fn) {
		return api('GET', 'courses/' + encodeURIComponent(slug)).then(fn);
	}

	// Navigation
	function viewNavigation(slug) {
		return withCourse(slug, function (c) {
			return api('GET', 'courses/' + encodeURIComponent(slug) + '/navigation').then(function (tasks) {
				var list = el('ol', { class: 'bp-module-list bp-nav-list' }, tasks.map(function (task) {
					var head = function (state) {
						return el('div', { class: 'bp-nav-card-head' }, [el('span', { class: 'bp-nav-no', 'aria-hidden': 'true', text: String(task.number) }), el('span', { class: 'bp-state bp-state-' + state, text: t('nav_state_' + state) })]);
					};
					if (task.locked) {
						return el('li', { class: 'bp-card bp-nav-card bp-locked' }, [head('locked'), el('h2', { text: t('nav_task_title', { number: task.number }) }), el('p', { class: 'bp-muted', text: t('locked_free') })]);
					}
					var state = task.done >= task.questions && task.questions > 0 ? 'done' : (task.done > 0 ? 'started' : 'open');
					return el('li', { class: 'bp-card bp-nav-card is-' + state }, [
						head(state),
						el('h2', {}, [link(slug + '/navigation/' + task.id + '/', t('nav_task_title', { number: task.number }))]),
						progressBar({ questions: task.questions, mastered: task.done, learning: 0, unsure: 0, percent: Math.floor(task.done * 100 / Math.max(task.questions, 1)) }, t('nav_progress', { done: task.done, total: task.questions }))
					]);
				}));
				var hasLocked = tasks.some(function (x) { return x.locked; });
				var qDone = tasks.reduce(function (a, x) { return a + (x.done || 0); }, 0);
				var qAll = tasks.reduce(function (a, x) { return a + (x.questions || 0); }, 0);
				var finished = tasks.filter(function (x) { return !x.locked && x.questions > 0 && x.done >= x.questions; }).length;
				mount([
					pageHero({ code: c.code, icon: 'compass', crumbs: crumbs(slug, c.name, [[t('trainer_navigation')]]), eyebrow: c.name, title: t('trainer_navigation'), intro: t('nav_intro'), stats: [[tasks.length, t('nav_stat_tasks')], [finished, t('nav_stat_finished')], [qDone + '/' + qAll, t('nav_stat_parts')]], percent: Math.floor(qDone * 100 / Math.max(qAll, 1)), percentLabel: t('nav_stat_done') }),
					sectionFiles(c, 'navigation_trainer'), chartNote(c), hasLocked ? lockedNote((c.shop || {}).navigation_trainer) : null, list
				], t('trainer_navigation') + ' – ' + c.name);
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
				var hero = null;
				var heroStats = function () {
					var n = task.questions.filter(function (q) { return q.done; }).length;
					return { stats: [[task.questions.length, t('nav_stat_parts')], [n, t('nav_stat_done')]], percent: Math.floor(n * 100 / Math.max(task.questions.length, 1)) };
				};
				// Zähler im Kopf sofort nachziehen, wenn eine Teilaufgabe erledigt ist.
				var score = el('div', { class: 'bp-card bp-nav-score', 'aria-live': 'polite' });
				var onDone = function () { var h = heroStats(); updateHero(hero, h.stats, h.percent, t('nav_stat_done')); navigationScore(score, task); };
				navigationScore(score, task);
				task.questions.forEach(function (q) { items.appendChild(navigationQuestion(slug, q, onDone)); });
				var title = t('nav_task_title', { number: task.number });
				var hs = heroStats();
				hero = pageHero({ code: c.code, icon: 'compass', crumbs: crumbs(slug, c.name, [[t('trainer_navigation'), slug + '/navigation/'], [title]]), eyebrow: t('trainer_navigation'), title: title, stats: hs.stats, percent: hs.percent, percentLabel: t('nav_stat_done') });
				// Nachbaraufgaben aus dem Vollzugang: kein Link ins Leere, sondern Hinweis.
				var prevNode = task.previous && !task.previous_locked ? link(slug + '/navigation/' + task.previous + '/', '← ' + t('nav_previous'), 'bp-button') : el('span');
				var nextNode = task.next && !task.next_locked ? link(slug + '/navigation/' + task.next + '/', t('nav_next') + ' →', 'bp-button bp-button-primary') : link(slug + '/navigation/', t('nav_all'), 'bp-button');
				var lockedNav = (task.next && task.next_locked) || (task.previous && task.previous_locked) ? el('p', { class: 'bp-muted bp-nav-locked-note', text: task.next && task.next_locked ? t('nav_next_locked') : t('nav_prev_locked') }) : null;
				mount([
					hero,
					el('div', { class: 'bp-nav-layout' }, [
						el('aside', { class: 'bp-nav-scenario' }, [
							el('div', { class: 'bp-card bp-nav-scenario-card' }, [el('h2', { class: 'bp-learn-course bp-nav-scenario-h', text: t('nav_scenario') }), multiline('p', task.scenario, 'bp-nav-scenario-text')]),
							task.hint ? el('p', { class: 'bp-note', text: task.hint }) : null,
							chartNote(c)
						]),
						el('div', { class: 'bp-nav-questions' }, [
							items,
							score,
							el('div', { class: 'bp-actions bp-nav-pager' }, [prevNode, nextNode]),
							lockedNav,
							task.source ? el('p', { class: 'bp-muted bp-source', text: t('source', { source: task.source }) }) : null
						])
					])
				], title + ' – ' + c.name);
			});
		});
	}

	// Punktestand der Navigationsaufgabe nach Selbsteinschätzung (wie TrainerService::navigationScore): richtig = 1 Punkt.
	function navigationScore(box, task) {
		var max = task.questions.length;
		var points = task.questions.filter(function (q) { return q.self_assessment === 'correct'; }).length;
		var open = task.questions.filter(function (q) { return !q.self_assessment; }).length;
		var pass = (task.score && task.score.pass_points) || null;
		box.textContent = '';
		box.appendChild(el('p', {}, [el('strong', { text: t('nav_score', { points: points, max: max }) })]));
		if (pass) {
			box.appendChild(el('p', { class: 'bp-muted', text: t('nav_score_rule', { pass: pass, max: max }) }));
		}
		if (open > 0 || pass) {
			box.appendChild(el('p', { text: open > 0 ? t('nav_score_open', { n: open }) : t(points >= pass ? 'nav_score_passed' : 'nav_score_failed') }));
		}
	}

	// Automatische Prüfung der eigenen Werte (nur Hilfe): Ergebnis je Wert als Text, nicht nur über die Farbe.
	function navigationCheckNumber(n) {
		return String(Math.round(Math.abs(n) * 100) / 100).replace('.', ',');
	}

	function navigationCheckBlock(check) {
		var list = el('ul', { class: 'bp-nav-check-list' }, check.values.map(function (v) {
			var tolerance = navigationCheckNumber(v.tolerance) + (v.tolerance_unit === '°' || v.tolerance_unit === "'" ? '' : ' ') + v.tolerance_unit;
			var vars = { label: v.label, given: v.given, expected: v.expected || '–', tolerance: tolerance, deviation: v.deviation === null ? '' : navigationCheckNumber(v.deviation) + (v.tolerance_unit === '°' || v.tolerance_unit === "'" ? '' : ' ') + v.tolerance_unit };
			var key = v.given === null ? 'nav_check_missing' : (v.ok ? 'nav_check_ok' : 'nav_check_off');
			return el('li', { class: v.ok ? 'is-ok' : 'is-off' }, [
				el('span', { class: 'bp-nav-check-mark', text: v.ok ? t('nav_check_mark_ok') : t('nav_check_mark_off') }),
				el('span', { text: t(key, vars) })
			]);
		}));
		return el('div', { class: 'bp-nav-check is-' + check.status }, [
			el('p', { class: 'bp-nav-check-title', text: t('nav_check_title') }),
			el('p', { class: 'bp-nav-check-summary', text: check.message }),
			list,
			el('p', { class: 'bp-muted bp-nav-check-hint', text: t('nav_check_hint') })
		]);
	}

	function navigationQuestion(slug, q, onDone) {
		var item = el('li', { class: 'bp-card bp-nav-question' + (q.done ? ' is-correct' : '') });
		// Bleibt über Neuaufbauten bestehen, damit Screenreader das Prüfergebnis ansagen (aria-live).
		var checkLive = el('div', { class: 'bp-nav-check-live', 'aria-live': 'polite' });
		function render(focusKey) {
			item.textContent = '';
			item.appendChild(el('p', { class: 'bp-question-meta' }, [el('span', { class: 'bp-pill bp-pill-strong', text: t('nav_question', { number: q.number }) }), q.self_assessment ? badge(t('assess_' + q.self_assessment), q.self_assessment === 'correct' ? 'ok' : (q.self_assessment === 'wrong' ? 'bad' : 'warn')) : null]));
			item.appendChild(multiline('h3', q.text, 'bp-question-text'));
			var area = el('textarea', { class: 'bp-notes', rows: '2', 'aria-label': t('nav_own_solution') + ' – ' + t('nav_question', { number: q.number }), placeholder: t('nav_own_solution') });
			area.value = q.my_answer || '';
			item.appendChild(area);
			checkLive.textContent = '';
			if (q.answer === null) {
				if (q.auto_check) {
					item.appendChild(el('p', { class: 'bp-muted bp-nav-check-available', text: t('nav_check_available') }));
				}
				var reveal = el('button', { type: 'button', class: 'bp-button', text: t('nav_show_solution') });
				reveal.addEventListener('click', function () {
					if (isBusy(reveal)) { return; }
					setBusy(reveal, true);
					api('POST', 'courses/' + encodeURIComponent(slug) + '/navigation-questions/' + q.id, { answer: area.value || null }).then(function (r) {
						q.answer = r.answer;
						q.my_answer = r.my_answer !== undefined ? r.my_answer : area.value;
						q.check = r.check || null;
						render('official');
					}).catch(function (e) { setBusy(reveal, false); alertMessage(item, e); });
				});
				item.appendChild(el('div', { class: 'bp-actions' }, [reveal]));
				return;
			}
			var official = el('div', { class: 'bp-model', tabindex: '-1' }, [el('p', { class: 'bp-model-title', text: t('nav_official') }), multiline('p', q.answer), q.chart_entry ? el('p', { class: 'bp-muted bp-nav-chart-entry', text: t('nav_chart_entry') }) : null]);
			item.appendChild(official);
			// Prüfergebnis erst nach dem Einhängen füllen, damit die Live-Region die Änderung meldet.
			item.appendChild(checkLive);
			if (q.check) {
				checkLive.appendChild(navigationCheckBlock(q.check));
			}
			var group = el('div', { class: 'bp-actions', role: 'group', 'aria-label': t('self_assessment') });
			var buttons = [];
			['correct', 'partly', 'wrong'].forEach(function (a) {
				var b = el('button', { type: 'button', class: 'bp-button' + (q.self_assessment === a ? ' bp-button-primary' : ''), 'aria-pressed': q.self_assessment === a ? 'true' : 'false', 'data-assess': a, text: t('assess_' + a) });
				b.addEventListener('click', function () {
					if (isBusy(b)) { return; }
					buttons.forEach(function (x) { setBusy(x, true); });
					api('POST', 'courses/' + encodeURIComponent(slug) + '/navigation-questions/' + q.id, { answer: area.value || null, self_assessment: a }).then(function (r) {
						q.self_assessment = a;
						q.done = true;
						q.my_answer = r && r.my_answer !== undefined ? r.my_answer : area.value;
						if (r && r.check !== undefined) { q.check = r.check; }
						item.classList.add('is-correct');
						render('assess-' + a);
						if (onDone) { onDone(); }
					}).catch(function (e) { buttons.forEach(function (x) { setBusy(x, false); }); alertMessage(item, e); });
				});
				buttons.push(b);
				group.appendChild(b);
			});
			item.appendChild(group);
			// Nach dem Neuaufbau den Fokus an die passende Stelle (nicht auf <body>).
			if (focusKey === 'official') {
				official.focus();
			} else if (focusKey && focusKey.indexOf('assess-') === 0) {
				var target = group.querySelector('[data-assess="' + focusKey.slice(7) + '"]');
				if (target) { target.focus(); }
			}
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
				var chipLinks = [];
				var nav = el('ul', { class: 'bp-chips', 'aria-label': t('praxis_categories') }, [el('li', {}, [link(slug + '/praxis/', t('praxis_all'), 'bp-chip' + (active ? '' : ' is-active'))])].concat(cats.map(function (x) {
					var a = link(slug + '/praxis/?kategorie=' + encodeURIComponent(x.name), x.name + ' (' + x.known + '/' + x.cards.length + ')', 'bp-chip' + (active === x ? ' is-active' : ''));
					chipLinks.push([a, x]);
					return el('li', {}, [a]);
				})));
				var hero = null;
				// Nach einer Selbsteinschätzung Zähler (Kopf, Kategorien) sofort aktualisieren.
				function refreshCounts() {
					var total = 0;
					cats.forEach(function (x) { x.known = x.cards.filter(function (k) { return k.status === 'known'; }).length; total += x.known; });
					data.known = total;
					chipLinks.forEach(function (pair) { pair[0].textContent = pair[1].name + ' (' + pair[1].known + '/' + pair[1].cards.length + ')'; });
					var k = active ? active.known : data.known;
					var n = active ? active.cards.length : data.total;
					updateHero(hero, [[n, t('praxis_stat_cards')], [k, t('praxis_stat_known')], [cats.length, t('praxis_stat_categories')]], Math.floor(k * 100 / Math.max(n, 1)), t('praxis_stat_known'));
				}
				var allCards = active ? active.cards : [].concat.apply([], cats.map(function (x) { return x.cards; }));
				var cards = allCards.filter(function (x) { return !x.locked; });
				var lockedCount = allCards.length - cards.length;
				var stage = el('div', { class: 'bp-praxis-stage' });
				var onlyOpen = el('input', { type: 'checkbox', id: 'bp-only-open' });
				var index = 0;
				function pool() {
					return onlyOpen.checked ? cards.filter(function (x) { return x.status !== 'known'; }) : cards;
				}
				function show(focus) {
					var list = pool();
					stage.textContent = '';
					if (!list.length) {
						stage.appendChild(el('div', { class: 'bp-card bp-done' }, [el('h2', { text: t('done_title'), tabindex: '-1' }), el('p', { text: t('praxis_all_known') })]));
						if (focus) { stage.querySelector('h2').focus(); }
						return;
					}
					index = index % list.length;
					var card = list[index];
					var box = el('article', { class: 'bp-card bp-question bp-flashcard' }, [
						el('p', { class: 'bp-question-meta' }, [el('span', { class: 'bp-pill bp-pill-strong', text: t('praxis_card_of', { n: index + 1, total: list.length }) }), card.reference ? el('span', { text: card.reference }) : null, card.status ? badge(t('praxis_status_' + card.status), card.status === 'known' ? 'ok' : 'warn') : null]),
						card.title ? el('h2', { text: card.title }) : null,
						card.image ? el('div', { class: 'bp-media' }, [el('img', { src: card.image.url, alt: card.image.alt || '', loading: 'lazy' })]) : null,
						multiline('p', card.question, 'bp-question-text')
					]);
					var actions = el('div', { class: 'bp-actions' });
					var reveal = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: t('praxis_show_answer') });
					reveal.addEventListener('click', function () {
						reveal.remove();
						box.insertBefore(el('div', { class: 'bp-model' }, [el('p', { class: 'bp-model-title', text: t('model_answer') }), multiline('p', card.answer), card.explanation ? multiline('p', card.explanation, 'bp-explanation') : null]), actions);
						var choice = [];
						[['known', t('praxis_known')], ['practice', t('praxis_practice')]].forEach(function (opt) {
							var b = el('button', { type: 'button', class: 'bp-button' + (opt[0] === 'known' ? ' bp-button-primary' : ''), text: opt[1] });
							b.addEventListener('click', function () {
								if (isBusy(b)) { return; }
								// Bis zur Antwort sperren (kein Doppelklick, keine doppelte Anfrage).
								choice.forEach(function (x) { setBusy(x, true); });
								api('PUT', 'courses/' + encodeURIComponent(slug) + '/praxis/' + card.id, { status: opt[0] }).then(function () {
									card.status = opt[0];
									refreshCounts();
									if (!(onlyOpen.checked && opt[0] === 'known')) {
										index++;
									}
									show(true);
								}).catch(function (e) {
									choice.forEach(function (x) { setBusy(x, false); });
									alertMessage(actions, e);
								});
							});
							choice.push(b);
							actions.appendChild(b);
						});
						choice[0].focus();
					});
					actions.appendChild(reveal);
					box.appendChild(actions);
					if (card.source) {
						box.appendChild(el('p', { class: 'bp-muted bp-source', text: t('source', { source: card.source }) }));
					}
					stage.appendChild(box);
					if (focus) {
						reveal.focus({ preventScroll: true });
						bringIntoView(box);
					}
				}
				onlyOpen.addEventListener('change', function () { index = 0; show(); });
				var scopeKnown = active ? active.known : data.known;
				var scopeTotal = active ? active.cards.length : data.total;
				hero = pageHero({ code: c.code, icon: 'cards', crumbs: crumbs(slug, c.name, active ? [[t('trainer_praxis'), slug + '/praxis/'], [active.name]] : [[t('trainer_praxis')]]), eyebrow: active ? t('trainer_praxis') : c.name, title: active ? active.name : t('trainer_praxis'), intro: t('praxis_intro'), stats: [[scopeTotal, t('praxis_stat_cards')], [scopeKnown, t('praxis_stat_known')], [cats.length, t('praxis_stat_categories')]], percent: Math.floor(scopeKnown * 100 / Math.max(scopeTotal, 1)), percentLabel: t('praxis_stat_known') });
				mount([
					hero,
					active ? null : sectionFiles(c, 'praxis_trainer'),
					lockedCount ? lockedNote((c.shop || {}).praxis_trainer, t('locked_more', { n: lockedCount })) : null,
					el('div', { class: 'bp-praxis-toolbar' }, [nav, el('p', { class: 'bp-switch' }, [onlyOpen, el('label', { for: 'bp-only-open', text: t('praxis_only_open') })])]),
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
		// Platzhalter-Lektionen: keine Video-URL vom Server, kein Player – nur ein Hinweis (Quiz/Schritte bleiben nutzbar).
		var video = (!l.placeholder && l.video_url) ? el('video', { class: 'bp-video', controls: true, preload: 'metadata', src: l.video_url }) : null;
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
			if (isBusy(doneBtn)) { return; }
			setBusy(doneBtn, true);
			saveProgress(!l.completed).then(function (r) {
				setDone(!!r.completed);
				clearAlert(doneBtn.parentNode);
			}).catch(function (e) { alertMessage(doneBtn.parentNode, e); }).then(function () { setBusy(doneBtn, false); });
		});
		if (video) {
			video.addEventListener('loadedmetadata', function () {
				if (l.position > 0 && l.position < (video.duration || 0) - 2) {
					video.currentTime = l.position;
				}
			});
			video.addEventListener('pause', function () { saveProgress(null).catch(function () { /* nächster Versuch beim Takt */ }); });
			video.addEventListener('ended', function () {
				saveProgress(true).then(function () { setDone(true); }).catch(function (e) { alertMessage(doneBtn.parentNode, e); });
			});
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
				quiz.scrollIntoView({ behavior: scrollBehavior(), block: 'start' });
				quiz.focus({ preventScroll: true });
			});
		} else if (l.next && !l.next_locked) {
			// Hauptknopf „Nächste Lektion“; der Pager unten verlinkt dann nur „zurück“ (kein doppelter Knopf).
			cta = el('a', { href: base + slug + '/video/' + l.next + '/', class: 'bp-button bp-button-primary bp-vc-cta', 'data-bp-link': '1' }, [t('video_next'), el('span', { class: 'bp-vc-arrow', 'aria-hidden': 'true', text: '→' })]);
		}
		var ctaIsNext = !!cta && !l.quiz.length;
		var main = el('div', { class: 'bp-vc-main' }, [
			el('p', { class: 'bp-vc-module', text: l.module }),
			el('h1', { class: 'bp-vc-title', text: l.title }),
			video ? el('div', { class: 'bp-video-wrap' }, [video, poster]) : el('p', { class: l.placeholder ? 'bp-note bp-vc-pending' : 'bp-vc-missing', text: t(l.placeholder ? 'video_placeholder' : 'video_missing') }),
			el('div', { class: 'bp-vc-bar-row' }, [el('div', { class: 'bp-vc-state' }, [status, doneBtn]), cta]),
			l.steps.length ? el('section', { class: 'bp-section' }, [el('h2', { text: t('video_steps') }), el('ol', { class: 'bp-steps' }, l.steps.map(function (st) { return el('li', { text: st.title }); }))]) : null,
			quiz,
			(l.previous || l.next) ? el('nav', { class: 'bp-vc-pager', 'aria-label': t('video_chapters') }, [
				l.previous ? link(slug + '/video/' + l.previous + '/', '← ' + t('prev'), 'bp-button') : el('span'),
				l.next && l.next_locked ? el('span', { class: 'bp-muted bp-vc-next-locked', text: t('video_next_locked') })
					: (l.next && !ctaIsNext ? link(slug + '/video/' + l.next + '/', t('video_next') + ' →', 'bp-button') : null)
			]) : null
		]);
		layout = videoLayout(slug, c, v, l.id, main);
		mount([crumbs(slug, c.name, [[t('trainer_video'), slug + '/video/'], [l.title]]), layout], l.title + ' – ' + c.name);
		if (video) {
			// Erst nach mount() anlegen: mount() räumt Timer und Aufräumfunktionen der Vorseite ab.
			timers.push(setInterval(function () { if (!video.paused) { saveProgress(null).catch(function () { /* nächster Takt */ }); } }, 15000));
			// Beim Verlassen/Verstecken der Seite Position sichern (keepalive überlebt das Entladen).
			var lastSent = -1;
			var keepPosition = function () {
				var pos = Math.floor(video.currentTime || 0);
				if (pos <= 0 || pos === lastSent) {
					return;
				}
				lastSent = pos;
				try {
					fetch(cfg.root + path, { method: 'PUT', keepalive: true, credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ position: pos, completed: null }) }).catch(function () { /* beim Entladen egal */ });
				} catch (e) { /* ohne fetch/keepalive */ }
			};
			var onVisibility = function () { if (document.visibilityState === 'hidden') { keepPosition(); } };
			document.addEventListener('visibilitychange', onVisibility);
			window.addEventListener('pagehide', keepPosition);
			cleanups.push(function () {
				document.removeEventListener('visibilitychange', onVisibility);
				window.removeEventListener('pagehide', keepPosition);
				// Wechsel innerhalb der App: Entfernen aus dem DOM pausiert das Video, der pause-Handler speichert.
			});
		}
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
			// Der geklickte Knopf ist weg: Fokus auf die Rückmeldung (nicht auf <body>).
			feedback.setAttribute('tabindex', '-1');
			feedback.focus({ preventScroll: true });
			bringIntoView(feedback);
		}
		function send(body, fieldset, button, other) {
			if (isBusy(button)) { return; }
			setBusy(button, true);
			setBusy(other, true);
			body.token = q.token;
			body.idempotency_key = key;
			body.response_ms = Date.now() - started;
			api('POST', 'answers', body).then(function (r) { result(r, fieldset); }).catch(function (e) { setBusy(button, false); setBusy(other, false); alertMessage(feedback, e); });
		}
		if (q.type === 'open_text') {
			var reveal = el('button', { type: 'button', class: 'bp-button', text: t('reveal_answer') });
			reveal.addEventListener('click', function () {
				if (isBusy(reveal)) { return; }
				setBusy(reveal, true);
				api('POST', 'answers/reveal', { token: q.token }).then(function (r) {
					card.insertBefore(el('div', { class: 'bp-model' }, [el('p', { class: 'bp-model-title', text: t('model_answer') }), multiline('p', r.model_answer)]), actions);
					actions.textContent = '';
					var yes = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: t('known_yes') });
					var no = el('button', { type: 'button', class: 'bp-button', text: t('known_no') });
					yes.addEventListener('click', function () { if (!isBusy(no)) { send({ known: true }, null, yes, no); } });
					no.addEventListener('click', function () { if (!isBusy(yes)) { send({ known: false }, null, no, yes); } });
					actions.appendChild(yes);
					actions.appendChild(no);
					yes.focus(); // „Gewusst“
				}).catch(function (e) { setBusy(reveal, false); alertMessage(feedback, e); });
			});
			actions.appendChild(reveal);
		} else {
			var fieldset = answerList(q, name, null, false);
			var check = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: t('check_answer'), 'aria-disabled': 'true' });
			var pending = false;
			fieldset.addEventListener('change', function () { if (!pending) { setBusy(check, selectedIds(fieldset).length === 0); } });
			check.addEventListener('click', function () {
				if (isBusy(check) || pending) { return; }
				pending = true;
				setBusy(check, true);
				var body = { answer_ids: selectedIds(fieldset), token: q.token, idempotency_key: key, response_ms: Date.now() - started };
				api('POST', 'answers', body).then(function (r) { result(r, fieldset); }).catch(function (e) { pending = false; setBusy(check, selectedIds(fieldset).length === 0); alertMessage(feedback, e); });
			});
			card.appendChild(fieldset);
			actions.appendChild(check);
		}
		card.appendChild(actions);
		card.appendChild(feedback);
		return card;
	}

	// Funk: Diktat/Text vorlesen (sichtbarer Knopf, Text nicht im Label – sonst verrät der Screenreader die Lösung)
	function speakTextButton(text, lang) {
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
				var textsAll = o.texts.reduce(function (a, g) { return a + g.items.length; }, 0);
				var textsDone = o.texts.reduce(function (a, g) { return a + g.items.filter(function (i) { return i.done; }).length; }, 0);
				var vocAll = o.vocabulary.reduce(function (a, v) { return a + v.total; }, 0);
				var vocDone = o.vocabulary.reduce(function (a, v) { return a + v.learned; }, 0);
				var pracAll = (o.practice || []).reduce(function (a, g) { return a + g.tasks.length; }, 0);
				var nodes = [pageHero({ code: c.code, icon: 'radio', crumbs: crumbs(slug, c.name, [[t('trainer_radio')]]), eyebrow: c.name, title: t('trainer_radio'), intro: t('radio_intro'), stats: [pracAll ? [pracAll, t('radio_stat_practice')] : null, [textsDone + '/' + textsAll, t('radio_stat_texts')], [vocDone + '/' + vocAll, t('radio_stat_vocab')]] })];
				nodes.push(sectionFiles(c, 'radio_training'));
				nodes.push(el('ul', { class: 'bp-radio-refs' }, [
					el('li', {}, [el('a', { href: base + slug + '/funk/muster/', 'data-bp-link': '1', class: 'bp-radio-ref' }, [el('span', { class: 'bp-ico bp-ico-radio', 'aria-hidden': 'true' }), el('span', {}, [el('strong', { text: t('samples_title') }), el('span', { text: t('samples_tile') })]), el('span', { class: 'bp-area-chevron', 'aria-hidden': 'true' })])]),
					el('li', {}, [el('a', { href: base + slug + '/funk/buchstabiertafel/', 'data-bp-link': '1', class: 'bp-radio-ref' }, [el('span', { class: 'bp-ico bp-ico-cards', 'aria-hidden': 'true' }), el('span', {}, [el('strong', { text: t('spelling_title') }), el('span', { text: t('spelling_tile') })]), el('span', { class: 'bp-area-chevron', 'aria-hidden': 'true' })])])
				]));
				if (o.device && o.device.lessons > 0) {
					nodes.push(el('section', { class: 'bp-radio-device' }, [
						el('span', { class: 'bp-ico bp-ico-radio', 'aria-hidden': 'true' }),
						el('div', {}, [
							el('h2', {}, [link(slug + '/funk/geraet/', t('device_title')), o.device.premium === false ? ' ' : null, o.device.premium === false ? badge(t('sim_free_badge'), 'warn') : null]),
							el('p', { text: o.device.premium === false ? t('sim_free_short') : t('device_meta', { lessons: o.device.lessons, mastered: o.device.mastered }) })
						]),
						link(slug + '/funk/geraet/', t('device_open'), 'bp-button bp-button-signal')
					]));
				}
				var sectionHead = function (title, explain, done, total) {
					var pctv = Math.floor(done * 100 / Math.max(total, 1));
					return el('div', { class: 'bp-radio-head' }, [
						el('div', {}, [el('h2', { text: title }), el('p', { class: 'bp-muted', text: explain })]),
						el('div', { class: 'bp-radio-count' }, [el('strong', { text: done + '/' + total }), el('span', { class: 'bp-page-bar is-light', 'aria-hidden': 'true' }, [el('span', { style: 'width:' + pctv + '%' })])])
					]);
				};
				(o.practice || []).forEach(function (g) {
					var done = g.tasks.filter(function (x) { return x.done; }).length;
					nodes.push(el('section', { class: 'bp-section bp-panel bp-radio-section' }, [
						sectionHead(t('practice_title', { catalog: g.catalog }), t('practice_explain'), done, g.tasks.length),
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
					nodes.push(el('section', { class: 'bp-section bp-panel bp-radio-section' }, [
						sectionHead(t('radio_type_' + g.type, { catalog: g.catalog }), t('radio_type_' + g.type + '_explain'), done, g.items.length),
						el('ul', { class: 'bp-paper-grid' }, g.items.map(function (i) {
							if (i.locked) {
								return el('li', {}, [lockedPaper(i.number)]);
							}
							return el('li', {}, [el('a', { href: base + slug + '/funk/text/' + i.id + '/', 'data-bp-link': '1', class: 'bp-paper' + (i.done ? ' bp-paper-passed' : ''), title: i.teaser, 'aria-label': t('radio_text_aria', { number: i.number, state: i.done ? t('radio_done') : t('paper_state_open') }) }, [el('span', { class: 'bp-paper-number', text: String(i.number) }), el('span', { class: 'bp-paper-state', text: i.done ? t('radio_done') : (i.direction ? t('radio_direction_' + i.direction) : '') })])]);
						}))
					]));
				});
				if (o.vocabulary.length) {
					nodes.push(el('section', { class: 'bp-section bp-radio-vocab' }, [
						el('div', { class: 'bp-section-head' }, [el('h2', { text: t('radio_vocabulary') }), el('p', { class: 'bp-muted', text: t('radio_learned', { learned: vocDone, total: vocAll }) })]),
						el('ul', { class: 'bp-module-list' }, o.vocabulary.map(function (v) {
							return el('li', { class: 'bp-card bp-module bp-vocab-cat' + (v.learned >= v.total && v.total > 0 ? ' is-done' : '') }, [
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

	// Vorlesen über die Sprachausgabe des Browsers (keine externen Dienste).
	function speakButton(text, lang, label) {
		if (cfg.voice === 'off' || !('speechSynthesis' in window)) {
			return null;
		}
		var b = el('button', { type: 'button', class: 'bp-speak', 'aria-label': t('speak_label', { text: label || text }) }, [el('span', { class: 'bp-ico bp-ico-play', 'aria-hidden': 'true' })]);
		b.addEventListener('click', function () {
			window.speechSynthesis.cancel();
			var u = new SpeechSynthesisUtterance(text);
			u.lang = lang;
			u.rate = 0.85;
			window.speechSynthesis.speak(u);
		});
		return b;
	}

	function radioRefHero(c, slug, icon, title, intro, stats) {
		return pageHero({ code: c.code, icon: icon, crumbs: crumbs(slug, c.name, [[t('trainer_radio'), slug + '/funk/'], [title]]), eyebrow: t('trainer_radio') + ' · ' + c.name, title: title, intro: intro, stats: stats });
	}

	function viewRadioSpelling(slug) {
		return withCourse(slug, function (c) {
			return api('GET', 'courses/' + encodeURIComponent(slug) + '/radio/spelling').then(function (d) {
				var title = t('spelling_title');
				var search = el('input', { type: 'search', class: 'bp-spell-search', placeholder: t('spelling_try'), maxlength: '40', autocomplete: 'off' });
				var out = el('p', { class: 'bp-spell-out', 'aria-live': 'polite' });
				var map = {};
				d.letters.forEach(function (l) { map[l.letter] = l.word; });
				// Wie in der Tabelle: gesprochene Form (z. B. FIFE, FOWER, NINER), sonst das Wort.
				var digitWords = (d.digits[0] || { items: [] }).items.map(function (x) { return x.spoken || x.word; });
				search.addEventListener('input', function () {
					var v = search.value.toUpperCase().replace(/Ä/g, 'AE').replace(/Ö/g, 'OE').replace(/Ü/g, 'UE').replace(/ß/g, 'SS');
					out.textContent = v.split('').map(function (ch) { return map[ch] || (/[0-9]/.test(ch) ? digitWords[+ch] : (ch === ' ' ? '·' : null)); }).filter(Boolean).join(' ').toUpperCase();
				});
				var letters = el('section', { class: 'bp-section bp-spell', 'aria-labelledby': 'bp-spell-h' }, [
					el('h2', { id: 'bp-spell-h', text: t('spelling_letters') }),
					el('ul', { class: 'bp-spell-grid' }, d.letters.map(function (l) {
						return el('li', {}, [
							el('span', { class: 'bp-spell-letter', 'aria-hidden': 'true', text: l.letter }),
							el('span', { class: 'bp-spell-word' }, [el('strong', { text: l.word }), l.hint ? el('span', { text: t('spelling_spoken', { hint: l.hint }) }) : null]),
							speakButton(l.word, 'en-GB', l.letter + ': ' + l.word)
						]);
					}))
				]);
				var digits = d.digits.map(function (g) {
					return el('section', { class: 'bp-section bp-spell', 'aria-labelledby': 'bp-spell-d-' + g.catalog }, [
						el('h2', { id: 'bp-spell-d-' + g.catalog, text: t('spelling_digits', { catalog: g.catalog }) }),
						el('p', { class: 'bp-muted', text: g.catalog === 'UBI' ? t('spelling_digits_ubi') : t('spelling_digits_src') }),
						el('ul', { class: 'bp-spell-grid is-digits' }, g.items.map(function (x) {
							return el('li', {}, [
								el('span', { class: 'bp-spell-letter', 'aria-hidden': 'true', text: x.digit }),
								el('span', { class: 'bp-spell-word' }, [el('strong', { text: x.word }), x.spoken ? el('span', { text: t('spelling_spoken', { hint: x.spoken }) }) : null]),
								speakButton(x.spoken || x.word, g.catalog === 'UBI' ? 'de-DE' : 'en-GB', x.digit + ': ' + x.word)
							]);
						}))
					]);
				});
				mount([
					radioRefHero(c, slug, 'cards', title, t('spelling_intro'), [[d.letters.length, t('spelling_stat_letters')], [10, t('spelling_stat_digits')]]),
					sectionFiles(c, 'radio_training', 'spelling'),
					el('div', { class: 'bp-spell-try' }, [el('label', {}, [el('strong', { text: t('spelling_try_label') }), search]), out]),
					letters
				].concat(digits), title + ' – ' + c.name);
			});
		});
	}

	function viewRadioSamples(slug) {
		return withCourse(slug, function (c) {
			return api('GET', 'courses/' + encodeURIComponent(slug) + '/radio/samples').then(function (d) {
				var title = t('samples_title');
				var total = d.catalogs.reduce(function (n, g) { return n + g.samples.length; }, 0);
				var locked = d.catalogs.some(function (g) { return g.samples.some(function (x) { return x.locked; }); });
				var multi = d.catalogs.length > 1;
				var nodes = [
					radioRefHero(c, slug, 'radio', title, t('samples_intro'), [[total, t('samples_stat')]]),
					sectionFiles(c, 'radio_training', 'samples'),
					el('div', { class: 'bp-infobox' }, [el('span', { class: 'bp-ico bp-ico-bulb', 'aria-hidden': 'true' }), el('p', { text: t('samples_info') })]),
					locked ? lockedNote((c.shop || {}).radio_training, t('samples_locked')) : null
				];
				d.catalogs.forEach(function (g) {
					if (multi) {
						nodes.push(el('h2', { class: 'bp-subhead bp-samples-catalog', text: g.catalog }));
					}
					g.samples.forEach(function (x) {
						var head = el('summary', {}, [badge(t('kind_' + x.kind)), el('strong', { text: t('samples_kind_' + x.kind) }), x.locked ? el('span', { class: 'bp-pro-badge', text: t('pdf_pro') }) : null, el('span', { class: 'bp-topic-chevron', 'aria-hidden': 'true' })]);
						var body = x.locked ? el('div', { class: 'bp-sample-body' }, [el('p', { class: 'bp-muted', text: t('samples_locked_one') })]) : el('div', { class: 'bp-sample-body' }, [
							el('p', { class: 'bp-sample-situation' }, [el('strong', { text: t('samples_situation') + ' ' }), x.situation]),
							x.steps.length ? el('dl', { class: 'bp-sample-steps' }, x.steps.reduce(function (a, st) { return a.concat([el('dt', { text: st.label }), el('dd', { text: st.text })]); }, [])) : null,
							el('div', { class: 'bp-sample-msg-head' }, [el('strong', { text: t('samples_message') }), speakButton(x.message.replace(/\n/g, '. '), g.catalog === 'UBI' ? 'de-DE' : 'en-GB', t('samples_message'))]),
							el('pre', { class: 'bp-sample-msg', text: x.message }),
							el('p', {}, [link(slug + '/funk/praxis/' + x.id + '/', t('samples_practice', { number: x.number }), 'bp-button')])
						]);
						var det = el('details', { class: 'bp-sample' + (x.locked ? ' is-locked' : '') }, [head, body]);
						nodes.push(det);
					});
				});
				mount(nodes, title + ' – ' + c.name);
				var first = root.querySelector('.bp-sample:not(.is-locked)');
				if (first) { first.open = true; }
			});
		});
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
					}).catch(function (e) {
						alertMessage(doneBtn.parentNode || doneBtn, e);
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
					source.setAttribute('lang', lang.slice(0, 2)); // englische Funktexte als Englisch auszeichnen (3.1.2)
					source.hidden = cfg.voice !== 'off';
					var toggle = el('button', { type: 'button', class: 'bp-button', text: t('radio_show_text') });
					toggle.addEventListener('click', function () { source.hidden = !source.hidden; toggle.textContent = source.hidden ? t('radio_show_text') : t('radio_hide_text'); });
					body.appendChild(el('div', { class: 'bp-actions' }, [speakTextButton(from, lang), cfg.voice !== 'off' ? toggle : null]));
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
						}).catch(function (e) { alertMessage(result, e); }).finally(function () { evalBtn.disabled = false; });
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
						result.scrollIntoView({ behavior: scrollBehavior(), block: 'start' });
					}).catch(function (err) { alertMessage(result, err); }).finally(function () { submit.disabled = false; });
				});
				var doneBtn = el('button', { type: 'button', class: 'bp-button', 'aria-pressed': x.done ? 'true' : 'false', text: x.done ? t('radio_done') : t('radio_mark_done') });
				doneBtn.addEventListener('click', function () {
					api('PUT', 'courses/' + encodeURIComponent(slug) + '/radio/practice/' + x.id + '/done', { done: !x.done }).then(function (r) {
						x.done = r.done;
						doneBtn.textContent = x.done ? t('radio_done') : t('radio_mark_done');
						doneBtn.setAttribute('aria-pressed', x.done ? 'true' : 'false');
					}).catch(function (e) {
						alertMessage(doneBtn.parentNode || doneBtn, e);
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
				// Screenreader-Werte an den Drehknöpfen (Quelle setzt kein aria-valuenow): vor app.js an dieselben
				// Module hängen – app.js lädt ./devices/index.js und ./channels.js ohne Versions-Parameter.
				var a11y = Promise.all([
					import(cfg.deviceSim + '../build/device-sim-a11y.js?ver=' + encodeURIComponent(cfg.version)),
					import(cfg.deviceSim + 'funkgeraet/devices/index.js'),
					import(cfg.deviceSim + 'funkgeraet/channels.js')
				]).then(function (m) { m[0].install(m[1].DEVICES, m[2].CHANNEL_GROUPS); }).catch(function () { /* nur Zusatzinfo */ });
				var seq = routeSeq;
				return a11y.then(function () { return import(cfg.deviceSim + 'funkgeraet/app.js?ver=' + encodeURIComponent(cfg.version)); }).then(function (module) {
					if (seq !== routeSeq) {
						return null; // inzwischen weiternavigiert: Gerät nicht mehr einhängen
					}
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
				var counter = el('p', { class: 'bp-page-counter', 'aria-live': 'polite' });
				function updateCounter() {
					counter.textContent = t('radio_learned', { learned: learned, total: cards.length });
				}
				function show(focus) {
					stage.textContent = '';
					if (!cards.length) {
						return;
					}
					var card = cards[index % cards.length];
					var box = el('article', { class: 'bp-card bp-vocab bp-flashcard' }, [
						el('p', { class: 'bp-question-meta' }, [el('span', { class: 'bp-pill', text: card.category }), card.learned ? badge(t('radio_learned_badge'), 'ok') : el('span', { text: t('streak', { n: card.streak }) })]),
						el('p', { class: 'bp-vocab-term', lang: 'de', text: card.de })
					]);
					var actions = el('div', { class: 'bp-actions' });
					var reveal = el('button', { type: 'button', class: 'bp-button bp-button-primary', text: t('radio_show_translation') });
					reveal.addEventListener('click', function () {
						reveal.remove();
						box.insertBefore(el('p', { class: 'bp-vocab-solution', lang: 'en', text: card.en + (card.hint ? ' (' + card.hint + ')' : '') }), actions);
						var sp = speakTextButton(card.en, 'en-GB');
						var choice = [];
						[[true, t('known_yes')], [false, t('known_no')]].forEach(function (opt) {
							var b = el('button', { type: 'button', class: 'bp-button' + (opt[0] ? ' bp-button-primary' : ''), text: opt[1] });
							b.addEventListener('click', function () {
								if (isBusy(b)) { return; }
								choice.forEach(function (x) { setBusy(x, true); });
								// streak = angezeigte Serie (Vergleich auf dem Server, keine Doppelzählung).
								api('POST', 'courses/' + encodeURIComponent(slug) + '/radio/vocabulary/' + card.id, { known: opt[0], streak: card.streak || 0 }).then(function (r) {
									// repeated: Antwort wurde schon gezählt (anderer Tab/Doppelklick) – nur Stand übernehmen.
									if (r.learned && !card.learned) {
										learned++;
									}
									card.streak = r.streak;
									card.learned = r.learned;
									updateCounter();
									index++;
									show(true);
								}).catch(function (e) {
									choice.forEach(function (x) { setBusy(x, false); });
									alertMessage(actions, e);
								});
							});
							choice.push(b);
							actions.appendChild(b);
						});
						choice[0].focus();
						if (sp) {
							actions.appendChild(sp);
						}
					});
					actions.appendChild(reveal);
					box.appendChild(actions);
					stage.appendChild(box);
					if (focus) {
						reveal.focus({ preventScroll: true });
						bringIntoView(box);
					}
				}
				mount([
					pageHero({ code: c.code, icon: 'radio', crumbs: crumbs(slug, c.name, [[t('trainer_radio'), slug + '/funk/'], [category || t('radio_vocabulary')]]), eyebrow: t('radio_vocabulary') + ' · ' + catalog, title: category || t('radio_vocabulary'), intro: t('radio_vocab_explain', { n: v.streak_to_learn }), extra: counter }),
					sectionFiles(c, 'radio_training', 'vocabulary'),
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
		// Markierungen „zur Überprüfung“: Zähler und Filter der Fragenübersicht.
		var flagCount = el('span', { class: 'bp-exam-flag-count' });
		var onlyFlagged = el('input', { type: 'checkbox', id: 'bp-exam-only-flagged' });
		onlyFlagged.addEventListener('change', function () { renderNav(); });

		function flaggedCount() {
			return questions.filter(function (q) { return q.flagged; }).length;
		}

		function answered(q) {
			return q.type === 'open_text' ? q.known !== null : q.selected_ids.length > 0;
		}

		function renderNav() {
			var n = questions.filter(answered).length;
			doneCount.textContent = t('exam_progress', { n: n, total: questions.length });
			doneFill.style.width = (n * 100 / Math.max(questions.length, 1)) + '%';
			var flagged = flaggedCount();
			flagCount.textContent = t('exam_flag_count', { n: flagged });
			if (!flagged) { onlyFlagged.checked = false; }
			onlyFlagged.disabled = !flagged;
			nav.textContent = '';
			questions.forEach(function (q, i) {
				var b = el('button', { type: 'button', class: 'bp-exam-nav-item' + (answered(q) ? ' is-answered' : '') + (q.flagged ? ' is-flagged' : '') + (i === current ? ' is-current' : ''), 'aria-current': i === current ? 'step' : null, 'aria-label': t(q.flagged ? 'exam_nav_item_flagged' : 'exam_nav_item', { n: q.position, state: answered(q) ? t('answered') : t('unanswered') }), text: String(q.position) });
				b.addEventListener('click', function () { current = i; renderQuestion(true); });
				nav.appendChild(el('li', { hidden: onlyFlagged.checked && !q.flagged && i !== current }, [b]));
			});
		}

		function flagButton(q) {
			var b = el('button', { type: 'button', class: 'bp-button bp-exam-flag' + (q.flagged ? ' is-flagged' : ''), 'aria-pressed': q.flagged ? 'true' : 'false', title: t('exam_flag_hint') }, [el('span', { class: 'bp-exam-flag-icon', 'aria-hidden': 'true', text: '⚑' }), t('exam_flag')]);
			b.addEventListener('click', function () {
				if (isBusy(b) || submitting) { return; }
				setBusy(b, true);
				api('PUT', 'exam-sessions/' + s.id + '/answers/' + q.position + '/flag', { flagged: !q.flagged }).then(function (r) {
					q.flagged = !!r.flagged;
					setBusy(b, false);
					b.setAttribute('aria-pressed', q.flagged ? 'true' : 'false');
					b.classList.toggle('is-flagged', q.flagged);
					status.textContent = t(q.flagged ? 'exam_flag_saved' : 'exam_flag_removed');
					renderNav();
				}).catch(function (e) {
					setBusy(b, false);
					status.textContent = e.message;
					if (e.status === 422 && /abgelaufen|abgeschlossen/i.test(e.message)) {
						viewExam(slug, s.id).catch(showError);
					}
				});
			});
			return b;
		}

		// Speichern je Frage strikt nacheinander: höchstens eine Anfrage gleichzeitig, der zuletzt gewählte
		// Stand gewinnt (Zwischenstände werden zusammengefasst). „Gespeichert“ erst, wenn nichts mehr offen ist.
		var queues = {};
		var idleWaiters = [];
		var saveFailed = false;

		function queuesIdle() {
			return Object.keys(queues).every(function (k) { return !queues[k].busy && !queues[k].next; });
		}

		function whenIdle() {
			return queuesIdle() ? Promise.resolve() : new Promise(function (resolve) { idleWaiters.push(resolve); });
		}

		function save(q, body) {
			var qu = queues[q.position] || (queues[q.position] = { busy: false, next: null, waiters: [] });
			qu.next = body;
			saveFailed = false;
			status.textContent = t('saving');
			var done = new Promise(function (resolve) { qu.waiters.push(resolve); });
			pump(q.position);
			return done;
		}

		function pump(position) {
			var qu = queues[position];
			if (qu.busy || !qu.next) {
				return;
			}
			var body = qu.next;
			qu.next = null;
			qu.busy = true;
			api('PUT', 'exam-sessions/' + s.id + '/answers/' + position, body).then(function () {
				finish(position, null);
			}, function (e) {
				finish(position, e);
			});
		}

		function finish(position, error) {
			var qu = queues[position];
			qu.busy = false;
			if (qu.next) {
				pump(position); // neuerer Stand wartet: der gilt, Zwischenergebnis nicht melden
				return;
			}
			var waiters = qu.waiters;
			qu.waiters = [];
			if (error) {
				saveFailed = true;
				status.textContent = error.message;
				if (error.status === 422 && /abgelaufen|abgeschlossen/i.test(error.message)) {
					viewExam(slug, s.id).catch(showError);
				}
			} else if (queuesIdle() && !saveFailed) {
				status.textContent = t('saved');
			}
			renderNav();
			waiters.forEach(function (w) { w(!error); });
			if (queuesIdle()) {
				var idle = idleWaiters;
				idleWaiters = [];
				idle.forEach(function (w) { w(); });
			}
		}

		function renderQuestion(focus) {
			var q = questions[current];
			stage.textContent = '';
			var name = 'bp-e-' + q.position;
			var card = el('article', { class: 'bp-card bp-question' }, [
				el('p', { class: 'bp-question-meta' }, [el('span', { class: 'bp-pill bp-pill-strong', text: t('exam_question_of', { n: q.position, total: questions.length }) }), q.official_number ? el('span', { class: 'bp-pill', text: t('question_number', { number: q.official_number }) }) : null, flagButton(q)]),
				multiline('h2', q.text, 'bp-question-text'),
				questionMedia(q.media)
			]);
			var heading = card.querySelector('.bp-question-text');
			if (heading) {
				heading.setAttribute('tabindex', '-1');
			}
			if (q.type === 'open_text') {
				var area = el('div', { class: 'bp-open' });
				if (q.model_answer) {
					area.appendChild(el('div', { class: 'bp-model' }, [el('p', { class: 'bp-model-title', text: t('model_answer') }), multiline('p', q.model_answer)]));
					var group = el('div', { class: 'bp-actions', role: 'group', 'aria-label': t('self_assessment') });
					[[true, t('known_yes')], [false, t('known_no')]].forEach(function (opt) {
						var b = el('button', { type: 'button', class: 'bp-button' + (q.known === opt[0] ? ' bp-button-primary' : ''), 'aria-pressed': q.known === opt[0] ? 'true' : 'false', text: opt[1] });
						b.addEventListener('click', function () {
							q.known = opt[0];
							group.querySelectorAll('button').forEach(function (x) {
								var on = x === b;
								x.setAttribute('aria-pressed', on ? 'true' : 'false');
								x.classList.toggle('bp-button-primary', on);
							});
							renderNav();
							save(q, { known: opt[0] });
						});
						group.appendChild(b);
					});
					area.appendChild(group);
				} else {
					area.appendChild(el('p', { class: 'bp-muted', text: t('open_exam_explain') }));
					var reveal = el('button', { type: 'button', class: 'bp-button', text: t('reveal_answer') });
					reveal.addEventListener('click', function () {
						if (isBusy(reveal)) { return; }
						setBusy(reveal, true);
						api('POST', 'exam-sessions/' + s.id + '/answers/' + q.position + '/reveal').then(function (r) {
							q.model_answer = r.model_answer;
							if (questions[current] === q) { renderQuestion(true); }
						}).catch(function (e) { setBusy(reveal, false); status.textContent = e.message; });
					});
					area.appendChild(reveal);
				}
				card.appendChild(area);
			} else {
				var fieldset = answerList(q, name, q.selected_ids, false);
				fieldset.addEventListener('change', function () {
					var ids = selectedIds(fieldset);
					q.selected_ids = ids;
					// Auch das Leeren speichern (alle Kästchen abgehakt = unbeantwortet).
					save(q, { answer_ids: ids });
					renderNav();
				});
				card.appendChild(fieldset);
			}
			var prev = el('button', { type: 'button', class: 'bp-button', disabled: current === 0 }, [el('span', { class: 'bp-arrow', 'aria-hidden': 'true', text: '←' }), t('prev')]);
			var nextB = el('button', { type: 'button', class: 'bp-button bp-button-primary', disabled: current === questions.length - 1 }, [t('next'), el('span', { class: 'bp-arrow', 'aria-hidden': 'true', text: '→' })]);
			prev.addEventListener('click', function () { current--; renderQuestion(true); });
			nextB.addEventListener('click', function () { current++; renderQuestion(true); });
			card.appendChild(el('div', { class: 'bp-actions bp-exam-pager' }, [prev, nextB]));
			stage.appendChild(card);
			renderNav();
			// Tastatur/Screenreader: nach dem Fragenwechsel auf die neue Frage, nicht auf <body>;
			// mobil mit Abstand zum fixierten Timer einscrollen (scroll-margin-top in app.css).
			if (focus && heading) {
				heading.focus({ preventScroll: true });
				bringIntoView(card);
			}
		}

		function submit(auto) {
			if (submitting) {
				return;
			}
			var open = questions.filter(function (q) { return !answered(q); }).length;
			var flagged = flaggedCount();
			var question = (flagged > 0 ? t('confirm_submit_flagged', { n: flagged }) + ' ' : '') + (open > 0 ? t('confirm_submit_open', { n: open }) : t('confirm_submit'));
			if (!auto && !window.confirm(question)) {
				return;
			}
			submitting = true;
			// Erst abgeben, wenn alle Antworten gespeichert sind (sonst fehlt evtl. die letzte Auswahl).
			whenIdle().then(function () {
				return api('POST', 'exam-sessions/' + s.id + '/submit');
			}).then(function (r) { examResult(slug, r); }).catch(function (e) {
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
					el('p', { class: 'bp-exam-rule' }, [s.live ? badge(t('exam_live'), 'ok') : badge(t('exam_practice'), 'warn'), el('span', { text: s.rule.passing })]),
					s.reduced ? el('p', { class: 'bp-note bp-exam-reduced', text: t('exam_reduced') }) : null
				]),
				deadline ? timer : null
			]),
			el('div', { class: 'bp-exam-layout' }, [
				stage,
				el('aside', { class: 'bp-exam-side', 'aria-label': t('exam_overview') }, [
					el('div', { class: 'bp-exam-progress' }, [doneCount, el('span', { class: 'bp-exam-bar', 'aria-hidden': 'true' }, [doneFill])]),
					el('div', { class: 'bp-exam-flags' }, [flagCount, el('p', { class: 'bp-switch' }, [onlyFlagged, el('label', { for: 'bp-exam-only-flagged', text: t('exam_only_flagged') })])]),
					nav,
					el('p', { class: 'bp-exam-legend', 'aria-hidden': 'true' }, [el('span', { class: 'is-answered' }), t('answered'), el('span'), t('unanswered'), el('span', { class: 'is-flagged' }), t('exam_flagged')]),
					el('div', { class: 'bp-exam-footer' }, [submitButton, status])
				])
			])
		], examTitle(s));
		renderQuestion();

		if (deadline) {
			var warned5 = false;
			var warned1 = false;
			var tick = function () {
				var left = Math.max(0, Math.round((deadline - Date.now()) / 1000));
				var m = Math.floor(left / 60);
				var sec = left % 60;
				timeText.textContent = m + ':' + (sec < 10 ? '0' : '') + sec;
				timer.setAttribute('aria-label', t('time_left', { time: timeText.textContent }));
				timer.classList.toggle('is-low', left <= 300);
				// Einmalige Ansagen (auch wenn ein Takt übersprungen wird, z. B. im Hintergrund-Tab).
				if (left <= 60 && left > 0 && !warned1) {
					warned1 = warned5 = true;
					status.textContent = t('one_minute');
				} else if (left <= 300 && left > 60 && !warned5) {
					warned5 = true;
					status.textContent = t('five_minutes');
				}
				if (left <= 0) {
					clearTimers();
					status.textContent = t('time_up');
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
		var onlyFlagged = el('input', { type: 'checkbox', id: 'bp-only-flagged' });
		var wrong = 0;
		var flagged = 0;
		r.questions.forEach(function (q) {
			if (!q.correct) { wrong++; }
			if (q.flagged) { flagged++; }
			var item = el('li', { class: 'bp-card bp-review-item ' + (q.correct ? 'is-correct' : 'is-wrong') + (q.flagged ? ' is-flagged' : '') }, [
				el('p', { class: 'bp-question-meta' }, [el('span', { class: 'bp-pill bp-pill-strong', text: t('exam_question_of', { n: q.position, total: r.questions.length }) }), badge(q.correct ? t('mark_correct') : (q.answered ? t('mark_wrong') : t('unanswered')), q.correct ? 'ok' : 'bad'), q.flagged ? badge(t('exam_flagged'), 'warn') : null]),
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
		onlyFlagged.addEventListener('change', function () { list.classList.toggle('bp-only-flagged', onlyFlagged.checked); });

		var parts = null;
		if (r.parts.length) {
			parts = el('section', { class: 'bp-section', 'aria-labelledby': 'bp-parts-h' }, [
				el('h2', { id: 'bp-parts-h', text: t('exam_parts_title') }),
				el('ul', { class: 'bp-part-cards' }, r.parts.map(function (p) {
					if (p.empty) {
						// Teil kam in dieser Prüfung nicht vor: nicht gewertet (weder bestanden noch durchgefallen).
						return el('li', { class: 'bp-card bp-part is-empty' }, [
							el('p', { class: 'bp-part-head' }, [el('strong', { text: p.label }), badge(t('part_not_scored'), 'neutral')]),
							el('p', { class: 'bp-muted bp-part-need', text: t('part_not_scored_text') })
						]);
					}
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

		var percent = resultPct({ score: r.score, max: r.max });
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
						s.reduced || r.reduced ? el('p', { class: 'bp-verdict-note', text: t('exam_reduced') }) : (!s.live ? el('p', { class: 'bp-verdict-note', text: t('practice_result_note') }) : null),
						r.open_text.total > 0 ? el('p', { class: 'bp-verdict-note', text: t('open_text_note', { n: r.open_text.correct, total: r.open_text.total }) }) : null
					]),
					el('div', { class: 'bp-hero-actions' }, [
						wrong ? link(slug + '/lernen/?pruefung=' + s.id, t('learn_wrong'), 'bp-button bp-button-signal') : null,
						link(slug + '/', t('back_course'), 'bp-button bp-button-ghost')
					])
				]),
				el('div', { class: 'bp-result-ring' }, [scoreRing(r.ratio || 0, r.passed), el('p', {}, [el('strong', { text: percent + ' %' }), el('span', { text: r.score + ' / ' + r.max })])])
			]),
			s.rule.note ? el('p', { class: 'bp-note', text: s.rule.note }) : null,
			parts,
			// Nur sinnvoll ab zwei Themen (sonst identisch mit dem Gesamtergebnis).
			Object.keys(r.topics).length > 1 ? el('section', { class: 'bp-section bp-panel', 'aria-labelledby': 'bp-topics-h' }, [el('h2', { id: 'bp-topics-h', text: t('topics_title') }), topics]) : null,
			el('section', { class: 'bp-section' }, [
				el('div', { class: 'bp-section-head' }, [
					el('h2', { text: t('review_title') }),
					el('p', { class: 'bp-switch' }, [onlyWrong, el('label', { for: 'bp-only-wrong', text: t('only_wrong') })]),
					flagged ? el('p', { class: 'bp-switch' }, [onlyFlagged, el('label', { for: 'bp-only-flagged', text: t('only_flagged') })]) : null
				]),
				flagged ? el('p', { class: 'bp-muted bp-result-flagged', text: t('result_flagged', { n: flagged }) }) : null,
				list
			])
		], t('result_title', { title: examTitle(s) }));
	}

	route();
}());
