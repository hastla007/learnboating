// Gerätelogik Icom IC-M503 mit DSC-Controller DS-100 (Stufe 5 der
// Gerätesimulation), nach den Geräteprofilen aus den Bedienungsanleitungen.
// Zwei Bedienteile: Das Funkgerät (POWER, VOL, SQL, CHANNEL, HI/LO als
// Umschalttaste, DUAL, SCAN/TAG, DIAL, CALL, 16, DIMMER) macht Sprechfunk;
// alle DSC-Funktionen laufen über den DS-100 (eigenes Display, DISTRESS 5 s
// unter der Klappe, CLR, CALL, ENT, Pfeile, Zifferntasten). Gesendet wird ein
// vorbereiteter Ruf mit CALL und ENT gleichzeitig; in der Simulation auch
// CALL und innerhalb von 2 Sekunden ENT.
//
// Der DS-100 kennt keinen Testanruf, keine Positions- und Polling-Abfrage
// und keine DSC-Bestätigung oder -Weiterleitung eines fremden Notalarms. CLR
// während des Wartens auf eine Quittung beendet die Wiederholung und sendet
// eine Aufhebung. Gleiche Ereignisnamen wie das IC-M505; die Art der Not
// wird mit den Namen des IC-M505 gemeldet ("nature"), die Anzeige des DS-100
// steht in "label".

import { CHANNEL_GROUPS, channelInfo, stepChannel, stepIntership } from '../channels.js';
import { NATURES } from './icm505.js';

export const DS100_NATURES = ['Undesignated', 'Fire,Explosion', 'Flooding', 'Collision', 'Grounding', 'Capsizing', 'Sinking', 'Disable adrift', 'Abandoning ship', 'Piracy attack', 'Man overboard', 'EPIRB emission'];
// Index in NATURES (IC-M505) je Eintrag; EPIRB gibt es dort nicht.
const NATURE_MAP = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 0];
// Zustände, in denen das Gerät nicht empfängt (eigene Aussendung, DISTRESS halten).
const BUSY = ['callTx', 'distressSent', 'distressHold'];

export const DEVICE = {
    key: 'ic-m503',
    name: 'Icom IC-M503 + DS-100',
    keys: [
        'power', 'hl', 'dual', 'scan', 'dial', 'call', 'ch16', 'dimmer', 'micUp', 'micDown', 'micHiLo',
        'distressCover', 'distress', 'dsClr', 'dsCall', 'dsEnt', 'dsUp', 'dsDown', 'dsLeft', 'dsRight', 'dsAa', 'dsBs',
        'd0', 'd1', 'd2', 'd3', 'd4', 'd5', 'd6', 'd7', 'd8', 'd9',
    ],
    knobs: ['channel', 'vol', 'sql'],
};

const LONG = 1000;
const VERY_LONG = 3000;
const DISTRESS_HOLD = 5000;
const DISTRESS_REPEAT = 4 * 60 * 1000;
const TRANSMIT_MS = 1500;
const SEND_WINDOW = 2000;
const SCAN_STEP = 500;
const POSITION_STALE = 4 * 60 * 60 * 1000;

const MAIN_MENU = ['Entry Position/Time', 'Individual call', 'Group call', 'All ships call', 'Received calls', 'Distress setting', 'Set-up'];
const SETUP_MENU = ['Address ID', 'Offset time', 'Brightness', 'Contrast', 'MMSI CHECK'];
const ADDRESS_MENU = ['Add an address ID', 'Delete address ID', 'Add a group ID', 'Delete a group ID'];
const CATEGORIES = ['Routine', 'Safety', 'Urgency'];
const ALL_SHIPS_CATEGORIES = ['Routine', 'Safety', 'Urgency', 'Distress'];
const SET_MODE = ['Scan mode', 'Scan timer', 'DUAL/TRI', 'ATIS check'];
const RECEIVED = {
    distress: 'RCV Distress call', distress_ack: 'RCV Distress ACK', distress_relay: 'RCV Distress relay', individual: 'RCV Individual call',
    individual_ack: 'RCV Individual ACK', group: 'RCV Group call', all_ships: 'RCV All ships call', geographic: 'RCV All ships call',
    position_request: 'RCV Pos request', position_reply: 'RCV Pos reply',
};
// ENT stellt bei diesen Empfängen den angekündigten Arbeitskanal ein.
const CHANNEL_ON_ENTER = new Set(['group', 'all_ships', 'geographic']);

function pad(n, width = 2) {
    return String(n).padStart(width, '0');
}

export function createIcM503({ own, clock = () => Date.now(), emit = () => {}, addressBook = [], directory = [] } = {}) {
    const s = {
        power: false,
        vol: 0,
        sql: 0,
        group: 'INT',
        channel: 16,
        workChannel: 16,
        callChannel: { INT: 9, DSC: 9, ATIS: 10 },
        highPower: true,
        watch: 'off',
        watchMode: 'dual',
        scan: null,
        tags: new Set(),
        tx: false,
        onCall: false,
        setMode: false,
        setCursor: 0,
        // DS-100
        ds: 'standby',
        dsSince: 0,
        cover: false,
        menu: null,
        cursor: 0,
        nature: 0,
        position: null,
        utc: null,
        positionSetAt: null,
        editor: null,
        call: null,
        callArmedAt: null,
        distress: null,
        incoming: null,
        received: [],
        book: addressBook.filter((a) => ! a.group).map((a) => ({ ...a })),
        groups: addressBook.filter((a) => a.group).map((a) => ({ ...a })),
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
            power: s.power, group: s.group, channel: s.channel, highPower: effectiveHighPower(), vol: s.vol, sql: s.sql, screen: s.ds, watch: s.watch,
            nature: NATURES[NATURE_MAP[s.nature]], position: s.position, utc: s.utc, coverOpen: s.cover, tx: s.tx, scanning: Boolean(s.scan), tags: [...s.tags].sort((a, b) => a - b),
            addressBook: s.book.map((a) => a.mmsi), groups: s.groups.map((a) => a.mmsi), positionStale: positionStale(), circle: current()?.circle ?? null,
        };
    }

    // Anrufe, die während der eigenen Aussendung eintreffen.
    let pending = [];

    function setDs(screen) {
        const changed = s.ds !== screen;
        s.ds = screen;
        s.dsSince = clock();
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
        if (s.sql <= 1) return 'noise';
        if (s.sql >= 7) return 'tight';

        return 'set';
    }

    function standby() {
        s.menu = null;
        s.editor = null;
        s.callArmedAt = null;
        setDs(s.distress?.waiting ? 'distressWait' : 'standby');
    }

    // --- Funkgerät IC-M503 -------------------------------------------------------

    function toggleHighPower() {
        if (current()?.lowPowerOnly) {
            event('power_level_blocked', { channel: s.channel });

            return;
        }
        s.highPower = ! s.highPower;
        event('power_level', { high: s.highPower, channel: s.channel });
    }

    function radioShort(key) {
        if (s.setMode) {
            if (key === 'ch16') s.setCursor = (s.setCursor + 1) % SET_MODE.length;
            if (key === 'power') {
                s.setMode = false;
                // Nach dem Set-Modus ist der DS-100 betriebsbereit.
                setDs('standby');
                event('set_mode', { open: false });
            }

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
                // Kanal 16 schaltet automatisch auf 25 W.
                if (! s.highPower) {
                    s.highPower = true;
                    event('power_level', { high: true, channel: 16, auto: true });
                }
                break;
            case 'call':
                if (s.channel !== 16 && s.channel !== s.callChannel[s.group]) s.workChannel = s.channel;
                setChannel(s.callChannel[s.group], { work: false });
                s.onCall = true;
                event('call_channel', { channel: s.callChannel[s.group] });
                break;
            case 'dial':
                if (s.scan) stopScan();
                setChannel(s.workChannel);
                break;
            case 'dual':
                if (s.watch !== 'off') {
                    s.watch = 'off';
                    event('watch', { mode: 'off' });
                } else {
                    if (s.scan) stopScan();
                    s.watch = s.watchMode;
                    event('watch', { mode: s.watch, channel: s.channel });
                }
                break;
            case 'scan':
                if (s.scan) stopScan();
                else startScan();
                break;
            case 'hl':
            case 'micHiLo':
                toggleHighPower();
                break;
            case 'micUp':
            case 'micDown':
                setChannel(stepChannel(s.group, s.channel, key === 'micUp' ? 1 : -1));
                break;
            case 'dimmer':
                event('dimmer');
                break;
            default:
                break;
        }
    }

    function radioLong(key) {
        if (key === 'scan') {
            const on = ! s.tags.has(s.channel);
            if (on) s.tags.add(s.channel);
            else s.tags.delete(s.channel);
            event('tag', { channel: s.channel, on });
        }
    }

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

    // --- DS-100: Menüs ----------------------------------------------------------------

    function openMenu(title, items, back = null, kind = 'menu') {
        s.menu = { title, items, back, kind };
        s.cursor = 0;
        setDs('menu');
    }

    function openMain() {
        openMenu('<Select a subject>', MAIN_MENU);
    }

    function addressList(group = s.call?.kind === 'group') {
        return group ? s.groups : s.book;
    }

    function chooseMenuItem() {
        const m = s.menu;
        const item = m.items[s.cursor];

        if (m.kind === 'select') {
            m.onSelect(item, s.cursor);

            return;
        }
        event('menu', { item });

        switch (item) {
            case 'Entry Position/Time':
                startEditor('position');
                break;
            case 'Individual call':
            case 'Group call':
                s.call = { kind: item === 'Group call' ? 'group' : 'individual' };
                select(item === 'Group call' ? '<Select a group>' : '<Select address ID>', ['Manual entry', ...addressList().map((a) => a.name)], (name, index) => {
                    if (index === 0) {
                        startEditor(s.call.kind === 'group' ? 'groupId' : 'indId');

                        return;
                    }
                    s.call.address = addressList()[index - 1];
                    afterAddress();
                }, openMain);
                break;
            case 'All ships call':
                s.call = { kind: 'all_ships' };
                select('<Select a category>', ALL_SHIPS_CATEGORIES, (category) => {
                    s.call.category = category;
                    s.call.channel = 16;
                    setDs('callReady');
                }, openMain);
                break;
            case 'Received calls':
                select('<Select a message>', s.received.length ? s.received.slice(0, 8).map((r, i) => `${i + 1}:${RECEIVED[r.type]?.replace('RCV ', '') ?? r.type}`) : ['No message'], () => {}, openMain);
                break;
            case 'Distress setting':
                select('<Select a nature>', DS100_NATURES, (label, index) => {
                    s.nature = index;
                    event('nature_selected', { nature: NATURES[NATURE_MAP[index]], label });
                    if (! s.position) startEditor('position', 'distress');
                    else setDs('distressReady');
                }, openMain);
                s.cursor = s.nature;
                break;
            case 'Set-up':
                openMenu('<Select a subject>', SETUP_MENU, openMain);
                break;
            case 'Address ID':
                openMenu('<Select a subject>', ADDRESS_MENU, () => openMenu('<Select a subject>', SETUP_MENU, openMain));
                break;
            case 'Add an address ID':
                startEditor('addIndv');
                break;
            case 'Add a group ID':
                startEditor('addGroup');
                break;
            case 'MMSI CHECK':
                setDs('mmsiCheck');
                event('mmsi_checked', { mmsi: own?.mmsi });
                break;
            default:
                event('menu_unavailable', { item });
        }
    }

    function select(title, items, onSelect, back) {
        openMenu(title, items, back, 'select');
        s.menu.onSelect = onSelect;
    }

    function afterAddress() {
        const c = s.call;
        select('<Select a category>', CATEGORIES, (category) => {
            c.category = category;
            // Bei einer Küstenfunkstelle legt diese den Arbeitskanal fest.
            if (c.kind === 'individual' && c.address.mmsi.startsWith('00')) {
                c.channel = null;
                setDs('callReady');
            } else {
                c.channel = 8;
                setDs('callChannel');
            }
        }, openMain);
    }

    function sendCall() {
        const c = s.call;
        if (! c) return;
        s.callArmedAt = null;
        event('dsc_sent', { kind: c.kind, address: c.address ?? null, category: c.category ?? null, channel: c.channel ?? null, action: c.action ?? null });
        c.sentAt = clock();
        c.waiting = c.kind === 'individual';
        setDs('callTx');
    }

    // --- DS-100: Eingabe über das Tastenfeld ---------------------------------------------

    function startEditor(kind, then = null) {
        const templates = {
            position: { title: '<Input a position>', template: '##°##\'N ###°##\'E' },
            utc: { title: '<Input a Time>', template: '##:##' },
            indId: { title: '<Input address ID>', template: '#########' },
            groupId: { title: '<Input an address>', template: '0########' },
            addIndv: { title: '<Add an address ID>', template: '#########' },
            addGroup: { title: '<Add a group ID>', template: '0########' },
        };
        const t = templates[kind];
        const chars = t.template.split('').map((c) => (c === '#' ? '_' : c));
        const editable = t.template.split('').map((c, i) => (c === '#' || c === 'N' || c === 'E' ? i : -1)).filter((i) => i >= 0);
        if (kind === 'groupId' || kind === 'addGroup') editable.shift();
        s.editor = { kind, title: t.title, chars, editable, pos: 0, then };
        setDs('editor');
    }

    function editorDigit(d) {
        const e = s.editor;
        let i = e.editable[e.pos];
        // N/S und E/W überspringen die Ziffern; der Cursor rückt auf die
        // nächste Ziffernstelle.
        while (/[NSEW]/.test(e.chars[i]) && e.pos < e.editable.length - 1) {
            e.pos++;
            i = e.editable[e.pos];
        }
        if (/[NSEW]/.test(e.chars[i])) return;
        e.chars[i] = d;
        if (e.pos < e.editable.length - 1) e.pos++;
    }

    function editorToggle() {
        const e = s.editor;
        const i = e.editable[e.pos];
        const c = e.chars[i];
        if (c === 'N' || c === 'S') e.chars[i] = c === 'N' ? 'S' : 'N';
        else if (c === 'E' || c === 'W') e.chars[i] = c === 'E' ? 'W' : 'E';
    }

    function editorFinish() {
        const e = s.editor;
        const text = e.chars.join('');
        if (text.includes('_')) {
            event('input_incomplete', { kind: e.kind });

            return;
        }
        if (e.kind === 'position') {
            const m = text.match(/^(\d\d)°(\d\d)'([NS]) (\d\d\d)°(\d\d)'([EW])$/);
            s.position = [Number(m[1]), Number(m[2]), m[3], Number(m[4]), Number(m[5]), m[6]];
            s.positionSetAt = clock();
            event('position_set', { position: s.position });
            startEditor('utc', e.then);
        } else if (e.kind === 'utc') {
            s.utc = text;
            event('utc_set', { utc: s.utc });
            if (e.then === 'distress') setDs('distressReady');
            else standby();
        } else if (e.kind === 'indId' || e.kind === 'groupId') {
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
            openMenu('<Select a subject>', ADDRESS_MENU, () => openMenu('<Select a subject>', SETUP_MENU, openMain));
        }
    }

    // --- DS-100: Notalarm ---------------------------------------------------------------------

    function sendDistress(repeat = false) {
        const nature = NATURES[NATURE_MAP[s.nature]];
        s.distress = { nature, sentAt: clock(), nextAt: clock() + DISTRESS_REPEAT, waiting: true, count: (s.distress?.count ?? 0) + 1 };
        // Das Funkgerät sendet auf Kanal 70 und geht danach auf Kanal 16.
        s.channel = 70;
        setDs('distressSent');
        event('dsc_sent', { kind: 'distress', nature, label: DS100_NATURES[s.nature], position: s.position, utc: s.utc, repeat });
    }

    // CLR beim Warten: Wiederholung beenden und Aufhebung senden.
    function cancelDistress() {
        if (! s.distress?.waiting) return;
        s.distress.waiting = false;
        event('dsc_sent', { kind: 'distress_cancel', nature: s.distress.nature });
        event('distress_repeat_cancelled', { nature: s.distress.nature, dscCancel: true });
        standby();
    }

    // --- DS-100: Tasten -------------------------------------------------------------------------

    function dsShort(key) {
        if (key === 'distressCover') {
            s.cover = ! s.cover;
            event('cover', { open: s.cover });

            return;
        }

        // Binnenmodus: DSC gesperrt.
        if (s.group === 'ATIS' && (key === 'dsCall' || key === 'dsEnt') && s.ds === 'standby') {
            event('dsc_blocked', { group: s.group, key: 'menu' });

            return;
        }

        // Empfangsanzeige: CLR erst Ton aus, dann zurück; ENT handelt.
        if (s.incoming && s.ds === 'received') {
            const i = s.incoming;
            if (key === 'dsClr') {
                if (! i.silenced) {
                    i.silenced = true;
                    event('alarm_stopped', { call: i.type });

                    return;
                }
                s.incoming = null;
                standby();

                return;
            }
            if (key === 'dsEnt') {
                // ENT ohne vorheriges CLR schaltet den Alarm mit ab.
                if (! i.silenced) {
                    i.silenced = true;
                    event('alarm_stopped', { call: i.type });
                }
                if (i.type === 'individual') {
                    i.silenced = true;
                    s.incoming = null;
                    s.call = { kind: 'individual_ack', address: i.from, channel: i.channel ?? null, category: i.category ?? 'Routine' };
                    select('<Select to comply>', ['Able to comply', 'Unable to comply'], (choice) => {
                        s.call.action = choice === 'Able to comply' ? 'able' : 'unable';
                        if (s.call.action === 'unable') s.call.channel = null;
                        sendCall();
                    }, standby);

                    return;
                }
                if ((CHANNEL_ON_ENTER.has(i.type) || i.type === 'individual_ack') && i.channel) {
                    s.incoming = null;
                    standby();
                    setChannel(i.channel);
                    event('call_accepted', { call: i.type, channel: i.channel });

                    return;
                }
                if (i.type === 'position_request') {
                    s.incoming = null;
                    event('dsc_sent', { kind: 'position_reply', address: i.from, position: s.position });
                    standby();

                    return;
                }
            }

            return;
        }

        switch (s.ds) {
            case 'standby':
                if (key === 'dsCall') openMain();
                break;
            case 'menu':
                if (key === 'dsUp') s.cursor = Math.max(0, s.cursor - 1);
                else if (key === 'dsDown') s.cursor = Math.min(s.menu.items.length - 1, s.cursor + 1);
                else if (key === 'dsEnt') chooseMenuItem();
                else if (key === 'dsClr') { if (s.menu.back) s.menu.back(); else standby(); }
                else if (key === 'dsCall') standby();
                break;
            case 'editor':
                if (/^d\d$/.test(key)) editorDigit(key.slice(1));
                else if (key === 'dsUp' || key === 'dsDown') editorToggle();
                else if (key === 'dsLeft') s.editor.pos = Math.max(0, s.editor.pos - 1);
                else if (key === 'dsRight') s.editor.pos = Math.min(s.editor.editable.length - 1, s.editor.pos + 1);
                else if (key === 'dsBs') {
                    s.editor.pos = Math.max(0, s.editor.pos - 1);
                    const i = s.editor.editable[s.editor.pos];
                    if (/\d/.test(s.editor.chars[i])) s.editor.chars[i] = '_';
                } else if (key === 'dsEnt') editorFinish();
                else if (key === 'dsClr') { s.call = null; standby(); }
                break;
            case 'callChannel':
                if (key === 'dsUp' || key === 'dsDown') s.call.channel = stepIntership(s.call.channel, key === 'dsUp' ? 1 : -1);
                else if (key === 'dsEnt') setDs('callReady');
                else if (key === 'dsClr') { s.call = null; standby(); }
                break;
            case 'callReady':
                if (key === 'dsCall') s.callArmedAt = clock();
                else if (key === 'dsEnt' && s.callArmedAt !== null && clock() - s.callArmedAt <= SEND_WINDOW) sendCall();
                else if (key === 'dsClr') { s.call = null; standby(); }
                break;
            case 'distressWait':
            case 'distressSent':
                if (key === 'dsClr') cancelDistress();
                break;
            case 'callTx':
            case 'callWait':
            case 'mmsiCheck':
            case 'distressReady':
                if (key === 'dsClr') { s.call = null; standby(); }
                break;
            default:
                break;
        }
    }

    // --- Öffentliche Schnittstelle --------------------------------------------------------------

    const radio = {
        get state() {
            return snapshot();
        },
        keyDown(key) {
            const modifier = pressed.hl && ! pressed.hl.released && key !== 'hl' ? 'hl' : null;
            pressed[key] = { t0: clock(), long: false, veryLong: false, combo: false };

            if (modifier) {
                pressed.hl.combo = true;
                pressed[key].combo = true;
                if (key === 'dial' && s.power) {
                    const order = ['INT', 'ATIS', 'DSC'];
                    const group = order[(order.indexOf(s.group) + 1) % order.length];
                    const ch = channelInfo(group, s.channel) ? s.channel : CHANNEL_GROUPS[group][0].number;
                    setChannel(ch, { group });
                    event('group', { group });
                }

                return;
            }

            // CALL und ENT gleichzeitig senden einen vorbereiteten Ruf.
            if (key === 'dsEnt' && pressed.dsCall && s.ds === 'callReady') {
                pressed.dsCall.combo = true;
                pressed.dsEnt.combo = true;
                sendCall();

                return;
            }

            if (key === 'distress' && s.power) {
                if (s.group === 'ATIS') {
                    event('dsc_blocked', { group: s.group, key });

                    return;
                }
                if (! s.cover) {
                    event('distress_covered');

                    return;
                }
                s.distressHold = { t0: clock(), from: s.ds };
                setDs('distressHold');
            }
        },
        keyUp(key) {
            const p = pressed[key];
            delete pressed[key];
            if (! p) return;
            if (key === 'distress') {
                if (s.ds === 'distressHold') {
                    setDs(s.distressHold.from ?? 'standby');
                    event('distress_released_early', { heldMs: clock() - p.t0 });
                }
                s.distressHold = null;

                return;
            }
            if (p.combo || p.long) return;

            if (key === 'power') {
                if (! s.power) {
                    s.power = true;
                    // Einschalten bei gedrückter 16: Set-Modus.
                    if (pressed.ch16) {
                        pressed.ch16.combo = true;
                        s.setMode = true;
                        s.setCursor = 0;
                        event('power', { on: true });
                        event('set_mode', { open: true });

                        return;
                    }
                    setDs('standby');
                    event('power', { on: true });
                } else if (s.setMode) {
                    radioShort('power');
                } else {
                    Object.assign(s, { power: false, tx: false, menu: null, editor: null, call: null, incoming: null, cover: false, scan: null, watch: 'off', distress: null });
                    setDs('off');
                    event('power', { on: false });
                }

                return;
            }
            if (! s.power) return;
            if (key.startsWith('ds') || /^d\d$/.test(key) || key === 'distressCover') dsShort(key);
            else radioShort(key);
        },
        turn(knob, steps) {
            if (knob === 'vol') {
                s.vol = Math.max(0, Math.min(10, s.vol + steps));
                event('volume', { level: s.vol });

                return;
            }
            if (knob === 'sql') {
                s.sql = Math.max(0, Math.min(10, s.sql + steps));
                event('squelch', { level: s.sql, state: squelchState() });

                return;
            }
            if (! s.power) return;
            if (s.setMode) {
                // Im Set-Modus ändert der Knopf den Wert des Menüpunkts.
                if (SET_MODE[s.setCursor] === 'DUAL/TRI') {
                    s.watchMode = s.watchMode === 'dual' ? 'tri' : 'dual';
                    event('set_mode', { item: 'Dual/Tri', value: s.watchMode });
                }

                return;
            }
            const direction = Math.sign(steps);
            if (s.scan) { stopScan(); return; }
            for (let i = 0; i < Math.abs(steps); i++) setChannel(stepChannel(s.group, s.channel, direction));
        },
        pttDown() {
            if (! s.power) return false;
            if (current()?.receiveOnly) {
                event('tx_blocked', { channel: s.channel });

                return false;
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
                if (key === 'distress' || p.combo || p.long) continue;
                if (now - p.t0 >= LONG && key === 'scan' && s.power) {
                    p.long = true;
                    radioLong(key);
                }
                if (now - p.t0 >= VERY_LONG && key === 'call') p.long = true;
            }
            if (s.ds === 'distressHold' && s.distressHold && now - s.distressHold.t0 >= DISTRESS_HOLD) {
                s.distressHold = null;
                sendDistress();
            }
            if (s.ds === 'distressSent' && now - s.dsSince >= TRANSMIT_MS) {
                setChannel(16, { work: false });
                setDs('distressWait');
            }
            if (s.ds === 'callTx' && now - s.dsSince >= TRANSMIT_MS) {
                const c = s.call;
                if (c?.waiting) {
                    setDs('callWait');
                } else {
                    if (c && c.channel && ! (c.kind === 'individual_ack' && c.action === 'unable')) setChannel(c.channel);
                    s.call = null;
                    standby();
                }
            }
            if (s.scan && now - s.scan.last >= SCAN_STEP) {
                s.scan.last = now;
                s.scan.index = (s.scan.index + 1) % s.scan.list.length;
                s.channel = s.scan.list[s.scan.index];
            }
            if (s.power && s.distress?.waiting && now >= s.distress.nextAt) {
                sendDistress(true);
            }
            if (! s.power) pending = [];
            if (pending.length && ! BUSY.includes(s.ds)) this.receive(pending.shift(), true);
        },
        receive(call, queued = false) {
            // Aus oder im Binnenmodus (ATIS, DSC gesperrt): kein Empfang.
            if (! s.power || s.group === 'ATIS') return false;
            // Während das Gerät selbst sendet bzw. DISTRESS gehalten wird,
            // geht kein Anruf verloren: Er kommt an, sobald das Gerät wieder
            // empfängt (danach wie jeder andere Anruf, auch mit Kanalwechsel).
            if (! queued && BUSY.includes(s.ds)) {
                pending.push(call);

                return true;
            }
            if (s.scan) stopScan();
            s.received.unshift({ ...call, at: clock() });

            if (call.type === 'distress_ack' && s.distress?.waiting) s.distress.waiting = false;
            if (call.type === 'individual_ack' && s.call?.waiting) {
                // Arbeitskanal: der eigene Vorschlag oder der Kanal der
                // Küstenfunkstelle; ENT stellt ihn am Funkgerät ein.
                call = { ...call, channel: call.channel ?? s.call.channel };
                s.call = null;
            }
            // Notalarm, Quittung und Relay schalten das Funkgerät selbst auf 16.
            if (['distress', 'distress_ack', 'distress_relay'].includes(call.type) && s.channel !== 16) {
                s.workChannel = s.channel;
                setChannel(16, { work: false });
            }
            s.incoming = { ...call, silenced: false };
            setDs('received');
            event('dsc_received', { call });
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
            if (s.ds !== 'distressHold' || ! s.distressHold) return null;

            return Math.min(1, (clock() - s.distressHold.t0) / DISTRESS_HOLD);
        },
        squelch: squelchState,
        setup(start = {}) {
            if (start.power && ! s.power) {
                s.power = true;
                setDs('standby');
                event('power', { on: true });
            }
            for (const key of ['vol', 'sql', 'group', 'channel', 'highPower', 'position', 'utc', 'watchMode']) {
                if (key in start) s[key] = start[key];
            }
            if ('channel' in start) s.workChannel = start.channel;
            if (start.position) s.positionSetAt = clock();
            if (start.tags) s.tags = new Set(start.tags);
            if (start.distressWaiting) {
                s.distress = { nature: start.distressWaiting, sentAt: clock(), nextAt: clock() + DISTRESS_REPEAT, waiting: true, count: 1 };
                setDs('distressWait');
            }
        },
        display() {
            return renderDisplay();
        },
    };

    // --- Anzeigen: Funkgerät und DS-100 ------------------------------------------------------------

    function renderDisplay() {
        if (! s.power) return { on: false, ds: { lines: [] } };
        const ch = current();
        const radioLcd = {
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
            watch: s.scan ? 'Pri scan 16' : s.watch === 'dual' ? 'DUAL 16' : s.watch === 'tri' ? 'TRI 16' : null,
            setMode: s.setMode ? { item: SET_MODE[s.setCursor], value: SET_MODE[s.setCursor] === 'DUAL/TRI' ? (s.watchMode === 'dual' ? 'd-' : 't-') : SET_MODE[s.setCursor] === 'ATIS check' ? own?.atis ?? '' : '' } : null,
        };
        return { ...radioLcd, ds: renderDs(), alarm: Boolean(s.incoming && ! s.incoming.silenced) };
    }

    function renderDs() {
        const stale = positionStale() ? '??' : '';
        const posLines = s.position ? [`     Lat  ${stale}${pad(s.position[0])}°${pad(s.position[1])}'${s.position[2]}`, `     Lon ${stale}${pad(s.position[3], 3)}°${pad(s.position[4])}'${s.position[5]}`] : ['No position data'];
        const timeLine = s.utc ? `MNL: UTC  ${s.utc}` : 'No time data';
        const c = s.call;
        const to = c?.address ? `To: ${c.address.name ?? c.address.mmsi}` : c?.kind === 'all_ships' ? 'To: All ships' : '';

        switch (s.ds) {
            case 'menu': {
                const first = Math.max(0, Math.min(s.cursor - 3, s.menu.items.length - 6));
                return { title: s.menu.title, items: s.menu.items.slice(first, first + 6), cursor: s.cursor - first, footer: '<CLR→Exit / ENT→OK>' };
            }
            case 'editor':
                return { title: s.editor.title, editor: { chars: s.editor.chars, cursor: s.editor.editable[s.editor.pos] }, footer: '<CLR→Exit / ENT→OK>' };
            case 'mmsiCheck':
                return { title: '<MMSI check>', lines: ['', own?.mmsi ?? '---------'] };
            case 'callChannel':
                return { title: '<Select a traffic CH>', lines: ['', `   CH ${pad(c.channel)}`], footer: '<CLR→Exit / ENT→OK>' };
            case 'callReady':
                return { title: `<${{ individual: 'Individual call', group: 'Group call', all_ships: 'All ships call' }[c.kind] ?? 'Call'}>`, lines: [to, c.category ?? '', c.channel ? `CH ${pad(c.channel)}` : 'Coast stn CH', '', s.callArmedAt !== null ? 'CALL ... now push ENT' : 'Push [CALL]+[ENT]'], footer: '<CLR→Exit>' };
            case 'callTx':
                return { title: '', lines: ['Transmitting', to] };
            case 'callWait':
                return { title: '', lines: ['Call completed', '', 'Now waiting for', '    acknowledgment'], blink: true, footer: '<CLR→Exit>' };
            case 'distressReady':
                return { title: '<Push and hold', lines: ['[DISTRESS] for 5 sec>', '', 'Nature of distress:', `   ${DS100_NATURES[s.nature]}`, timeLine, ...posLines] };
            case 'distressHold':
                return { title: '<Push and hold', lines: ['[DISTRESS] for 5 sec>', '', 'Nature of distress:', `   ${DS100_NATURES[s.nature]}`], progress: radio.holdProgress() };
            case 'distressSent':
                return { title: '', lines: ['Distress alert', '  Transmitting'] };
            case 'distressWait':
                return { title: '', lines: ['Distress alert', '       Completed', '', 'Now waiting for', '    acknowledgment'], blink: true, footer: '<CLR→Exit>' };
            case 'received': {
                const i = s.incoming;
                const from = `From: ${i.from?.name ?? i.from?.mmsi ?? ''}`;
                const extra = i.type === 'distress' ? [`Distress ID:`, i.from?.mmsi ?? '', i.nature ?? 'Undesignated'] : i.category ? [`Category: ${i.category}`] : [];
                const hint = i.type === 'position_request' ? 'To reply, push ENT' : '';
                return { title: `—${RECEIVED[i.type] ?? i.type}—`, lines: [from, ...extra, hint].filter(Boolean), footer: i.silenced ? '<CLR→Exit/ ENT→OK>' : '<CLR→Alarm off>', alarm: ! i.silenced };
            }
            default:
                return { title: '-VHF DSC CONTROLLER-', lines: ['', '    CH 70 WATCHING', '', timeLine, ...posLines] };
        }
    }

    return radio;
}
