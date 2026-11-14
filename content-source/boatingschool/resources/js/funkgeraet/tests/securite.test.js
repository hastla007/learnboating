// SECURITE an alle (Prüfungsaufgabe 4.b): DSC „Safety“, Ankündigung auf
// Kanal 16, Wechsel auf den Arbeitskanal 06, Meldung auf 06 -- auf allen
// Geräten.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createLessonRun } from '../lessons.js';
import { DEVICES, prepareLesson } from '../devices/index.js';

const data = JSON.parse(execFileSync('php', ['-r', 'echo json_encode(require "database/data/geraetesimulation.php");'], { encoding: 'utf8' }));
const lesson = data.lessons.find((l) => l.key === 'sicherheit');

test('SECURITE: Ankündigung auf 16, Meldung auf dem Arbeitskanal 06, auf allen Geräten', () => {
    for (const key of Object.keys(DEVICES)) {
        let now = 0;
        const events = [];
        const radio = DEVICES[key].create({ own: data.ship, clock: () => now, emit: (e) => events.push(e), addressBook: data.address_book, directory: data.directory });
        const prepared = prepareLesson(lesson, key);
        const run = createLessonRun(prepared, { schedule: () => {}, receive: () => {}, say: () => ({ heard: true }) });
        radio.setup(prepared.start);
        run.start();
        const speech = [];
        const pump = () => { while (events.length) { const p = run.handle(events.shift()); if (p && ! p.miss) { speech.push(p); run.speechResult(p.stepIndex, true); } } };
        events.length = 0;
        run.handle({ type: 'dsc_sent', kind: 'all_ships', category: 'Safety' });
        assert.equal(run.status().index, 1, key);
        // Auf Kanal 16 senden: das ist die Ankündigung (Schritt 1).
        radio.pttDown(); radio.pttUp(); radio.spoken('Securite … listen on channel zero six, out'); pump();
        assert.equal(speech.at(-1)?.stepIndex, 1, `${key}: Ankündigung auf 16`);
        assert.equal(run.status().index, 2, key);
        // Wechsel auf den angekündigten Arbeitskanal.
        radio.setup({ channel: 6 });
        run.handle({ type: 'channel', channel: 6 });
        assert.equal(run.status().index, 3, `${key}: Kanal 06`);
        radio.pttDown(); radio.pttUp(); radio.spoken('Securite … navigational warning … out'); pump();
        assert.equal(speech.at(-1)?.stepIndex, 3, `${key}: Meldung auf 06`);
        assert.equal(run.status().done, true, key);
    }
});

test('Binnenfunk: Verkehrskreise am Gerät nach dem Handbuch Binnenschifffahrtsfunk', async () => {
    const { CHANNEL_GROUPS } = await import('../channels.js');
    const circle = (name) => CHANNEL_GROUPS.ATIS.filter((c) => c.circle === name).map((c) => c.number);
    assert.deepEqual(circle('Schiff-Schiff'), [6, 8, 10, 72, 77]);
    assert.deepEqual(circle('Schiff-Hafen'), [11, 12, 13, 14, 71, 74]);
    assert.deepEqual(circle('An Bord'), [15, 17]);
    assert.deepEqual(circle('Nautische Information'), [18, 19, 20, 22, 23, 24, 25, 26, 27, 28, 73, 78, 79, 80, 81, 82, 84, 85, 86]);
    assert.ok(! CHANNEL_GROUPS.ATIS.some((c) => c.number === 21), 'kein Kanal 21');
});
