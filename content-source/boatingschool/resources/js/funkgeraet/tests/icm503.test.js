// Gerätelogik IC-M503 mit DSC-Controller DS-100 (Stufe 5).

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createIcM503 } from '../devices/icm503.js';

const own = { name: 'KORMORAN', callsign: 'DH4721', mmsi: '211472100', atis: '9211234721' };
const moewe = { name: 'MOEWE', mmsi: '211839200' };
const bremen = { name: 'BREMEN RESCUE', mmsi: '002111240' };
const flotilla = { name: 'FLOTILLE', mmsi: '021100001', group: true };

function rig(start = { power: true, vol: 5, sql: 3, channel: 16 }) {
    let now = 0;
    const events = [];
    const radio = createIcM503({ own, clock: () => now, emit: (e) => events.push(e), addressBook: [moewe, bremen, flotilla], directory: [{ name: 'SEESTERN', mmsi: '211555000' }] });
    radio.setup(start);
    const wait = (ms) => { for (let t = 0; t < ms; t += 100) { now += 100; radio.tick(); } };
    const press = (key, ms = 100) => { radio.keyDown(key); wait(ms); radio.keyUp(key); };
    const pick = (item) => {
        const ds = radio.display().ds;
        for (let i = 0; i < 20 && radio.display().ds.items[radio.display().ds.cursor] !== item; i++) press('dsDown');
        assert.equal(radio.display().ds.items[radio.display().ds.cursor], item, `${item} fehlt: ${ds.items}`);
        press('dsEnt');
    };
    const type = (digits) => { for (const d of digits) press(`d${d}`); press('dsEnt'); };
    const send = () => { press('dsCall'); press('dsEnt'); };

    return { radio, events, wait, press, pick, type, send, last: (t) => events.filter((e) => e.type === t).at(-1) };
}

test('radio: POWER toggles, 16 forces 25 W, CALL selects the call channel, DIAL returns, HI/LO+DIAL changes the group', () => {
    const r = rig({ vol: 0, sql: 3 });
    r.press('power');
    assert.equal(r.radio.state.power, true);
    r.radio.turn('channel', 3);
    r.press('hl');
    assert.equal(r.radio.state.highPower, false);
    r.press('ch16');
    assert.equal(r.radio.state.channel, 16);
    assert.equal(r.last('power_level').auto, true);
    r.press('call');
    assert.equal(r.last('call_channel').channel, 9);
    r.press('dial');
    assert.notEqual(r.radio.state.channel, 9);
    r.radio.keyDown('hl'); r.radio.keyDown('dial'); r.radio.keyUp('dial'); r.radio.keyUp('hl');
    assert.equal(r.radio.state.group, 'ATIS');
    r.press('power');
    assert.equal(r.radio.state.power, false);
});

test('Set mode via 16 + POWER switches to tri watch; DUAL starts it', () => {
    const r = rig({ power: false, vol: 5, sql: 3, channel: 72 });
    r.radio.keyDown('ch16');
    r.press('power');
    r.radio.keyUp('ch16');
    assert.equal(r.radio.display().setMode.item, 'Scan mode');
    r.press('ch16');
    r.press('ch16');
    r.radio.turn('channel', 1);
    assert.equal(r.last('set_mode').value, 'tri');
    r.press('power');
    r.press('dual');
    assert.equal(r.last('watch').mode, 'tri');
});

test('DS-100: manual position and time via the keypad, MMSI check in Set-up', () => {
    const r = rig();
    r.press('dsCall');
    r.pick('Entry Position/Time');
    r.type('541000752'); // 54°10'N 007°52'E, N/E bleiben
    assert.deepEqual(r.last('position_set').position, [54, 10, 'N', 7, 52, 'E']);
    r.type('1230');
    assert.equal(r.last('utc_set').utc, '12:30');
    r.press('dsCall');
    r.pick('Set-up');
    r.pick('MMSI CHECK');
    assert.equal(r.last('mmsi_checked').mmsi, own.mmsi);
});

test('DS-100 distress: nature, 5 s hold, radio on 70 then 16, CLR while waiting sends a cancel', () => {
    const r = rig({ power: true, vol: 5, sql: 3, channel: 72, position: [54, 10, 'N', 7, 52, 'E'], utc: '12:30' });
    r.press('dsCall');
    r.pick('Distress setting');
    r.pick('Flooding');
    assert.equal(r.last('nature_selected').nature, 'Flooding');
    assert.equal(r.radio.state.screen, 'distressReady');
    r.press('distressCover');
    r.press('distress', 3000);
    assert.ok(r.last('distress_released_early'));
    r.radio.keyDown('distress');
    r.wait(5100);
    r.radio.keyUp('distress');
    assert.equal(r.last('dsc_sent').kind, 'distress');
    assert.equal(r.radio.state.channel, 70);
    r.wait(1600);
    assert.equal(r.radio.state.channel, 16);
    r.press('dsClr');
    assert.equal(r.last('dsc_sent').kind, 'distress_cancel');
    assert.ok(r.last('distress_repeat_cancelled'));
});

test('DS-100 individual call: category selectable, CALL+ENT sends, ENT on the acknowledgement sets the channel', () => {
    const r = rig();
    r.press('dsCall');
    r.pick('Individual call');
    r.pick('MOEWE');
    r.pick('Routine');
    while (r.radio.display().ds.lines[1].trim() !== 'CH 72') r.press('dsUp');
    r.press('dsEnt');
    r.press('dsEnt');
    assert.equal(r.last('dsc_sent'), undefined, 'ENT allein sendet nicht');
    r.send();
    assert.deepEqual([r.last('dsc_sent').kind, r.last('dsc_sent').category, r.last('dsc_sent').channel], ['individual', 'Routine', 72]);
    r.wait(1600);
    r.radio.receive({ type: 'individual_ack', from: moewe });
    r.press('dsClr');
    assert.ok(r.last('alarm_stopped'));
    r.press('dsEnt');
    assert.equal(r.radio.state.channel, 72);
});

test('DS-100 to a coast station without channel; urgency category is possible', () => {
    const r = rig();
    r.press('dsCall');
    r.pick('Individual call');
    r.pick('BREMEN RESCUE');
    r.pick('Urgency');
    assert.equal(r.radio.state.screen, 'callReady');
    r.send();
    assert.deepEqual([r.last('dsc_sent').category, r.last('dsc_sent').channel], ['Urgency', null]);
});

test('received individual call: CLR silences, ENT, Able to comply sends the acknowledgement and sets the channel', () => {
    const r = rig();
    r.radio.receive({ type: 'individual', from: moewe, category: 'Routine', channel: 72 });
    r.press('dsClr');
    r.press('dsEnt');
    r.pick('Able to comply');
    assert.equal(r.last('dsc_sent').action, 'able');
    r.wait(1600);
    assert.equal(r.radio.state.channel, 72);
});

test('received distress switches the radio to 16 automatically; all ships call needs ENT', () => {
    const r = rig({ power: true, vol: 5, sql: 3, channel: 72 });
    r.radio.receive({ type: 'distress', from: moewe, nature: 'Flooding' });
    assert.equal(r.radio.state.channel, 16);
    r.press('dsClr');
    r.press('dsClr');
    r.press('dial');
    r.radio.receive({ type: 'all_ships', from: bremen, category: 'Urgency', channel: 16 });
    assert.notEqual(r.radio.state.channel, 16);
    r.press('dsClr');
    r.press('dsEnt');
    assert.equal(r.last('call_accepted').channel, 16);
});

test('group call and all ships call (channel 16 automatically); address book entry via Set-up', () => {
    const r = rig();
    r.press('dsCall');
    r.pick('All ships call');
    r.pick('Safety');
    r.send();
    assert.deepEqual([r.last('dsc_sent').kind, r.last('dsc_sent').category, r.last('dsc_sent').channel], ['all_ships', 'Safety', 16]);
    r.wait(1600);
    r.press('dsCall');
    r.pick('Group call');
    r.pick('FLOTILLE');
    r.pick('Routine');
    r.press('dsEnt');
    r.send();
    r.wait(1600);
    assert.equal(r.radio.state.channel, 8);
    r.press('dsCall');
    r.pick('Set-up');
    r.pick('Address ID');
    r.pick('Add an address ID');
    r.type('211555000');
    assert.equal(r.last('address_added').name, 'SEESTERN');
});

test('ATIS: no channel 16, DS-100 blocked, ATIS identification after transmitting', () => {
    const r = rig({ power: true, vol: 5, sql: 3, group: 'ATIS', channel: 10, highPower: false });
    r.press('ch16');
    assert.ok(r.last('ch16_unavailable'));
    r.press('dsCall');
    assert.equal(r.last('dsc_blocked').key, 'menu');
    r.radio.pttDown();
    r.radio.pttUp();
    assert.equal(r.last('atis_sent').id, own.atis);
});
