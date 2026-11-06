// Gerätelogik Icom IC-M505 (Stufen 1–3 der Gerätesimulation). Nachgebildet nach
// dem Geräteprofil aus der Bedienungsanleitung: Tasten mit Druckdauer (kurz,
// 1 s, 3 s, DISTRESS 5 s), H/L als Umschalttaste, Display und DSC-Menü.
// Reine Logik ohne DOM: die Oberfläche ruft keyDown/keyUp/turn/tick auf und
// zeichnet display(); jede Aktion wird als Ereignis gemeldet, das die
// Lektionen prüfen. Uhrzeit kommt von außen (clock), damit Tests
// Haltedauern ohne Warten durchspielen können.

import { CHANNEL_GROUPS, channelInfo, stepChannel, stepIntership } from '../channels.js';

export const NATURES = ['Undesignated', 'Explosion', 'Flooding', 'Collision', 'Grounding', 'Capsizing', 'Sinking', 'Adrift', 'Abandoning', 'Piracy', 'MOB'];

export const CATEGORIES = ['Routine', 'Safety', 'Urgency', 'Distress'];

const LONG = 1000;
// Zustände, in denen das Gerät nicht empfängt (eigene Aussendung, DISTRESS halten).
const BUSY = ['distressHold'];
const VERY_LONG = 3000;
const DISTRESS_HOLD = 5000;
const DISTRESS_REPEAT = 4 * 60 * 1000;
const AUTO_RETURN = 2000;
const SCAN_STEP = 500;
// Manuell eingegebene Position und UTC gelten als veraltet nach 4 Stunden
// („??“ blinkt im Display).
const POSITION_STALE = 4 * 60 * 60 * 1000;

const MAIN_MENU = ['Position Input', 'Individual Call', 'Group Call', 'All Ships Call', 'Position Request', 'Position Report', 'Polling Request', 'Received Calls', 'Distress Setting', 'Set up', 'Exit'];
const SET_MODE = ['Scan Type', 'Scan Timer', 'Dual/Tri', 'Beep', 'Contrast', 'Exit'];
const SETUP_MENU = ['Add:INDV ID', 'Add:Group ID', 'DEL:INDV ID', 'DEL:Group ID', 'Offset Time', 'MMSI Check', 'Auto ACK', 'NMEA Output', 'Exit'];
// Bedienbare Menüpunkte; Position Report, Offset Time, Auto ACK und NMEA
// Output braucht keine Lektion und meldet „menu_unavailable“.
const AVAILABLE = new Set([
    'Position Input', 'Individual Call', 'Individual ACK', 'Group Call', 'All Ships Call', 'Position Request', 'Position Reply',
    'Polling Request', 'Polling Reply', 'Received Calls', 'Distress Setting', 'Set up', 'Exit', 'MMSI Check', 'Add:INDV ID', 'Add:Group ID',
]);

const CALL_TITLES = {
    individual: 'Individual Call', group: 'Group Call', all_ships: 'All Ships Call', individual_ack: 'Individual ACK',
    position_request: 'Position Request', position_reply: 'Position Reply', polling_request: 'Polling Request', polling_reply: 'Polling Reply',
};

const RECEIVED_TITLES = {
    individual_ack: 'INDV ACK', distress_ack: 'DistressACK', distress: 'Distress', distress_relay: 'DistressRLY', individual: 'Individual',
    all_ships: 'All Ships', geographic: 'Geographic', group: 'Group', position_request: 'POS Request', position_reply: 'POS Reply',
    polling_request: 'POLL REQ', polling_reply: 'POLL Reply',
};

// Anrufe, auf die der Gerätenutzer mit ENTER auf den vorgeschlagenen
// Arbeitskanal geht (Profil, „Anrufe empfangen“).
const CHANNEL_ON_ENTER = new Set(['group', 'all_ships', 'geographic']);

export const DEVICE = {
    key: 'ic-m505',
    name: 'Icom IC-M505',
    keys: ['distressCover', 'distress', 'menu', 'clr', 'hail', 'ic', 'ch16', 'dial', 'scan', 'hl', 'power', 'enter', 'micUp', 'micDown', 'micHiLo'],
    knobs: ['channel', 'vol', 'sql'],
};

function pad(n, width = 2) {
    return String(n).padStart(width, '0');
}

export function formatPosition(pos) {
    if (! pos) return null;
    const [latD, latM, ns, lonD, lonM, ew] = pos;

    return [`${pad(latD)}°${latM.toFixed(3).padStart(6, '0')}${ns}`, `${pad(lonD, 3)}°${lonM.toFixed(3).padStart(6, '0')}${ew}`];
}

// addressBook: Einträge {name, mmsi, group?}; directory: bekannte Stationen,
// deren Namen das Gerät nach „Add:INDV ID“ anzeigt (im echten Gerät tippt
// man den Namen ein).
export function createIcM505({ own, clock = () => Date.now(), emit = () => {}, addressBook = [], directory = [] } = {}) {
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
        tx: false,
        screen: 'off',
        cover: false,
        cursor: 0,
        menu: null,
        nature: 0,
        position: null,
        utc: null,
        editor: null,
        call: null,
        distress: null,
        received: [],
        incoming: null,
        screenSince: 0,
        book: addressBook.filter((a) => ! a.group).map((a) => ({ ...a })),
        groups: addressBook.filter((a) => a.group).map((a) => ({ ...a })),
        // Letzter Einzelanruf, auf den mit „Individual ACK“ geantwortet
        // werden kann, und offene Positions- bzw. Polling-Abfragen.
        ackable: null,
        replyTo: { position: null, polling: null },
        watchMode: 'dual',
        scan: null,
        tags: new Set(),
        positionSetAt: null,
    };
    const pressed = {};

    // "radio" ist der Gerätezustand nach dem Ereignis; die Ereignisfelder selbst
    // (z. B. state der Rauschsperre) bleiben davon unberührt.
    const event = (type, data = {}) => emit({ type, at: clock(), ...data, radio: snapshot() });

    function snapshot() {
        return {
            power: s.power, group: s.group, channel: s.channel, highPower: effectiveHighPower(), vol: s.vol, sql: s.sql, screen: s.screen, watch: s.watch,
            nature: NATURES[s.nature], position: s.position, utc: s.utc, coverOpen: s.cover, tx: s.tx, scanning: Boolean(s.scan), tags: [...s.tags].sort((a, b) => a - b),
            addressBook: s.book.map((a) => a.mmsi), groups: s.groups.map((a) => a.mmsi), positionStale: positionStale(),
            circle: current()?.circle ?? null,
        };
    }

    function positionStale() {
        return Boolean(s.position && s.positionSetAt !== null && clock() - s.positionSetAt > POSITION_STALE);
    }

    function current() {
        return channelInfo(s.group, s.channel);
    }

    function effectiveHighPower() {
        return s.highPower && ! current()?.lowPowerOnly;
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

    // --- Ein/Aus, Lautstärke, Rauschsperre --------------------------------

    function powerOn() {
        s.power = true;
        setScreen('normal');
        event('power', { on: true });
    }

    function powerOff() {
        // Ausschalten beendet auch einen laufenden Notalarm (keine Wiederholung).
        Object.assign(s, { power: false, tx: false, menu: null, editor: null, call: null, incoming: null, cover: false, distress: null });
        setScreen('off');
        event('power', { on: false });
    }

    // Rauschen ist hörbar, solange die Rauschsperre ganz offen ist; ab Stufe 7
    // werden schwache Stationen weggedrückt (Lektion „Hören und senden“).
    function squelchState() {
        if (! s.power) return 'off';
        if (s.sql <= 1) return 'noise';
        if (s.sql >= 7) return 'tight';

        return 'set';
    }

    // --- Menüs ------------------------------------------------------------

    function openMenu(title, items, back = null) {
        s.menu = { title, items, back };
        s.cursor = 0;
        setScreen('menu');
    }

    // Ohne angeschlossenen GPS-Empfänger steht „Position Input“ im Menü; die
    // Simulation hat keinen GPS-Empfänger.
    function mainMenuItems() {
        const items = [...MAIN_MENU];
        const after = (anchor, item) => items.splice(items.indexOf(anchor) + 1, 0, item);
        if (s.ackable) after('Individual Call', 'Individual ACK');
        if (s.replyTo.polling) after('Polling Request', 'Polling Reply');
        if (s.replyTo.position) after('Position Report', 'Position Reply');

        return items;
    }

    function closeToNormal() {
        s.menu = null;
        s.editor = null;
        setScreen(s.distress?.waiting ? 'distressWait' : 'normal');
    }

    function chooseMenuItem() {
        const item = s.menu.items[s.cursor];

        if (! AVAILABLE.has(item)) {
            event('menu_unavailable', { item });

            return;
        }

        event('menu', { item });

        switch (item) {
            case 'Exit':
                if (s.menu.back) openMenu(...s.menu.back);
                else closeToNormal();
                break;
            case 'Set up':
                openMenu('Set up', SETUP_MENU, ['Select Item', mainMenuItems()]);
                break;
            case 'MMSI Check':
                setScreen('mmsiCheck');
                event('mmsi_checked', { mmsi: own?.mmsi });
                break;
            case 'Distress Setting':
                s.cursor = s.nature;
                setScreen('nature');
                break;
            case 'Position Input':
                startEditor('position');
                break;
            case 'Individual Call':
            case 'Group Call':
            case 'Position Request':
            case 'Polling Request':
                s.call = { kind: { 'Individual Call': 'individual', 'Group Call': 'group', 'Position Request': 'position_request', 'Polling Request': 'polling_request' }[item] };
                s.cursor = 0;
                setScreen('callAddress');
                break;
            case 'Individual ACK':
                s.call = { kind: 'individual_ack', address: s.ackable.from, channel: s.ackable.channel ?? null, category: s.ackable.category ?? 'Routine' };
                s.cursor = 0;
                setScreen('ackAction');
                break;
            case 'Position Reply':
                s.call = { kind: 'position_reply', address: s.replyTo.position, position: s.position, utc: s.utc };
                setScreen('callReady');
                break;
            case 'Polling Reply':
                s.call = { kind: 'polling_reply', address: s.replyTo.polling };
                setScreen('callReady');
                break;
            case 'Add:INDV ID':
                startEditor('addIndv');
                break;
            case 'Add:Group ID':
                startEditor('addGroup');
                break;
            case 'All Ships Call':
                s.call = { kind: 'all_ships' };
                s.cursor = 0;
                setScreen('callCategory');
                break;
            case 'Received Calls':
                s.cursor = 0;
                setScreen('receivedList');
                break;
        }
    }

    // --- Ziffern-Editor für Position, UTC und MMSI ------------------------

    function startEditor(kind) {
        const templates = {
            position: { title: 'Input Position', template: '##°##.###N ###°##.###E', next: 'utc' },
            utc: { title: 'Input UTC Time', template: '##:##', next: null },
            mmsi: { title: 'Input 9 digits', template: '#########', next: 'category' },
            addIndv: { title: 'Input ID (9 digits)', template: '#########', next: null },
            addGroup: { title: 'Input Group ID', template: '#########', next: null },
        };
        const t = templates[kind];
        const chars = t.template.split('').map((c) => (c === '#' ? '0' : c));
        const editable = t.template.split('').map((c, i) => (c === '#' || c === 'N' || c === 'E' ? i : -1)).filter((i) => i >= 0);
        s.editor = { kind, title: t.title, chars, editable, pos: 0, next: t.next };
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

    function editorConfirm() {
        const e = s.editor;
        const text = e.chars.join('');

        if (e.kind === 'position') {
            const m = text.match(/^(\d\d)°(\d\d\.\d\d\d)([NS]) (\d\d\d)°(\d\d\.\d\d\d)([EW])$/);
            s.position = [Number(m[1]), Number(m[2]), m[3], Number(m[4]), Number(m[5]), m[6]];
            s.positionSetAt = clock();
            event('position_set', { position: s.position });
            startEditor('utc');
        } else if (e.kind === 'utc') {
            s.utc = text;
            event('utc_set', { utc: s.utc });
            closeToNormal();
        } else if (e.kind === 'mmsi') {
            s.call.address = { name: directory.find((d) => d.mmsi === text)?.name ?? null, mmsi: text };
            s.editor = null;
            s.cursor = 0;
            afterAddress();
        } else if (e.kind === 'addIndv' || e.kind === 'addGroup') {
            const group = e.kind === 'addGroup';
            // Gruppen-MMSI beginnen mit 0, Schiffs-MMSI nicht.
            if (group !== text.startsWith('0') || (! group && text.startsWith('00'))) {
                event('address_invalid', { mmsi: text, group });

                return;
            }
            const list = group ? s.groups : s.book;
            const name = directory.find((d) => d.mmsi === text)?.name ?? `ID ${text}`;
            if (! list.some((a) => a.mmsi === text)) list.push({ name, mmsi: text, ...(group ? { group: true } : {}) });
            event('address_added', { mmsi: text, name, group });
            s.editor = null;
            openMenu('Set up', SETUP_MENU, ['Select Item', mainMenuItems()]);
        }
    }

    // --- DSC-Anrufe --------------------------------------------------------

    function addressList() {
        return s.call?.kind === 'group' ? s.groups : s.book;
    }

    function callAddressItems() {
        return ['Manual Input', ...addressList().map((a) => a.name)];
    }

    function afterAddress() {
        const c = s.call;
        s.cursor = 0;
        if (c.kind === 'position_request' || c.kind === 'polling_request') setScreen('callReady');
        else setScreen('callCategory');
    }

    function chooseCallStep() {
        const c = s.call;

        if (s.screen === 'callAddress') {
            if (s.cursor === 0) {
                startEditor('mmsi');

                return;
            }
            c.address = addressList()[s.cursor - 1];
            afterAddress();
        } else if (s.screen === 'callCategory') {
            c.category = CATEGORIES[s.cursor];
            s.cursor = 0;
            if (c.kind === 'individual' || c.kind === 'group') {
                c.channel = 8;
                setScreen('callChannel');
            } else {
                setScreen('callReady');
            }
        } else if (s.screen === 'ackAction') {
            c.action = s.cursor === 0 ? 'able' : 'unable';
            if (c.action === 'unable') c.channel = null;
            setScreen('callReady');
        } else if (s.screen === 'callChannel') {
            setScreen('callReady');
        } else if (s.screen === 'callReady') {
            event('dsc_sent', { kind: c.kind, address: c.address ?? null, category: c.category ?? null, channel: c.channel ?? null, action: c.action ?? null });
            if (c.kind === 'individual_ack') s.ackable = null;
            if (c.kind === 'position_reply') s.replyTo.position = null;
            if (c.kind === 'polling_reply') s.replyTo.polling = null;
            setScreen('callTx');
            if (['individual', 'position_request', 'polling_request'].includes(c.kind)) c.waiting = true;
        }
    }

    // --- Suchlauf und Wache ------------------------------------------------

    function startScan() {
        if (! s.tags.size) {
            event('scan_blocked', { reason: 'no_tag' });

            return;
        }
        s.watch = 'off';
        s.scan = { list: [...s.tags].sort((a, b) => a - b), index: -1, last: clock(), from: s.channel };
        event('scan', { running: true, channels: s.scan.list });
    }

    function stopScan() {
        if (! s.scan) return;
        s.scan = null;
        event('scan', { running: false, channel: s.channel });
    }

    // --- Notalarm ----------------------------------------------------------

    function sendDistress(repeat = false) {
        const nature = NATURES[s.nature];
        s.distress = { nature, sentAt: clock(), nextAt: clock() + DISTRESS_REPEAT, waiting: true, acked: false, count: (s.distress?.count ?? 0) + 1 };
        s.channel = 70;
        setScreen('distressSent');
        event('dsc_sent', { kind: 'distress', nature, position: s.position, utc: s.utc, repeat });
    }

    function cancelDistressRepeat() {
        if (! s.distress?.waiting) return;
        s.distress.waiting = false;
        event('distress_repeat_cancelled', { nature: s.distress.nature });
        setScreen('normal');
    }

    // --- Tastenlogik -------------------------------------------------------

    function shortPress(key) {
        if (! s.power) {
            if (key === 'power') {
                powerOn();
                // Einschalten bei gedrückter 16•C öffnet den Set-Modus.
                if (pressed.ch16) {
                    pressed.ch16.combo = true;
                    s.cursor = 0;
                    setScreen('setMode');
                    event('set_mode', { open: true });
                }
            }

            return;
        }

        if (s.screen === 'setMode') {
            const item = SET_MODE[s.cursor];
            if (key === 'clr' || (key === 'enter' && item === 'Exit')) {
                event('set_mode', { open: false });
                closeToNormal();
            } else if (key === 'enter' && item === 'Dual/Tri') {
                s.watchMode = s.watchMode === 'dual' ? 'tri' : 'dual';
                event('set_mode', { item, value: s.watchMode });
            } else if (key === 'enter' && item === 'Scan Type') {
                event('set_mode', { item, value: 'Priority' });
            }

            return;
        }

        if (key === 'distressCover') {
            s.cover = ! s.cover;
            event('cover', { open: s.cover });

            return;
        }

        // Empfangener Anruf: CLR schaltet erst den Alarmton ab, ein zweites
        // CLR verlässt die Anzeige; ENTER geht auf den vorgeschlagenen
        // Arbeitskanal bzw. öffnet die Antwort auf einen Einzelanruf.
        if (s.incoming && s.screen === 'received' && (key === 'clr' || key === 'enter')) {
            const i = s.incoming;
            // ENTER ohne vorheriges CLR schaltet den Alarm mit ab.
            if (key === 'enter' && ! i.silenced) {
                i.silenced = true;
                event('alarm_stopped', { call: i.type });
            }
            if (key === 'clr' && ! i.silenced) {
                i.silenced = true;
                event('alarm_stopped', { call: i.type });

                return;
            }
            if (key === 'enter' && i.type === 'individual') {
                i.silenced = true;
                s.incoming = null;
                s.call = { kind: 'individual_ack', address: i.from, channel: i.channel ?? null, category: i.category ?? 'Routine' };
                s.cursor = 0;
                setScreen('ackAction');

                return;
            }
            if (key === 'enter' && CHANNEL_ON_ENTER.has(i.type) && i.channel) {
                s.incoming = null;
                closeToNormal();
                setChannel(i.channel);
                event('call_accepted', { call: i.type, channel: i.channel });

                return;
            }
            if (key === 'enter' && i.type === 'position_reply') {
                event('position_info', { from: i.from, position: i.position ?? null });

                return;
            }
            s.incoming = null;
            setScreen(s.distress?.waiting ? 'distressWait' : 'normal');

            return;
        }

        if (s.incoming && key === 'clr') {
            event('alarm_stopped', { call: s.incoming.type });
            s.incoming = null;
            setScreen(s.distress?.waiting ? 'distressWait' : 'normal');

            return;
        }

        switch (s.screen) {
            case 'menu':
                if (key === 'enter') chooseMenuItem();
                else if (key === 'clr' || key === 'menu') { if (s.menu.back) openMenu(...s.menu.back); else closeToNormal(); }
                else if (key === 'micUp') s.cursor = Math.max(0, s.cursor - 1);
                else if (key === 'micDown') s.cursor = Math.min(s.menu.items.length - 1, s.cursor + 1);

                return;
            case 'nature':
                if (key === 'enter') {
                    event('nature_selected', { nature: NATURES[s.cursor] });
                    s.nature = s.cursor;
                    s.menu = null;
                    if (! s.position) startEditor('position');
                    else setScreen('distressReady');
                } else if (key === 'clr') {
                    closeToNormal();
                }

                return;
            case 'editor':
                if (key === 'enter') editorConfirm();
                else if (key === 'clr') { s.call = null; closeToNormal(); }
                else if (key === 'ch16') editorMove(1);
                else if (key === 'dial') editorMove(-1);

                return;
            case 'mmsiCheck':
            case 'receivedList':
                if (key === 'clr' || key === 'enter') openMenu('Select Item', mainMenuItems());

                return;
            case 'callAddress':
            case 'callCategory':
            case 'callChannel':
            case 'callReady':
            case 'ackAction':
                if (key === 'enter') chooseCallStep();
                else if (key === 'clr') { s.call = null; closeToNormal(); }

                return;
            case 'callTx':
            case 'distressReady':
                if (key === 'clr') { s.call = null; closeToNormal(); }

                return;
            case 'distressSent':
            case 'distressWait':
                if (key === 'clr') { cancelDistressRepeat(); return; }
                break;
        }

        // Binnenmodus (Kanalgruppe ATIS): kein DSC und kein Kanal 16.
        if (s.group === 'ATIS' && (key === 'menu' || key === 'ch16')) {
            event(key === 'menu' ? 'dsc_blocked' : 'ch16_unavailable', { group: s.group, key });

            return;
        }

        switch (key) {
            case 'menu':
                openMenu('Select Item', mainMenuItems());
                break;
            case 'ch16':
                if (s.channel !== 16 && s.channel !== s.callChannel[s.group]) s.workChannel = s.channel;
                setChannel(16, { work: false });
                break;
            case 'dial':
                if (s.scan) {
                    stopScan();
                } else if (s.watch !== 'off') {
                    s.watch = 'off';
                    event('watch', { mode: 'off' });
                } else {
                    setChannel(s.workChannel);
                }
                break;
            case 'hl':
            case 'micHiLo':
                if (current()?.lowPowerOnly) {
                    event('power_level_blocked', { channel: s.channel });
                } else {
                    s.highPower = ! s.highPower;
                    event('power_level', { high: s.highPower, channel: s.channel });
                }
                break;
            case 'micUp':
            case 'micDown':
                setChannel(stepChannel(s.group, s.channel, key === 'micUp' ? 1 : -1));
                break;
            case 'scan':
                if (s.scan) stopScan();
                else startScan();
                break;
        }
    }

    function longPress(key) {
        if (! s.power || s.screen === 'setMode') return;

        switch (key) {
            case 'power':
                powerOff();
                break;
            case 'ch16':
                if (s.channel !== 16 && s.channel !== s.callChannel[s.group]) s.workChannel = s.channel;
                setChannel(s.callChannel[s.group], { work: false });
                s.onCall = true;
                event('call_channel', { channel: s.callChannel[s.group] });
                break;
            case 'dial':
                if (s.scan) stopScan();
                s.watch = s.watchMode;
                event('watch', { mode: s.watch, channel: s.channel });
                break;
            case 'scan': {
                const on = ! s.tags.has(s.channel);
                if (on) s.tags.add(s.channel);
                else s.tags.delete(s.channel);
                event('tag', { channel: s.channel, on });
                break;
            }
            case 'clr':
                if (s.screen === 'editor' && s.editor.kind !== 'mmsi') {
                    s.editor.chars = s.editor.chars.map((c) => (/\d/.test(c) ? '0' : c));
                    event('editor_cleared', { kind: s.editor.kind });
                }
                break;
        }
    }

    function combo(modifier, key) {
        if (modifier === 'hl' && key === 'dial') {
            const order = ['INT', 'ATIS', 'DSC'];
            const group = order[(order.indexOf(s.group) + 1) % order.length];
            const ch = channelInfo(group, s.channel) ? s.channel : CHANNEL_GROUPS[group][0].number;
            setChannel(ch, { group });
            event('group', { group });
        }
    }

    // --- Öffentliche Schnittstelle ------------------------------------------

    const radio = {
        get state() {
            return snapshot();
        },
        keyDown(key) {
            const modifier = Object.keys(pressed).find((k) => k === 'hl' && ! pressed[k].released);
            pressed[key] = { t0: clock(), long: false, veryLong: false, combo: false };

            if (modifier && key !== 'hl') {
                pressed[modifier].combo = true;
                pressed[key].combo = true;
                combo(modifier, key);

                return;
            }

            if (key === 'distress' && s.power && s.group === 'ATIS') {
                event('dsc_blocked', { group: s.group, key });

                return;
            }

            if (key === 'distress' && s.power) {
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

            if (p.combo || p.long) return;
            shortPress(key);
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

            if (s.screen === 'menu') {
                s.cursor = Math.max(0, Math.min(s.menu.items.length - 1, s.cursor + steps));
            } else if (s.screen === 'nature') {
                s.cursor = Math.max(0, Math.min(NATURES.length - 1, s.cursor + steps));
            } else if (s.screen === 'editor') {
                editorTurn(steps);
            } else if (s.screen === 'callAddress') {
                s.cursor = Math.max(0, Math.min(callAddressItems().length - 1, s.cursor + steps));
            } else if (s.screen === 'callCategory') {
                s.cursor = Math.max(0, Math.min(CATEGORIES.length - 1, s.cursor + steps));
            } else if (s.screen === 'callChannel') {
                s.call.channel = stepIntership(s.call.channel, Math.sign(steps), { coast: Boolean(s.call.address?.mmsi?.startsWith('00')) });
            } else if (s.screen === 'ackAction') {
                s.cursor = Math.max(0, Math.min(1, s.cursor + steps));
            } else if (s.screen === 'setMode') {
                s.cursor = Math.max(0, Math.min(SET_MODE.length - 1, s.cursor + steps));
            } else if (s.screen === 'normal' || s.screen === 'distressWait') {
                const direction = Math.sign(steps);
                for (let i = 0; i < Math.abs(steps); i++) setChannel(stepChannel(s.group, s.channel, direction));
            }
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
            // Im Binnenmodus hängt das Gerät nach jedem Loslassen der
            // Sprechtaste die ATIS-Kennung an.
            if (s.group === 'ATIS') event('atis_sent', { id: own?.atis ?? null, channel: s.channel, circle: current()?.circle ?? null });
        },
        // Erkannter Text eines Funkspruchs (Spracherkennung oder Tipp-Eingabe).
        spoken(text, channel = s.channel) {
            event('spoken', { text, channel, group: s.group, highPower: effectiveHighPower(), circle: current()?.circle ?? null });
        },
        tick() {
            const now = clock();

            for (const [key, p] of Object.entries(pressed)) {
                const held = now - p.t0;
                if (key === 'distress') continue;
                if (! p.combo && ! p.long && held >= LONG) {
                    p.long = true;
                    longPress(key);
                }
            }

            if (s.screen === 'distressHold' && s.distressHold && now - s.distressHold.t0 >= DISTRESS_HOLD) {
                s.distressHold = null;
                sendDistress();
            }

            if (s.screen === 'distressSent' && now - s.screenSince >= AUTO_RETURN) {
                s.channel = 16;
                setScreen('distressWait');
                event('channel', { channel: 16, group: s.group, auto: true });
            }

            if (s.screen === 'callTx' && s.call?.waiting && s.call.kind !== 'individual' && now - s.screenSince >= AUTO_RETURN) {
                // Positions- und Polling-Abfrage: nach 2 s zurück, die Antwort
                // kommt später als eigener Empfang.
                s.call = null;
                closeToNormal();
            }

            if (s.screen === 'callTx' && s.call && ! s.call.waiting && now - s.screenSince >= AUTO_RETURN) {
                if (s.call.channel) setChannel(s.call.channel);
                s.call = null;
                closeToNormal();
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
            if (pending.length && ! BUSY.includes(s.screen)) this.receive(pending.shift(), true);
        },
        // Eingehender DSC-Anruf einer Gegenstation (Lektion oder Freies Üben).
        receive(call, queued = false) {
            // Aus oder im Binnenmodus (ATIS, DSC gesperrt): kein Empfang.
            if (! s.power || s.group === 'ATIS') return false;
            // Während das Gerät selbst sendet, DISTRESS gehalten wird,
            // geht kein Anruf verloren: Er kommt an, sobald das Gerät wieder
            // empfängt (danach wie jeder andere Anruf, auch mit Kanalwechsel).
            if (! queued && BUSY.includes(s.screen)) {
                pending.push(call);

                return true;
            }
            if (s.scan) stopScan();
            s.received.unshift({ ...call, at: clock() });
            if (call.type === 'individual') s.ackable = call;
            if (call.type === 'position_request') s.replyTo.position = call.from;
            if (call.type === 'polling_request') s.replyTo.polling = call.from;

            if (call.type === 'distress_ack' && s.distress?.waiting) {
                s.distress.waiting = false;
                s.distress.acked = true;
            }

            // „Able to comply“ auf den eigenen Einzelanruf: das Gerät stellt den
            // vereinbarten Arbeitskanal selbst ein.
            if (call.type === 'individual_ack' && s.call?.waiting) {
                const channel = s.call.channel;
                s.call = null;
                if (call.action !== 'unable') setChannel(channel);
            }
            if ((call.type === 'position_reply' || call.type === 'polling_reply') && s.call?.waiting) s.call = null;

            if (['distress_ack', 'distress', 'distress_relay'].includes(call.type)) {
                if (s.channel !== 16) s.workChannel = s.channel;
                s.channel = 16;
            }

            s.incoming = { ...call, silenced: false };
            setScreen('received');
            event('dsc_received', { call });
        },
        // Sprechfunk einer Gegenstation auf einem Kanal: hörbar nur, wenn das
        // Gerät an ist, auf dem Kanal steht (oder ihn per Wache bzw.
        // Suchlauf überwacht), die Lautstärke offen ist und die Rauschsperre
        // nicht zu weit zugedreht. Der Grund geht an die Oberfläche
        // (Freies Üben erklärt, warum nichts zu hören war).
        hear(voice) {
            const result = (heard, reason = null) => {
                event('voice', { heard, reason, from: voice.from ?? null, channel: voice.channel, text: voice.text });

                return { heard, reason };
            };
            if (! s.power) return result(false, 'off');
            // Binnenfunk (ATIS) und Seefunk (INT/DSC) sind getrennte Dienste.
            if (voice.group && (voice.group === 'ATIS') !== (s.group === 'ATIS')) return result(false, 'group');
            let listening = s.channel === voice.channel;
            if (! listening && s.watch !== 'off' && (voice.channel === 16 || (s.watch === 'tri' && voice.channel === s.callChannel[s.group]))) {
                listening = true;
            }
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
        // Ausgangslage einer Lektion.
        setup(start = {}) {
            if (start.power) powerOn();
            for (const key of ['vol', 'sql', 'group', 'channel', 'highPower', 'position', 'utc', 'watchMode']) {
                if (key in start) s[key] = start[key];
            }
            if (start.position) s.positionSetAt = clock();
            if (start.tags) s.tags = new Set(start.tags);
            if ('channel' in start) s.workChannel = start.channel;
            if ('nature' in start) s.nature = NATURES.indexOf(start.nature);
            if (start.distressWaiting) {
                s.distress = { nature: start.distressWaiting, sentAt: clock(), nextAt: clock() + DISTRESS_REPEAT, waiting: true, acked: false, count: 1 };
                setScreen('distressWait');
            }
        },
        display() {
            return renderDisplay();
        },
    };

    // --- Display -------------------------------------------------------------

    function renderDisplay() {
        if (! s.power) return { on: false };

        const ch = current();
        const base = {
            on: true,
            status: {
                busy: squelchState() === 'noise' || Boolean(s.incoming && ! s.incoming.silenced),
                tx: s.tx,
                power: effectiveHighPower() ? '25W' : '1W',
                group: s.group,
                dup: Boolean(ch?.duplex),
                call: Boolean(s.onCall),
                atis: s.group === 'ATIS',
            },
            channel: pad(s.channel),
            channelName: ch?.name ?? '',
            watch: s.scan ? 'PRI-SCAN 16' : s.watch === 'dual' ? 'DUAL 16' : s.watch === 'tri' ? 'TRI 16' : null,
            tag: s.tags.has(s.channel),
            lines: [],
            panel: null,
        };
        const stale = positionStale() ? '??' : '';
        const position = formatPosition(s.position)?.map((line) => stale + line);
        const timeLine = s.utc ? `${stale}UTC ${s.utc}` : 'No Time';
        const posLines = position ?? ['No Position'];

        switch (s.screen) {
            case 'menu':
                return { ...base, panel: { title: '--DSC Menu--', subtitle: s.menu.title, items: s.menu.items, cursor: s.cursor, footer: '<CLR→Exit / ENT→OK>' } };
            case 'nature':
                return { ...base, panel: { title: '--DSC Menu--', subtitle: 'Select Nature', items: NATURES, cursor: s.cursor, footer: '<CLR→Exit / ENT→OK>' } };
            case 'editor': {
                const e = s.editor;
                return { ...base, panel: { title: '--DSC Menu--', subtitle: e.title, editor: { chars: e.chars, cursor: e.editable[e.pos] }, footer: e.kind === 'mmsi' ? '<CLR→Exit / ENT→OK>' : '<CLR 1sec→Null Data>' } };
            }
            case 'mmsiCheck':
                return { ...base, panel: { title: '--DSC Menu--', subtitle: 'MMSI Check', lines: [own?.mmsi ?? '---------'], footer: '<CLR→Exit>' } };
            case 'receivedList':
                return { ...base, panel: { title: '--DSC Menu--', subtitle: 'Select Message', lines: s.received.length ? s.received.slice(0, 4).map((r) => `${r.type} ${r.from?.name ?? r.from?.mmsi ?? ''}`) : ['No Message'], footer: '<CLR→Exit>' } };
            case 'callAddress':
                return { ...base, panel: { title: '--DSC Menu--', subtitle: 'Select Address', items: callAddressItems(), cursor: s.cursor, footer: '<CLR→Exit / ENT→OK>' } };
            case 'callCategory':
                return { ...base, panel: { title: '--DSC Menu--', subtitle: 'Select Category', items: CATEGORIES, cursor: s.cursor, footer: '<CLR→Exit / ENT→OK>' } };
            case 'setMode':
                return { ...base, panel: { title: '--Set Mode--', subtitle: SET_MODE[s.cursor] === 'Dual/Tri' ? (s.watchMode === 'dual' ? 'Dualwatch' : 'Tri-watch') : '', items: SET_MODE, cursor: s.cursor, footer: '<CLR→Exit / ENT→OK>' } };
            case 'ackAction':
                return { ...base, panel: { title: '--DSC Menu--', subtitle: 'Select Action', items: ['Able to Comply', 'Unable to Comply'], cursor: s.cursor, footer: '<CLR→Exit / ENT→OK>' } };
            case 'callChannel':
                return { ...base, panel: { title: '--DSC Menu--', subtitle: 'Select Traffic CH', lines: [pad(s.call.channel)], footer: '<CLR→Exit / ENT→OK>' } };
            case 'callReady':
                return { ...base, panel: { title: '--DSC Menu--', subtitle: `${CALL_TITLES[s.call.kind]} Ready`, lines: s.call.address ? [s.call.address.name ?? s.call.address.mmsi] : [], footer: '<CLR→Exit / ENT→OK>' } };
            case 'callTx':
                return { ...base, panel: { title: '--DSC Menu--', subtitle: CALL_TITLES[s.call.kind], lines: s.call.waiting ? ['TX Complete', 'Now Waiting for ACK'] : ['TX Complete'], blink: s.call.waiting, footer: '<CLR→Exit>' } };
            case 'distressReady':
                return { ...base, lines: [`Nature: ${NATURES[s.nature]}`, 'Push DISTRESS', 'for 5 sec.'] };
            case 'distressHold':
                return { ...base, panel: { title: 'Distress Call', lines: ['Push for 5 sec.'], progress: radio.holdProgress() } };
            case 'distressSent':
                return { ...base, channel: '70', channelName: 'DSC', panel: { title: 'Distress Call', lines: ['TX Complete', 'Now Waiting for ACK'], blink: true, footer: '<CLR→Cancel ACK>' } };
            case 'distressWait':
                return { ...base, lines: ['Wait ACK', '<CLR→', 'Cancel ACK>'] };
            case 'received': {
                const i = s.incoming;
                const title = RECEIVED_TITLES[i.type] ?? i.type;
                const detail = i.type === 'individual_ack' && i.action === 'unable' ? 'Unable to Comply' : i.nature ?? i.category ?? '';
                const hint = i.silenced ? (i.type === 'individual' ? '<ENT→ACK>' : CHANNEL_ON_ENTER.has(i.type) && i.channel ? `<ENT→CH ${pad(i.channel)}>` : '<CLR→Exit>') : '<CLR→ Beep Off>';

                return { ...base, lines: ['Received', title, `${i.from?.name ?? i.from?.mmsi ?? ''}`, detail, hint].filter((l) => l !== ''), alarm: ! i.silenced };
            }
            default:
                return { ...base, lines: [...posLines, timeLine] };
        }
    }

    return radio;
}
