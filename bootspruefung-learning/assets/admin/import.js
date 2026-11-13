/*
 * Seite „Inhaltspakete“:
 * 1. Vor dem Hochladen: Dateigröße und Endung im Browser prüfen (zu große Dateien verwirft PHP sonst
 *    ohne verständliche Meldung).
 * 2. Freigegebenen Import in Teilschritten ausführen (REST) mit deutscher Fortschrittsanzeige; Serverfehler
 *    (HTTP-Fehler, keine gültige Antwort, Zeitüberschreitung, keine Verbindung) verständlich anzeigen.
 */
(function () {
	'use strict';
	var text = window.bpImportText || {};

	function format(template) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(template || '').replace(/%(\d+\$)?([sd])/g, function (match, position) {
			var value = position ? args[parseInt(position, 10) - 1] : args[i++];
			return value === undefined ? '' : String(value);
		});
	}

	function sizeLabel(bytes) {
		if (bytes >= 1024 * 1024) {
			return (bytes / (1024 * 1024)).toFixed(1).replace('.', ',') + ' MB';
		}
		return Math.max(1, Math.round(bytes / 1024)) + ' KB';
	}

	// 1. Größenprüfung vor dem Hochladen.
	var form = document.getElementById('bp-import-upload');
	if (form) {
		var box = document.getElementById('bp-import-upload-message');
		var show = function (message, type) {
			if (!box) {
				return;
			}
			box.innerHTML = '';
			var notice = document.createElement('div');
			notice.className = 'notice inline notice-' + type;
			var p = document.createElement('p');
			p.textContent = message;
			notice.appendChild(p);
			box.appendChild(notice);
		};
		form.addEventListener('submit', function (event) {
			var input = form.querySelector('input[type=file]');
			var file = input && input.files && input.files[0];
			var max = parseInt(form.getAttribute('data-max-bytes') || '0', 10);
			if (!file) {
				return;
			}
			if (!/\.zip$/i.test(file.name)) {
				event.preventDefault();
				show(text.notZip, 'error');
				return;
			}
			if (max > 0 && file.size > max) {
				event.preventDefault();
				show(format(text.tooLargeSize, sizeLabel(file.size)) + ' ' + text.tooLarge, 'error');
				return;
			}
			show(text.uploading, 'info');
		});
	}

	// 2. Import in Teilschritten.
	var button = document.getElementById('bp-import-run');
	if (!button || !window.bpImport) {
		return;
	}
	var progress = document.getElementById('bp-import-progress');
	var status = document.getElementById('bp-import-status');
	var phases = window.bpImport.phases;
	var batch = button.dataset.batch;
	var TIMEOUT = 180000; // ein Teilschritt; der Server begrenzt Medien je Schritt

	function failure(message) {
		var error = new Error(message);
		error.userMessage = message;
		return error;
	}

	function step() {
		var controller = window.AbortController ? new window.AbortController() : null;
		var timer = controller ? window.setTimeout(function () { controller.abort(); }, TIMEOUT) : null;
		return fetch(window.bpImport.root + 'imports/' + batch + '/step', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': window.bpImport.nonce },
			signal: controller ? controller.signal : undefined
		}).then(function (response) {
			if (timer) {
				window.clearTimeout(timer);
			}
			return response.text().then(function (raw) {
				var body = null;
				try {
					body = raw ? JSON.parse(raw) : null;
				} catch (e) {
					body = null;
				}
				if (response.ok && body && typeof body === 'object') {
					return body;
				}
				if (body && body.code === 'rest_cookie_invalid_nonce') {
					throw failure(text.expired);
				}
				if (body && body.message) {
					throw failure(format(text.error, body.message));
				}
				if (response.ok) {
					throw failure(format(text.invalid, batch));
				}
				throw failure(format(text.http, response.status, batch));
			});
		}, function (error) {
			if (timer) {
				window.clearTimeout(timer);
			}
			if (error && error.name === 'AbortError') {
				throw failure(format(text.timeout, batch));
			}
			throw failure(text.network);
		});
	}

	function label(phase) {
		return (text.phases && text.phases[phase]) || phase;
	}

	function loop() {
		return step().then(function (result) {
			var index = phases.indexOf(result.phase);
			var share = result.total > 0 ? result.processed / result.total : 1;
			progress.value = Math.round(((Math.max(0, index) + share) / phases.length) * 100);
			status.textContent = format(text.step || '%3$s %4$d/%5$d', index + 1, phases.length, label(result.phase), result.processed, result.total);
			if (result.done) {
				progress.value = 100;
				status.textContent = text.done || window.bpImport.doneText;
				window.location.reload();
				return null;
			}
			return loop();
		});
	}

	button.addEventListener('click', function () {
		button.disabled = true;
		status.textContent = window.bpImport.runningText;
		loop().catch(function (error) {
			status.textContent = (error && error.userMessage) || format(text.error, error && error.message ? error.message : '');
			status.setAttribute('role', 'alert');
			button.disabled = false;
		});
	});
}());
