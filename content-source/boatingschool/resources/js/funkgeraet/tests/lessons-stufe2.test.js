// Lektionen und Prüfungsmodus aus Stufe 2 sowie Freies Üben, ohne Browser.
// Die Lektionen kommen wie im Browser aus database/data/geraetesimulation.php.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createIcM505 } from '../devices/icm505.js';
import { createLessonRun, EXAM_ATTEMPTS } from '../lessons.js';
import { createFreePractice } from '../free-practice.js';

const data = JSON.parse(execFileSync('php', ['-r', 'echo json_encode(require "database/data/geraetesimulation.php");'], { encoding: 'utf8' }));
const lesson = (key) => data.lessons.find((l) => l.key === key);

// Lektion mit Gerät, Zeitplan und Gegenstationen: Zeitgesteuertes läuft
// erst mit fire(), damit der Test die Reihenfolge bestimmt.
function play(key, { exam = false, start = null } = {}) {
    let now = 0;
    const events = [];
    const radio = createIcM505({ own: data.ship, clock: () => now, emit: (e) => events.push(e), addressBook: data.address_book, directory: data.directory });
    const timers = [];
    const voices = [];
    const l = lesson(key);
    const run = createLessonRun(l, {
        level: 1,
        exam,
        schedule: (fn, ms) => timers.push({ fn, ms }),
        receive: (call) => radio.receive(call),
        say: (voice) => { const r = radio.hear(voice); voices.push({ ...voice, ...r }); return r; },
    });
    radio.setup(start ?? l.start);
    events.length = 0;
    const speech = [];
    const misses = [];
    const pump = () => {
        while (events.length) {
            const pending = run.handle(events.shift());
            if (pending?.miss) misses.push(pending);
            else if (pending) speech.push(pending);
        }
    };
    const wait = (ms) => { for (let t = 0; t < ms; t += 100) { now += 100; radio.tick(); } pump(); };
    const press = (k, ms = 100) => { radio.keyDown(k); wait(ms); radio.keyUp(k); pump(); };
    const fire = () => { timers.splice(0).forEach((t) => t.fn()); pump(); };
    const menu = (item) => {
        press('menu');
        radio.turn('channel', radio.display().panel.items.indexOf(item));
        press('enter');
    };
    const pick = (item) => {
        const items = radio.display().panel.items;
        radio.turn('channel', items.indexOf(item) - radio.display().panel.cursor);
        press('enter');
    };
    const speak = (text) => { radio.pttDown(); radio.pttUp(); radio.spoken(text); pump(); };
    run.start();

    return { radio, run, press, wait, fire, menu, pick, speak, speech, misses, voices, pump };
}

test('every lesson has steps with a goal, and exam series only use existing lessons', () => {
    assert.ok(data.lessons.length >= 30);
    for (const l of data.lessons) {
        assert.ok(l.steps.length > 0, l.key);
        for (const step of l.steps) assert.ok(step.goal?.type, `${l.key}: Schritt ohne Ziel`);
    }
    for (const [series, keys] of Object.entries(data.exams)) {
        assert.equal(keys.length, data.exam_meta[series].catalog === 'SRC' ? 7 : 2, series);
        for (const key of keys) assert.ok(lesson(key), `${series}: ${key}`);
    }
});

test('lesson: Hören und senden waits until the squelch lets the voice through', () => {
    const p = play('hoeren-senden');
    p.radio.turn('channel', 72 - 16 > 0 ? 1 : 0);
    for (let i = 0; i < 60 && p.radio.state.channel !== 72; i++) p.radio.turn('channel', 1);
    p.pump();
    p.radio.turn('vol', 5);
    p.pump();
    p.fire(); // Stimme kommt erst im Schritt „Hör zu“
    p.radio.turn('sql', -2);
    p.pump();
    p.fire();
    assert.equal(p.voices.at(-1).heard, true);
    assert.equal(p.run.status().index, 4);
});

test('lesson: Einzelanruf annehmen with Able to Comply and the reply of the Möwe', () => {
    const p = play('einzelanruf-annehmen');
    p.fire();
    p.press('clr');
    p.press('enter');
    p.press('enter');
    p.press('enter');
    p.wait(2100);
    assert.equal(p.radio.state.channel, 72);
    p.fire();
    assert.equal(p.voices.at(-1).heard, true);
    p.speak('Moewe this is Kormoran yes over');
    assert.equal(p.speech.length, 1);
    assert.equal(p.run.status().awaitingSpeech, true);
});

test('lesson: Positionsabfrage und Polling', () => {
    const p = play('position-polling');
    p.menu('Position Request');
    p.pick('MOEWE');
    p.press('enter');
    p.fire();
    p.press('clr');
    p.press('enter');
    assert.equal(p.run.status().index, 2);
    p.fire();
    p.press('clr');
    p.press('clr');
    p.menu('Polling Reply');
    p.press('enter');
    assert.equal(p.run.status().done, true);
});

test('lesson: Suchlauf stops on the voice on a TAG channel', () => {
    const p = play('suchlauf-tag');
    p.press('scan', 1200);
    for (const ch of [72, 77]) {
        while (p.radio.state.channel !== ch) p.radio.turn('channel', 1);
        p.press('scan', 1200);
    }
    p.press('scan');
    p.wait(1200);
    p.fire();
    assert.deepEqual(p.voices.map((v) => [v.channel, v.heard, v.reason]), [[77, true, null]]);
    assert.equal(p.radio.state.channel, 77);
    p.press('ch16');
    assert.equal(p.run.status().done, true);
});

test('lesson: Zwei- und Dreikanalwache with the Set mode', () => {
    const p = play('zweikanalwache');
    p.press('dial', 1200);
    p.fire();
    assert.equal(p.voices.at(-1).heard, true);
    p.press('dial');
    p.press('power', 1200);
    p.radio.keyDown('ch16');
    p.press('power');
    p.radio.keyUp('ch16');
    p.radio.turn('channel', 2);
    p.press('enter');
    p.press('clr');
    p.press('dial', 1200);
    assert.equal(p.run.status().done, true);
});

test('lesson: Notalarm empfangen switches to 16 and waits for the spoken acknowledgement', () => {
    const p = play('notalarm-empfangen');
    p.fire();
    assert.equal(p.radio.state.channel, 16);
    p.press('clr');
    p.fire();
    assert.equal(p.voices.at(-1).from, 'SEESTERN');
    p.speak('Mayday Seestern Seestern Seestern this is Kormoran received mayday over');
    assert.equal(p.speech[0].stepIndex, 2);
});

test('lesson: Adressbuch adds the Seestern and calls it from the book', () => {
    const p = play('adressbuch');
    p.menu('Set up');
    p.press('enter');
    for (const d of '211555000') { p.radio.turn('channel', Number(d)); p.press('ch16'); }
    p.press('enter');
    p.press('clr'); // Set up -> Hauptmenü
    p.press('clr'); // Hauptmenü schließen
    p.menu('Individual Call');
    p.pick('SEESTERN');
    p.press('enter'); // Routine
    while (p.radio.display().panel.lines[0] !== '77') p.radio.turn('channel', 1);
    p.press('enter');
    p.press('enter');
    p.fire();
    assert.equal(p.run.status().done, true);
});

test('a voice that is not heard is repeated until the radio is set up', () => {
    const p = play('hoeren-senden');
    // Schritte 1–3 überspringen: Gerät steht noch auf 16, Stimme auf 72.
    while (p.run.status().index < 3) {
        const goal = lesson('hoeren-senden').steps[p.run.status().index].goal;
        p.run.handle({ ...goal, radio: {}, level: 9, state: goal.state ?? 'set', heard: true });
    }
    p.fire();
    assert.equal(p.voices.at(-1).heard, false);
    assert.equal(p.voices.at(-1).reason, 'channel');
    p.fire(); // Wiederholung
    assert.equal(p.voices.length, 2);
});

test('exam mode: two failed attempts fail the task, a wrong channel counts as one', () => {
    const p = play('notmeldung-sprechen', { exam: true });
    assert.equal(p.run.status().help, null);
    assert.equal(p.run.status().target, null);
    p.radio.turn('channel', 1);
    p.speak('Mayday this is Kormoran');
    assert.equal(p.misses.length, 1);
    assert.equal(p.run.status().attemptsLeft, EXAM_ATTEMPTS - 1);
    p.press('ch16');
    p.speak('Mayday this is Kormoran');
    p.run.speechResult(p.speech[0].stepIndex, false, [{ label: 'Position', ok: false }]);
    assert.equal(p.run.status().done, true);
    assert.equal(p.run.status().failed, true);
    assert.equal(p.run.status().results[0].checks[0].label, 'Position');
});

test('exam mode: a task can be given up', () => {
    const p = play('dringlichkeit', { exam: true });
    p.run.giveUp();
    assert.deepEqual([p.run.status().done, p.run.status().failed], [true, true]);
});

test('free practice: the Möwe acknowledges an individual call and replies on the working channel', () => {
    let now = 0;
    const timers = [];
    const voices = [];
    let free;
    const radio = createIcM505({ own: data.ship, clock: () => now, emit: (e) => free?.handle(e), addressBook: data.address_book });
    radio.setup({ power: true, vol: 5, sql: 3, channel: 16 });
    free = createFreePractice({ ship: data.ship, schedule: (fn) => timers.push(fn), receive: (call) => radio.receive(call), say: (v) => { const r = radio.hear(v); voices.push({ ...v, ...r }); return r; }, radio: () => radio });
    const press = (k) => { radio.keyDown(k); radio.keyUp(k); };

    free.trigger('individual');
    press('clr');
    press('enter');
    press('enter');
    press('enter');
    for (let t = 0; t < 2100; t += 100) { now += 100; radio.tick(); }
    timers.splice(0).forEach((fn) => fn());
    assert.equal(voices.at(-1).heard, true);
    assert.equal(voices.at(-1).channel, 72);
    radio.spoken('Moewe this is Kormoran fine thanks over', 72);
    timers.splice(0).forEach((fn) => fn());
    assert.match(voices.at(-1).text, /go ahead/);

    // Notalarm: Bremen Rescue bestätigt.
    radio.keyDown('distressCover'); radio.keyUp('distressCover');
    radio.keyDown('distress');
    for (let t = 0; t < 5100; t += 100) { now += 100; radio.tick(); }
    radio.keyUp('distress');
    timers.splice(0).forEach((fn) => fn());
    assert.equal(radio.display().lines[1], 'DistressACK');
});

test('lesson: Sendeleistung (1 W forced on 17, 1 W on 72, 25 W on 16)', () => {
    const p = play('sendeleistung');
    p.radio.turn('channel', 1);
    p.pump();
    p.press('hl');
    while (p.radio.state.channel !== 72) p.radio.turn('channel', 1);
    p.pump();
    p.press('hl');
    p.press('ch16');
    p.press('hl');
    assert.equal(p.run.status().done, true);
});

test('lesson: Gruppenanruf to the flotilla on channel 69', () => {
    const p = play('gruppenanruf');
    p.menu('Group Call');
    p.pick('FLOTILLE');
    p.press('enter');
    while (p.radio.display().panel.lines[0] !== '69') p.radio.turn('channel', 1);
    p.press('enter');
    p.press('enter');
    p.wait(2100);
    p.speak('All yachts of flotilla this is Kormoran over');
    assert.equal(p.speech[0].stepIndex, 2);
});

test('lessons: Dringlichkeit and Sicherheit announce via All Ships Call with the right category', () => {
    for (const [key, category] of [['dringlichkeit', 'Urgency'], ['sicherheit', 'Safety'], ['dringlichkeit-aufheben', 'Urgency']]) {
        const p = play(key);
        p.menu('All Ships Call');
        p.pick(category);
        p.press('enter');
        p.wait(2100);
        p.speak('Pan pan test over');
        assert.equal(p.speech.length, 1, key);
    }
});

test('lessons: received All Ships and Geographic calls take the radio to the announced channel with ENTER', () => {
    for (const [key, channel, from] of [['dringlichkeit-empfangen', 16, 'BREMEN RESCUE'], ['gebietsanruf', 26, 'NORDSEE RADIO']]) {
        const p = play(key);
        p.fire();
        p.press('clr');
        p.press('enter');
        assert.equal(p.radio.state.channel, channel, key);
        p.fire();
        assert.equal(p.voices.at(-1).from, from);
        assert.equal(p.run.status().done, true, key);
    }
});

test('lesson: MEDICO via an urgency individual call to Bremen Rescue', () => {
    const p = play('medico');
    p.menu('Individual Call');
    p.pick('BREMEN RESCUE');
    p.pick('Urgency');
    while (p.radio.display().panel.lines[0] !== '16') p.radio.turn('channel', 1);
    p.press('enter');
    p.press('enter');
    p.fire();
    assert.equal(p.run.status().index, 2);
    p.press('clr');
    p.speak('Pan pan Bremen Rescue Radio this is Kormoran medical advice over');
    assert.equal(p.speech[0].stepIndex, 2);
});

// Review: zweiter Funkspruch während der Bewertung, Serverfehler, Anruf bei
// ausgeschaltetem Gerät, Kanalgruppe im Hinweis.
test('lesson run: a second transmission while one is checked is ignored; a server error is no attempt', async () => {
    const { createLessonRun: create, missLabel } = await import('../lessons.js');
    const lesson = { key: 'x', steps: [{ goal: { type: 'spoken', channel: 16 }, speech: true }] };
    const run = create(lesson, { exam: true, schedule: () => {} });
    run.start();
    const first = run.handle({ type: 'spoken', channel: 16, text: 'falsch' });
    assert.equal(first.stepIndex, 0);
    assert.equal(run.handle({ type: 'spoken', channel: 16, text: 'richtig' }), null);
    run.speechError(0);
    assert.equal(run.status().awaitingSpeech, false);
    assert.equal(run.status().attemptsLeft, 2);
    assert.ok(run.handle({ type: 'spoken', channel: 16, text: 'noch einmal' }));
    assert.equal(missLabel({ channel: 18, group: 'ATIS', highPower: true }), 'Kanal 18 in der Kanalgruppe ATIS mit 25 W');
});

test('lesson run: a DSC call the radio could not take (switched off) is delivered again later', async () => {
    const { createLessonRun: create, VOICE_REPEAT_MS } = await import('../lessons.js');
    const timers = [];
    let on = false;
    const delivered = [];
    const lesson = { key: 'x', steps: [{ goal: { type: 'alarm_stopped' }, receive: { delay_ms: 2000, call: { type: 'individual' } } }] };
    const run = create(lesson, { schedule: (fn, ms) => timers.push([fn, ms]), receive: (call) => { if (! on) return false; delivered.push(call); return true; } });
    run.start();
    const [fn] = timers.shift();
    fn();
    assert.equal(delivered.length, 0);
    assert.equal(timers[0][1], VOICE_REPEAT_MS);
    on = true;
    timers.shift()[0]();
    assert.equal(delivered.length, 1);
});
