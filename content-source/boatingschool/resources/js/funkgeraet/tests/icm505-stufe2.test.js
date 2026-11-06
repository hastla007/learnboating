// Gerätelogik IC-M505, Funktionen aus Stufe 2: Antwort auf Einzelanrufe,
// Gruppenanruf, Positionsabfrage, Polling, Adressbuch, Suchlauf, Wache,
// Set-Modus und Hörbarkeit von Sprechfunk.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createIcM505 } from '../devices/icm505.js';

const own = { name: 'KORMORAN', callsign: 'DH4721', mmsi: '211472100' };
const moewe = { name: 'MOEWE', mmsi: '211839200' };
const flotilla = { name: 'FLOTILLE', mmsi: '021100001', group: true };

function rig(start = {}, options = {}) {
    let now = 0;
    const events = [];
    const radio = createIcM505({ own, clock: () => now, emit: (e) => events.push(e), addressBook: [moewe, flotilla], ...options });
    radio.setup({ power: true, vol: 5, sql: 3, ...start });
    const wait = (ms) => { for (let t = 0; t < ms; t += 100) { now += 100; radio.tick(); } };
    const press = (key, ms = 100) => { radio.keyDown(key); wait(ms); radio.keyUp(key); };
    const menu = (item) => {
        press('menu');
        const items = radio.display().panel.items;
        const index = items.indexOf(item);
        assert.ok(index >= 0, `${item} fehlt im Menü: ${items}`);
        radio.turn('channel', index);
        press('enter');
    };

    return { radio, events, wait, press, menu, last: (type) => events.filter((e) => e.type === type).at(-1), advance: (ms) => { now += ms; } };
}

test('an individual call is answered with Able to Comply and the radio goes to the proposed channel', () => {
    const r = rig({ channel: 16 });
    r.radio.receive({ type: 'individual', from: moewe, category: 'Routine', channel: 72 });
    assert.equal(r.radio.display().lines[1], 'Individual');
    r.press('clr');
    assert.ok(r.last('alarm_stopped'));
    assert.equal(r.radio.state.screen, 'received');
    r.press('enter');
    assert.equal(r.radio.display().panel.subtitle, 'Select Action');
    r.press('enter'); // Able to Comply
    r.press('enter'); // senden
    const sent = r.last('dsc_sent');
    assert.equal(sent.kind, 'individual_ack');
    assert.equal(sent.action, 'able');
    assert.equal(sent.address.mmsi, moewe.mmsi);
    r.wait(2100);
    assert.equal(r.radio.state.channel, 72);
});

test('Individual ACK is offered in the menu after an individual call and Unable to Comply keeps the channel', () => {
    const r = rig({ channel: 16 });
    r.radio.receive({ type: 'individual', from: moewe, category: 'Routine', channel: 72 });
    r.press('clr');
    r.press('clr');
    r.menu('Individual ACK');
    r.radio.turn('channel', 1);
    r.press('enter');
    r.press('enter');
    assert.equal(r.last('dsc_sent').action, 'unable');
    r.wait(2100);
    assert.equal(r.radio.state.channel, 16);
    r.press('menu');
    assert.ok(! r.radio.display().panel.items.includes('Individual ACK'));
});

test('a group call goes to a group from the address book with a working channel', () => {
    const r = rig();
    r.menu('Group Call');
    assert.deepEqual(r.radio.display().panel.items, ['Manual Input', 'FLOTILLE']);
    r.radio.turn('channel', 1);
    r.press('enter');
    r.press('enter'); // Routine
    r.radio.turn('channel', 1); // 09 -> Kanal nach 08
    r.press('enter');
    r.press('enter');
    const sent = r.last('dsc_sent');
    assert.equal(sent.kind, 'group');
    assert.equal(sent.address.mmsi, flotilla.mmsi);
    assert.equal(sent.channel, 9);
    r.wait(2100);
    assert.equal(r.radio.state.channel, 9);
});

test('position request and polling wait for the reply; a received request is answered from the menu', () => {
    const r = rig({ position: [54, 10, 'N', 7, 52, 'E'], utc: '12:30' });
    r.menu('Position Request');
    r.radio.turn('channel', 1);
    r.press('enter');
    r.press('enter');
    assert.equal(r.last('dsc_sent').kind, 'position_request');
    r.wait(2100);
    assert.equal(r.radio.state.screen, 'normal');
    r.radio.receive({ type: 'position_reply', from: moewe, position: [54, 12, 'N', 7, 40, 'E'] });
    r.press('clr');
    r.press('enter');
    assert.deepEqual(r.last('position_info').position, [54, 12, 'N', 7, 40, 'E']);
    r.press('clr');

    r.radio.receive({ type: 'polling_request', from: moewe });
    r.press('clr');
    r.press('clr');
    r.menu('Polling Reply');
    r.press('enter');
    assert.equal(r.last('dsc_sent').kind, 'polling_reply');
    assert.equal(r.last('dsc_sent').address.mmsi, moewe.mmsi);
});

test('Add:INDV ID stores a ship and refuses a group MMSI', () => {
    const r = rig({}, { directory: [{ name: 'SEESTERN', mmsi: '211555000' }] });
    r.menu('Set up');
    r.press('enter'); // Add:INDV ID
    for (const d of '211555000') { r.radio.turn('channel', Number(d)); r.press('ch16'); }
    r.press('enter');
    assert.equal(r.last('address_added').name, 'SEESTERN');
    assert.ok(r.radio.state.addressBook.includes('211555000'));
    r.radio.turn('channel', 1); // Add:Group ID
    r.press('enter');
    for (const d of '211000001') { r.radio.turn('channel', Number(d)); r.press('ch16'); }
    r.press('enter');
    assert.equal(r.last('address_invalid').group, true);
});

test('scan needs TAG channels and stops on a voice on a tagged channel', () => {
    const r = rig({ channel: 72 });
    r.press('scan');
    assert.equal(r.last('scan_blocked').reason, 'no_tag');
    r.press('scan', 1200);
    assert.deepEqual(r.radio.state.tags, [72]);
    r.radio.turn('channel', 1);
    r.press('scan', 1200);
    r.press('scan');
    assert.equal(r.radio.state.scanning, true);
    assert.equal(r.radio.display().watch, 'PRI-SCAN 16');
    assert.equal(r.radio.hear({ channel: 72, text: 'Kormoran this is Moewe' }).heard, true);
    assert.equal(r.radio.state.scanning, false);
    assert.equal(r.radio.state.channel, 72);
});

test('dual watch hears channel 16, tri watch also the call channel; Set mode switches to tri watch', () => {
    const r = rig({ channel: 72 });
    assert.equal(r.radio.hear({ channel: 16, text: 'x' }).reason, 'channel');
    r.press('dial', 1200);
    assert.equal(r.radio.display().watch, 'DUAL 16');
    assert.equal(r.radio.hear({ channel: 16, text: 'x' }).heard, true);

    r.press('power', 1200);
    r.radio.keyDown('ch16');
    r.press('power');
    r.radio.keyUp('ch16');
    assert.equal(r.radio.state.screen, 'setMode');
    r.radio.turn('channel', 2);
    r.press('enter');
    assert.equal(r.last('set_mode').value, 'tri');
    r.press('clr');
    r.press('dial', 1200);
    assert.equal(r.radio.display().watch, 'TRI 16');
});

test('nothing is heard with the radio off, the volume down or the squelch closed', () => {
    const r = rig({ channel: 72, vol: 0 });
    assert.equal(r.radio.hear({ channel: 72, text: 'x' }).reason, 'volume');
    r.radio.turn('vol', 5);
    r.radio.turn('sql', 6);
    assert.equal(r.radio.hear({ channel: 72, text: 'x' }).reason, 'squelch');
    r.press('power', 1200);
    assert.equal(r.radio.hear({ channel: 72, text: 'x' }).reason, 'off');
});

test('a manual position is shown as stale after four hours', () => {
    const r = rig({ position: [54, 10, 'N', 7, 52, 'E'], utc: '12:30' });
    assert.equal(r.radio.state.positionStale, false);
    r.advance(4 * 60 * 60 * 1000 + 1000);
    assert.equal(r.radio.state.positionStale, true);
    assert.ok(r.radio.display().lines[0].startsWith('??'));
});

test('a received distress alert switches to channel 16 and CLR first silences the alarm', () => {
    const r = rig({ channel: 72 });
    r.radio.receive({ type: 'distress', from: moewe, nature: 'Flooding', position: [54, 12, 'N', 7, 40, 'E'] });
    assert.equal(r.radio.state.channel, 16);
    assert.equal(r.radio.display().alarm, true);
    r.press('clr');
    assert.equal(r.radio.display().alarm, false);
    r.press('clr');
    assert.equal(r.radio.state.screen, 'normal');
});
