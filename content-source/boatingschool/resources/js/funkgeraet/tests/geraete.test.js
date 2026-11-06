// Stufe 4: Lektionen auf dem IC-M323 – Ausblendung, Anpassungen und
// automatische Schritt-Hilfen; ausgewählte Lektionen durchgespielt.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { DEVICE, DEVICE_M423, createIcM323, createIcM423 } from '../devices/icm323.js';
import { DEVICE as DEVICE_503, createIcM503 } from '../devices/icm503.js';
import { createLessonRun } from '../lessons.js';
import { examLessons, lessonAvailable, prepareLesson } from '../devices/index.js';

const data = JSON.parse(execFileSync('php', ['-r', 'echo json_encode(require "database/data/geraetesimulation.php");'], { encoding: 'utf8' }));
const lesson = (key) => data.lessons.find((l) => l.key === key);

for (const [deviceKey, device] of [['ic-m323', DEVICE], ['ic-m423', DEVICE_M423], ['ic-m503', DEVICE_503]]) test(`every lesson available on the ${deviceKey} gets help and a target that exists on that radio`, () => {
    const valid = new Set([...device.keys, ...device.knobs, 'ptt']);
    for (const original of data.lessons.filter((l) => lessonAvailable(l, deviceKey))) {
        for (const [i, step] of prepareLesson(original, deviceKey).steps.entries()) {
            const ok = step.target === null || step.target.startsWith('sk:') || valid.has(step.target);
            assert.ok(ok, `${original.key} Schritt ${i}: Ziel ${step.target}`);
            assert.ok(step.help, `${original.key} Schritt ${i}: keine Hilfe`);
            if (! step.speech && deviceKey !== 'ic-m503') assert.doesNotMatch(step.help, /\bDIAL\b|H\/L gedrückt|Kanalwahlknopf/, `${original.key} Schritt ${i}: Hilfe des IC-M505`);
            if (! step.speech && deviceKey === 'ic-m503') assert.doesNotMatch(step.help, /\bMENU\b|16\/C|Kanalwahlknopf|Softkey/, `${original.key} Schritt ${i}: Hilfe eines anderen Geräts`);
        }
    }
});

test('position request and polling are hidden on the IC-M323, the test call only exists there; series b substitutes it', () => {
    assert.equal(lessonAvailable(lesson('position-polling'), 'ic-m323'), false);
    assert.equal(lessonAvailable(lesson('testanruf'), 'ic-m505'), false);
    const b = { lessons: data.exams.b, substitutes: data.exam_meta.b.substitutes };
    assert.ok(examLessons(b, 'ic-m323').includes('testanruf'));
    assert.ok(! examLessons(b, 'ic-m323').includes('position-polling'));
    assert.deepEqual(examLessons(b, 'ic-m505'), data.exams.b);
});

function play(key) {
    let now = 0;
    const events = [];
    const radio = createIcM323({ own: data.ship, clock: () => now, emit: (e) => events.push(e), addressBook: data.address_book, directory: data.directory });
    const timers = [];
    const voices = [];
    const prepared = prepareLesson(lesson(key), 'ic-m323');
    const run = createLessonRun(prepared, { schedule: (fn) => timers.push(fn), receive: (c) => radio.receive(c), say: (v) => { const r = radio.hear(v); voices.push({ ...v, ...r }); return r; } });
    radio.setup(prepared.start);
    events.length = 0;
    const speech = [];
    const pump = () => { while (events.length) { const e = events.shift(); const p = run.handle(e); if (p && ! p.miss) speech.push(p); } };
    const wait = (ms) => { for (let t = 0; t < ms; t += 100) { now += 100; radio.tick(); } pump(); };
    const press = (k, ms = 100) => { radio.keyDown(k); wait(ms); radio.keyUp(k); pump(); };
    const soft = (label) => press(`soft${radio.display().softkeys.indexOf(label) + 1}`);
    const pick = (item) => { const p = radio.display().panel; radio.turn('dial', p.items.indexOf(item) - p.cursor); press('enter'); };
    const fire = () => { timers.splice(0).forEach((fn) => fn()); pump(); };
    const speak = (t) => { radio.pttDown(); radio.pttUp(); radio.spoken(t); pump(); };
    run.start();

    return { radio, run, press, soft, pick, fire, speak, speech, voices, wait, pump, prepared };
}

test('IC-M323 lesson: Kanal 16, Anrufkanal und Kanal 70', () => {
    const p = play('kanaele-anrufkanal');
    p.press('ch16', 1200);
    p.soft('CHAN');
    while (p.radio.state.channel !== 70) p.radio.turn('dial', 0), p.press('up');
    p.radio.pttDown();
    p.pump();
    p.press('ch16');
    assert.equal(p.run.status().done, true);
});

test('IC-M323 lesson: Fehlalarm aufheben with the DSC cancel', () => {
    const p = play('fehlalarm-aufheben');
    assert.equal(p.run.status().target, 'sk:CANCEL');
    p.soft('CANCEL');
    p.soft('CONTINUE');
    p.soft('FINISH');
    assert.equal(p.run.status().index, 1);
    p.speak('All stations all stations all stations this is Kormoran cancel my distress alert over');
    assert.equal(p.speech.length, 1);
});

test('IC-M323 lesson: MEDICO announces urgency with an all ships call', () => {
    const p = play('medico');
    p.press('menu');
    p.pick('DSC Calls');
    p.pick('All Ships Call');
    p.pick('Urgency');
    p.press('enter');
    p.soft('CALL');
    p.wait(1100);
    assert.equal(p.run.status().index, 2);
    p.speak('Pan pan Bremen Rescue Radio this is Kormoran medical advice over');
    assert.equal(p.speech[0].stepIndex, 2);
});

test('IC-M323 lesson: Küstenfunkstelle – the coast station sets channel 26', () => {
    const p = play('kuestenfunkstelle');
    p.press('menu');
    p.pick('DSC Calls');
    p.pick('Individual Call');
    p.pick('NORDSEE RADIO');
    p.soft('CALL');
    p.wait(1100);
    p.fire();
    p.soft('ALARM OFF');
    assert.equal(p.radio.state.channel, 26);
    assert.equal(p.run.status().index, 2);
});

test('IC-M323 lesson: Notalarm empfangen – ACPT before the distress message on 16 can be heard', () => {
    const p = play('notalarm-empfangen');
    p.fire();
    p.soft('ALARM OFF');
    assert.equal(p.run.status().index, 1);
    p.fire();
    assert.equal(p.voices.at(-1).heard, false);
    p.soft('ACPT');
    p.soft('CH 16');
    p.fire();
    assert.equal(p.voices.at(-1).heard, true);
});

test('IC-M323 lesson: Testanruf', () => {
    const p = play('testanruf');
    p.press('menu');
    p.pick('DSC Calls');
    p.pick('Test Call');
    p.pick('MOEWE');
    p.soft('CALL');
    p.wait(1100);
    p.fire();
    p.soft('ALARM OFF');
    assert.equal(p.run.status().done, true);
});

function play503(key) {
    let now = 0;
    const events = [];
    const radio = createIcM503({ own: data.ship, clock: () => now, emit: (e) => events.push(e), addressBook: data.address_book, directory: data.directory });
    const timers = [];
    const voices = [];
    const prepared = prepareLesson(lesson(key), 'ic-m503');
    const run = createLessonRun(prepared, { schedule: (fn) => timers.push(fn), receive: (c) => radio.receive(c), say: (v) => { const r = radio.hear(v); voices.push({ ...v, ...r }); return r; } });
    radio.setup(prepared.start);
    events.length = 0;
    const speech = [];
    const pump = () => { while (events.length) { const e = events.shift(); const p = run.handle(e); if (p && ! p.miss) speech.push(p); } };
    const wait = (ms) => { for (let t = 0; t < ms; t += 100) { now += 100; radio.tick(); } pump(); };
    const press = (k, ms = 100) => { radio.keyDown(k); wait(ms); radio.keyUp(k); pump(); };
    const pick = (item) => { for (let i = 0; i < 20 && radio.display().ds.items[radio.display().ds.cursor] !== item; i++) press('dsDown'); press('dsEnt'); };
    const send = () => { press('dsCall'); press('dsEnt'); };
    const fire = () => { timers.splice(0).forEach((fn) => fn()); pump(); };
    const speak = (t) => { radio.pttDown(); radio.pttUp(); radio.spoken(t); pump(); };
    run.start();

    return { radio, run, press, pick, send, fire, speak, speech, voices, wait };
}

test('IC-M503 lesson: Einzelanruf with CALL+ENT and ENT on the acknowledgement', () => {
    const p = play503('einzelanruf');
    assert.equal(p.run.status().target, 'dsCall');
    p.press('dsCall');
    p.pick('Individual call');
    p.pick('MOEWE');
    p.pick('Routine');
    while (p.radio.display().ds.lines[1].trim() !== 'CH 72') p.press('dsUp');
    p.press('dsEnt');
    p.send();
    p.wait(1600);
    assert.equal(p.run.status().target, 'dsEnt');
    p.fire();
    p.press('dsClr');
    p.press('dsEnt');
    assert.equal(p.radio.state.channel, 72);
    assert.equal(p.run.status().index, 2);
});

test('IC-M503 lesson: Notalarm senden with the DS-100', () => {
    const p = play503('notalarm-senden');
    p.press('dsCall');
    p.pick('Distress setting');
    p.pick('Flooding');
    for (const d of '541000752') p.press(`d${d}`);
    p.press('dsEnt');
    for (const d of '1230') p.press(`d${d}`);
    p.press('dsEnt');
    p.press('distressCover');
    p.radio.keyDown('distress');
    p.wait(5100);
    p.radio.keyUp('distress');
    p.wait(1600);
    assert.equal(p.radio.state.channel, 16);
    p.fire();
    p.press('dsClr');
    assert.equal(p.run.status().done, true);
});

test('IC-M503 lessons: Fehlalarm (CLR sends the cancel), Küstenfunkstelle (coast station sets channel 26)', () => {
    const f = play503('fehlalarm-aufheben');
    f.press('dsClr');
    assert.equal(f.run.status().index, 1);

    const k = play503('kuestenfunkstelle');
    k.press('dsCall');
    k.pick('Individual call');
    k.pick('NORDSEE RADIO');
    k.pick('Routine');
    k.send();
    k.wait(1600);
    k.fire();
    k.press('dsClr');
    k.press('dsEnt');
    assert.equal(k.radio.state.channel, 26);
    assert.equal(k.run.status().index, 2);
});

test('IC-M503 lesson: Zwei- und Dreikanalwache with the Set mode', () => {
    const p = play503('zweikanalwache');
    p.press('dual');
    p.fire();
    assert.equal(p.voices.at(-1).heard, true);
    p.press('dual');
    p.press('power');
    p.radio.keyDown('ch16');
    p.press('power');
    p.radio.keyUp('ch16');
    p.press('ch16');
    p.press('ch16');
    p.radio.turn('channel', 1);
    p.wait(100);
    p.press('power');
    p.press('dual');
    assert.equal(p.run.status().done, true);
});

test('IC-M503: position request lesson hidden, series b uses Küstenfunkstelle instead', () => {
    assert.equal(lessonAvailable(lesson('position-polling'), 'ic-m503'), false);
    assert.equal(lessonAvailable(lesson('testanruf'), 'ic-m503'), false);
    const b = { lessons: data.exams.b, substitutes: data.exam_meta.b.substitutes };
    assert.ok(examLessons(b, 'ic-m503').includes('kuestenfunkstelle'));
});

test('IC-M423: CH/WX instead of CHAN, longer softkey ring, 16/C on the handset; inherits IC-M323 lesson rules', () => {
    let now = 0;
    const events = [];
    const radio = createIcM423({ own: data.ship, clock: () => now, emit: (e) => events.push(e), addressBook: data.address_book });
    radio.setup({ power: true, vol: 5, sql: 3, channel: 72 });
    const press = (k) => { radio.keyDown(k); radio.keyUp(k); };
    assert.deepEqual(radio.display().softkeys, ['SCAN', 'DW', 'HI/LO', 'CH/WX']);
    press('mic16');
    assert.equal(radio.state.channel, 16);
    press('soft4');
    assert.equal(radio.state.channel, 72);
    press('right');
    assert.deepEqual(radio.display().softkeys, ['DW', 'HI/LO', 'CH/WX', 'PA']);

    assert.equal(lessonAvailable(lesson('testanruf'), 'ic-m423'), true);
    assert.equal(lessonAvailable(lesson('position-polling'), 'ic-m423'), false);
    const b = { lessons: data.exams.b, substitutes: data.exam_meta.b.substitutes };
    assert.ok(examLessons(b, 'ic-m423').includes('testanruf'));
    const back = prepareLesson(lesson('geraet-bedienen'), 'ic-m423').steps.at(-1);
    assert.equal(back.target, 'sk:CH/WX');
    const medico = prepareLesson(lesson('medico'), 'ic-m423').steps[0];
    assert.equal(medico.goal.kind, 'all_ships');
    assert.ok(createIcM323);
});

// Review der Gerätelogik: Regressionstests über alle Geräte.
import { createIcM330 } from '../devices/icm330.js';
import { createIcM505 } from '../devices/icm505.js';
import { stepIntership } from '../channels.js';

const FACTORIES = { 'ic-m505': createIcM505, 'ic-m323': createIcM323, 'ic-m503': createIcM503, 'ic-m330': createIcM330 };
function rig(deviceKey, start = {}) {
    let now = 0;
    const events = [];
    const radio = FACTORIES[deviceKey]({ own: data.ship, clock: () => now, emit: (e) => events.push(e), addressBook: data.address_book, directory: data.directory });
    radio.setup({ power: true, vol: 5, sql: 3, channel: 16, position: [54, 10, 'N', 7, 52, 'E'], utc: '12:30', ...start });
    const wait = (ms) => { for (let t = 0; t < ms; t += 100) { now += 100; radio.tick(); } };
    const press = (k, ms = 100) => { radio.keyDown(k); wait(ms); radio.keyUp(k); };

    return { radio, events, wait, press };
}

for (const deviceKey of Object.keys(FACTORIES)) {
    test(`${deviceKey}: switched off, a waiting distress alert is not repeated; DSC is ignored in ATIS; a call does not break the DISTRESS hold`, () => {
        const off = rig(deviceKey, { distressWaiting: 'Undesignated' });
        const powerKey = deviceKey === 'ic-m505' || deviceKey === 'ic-m503' ? 'power' : 'knob';
        off.press(powerKey, 1200);
        assert.equal(off.radio.state.power, false);
        off.events.length = 0;
        off.wait(5 * 60 * 1000);
        assert.equal(off.events.filter((e) => e.type === 'dsc_sent').length, 0);

        const atis = rig(deviceKey, { group: 'ATIS', channel: 10 });
        assert.equal(atis.radio.receive({ type: 'distress', from: { name: 'SEESTERN', mmsi: '211555000' } }), false);
        assert.equal(atis.radio.state.channel, 10);

        const hold = rig(deviceKey);
        hold.press('distressCover');
        hold.radio.keyDown('distress');
        hold.wait(1000);
        hold.radio.receive({ type: 'all_ships', from: data.address_book[1], channel: 16, category: 'Urgency' });
        hold.wait(6000);
        hold.radio.keyUp('distress');
        assert.equal(hold.events.filter((e) => e.type === 'dsc_sent' && e.kind === 'distress').length, 1);
    });
}

test('DSC traffic channels skip channel 70 and duplex channels', () => {
    assert.equal(stepIntership(69, 1), 71);
    assert.equal(stepIntership(17, 1), 67);
});

test('coast stations allow duplex traffic channels (IC-M505 Küstenfunkstelle, channel 26); off-list start moves to the next channel', () => {
    assert.equal(stepIntership(25, 1, { coast: true }), 26);
    assert.equal(stepIntership(26, 1), 67);
    assert.equal(stepIntership(26, -1), 17);
    // Vom IC-M323 übernommene Texte nennen das eigene Gerät.
    assert.match(prepareLesson(lesson('kuestenfunkstelle'), 'ic-m423').steps[0].text, /IC-M423/);
    assert.doesNotMatch(prepareLesson(lesson('kuestenfunkstelle'), 'ic-m423').steps[0].help, /IC-M323/);
    assert.match(prepareLesson(lesson('kuestenfunkstelle'), 'ic-m330').steps[0].help, /NORDSEE RADIO/);
});
