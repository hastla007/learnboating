// Gerätelogik Icom IC-M323 (Stufe 4): Bedienung über Drehknopf, Wippe,
// Softkeys und Menüs; gleiche Ereignisse wie beim IC-M505.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createIcM323 } from '../devices/icm323.js';

const own = { name: 'KORMORAN', callsign: 'DH4721', mmsi: '211472100', atis: '9211234721' };
const moewe = { name: 'MOEWE', mmsi: '211839200' };
const bremen = { name: 'BREMEN RESCUE', mmsi: '002111240' };
const flotilla = { name: 'FLOTILLE', mmsi: '021100001', group: true };

function rig(start = { power: true, vol: 5, sql: 3, channel: 16 }) {
    let now = 0;
    const events = [];
    const radio = createIcM323({ own, clock: () => now, emit: (e) => events.push(e), addressBook: [moewe, bremen, flotilla], directory: [{ name: 'SEESTERN', mmsi: '211555000' }] });
    radio.setup(start);
    const wait = (ms) => { for (let t = 0; t < ms; t += 100) { now += 100; radio.tick(); } };
    const press = (key, ms = 100) => { radio.keyDown(key); wait(ms); radio.keyUp(key); };
    const soft = (label) => {
        const i = radio.display().softkeys.indexOf(label);
        assert.ok(i >= 0, `Softkey ${label} fehlt: ${radio.display().softkeys}`);
        press(`soft${i + 1}`);
    };
    const pick = (item) => {
        const p = radio.display().panel;
        const i = p.items.indexOf(item);
        assert.ok(i >= 0, `${item} fehlt: ${p.items}`);
        radio.turn('dial', i - p.cursor);
        press('enter');
    };
    const path = (...items) => { press('menu'); items.forEach(pick); };
    const digits = (text) => {
        for (const d of text) {
            const e = radio.display().panel.editor;
            const cur = e.chars[e.cursor];
            if (/\d/.test(d)) radio.turn('dial', (Number(d) - Number(cur) + 10) % 10);
            else if (d !== cur) radio.turn('dial', 1);
            press('enter');
        }
    };

    return { radio, events, wait, press, soft, pick, path, digits, last: (type) => events.filter((e) => e.type === type).at(-1) };
}

test('power with the knob held one second; turning without pressing sets the volume; short press switches to squelch', () => {
    const r = rig({ vol: 0, sql: 4 });
    r.press('knob', 1200);
    assert.equal(r.radio.state.power, true);
    r.radio.turn('dial', 6);
    assert.equal(r.last('volume').level, 3); // 6 von 20 Stufen = 3 in den Stufen der Lektionen
    r.press('knob');
    assert.equal(r.radio.display().window.label, 'SQL:');
    r.radio.turn('dial', -4);
    assert.equal(r.last('squelch').state, 'noise');
    r.press('knob', 1200);
    assert.equal(r.radio.state.power, false);
});

test('softkey ring: SCAN DW HI/LO CHAN, ▶ shifts by one; CHAN returns from channel 16', () => {
    const r = rig({ power: true, vol: 5, sql: 3, channel: 72 });
    assert.deepEqual(r.radio.display().softkeys, ['SCAN', 'DW', 'HI/LO', 'CHAN']);
    r.press('ch16');
    assert.equal(r.radio.state.channel, 16);
    r.soft('CHAN');
    assert.equal(r.radio.state.channel, 72);
    r.press('right');
    assert.deepEqual(r.radio.display().softkeys, ['DW', 'HI/LO', 'CHAN', 'LO/DX']);
    r.press('ch16', 1200);
    assert.ok(r.last('call_channel'));
});

test('channel group and dual/tri watch are set in the Radio Settings menu', () => {
    const r = rig();
    r.path('Radio Settings', 'Dual/Tri-Watch', 'Tri-watch');
    assert.equal(r.last('set_mode').value, 'tri');
    r.press('menu');
    r.soft('DW');
    assert.equal(r.last('watch').mode, 'tri');
    r.path('Radio Settings', 'CHAN Group', 'ATIS');
    assert.equal(r.radio.state.group, 'ATIS');
    r.press('menu');
    r.path('DSC Calls');
    assert.equal(r.last('dsc_blocked').key, 'menu');
});

test('distress: nature from the menu, manual position, 3 s hold, acknowledgement with ALARM OFF', () => {
    const r = rig();
    r.path('DSC Calls', 'Distress Call', 'Flooding');
    assert.equal(r.last('nature_selected').nature, 'Flooding');
    r.digits('54100000N007520000E');
    r.digits('1230');
    assert.equal(r.last('utc_set').utc, '12:30');
    assert.equal(r.radio.state.screen, 'distressConfirm');
    r.press('distressCover');
    r.radio.keyDown('distress');
    r.wait(3100);
    r.radio.keyUp('distress');
    assert.equal(r.last('dsc_sent').nature, 'Flooding');
    assert.equal(r.last('dsc_sent').label, 'Flooding');
    r.wait(1100);
    assert.deepEqual(r.radio.display().softkeys.slice(0, 2), ['CANCEL', 'RESEND']);
    r.radio.receive({ type: 'distress_ack', from: bremen });
    r.soft('ALARM OFF');
    assert.equal(r.last('alarm_stopped').call, 'distress_ack');
    assert.equal(r.radio.state.channel, 16);
});

test('distress cancel: CANCEL, CONTINUE, FINISH sends a DSC cancel and goes to channel 16', () => {
    const r = rig({ power: true, vol: 5, sql: 3, channel: 16, distressWaiting: 'Undesignated' });
    r.soft('CANCEL');
    r.soft('CONTINUE');
    r.soft('FINISH');
    assert.equal(r.last('dsc_sent').kind, 'distress_cancel');
    assert.ok(r.last('distress_repeat_cancelled'));
    assert.equal(r.radio.state.channel, 16);
});

test('individual call is always Routine; to a ship with intership channel, to a coast station without', () => {
    const r = rig();
    r.path('DSC Calls', 'Individual Call', 'MOEWE');
    r.radio.turn('dial', 64); // ab 08 im INT-Kanalring vorwärts
    while (r.radio.display().panel.lines[1] !== 'CHAN: 72') r.radio.turn('dial', 1);
    r.press('enter');
    r.soft('CALL');
    assert.deepEqual([r.last('dsc_sent').kind, r.last('dsc_sent').category, r.last('dsc_sent').channel], ['individual', 'Routine', 72]);
    r.wait(1100);
    r.radio.receive({ type: 'individual_ack', from: moewe });
    r.soft('ALARM OFF');
    assert.equal(r.radio.state.channel, 72);
    r.soft('EXIT');

    r.path('DSC Calls', 'Individual Call', 'BREMEN RESCUE');
    assert.equal(r.radio.state.screen, 'callConfirm');
    r.soft('CALL');
    assert.equal(r.last('dsc_sent').channel, null);
    r.wait(1100);
    r.radio.receive({ type: 'individual_ack', from: bremen, channel: 26 });
    r.soft('ALARM OFF');
    assert.equal(r.radio.state.channel, 26);
});

test('a received individual call is answered: ALARM OFF, ACK, Able to Comply, CALL', () => {
    const r = rig();
    r.radio.receive({ type: 'individual', from: moewe, category: 'Routine', channel: 72 });
    r.soft('ALARM OFF');
    assert.equal(r.last('alarm_stopped').call, 'individual');
    r.soft('ACK');
    r.press('enter');
    r.soft('CALL');
    assert.equal(r.last('dsc_sent').action, 'able');
    r.wait(1100);
    assert.equal(r.radio.state.channel, 72);
});

test('a received distress alert needs ACPT; channel 16 follows after 10 s or with CH 16', () => {
    const r = rig({ power: true, vol: 5, sql: 3, channel: 72 });
    r.radio.receive({ type: 'distress', from: moewe, nature: 'Flooding' });
    assert.equal(r.radio.state.channel, 72);
    r.soft('ALARM OFF');
    r.soft('ACPT');
    r.wait(10100);
    assert.equal(r.radio.state.channel, 16);
    assert.equal(r.last('call_accepted').channel, 16);
});

test('all ships call only offers Safety and Urgency; group call goes to the working channel', () => {
    const r = rig();
    r.path('DSC Calls', 'All Ships Call');
    assert.deepEqual(r.radio.display().panel.items, ['Safety', 'Urgency']);
    r.pick('Urgency');
    r.press('enter');
    r.soft('CALL');
    assert.deepEqual([r.last('dsc_sent').kind, r.last('dsc_sent').category], ['all_ships', 'Urgency']);
    r.wait(1100);
    r.soft('EXIT');
    r.path('DSC Calls', 'Group Call', 'FLOTILLE');
    r.press('enter');
    r.soft('CALL');
    r.wait(1100);
    assert.equal(r.last('dsc_sent').kind, 'group');
    assert.equal(r.radio.state.channel, 8);
});

test('test call and automatic position/polling replies; no position request menu', () => {
    const r = rig();
    r.press('menu');
    r.pick('DSC Calls');
    assert.ok(! r.radio.display().panel.items.includes('Position Request'));
    r.pick('Test Call');
    r.pick('MOEWE');
    r.soft('CALL');
    assert.deepEqual([r.last('dsc_sent').kind, r.last('dsc_sent').category], ['test', 'Safety']);
    r.wait(1100);
    r.radio.receive({ type: 'test_ack', from: moewe });
    r.soft('ALARM OFF');
    r.soft('EXIT');
    r.radio.receive({ type: 'polling_request', from: bremen });
    assert.equal(r.last('dsc_sent').kind, 'polling_reply');
    assert.equal(r.last('dsc_sent').auto, true);
});

test('Individual ID: ADD stores a ship from the directory; MMSI/GPS Info shows the own MMSI', () => {
    const r = rig();
    r.path('DSC Settings', 'Individual ID');
    r.soft('ADD');
    r.digits('211555000');
    assert.equal(r.last('address_added').name, 'SEESTERN');
    r.press('menu');
    r.path('MMSI/GPS Info');
    assert.equal(r.last('mmsi_checked').mmsi, own.mmsi);
});

test('ATIS: no channel 16, ATIS identification after transmitting, TAG and scan via softkeys', () => {
    const r = rig({ power: true, vol: 5, sql: 3, group: 'ATIS', channel: 10, highPower: false });
    r.press('ch16');
    assert.ok(r.last('ch16_unavailable'));
    r.radio.pttDown();
    r.radio.pttUp();
    assert.equal(r.last('atis_sent').id, own.atis);
    r.press('right'); r.press('right'); r.press('right'); r.press('right'); r.press('right'); r.press('right');
    r.soft('★');
    assert.equal(r.last('tag').on, true);
});
