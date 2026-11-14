// Standard Horizon HX891BT/E: eigene Gerätelogik (MENU/SET, DSC CALL mit
// HISTORY/MEMORY/NEW ID, YES/NO, Alarm aus mit beliebiger Taste), nur
// Seefunk; die Lektionen auf dem Gerät.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { DEVICE_HX891, PAGES_HX, createHx891 } from '../devices/hx891.js';
import { createLessonRun } from '../lessons.js';
import { deviceCatalogs, examLessons, lessonAvailable, prepareLesson } from '../devices/index.js';

const data = JSON.parse(execFileSync('php', ['-r', 'echo json_encode(require "database/data/geraetesimulation.php");'], { encoding: 'utf8' }));
const lesson = (key) => data.lessons.find((l) => l.key === key);

function play(key) {
    let now = 0;
    const events = [];
    const radio = createHx891({ own: data.ship, clock: () => now, emit: (e) => events.push(e), addressBook: data.address_book, directory: data.directory });
    const timers = [];
    const voices = [];
    const prepared = prepareLesson(lesson(key), 'hx891bte');
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
            assert.ok(radio.display().pages, `Softkey ${label} nicht vorhanden (${radio.display().softkeys.join(', ')})`);
            press('right');
        }
        assert.ok(radio.display().softkeys.includes(label), `Softkey ${label} nicht gefunden`);
        press(`soft${radio.display().softkeys.indexOf(label) + 1}`);
    };
    // Listeneintrag mit CH▲/CH▼ wählen (Anfang genügt) und SELECT.
    const pick = (item) => {
        const p = radio.display().panel;
        const index = p.items.findIndex((i) => i === item || i.startsWith(item));
        assert.ok(index >= 0, `${item} nicht in ${p.items.join(', ')}`);
        for (let i = p.cursor; i < index; i++) press('down');
        for (let i = p.cursor; i > index; i--) press('up');
        soft('SELECT');
    };
    const type = (chars) => {
        for (const c of chars) {
            const target = radio.display().panel.editor.row.indexOf(c);
            for (let i = 0; i < 20 && radio.display().panel.editor.sel !== target; i++) press('right');
            soft('SELECT');
        }
    };
    const fire = () => { timers.splice(0).forEach((fn) => fn()); pump(); };
    const speak = (t) => { radio.pttDown(); radio.pttUp(); radio.spoken(t); pump(); };
    run.start();

    return { radio, run, press, soft, pick, type, fire, speak, speech, voices, wait, pump, prepared, events };
}

test('HX891BT/E: nur Seefunk (Binnenfunk am Handfunkgerät gesperrt), position request and test call available, own lesson texts', () => {
    assert.deepEqual(deviceCatalogs('hx891bte'), ['SRC']);
    assert.ok(data.lessons.filter((l) => l.catalog === 'UBI').every((l) => ! lessonAvailable(l, 'hx891bte')));
    assert.equal(lessonAvailable(lesson('position-polling'), 'hx891bte'), true);
    assert.equal(lessonAvailable(lesson('testanruf'), 'hx891bte'), true);
    const b = { lessons: data.exams.b, substitutes: data.exam_meta.b.substitutes };
    assert.deepEqual(examLessons(b, 'hx891bte'), data.exams.b);
    assert.match(prepareLesson(lesson('kuestenfunkstelle'), 'hx891bte').steps[0].text, /HX891BT\/E/);
    const power = prepareLesson(lesson('sendeleistung'), 'hx891bte');
    assert.match(power.title, /25 W/);
    assert.match(power.steps[3].help, /HI .*„25 W“/);
});

test('HX891BT/E lessons get help and targets that exist on the radio', () => {
    const contextLabels = ['BACK', 'SELECT', 'FINISH', 'POS/TM', 'NATURE', 'YES', 'NO', 'QUIT', 'STOP ALARM', 'ACCEPT', 'PAUSE', 'ABLE', 'UNABLE', 'CANCEL', 'OK', 'TRIPLE WATCH'];
    const labels = new Set([...PAGES_HX.flat(), ...contextLabels]);
    const valid = new Set([...DEVICE_HX891.keys]);
    for (const original of data.lessons.filter((l) => lessonAvailable(l, 'hx891bte'))) {
        for (const [i, step] of prepareLesson(original, 'hx891bte').steps.entries()) {
            const ok = step.target === null || (step.target.startsWith('sk:') ? labels.has(step.target.slice(3)) : valid.has(step.target));
            assert.ok(ok, `${original.key} Schritt ${i}: Ziel ${step.target}`);
            assert.ok(step.help, `${original.key} Schritt ${i}: keine Hilfe`);
            if (! step.speech) assert.doesNotMatch(step.help, /Drehknopf|\bDIAL\b|H\/L|ENTER|\bENT\b|16\/C|Softkey DISTRESS|OTHER DSC|„Individual Call“/, `${original.key} Schritt ${i}: Hilfe eines anderen Geräts`);
        }
    }
});

test('HX891BT/E: side keys, softkey pages with wrap-around, 16/S, sub channel, VOL and SQL, key lock', () => {
    let now = 0;
    const events = [];
    const radio = createHx891({ own: data.ship, clock: () => now, emit: (e) => events.push(e) });
    const press = (k, ms = 0) => { radio.keyDown(k); now += ms; radio.tick(); radio.keyUp(k); };
    press('power');
    assert.equal(radio.state.power, false);
    press('power', 1100);
    assert.equal(radio.state.power, true);
    assert.deepEqual(radio.display().softkeys, ['PRESET', 'DUAL WATCH', 'FM RADIO']);
    press('left');
    assert.deepEqual(radio.display().softkeys, ['STROBE', 'LOGGER', 'WPT MARK']);
    press('right');
    press('right');
    press('soft1');
    assert.equal(radio.display().status.power, 'MD');
    press('volUp');
    press('volUp');
    assert.equal(radio.state.vol, 2);
    assert.equal(radio.display().window.label, 'VOL');
    press('sql');
    press('down');
    press('down');
    press('down');
    assert.equal(radio.state.sql, 0);
    assert.equal(radio.squelch(), 'noise');
    press('up');
    assert.equal(radio.squelch(), 'set');
    now += 6000;
    radio.tick();
    press('up');
    assert.equal(radio.state.channel, 17, 'ohne SQL-Fenster wählt CH▲ den Kanal');
    press('ch16');
    assert.equal(radio.state.channel, 16);
    press('ch16');
    assert.equal(radio.state.channel, 17, '16/S erneut: zurück');
    press('ch16', 1100);
    assert.equal(radio.state.channel, 9);
    assert.equal(radio.display().sub, true);
    press('ch16');
    assert.equal(radio.state.channel, 17);
    radio.keyDown('sql');
    now += 3100;
    radio.tick();
    assert.equal(radio.squelch(), 'noise', 'SQL 3 s: Monitor');
    radio.keyUp('sql');
    press('sql');
    assert.equal(radio.squelch(), 'set');
    press('lock', 1100);
    assert.equal(radio.display().locked, true);
    press('menu');
    assert.equal(radio.state.screen, 'normal');
    assert.equal(radio.pttDown(), true);
    radio.pttUp();
    press('lock', 1100);
    press('menu');
    assert.deepEqual(radio.display().panel.items, ['DSC CALL', 'CH', 'GM', 'GPS', 'NAVI', 'MMSI/POS INFO']);
    press('clr');
    press('menu', 1100);
    assert.equal(radio.display().panel.title, 'SETUP');
    assert.ok(events.some((e) => e.type === 'key_locked'));
});

test('HX891BT/E lessons: Gerät bedienen, Anrufkanal und Kanal 70, Sendeleistung', () => {
    const p = play('geraet-bedienen');
    assert.equal(p.run.status().target, 'power');
    p.press('power', 1200);
    for (let i = 0; i < 5; i++) p.press('volUp');
    p.press('sql');
    for (let i = 0; i < 4; i++) p.press('down');
    p.press('up');
    assert.equal(p.run.status().index, 4);
    p.wait(6000);
    while (p.radio.state.channel !== 72) p.press('up');
    p.press('ch16');
    assert.equal(p.run.status().target, 'ch16');
    p.press('ch16');
    assert.equal(p.run.status().done, true);

    const k = play('kanaele-anrufkanal');
    k.press('ch16', 1200);
    assert.equal(k.radio.state.channel, 9);
    k.press('ch16');
    assert.equal(k.radio.state.channel, 6);
    while (k.radio.state.channel !== 70) k.press('up');
    k.radio.pttDown();
    k.pump();
    k.press('ch16');
    assert.equal(k.run.status().done, true);

    const s = play('sendeleistung');
    s.press('up');
    s.soft('TX PWR');
    while (s.radio.state.channel !== 72) s.press('up');
    s.soft('TX PWR');
    s.press('ch16');
    s.soft('TX PWR');
    s.soft('TX PWR');
    assert.equal(s.run.status().done, true);
});

test('HX891BT/E lesson: MMSI, Position und Uhrzeit (MENU > MMSI/POS INFO > POS/TM)', () => {
    const p = play('mmsi-position-zeit');
    p.press('menu');
    p.pick('MMSI/POS INFO');
    assert.equal(p.run.status().index, 2);
    p.soft('POS/TM');
    p.type('5410000000N00752000000E1230');
    p.soft('FINISH');
    assert.equal(p.run.status().done, true);
    assert.equal(p.radio.state.screen, 'mmsiInfo');
});

test('HX891BT/E lessons: Zweikanalwache, Dreikanalwache über SETUP, Suchlauf mit SCAN MEMORY', () => {
    const w = play('zweikanalwache');
    w.soft('DUAL WATCH');
    w.fire();
    assert.equal(w.voices.at(-1).heard, true);
    w.soft('DUAL WATCH');
    w.press('menu', 1100);
    w.pick('CH SETUP');
    w.pick('MULTI WATCH');
    w.pick('TRIPLE');
    w.press('clr');
    w.soft('TRIPLE WATCH');
    assert.equal(w.run.status().done, true);

    const s = play('suchlauf-tag');
    s.soft('SCAN MEMORY');
    while (s.radio.state.channel !== 72) s.press('up');
    s.soft('SCAN MEMORY');
    while (s.radio.state.channel !== 77) s.press('up');
    s.soft('SCAN MEMORY');
    s.soft('SCAN');
    s.fire();
    assert.equal(s.voices.at(-1).heard, true);
    s.press('ch16');
    assert.equal(s.run.status().done, true);
});

test('HX891BT/E lesson: Einzelanruf – MEMORY, Kanal, YES; the ACK selects the channel', () => {
    const p = play('einzelanruf');
    assert.equal(p.run.status().target, 'menu');
    p.press('menu');
    p.pick('DSC CALL');
    p.pick('INDIVIDUAL');
    p.pick('MEMORY');
    p.pick('MOEWE');
    p.pick('CH:72');
    assert.deepEqual(p.radio.display().softkeys, ['YES', '', 'NO']);
    p.soft('YES');
    p.wait(1100);
    assert.match(p.radio.display().panel.lines.join(' '), /Waiting for ACK/);
    p.fire();
    assert.equal(p.radio.state.channel, 72);
    assert.equal(p.run.status().index, 2);
    assert.equal(p.radio.display().alarm, true);
    p.press('volUp');
    assert.equal(p.radio.display().alarm, false, 'eine beliebige Taste schaltet den Alarm aus');
    p.soft('QUIT');
    assert.equal(p.radio.state.screen, 'normal');
});

test('HX891BT/E lesson: Einzelanruf annehmen – STOP ALARM, ABLE, YES', () => {
    const p = play('einzelanruf-annehmen');
    p.fire();
    p.soft('STOP ALARM');
    assert.deepEqual(p.radio.display().softkeys, ['ABLE', 'UNABLE', 'QUIT']);
    p.soft('ABLE');
    assert.deepEqual(p.radio.display().softkeys, ['YES', 'CHG CH', 'NO']);
    p.soft('YES');
    p.wait(1100);
    assert.equal(p.radio.state.channel, 72);
    p.fire();
    assert.equal(p.voices.at(-1).heard, true);
});

test('HX891BT/E lessons: Küstenfunkstelle without channel choice, Gruppenanruf, Adressbuch, NEW ID', () => {
    const k = play('kuestenfunkstelle');
    k.press('menu');
    k.pick('DSC CALL');
    k.pick('INDIVIDUAL');
    k.pick('MEMORY');
    k.pick('NORDSEE RADIO');
    assert.equal(k.radio.state.screen, 'confirm');
    k.soft('YES');
    k.wait(1100);
    k.fire();
    assert.equal(k.radio.state.channel, 26);
    assert.equal(k.run.status().index, 2);

    const g = play('gruppenanruf');
    g.press('menu');
    g.pick('DSC CALL');
    g.pick('GROUP');
    g.pick('MEMORY');
    g.pick('FLOTILLE');
    g.pick('CH:69');
    g.soft('YES');
    g.wait(1100);
    assert.equal(g.radio.state.channel, 69);
    assert.equal(g.run.status().index, 2);

    const a = play('adressbuch');
    a.press('menu', 1100);
    a.pick('DSC SETUP');
    a.pick('INDIVIDUAL DIR.');
    a.pick('ADD');
    a.pick('MMSI');
    a.type('211555000');
    a.soft('FINISH');
    a.pick('SAVE');
    assert.equal(a.run.status().index, 1);
    a.press('clr');
    a.press('menu');
    a.pick('DSC CALL');
    a.pick('INDIVIDUAL');
    a.pick('MEMORY');
    a.pick('SEESTERN');
    a.pick('CH:77');
    a.soft('YES');
    a.wait(1100);
    a.fire();
    assert.equal(a.radio.state.channel, 77);
    assert.equal(a.run.status().done, true);

    const n = play('einzelanruf');
    n.press('menu');
    n.pick('DSC CALL');
    n.pick('INDIVIDUAL');
    n.pick('NEW ID');
    n.type('211234560');
    n.soft('FINISH');
    assert.equal(n.radio.state.screen, 'chList');
});

test('HX891BT/E lessons: Positionsabfrage und Polling, Testanruf', () => {
    const p = play('position-polling');
    p.press('menu');
    p.pick('DSC CALL');
    p.pick('POS REQUEST');
    p.pick('MEMORY');
    p.pick('MOEWE');
    p.soft('YES');
    p.wait(1100);
    assert.equal(p.run.status().index, 1);
    p.fire();
    p.soft('STOP ALARM');
    assert.match(p.radio.display().panel.lines.join(' '), /54°12/);
    assert.equal(p.run.status().index, 2);
    p.fire();
    assert.equal(p.run.status().done, true, 'Polling beantwortet das Gerät selbst');

    const t = play('testanruf');
    t.press('menu');
    t.pick('DSC CALL');
    t.pick('DSC TEST');
    t.pick('MEMORY');
    t.pick('MOEWE');
    t.soft('YES');
    t.wait(1100);
    t.fire();
    t.soft('STOP ALARM');
    assert.equal(t.run.status().done, true);
});

test('HX891BT/E lessons: Notalarm senden (DIST ALERT MSG, Klappe, 3 s), Fehlalarm aufheben, Notalarm empfangen', () => {
    const p = play('notalarm-senden');
    p.press('menu');
    p.pick('DSC CALL');
    p.pick('DIST ALERT MSG');
    p.soft('NATURE');
    p.pick('FLOODING');
    assert.equal(p.run.status().index, 1);
    p.soft('POS/TM');
    p.type('5410000000N00752000000E1230');
    p.soft('FINISH');
    assert.equal(p.run.status().index, 2);
    p.press('distressCover');
    p.radio.keyDown('distress');
    p.wait(3100);
    p.radio.keyUp('distress');
    p.wait(1100);
    assert.equal(p.radio.state.screen, 'distressWait');
    assert.deepEqual(p.radio.display().softkeys, ['PAUSE', '', 'CANCEL']);
    p.fire();
    p.soft('STOP ALARM');
    assert.equal(p.run.status().done, true);
    assert.equal(p.radio.state.channel, 16);

    const f = play('fehlalarm-aufheben');
    assert.equal(f.run.status().target, 'sk:CANCEL');
    f.soft('CANCEL');
    f.soft('YES');
    assert.equal(f.run.status().index, 1);
    f.speak('All stations all stations all stations this is Kormoran cancel my distress alert over');
    assert.equal(f.speech.length, 1);
    f.soft('OK');
    assert.equal(f.radio.state.screen, 'normal');

    const r = play('notalarm-empfangen');
    r.fire();
    r.soft('STOP ALARM');
    assert.deepEqual(r.radio.display().softkeys, ['ACCEPT', 'PAUSE', 'QUIT']);
    r.fire();
    assert.equal(r.voices.at(-1).heard, false);
    r.soft('ACCEPT');
    assert.equal(r.radio.state.channel, 16);
    r.fire();
    assert.equal(r.voices.at(-1).heard, true);
});

test('HX891BT/E lessons: Dringlichkeit (ALL SHIPS URGENCY), MEDICO, Gebietsanruf with auto switch after 30 s', () => {
    const d = play('dringlichkeit');
    d.press('menu');
    d.pick('DSC CALL');
    d.pick('ALL SHIPS');
    d.pick('URGENCY');
    d.pick('CH:16');
    d.soft('YES');
    d.wait(1100);
    assert.equal(d.run.status().index, 1);

    const m = play('medico');
    m.press('menu');
    m.pick('DSC CALL');
    m.pick('ALL SHIPS');
    m.pick('URGENCY');
    m.pick('CH:16');
    m.soft('YES');
    m.wait(1100);
    assert.equal(m.run.status().index, 2);

    const g = play('gebietsanruf');
    g.fire();
    g.soft('STOP ALARM');
    g.wait(31000);
    assert.equal(g.radio.state.channel, 26);
    assert.equal(g.run.status().index, 2);
});

test('HX891BT/E Gerätelogik ATIS (in der Bootsschule gesperrt): CH SETUP > CH GROUP ATIS, kein Kanal 16, DSC gesperrt, 1 W, HI in der Nautischen Information', () => {
    const p = play('binnen-atis');
    p.press('power', 1200);
    assert.equal(p.run.status().target, 'menu');
    p.press('menu', 1100);
    p.pick('CH SETUP');
    p.pick('CH GROUP');
    p.pick('ATIS');
    assert.equal(p.run.status().index, 2);
    p.press('clr');
    assert.equal(p.radio.display().status.group, 'ATIS');
    p.press('ch16');
    assert.equal(p.run.status().index, 3);
    p.press('ch16', 1100);
    assert.notEqual(p.radio.state.channel, 9, 'kein Zweitkanal im Binnenfunk');
    p.press('menu');
    p.pick('DSC CALL');
    assert.equal(p.run.status().index, 4);
    p.press('clr');
    while (p.radio.state.channel !== 10) p.press('up');
    p.soft('TX PWR');
    assert.equal(p.run.status().index, 6);
    while (p.radio.state.channel !== 18) p.press('up');
    p.soft('TX PWR');
    assert.equal(p.run.status().done, true);
    assert.equal(p.radio.display().status.power, 'HI');
});

test('HX891BT/E Gerätelogik ATIS (in der Bootsschule gesperrt): Wache und Suchlauf gesperrt, ATIS-Kennung beim Loslassen, kein DSC-Empfang', () => {
    let now = 0;
    const events = [];
    const radio = createHx891({ own: data.ship, clock: () => now, emit: (e) => events.push(e) });
    radio.setup({ power: true, vol: 5, sql: 3, group: 'ATIS', channel: 10, highPower: false });
    const press = (k) => { radio.keyDown(k); radio.keyUp(k); };
    press('soft2');
    assert.equal(radio.state.watch, 'off');
    assert.ok(events.some((e) => e.type === 'watch_blocked'));
    radio.pttDown();
    radio.pttUp();
    const atis = events.find((e) => e.type === 'atis_sent');
    assert.equal(atis.id, data.ship.atis);
    assert.equal(radio.receive({ type: 'distress', from: data.address_book[0] }), false);
    assert.equal(radio.hear({ channel: 10, group: 'ATIS', from: 'X' }).heard, true);
    assert.equal(radio.hear({ channel: 10, from: 'X', group: 'INT' }).heard, false);
    radio.keyDown('distress');
    assert.ok(events.some((e) => e.type === 'dsc_blocked' && e.key === 'distress'));
});
