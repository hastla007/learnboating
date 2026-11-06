// Gerätelogik Icom IC-M323 (Stufe 4 der Gerätesimulation), nach dem
// Geräteprofil aus der deutschen Bedienungsanleitung: Drehknopf VOL/SQL·PWR
// (1 s = Ein/Aus, kurz = Lautstärke, Rauschsperre, Kanalwahl), Wippe
// ▲▼◀▶ mit ENT, MENU, CLEAR, 16/C, vier Softkeys mit blätterbarer Leiste,
// DISTRESS 3 s unter der Klappe. DSC Klasse D: Einzel- und Gruppenanruf nur
// Routine, Anruf an alle nur Safety/Urgency, kein Senden von Positions- oder
// Polling-Abfragen, dafür Testanruf und Distress Cancel.
//
// Gleiche Schnittstelle und gleiche Ereignisnamen wie die Gerätelogik des
// IC-M505, damit die Lektionen auf beiden Geräten laufen. Die Art der Not
// wird in Ereignissen mit den Namen des IC-M505 gemeldet ("nature"), die
// Anzeige des IC-M323 steht in "label".

import { CHANNEL_GROUPS, channelInfo, stepChannel, stepIntership } from '../channels.js';
import { NATURES } from './icm505.js';

export const NATURE_LABELS = ['Undesignated', 'Fire,Explosion', 'Flooding', 'Collision', 'Grounding', 'Capsizing', 'Sinking', 'Adrift', 'Abandoning ship', 'Piracy', 'Man Overboard'];

export const DEVICE = {
    key: 'ic-m323',
    name: 'Icom IC-M323',
    keys: ['distressCover', 'distress', 'up', 'down', 'left', 'right', 'enter', 'menu', 'clr', 'ch16', 'knob', 'soft1', 'soft2', 'soft3', 'soft4', 'micUp', 'micDown', 'micHiLo'],
    knobs: ['dial'],
};

const LONG = 1000;
// Zustände, in denen das Gerät nicht empfängt (eigene Aussendung, DISTRESS halten).
const BUSY = ['callTx', 'distressSent', 'distressHold'];
const DISTRESS_HOLD = 3000;
const DISTRESS_REPEAT = 4 * 60 * 1000;
const TRANSMIT_MS = 1000;
const CH16_DELAY = 10000;
const WINDOW_MS = 5000;
const SCAN_STEP = 500;
const POSITION_STALE = 4 * 60 * 60 * 1000;

const RING = ['SCAN', 'DW', 'HI/LO', 'CHAN', 'LO/DX', 'AQUA', '★', 'NAME', 'BKLT', 'LOG'];
// IC-M423 (GE): längere Softkey-Leiste; der Rücksprung heißt CH/WX.
const RING_M423 = ['SCAN', 'DW', 'HI/LO', 'CH/WX', 'PA', 'RX', 'HORN', 'INCM', 'LO/DX', 'AQUA', '★', 'NAME', 'BKLT', 'LOG'];

export const DEVICE_M423 = {
    key: 'ic-m423',
    name: 'Icom IC-M423',
    keys: ['distressCover', 'distress', 'up', 'down', 'left', 'right', 'enter', 'menu', 'clr', 'ch16', 'knob', 'soft1', 'soft2', 'soft3', 'soft4', 'micUp', 'micDown', 'micHiLo', 'mic16'],
    knobs: ['dial'],
};
const MAIN_MENU = ['DSC Calls', 'DSC Settings', 'Radio Settings', 'Configuration', 'MMSI/GPS Info'];
const DSC_SETTINGS = ['Position Input', 'Individual ID', 'Group ID', 'Individual ACK', 'Position ACK', 'Test ACK', 'CH 16 Switch', 'Alarm', 'CH 70 SQL Level', 'DSC Loop Test'];
const RADIO_SETTINGS = ['Scan Type', 'Scan Timer', 'Dual/Tri-Watch', 'CHAN Group'];
const AVAILABLE = new Set([
    'DSC Calls', 'DSC Settings', 'Radio Settings', 'MMSI/GPS Info', 'Individual Call', 'Individual ACK', 'Group Call', 'All Ships Call',
    'Distress Call', 'Received Call Log', 'Position Reply', 'Polling Reply', 'Test Call', 'Test ACK', 'Position Input', 'Individual ID', 'Group ID',
    'Dual/Tri-Watch', 'CHAN Group',
]);
const TITLES = {
    individual: 'INDIVIDUAL CALL', group: 'GROUP CALL', all_ships: 'ALL SHIPS CALL', individual_ack: 'INDIVIDUAL ACK', test: 'TEST CALL',
    test_ack: 'TEST ACK', position_reply: 'POSITION REPLY', polling_reply: 'POLLING REPLY',
};
const RECEIVED = {
    distress: 'RCVD DISTRESS', distress_ack: 'RCVD DISTRESS', distress_relay: 'RCVD DISTRESS RELAY', individual: 'RCVD INDIVIDUAL CALL',
    group: 'RCVD GROUP CALL', all_ships: 'RCVD ALL SHIPS CALL', geographic: 'RCVD GEOGRAPHICAL', position_request: 'RCVD POS REQUEST',
    polling_request: 'RCVD POLLING REQUEST', test_call: 'RCVD TEST CALL',
};
// Angenommene Anrufe, bei denen ACPT auf einen Kanal schaltet.
const ACCEPT_CHANNEL = new Set(['group', 'all_ships', 'geographic']);

function pad(n, width = 2) {
    return String(n).padStart(width, '0');
}

function formatPosition(pos) {
    if (! pos) return null;
    const [latD, latM, ns, lonD, lonM, ew] = pos;

    return [`${pad(latD)}°${latM.toFixed(4).padStart(7, '0')}${ns}`, `${pad(lonD, 3)}°${lonM.toFixed(4).padStart(7, '0')}${ew}`];
}

// Das IC-M423 ist laut Anleitung in Menüs, DSC und Notalarm gleich
// aufgebaut; es unterscheidet sich in der Softkey-Leiste und der
// 16/C-Taste am Hörer (variant "ic-m423").
export function createIcM423(options = {}) {
    return createIcM323({ ...options, variant: 'ic-m423' });
}

export function createIcM323({ own, clock = () => Date.now(), emit = () => {}, addressBook = [], directory = [], variant = 'ic-m323' } = {}) {
    const ring = variant === 'ic-m423' ? RING_M423 : RING;
    const s = {
        power: false,
        vol: 0,
        sql: 0,
        group: 'INT',
        channel: 16,
        workChannel: 16,
        callChannel: { INT: 16, DSC: 16, ATIS: 10 },
        highPower: true,
        watch: 'off',
        watchMode: 'dual',
        scan: null,
        tags: new Set(),
        tx: false,
        screen: 'off',
        screenSince: 0,
        cover: false,
        ring: 0,
        knobMode: 'vol',
        windowUntil: 0,
        menu: null,
        cursor: 0,
        nature: 0,
        position: null,
        utc: null,
        positionSetAt: null,
        editor: null,
        call: null,
        distress: null,
        incoming: null,
        received: [],
        book: addressBook.filter((a) => ! a.group).map((a) => ({ ...a })),
        groups: addressBook.filter((a) => a.group).map((a) => ({ ...a })),
        ackable: null,
        testAckable: null,
        onCall: false,
    };
    const pressed = {};

    const event = (type, data = {}) => emit({ type, at: clock(), ...data, radio: snapshot() });

    function current() {
        return channelInfo(s.group, s.channel);
    }

    function effectiveHighPower() {
        return s.highPower && ! current()?.lowPowerOnly;
    }

    function positionStale() {
        return Boolean(s.position && s.positionSetAt !== null && clock() - s.positionSetAt > POSITION_STALE);
    }

    function snapshot() {
        return {
            power: s.power, group: s.group, channel: s.channel, highPower: effectiveHighPower(), vol: s.vol, sql: s.sql, screen: s.screen, watch: s.watch,
            nature: NATURES[s.nature], position: s.position, utc: s.utc, coverOpen: s.cover, tx: s.tx, scanning: Boolean(s.scan), tags: [...s.tags].sort((a, b) => a - b),
            addressBook: s.book.map((a) => a.mmsi), groups: s.groups.map((a) => a.mmsi), positionStale: positionStale(), circle: current()?.circle ?? null,
            knobMode: s.knobMode, softkeys: softkeys(),
        };
    }

    // Anrufe, die während der eigenen Aussendung eintreffen.
    let pending = [];

    function setScreen(screen) {
        const changed = s.screen !== screen;
        s.screen = screen;
        s.screenSince = clock();
        if (changed) event('screen', { screen });
    }

    function setChannel(channel, { work = true, group = s.group } = {}) {
        if (s.scan) stopScan();
        s.group = group;
        s.channel = channel;
        s.onCall = false;
        if (work && channel !== 16 && channel !== s.callChannel[s.group]) s.workChannel = channel;
        event('channel', { channel, group, circle: channelInfo(group, channel)?.circle ?? null });
    }

    function squelchState() {
        if (! s.power) return 'off';
        if (s.sql <= 0) return 'noise';
        if (s.sql >= 7) return 'tight';

        return 'set';
    }

    function backToNormal() {
        s.menu = null;
        s.editor = null;
        setScreen(s.distress?.waiting ? 'distressWait' : 'normal');
    }

    // --- Softkeys ------------------------------------------------------------

    function softkeys() {
        switch (s.screen) {
            case 'off':
            case 'callTx':
            case 'distressHold':
            case 'distressSent':
                return ['', '', '', ''];
            case 'menu':
                return s.menu.list === 'idList' ? ['ADD', 'EDIT', 'DEL', 'EXIT'] : ['EXIT', 'BACK', '', 'ENT'];
            case 'editor':
                return ['EXIT', 'BACK', '', 'FINISH'];
            case 'callChannel':
            case 'ackChoice':
            case 'distressConfirm':
                return ['EXIT', 'BACK', '', s.screen === 'distressConfirm' ? '' : 'ENT'];
            case 'callConfirm':
                return ['EXIT', 'BACK', '', 'CALL'];
            case 'callWait':
            case 'callResult':
            case 'cancelDone':
            case 'mmsiInfo':
                return ['EXIT', '', '', ''];
            case 'ackAlarm':
            case 'rcvdAlarm':
                return ['ALARM OFF', 'ALARM OFF', 'ALARM OFF', 'ALARM OFF'];
            case 'rcvdAction': {
                const t = s.incoming?.type;
                return ['IGN', 'INFO', '', ['individual', 'test_call', 'position_request', 'polling_request'].includes(t) ? 'ACK' : 'ACPT'];
            }
            case 'rcvdCountdown':
                return ['IGN', 'INFO', '', 'CH 16'];
            case 'distressWait':
                return ['CANCEL', 'RESEND', '', ''];
            case 'cancelConfirm':
                return ['BACK', '', '', 'CONTINUE'];
            case 'cancelReport':
                return ['EXIT', '', '', 'FINISH'];
            default:
                return [0, 1, 2, 3].map((i) => ring[(s.ring + i) % ring.length]);
        }
    }

    function softkey(index) {
        const label = softkeys()[index];
        if (! label) return;
        event('softkey', { label });

        switch (label) {
            case 'SCAN':
                if (s.scan) stopScan();
                else startScan();
                break;
            case 'DW':
                if (s.watch !== 'off') {
                    s.watch = 'off';
                    event('watch', { mode: 'off' });
                } else {
                    if (s.scan) stopScan();
                    s.watch = s.watchMode;
                    event('watch', { mode: s.watch, channel: s.channel });
                }
                break;
            case 'HI/LO':
                toggleHighPower();
                break;
            case 'CHAN':
            case 'CH/WX':
                setChannel(s.workChannel);
                break;
            case '★': {
                const on = ! s.tags.has(s.channel);
                if (on) s.tags.add(s.channel);
                else s.tags.delete(s.channel);
                event('tag', { channel: s.channel, on });
                break;
            }
            case 'LOG':
                openMenu('RCVD CALL LOG', receivedLines(), null, 'log');
                break;
            case 'EXIT':
                s.call = null;
                backToNormal();
                break;
            case 'BACK':
                back();
                break;
            case 'ENT':
                enter();
                break;
            case 'FINISH':
                if (s.screen === 'cancelReport') sendDistressCancel();
                else editorFinish();
                break;
            case 'CALL':
                sendCall();
                break;
            case 'ADD':
                startEditor(s.menu.kind === 'groups' ? 'addGroup' : 'addIndv');
                break;
            case 'ALARM OFF':
                alarmOff();
                break;
            case 'IGN':
                event('call_ignored', { call: s.incoming?.type });
                s.incoming = null;
                backToNormal();
                break;
            case 'INFO':
                event('call_info', { call: s.incoming?.type });
                break;
            case 'ACK':
                acknowledgeIncoming();
                break;
            case 'ACPT':
                acceptIncoming();
                break;
            case 'CH 16':
                switchTo16AfterAccept();
                break;
            case 'CANCEL':
                setScreen('cancelConfirm');
                break;
            case 'RESEND':
                sendDistress(true);
                break;
            case 'CONTINUE':
                setScreen('cancelReport');
                break;
            default:
                event('softkey_unavailable', { label });
        }
    }

    function toggleHighPower() {
        if (current()?.lowPowerOnly) {
            event('power_level_blocked', { channel: s.channel });

            return;
        }
        s.highPower = ! s.highPower;
        event('power_level', { high: s.highPower, channel: s.channel });
    }

    // --- Menüs -----------------------------------------------------------------

    function dscCallItems() {
        return [
            'Individual Call', s.ackable ? 'Individual ACK' : null, 'Group Call', 'All Ships Call', 'Distress Call', 'Received Call Log',
            'Transmitted Call Log', 'Test Call', s.testAckable ? 'Test ACK' : null,
        ].filter(Boolean);
    }

    function openMenu(title, items, back = null, list = 'menu', kind = null) {
        s.menu = { title, items, back, list, kind };
        s.cursor = 0;
        setScreen('menu');
    }

    function openMain() {
        openMenu('MENU', MAIN_MENU);
    }

    function back() {
        if (s.screen === 'menu' && s.menu.back) {
            s.menu.back();

            return;
        }
        if (s.screen === 'editor' && s.editor.back) {
            const fn = s.editor.back;
            s.editor = null;
            fn();

            return;
        }
        if (['callChannel', 'callConfirm', 'ackChoice', 'distressConfirm'].includes(s.screen) && s.call?.back) {
            s.call.back();

            return;
        }
        s.call = null;
        backToNormal();
    }

    function receivedLines() {
        return s.received.length ? s.received.slice(0, 8).map((r) => `${RECEIVED[r.type]?.replace('RCVD ', '') ?? r.type}`) : ['No Call'];
    }

    function chooseMenuItem() {
        const item = s.menu.items[s.cursor];
        if (s.menu.list === 'select') {
            s.menu.onSelect(item);

            return;
        }
        if (s.menu.list === 'address') {
            chooseAddress(s.cursor);

            return;
        }
        if (s.menu.list === 'log' || s.menu.list === 'idList') return;

        if (! AVAILABLE.has(item)) {
            event('menu_unavailable', { item });

            return;
        }
        event('menu', { item });

        switch (item) {
            case 'DSC Calls':
                // Im Binnenmodus (ATIS) ist DSC gesperrt.
                if (s.group === 'ATIS') {
                    event('dsc_blocked', { group: s.group, key: 'menu' });

                    return;
                }
                openMenu('DSC CALLS', dscCallItems(), openMain);
                break;
            case 'DSC Settings':
                openMenu('DSC SETTINGS', DSC_SETTINGS, openMain);
                break;
            case 'Radio Settings':
                openMenu('RADIO SETTINGS', RADIO_SETTINGS, openMain);
                break;
            case 'MMSI/GPS Info':
                setScreen('mmsiInfo');
                event('mmsi_checked', { mmsi: own?.mmsi });
                break;
            case 'Dual/Tri-Watch':
                openSelect('DUAL/TRI-WATCH', ['Dualwatch', 'Tri-watch'], (value) => {
                    s.watchMode = value === 'Tri-watch' ? 'tri' : 'dual';
                    event('set_mode', { item: 'Dual/Tri', value: s.watchMode });
                    openMenu('RADIO SETTINGS', RADIO_SETTINGS, openMain);
                });
                s.cursor = s.watchMode === 'tri' ? 1 : 0;
                break;
            case 'CHAN Group':
                openSelect('CHAN GROUP', ['INT', 'ATIS', 'DSC'], (group) => {
                    const ch = channelInfo(group, s.channel) ? s.channel : CHANNEL_GROUPS[group][0].number;
                    setChannel(ch, { group });
                    event('group', { group });
                    openMenu('RADIO SETTINGS', RADIO_SETTINGS, openMain);
                });
                s.cursor = ['INT', 'ATIS', 'DSC'].indexOf(s.group);
                break;
            case 'Position Input':
                startEditor('position', () => openMenu('DSC SETTINGS', DSC_SETTINGS, openMain));
                break;
            case 'Individual ID':
                openMenu('INDIVIDUAL ID', s.book.length ? s.book.map((a) => a.name) : ['No ID'], () => openMenu('DSC SETTINGS', DSC_SETTINGS, openMain), 'idList', 'book');
                break;
            case 'Group ID':
                openMenu('GROUP ID', s.groups.length ? s.groups.map((a) => a.name) : ['No ID'], () => openMenu('DSC SETTINGS', DSC_SETTINGS, openMain), 'idList', 'groups');
                break;
            case 'Received Call Log':
                openMenu('RCVD CALL LOG', receivedLines(), () => openMenu('DSC CALLS', dscCallItems(), openMain), 'log');
                break;
            case 'Distress Call':
                openSelect('DISTRESS CALL', NATURE_LABELS, (label) => {
                    s.nature = NATURE_LABELS.indexOf(label);
                    event('nature_selected', { nature: NATURES[s.nature], label });
                    if (! s.position) startEditor('position', null, 'distress');
                    else setScreen('distressConfirm');
                }, () => openMenu('DSC CALLS', dscCallItems(), openMain));
                s.cursor = s.nature;
                break;
            case 'Individual Call':
            case 'Group Call':
            case 'Test Call':
                s.call = { kind: { 'Individual Call': 'individual', 'Group Call': 'group', 'Test Call': 'test' }[item] };
                openAddressList();
                break;
            case 'All Ships Call':
                s.call = { kind: 'all_ships' };
                openSelect('ALL SHIPS CALL', ['Safety', 'Urgency'], (category) => {
                    s.call.category = category;
                    s.call.channel = 16;
                    s.call.channelLabel = 'ITU CH';
                    setScreen('callChannel');
                }, () => openMenu('DSC CALLS', dscCallItems(), openMain));
                break;
            case 'Individual ACK':
                startAck(s.ackable);
                break;
            case 'Test ACK':
                s.call = { kind: 'test_ack', address: s.testAckable.from, category: 'Safety' };
                setScreen('callConfirm');
                break;
        }
    }

    function openSelect(title, items, onSelect, backFn = () => openMenu('RADIO SETTINGS', RADIO_SETTINGS, openMain)) {
        openMenu(title, items, backFn, 'select');
        s.menu.onSelect = onSelect;
    }

    function addressList() {
        return s.call.kind === 'group' ? s.groups : s.book;
    }

    function openAddressList() {
        openMenu(TITLES[s.call.kind], ['Manual Input', ...addressList().map((a) => a.name)], () => openMenu('DSC CALLS', dscCallItems(), openMain), 'address');
    }

    function chooseAddress(index) {
        if (index === 0) {
            startEditor(s.call.kind === 'group' ? 'groupId' : 'indId', openAddressList);

            return;
        }
        s.call.address = addressList()[index - 1];
        afterAddress();
    }

    function afterAddress() {
        const c = s.call;
        if (c.kind === 'test') {
            c.category = 'Safety';
            setScreen('callConfirm');

            return;
        }
        // Kategorie ist beim Einzel- und Gruppenanruf fest Routine. Bei einer
        // Küstenfunkstelle (MMSI 00…) bestimmt diese den Kanal.
        c.category = 'Routine';
        if (c.kind === 'individual' && c.address.mmsi.startsWith('00')) {
            c.channel = null;
            setScreen('callConfirm');
        } else {
            c.channel = 8;
            c.channelLabel = 'Intership CH';
            setScreen('callChannel');
        }
        c.back = openAddressList;
    }

    function startAck(incoming) {
        s.call = { kind: 'individual_ack', address: incoming.from, channel: incoming.channel ?? null, category: incoming.category ?? 'Routine', back: backToNormal };
        s.cursor = 0;
        setScreen('ackChoice');
    }

    function sendCall() {
        const c = s.call;
        if (! c) return;
        event('dsc_sent', { kind: c.kind === 'test' ? 'test' : c.kind, address: c.address ?? null, category: c.category ?? null, channel: c.channel ?? null, action: c.action ?? null });
        if (c.kind === 'individual_ack') s.ackable = null;
        if (c.kind === 'test_ack') s.testAckable = null;
        c.sentAt = clock();
        c.waiting = ['individual', 'test'].includes(c.kind);
        setScreen('callTx');
    }

    // --- Editor (Ziffern) --------------------------------------------------------

    function startEditor(kind, backFn = null, then = null) {
        const templates = {
            position: { title: 'POSITION INPUT', template: '##°##.####N ###°##.####E' },
            utc: { title: 'POSITION INPUT', template: '##:##' },
            indId: { title: 'INDIVIDUAL CALL', template: '#########' },
            groupId: { title: 'GROUP CALL', template: '0########' },
            addIndv: { title: 'INDIVIDUAL ID', template: '#########' },
            addGroup: { title: 'GROUP ID', template: '0########' },
        };
        const t = templates[kind];
        const chars = t.template.split('').map((c) => (c === '#' ? '0' : c));
        const editable = t.template.split('').map((c, i) => (c === '#' || c === 'N' || c === 'E' ? i : -1)).filter((i) => i >= 0);
        if (kind === 'groupId' || kind === 'addGroup') editable.shift();
        s.editor = { kind, title: t.title, chars, editable, pos: 0, back: backFn, then };
        setScreen('editor');
    }

    function editorTurn(steps) {
        const e = s.editor;
        const i = e.editable[e.pos];
        const c = e.chars[i];
        if (c === 'N' || c === 'S') e.chars[i] = c === 'N' ? 'S' : 'N';
        else if (c === 'E' || c === 'W') e.chars[i] = c === 'E' ? 'W' : 'E';
        else e.chars[i] = String((((Number(c) + steps) % 10) + 10) % 10);
    }

    function editorMove(direction) {
        const e = s.editor;
        e.pos = Math.max(0, Math.min(e.editable.length - 1, e.pos + direction));
    }

    // ENT übernimmt die Stelle und rückt vor; nach der letzten Stelle ist
    // FINISH markiert, ein weiteres ENT schließt ab.
    function editorEnter() {
        const e = s.editor;
        if (e.pos < e.editable.length - 1) {
            e.pos++;

            return;
        }
        editorFinish();
    }

    function editorFinish() {
        const e = s.editor;
        if (! e) return;
        const text = e.chars.join('');

        if (e.kind === 'position') {
            const m = text.match(/^(\d\d)°(\d\d\.\d{4})([NS]) (\d\d\d)°(\d\d\.\d{4})([EW])$/);
            s.position = [Number(m[1]), Number(m[2]), m[3], Number(m[4]), Number(m[5]), m[6]];
            s.positionSetAt = clock();
            event('position_set', { position: s.position });
            const then = e.then;
            const backFn = e.back;
            startEditor('utc', backFn, then);
        } else if (e.kind === 'utc') {
            s.utc = text;
            event('utc_set', { utc: s.utc });
            const then = e.then;
            s.editor = null;
            if (then === 'distress') setScreen('distressConfirm');
            else openMenu('DSC SETTINGS', DSC_SETTINGS, openMain);
        } else if (e.kind === 'indId' || e.kind === 'groupId') {
            if (e.kind === 'indId' && text.startsWith('0') && ! text.startsWith('00')) {
                event('address_invalid', { mmsi: text, group: true });

                return;
            }
            s.call.address = { name: directory.find((d) => d.mmsi === text)?.name ?? null, mmsi: text };
            s.editor = null;
            afterAddress();
        } else if (e.kind === 'addIndv' || e.kind === 'addGroup') {
            const group = e.kind === 'addGroup';
            if (! group && text.startsWith('0') && ! text.startsWith('00')) {
                event('address_invalid', { mmsi: text, group });

                return;
            }
            const list = group ? s.groups : s.book;
            const name = directory.find((d) => d.mmsi === text)?.name ?? `ID ${text}`;
            if (! list.some((a) => a.mmsi === text)) list.push({ name, mmsi: text, ...(group ? { group: true } : {}) });
            event('address_added', { mmsi: text, name, group });
            s.editor = null;
            openMenu(group ? 'GROUP ID' : 'INDIVIDUAL ID', list.map((a) => a.name), () => openMenu('DSC SETTINGS', DSC_SETTINGS, openMain), 'idList', group ? 'groups' : 'book');
        }
    }

    // --- Notalarm ------------------------------------------------------------------

    function sendDistress(repeat = false) {
        const nature = NATURES[s.nature];
        s.distress = { nature, sentAt: clock(), nextAt: clock() + DISTRESS_REPEAT, waiting: true, count: (s.distress?.count ?? 0) + 1 };
        s.channel = 70;
        setScreen('distressSent');
        event('dsc_sent', { kind: 'distress', nature, label: NATURE_LABELS[s.nature], position: s.position, utc: s.utc, repeat });
    }

    function sendDistressCancel() {
        if (! s.distress) return;
        s.distress.waiting = false;
        event('dsc_sent', { kind: 'distress_cancel', nature: s.distress.nature });
        event('distress_repeat_cancelled', { nature: s.distress.nature, dscCancel: true });
        setChannel(16, { work: false });
        setScreen('cancelDone');
    }

    // --- Empfang -------------------------------------------------------------------

    function alarmOff() {
        if (s.screen === 'ackAlarm') {
            const c = s.call;
            const incoming = s.incoming;
            s.incoming = null;
            event('alarm_stopped', { call: incoming?.type ?? 'ack' });
            if (incoming?.type === 'distress_ack') {
                if (s.distress) s.distress.waiting = false;
                setChannel(16, { work: false });
                backToNormal();

                return;
            }
            if (incoming?.type === 'individual_ack' && incoming.action === 'unable') {
                s.call = null;
                event('call_failed', { kind: 'individual' });
                backToNormal();

                return;
            }
            if (c?.kind === 'individual') {
                const channel = incoming?.channel ?? c.channel;
                if (channel) setChannel(channel);
                setScreen('callResult');

                return;
            }
            setScreen('callResult');

            return;
        }
        if (s.screen === 'rcvdAlarm') {
            s.incoming.silenced = true;
            event('alarm_stopped', { call: s.incoming.type });
            setScreen('rcvdAction');
        }
    }

    function acknowledgeIncoming() {
        const i = s.incoming;
        if (! i) return;
        if (i.type === 'individual') {
            s.incoming = null;
            startAck(i);
        } else if (i.type === 'test_call') {
            s.incoming = null;
            s.call = { kind: 'test_ack', address: i.from, category: 'Safety' };
            setScreen('callConfirm');
        } else if (i.type === 'position_request' || i.type === 'polling_request') {
            s.incoming = null;
            s.call = { kind: i.type === 'position_request' ? 'position_reply' : 'polling_reply', address: i.from, category: 'Routine' };
            setScreen('callConfirm');
        }
    }

    function acceptIncoming() {
        const i = s.incoming;
        if (! i) return;
        if (i.type === 'distress' || i.type === 'distress_relay') {
            i.acceptedAt = clock();
            setScreen('rcvdCountdown');

            return;
        }
        if (ACCEPT_CHANNEL.has(i.type)) {
            s.incoming = null;
            backToNormal();
            setChannel(i.channel ?? 16);
            event('call_accepted', { call: i.type, channel: i.channel ?? 16 });
        }
    }

    function switchTo16AfterAccept() {
        const i = s.incoming;
        s.incoming = null;
        backToNormal();
        setChannel(16, { work: false });
        event('call_accepted', { call: i?.type ?? 'distress', channel: 16 });
    }

    // --- Suchlauf --------------------------------------------------------------------

    function startScan() {
        if (! s.tags.size) {
            event('scan_blocked', { reason: 'no_tag' });

            return;
        }
        s.watch = 'off';
        s.scan = { list: [...s.tags].sort((a, b) => a - b), index: -1, last: clock() };
        event('scan', { running: true, channels: s.scan.list });
    }

    function stopScan() {
        if (! s.scan) return;
        s.scan = null;
        event('scan', { running: false, channel: s.channel });
    }

    // --- Tasten ----------------------------------------------------------------------

    function enter() {
        switch (s.screen) {
            case 'menu':
                chooseMenuItem();
                break;
            case 'editor':
                editorEnter();
                break;
            case 'callChannel':
                setScreen('callConfirm');
                break;
            case 'ackChoice': {
                const c = s.call;
                c.action = s.cursor === 1 ? 'unable' : 'able';
                if (c.action === 'unable') c.channel = null;
                if (s.cursor === 2) {
                    // Propose New Channel: Arbeitskanal wählen, dann CALL.
                    c.channel = c.channel ?? 8;
                    c.channelLabel = 'Intership CH';
                    c.back = () => setScreen('ackChoice');
                    setScreen('callChannel');
                    break;
                }
                setScreen('callConfirm');
                break;
            }
            case 'callConfirm':
                sendCall();
                break;
            default:
                break;
        }
    }

    function shortPress(key) {
        if (! s.power) return;

        if (key === 'distressCover') {
            s.cover = ! s.cover;
            event('cover', { open: s.cover });

            return;
        }
        if (key.startsWith('soft')) {
            softkey(Number(key.slice(4)) - 1);

            return;
        }
        if (key === 'knob') {
            if (s.screen === 'menu' || s.screen === 'editor' || s.screen === 'callChannel' || s.screen === 'ackChoice' || s.screen === 'callConfirm') {
                enter();

                return;
            }
            const order = ['vol', 'sql', 'chan'];
            s.knobMode = order[(order.indexOf(s.knobMode) + 1) % order.length];
            s.windowUntil = clock() + WINDOW_MS;
            event('knob_mode', { mode: s.knobMode });

            return;
        }
        if (key === 'micHiLo') {
            toggleHighPower();

            return;
        }
        if (key === 'mic16') key = 'ch16';
        if (key === 'menu') {
            if (s.screen === 'menu' || s.screen === 'editor') backToNormal();
            else if (! ['rcvdAlarm', 'ackAlarm', 'distressHold', 'distressSent', 'callTx'].includes(s.screen)) openMain();

            return;
        }
        if (key === 'enter') {
            enter();

            return;
        }
        if (key === 'clr') {
            if (s.scan) {
                stopScan();

                return;
            }
            if (s.screen === 'normal') return;
            if (['rcvdAlarm', 'ackAlarm'].includes(s.screen)) {
                alarmOff();

                return;
            }
            back();

            return;
        }

        if (s.screen === 'menu' || s.screen === 'ackChoice') {
            const max = s.screen === 'menu' ? s.menu.items.length - 1 : 2;
            if (key === 'up') s.cursor = Math.max(0, s.cursor - 1);
            if (key === 'down') s.cursor = Math.min(max, s.cursor + 1);

            return;
        }
        if (s.screen === 'editor') {
            if (key === 'up') editorTurn(1);
            if (key === 'down') editorTurn(-1);
            if (key === 'left') editorMove(-1);
            if (key === 'right') editorMove(1);

            return;
        }
        if (s.screen === 'callChannel') {
            if (key === 'up' || key === 'down') s.call.channel = stepIntership(s.call.channel, key === 'up' ? 1 : -1);

            return;
        }

        // Binnenmodus (ATIS): kein Kanal 16.
        if (s.group === 'ATIS' && key === 'ch16') {
            event('ch16_unavailable', { group: s.group, key });

            return;
        }

        switch (key) {
            case 'ch16':
                if (s.channel !== 16 && s.channel !== s.callChannel[s.group]) s.workChannel = s.channel;
                setChannel(16, { work: false });
                break;
            case 'up':
            case 'down':
                if (s.scan) { stopScan(); break; }
                setChannel(stepChannel(s.group, s.channel, key === 'up' ? 1 : -1));
                break;
            case 'left':
            case 'right':
                s.ring = (s.ring + (key === 'right' ? 1 : -1) + ring.length) % ring.length;
                event('softkeys', { labels: softkeys() });
                break;
            case 'micUp':
            case 'micDown': {
                // Hörer-▲/▼ wählen nur TAG-Kanäle.
                const tags = [...s.tags].sort((a, b) => a - b);
                if (! tags.length) break;
                const i = tags.findIndex((t) => t === s.channel);
                const next = tags[(i + (key === 'micUp' ? 1 : -1) + tags.length) % tags.length] ?? tags[0];
                setChannel(next);
                break;
            }
            default:
                break;
        }
    }

    function longPress(key) {
        if (key === 'knob') {
            if (s.power) {
                Object.assign(s, { power: false, tx: false, menu: null, editor: null, call: null, incoming: null, cover: false, scan: null, watch: 'off', distress: null });
                setScreen('off');
                event('power', { on: false });
            } else {
                s.power = true;
                s.knobMode = 'vol';
                setScreen('normal');
                event('power', { on: true });
            }

            return;
        }
        if (! s.power) return;
        if ((key === 'ch16' || key === 'mic16') && s.screen === 'normal') {
            if (s.channel !== 16 && s.channel !== s.callChannel[s.group]) s.workChannel = s.channel;
            setChannel(s.callChannel[s.group], { work: false });
            s.onCall = true;
            event('call_channel', { channel: s.callChannel[s.group] });
        }
    }

    // --- Öffentliche Schnittstelle -----------------------------------------------------

    const radio = {
        get state() {
            return snapshot();
        },
        keyDown(key) {
            pressed[key] = { t0: clock(), long: false };
            if (key === 'distress' && s.power) {
                if (s.group === 'ATIS') {
                    event('dsc_blocked', { group: s.group, key });

                    return;
                }
                if (! s.cover) {
                    event('distress_covered');

                    return;
                }
                s.distressHold = { t0: clock(), from: s.screen };
                setScreen('distressHold');
            }
        },
        keyUp(key) {
            const p = pressed[key];
            delete pressed[key];
            if (! p) return;
            if (key === 'distress') {
                if (s.screen === 'distressHold') {
                    setScreen(s.distressHold.from ?? 'normal');
                    event('distress_released_early', { heldMs: clock() - p.t0 });
                }
                s.distressHold = null;

                return;
            }
            if (p.long) return;
            shortPress(key);
        },
        turn(knob, steps) {
            if (knob !== 'dial' || ! s.power) return;
            if (s.screen === 'menu') {
                s.cursor = Math.max(0, Math.min(s.menu.items.length - 1, s.cursor + steps));
            } else if (s.screen === 'ackChoice') {
                s.cursor = Math.max(0, Math.min(2, s.cursor + steps));
            } else if (s.screen === 'editor') {
                editorTurn(steps);
            } else if (s.screen === 'callChannel') {
                s.call.channel = stepIntership(s.call.channel, Math.sign(steps));
            } else if (s.knobMode === 'sql' && clock() < s.windowUntil) {
                s.windowUntil = clock() + WINDOW_MS;
                s.sql = Math.max(0, Math.min(10, s.sql + steps));
                event('squelch', { level: s.sql, state: squelchState() });
            } else if (s.knobMode === 'chan' && clock() < s.windowUntil) {
                s.windowUntil = clock() + WINDOW_MS;
                const direction = Math.sign(steps);
                for (let i = 0; i < Math.abs(steps); i++) setChannel(stepChannel(s.group, s.channel, direction));
            } else {
                // Ohne Drücken dreht der Knopf die Lautstärke (Werkseinstellung).
                s.knobMode = 'vol';
                s.windowUntil = clock() + WINDOW_MS;
                s.vol = Math.max(0, Math.min(20, s.vol + steps));
                // Ereignis in den Stufen der Lektionen (0–10, wie IC-M505).
                event('volume', { level: s.vol / 2 });
            }
        },
        pttDown() {
            if (! s.power) return false;
            if (current()?.receiveOnly) {
                event('tx_blocked', { channel: s.channel });

                return false;
            }
            // Die Sprechtaste beendet einen DSC-Empfang (wie IGN).
            if (['rcvdAlarm', 'rcvdAction', 'rcvdCountdown'].includes(s.screen)) {
                s.incoming = null;
                backToNormal();
            }
            if (s.scan) stopScan();
            s.tx = true;
            event('tx_start', { channel: s.channel, group: s.group, highPower: effectiveHighPower() });

            return true;
        },
        pttUp() {
            if (! s.tx) return;
            s.tx = false;
            event('tx_end', { channel: s.channel, group: s.group, highPower: effectiveHighPower() });
            if (s.group === 'ATIS') event('atis_sent', { id: own?.atis ?? null, channel: s.channel, circle: current()?.circle ?? null });
        },
        spoken(text, channel = s.channel) {
            event('spoken', { text, channel, group: s.group, highPower: effectiveHighPower(), circle: current()?.circle ?? null });
        },
        tick() {
            const now = clock();
            for (const [key, p] of Object.entries(pressed)) {
                if (key === 'distress' || p.long) continue;
                if (now - p.t0 >= LONG && (key === 'knob' || key === 'ch16' || key === 'mic16')) {
                    p.long = true;
                    longPress(key);
                }
            }
            if (s.screen === 'distressHold' && s.distressHold && now - s.distressHold.t0 >= DISTRESS_HOLD) {
                s.distressHold = null;
                sendDistress();
            }
            if (s.screen === 'distressSent' && now - s.screenSince >= TRANSMIT_MS) {
                // Nach dem Senden auf Kanal 70 hört das Gerät wieder Kanal 16.
                setChannel(16, { work: false });
                setScreen('distressWait');
            }
            if (s.screen === 'callTx' && now - s.screenSince >= TRANSMIT_MS) {
                const c = s.call;
                if (c?.waiting) {
                    setScreen('callWait');
                } else {
                    // Gruppenanruf, Anruf an alle, Antworten: danach direkt
                    // auf den Arbeitskanal.
                    if (c?.kind === 'individual_ack' && c.action === 'able' && c.channel) setChannel(c.channel);
                    if ((c?.kind === 'group' || c?.kind === 'all_ships') && c.channel) setChannel(c.channel);
                    if (c && ['group', 'all_ships', 'individual_ack'].includes(c.kind) && c.action !== 'unable') setScreen('callResult');
                    else { s.call = null; backToNormal(); }
                }
            }
            if (s.screen === 'rcvdCountdown' && s.incoming && now - s.incoming.acceptedAt >= CH16_DELAY) {
                switchTo16AfterAccept();
            }
            if (s.scan && now - s.scan.last >= SCAN_STEP) {
                s.scan.last = now;
                s.scan.index = (s.scan.index + 1) % s.scan.list.length;
                s.channel = s.scan.list[s.scan.index];
            }
            if (s.power && s.distress?.waiting && now >= s.distress.nextAt) {
                sendDistress(true);
            }
            if (s.knobMode !== 'vol' && now >= s.windowUntil && s.screen === 'normal') {
                s.knobMode = 'vol';
            }
            if (! s.power) pending = [];
            if (pending.length && ! BUSY.includes(s.screen)) this.receive(pending.shift(), true);
        },
        receive(call, queued = false) {
            // Aus oder im Binnenmodus (ATIS, DSC gesperrt): kein Empfang.
            if (! s.power || s.group === 'ATIS') return false;
            // Während das Gerät selbst sendet bzw. DISTRESS gehalten wird,
            // geht kein Anruf verloren: Er kommt an, sobald das Gerät wieder
            // empfängt (danach wie jeder andere Anruf, auch mit Kanalwechsel).
            if (! queued && BUSY.includes(s.screen)) {
                pending.push(call);

                return true;
            }
            if (s.scan) stopScan();
            s.received.unshift({ ...call, at: clock() });
            event('dsc_received', { call });

            // Antworten auf eigene Anrufe bzw. den eigenen Notalarm.
            if (call.type === 'distress_ack' && s.distress?.waiting) {
                // Die Bestätigung beendet die automatische Wiederholung.
                s.distress.waiting = false;
                s.incoming = call;
                setScreen('ackAlarm');

                return;
            }
            if ((call.type === 'individual_ack' && s.call?.kind === 'individual' && s.call.waiting) || (call.type === 'test_ack' && s.call?.kind === 'test' && s.call.waiting)) {
                s.call.waiting = false;
                s.incoming = call;
                setScreen('ackAlarm');

                return;
            }
            if (call.type === 'individual') s.ackable = call;
            if (call.type === 'test_call') s.testAckable = call;

            // Positions- und Polling-Abfragen sowie Testanrufe beantwortet das
            // Gerät ab Werk automatisch (Position ACK und Test ACK: Auto TX).
            if (call.type === 'position_request' || call.type === 'polling_request' || call.type === 'test_call') {
                const kind = { position_request: 'position_reply', polling_request: 'polling_reply', test_call: 'test_ack' }[call.type];
                event('dsc_sent', { kind, address: call.from, auto: true, position: s.position });
                if (call.type === 'test_call') s.testAckable = null;

                return;
            }

            s.incoming = { ...call, silenced: false };
            setScreen('rcvdAlarm');
        },
        hear(voice) {
            const result = (heard, reason = null) => {
                event('voice', { heard, reason, from: voice.from ?? null, channel: voice.channel, text: voice.text });

                return { heard, reason };
            };
            if (! s.power) return result(false, 'off');
            if (voice.group && (voice.group === 'ATIS') !== (s.group === 'ATIS')) return result(false, 'group');
            let listening = s.channel === voice.channel;
            if (! listening && s.watch !== 'off' && (voice.channel === 16 || (s.watch === 'tri' && voice.channel === s.callChannel[s.group]))) listening = true;
            if (! listening && s.scan && s.scan.list.includes(voice.channel)) {
                stopScan();
                s.channel = voice.channel;
                listening = true;
            }
            if (! listening) return result(false, 'channel');
            if (s.vol === 0) return result(false, 'volume');
            if (squelchState() === 'tight' && ! voice.strong) return result(false, 'squelch');

            return result(true);
        },
        holdProgress() {
            if (s.screen !== 'distressHold' || ! s.distressHold) return null;

            return Math.min(1, (clock() - s.distressHold.t0) / DISTRESS_HOLD);
        },
        squelch: squelchState,
        setup(start = {}) {
            if (start.power && ! s.power) {
                s.power = true;
                setScreen('normal');
                event('power', { on: true });
            }
            for (const key of ['group', 'channel', 'highPower', 'position', 'utc', 'watchMode']) {
                if (key in start) s[key] = start[key];
            }
            // Lautstärke des IC-M323 hat 20 Stufen, die Lektionen nennen die
            // Stufen des IC-M505 (0–10).
            if ('vol' in start) s.vol = start.vol * 2;
            if ('sql' in start) s.sql = start.sql;
            if ('channel' in start) s.workChannel = start.channel;
            if (start.position) s.positionSetAt = clock();
            if (start.tags) s.tags = new Set(start.tags);
            if ('nature' in start) s.nature = Math.max(0, NATURES.indexOf(start.nature));
            if (start.distressWaiting) {
                s.distress = { nature: start.distressWaiting, sentAt: clock(), nextAt: clock() + DISTRESS_REPEAT, waiting: true, count: 1 };
                setScreen('distressWait');
            }
        },
        display() {
            return renderDisplay();
        },
    };

    // --- Display -------------------------------------------------------------------------

    function renderDisplay() {
        if (! s.power) return { on: false, softkeys: ['', '', '', ''] };
        const ch = current();
        const stale = positionStale() ? '??' : '';
        const pos = formatPosition(s.position)?.map((l) => stale + l) ?? ['NO POSITION'];
        const time = s.utc ? `${stale}${s.utc} MNL` : 'NO TIME';
        const base = {
            on: true,
            status: {
                busy: squelchState() === 'noise' || Boolean(s.incoming && ! s.incoming.silenced),
                tx: s.tx,
                power: effectiveHighPower() ? '25W' : '1W',
                group: s.group,
                dup: Boolean(ch?.duplex),
                call: Boolean(s.onCall),
                tag: s.tags.has(s.channel),
                atis: s.group === 'ATIS',
            },
            channel: pad(s.channel),
            channelName: ch?.name ?? '',
            watch: s.scan ? 'SCAN 16' : s.watch === 'dual' ? 'DUAL 16' : s.watch === 'tri' ? 'TRI 16' : null,
            lines: [...pos, time],
            softkeys: softkeys(),
            arrows: ! ['menu', 'editor', 'rcvdAlarm', 'ackAlarm'].includes(s.screen),
            panel: null,
            window: null,
            alarm: false,
        };
        if (s.screen === 'normal' && s.knobMode !== 'vol' && clock() < s.windowUntil) {
            base.window = s.knobMode === 'sql' ? { label: 'SQL:', value: s.sql === 0 ? 'OPEN' : String(s.sql), level: s.sql / 10 } : { label: 'CHAN', value: pad(s.channel), level: null };
        } else if (s.screen === 'normal' && clock() < s.windowUntil) {
            base.window = { label: 'Volume:', value: s.vol === 0 ? 'OFF' : String(s.vol), level: s.vol / 20 };
        }
        const panel = (title, lines = [], extra = {}) => ({ ...base, panel: { title, lines, ...extra } });
        const c = s.call;
        const to = c?.address ? `To: ${c.address.name ?? c.address.mmsi}` : c?.kind === 'all_ships' ? 'To: All Ships' : '';
        const elapsed = (t0) => {
            const sec = Math.max(0, Math.floor((clock() - t0) / 1000));
            return `${pad(Math.floor(sec / 3600))}:${pad(Math.floor(sec / 60) % 60)}:${pad(sec % 60)}`;
        };

        switch (s.screen) {
            case 'menu':
                return panel(s.menu.title, [], { items: s.menu.items, cursor: s.cursor });
            case 'editor': {
                const e = s.editor;
                const label = { position: 'LAT/LON:', utc: 'UTC:', indId: 'IND ID:', groupId: 'GRP ID:', addIndv: 'IND ID:', addGroup: 'GRP ID:' }[e.kind];
                return panel(e.title, [label], { editor: { chars: e.chars, cursor: e.editable[e.pos] }, footer: e.pos === e.editable.length - 1 ? 'ENT → FINISH' : '0-9 ←  →  FINISH' });
            }
            case 'mmsiInfo':
                return panel('MMSI/GPS INFO', [`MMSI: ${own?.mmsi ?? '---------'}`, `ATIS: ${own?.atis ?? 'NO ATIS MMSI'}`]);
            case 'callChannel':
                return panel(TITLES[c.kind], [c.channelLabel ?? 'Intership CH', `CHAN: ${pad(c.channel)}`]);
            case 'ackChoice':
                return panel('INDIVIDUAL ACK', [], { items: ['Able to Comply', 'Unable to Comply', 'Propose New Channel'], cursor: s.cursor });
            case 'callConfirm':
                return panel(TITLES[c.kind], [to, c.category ?? '', c.channel ? `CH ${pad(c.channel)}` : '', c.action ? (c.action === 'able' ? 'Able to Comply' : 'Unable to Comply') : (['individual', 'group', 'all_ships'].includes(c.kind) ? 'Telephony' : '')].filter(Boolean));
            case 'callTx':
                return panel(TITLES[c.kind], ['TRANSMITTING', TITLES[c.kind]]);
            case 'callWait':
                return panel(TITLES[c.kind], ['Waiting for ACK', `Elapsed: ${elapsed(c.sentAt)}`], { blink: true });
            case 'callResult':
                return panel(TITLES[c?.kind] ?? '', [`${effectiveHighPower() ? '25W' : '1W'}   ${pad(s.channel)}`, to, c?.sentAt ? `Elapsed: ${elapsed(c.sentAt)}` : ''].filter(Boolean));
            case 'ackAlarm': {
                const i = s.incoming;
                const unable = i?.type === 'individual_ack' && i.action === 'unable';
                return { ...panel(i?.type === 'distress_ack' ? '!! DISTRESS !!' : TITLES[c?.kind] ?? '', [unable ? 'Received Unable ACK' : 'Received ACK', `From: ${i?.from?.name ?? i?.from?.mmsi ?? ''}`]), alarm: true };
            }
            case 'distressConfirm':
                return panel('DISTRESS CALL', ['Push [DISTRESS] for 3 sec', `MMSI: ${own?.mmsi ?? ''}`, NATURE_LABELS[s.nature], ...pos]);
            case 'distressHold':
                return panel('!! DISTRESS !!', ['Hold Down', 'for 3 sec.'], { progress: radio.holdProgress() });
            case 'distressSent':
                return { ...panel('!! DISTRESS !!', ['TRANSMITTING', 'DISTRESS ALERT']), channel: '70' };
            case 'distressWait': {
                const left = Math.max(0, Math.ceil((s.distress.nextAt - clock()) / 1000));
                return panel('!! DISTRESS !!', ['Waiting for ACK', 'Next TX after', `${Math.floor(left / 60)} min. ${pad(left % 60)} sec.`], { blink: true });
            }
            case 'cancelConfirm':
                return panel('!! DISTRESS CANCEL !!', ['ARE YOU SURE?']);
            case 'cancelReport':
                return panel('!! DISTRESS CANCEL !!', ['Report Your Situation', 'On CH 16.']);
            case 'cancelDone':
                return panel('!! DISTRESS CANCEL !!', [`${effectiveHighPower() ? '25W' : '1W'}   16`]);
            case 'rcvdAlarm':
            case 'rcvdAction':
            case 'rcvdCountdown': {
                const i = s.incoming;
                const detail = i.type === 'distress' || i.type === 'distress_relay' ? (i.nature ?? 'UNDESIGNATED').toUpperCase() : i.channel ? `CH ${pad(i.channel)}` : '';
                const countdown = s.screen === 'rcvdCountdown' ? `CH 16 after ${Math.max(0, Math.ceil((CH16_DELAY - (clock() - i.acceptedAt)) / 1000))} sec.` : `ELAPSED: ${elapsed(s.received[0]?.at ?? clock())}`;
                return { ...panel('', [RECEIVED[i.type] ?? i.type, `FROM: ${i.from?.name ?? i.from?.mmsi ?? ''}`, detail, countdown].filter(Boolean)), alarm: s.screen === 'rcvdAlarm' };
            }
            default:
                return base;
        }
    }

    return radio;
}
