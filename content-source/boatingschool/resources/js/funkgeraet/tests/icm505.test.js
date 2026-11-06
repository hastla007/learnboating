// Tests der Gerätelogik IC-M505 und der Lektionen (node --test, ohne Browser).
// Die Lektionen kommen wie im Browser aus database/data/geraetesimulation.php.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createIcM505, NATURES } from '../devices/icm505.js';
import { createLessonRun, matchesGoal } from '../lessons.js';

const data = JSON.parse(execFileSync('php', ['-r', 'echo json_encode(require "database/data/geraetesimulation.php");'], { encoding: 'utf8' }));
const lesson = (key) => data.lessons.find((l) => l.key === key);

function rig(start = {}) {
    let now = 0;
    const events = [];
    const radio = createIcM505({ own: data.ship, clock: () => now, emit: (e) => events.push(e), addressBook: data.address_book });
    radio.setup(start);
    const wait = (ms) => {
        for (let t = 0; t < ms; t += 100) {
            now += 100;
            radio.tick();
        }
    };
    const press = (key, ms = 100) => {
        radio.keyDown(key);
        wait(ms);
        radio.keyUp(key);
    };
    const combo = (modifier, key) => {
        radio.keyDown(modifier);
        radio.keyDown(key);
        radio.keyUp(key);
        radio.keyUp(modifier);
    };

    return { radio, events, wait, press, combo, last: (type) => events.filter((e) => e.type === type).at(-1) };
}

// Kanalwahlknopf drehen, bis der Kanal im Display (bzw. im Menü „Select
// Traffic CH“) steht.
function turnTo(r, channel) {
    const label = String(channel).padStart(2, '0');
    const shown = () => r.radio.display().panel?.subtitle === 'Select Traffic CH' ? r.radio.display().panel.lines[0] : r.radio.display().channel;
    for (let i = 0; i < 60 && shown() !== label; i++) r.radio.turn('channel', 1);
    assert.equal(shown(), label);
}

// Ziffern im Editor einstellen: Zielzeichen für jede editierbare Stelle.
function typeDigits(r, digits) {
    for (const d of digits) {
        const shown = r.radio.display().panel.editor;
        const current = shown.chars[shown.cursor];
        if (/\d/.test(d)) r.radio.turn('channel', (Number(d) - Number(current) + 10) % 10);
        else if (d !== current) r.radio.turn('channel', 1);
        r.press('ch16');
    }
    r.press('enter');
}

test('power, volume, squelch and channel selection', () => {
    const r = rig({ sql: 4 });
    assert.equal(r.radio.display().on, false);
    r.press('power');
    assert.equal(r.radio.state.power, true);
    r.radio.turn('sql', -4);
    assert.equal(r.radio.squelch(), 'noise');
    r.radio.turn('sql', 3);
    assert.equal(r.radio.squelch(), 'set');
    r.radio.turn('channel', 1);
    assert.equal(r.radio.state.channel, 17);
    r.press('ch16');
    assert.equal(r.radio.state.channel, 16);
    r.press('dial');
    assert.equal(r.radio.state.channel, 17);
    // POWER eine Sekunde halten schaltet aus.
    r.press('power', 1200);
    assert.equal(r.radio.state.power, false);
});

test('low power is forced on channels 15, 17, 75 and 76', () => {
    const r = rig({ power: true, channel: 15 });
    assert.equal(r.radio.display().status.power, '1W');
    r.press('hl');
    assert.equal(r.last('power_level_blocked').channel, 15);
    turnTo(r, 72);
    r.press('hl');
    assert.equal(r.radio.display().status.power, '1W');
});

test('channel 70 is DSC only and H/L with DIAL switches the channel group', () => {
    const r = rig({ power: true, channel: 70 });
    assert.equal(r.radio.pttDown(), false);
    assert.ok(r.last('tx_blocked'));
    r.combo('hl', 'dial');
    assert.equal(r.radio.state.group, 'ATIS');
    // H/L als Umschalttaste schaltet dabei nicht die Leistung um.
    assert.equal(r.last('power_level'), undefined);
});

test('distress needs the cover open and a five second hold', () => {
    const r = rig({ power: true, position: [54, 10, 'N', 7, 52, 'E'], utc: '12:30', nature: 'Flooding' });
    r.press('distress', 6000);
    assert.ok(r.last('distress_covered'));
    assert.equal(r.last('dsc_sent'), undefined);

    r.press('distressCover');
    r.press('distress', 3000);
    assert.ok(r.last('distress_released_early'));
    assert.equal(r.last('dsc_sent'), undefined);

    r.radio.keyDown('distress');
    r.wait(5100);
    r.radio.keyUp('distress');
    const sent = r.last('dsc_sent');
    assert.equal(sent.kind, 'distress');
    assert.equal(sent.nature, 'Flooding');
    assert.equal(r.radio.state.channel, 70);
    r.wait(2100);
    assert.equal(r.radio.state.channel, 16);
    assert.equal(r.radio.display().lines[0], 'Wait ACK');

    // Ohne Bestätigung wiederholt das Gerät den Alarm.
    r.wait(4 * 60 * 1000);
    assert.equal(r.events.filter((e) => e.type === 'dsc_sent').length, 2);

    // CLR bricht die Wiederholung ab.
    r.press('clr');
    assert.ok(r.last('distress_repeat_cancelled'));
    r.wait(5 * 60 * 1000);
    assert.equal(r.events.filter((e) => e.type === 'dsc_sent').length, 2);
});

test('the DSC menu lists the nature of distress as on the radio', () => {
    const r = rig({ power: true });
    r.press('menu');
    r.radio.turn('channel', 8); // Distress Setting
    assert.equal(r.radio.display().panel.items[r.radio.display().panel.cursor], 'Distress Setting');
    r.press('enter');
    assert.deepEqual(r.radio.display().panel.items, NATURES);
});

test('a goal matches nested fields, ranges and arrays', () => {
    assert.ok(matchesGoal({ type: 'volume', level: { min: 5 } }, { type: 'volume', level: 6 }));
    assert.ok(! matchesGoal({ type: 'volume', level: { min: 5 } }, { type: 'volume', level: 4 }));
    assert.ok(matchesGoal({ type: 'dsc_received', 'call.type': 'distress_ack' }, { type: 'dsc_received', call: { type: 'distress_ack' } }));
    assert.ok(matchesGoal({ position: [54, 10, 'N'] }, { position: [54, 10, 'N'] }));
});

// --- Lektionen von Anfang bis Ende -------------------------------------------

function playLesson(key, script) {
    const l = lesson(key);
    const r = rig();
    const timers = [];
    const run = createLessonRun(l, {
        level: 1,
        schedule: (fn, ms) => timers.push({ fn, at: ms }),
        receive: (call) => r.radio.receive(call),
    });
    r.radio.setup(l.start);
    const seen = r.events.length;
    r.events.splice(0, seen);
    const speech = [];
    const pump = () => {
        while (r.events.length) {
            const e = r.events.shift();
            const pending = run.handle(e);
            if (pending) speech.push(pending);
        }
    };
    run.start();
    script({ ...r, pump, fireTimers: () => { timers.splice(0).forEach((t) => t.fn()); pump(); }, run, speech });
    pump();

    return { run, speech };
}

test('lesson: so bedienst du das Gerät', () => {
    const { run } = playLesson('geraet-bedienen', (r) => {
        r.press('power');
        r.radio.turn('vol', 6);
        r.radio.turn('sql', -4);
        r.radio.turn('sql', 3);
        turnTo(r, 72);
        r.press('ch16');
        r.press('dial');
    });
    assert.equal(run.status().done, true);
});

test('lesson: Kanal 16, Anrufkanal und Kanal 70', () => {
    const { run } = playLesson('kanaele-anrufkanal', (r) => {
        r.press('ch16', 1200);
        r.press('dial');
        turnTo(r, 70);
        r.radio.pttDown();
        r.press('ch16');
    });
    assert.equal(run.status().done, true);
});

test('lesson: MMSI, Position und Uhrzeit', () => {
    const { run } = playLesson('mmsi-position-zeit', (r) => {
        r.press('menu');
        r.radio.turn('channel', 9); // Set up
        r.press('enter');
        r.radio.turn('channel', 5); // MMSI Check
        r.press('enter');
        assert.equal(r.radio.display().panel.lines[0], data.ship.mmsi);
        r.press('clr');
        r.press('enter'); // Position Input
        typeDigits(r, '5410000N00752000E');
        typeDigits(r, '1230');
    });
    assert.equal(run.status().done, true);
});

test('lesson: Notalarm senden (Prüfung 1.a) with acknowledgement from Bremen Rescue', () => {
    const { run } = playLesson('notalarm-senden', (r) => {
        r.press('menu');
        r.radio.turn('channel', 8);
        r.press('enter');
        r.radio.turn('channel', 2); // Flooding
        r.press('enter');
        typeDigits(r, '5410000N00752000E');
        typeDigits(r, '1230');
        r.press('distressCover');
        r.radio.keyDown('distress');
        r.wait(5100);
        r.radio.keyUp('distress');
        r.wait(2100);
        r.pump();
        r.fireTimers();
        assert.equal(r.radio.display().lines[1], 'DistressACK');
        r.press('clr');
    });
    assert.equal(run.status().done, true);
});

test('lesson: Fehlalarm aufheben waits for the spoken cancellation', () => {
    const { run, speech } = playLesson('fehlalarm-aufheben', (r) => {
        r.press('clr');
        r.pump();
        r.radio.pttDown();
        r.radio.pttUp();
        r.radio.spoken('All stations all stations all stations this is Kormoran ...');
    });
    assert.equal(speech.length, 1);
    assert.equal(run.status().awaitingSpeech, true);
    run.speechResult(speech[0].stepIndex, true);
    assert.equal(run.status().done, true);
});

test('lesson: Einzelanruf with automatic switch to the working channel', () => {
    const { run } = playLesson('einzelanruf', (r) => {
        r.press('menu');
        r.radio.turn('channel', 1); // Individual Call
        r.press('enter');
        r.radio.turn('channel', 1); // MOEWE
        r.press('enter');
        r.press('enter'); // Routine
        turnTo(r, 72);
        r.press('enter');
        r.press('enter'); // senden
        r.pump();
        r.fireTimers();
        assert.equal(r.radio.state.channel, 72);
        r.press('clr');
        r.radio.spoken('Moewe this is Kormoran over');
    });
    assert.equal(run.status().awaitingSpeech, true);
});

test('help and highlighting depend on the level', () => {
    const l = lesson('geraet-bedienen');
    assert.equal(createLessonRun(l, { level: 1 }).status().target, 'power');
    assert.equal(createLessonRun(l, { level: 2 }).status().target, null);
    assert.ok(createLessonRun(l, { level: 2 }).status().help);
    assert.equal(createLessonRun(l, { level: 3 }).status().help, null);
});
