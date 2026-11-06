/* Führt einen freigegebenen Import in Teilschritten aus (REST, mit Fortschrittsanzeige). */
(function () {
	'use strict';
	var button = document.getElementById('bp-import-run');
	if (!button || !window.bpImport) {
		return;
	}
	var progress = document.getElementById('bp-import-progress');
	var status = document.getElementById('bp-import-status');
	var phases = window.bpImport.phases;

	function step() {
		return fetch(window.bpImport.root + 'imports/' + button.dataset.batch + '/step', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': window.bpImport.nonce }
		}).then(function (response) {
			return response.json().then(function (body) {
				if (!response.ok) {
					throw new Error(body && body.message ? body.message : response.statusText);
				}
				return body;
			});
		});
	}

	function loop() {
		return step().then(function (result) {
			var index = phases.indexOf(result.phase);
			var share = result.total > 0 ? result.processed / result.total : 1;
			progress.value = Math.round(((index + share) / phases.length) * 100);
			status.textContent = result.phase + ' ' + result.processed + '/' + result.total;
			if (result.done) {
				status.textContent = window.bpImport.doneText;
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
			status.textContent = error.message;
			button.disabled = false;
		});
	});
}());
