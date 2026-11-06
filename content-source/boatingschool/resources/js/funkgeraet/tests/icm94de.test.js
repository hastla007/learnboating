// IC-M94DE (Handfunkgerät, Variante des IC-M330): drei Softkeys auf Seiten,
// Ein/Aus-, VOL/SQL- und Sperrtaste, Menü mit Settings, nur Seefunk; die
// Lektionen auf dem Gerät.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { DEVICE_M94DE, PAGES_M94DE, createIcM94DE } from '../devices/icm330.js';
import { createLessonRun } from '../lessons.js';
import { deviceCatalogs, examLessons, lessonAvailable, lineage, prepareLesson } from '../devices/index.js';

const data = JSON.parse(execFileSync('php', ['-r', 'echo json_encode(require "database/data/geraetesimulation.php");'], { encoding: 'utf8' }));
const lesson = (key) => data.lessons.find((l) => l.key === key);

function play(key) {
    let now = 0;
    const events = [];
    const radio = createIcM94DE({ own: data.ship, clock: () => now, emit: (e) => events.push(e), addressBook: data.address_book, directory: data.directory });
    const timers = [];
    const voices = [];
    const prepared = prepareLesson(lesson(key), 'ic-m94de');
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
            assert.ok(pages, `Softkey ${label} nicht vorhanden (${radio.display().softkeys.join(', ')})`);
            const page = pages.labels.findIndex((l) => l.includes(label));
            assert.ok(page >= 0, `Softkey ${label} nicht vorhanden`);
            press(page < pages.current ? 'left' : 'right');
        }
        press(`soft${radio.display().softkeys.indexOf(label) + 1}`);
    };
    // Listeneintrag mit ▲/▼ wählen (Anfang genügt) und ENT.
    const pick = (item) => {
        const p = radio.display().panel;
        const index = p.items.findIndex((i) => i === item || i.startsWith(item));
        assert.ok(index >= 0, `${item} nicht in ${p.items.join(', ')}`);
        for (let i = p.cursor; i < index; i++) press('down');
        for (let i = p.cursor; i > index; i--) press('up');
        press('enter');
    };
    const type = (chars) => {
        for (const c of chars) {
            const target = radio.display().panel.editor.row.indexOf(c);
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

test('IC-M94DE: nur Seefunk (Binnenfunk am Handfunkgerät gesperrt), lessons and exam rules of the IC-M330 and IC-M323 (like chain)', () => {
    assert.deepEqual(lineage('ic-m94de'), ['ic-m94de', 'ic-m330', 'ic-m323']);
    assert.deepEqual(deviceCatalogs('ic-m94de'), ['SRC']);
    assert.ok(data.lessons.filter((l) => l.catalog === 'UBI').every((l) => ! lessonAvailable(l, 'ic-m94de')));
    assert.equal(lessonAvailable(lesson('testanruf'), 'ic-m94de'), true);
    assert.equal(lessonAvailable(lesson('position-polling'), 'ic-m94de'), false);
    const b = { lessons: data.exams.b, substitutes: data.exam_meta.b.substitutes };
    assert.ok(examLessons(b, 'ic-m94de').includes('testanruf'));
    // Texte des IC-M330 nennen das IC-M94DE; die Lektionen bleiben bei „25 W“
    // (Prüfung an der Feststation), die Hilfe nennt HI am Handfunkgerät.
    const coast = prepareLesson(lesson('kuestenfunkstelle'), 'ic-m94de').steps[0];
    assert.match(coast.text, /IC-M94DE/);
    const power = prepareLesson(lesson('sendeleistung'), 'ic-m94de');
    assert.match(power.title, /25 W/);
    assert.match(power.steps[3].help, /HI .*„25 W“/);
});

test('IC-M94DE lessons get help and targets that exist on the radio', () => {
    const contextLabels = ['EXIT', 'BACK', 'ENT', 'FIN', 'CALL', 'STBY', 'RESEND', 'ALARM OFF', 'IGNORE', 'PAUSE', 'ACCEPT', 'ABLE', 'UNABLE', 'NEW CH', 'CANCEL', 'CONTINUE', 'FINISH', 'TW'];
    const labels = new Set([...PAGES_M94DE.flat(), ...contextLabels]);
    const valid = new Set([...DEVICE_M94DE.keys, 'ptt']);
    for (const original of data.lessons.filter((l) => lessonAvailable(l, 'ic-m94de'))) {
        for (const [i, step] of prepareLesson(original, 'ic-m94de').steps.entries()) {
            const ok = step.target === null || (step.target.startsWith('sk:') ? labels.has(step.target.slice(3)) : valid.has(step.target));
            assert.ok(ok, `${original.key} Schritt ${i}: Ziel ${step.target}`);
            assert.ok(step.help, `${original.key} Schritt ${i}: keine Hilfe`);
            if (! step.speech) assert.doesNotMatch(step.help, /Drehknopf|\bDIAL\b|H\/L gedrückt|DSC Calls|am Hörer|Softkey DISTRESS|„Radio Settings“|„DSC Settings“/, `${original.key} Schritt ${i}: Hilfe eines anderen Geräts`);
        }
    }
});

test('IC-M94DE: power key, three softkeys on six pages, VOL/SQL with the arrows, monitor and key lock', () => {
    let now = 0;
    const events = [];
    const radio = createIcM94DE({ own: data.ship, clock: () => now, emit: (e) => events.push(e) });
    const press = (k, ms = 0) => { radio.keyDown(k); now += ms; radio.tick(); radio.keyUp(k); };
    press('power');
    assert.equal(radio.state.power, false, 'kurz drücken schaltet nicht ein');
    press('power', 1100);
    assert.equal(radio.state.power, true);
    assert.deepEqual(radio.display().softkeys, ['DTRS', 'OTHER DSC', 'UNREAD']);
    assert.equal(radio.display().status.power, 'HI');
    assert.equal(radio.display().mmsi, data.ship.mmsi);
    for (let i = 0; i < 8; i++) press('right');
    assert.deepEqual(radio.display().softkeys, ['LOG', '', '']);
    for (let i = 0; i < 8; i++) press('left');
    press('right');
    press('right');
    press('soft1');
    assert.equal(radio.display().status.power, 'LO');

    press('volsql');
    assert.equal(radio.display().window.label, 'Volume:');
    press('up');
    press('right');
    assert.equal(radio.state.vol, 4);
    press('volsql');
    assert.equal(radio.display().window.label, 'SQL:');
    press('up');
    press('up');
    assert.equal(radio.state.sql, 2);
    press('enter');
    assert.equal(radio.display().window, null);
    assert.equal(radio.state.channel, 16);
    press('up');
    assert.equal(radio.state.channel, 17, '▲ wählt ohne Fenster den Kanal');

    radio.keyDown('volsql');
    now += 1100;
    radio.tick();
    assert.equal(radio.squelch(), 'noise', 'Monitor öffnet die Rauschsperre');
    radio.keyUp('volsql');
    assert.equal(radio.squelch(), 'set');

    press('clr', 1100);
    assert.equal(radio.display().locked, true);
    press('menu');
    press('up');
    assert.equal(radio.state.screen, 'normal');
    assert.equal(radio.state.channel, 17);
    assert.equal(radio.pttDown(), true, 'die Sprechtaste geht auch gesperrt');
    radio.pttUp();
    press('clr', 1100);
    assert.equal(radio.display().locked, false);
    press('menu');
    assert.deepEqual(radio.display().panel.items.slice(0, 3), ['Compose Distress', 'Compose Other', 'Unread List']);
    assert.ok(events.some((e) => e.type === 'key_locked'));

    press('power', 1100);
    assert.equal(radio.state.power, false);
});

test('IC-M94DE: screens with more than three softkey functions page with ◄/►', () => {
    let now = 0;
    const events = [];
    const radio = createIcM94DE({ own: data.ship, clock: () => now, emit: (e) => events.push(e), addressBook: data.address_book });
    radio.setup({ power: true, vol: 5, sql: 3, channel: 16 });
    const press = (k) => { radio.keyDown(k); radio.keyUp(k); };
    const soft = (label) => press(`soft${radio.display().softkeys.indexOf(label) + 1}`);
    radio.receive({ type: 'individual', from: data.address_book[0], channel: 72, category: 'Routine' });
    soft('ALARM OFF');
    assert.deepEqual(radio.display().softkeys, ['IGNORE', 'ABLE', 'ACCEPT']);
    soft('ACCEPT');
    assert.deepEqual(radio.display().softkeys, ['STBY', 'ABLE', 'UNABLE']);
    assert.equal(radio.display().pages.count, 2);
    press('right');
    assert.deepEqual(radio.display().softkeys, ['NEW CH', '', '']);
    press('left');
    soft('UNABLE');
    soft('CALL');
    const sent = events.filter((e) => e.type === 'dsc_sent').at(-1);
    assert.equal(sent.kind, 'individual_ack');
    assert.equal(sent.action, 'unable');
});

test('IC-M94DE lessons: Gerät bedienen, Sendeleistung', () => {
    const p = play('geraet-bedienen');
    assert.equal(p.run.status().target, 'power');
    p.press('power', 1200);
    p.press('volsql');
    for (let i = 0; i < 5; i++) p.press('up');
    p.press('volsql');
    for (let i = 0; i < 10; i++) p.press('left');
    for (let i = 0; i < 3; i++) p.press('right');
    p.press('enter');
    assert.equal(p.run.status().index, 4);
    while (p.radio.state.channel !== 72) p.press('up');
    p.press('ch16');
    assert.equal(p.run.status().target, 'sk:CH');
    p.soft('CH');
    assert.equal(p.run.status().done, true);

    const s = play('sendeleistung');
    s.press('up');
    s.soft('HI/LO');
    while (s.radio.state.channel !== 72) s.press('up');
    s.soft('HI/LO');
    s.press('ch16');
    s.soft('HI/LO');
    assert.equal(s.run.status().done, true);
});

test('IC-M94DE lesson: MMSI, Position und Zeit (MENU > Settings > DSC > Position Input)', () => {
    const p = play('mmsi-position-zeit');
    p.press('menu');
    p.pick('Radio Info');
    p.press('menu');
    p.press('menu');
    p.pick('Settings');
    p.pick('DSC');
    p.pick('Position Input');
    p.type('5410');
    p.soft('FIN');
    p.type('00752');
    p.soft('FIN');
    assert.equal(p.run.status().index, 3);
    p.type('1230');
    p.soft('FIN');
    assert.equal(p.run.status().done, true);
});

test('IC-M94DE lesson: Einzelanruf with Compose Other, ALARM OFF sets the channel', () => {
    const p = play('einzelanruf');
    assert.equal(p.run.status().target, 'sk:OTHER DSC');
    p.soft('OTHER DSC');
    assert.deepEqual(p.radio.display().softkeys, ['EXIT', 'BACK', 'CALL']);
    assert.equal(p.radio.display().panel.title, 'COMPOSE OTHER');
    p.pick('Address');
    p.pick('MOEWE');
    p.pick('Channel');
    p.channel(72);
    p.soft('CALL');
    p.wait(1100);
    p.fire();
    p.soft('ALARM OFF');
    assert.equal(p.radio.state.channel, 72);
    assert.equal(p.run.status().index, 2);
});

test('IC-M94DE lessons: Notalarm senden (DTRS, DISTRESS on the back), Fehlalarm aufheben, Notalarm empfangen', () => {
    const p = play('notalarm-senden');
    assert.equal(p.run.status().target, 'sk:DTRS');
    p.soft('DTRS');
    assert.equal(p.radio.display().panel.title, 'COMPOSE DISTRESS');
    p.pick('Nature');
    p.pick('Flooding');
    p.pick('Position');
    p.type('5410');
    p.soft('FIN');
    p.type('00752');
    p.soft('FIN');
    p.type('1230');
    p.soft('FIN');
    p.press('distressCover');
    p.radio.keyDown('distress');
    p.wait(3100);
    p.radio.keyUp('distress');
    p.wait(1100);
    assert.equal(p.radio.state.screen, 'distressWait');
    p.fire();
    p.soft('ALARM OFF');
    assert.equal(p.run.status().done, true);

    const f = play('fehlalarm-aufheben');
    assert.equal(f.run.status().target, 'sk:CANCEL');
    f.soft('CANCEL');
    f.soft('CONTINUE');
    assert.equal(f.run.status().index, 1);
    f.speak('All stations all stations all stations this is Kormoran cancel my distress alert over');
    assert.equal(f.speech.length, 1);

    const r = play('notalarm-empfangen');
    r.fire();
    r.soft('ALARM OFF');
    assert.deepEqual(r.radio.display().softkeys, ['IGNORE', 'PAUSE', 'ACCEPT']);
    r.fire();
    r.soft('ACCEPT');
    assert.equal(r.radio.state.channel, 16);
    r.fire();
    assert.equal(r.voices.at(-1).heard, true);
});

test('IC-M94DE Gerätelogik ATIS (in der Bootsschule gesperrt): ATIS über Settings > Radio > Channel Group, kein Kanal 16, DSC gesperrt, 1 W, HI in der Nautischen Information', () => {
    const p = play('binnen-atis');
    p.press('power', 1200);
    assert.equal(p.run.status().target, 'menu');
    p.press('menu');
    p.pick('Settings');
    p.pick('Radio');
    p.pick('Channel Group');
    p.pick('ATIS');
    assert.equal(p.run.status().index, 2);
    p.press('menu');
    p.press('ch16');
    assert.equal(p.run.status().index, 3);
    p.press('menu');
    p.pick('Compose Other');
    assert.equal(p.run.status().index, 4);
    p.press('menu');
    while (p.radio.state.channel !== 10) p.press('up');
    p.soft('HI/LO');
    assert.equal(p.run.status().index, 6);
    while (p.radio.state.channel !== 18) p.press('up');
    p.soft('HI/LO');
    assert.equal(p.run.status().done, true);
    assert.equal(p.radio.receive({ type: 'distress', from: data.address_book[0] }), false, 'im Binnenfunk kein DSC-Empfang');
});
