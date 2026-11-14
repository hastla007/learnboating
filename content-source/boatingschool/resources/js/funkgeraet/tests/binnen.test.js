// Stufe 3: Binnenfunk (UBI) am IC-M505 – Binnenmodus (ATIS), Verkehrskreise,
// 1-W-Regel, ATIS-Kennung und die Binnen-Lektionen.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createIcM505 } from '../devices/icm505.js';
import { createLessonRun } from '../lessons.js';

const data = JSON.parse(execFileSync('php', ['-r', 'echo json_encode(require "database/data/geraetesimulation.php");'], { encoding: 'utf8' }));
const lesson = (key) => data.lessons.find((l) => l.key === key);

function rig(start, key = null) {
    let now = 0;
    const events = [];
    const radio = createIcM505({ own: data.ship, clock: () => now, emit: (e) => events.push(e), addressBook: data.address_book });
    const timers = [];
    const voices = [];
    const run = key ? createLessonRun(lesson(key), {
        schedule: (fn) => timers.push(fn),
        say: (v) => { const r = radio.hear({ group: 'ATIS', ...v }); voices.push({ ...v, ...r }); return r; },
    }) : null;
    radio.setup(start ?? lesson(key).start);
    events.length = 0;
    const speech = [];
    const pump = () => {
        while (events.length) {
            const event = events.shift();
            const p = run ? run.handle(event) : null;
            if (p && ! p.miss) speech.push(p);
        }
    };
    const wait = (ms) => { for (let t = 0; t < ms; t += 100) { now += 100; radio.tick(); } pump(); };
    const press = (k, ms = 100) => { radio.keyDown(k); wait(ms); radio.keyUp(k); pump(); };
    const combo = (m, k) => { radio.keyDown(m); radio.keyDown(k); radio.keyUp(k); radio.keyUp(m); pump(); };
    const to = (ch) => { for (let i = 0; i < 60 && radio.state.channel !== ch; i++) radio.turn('channel', 1); pump(); assert.equal(radio.state.channel, ch); };
    const fire = () => { timers.splice(0).forEach((fn) => fn()); pump(); };
    const speak = (text) => { radio.pttDown(); radio.pttUp(); radio.spoken(text); pump(); };
    run?.start();

    return { radio, run, events, press, combo, to, fire, speak, speech, voices, last: (type) => events.filter((e) => e.type === type).at(-1) };
}

test('ATIS mode has no channel 16, no DSC and no distress alert', () => {
    const events = [];
    const radio = createIcM505({ own: data.ship, clock: () => 0, emit: (e) => events.push(e) });
    radio.setup({ power: true, group: 'ATIS', channel: 10 });
    for (const key of ['ch16', 'menu']) { radio.keyDown(key); radio.keyUp(key); }
    radio.keyDown('distressCover'); radio.keyUp('distressCover');
    radio.keyDown('distress');
    assert.deepEqual(events.filter((e) => ['ch16_unavailable', 'dsc_blocked'].includes(e.type)).map((e) => e.key), ['ch16', 'menu', 'distress']);
    assert.equal(radio.state.channel, 10);
    assert.notEqual(radio.state.screen, 'distressHold');
});

test('1 W is forced outside the Nautische Information, 25 W is allowed there', () => {
    const r = rig({ power: true, group: 'ATIS', channel: 10, highPower: true });
    assert.equal(r.radio.display().status.power, '1W');
    r.to(18);
    assert.equal(r.radio.display().status.power, '25W');
    assert.equal(r.radio.state.circle, 'Nautische Information');
    r.to(72);
    assert.equal(r.radio.display().status.power, '1W');
    assert.equal(r.radio.state.circle, 'Schiff-Schiff');
});

test('the ATIS identification is sent after every transmission in ATIS mode only', () => {
    const events = [];
    const radio = createIcM505({ own: data.ship, clock: () => 0, emit: (e) => events.push(e) });
    radio.setup({ power: true, group: 'ATIS', channel: 10 });
    radio.pttDown(); radio.pttUp();
    assert.equal(events.at(-1).type, 'atis_sent');
    assert.equal(events.at(-1).id, data.ship.atis);
    radio.setup({ group: 'INT', channel: 72 });
    radio.pttDown(); radio.pttUp();
    assert.equal(events.filter((e) => e.type === 'atis_sent').length, 1);
});

test('inland voices are only heard in ATIS mode, maritime voices only outside it', () => {
    const radio = createIcM505({ own: data.ship, clock: () => 0 });
    radio.setup({ power: true, vol: 5, sql: 3, group: 'INT', channel: 10 });
    assert.equal(radio.hear({ channel: 10, group: 'ATIS', text: 'x' }).reason, 'group');
    radio.setup({ group: 'ATIS' });
    assert.equal(radio.hear({ channel: 10, group: 'ATIS', text: 'x' }).heard, true);
    assert.equal(radio.hear({ channel: 10, group: 'INT', text: 'x' }).reason, 'group');
});

test('lesson: Binnenmodus einstellen from INT to ATIS with the 1 W rule', () => {
    const r = rig(null, 'binnen-atis');
    r.press('power');
    r.combo('hl', 'dial');
    assert.equal(r.radio.state.group, 'ATIS');
    r.press('ch16');
    r.press('menu');
    r.to(10);
    r.press('hl');
    r.to(18);
    r.press('hl');
    assert.equal(r.run.status().done, true);
});

test('lesson: Verkehrskreise und Testsendung', () => {
    const r = rig(null, 'binnen-verkehrskreise');
    r.to(11);
    r.to(18);
    r.to(10);
    r.speak('Kormoran Delta Hotel Vier Sieben Zwo Eins Test');
    assert.equal(r.speech[0].stepIndex, 2);
});

test('lesson: Schleuse anrufen on the channel from the sign', () => {
    const r = rig(null, 'binnen-schleuse');
    r.to(20);
    r.speak('Schleuse Schleuse hier ist Kormoran Kormoran Schleusung kommen');
    assert.equal(r.speech[0].stepIndex, 1);
    r.run.speechResult(1, true);
    r.fire();
    assert.equal(r.voices.at(-1).heard, true);
    assert.equal(r.run.status().done, true);
});

test('lesson: Notmeldung needs channel 18 with 25 W, a call on channel 10 is a miss', () => {
    const r = rig(null, 'binnen-notmeldung');
    const misses = [];
    r.radio.pttDown(); r.radio.pttUp();
    const miss = r.run.handle({ type: 'spoken', channel: 10, group: 'ATIS', highPower: false, text: 'Mayday', radio: {} });
    misses.push(miss);
    assert.equal(misses[0].miss, true);
    assert.deepEqual(misses[0].expected, { channel: 18, group: 'ATIS', highPower: true });
    r.to(18);
    r.press('hl');
    r.speak('Mayday Mayday Mayday hier ist Kormoran');
    assert.equal(r.speech.length, 1);
});

test('lesson: Funkstille – die Revierzentrale, die den Notverkehr leitet, beendet ihn (SILENCE FINI)', () => {
    const r = rig(null, 'binnen-funkstille');
    r.fire();
    assert.equal(r.voices.at(-1).from, 'ALBATROS');
    r.speak('Albatros hier ist Kormoran Silence Mayday');
    r.run.speechResult(r.speech[0].stepIndex, true);
    r.speak('Revierzentrale Oberwesel hier ist Kormoran ich brauche keine Hilfe mehr kommen');
    assert.equal(r.speech[1].stepIndex, 2);
    r.run.speechResult(r.speech[1].stepIndex, true);
    r.fire();
    assert.equal(r.voices.at(-1).from, 'REVIERZENTRALE OBERWESEL');
    assert.match(r.voices.at(-1).text, /Silence fini/);
    assert.equal(r.run.status().done, true);
});

test('lesson: in the Nautische Information the Revierzentrale acknowledges', () => {
    const r = rig(null, 'binnen-notmeldung-nif');
    r.fire();
    r.fire();
    assert.deepEqual(r.voices.map((v) => v.from), ['ALBATROS', 'REVIERZENTRALE OBERWESEL']);
    assert.equal(r.run.status().done, true);
});

test('UBI exams start with the radio off in INT and only use inland lessons', () => {
    for (const series of ['ubi-voll', 'ubi-ergaenzung']) {
        assert.deepEqual(data.exam_meta[series].start, { power: false, vol: 5, sql: 3, group: 'INT', channel: 16, highPower: false });
        for (const key of data.exams[series]) assert.equal(lesson(key).catalog, 'UBI', key);
    }
});
