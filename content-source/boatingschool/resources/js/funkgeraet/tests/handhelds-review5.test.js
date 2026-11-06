// Review-Runde 5: Fehler an den Handfunkgeräten HX891BT/E und IC-M94DE
// (CHG CH zurück, 16/S nach automatischem Kanalwechsel, Klappe bei
// Tastensperre, UNABLE-Anzeige, Notbestätigung ohne Aufforderung, ATIS
// während des Notalarms, VOL/SQL in jedem Bildschirm, unmögliche Position,
// Tastensperre nach dem Ausschalten).

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createHx891 } from '../devices/hx891.js';
import { createIcM94DE } from '../devices/icm330.js';

const data = JSON.parse(execFileSync('php', ['-r', 'echo json_encode(require "database/data/geraetesimulation.php");'], { encoding: 'utf8' }));
const moewe = data.address_book[0];

function radioOn(device, start = {}) {
    let now = 1_000_000;
    const events = [];
    const create = device === 'hx891bte' ? createHx891 : createIcM94DE;
    const radio = create({ own: data.ship, clock: () => now, emit: (e) => events.push(e), addressBook: data.address_book, directory: data.directory });
    radio.setup({ power: true, vol: 5, sql: 3, channel: 16, ...start });
    const d = () => radio.display();
    const wait = (ms) => { for (let t = 0; t < ms; t += 100) { now += 100; radio.tick(); } };
    const press = (k, ms = 100) => { radio.keyDown(k); wait(ms); radio.keyUp(k); };
    const soft = (label) => {
        for (let i = 0; i < 10 && ! d().softkeys.includes(label); i++) {
            const page = d().pages?.labels.findIndex((l) => l.includes(label)) ?? -1;
            assert.ok(page >= 0, `Softkey ${label} fehlt (${d().softkeys})`);
            press(page < d().pages.current ? 'left' : 'right');
        }
        assert.ok(d().softkeys.includes(label), `Softkey ${label} fehlt (${d().softkeys})`);
        press(`soft${d().softkeys.indexOf(label) + 1}`);
    };
    const select = () => (device === 'hx891bte' ? soft('SELECT') : press('enter'));
    const pick = (item) => {
        const p = d().panel;
        const index = p.items.findIndex((i) => i === item || i.startsWith(item));
        assert.ok(index >= 0, `${item} nicht in ${p.items}`);
        for (let i = p.cursor; i < index; i++) press('down');
        for (let i = p.cursor; i > index; i--) press('up');
        select();
    };
    const type = (chars) => {
        for (const c of chars) {
            const target = d().panel.editor.row.indexOf(c);
            for (let i = 0; i < 30 && d().panel.editor.sel !== target; i++) press('right');
            select();
        }
    };
    const sendDistress = () => {
        press('distressCover');
        radio.keyDown('distress');
        wait(3100);
        radio.keyUp('distress');
        wait(1200);
        assert.equal(radio.state.screen, 'distressWait');
    };
    const sent = () => events.filter((e) => e.type === 'dsc_sent');

    return { radio, d, wait, press, soft, pick, type, sendDistress, sent, events };
}

test('HX891BT/E: BACK in CHG CH returns to the confirmation, the call is kept', () => {
    const h = radioOn('hx891bte');
    h.radio.receive({ type: 'individual', from: moewe, category: 'Routine', channel: 72 });
    h.soft('STOP ALARM');
    h.soft('ABLE');
    h.soft('CHG CH');
    assert.equal(h.radio.state.screen, 'chList');
    h.soft('BACK');
    assert.equal(h.radio.state.screen, 'ackConfirm');
    h.soft('YES');
    h.wait(1200);
    assert.equal(h.sent().at(-1).action, 'able');
    assert.equal(h.radio.state.channel, 72);
});

test('HX891BT/E: a channel change by the radio ends 16/S and the sub channel', () => {
    const h = radioOn('hx891bte', { channel: 72 });
    h.press('ch16');
    assert.equal(h.radio.state.channel, 16);
    h.radio.receive({ type: 'group', from: moewe, category: 'Routine', channel: 69 });
    h.soft('STOP ALARM');
    h.soft('ACCEPT');
    assert.equal(h.radio.state.channel, 69);
    h.soft('QUIT');
    h.press('ch16');
    assert.equal(h.radio.state.channel, 16, '16/S geht nach dem Gruppenanruf auf Kanal 16');

    // Zweitkanal, dann Notalarm: Kanal 16, 16/S verlässt Kanal 16 nicht.
    const n = radioOn('hx891bte', { channel: 72 });
    n.press('ch16', 1200);
    assert.equal(n.d().sub, true);
    n.sendDistress();
    assert.equal(n.radio.state.channel, 16);
    assert.equal(n.d().sub, false);
    n.press('ch16');
    assert.equal(n.radio.state.channel, 16);
});

test('HX891BT/E: the DISTRESS cover opens while the keys are locked', () => {
    const h = radioOn('hx891bte');
    h.press('lock', 1200);
    assert.equal(h.d().locked, true);
    h.press('distressCover');
    assert.equal(h.radio.state.coverOpen, true);
    h.radio.keyDown('distress');
    h.wait(3100);
    h.radio.keyUp('distress');
    assert.equal(h.sent().at(-1).kind, 'distress');
});

test('HX891BT/E: after sending UNABLE the display says Unable to comply', () => {
    const h = radioOn('hx891bte');
    h.radio.receive({ type: 'individual', from: moewe, category: 'Routine', channel: 72 });
    h.soft('STOP ALARM');
    h.soft('UNABLE');
    h.soft('YES');
    h.wait(1200);
    assert.equal(h.sent().at(-1).action, 'unable');
    assert.equal(h.d().panel.lines.at(-1), 'Unable to comply');
});

test('HX891BT/E: a distress acknowledgement switches to 16; calls without a channel only offer QUIT', () => {
    const h = radioOn('hx891bte', { channel: 72 });
    h.radio.receive({ type: 'distress_ack', from: moewe });
    h.soft('STOP ALARM');
    h.soft('ACCEPT');
    assert.equal(h.radio.state.channel, 16);
    assert.equal(h.radio.state.screen, 'rcvdAccepted');

    const t = radioOn('hx891bte', { channel: 72 });
    t.radio.receive({ type: 'test_ack', from: moewe });
    t.soft('STOP ALARM');
    assert.deepEqual(t.d().softkeys, ['', '', 'QUIT']);
});

test('HX891BT/E and IC-M94DE: impossible positions and times are not taken', () => {
    const hx = radioOn('hx891bte');
    hx.press('menu');
    hx.pick('MMSI/POS INFO');
    hx.soft('POS/TM');
    hx.type('9999999999N99999999999E2359');
    hx.soft('FINISH');
    assert.equal(hx.radio.state.position, null);
    assert.ok(hx.events.some((e) => e.type === 'input_invalid'));

    const m = radioOn('ic-m94de');
    m.press('menu');
    m.pick('Settings');
    m.pick('DSC');
    m.pick('Position Input');
    m.type('9999');
    m.soft('FIN');
    assert.equal(m.radio.state.position, null);
    assert.equal(m.d().panel.editor.kind ?? 'lat', 'lat', 'bleibt bei der Breite');
    assert.ok(! m.events.some((e) => e.type === 'utc_set'));
});

test('IC-M94DE: no change to ATIS while the distress alert waits for the acknowledgement', () => {
    const h = radioOn('ic-m94de');
    h.sendDistress();
    h.press('menu');
    h.pick('Settings');
    h.pick('Radio');
    h.pick('Channel Group');
    h.pick('ATIS');
    assert.equal(h.radio.state.group, 'INT');
    assert.ok(h.events.some((e) => e.type === 'group_blocked'));
});

test('IC-M94DE: VOL/SQL works while waiting for the distress acknowledgement, ▲ does not change the channel', () => {
    const h = radioOn('ic-m94de', { vol: 4 });
    h.sendDistress();
    h.press('volsql');
    assert.equal(h.d().window.label, 'Volume:');
    h.press('up');
    h.press('up');
    assert.equal(h.radio.state.channel, 16);
    assert.ok(h.radio.state.vol > 4);
});

test('IC-M94DE: power off clears the key lock', () => {
    const h = radioOn('ic-m94de');
    h.press('clr', 1200);
    assert.equal(h.d().locked, true);
    h.press('power', 1200);
    h.press('power', 1200);
    assert.equal(h.radio.state.power, true);
    assert.equal(h.d().locked, false);
});

test('Handfunkgeräte: mit inland: false lässt sich ATIS nicht wählen, das Gerät meldet inland_blocked und bleibt bei INT', () => {
    for (const device of ['hx891bte', 'ic-m94de']) {
        let now = 1_000_000;
        const events = [];
        const create = device === 'hx891bte' ? createHx891 : createIcM94DE;
        const radio = create({ own: data.ship, clock: () => now, emit: (e) => events.push(e), inland: false });
        // Auch eine Ausgangslage mit ATIS führt nicht in den Binnenfunk.
        radio.setup({ power: true, vol: 5, sql: 3, group: 'ATIS', channel: 10 });
        assert.equal(radio.state.group, 'INT', device);
        const d = () => radio.display();
        const press = (k, ms = 100) => { radio.keyDown(k); for (let t = 0; t < ms; t += 100) { now += 100; radio.tick(); } radio.keyUp(k); };
        const pick = (item) => {
            const p = d().panel;
            const index = p.items.findIndex((i) => i === item || i.startsWith(item));
            assert.ok(index >= 0, `${item} nicht in ${p.items}`);
            for (let i = p.cursor; i < index; i++) press('down');
            for (let i = p.cursor; i > index; i--) press('up');
            if (device === 'hx891bte') press(`soft${d().softkeys.indexOf('SELECT') + 1}`);
            else press('enter');
        };
        if (device === 'hx891bte') {
            press('menu', 1100);
            pick('CH SETUP');
            pick('CH GROUP');
        } else {
            press('menu');
            pick('Settings');
            pick('Radio');
            pick('Channel Group');
        }
        pick('ATIS');
        assert.equal(radio.state.group, 'INT', device);
        assert.ok(events.some((e) => e.type === 'inland_blocked' && e.group === 'ATIS'), device);
        assert.ok(! events.some((e) => e.type === 'group'), device);
    }
});

test('HX891BT/E: CLR und MENU verlassen Abschlussbildschirme wie QUIT', () => {
    // Anruf an alle gesendet: „Transmitted“ (callDone), CLR führt zurück.
    const h = radioOn('hx891bte');
    h.press('menu');
    h.pick('DSC CALL');
    h.pick('ALL SHIPS');
    h.pick('SAFETY');
    h.pick('CH:16');
    h.soft('YES');
    h.wait(1200);
    assert.equal(h.radio.state.screen, 'callDone');
    h.press('clr');
    assert.equal(h.radio.state.screen, 'normal');

    // Angenommener Gruppenanruf (rcvdAccepted): MENU öffnet direkt das Menü.
    const g = radioOn('hx891bte', { channel: 72 });
    g.radio.receive({ type: 'group', from: moewe, category: 'Routine', channel: 69 });
    g.soft('STOP ALARM');
    g.soft('ACCEPT');
    assert.equal(g.radio.state.screen, 'rcvdAccepted');
    g.press('menu');
    assert.equal(g.radio.state.screen, 'menu');
    assert.equal(g.radio.state.channel, 69);

    // Notalarm bestätigt (distressAcked): CLR beendet den Notalarm-Zustand.
    const d = radioOn('hx891bte');
    d.sendDistress();
    d.radio.receive({ type: 'distress_ack', from: moewe });
    d.soft('STOP ALARM');
    assert.equal(d.radio.state.screen, 'distressAcked');
    d.press('clr');
    assert.equal(d.radio.state.screen, 'normal');
    d.wait(5 * 60 * 1000);
    assert.equal(d.sent().filter((e) => e.kind === 'distress').length, 1, 'keine Wiederholung des Notalarms');
});

test('Anruf während der eigenen Aussendung geht nicht verloren, der Kanalwechsel folgt danach', () => {
    const h = radioOn('hx891bte', { channel: 72 });
    h.press('menu');
    h.pick('DSC CALL');
    h.pick('ALL SHIPS');
    h.pick('SAFETY');
    h.pick('CH:16');
    h.soft('YES');
    assert.equal(h.radio.state.screen, 'callTx');
    // Gruppenanruf genau während des Sendens.
    h.radio.receive({ type: 'group', from: moewe, category: 'Routine', channel: 69 });
    assert.equal(h.radio.state.screen, 'callTx', 'Aussendung läuft weiter');
    h.wait(1500);
    assert.equal(h.d().alarm, true, 'der Anruf kommt nach der Aussendung an');
    h.soft('STOP ALARM');
    h.soft('ACCEPT');
    assert.equal(h.radio.state.channel, 69);
});

test('Bestätigung des Notalarms, während DISTRESS noch gehalten wird, beendet die Wiederholung', () => {
    for (const device of ['hx891bte', 'ic-m94de']) {
        const h = radioOn(device);
        h.press('distressCover');
        h.radio.keyDown('distress');
        h.wait(1000);
        h.radio.receive({ type: 'distress_ack', from: moewe });
        h.wait(2200);
        h.radio.keyUp('distress');
        h.wait(3000);
        assert.equal(h.sent().filter((e) => e.kind === 'distress').length, 1, device);
        assert.ok(h.events.some((e) => e.type === 'dsc_received' && e.call.type === 'distress_ack'), device);
        h.wait(5 * 60 * 1000);
        assert.equal(h.sent().filter((e) => e.kind === 'distress').length, 1, `${device}: keine Wiederholung`);
    }
});
