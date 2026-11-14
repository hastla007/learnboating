// Stufe 5c: IC-M330 – Gerätelogik (Softkey-Seiten, OTHER DSC, DISTRESS,
// Empfang mit ABLE/ACPT und Kanalautomatik) und die Lektionen auf dem Gerät.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { DEVICE_M330, PAGES_M330, createIcM330 } from '../devices/icm330.js';
import { createLessonRun } from '../lessons.js';
import { examLessons, lessonAvailable, prepareLesson } from '../devices/index.js';

const data = JSON.parse(execFileSync('php', ['-r', 'echo json_encode(require "database/data/geraetesimulation.php");'], { encoding: 'utf8' }));
const lesson = (key) => data.lessons.find((l) => l.key === key);

function play(key) {
    let now = 0;
    const events = [];
    const radio = createIcM330({ own: data.ship, clock: () => now, emit: (e) => events.push(e), addressBook: data.address_book, directory: data.directory });
    const timers = [];
    const voices = [];
    const prepared = prepareLesson(lesson(key), 'ic-m330');
    const run = createLessonRun(prepared, { schedule: (fn) => timers.push(fn), receive: (c) => radio.receive(c), say: (v) => { const r = radio.hear(v); voices.push({ ...v, ...r }); return r; } });
    radio.setup(prepared.start);
    events.length = 0;
    const speech = [];
    const pump = () => { while (events.length) { const e = events.shift(); const p = run.handle(e); if (p && ! p.miss) speech.push(p); } };
    const wait = (ms) => { for (let t = 0; t < ms; t += 100) { now += 100; radio.tick(); } pump(); };
    const press = (k, ms = 100) => { radio.keyDown(k); wait(ms); radio.keyUp(k); pump(); };
    // Softkey drücken; liegt er auf einer anderen Seite, vorher blättern.
    const soft = (label) => {
        for (let i = 0; i < 8 && ! radio.display().softkeys.includes(label); i++) {
            const pages = radio.display().pages;
            const page = pages.labels.findIndex((l) => l.includes(label));
            assert.ok(page >= 0, `Softkey ${label} nicht vorhanden`);
            press(page < pages.current ? 'left' : 'right');
        }
        press(`soft${radio.display().softkeys.indexOf(label) + 1}`);
    };
    // Listeneintrag wählen (Anfang des Eintrags genügt) und ENT.
    const pick = (item) => {
        const p = radio.display().panel;
        const index = p.items.findIndex((i) => i === item || i.startsWith(item));
        assert.ok(index >= 0, `${item} nicht in ${p.items.join(', ')}`);
        radio.turn('dial', index - p.cursor);
        press('enter');
    };
    // Zeichen in der Eingabemaske nacheinander übernehmen.
    const type = (chars) => {
        for (const c of chars) {
            const e = radio.display().panel.editor;
            const target = e.row.indexOf(c);
            for (let i = 0; i < 20 && radio.display().panel.editor.sel !== target; i++) press('right');
            press('enter');
        }
    };
    const channel = (ch) => { for (let i = 0; i < 100 && radio.display().panel.lines[1].trim() !== `▲▼  ${String(ch).padStart(2, '0')}`; i++) press('up'); press('enter'); };
    const fire = () => { timers.splice(0).forEach((fn) => fn()); pump(); };
    const speak = (t) => { radio.pttDown(); radio.pttUp(); radio.spoken(t); pump(); };
    run.start();

    return { radio, run, press, soft, pick, type, channel, fire, speak, speech, voices, wait, pump, prepared, events };
}

test('IC-M330 lessons get help and targets that exist on the radio (keys, page softkeys or screen softkeys)', () => {
    const contextLabels = ['EXIT', 'BACK', 'ENT', 'FIN', 'CALL', 'STBY', 'RESEND', 'ALARM OFF', 'IGN', 'PAUSE', 'ACPT', 'ABLE', 'UNABLE', 'NEW CH', 'CANCEL', 'CONTINUE', 'FINISH', 'TW'];
    const labels = new Set([...PAGES_M330.flat(), ...contextLabels]);
    const valid = new Set([...DEVICE_M330.keys, ...DEVICE_M330.knobs, 'ptt']);
    for (const original of data.lessons.filter((l) => lessonAvailable(l, 'ic-m330'))) {
        for (const [i, step] of prepareLesson(original, 'ic-m330').steps.entries()) {
            const ok = step.target === null || (step.target.startsWith('sk:') ? labels.has(step.target.slice(3)) : valid.has(step.target));
            assert.ok(ok, `${original.key} Schritt ${i}: Ziel ${step.target}`);
            assert.ok(step.help, `${original.key} Schritt ${i}: keine Hilfe`);
            if (! step.speech) assert.doesNotMatch(step.help, /\bDIAL\b|H\/L gedrückt|Kanalwahlknopf|DSC Calls|IC-M323|VOL\/SQL • PWR/, `${original.key} Schritt ${i}: Hilfe eines anderen Geräts`);
        }
    }
});

test('IC-M330 inherits the IC-M323 lesson rules but uses its own texts', () => {
    assert.equal(lessonAvailable(lesson('position-polling'), 'ic-m330'), false);
    assert.equal(lessonAvailable(lesson('testanruf'), 'ic-m330'), true);
    const b = { lessons: data.exams.b, substitutes: data.exam_meta.b.substitutes };
    assert.ok(examLessons(b, 'ic-m330').includes('testanruf'));
    const coast = prepareLesson(lesson('kuestenfunkstelle'), 'ic-m330').steps[0];
    assert.match(coast.text, /IC-M330/);
    assert.equal(coast.target, 'sk:OTHER DSC');
});

test('IC-M330: softkey pages without wrap-around, knob windows, 16/C and the call channel', () => {
    let now = 0;
    const radio = createIcM330({ own: data.ship, clock: () => now });
    const press = (k, ms = 0) => { radio.keyDown(k); now += ms; radio.tick(); radio.keyUp(k); };
    press('knob', 1100);
    assert.equal(radio.state.power, true);
    assert.deepEqual(radio.display().softkeys, ['DISTRESS', 'DISTRESS', 'OTHER DSC', 'OTHER DSC']);
    press('left');
    assert.equal(radio.state.page, 0);
    press('right');
    assert.deepEqual(radio.display().softkeys, ['SCAN', 'DW', 'CHAN', 'HI/LO']);
    press('right');
    press('right');
    press('right');
    assert.deepEqual(radio.display().softkeys, ['LOG', '', '', '']);
    press('knob');
    assert.equal(radio.display().window.label, 'Volume:');
    press('knob');
    assert.equal(radio.display().window.label, 'SQL:');
    radio.turn('dial', 4);
    assert.equal(radio.state.sql, 4);
    now += 6000;
    radio.tick();
    radio.turn('dial', 3);
    assert.equal(radio.state.vol, 3);
    radio.setup({ channel: 72 });
    press('ch16', 1100);
    assert.equal(radio.state.channel, 9);
    assert.equal(radio.display().status.call, true);
    press('mic16');
    assert.equal(radio.state.channel, 16);
    press('micUp');
    assert.equal(radio.state.channel, 17);
});

test('IC-M330 lesson: Gerät bedienen', () => {
    const p = play('geraet-bedienen');
    p.press('knob', 1200);
    p.radio.turn('dial', 10);
    p.pump();
    while (p.radio.display().window?.label !== 'SQL:') p.press('knob');
    p.radio.turn('dial', -10);
    p.pump();
    p.radio.turn('dial', 3);
    p.pump();
    assert.equal(p.run.status().index, 4);
    while (p.radio.state.channel !== 72) p.press('up');
    p.press('ch16');
    assert.equal(p.run.status().target, 'sk:CHAN');
    p.soft('CHAN');
    assert.equal(p.run.status().done, true);
});

test('IC-M330 lessons: Anrufkanal, Kanal 70, Sendeleistung', () => {
    const k = play('kanaele-anrufkanal');
    k.press('ch16', 1200);
    k.soft('CHAN');
    assert.equal(k.radio.state.channel, 6);
    while (k.radio.state.channel !== 70) k.press('up');
    k.radio.pttDown();
    k.pump();
    k.press('ch16');
    assert.equal(k.run.status().done, true);

    const s = play('sendeleistung');
    s.press('up');
    s.soft('HI/LO');
    while (s.radio.state.channel !== 72) s.press('up');
    s.soft('HI/LO');
    s.press('ch16');
    s.soft('HI/LO');
    assert.equal(s.run.status().done, true);
});

test('IC-M330 lesson: MMSI, Position und Zeit with the character row', () => {
    const p = play('mmsi-position-zeit');
    p.press('menu');
    p.pick('Radio Info');
    p.press('menu');
    p.press('menu');
    p.pick('DSC Settings');
    p.pick('Position Input');
    p.type('5410');
    p.press('soft4');
    p.type('00752');
    p.press('soft4');
    assert.equal(p.run.status().index, 3);
    p.type('1230');
    p.press('soft4');
    assert.equal(p.run.status().done, true);
});

test('IC-M330 lessons: Zweikanalwache (DW, then TW after the menu), Suchlauf mit Favoriten', () => {
    const w = play('zweikanalwache');
    w.soft('DW');
    w.fire();
    assert.equal(w.voices.at(-1).heard, true);
    w.soft('DW');
    w.press('menu');
    w.pick('Radio Settings');
    w.pick('Dual/Tri-watch');
    w.pick('Tri-watch');
    w.press('menu');
    w.soft('TW');
    assert.equal(w.run.status().done, true);

    const s = play('suchlauf-tag');
    s.soft('★');
    while (s.radio.state.channel !== 72) s.press('up');
    s.soft('★');
    while (s.radio.state.channel !== 77) s.press('up');
    s.soft('★');
    s.soft('SCAN');
    s.fire();
    assert.equal(s.voices.at(-1).heard, true);
    s.press('ch16');
    assert.equal(s.run.status().done, true);
});

test('IC-M330 lesson: Einzelanruf with OTHER DSC, ALARM OFF sets the channel', () => {
    const p = play('einzelanruf');
    assert.equal(p.run.status().target, 'sk:OTHER DSC');
    p.soft('OTHER DSC');
    assert.deepEqual(p.radio.display().softkeys, ['EXIT', 'BACK', '', 'CALL']);
    p.pick('Address');
    p.pick('MOEWE');
    p.pick('Channel');
    p.channel(72);
    p.soft('CALL');
    p.wait(1100);
    assert.equal(p.radio.display().panel.lines[0], 'Waiting for ACK');
    p.fire();
    p.soft('ALARM OFF');
    assert.equal(p.radio.state.channel, 72);
    assert.equal(p.run.status().index, 2);
});

test('IC-M330: CALL without an address does not transmit', () => {
    const p = play('einzelanruf');
    p.soft('OTHER DSC');
    p.soft('CALL');
    assert.equal(p.radio.state.screen, 'dscForm');
    assert.equal(p.run.status().index, 0);
});

test('IC-M330 lesson: Einzelanruf annehmen – ABLE answers at once and selects the channel', () => {
    const p = play('einzelanruf-annehmen');
    p.fire();
    p.soft('ALARM OFF');
    assert.deepEqual(p.radio.display().softkeys, ['IGN', 'ABLE', '', 'ACPT']);
    p.soft('ABLE');
    p.wait(1100);
    assert.equal(p.radio.state.channel, 72);
    p.fire();
    assert.equal(p.voices.at(-1).heard, true);
});

test('IC-M330: ACPT on an individual call, then UNABLE via the form', () => {
    let now = 0;
    const events = [];
    const radio = createIcM330({ own: data.ship, clock: () => now, emit: (e) => events.push(e), addressBook: data.address_book });
    radio.setup({ power: true, vol: 5, sql: 3, channel: 16 });
    const soft = (label) => { const i = radio.display().softkeys.indexOf(label); radio.keyDown(`soft${i + 1}`); radio.keyUp(`soft${i + 1}`); };
    radio.receive({ type: 'individual', from: data.address_book[0], channel: 72, category: 'Routine' });
    soft('ALARM OFF');
    soft('ACPT');
    assert.deepEqual(radio.display().softkeys, ['STBY', 'ABLE', 'UNABLE', 'NEW CH']);
    soft('UNABLE');
    soft('CALL');
    const sent = events.filter((e) => e.type === 'dsc_sent').at(-1);
    assert.equal(sent.kind, 'individual_ack');
    assert.equal(sent.action, 'unable');
    assert.equal(sent.channel, null);
});

test('IC-M330 lessons: Küstenfunkstelle (no channel field, ACK sets 26), Gruppenanruf, Testanruf', () => {
    const k = play('kuestenfunkstelle');
    k.soft('OTHER DSC');
    k.pick('Address');
    k.pick('NORDSEE RADIO');
    assert.ok(! k.radio.display().panel.items.some((i) => i.startsWith('Channel')));
    k.soft('CALL');
    k.wait(1100);
    k.fire();
    k.soft('ALARM OFF');
    assert.equal(k.radio.state.channel, 26);
    assert.equal(k.run.status().index, 2);

    const g = play('gruppenanruf');
    g.soft('OTHER DSC');
    g.pick('Type');
    g.pick('Group');
    g.pick('Address');
    g.pick('FLOTILLE');
    g.pick('Channel');
    g.channel(69);
    g.soft('CALL');
    g.wait(1100);
    assert.equal(g.radio.state.channel, 69);
    assert.equal(g.run.status().index, 2);

    const t = play('testanruf');
    t.soft('OTHER DSC');
    t.pick('Type');
    t.pick('Test');
    t.pick('Address');
    t.pick('MOEWE');
    t.soft('CALL');
    t.wait(1100);
    t.fire();
    t.soft('ALARM OFF');
    assert.equal(t.run.status().done, true);
    t.soft('STBY');
    t.soft('OK');
    assert.equal(t.radio.state.screen, 'normal');
});

test('IC-M330 lesson: Adressbuch – ADD, then call the new station', () => {
    const p = play('adressbuch');
    p.press('menu');
    p.pick('DSC Settings');
    p.pick('Individual ID');
    p.soft('ADD');
    p.type('211555000');
    p.soft('FIN');
    assert.equal(p.run.status().index, 1);
    p.press('menu');
    p.soft('OTHER DSC');
    p.pick('Address');
    p.pick('SEESTERN');
    p.pick('Channel');
    p.channel(77);
    p.soft('CALL');
    p.wait(1100);
    p.fire();
    p.soft('ALARM OFF');
    assert.equal(p.radio.state.channel, 77);
    assert.equal(p.run.status().done, true);
});

test('IC-M330 lesson: Notalarm senden via the DISTRESS screen (Nature, Position), ACK selects 16', () => {
    const p = play('notalarm-senden');
    assert.equal(p.run.status().target, 'sk:DISTRESS');
    p.soft('DISTRESS');
    p.pick('Nature');
    p.pick('Flooding');
    p.pick('Position');
    p.type('5410');
    p.soft('FIN');
    p.type('00752');
    p.soft('FIN');
    p.type('1230');
    p.soft('FIN');
    assert.equal(p.radio.state.screen, 'distressMenu');
    p.press('distressCover');
    p.radio.keyDown('distress');
    p.wait(3100);
    p.radio.keyUp('distress');
    p.wait(1100);
    assert.equal(p.radio.state.screen, 'distressWait');
    assert.equal(p.radio.state.channel, 16);
    p.fire();
    p.soft('ALARM OFF');
    assert.equal(p.run.status().done, true);
    assert.deepEqual(p.radio.display().softkeys, ['STBY', 'INFO', 'HIST', '']);
});

test('IC-M330 lesson: Fehlalarm aufheben – CANCEL, CONTINUE transmits the DSC cancel', () => {
    const p = play('fehlalarm-aufheben');
    assert.equal(p.run.status().target, 'sk:CANCEL');
    p.soft('CANCEL');
    p.soft('CONTINUE');
    assert.equal(p.run.status().index, 1);
    assert.equal(p.radio.state.channel, 16);
    p.speak('All stations all stations all stations this is Kormoran cancel my distress alert over');
    assert.equal(p.speech.length, 1);
    p.soft('FINISH');
    p.soft('STBY');
    assert.equal(p.radio.state.screen, 'normal');
});

test('IC-M330 lesson: Notalarm empfangen – ACPT selects 16 at once; CH Auto SW switches after 10 s', () => {
    const p = play('notalarm-empfangen');
    p.fire();
    p.soft('ALARM OFF');
    assert.deepEqual(p.radio.display().softkeys, ['IGN', 'PAUSE', '', 'ACPT']);
    assert.equal(p.run.status().index, 1);
    p.fire();
    assert.equal(p.voices.at(-1).heard, false);
    p.soft('ACPT');
    assert.equal(p.radio.state.channel, 16);
    p.fire();
    assert.equal(p.voices.at(-1).heard, true);

    const auto = play('notalarm-empfangen');
    auto.fire();
    auto.soft('ALARM OFF');
    auto.soft('PAUSE');
    auto.wait(12000);
    assert.equal(auto.radio.state.channel, 72);
    auto.soft('RESUME');
    auto.wait(10100);
    assert.equal(auto.radio.state.channel, 16);
});

test('IC-M330 lessons: Dringlichkeit, MEDICO (all ships, Urgency), Dringlichkeit empfangen, Gebietsanruf', () => {
    const d = play('dringlichkeit');
    d.soft('OTHER DSC');
    d.pick('Type');
    d.pick('All Ships');
    d.pick('Category');
    d.pick('Urgency');
    d.soft('CALL');
    d.wait(1100);
    assert.equal(d.run.status().index, 1);

    const m = play('medico');
    m.soft('OTHER DSC');
    m.pick('Type');
    m.pick('All Ships');
    m.pick('Category');
    m.pick('Urgency');
    m.soft('CALL');
    m.wait(1100);
    assert.equal(m.run.status().index, 2);

    const r = play('dringlichkeit-empfangen');
    r.fire();
    r.soft('ALARM OFF');
    r.soft('ACPT');
    assert.equal(r.radio.state.channel, 16);
    r.fire();
    assert.equal(r.run.status().done, true);

    const g = play('gebietsanruf');
    g.fire();
    g.soft('ALARM OFF');
    g.soft('ACPT');
    assert.equal(g.radio.state.channel, 26);
    assert.equal(g.run.status().index, 2);
});

test('IC-M330 lesson: Binnenfunk – ATIS group, no channel 16, DSC blocked', () => {
    const p = play('binnen-atis');
    p.press('knob', 1200);
    p.press('menu');
    p.pick('Radio Settings');
    p.pick('Channel Group');
    p.pick('ATIS');
    p.press('menu');
    p.press('ch16');
    assert.equal(p.run.status().index, 3);
    p.press('menu');
    p.pick('Other DSC');
    assert.equal(p.run.status().index, 4);
    p.press('menu');
    for (let i = 0; i < 80 && p.radio.state.channel !== 10; i++) p.press('up');
    p.soft('HI/LO');
    while (p.radio.state.channel !== 18) p.press('up');
    p.soft('HI/LO');
    assert.equal(p.run.status().done, true);
});
