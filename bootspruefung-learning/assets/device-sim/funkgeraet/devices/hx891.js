// Gerätelogik Standard Horizon HX891BT/E (Handfunkgerät, DSC Klasse H), nach
// der englischen Bedienungsanleitung (Owner's Manual HX891BT, HX891BT/E).
// Bedienelemente: links Ein/Aus (halten), Sprechtaste und SQL (drücken,
// dann CH▲/CH▼; 3 s halten = Monitor), Tastenfeld CH▲/CH▼, VOL+/VOL−, ◀/▶,
// CLR, Tastensperre (halten), MENU/SET (drücken = MENU, halten = SETUP),
// 16/S (kurz Kanal 16, erneut zurück, halten Zweitkanal 09), drei Softkeys
// auf Seiten (◀/▶), DISTRESS unter der roten Klappe an der Seite (3 s).
// DSC über MENU > DSC CALL: Adresse aus HISTORY, MEMORY oder NEW ID, dann
// Arbeitskanal und YES. Ein Alarm verstummt mit einer beliebigen Taste.
//
// Gleiche Schnittstelle und Ereignisnamen wie die übrigen Geräte. Annahmen,
// wo die Anleitung schweigt bzw. für die Übung: Individual Reply = MANUAL
// (ab Werk AUTO), Position Reply und Polling antworten automatisch, ein
// Testanruf wird automatisch bestätigt, bei einer Küstenfunkstelle (MMSI
// 00…) entfällt die Kanalwahl, nach dem Notalarm hört das Gerät auf
// Kanal 16.
//
// Binnenfunk: SETUP > CH SETUP > CH GROUP „ATIS“ (europäische Version).
// Dann Binnenkanäle mit 1 W außerhalb der Nautischen Information,
// ATIS-Kennung beim Loslassen der Sprechtaste (Kennung vorgegeben, kein
// ATIS SETUP in der Übung), kein Kanal 16 bzw. Zweitkanal, DSC und
// Zwei-/Dreikanalwache und Suchlauf gesperrt.

import { CHANNEL_GROUPS, channelInfo, stepChannel } from '../channels.js';
import { NATURES } from './icm505.js';

export const NATURE_LABELS_HX = ['UNDESIGNATED', 'FIRE,EXPLOSION', 'FLOODING', 'COLLISION', 'GROUNDING', 'CAPSIZING', 'SINKING', 'ADRIFT', 'ABANDONING', 'PIRACY', 'MOB'];

export const DEVICE_HX891 = {
    key: 'hx891bte',
    name: 'Standard Horizon HX891BT/E',
    keys: ['power', 'ptt', 'sql', 'up', 'down', 'left', 'right', 'volUp', 'volDown', 'clr', 'lock', 'menu', 'ch16', 'soft1', 'soft2', 'soft3', 'distressCover', 'distress'],
    knobs: [],
};

// Softkey-Seiten der Werksbelegung HX891BT/E (Anleitung 14.8, Taste 1–12).
export const PAGES_HX = [
    ['PRESET', 'DUAL WATCH', 'FM RADIO'],
    ['TX PWR', 'MOB', 'SCAN MEMORY'],
    ['SCAN', 'WAYPOINT', 'NOISE CANCEL'],
    ['STROBE', 'LOGGER', 'WPT MARK'],
];

const LONG = 1000;
// Zustände, in denen das Gerät nicht empfängt (eigene Aussendung, DISTRESS halten).
const BUSY = ['callTx', 'distressSent', 'distressHold'];
const SQL_MONITOR = 3000;
const DISTRESS_HOLD = 3000;
const DISTRESS_REPEAT = 4 * 60 * 1000;
const TRANSMIT_MS = 1000;
const AUTO_SW = 30000;
const WINDOW_MS = 5000;
const SCAN_STEP = 500;
const POSITION_STALE = 4 * 60 * 60 * 1000;
const SUB_CHANNEL = 9;
const PRIORITY_CHANNEL = 16;

const MAIN_MENU = ['DSC CALL', 'CH', 'GM', 'GPS', 'NAVI', 'MMSI/POS INFO'];
const DSC_CALL = ['INDIVIDUAL', 'GROUP', 'ALL SHIPS', 'POS REQUEST', 'POS REPORT', 'AUTO POS POLLING', 'DIST ALERT MSG', 'DSC LOG', 'DSC TEST'];
const SETUP = ['CONFIGURATION', 'CH SETUP', 'DSC SETUP', 'GPS SETUP', 'ATIS SETUP', 'BLUETOOTH SETUP', 'RESET'];
const CH_SETUP = ['CH GROUP', 'SCAN MEMORY', 'SCAN TYPE', 'SCAN RESUME', 'MULTI WATCH', 'PRIORITY CH', 'SUB CH', 'CH NAME', 'NOISE CANCEL', 'SCRAMBLER', 'VOX', 'AF PITCH'];
const DSC_SETUP = ['INDIVIDUAL DIR.', 'INDIVIDUAL REPLY', 'INDIVIDUAL ACK', 'INDIVIDUAL RING', 'POSITION REPLY', 'GROUP DIR.', 'AUTO POS POLLING', 'AUTO POS TIME', 'CH SWITCH TIMER', 'NO ACT TIMER', 'POS FIX WAIT', 'DSC BEEP'];
const GROUP_LABELS = { INT: 'INTL', ATIS: 'ATIS' };
const AVAILABLE = new Set([
    'DSC CALL', 'CH', 'MMSI/POS INFO', 'INDIVIDUAL', 'GROUP', 'ALL SHIPS', 'POS REQUEST', 'POS REPORT', 'DIST ALERT MSG', 'DSC LOG', 'DSC TEST',
    'CH SETUP', 'DSC SETUP', 'CH GROUP', 'SCAN TYPE', 'MULTI WATCH', 'INDIVIDUAL DIR.', 'INDIVIDUAL REPLY', 'RECEIVED', 'TRANSMITTED',
]);
// Arbeitskanäle der Kanalauswahl (INTERSHIP CH); MANUAL zeigt alle.
const INTERSHIP = [6, 8, 9, 10, 13, 67, 68, 69, 72, 73, 77];
const ALL_SHIPS_CH = [16, ...INTERSHIP];
const TITLES = {
    individual: 'INDIVIDUAL CALL', group: 'GROUP CALL', all_ships: 'ALL SHIPS CALL', position_request: 'POS REQUEST CALL', position_report: 'POS REPORT CALL',
    test: 'DSC TEST CALL', individual_ack: 'INDIVIDUAL ACK',
};
const RECEIVED = {
    distress: 'RX DISTRESS', distress_ack: 'RX DISTRESS ACK', distress_relay: 'RX DIST RELAY', individual: 'RX INDIVIDUAL', individual_ack: 'RX INDIVIDUAL ACK',
    group: 'RX GROUP', all_ships: 'RX ALL SHIPS', geographic: 'RX GEOGRAPHIC', position_request: 'RX POS REQUEST', position_reply: 'RX POS REPLY',
    polling_request: 'RX POLLING', test_call: 'RX DSC TEST', test_ack: 'RX TEST ACK',
};
const DISTRESS_TYPES = new Set(['distress', 'distress_relay', 'distress_ack']);
// Bildschirme nach einem abgeschlossenen Vorgang; QUIT, CLR und MENU verlassen sie.
const DONE_SCREENS = ['callDone', 'rcvdAccepted', 'cancelDone', 'distressAcked'];
// Empfangene Anrufe mit Kanalautomatik (CH SWITCH TIMER 30 s).
const AUTO_SWITCH = new Set(['distress', 'distress_relay', 'distress_ack', 'group', 'all_ships', 'geographic']);
const EDITORS = {
    postm: { title: 'POSITION/TIME', template: '##°##.####N###°##.####E##:##', row: '0123456789NSEW' },
    mmsi: { title: 'MMSI', template: '#########', row: '0123456789' },
    group: { title: 'GROUP MMSI', template: '0########', row: '0123456789' },
};
const ALARM_SCREENS = ['rcvdAlarm', 'ackAlarm'];

function pad(n, width = 2) {
    return String(n).padStart(width, '0');
}

function formatPosition(pos) {
    if (! pos) return null;
    const [latD, latM, ns, lonD, lonM, ew] = pos;

    return [`${pad(latD)}°${latM.toFixed(4).padStart(7, '0')}${ns}`, `${pad(lonD, 3)}°${lonM.toFixed(4).padStart(7, '0')}${ew}`];
}

const nameOf = (address) => address?.name ?? address?.mmsi ?? '';

// inland: false sperrt die Kanalgruppe ATIS (Binnenfunk). Die Bootsschule
// lässt Binnenfunk am Handfunkgerät nicht üben: Auf Sportbooten ist es in
// Deutschland verboten. Statt umzuschalten meldet das Gerät inland_blocked.
export function createHx891({ own, clock = () => Date.now(), emit = () => {}, addressBook = [], directory = [], inland = true } = {}) {
    const s = {
        power: false,
        vol: 0,
        sql: 3,
        monitor: false,
        group: 'INT',
        channel: 16,
        workChannel: 16,
        special: null,
        txPower: 'HI',
        watch: 'off',
        watchMode: 'dual',
        scanType: 'priority',
        scan: null,
        tags: new Set(),
        tx: false,
        screen: 'off',
        screenSince: 0,
        page: 0,
        locked: false,
        window: null,
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
        sent: [],
        unread: 0,
        history: [],
        individualReply: 'manual',
        book: addressBook.filter((a) => ! a.group).map((a) => ({ ...a })),
        groups: addressBook.filter((a) => a.group).map((a) => ({ ...a })),
        draft: null,
        cover: false,
    };
    const pressed = {};

    const event = (type, data = {}) => emit({ type, at: clock(), ...data, radio: snapshot() });

    function current() {
        return channelInfo(s.group, s.channel);
    }

    function effectiveHighPower() {
        return s.txPower === 'HI' && ! current()?.lowPowerOnly;
    }

    function powerLabel() {
        return current()?.lowPowerOnly ? 'LO' : s.txPower;
    }

    function positionStale() {
        return Boolean(s.position && s.positionSetAt !== null && clock() - s.positionSetAt > POSITION_STALE);
    }

    function snapshot() {
        return {
            power: s.power, group: s.group, channel: s.channel, highPower: effectiveHighPower(), vol: s.vol, sql: s.sql, screen: s.screen, watch: s.watch,
            nature: NATURES[s.nature], position: s.position, utc: s.utc, coverOpen: s.cover, tx: s.tx, scanning: Boolean(s.scan), tags: [...s.tags].sort((a, b) => a - b),
            addressBook: s.book.map((a) => a.mmsi), groups: s.groups.map((a) => a.mmsi), positionStale: positionStale(), circle: current()?.circle ?? null,
            knobMode: s.window === 'sql' ? 'sql' : 'vol', page: s.page, softkeys: softkeys(), locked: s.locked, txPower: s.txPower,
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

    function setChannel(channel, { work = true } = {}) {
        if (s.scan) stopScan();
        // Jeder Kanalwechsel beendet 16/S und den Zweitkanal; die Taste
        // 16/S setzt den Zustand danach selbst.
        s.special = null;
        s.channel = channel;
        if (work && channel !== PRIORITY_CHANNEL && channel !== SUB_CHANNEL) s.workChannel = channel;
        event('channel', { channel, group: s.group, circle: channelInfo(s.group, channel)?.circle ?? null });
    }

    function squelchState() {
        if (! s.power) return 'off';
        if (s.sql <= 0 || s.monitor) return 'noise';
        if (s.sql >= 7) return 'tight';

        return 'set';
    }

    function backToNormal() {
        s.menu = null;
        s.editor = null;
        s.draft = null;
        if (s.distress?.waiting) setScreen('distressWait');
        else setScreen('normal');
    }

    // --- Softkeys --------------------------------------------------------------

    function pageLabels(index) {
        return PAGES_HX[index].map((label) => (label === 'DUAL WATCH' && s.watchMode === 'tri' ? 'TRIPLE WATCH' : label));
    }

    function softkeys() {
        switch (s.screen) {
            case 'off':
            case 'callTx':
            case 'distressSent':
                return ['', '', ''];
            case 'normal':
                return pageLabels(s.page);
            case 'menu':
                return s.menu?.list === 'dir' ? ['BACK', '', 'SELECT'] : ['BACK', '', 'SELECT'];
            case 'editor':
                return ['BACK', 'FINISH', 'SELECT'];
            case 'mmsiInfo':
                return ['OK', '', 'POS/TM'];
            case 'distAlert':
                return ['BACK', 'NATURE', 'POS/TM'];
            case 'chList':
                return ['BACK', s.call?.manual ? 'INTERSHIP' : 'MANUAL', 'SELECT'];
            case 'confirm':
            case 'cancelConfirm':
                return ['YES', '', 'NO'];
            case 'callWait':
                return ['RESEND', '', 'QUIT'];
            case 'callDone':
                return s.call?.kind === 'individual_ack' ? ['RESEND', '', 'QUIT'] : ['', '', 'QUIT'];
            case 'rcvdAlarm':
            case 'ackAlarm':
                return ['STOP ALARM', 'STOP ALARM', 'STOP ALARM'];
            case 'rcvdAction':
                return AUTO_SWITCH.has(s.incoming?.type) ? ['ACCEPT', s.incoming?.paused ? 'RESUME' : 'PAUSE', 'QUIT'] : ['', '', 'QUIT'];
            case 'rcvdRespond':
                return ['ABLE', 'UNABLE', 'QUIT'];
            case 'ackConfirm':
                return s.call?.action === 'unable' ? ['YES', '', 'NO'] : ['YES', 'CHG CH', 'NO'];
            case 'rcvdAccepted':
            case 'posInfo':
            case 'log':
                return ['', '', 'QUIT'];
            case 'distressHold':
                return ['', '', 'QUIT'];
            case 'distressWait':
                return [s.distress?.paused ? 'RESUME' : 'PAUSE', '', 'CANCEL'];
            case 'cancelDone':
            case 'distressAcked':
                return ['', '', 'OK'];
            default:
                return ['', '', ''];
        }
    }

    function softkey(index) {
        const label = softkeys()[index];
        if (! label) return;
        event('softkey', { label });

        switch (label) {
            case 'DUAL WATCH':
            case 'TRIPLE WATCH':
                if (s.group === 'ATIS') {
                    event('watch_blocked', { group: s.group });
                    break;
                }
                if (s.watch !== 'off') {
                    s.watch = 'off';
                    event('watch', { mode: 'off' });
                } else {
                    if (s.scan) stopScan();
                    s.watch = s.watchMode;
                    event('watch', { mode: s.watch, channel: s.channel });
                }
                break;
            case 'TX PWR':
                changePower();
                break;
            case 'SCAN MEMORY': {
                const on = ! s.tags.has(s.channel);
                if (on) s.tags.add(s.channel);
                else s.tags.delete(s.channel);
                event('tag', { channel: s.channel, on });
                break;
            }
            case 'SCAN':
                if (s.group === 'ATIS') event('watch_blocked', { group: s.group, scan: true });
                else if (s.scan) stopScan();
                else startScan();
                break;
            case 'BACK':
                back();
                break;
            case 'SELECT':
                select();
                break;
            case 'FINISH':
                editorFinish();
                break;
            case 'POS/TM':
                startEditor('postm', s.screen === 'mmsiInfo' ? () => setScreen('mmsiInfo') : () => setScreen('distAlert'));
                break;
            case 'NATURE':
                openList('NATURE OF', NATURE_LABELS_HX, (labelText, i) => {
                    s.nature = i;
                    event('nature_selected', { nature: NATURES[i], label: labelText });
                    setScreen('distAlert');
                }, () => setScreen('distAlert'), s.nature);
                break;
            case 'OK':
                if (s.screen === 'distressAcked') s.distress = null;
                if (s.screen === 'cancelDone') s.distress = null;
                s.call = null;
                backToNormal();
                break;
            case 'MANUAL':
            case 'INTERSHIP':
                s.call.manual = label === 'MANUAL';
                s.cursor = 0;
                break;
            case 'YES':
                yes();
                break;
            case 'NO':
                no();
                break;
            case 'RESEND':
                if (s.call) transmit(s.call, true);
                break;
            case 'QUIT':
                quit();
                break;
            case 'STOP ALARM':
                stopAlarm();
                break;
            case 'ACCEPT':
                acceptIncoming();
                break;
            case 'PAUSE':
            case 'RESUME':
                if (s.screen === 'distressWait') pauseDistress(label === 'PAUSE');
                else pauseCountdown(label === 'PAUSE');
                break;
            case 'ABLE':
            case 'UNABLE':
                respond(label === 'ABLE' ? 'able' : 'unable');
                break;
            case 'CHG CH':
                s.call.chFor = 'ack';
                s.call.manual = false;
                s.call.back = () => setScreen('ackConfirm');
                s.cursor = 0;
                setScreen('chList');
                break;
            case 'CANCEL':
                setScreen('cancelConfirm');
                break;
            default:
                event('softkey_unavailable', { label });
        }
    }

    // Kanalgruppe wechseln: Seefunk (INTL) bzw. Binnenfunk (ATIS).
    function setGroup(group) {
        if (s.scan) stopScan();
        if (group === 'ATIS' && s.watch !== 'off') {
            s.watch = 'off';
            event('watch', { mode: 'off' });
        }
        s.group = group;
        s.special = null;
        const ch = channelInfo(group, s.channel) ? s.channel : CHANNEL_GROUPS[group][0].number;
        setChannel(ch);
        event('group', { group });
    }

    function changePower() {
        if (current()?.lowPowerOnly) {
            event('power_level_blocked', { channel: s.channel });

            return;
        }
        s.txPower = { HI: 'MD', MD: 'LO', LO: 'HI' }[s.txPower];
        event('power_level', { high: s.txPower === 'HI', level: s.txPower, channel: s.channel });
    }

    // --- Listen und Menüs --------------------------------------------------------

    function openMenu(title, items, backFn = null, list = 'menu') {
        s.menu = { title, items, back: backFn, list };
        s.cursor = 0;
        setScreen('menu');
    }

    function openList(title, items, onSelect, backFn, cursor = 0) {
        openMenu(title, items, backFn, 'select');
        s.menu.onSelect = onSelect;
        s.cursor = cursor;
    }

    const openMain = () => openMenu('MENU', MAIN_MENU);
    const openDscCall = () => openMenu('DSC CALL', DSC_CALL, openMain);
    const openSetup = () => openMenu('SETUP', SETUP);
    const openChSetup = () => openMenu('CH SETUP', CH_SETUP, openSetup);
    const openDscSetup = () => openMenu('DSC SETUP', DSC_SETUP, openSetup);

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
        if (s.screen === 'chList' && s.call?.back) {
            s.call.back();

            return;
        }
        s.call = null;
        backToNormal();
    }

    function select() {
        if (s.screen === 'menu') chooseMenuItem();
        else if (s.screen === 'editor') editorEnter();
        else if (s.screen === 'chList') chooseChannel();
    }

    function chooseMenuItem() {
        const item = s.menu.items[s.cursor];
        if (s.menu.list === 'select') {
            s.menu.onSelect(item, s.cursor);

            return;
        }
        if (s.menu.list === 'log') return;
        if (s.menu.list === 'dir') {
            chooseDirRow();

            return;
        }
        if (! AVAILABLE.has(item)) {
            event('menu_unavailable', { item });

            return;
        }
        event('menu', { item });

        switch (item) {
            case 'DSC CALL':
                if (s.group === 'ATIS') {
                    event('dsc_blocked', { group: s.group, key: 'menu' });
                    break;
                }
                openDscCall();
                break;
            case 'CH':
                backToNormal();
                break;
            case 'MMSI/POS INFO':
                s.menu = null;
                setScreen('mmsiInfo');
                event('mmsi_checked', { mmsi: own?.mmsi });
                break;
            case 'INDIVIDUAL':
            case 'POS REQUEST':
            case 'POS REPORT':
            case 'DSC TEST':
                startCall({ INDIVIDUAL: 'individual', 'POS REQUEST': 'position_request', 'POS REPORT': 'position_report', 'DSC TEST': 'test' }[item]);
                break;
            case 'GROUP':
                startCall('group');
                break;
            case 'ALL SHIPS':
                s.call = { kind: 'all_ships', address: null, category: null, channel: 16 };
                openList('CATEGORY', ['SAFETY', 'URGENCY'], (label) => {
                    s.call.category = label === 'URGENCY' ? 'Urgency' : 'Safety';
                    openChannels(() => openDscCall());
                }, openDscCall);
                break;
            case 'DIST ALERT MSG':
                s.menu = null;
                s.cursor = 0;
                setScreen('distAlert');
                break;
            case 'DSC LOG':
                openMenu('DSC LOG', ['RECEIVED', 'TRANSMITTED'], openDscCall);
                break;
            case 'RECEIVED':
                s.unread = 0;
                openMenu('RECEIVED', s.received.length ? s.received.slice(0, 8).map((r) => `${(RECEIVED[r.type] ?? r.type).replace('RX ', '')} ${nameOf(r.from)}`) : ['NO DATA'], () => openMenu('DSC LOG', ['RECEIVED', 'TRANSMITTED'], openDscCall), 'log');
                break;
            case 'TRANSMITTED':
                openMenu('TRANSMITTED', s.sent.length ? s.sent.slice(0, 8).map((k) => TITLES[k] ?? k) : ['NO DATA'], () => openMenu('DSC LOG', ['RECEIVED', 'TRANSMITTED'], openDscCall), 'log');
                break;
            case 'CH SETUP':
                openChSetup();
                break;
            case 'DSC SETUP':
                openDscSetup();
                break;
            case 'CH GROUP':
                openList('CH GROUP', ['INTL', 'ATIS'], (label) => {
                    if (label === 'ATIS' && ! inland) {
                        event('inland_blocked', { group: 'ATIS' });
                        openChSetup();

                        return;
                    }
                    setGroup(label === 'ATIS' ? 'ATIS' : 'INT');
                    openChSetup();
                }, openChSetup, s.group === 'ATIS' ? 1 : 0);
                break;
            case 'SCAN TYPE':
                openList('SCAN TYPE', ['PRIORITY', 'MEMORY'], (label) => {
                    s.scanType = label === 'MEMORY' ? 'memory' : 'priority';
                    event('set_mode', { item: 'Scan Type', value: s.scanType });
                    openChSetup();
                }, openChSetup, s.scanType === 'memory' ? 1 : 0);
                break;
            case 'MULTI WATCH':
                openList('MULTI WATCH', ['DUAL', 'TRIPLE'], (label) => {
                    s.watchMode = label === 'TRIPLE' ? 'tri' : 'dual';
                    event('set_mode', { item: 'Dual/Tri', value: s.watchMode });
                    openChSetup();
                }, openChSetup, s.watchMode === 'tri' ? 1 : 0);
                break;
            case 'INDIVIDUAL REPLY':
                openList('INDIVIDUAL REPLY', ['AUTO', 'MANUAL'], (label) => {
                    s.individualReply = label === 'AUTO' ? 'auto' : 'manual';
                    event('set_mode', { item: 'Individual Reply', value: s.individualReply });
                    openDscSetup();
                }, openDscSetup, s.individualReply === 'auto' ? 0 : 1);
                break;
            case 'INDIVIDUAL DIR.':
                openList('INDIVIDUAL DIR.', ['ADD', 'EDIT', 'DELETE'], (label) => {
                    if (label !== 'ADD') {
                        event('menu_unavailable', { item: label });

                        return;
                    }
                    s.draft = { name: null, mmsi: null };
                    openDir();
                }, openDscSetup);
                break;
        }
    }

    // Neuer Eintrag im Verzeichnis: NAME (aus der MMSI übernommen), MMSI, SAVE.
    function openDir(cursor = 0) {
        const d = s.draft;
        openMenu('INDIVIDUAL DIR.', [`NAME: ${d.name ?? '-----------'}`, `MMSI: ${d.mmsi ?? '---------'}`, 'SAVE'], openDscSetup, 'dir');
        s.cursor = cursor;
    }

    function chooseDirRow() {
        const d = s.draft;
        if (s.cursor === 0) {
            // Namenseingabe gibt es in der Übung nicht: Der Name kommt aus der MMSI.
            event('menu_unavailable', { item: 'NAME' });
        } else if (s.cursor === 1) {
            startEditor('mmsi', () => openDir(1), 'dir');
        } else if (d.mmsi) {
            const name = d.name ?? `ID ${d.mmsi}`;
            if (! s.book.some((a) => a.mmsi === d.mmsi)) s.book.push({ name, mmsi: d.mmsi });
            event('address_added', { mmsi: d.mmsi, name, group: false });
            s.draft = null;
            openDscSetup();
        } else {
            event('dsc_incomplete', { field: 'mmsi' });
        }
    }

    // --- Anrufe aufbauen ------------------------------------------------------------

    function isCoast(address) {
        return Boolean(address?.mmsi?.startsWith('00'));
    }

    function startCall(kind) {
        const category = kind === 'individual' || kind === 'group' ? 'Routine' : kind === 'position_report' || kind === 'position_request' || kind === 'test' ? 'Safety' : null;
        s.call = { kind, address: null, category, channel: null };
        const title = TITLES[kind];
        openList(title, ['HISTORY', 'MEMORY', 'NEW ID'], (label) => {
            const list = kind === 'group' ? s.groups : label === 'HISTORY' ? s.history : s.book;
            if (label === 'NEW ID') {
                startEditor(kind === 'group' ? 'group' : 'mmsi', () => startCall(kind), 'address');

                return;
            }
            openList(label, list.length ? list.map((a) => a.name ?? a.mmsi) : ['NO DATA'], (_name, i) => {
                if (! list[i]) return;
                addressChosen(list[i]);
            }, () => startCall(kind));
        }, openDscCall);
    }

    function addressChosen(address) {
        s.call.address = address;
        if (s.call.kind === 'individual' || s.call.kind === 'group') {
            // Den Arbeitskanal einer Küstenfunkstelle weist diese zu.
            if (s.call.kind === 'individual' && isCoast(address)) {
                s.menu = null;
                setScreen('confirm');

                return;
            }
            openChannels(() => startCall(s.call.kind));

            return;
        }
        s.menu = null;
        setScreen('confirm');
    }

    function channelList() {
        if (s.call?.manual) return stepList();

        return s.call?.kind === 'all_ships' ? ALL_SHIPS_CH : INTERSHIP;
    }

    function stepList() {
        const list = [];
        let ch = 1;
        for (let i = 0; i < 80; i++) {
            const info = channelInfo('INT', ch);
            if (info && ! info.receiveOnly && ! info.duplex && ! list.includes(ch)) list.push(ch);
            ch = stepChannel('INT', ch, 1);
            if (ch === 1) break;
        }

        return list;
    }

    function openChannels(backFn) {
        s.menu = null;
        s.call.manual = false;
        s.call.chFor = 'call';
        s.call.back = backFn;
        s.cursor = 0;
        setScreen('chList');
    }

    function chooseChannel() {
        const ch = channelList()[s.cursor];
        if (s.call.chFor === 'ack') {
            s.call.channel = ch;
            setScreen('ackConfirm');

            return;
        }
        s.call.channel = ch;
        setScreen('confirm');
    }

    function yes() {
        if (s.screen === 'confirm' || s.screen === 'ackConfirm') {
            transmit(s.call);
        } else if (s.screen === 'cancelConfirm') {
            sendDistressCancel();
        }
    }

    function no() {
        if (s.screen === 'cancelConfirm') {
            setScreen('distressWait');

            return;
        }
        if (s.screen === 'ackConfirm') {
            setScreen('rcvdRespond');

            return;
        }
        s.call = null;
        backToNormal();
    }

    // Wie Softkey OK bzw. QUIT auf einem Abschlussbildschirm.
    function leaveDone() {
        if (s.screen === 'distressAcked' || s.screen === 'cancelDone') s.distress = null;
        quit();
    }

    function quit() {
        if (['rcvdAction', 'rcvdRespond'].includes(s.screen)) {
            // Anruf verworfen: Kanal bleibt, die Meldung steht im Log.
            s.unread++;
            s.incoming = null;
        }
        if (s.screen === 'distressHold') {
            s.distressHold = null;
        }
        s.call = null;
        backToNormal();
    }

    function transmit(call, repeat = false) {
        event('dsc_sent', { kind: call.kind, address: call.address ?? null, category: call.category ?? null, channel: call.channel ?? null, action: call.action ?? null, ...(repeat ? { repeat: true } : {}) });
        s.sent.unshift(call.kind);
        if (call.address && call.kind !== 'group' && call.kind !== 'individual_ack' && ! s.history.some((a) => a.mmsi === call.address.mmsi)) s.history.unshift(call.address);
        s.call = { ...call, sentAt: clock(), waiting: ['individual', 'test', 'position_request'].includes(call.kind) };
        s.menu = null;
        setScreen('callTx');
    }

    function afterTransmit() {
        const c = s.call;
        if (c.waiting) {
            setScreen('callWait');

            return;
        }
        if (['group', 'all_ships'].includes(c.kind) && c.channel) setChannel(c.channel);
        if (c.kind === 'individual_ack' && c.action === 'able' && c.channel) setChannel(c.channel);
        setScreen('callDone');
    }

    // --- Eingabe (Zeichentabelle) ----------------------------------------------------

    function startEditor(kind, backFn, then) {
        const t = EDITORS[kind];
        let chars = t.template.split('').map((c) => (c === '#' ? '-' : c));
        // Position und Zeit: vorhandene Werte vorbelegen.
        if (kind === 'postm' && s.position) {
            const [lat, lon] = formatPosition(s.position);
            chars = `${lat}${lon}${s.utc ?? '--:--'}`.split('');
        }
        const editable = t.template.split('').map((c, i) => (c === '#' || (kind === 'postm' && 'NE'.includes(c)) ? i : -1)).filter((i) => i >= 0);
        if (kind === 'group') chars[0] = '0';
        s.editor = { kind, title: t.title, row: [...t.row, '←', '→'], chars, editable, pos: 0, sel: 0, back: backFn, then };
        s.menu = null;
        setScreen('editor');
    }

    function editorMove(direction) {
        const e = s.editor;
        e.pos = Math.max(0, Math.min(e.editable.length - 1, e.pos + direction));
    }

    // SELECT übernimmt das markierte Zeichen an der Cursorstelle und rückt vor.
    function editorEnter() {
        const e = s.editor;
        const c = e.row[e.sel];
        if (c === '←' || c === '→') {
            editorMove(c === '←' ? -1 : 1);

            return;
        }
        const i = e.editable[e.pos];
        const letterSlot = e.kind === 'postm' && (i === 10 || i === 22);
        if (letterSlot !== /[NSEW]/.test(c) || (i === 10 && ! /[NS]/.test(c)) || (i === 22 && ! /[EW]/.test(c))) {
            event('input_invalid', { char: c });

            return;
        }
        e.chars[i] = c;
        if (e.pos < e.editable.length - 1) e.pos++;
    }

    function editorFinish() {
        const e = s.editor;
        if (! e) return;
        const text = e.chars.join('');
        if (e.kind === 'postm') {
            const m = text.match(/^(\d\d)°(\d\d\.\d{4})([NS])(\d\d\d)°(\d\d\.\d{4})([EW])(\d\d):(\d\d)$/);
            const bad = ! m || Number(m[1]) > 90 || Number(m[2]) >= 60 || Number(m[4]) > 180 || Number(m[5]) >= 60 || Number(m[7]) > 23 || Number(m[8]) > 59;
            if (bad) {
                event('input_invalid', { field: 'position' });

                return;
            }
            s.position = [Number(m[1]), Number(m[2]), m[3], Number(m[4]), Number(m[5]), m[6]];
            s.utc = `${m[7]}:${m[8]}`;
            s.positionSetAt = clock();
            event('position_set', { position: s.position });
            event('utc_set', { utc: s.utc });
            const fn = e.back;
            s.editor = null;
            fn();

            return;
        }
        if (! /^\d{9}$/.test(text)) {
            event('input_invalid', { field: 'mmsi' });

            return;
        }
        if (e.kind === 'mmsi' && text.startsWith('0') && ! text.startsWith('00')) {
            event('address_invalid', { mmsi: text, group: true });

            return;
        }
        const address = { name: directory.find((d) => d.mmsi === text)?.name ?? null, mmsi: text };
        s.editor = null;
        if (e.then === 'dir') {
            s.draft.mmsi = text;
            s.draft.name = address.name;
            openDir(2);

            return;
        }
        addressChosen(address);
    }

    // --- Notalarm --------------------------------------------------------------------

    function sendDistress(repeat = false) {
        const nature = NATURES[s.nature];
        s.distress = { nature, sentAt: clock(), nextAt: clock() + DISTRESS_REPEAT, waiting: true, paused: false, count: (s.distress?.count ?? 0) + 1 };
        setScreen('distressSent');
        event('dsc_sent', { kind: 'distress', nature, label: NATURE_LABELS_HX[s.nature], position: s.position, utc: s.utc, repeat });
    }

    function pauseDistress(pause) {
        if (! s.distress) return;
        if (pause) {
            s.distress.left = Math.max(0, s.distress.nextAt - clock());
            s.distress.paused = true;
        } else {
            s.distress.nextAt = clock() + (s.distress.left ?? DISTRESS_REPEAT);
            s.distress.paused = false;
        }
        event('distress_paused', { paused: pause });
    }

    function sendDistressCancel() {
        if (! s.distress) return;
        s.distress.waiting = false;
        event('dsc_sent', { kind: 'distress_cancel', nature: s.distress.nature });
        event('distress_repeat_cancelled', { nature: s.distress.nature, dscCancel: true });
        setChannel(16, { work: false });
        setScreen('cancelDone');
    }

    // --- Empfang ---------------------------------------------------------------------

    // Eine beliebige Taste schaltet den Alarm aus.
    function stopAlarm() {
        const i = s.incoming;
        if (s.screen === 'ackAlarm') {
            s.incoming = null;
            event('alarm_stopped', { call: i?.type ?? 'ack' });
            if (i?.type === 'distress_ack') {
                setScreen('distressAcked');

                return;
            }
            if (i?.type === 'position_reply') {
                s.call = { ...s.call, reply: i };
                setScreen('posInfo');
                event('position_info', { position: i.position ?? null, from: i.from ?? null });

                return;
            }
            setScreen('callDone');

            return;
        }
        if (s.screen === 'rcvdAlarm') {
            i.silenced = true;
            event('alarm_stopped', { call: i.type });
            if (i.type === 'individual') {
                setScreen(s.individualReply === 'auto' ? 'callDone' : 'rcvdRespond');

                return;
            }
            setScreen('rcvdAction');
        }
    }

    function remaining(i) {
        if (i.paused) return i.left;

        return Math.max(0, AUTO_SW - (clock() - i.countdownFrom));
    }

    function pauseCountdown(pause) {
        const i = s.incoming;
        if (! i || ! AUTO_SWITCH.has(i.type)) return;
        if (pause) {
            i.left = remaining(i);
            i.paused = true;
        } else {
            i.countdownFrom = clock() - (AUTO_SW - i.left);
            i.paused = false;
        }
    }

    function acceptIncoming(auto = false) {
        const i = s.incoming;
        if (! i || ! AUTO_SWITCH.has(i.type)) return;
        const channel = DISTRESS_TYPES.has(i.type) ? 16 : i.channel ?? 16;
        s.incoming = null;
        s.menu = null;
        s.editor = null;
        setChannel(channel, { work: channel !== 16 });
        s.call = { received: i.type, from: i, at: clock() };
        setScreen('rcvdAccepted');
        event('call_accepted', { call: i.type, channel, ...(auto ? { auto: true } : {}) });
    }

    // Einzelanruf manuell beantworten: ABLE/UNABLE, dann YES (CHG CH: anderer Kanal).
    function respond(action) {
        const i = s.incoming;
        if (! i) return;
        s.call = { kind: 'individual_ack', address: i.from ?? null, category: i.category ?? 'Routine', channel: action === 'able' ? i.channel ?? null : null, action, incoming: i };
        setScreen('ackConfirm');
    }

    // --- Suchlauf --------------------------------------------------------------------

    function startScan() {
        if (! s.tags.size) {
            event('scan_blocked', { reason: 'no_tag' });

            return;
        }
        s.watch = 'off';
        const memory = [...s.tags].sort((a, b) => a - b);
        // P-SCAN: nach jedem Kanal der Vorrangkanal 16.
        const list = s.scanType === 'priority' ? memory.flatMap((ch) => (ch === PRIORITY_CHANNEL ? [ch] : [ch, PRIORITY_CHANNEL])) : memory;
        s.scan = { list, index: -1, last: clock(), type: s.scanType };
        event('scan', { running: true, channels: [...new Set(list)] });
    }

    function stopScan() {
        if (! s.scan) return;
        s.scan = null;
        event('scan', { running: false, channel: s.channel });
    }

    // --- Tasten ----------------------------------------------------------------------

    const LIST_SCREENS = ['menu', 'chList'];

    function listLength() {
        if (s.screen === 'menu') return s.menu.items.length;

        return channelList().length;
    }

    function moveCursor(steps) {
        s.cursor = Math.max(0, Math.min(listLength() - 1, s.cursor + steps));
    }

    function windowOpen() {
        return Boolean(s.window && clock() < s.windowUntil);
    }

    function shortPress(key) {
        if (! s.power) return;
        // Die Notklappe ist mechanisch: Sie öffnet auch bei Tastensperre.
        if (key === 'distressCover') {
            s.cover = ! s.cover;
            event('cover', { open: s.cover });

            return;
        }
        if (s.locked) {
            event('key_locked', { key });

            return;
        }
        if (ALARM_SCREENS.includes(s.screen)) {
            stopAlarm();

            return;
        }
        if (key.startsWith('soft')) {
            softkey(Number(key.slice(4)) - 1);

            return;
        }
        if (key === 'volUp' || key === 'volDown') {
            s.vol = Math.max(0, Math.min(10, s.vol + (key === 'volUp' ? 1 : -1)));
            s.window = 'vol';
            s.windowUntil = clock() + WINDOW_MS;
            event('volume', { level: s.vol });

            return;
        }
        if (key === 'sql') {
            if (s.monitor) {
                s.monitor = false;
                event('monitor', { on: false });
                event('squelch', { level: s.sql, state: squelchState() });

                return;
            }
            s.window = 'sql';
            s.windowUntil = clock() + WINDOW_MS;
            event('knob_mode', { mode: 'sql' });

            return;
        }
        // Im SQL-Fenster stellen CH▲/CH▼ die Rauschsperre ein.
        if (windowOpen() && s.window === 'sql' && (key === 'up' || key === 'down')) {
            s.windowUntil = clock() + WINDOW_MS;
            s.sql = Math.max(0, Math.min(10, s.sql + (key === 'up' ? 1 : -1)));
            event('squelch', { level: s.sql, state: squelchState() });

            return;
        }
        s.window = null;
        // Abschlussbildschirme (gesendet, angenommen, aufgehoben, bestätigt)
        // verlassen auch CLR und MENU, nicht nur der Softkey QUIT.
        const done = DONE_SCREENS.includes(s.screen);
        if (key === 'menu') {
            if (['menu', 'editor', 'chList', 'confirm', 'mmsiInfo', 'distAlert', 'log'].includes(s.screen)) backToNormal();
            else if (done) {
                leaveDone();
                openMain();
            } else if (s.screen === 'normal' || s.screen === 'distressWait') openMain();

            return;
        }
        if (key === 'clr') {
            if (s.scan) {
                stopScan();

                return;
            }
            // CLR bricht Menüs und Eingaben ab und kehrt zum Funkbetrieb zurück.
            if (['menu', 'editor', 'chList', 'confirm', 'mmsiInfo', 'distAlert', 'log', 'posInfo'].includes(s.screen)) {
                s.call = null;
                backToNormal();
            } else if (done) {
                leaveDone();
            }

            return;
        }

        if (LIST_SCREENS.includes(s.screen)) {
            if (key === 'up' || key === 'left') moveCursor(-1);
            if (key === 'down' || key === 'right') moveCursor(1);

            return;
        }
        if (s.screen === 'editor') {
            const e = s.editor;
            const d = key === 'up' || key === 'right' ? 1 : key === 'down' || key === 'left' ? -1 : 0;
            if (d) e.sel = (e.sel + d + e.row.length) % e.row.length;

            return;
        }

        // Binnenfunk (ATIS): kein Kanal 16.
        if (s.group === 'ATIS' && key === 'ch16') {
            event('ch16_unavailable', { group: s.group, key });

            return;
        }

        switch (key) {
            case 'ch16':
                if (s.special) {
                    // Erneut drücken: zurück zum Arbeitskanal.
                    s.special = null;
                    setChannel(s.workChannel);
                } else {
                    if (s.channel !== 16) s.workChannel = s.channel;
                    setChannel(16, { work: false });
                    s.special = '16';
                }
                break;
            case 'up':
            case 'down':
                if (s.scan) {
                    stopScan();
                    break;
                }
                if (s.screen !== 'normal' && s.screen !== 'distressWait') break;
                s.special = null;
                setChannel(stepChannel(s.group, s.channel, key === 'up' ? 1 : -1));
                break;
            case 'left':
            case 'right': {
                if (s.screen !== 'normal') break;
                const page = (s.page + (key === 'right' ? 1 : -1) + PAGES_HX.length) % PAGES_HX.length;
                s.page = page;
                event('softkeys', { labels: softkeys(), page });
                break;
            }
            default:
                break;
        }
    }

    function powerOff() {
        Object.assign(s, { power: false, tx: false, menu: null, editor: null, call: null, incoming: null, cover: false, scan: null, watch: 'off', window: null, distress: null, monitor: false, locked: false, special: null, draft: null });
        Object.assign(s, { position: null, utc: null, positionSetAt: null });
        setScreen('off');
        event('power', { on: false });
    }

    function longPress(key) {
        if (key === 'power') {
            if (s.power) {
                powerOff();
            } else {
                s.power = true;
                s.page = 0;
                setScreen('normal');
                event('power', { on: true });
            }

            return;
        }
        if (! s.power) return;
        if (key === 'lock') {
            s.locked = ! s.locked;
            event('key_lock', { on: s.locked });

            return;
        }
        if (s.locked) return;
        if (key === 'menu' && s.screen === 'normal') {
            openSetup();

            return;
        }
        if (key === 'ch16' && s.group === 'ATIS') {
            event('ch16_unavailable', { group: s.group, key });

            return;
        }
        if (key === 'ch16' && (s.screen === 'normal' || s.screen === 'distressWait')) {
            if (! s.special && s.channel !== 16) s.workChannel = s.channel;
            setChannel(SUB_CHANNEL, { work: false });
            s.special = 'sub';
            event('call_channel', { channel: SUB_CHANNEL });
        }
    }

    // --- Öffentliche Schnittstelle -------------------------------------------------------

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
                    setScreen(s.distressHold?.from ?? 'normal');
                    event('distress_released_early', { heldMs: clock() - p.t0 });
                }
                s.distressHold = null;

                return;
            }
            if (key === 'sql' && p.long) return;
            if (p.long) return;
            shortPress(key);
        },
        turn() {},
        pttDown() {
            if (! s.power) return false;
            if (current()?.receiveOnly) {
                event('tx_blocked', { channel: s.channel });

                return false;
            }
            if (['rcvdAlarm', 'rcvdAction'].includes(s.screen)) {
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
                if (key === 'sql' && now - p.t0 >= SQL_MONITOR && s.power && ! s.locked) {
                    p.long = true;
                    s.monitor = true;
                    event('monitor', { on: true });
                    event('squelch', { level: s.sql, state: squelchState() });
                } else if (now - p.t0 >= LONG && ['power', 'lock', 'menu', 'ch16'].includes(key)) {
                    p.long = true;
                    longPress(key);
                }
            }
            if (s.screen === 'distressHold' && s.distressHold && now - s.distressHold.t0 >= DISTRESS_HOLD) {
                s.distressHold = null;
                sendDistress(Boolean(s.distress?.waiting));
            }
            if (s.screen === 'distressSent' && now - s.screenSince >= TRANSMIT_MS) {
                if (s.channel !== 16) setChannel(16, { work: false });
                setScreen('distressWait');
            }
            if (s.screen === 'callTx' && now - s.screenSince >= TRANSMIT_MS) afterTransmit();
            if (s.incoming && AUTO_SWITCH.has(s.incoming.type) && ! s.incoming.paused && ['rcvdAlarm', 'rcvdAction'].includes(s.screen) && remaining(s.incoming) <= 0) {
                if (s.screen === 'rcvdAlarm') event('alarm_stopped', { call: s.incoming.type, auto: true });
                acceptIncoming(true);
            }
            if (s.scan && now - s.scan.last >= SCAN_STEP) {
                s.scan.last = now;
                s.scan.index = (s.scan.index + 1) % s.scan.list.length;
                s.channel = s.scan.list[s.scan.index];
            }
            if (s.power && s.distress?.waiting && ! s.distress.paused && now >= s.distress.nextAt) {
                sendDistress(true);
            }
            if (s.window && now >= s.windowUntil) s.window = null;
            if (! s.power) pending = [];
            if (pending.length && ! BUSY.includes(s.screen)) this.receive(pending.shift(), true);
        },
        receive(call, queued = false) {
            // Aus oder im Binnenfunk (ATIS, DSC gesperrt): kein Empfang.
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
                s.distress.waiting = false;
                s.incoming = call;
                setChannel(16, { work: false });
                setScreen('ackAlarm');

                return true;
            }
            const c = s.call;
            const answers = { individual_ack: 'individual', test_ack: 'test', position_reply: 'position_request' };
            if (answers[call.type] && c?.kind === answers[call.type] && c.waiting) {
                c.waiting = false;
                s.incoming = call;
                if (call.type === 'individual_ack') {
                    if (call.action === 'unable') {
                        event('call_failed', { kind: 'individual' });
                        c.unable = true;
                    } else {
                        // Die Bestätigung stellt den Arbeitskanal sofort ein.
                        const channel = call.channel ?? c.channel;
                        if (channel) setChannel(channel);
                    }
                }
                setScreen('ackAlarm');

                return true;
            }

            // Positionsabfrage, Polling und Testanruf beantwortet das Gerät selbst.
            if (call.type === 'position_request' || call.type === 'polling_request' || call.type === 'test_call') {
                const kind = { position_request: 'position_reply', polling_request: 'polling_reply', test_call: 'test_ack' }[call.type];
                event('dsc_sent', { kind, address: call.from, auto: true, position: s.position });

                return true;
            }

            s.menu = null;
            s.editor = null;
            s.incoming = { ...call, silenced: false, countdownFrom: clock(), paused: false };
            if (call.type === 'individual' && s.individualReply === 'auto') {
                event('dsc_sent', { kind: 'individual_ack', address: call.from ?? null, category: call.category ?? 'Routine', channel: call.channel ?? null, action: 'able', auto: true });
                if (call.channel) setChannel(call.channel);
                s.call = { kind: 'individual_ack', address: call.from, channel: call.channel, action: 'able', sentAt: clock() };
            }
            setScreen('rcvdAlarm');

            return true;
        },
        hear(voice) {
            const result = (heard, reason = null) => {
                event('voice', { heard, reason, from: voice.from ?? null, channel: voice.channel, text: voice.text });

                return { heard, reason };
            };
            if (! s.power) return result(false, 'off');
            if (voice.group && (voice.group === 'ATIS') !== (s.group === 'ATIS')) return result(false, 'group');
            let listening = s.channel === voice.channel;
            if (! listening && s.watch !== 'off' && (voice.channel === PRIORITY_CHANNEL || (s.watch === 'tri' && voice.channel === SUB_CHANNEL))) listening = true;
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
            for (const key of ['group', 'channel', 'position', 'utc', 'watchMode', 'vol', 'sql']) {
                if (key in start) s[key] = start[key];
            }
            if (! inland && s.group === 'ATIS') Object.assign(s, { group: 'INT', channel: 16 });
            if ('highPower' in start) s.txPower = start.highPower ? 'HI' : 'LO';
            if ('channel' in start) s.workChannel = start.channel;
            if (start.position) s.positionSetAt = clock();
            if (start.tags) s.tags = new Set(start.tags);
            if ('nature' in start) s.nature = Math.max(0, NATURES.indexOf(start.nature));
            if (start.distressWaiting) {
                s.distress = { nature: start.distressWaiting, sentAt: clock(), nextAt: clock() + DISTRESS_REPEAT, waiting: true, paused: false, count: 1 };
                setScreen('distressWait');
            }
        },
        display() {
            return renderDisplay();
        },
    };

    // --- Display ---------------------------------------------------------------------

    function renderDisplay() {
        if (! s.power) return { on: false, softkeys: ['', '', ''] };
        const ch = current();
        const stale = positionStale() ? '??' : '';
        const pos = formatPosition(s.position)?.map((l) => stale + l) ?? ['NO POSITION', ''];
        const time = s.utc ? `${stale}${s.utc} UTC` : 'NO TIME';
        const base = {
            on: true,
            status: {
                busy: squelchState() === 'noise' || Boolean(s.incoming && ! s.incoming.silenced),
                tx: s.tx,
                power: powerLabel(),
                group: GROUP_LABELS[s.group] ?? s.group,
                atis: s.group === 'ATIS',
                dup: Boolean(ch?.duplex),
                tag: s.tags.has(s.channel),
                mail: s.unread > 0,
                monitor: s.monitor,
                scan: s.scan ? (s.scan.type === 'priority' ? 'PRI-SCAN' : 'MEM-SCAN') : null,
            },
            mmsi: own?.mmsi ?? null,
            channel: pad(s.channel),
            priority: s.channel === PRIORITY_CHANNEL && s.group !== 'ATIS',
            sub: s.special === 'sub',
            channelName: ch?.name ?? '',
            watch: s.watch === 'dual' ? 'DW' : s.watch === 'tri' ? 'TW' : null,
            lines: [`LAT ${pos[0]}`, `LON ${pos[1]}`, `TIME ${time}`],
            softkeys: softkeys(),
            pages: s.screen === 'normal' ? { current: s.page, count: PAGES_HX.length, labels: PAGES_HX.map((_, i) => pageLabels(i)) } : null,
            locked: s.locked,
            panel: null,
            window: null,
            alarm: false,
        };
        if (s.screen === 'normal' && windowOpen()) {
            base.window = s.window === 'sql' ? { label: 'SQL', value: String(s.sql), level: s.sql / 10 } : { label: 'VOL', value: String(s.vol), level: s.vol / 10 };
        }
        const panel = (title, lines = [], extra = {}) => ({ ...base, panel: { title, lines, ...extra } });
        const c = s.call;
        const elapsed = (t0) => {
            const sec = Math.max(0, Math.floor((clock() - t0) / 1000));
            return `SINCE: ${pad(Math.floor(sec / 60))}:${pad(sec % 60)}`;
        };
        const callLines = (call) => [nameOf(call?.address), call?.category ? `CATEG: ${call.category.toUpperCase()}` : '', call?.channel ? `CH: ${pad(call.channel)}` : ''].filter(Boolean);

        switch (s.screen) {
            case 'menu':
                return panel(s.menu.title, [], { items: s.menu.items, cursor: s.cursor });
            case 'editor': {
                const e = s.editor;
                const lines = e.kind === 'postm' ? [['Lat ', 0, 11], ['Lon ', 11, 23], ['UTC ', 23, 28]] : [['', 0, e.chars.length]];
                return panel(e.title, [], { editor: { lines, chars: e.chars, cursor: e.editable[e.pos], row: e.row, sel: e.sel } });
            }
            case 'mmsiInfo':
                return panel('MMSI/POS INFO', [`MMSI: ${own?.mmsi ?? '---------'}`, 'POS INFO', `POS: ${pos[0]}`, `     ${pos[1]}`, `TIME: ${time}`]);
            case 'distAlert':
                return panel('DIST ALERT MSG', [`NATURE OF: ${NATURE_LABELS_HX[s.nature]}`, `POS: ${pos[0]}`, `     ${pos[1]}`, `TIME: ${time}`, '[DISTRESS]', 'Hold for 3sec.']);
            case 'chList': {
                const list = channelList();
                return panel(c?.chFor === 'ack' ? 'CHG CH' : c?.kind === 'all_ships' ? 'ALL SHIPS CALL' : TITLES[c?.kind] ?? 'CHANNEL', [], { items: list.map((n) => `CH:${pad(n)}`), cursor: Math.min(s.cursor, list.length - 1), note: s.call?.manual ? 'ALL CH' : 'INTERSHIP CH' });
            }
            case 'confirm':
                return panel(TITLES[c.kind], [...callLines(c), '', 'Do you want to', 'transmit a Call?']);
            case 'callTx':
                return panel(TITLES[c.kind], [...callLines(c), '', 'Transmitting']);
            case 'callWait':
                return panel(TITLES[c.kind], [...callLines(c), elapsed(c.sentAt), '', 'Waiting for ACK'], { blink: true });
            case 'callDone':
                if (c?.unable) return panel(TITLES[c.kind], [...callLines(c), '', 'Unable to comply']);
                if (c?.kind === 'individual_ack') return panel(RECEIVED.individual, [...callLines(c), '', c.action === 'unable' ? 'Unable to comply' : 'Connected']);
                return panel(TITLES[c?.kind] ?? 'DSC CALL', [...callLines(c), c?.sentAt ? elapsed(c.sentAt) : '', '', ['individual', 'test'].includes(c?.kind) ? 'ACK received' : 'Transmitted'].filter((l, i, a) => l || a[i + 1]));
            case 'ackAlarm': {
                const i = s.incoming;
                return { ...panel(RECEIVED[i?.type] ?? 'RX ACK', [nameOf(i?.from), i?.channel ? `CH: ${pad(i.channel)}` : i?.type === 'distress_ack' ? 'CH: 16' : '', i?.action === 'unable' ? 'Unable to comply' : '']), alarm: true };
            }
            case 'rcvdAlarm':
            case 'rcvdAction':
            case 'rcvdRespond': {
                const i = s.incoming;
                const channel = DISTRESS_TYPES.has(i.type) ? 16 : i.channel;
                const lines = [nameOf(i.from)];
                if (DISTRESS_TYPES.has(i.type)) lines.push(`NATURE: ${(i.nature ?? 'Undesignated').toUpperCase()}`);
                else if (i.category) lines.push(`CATEG: ${i.category.toUpperCase()}`);
                if (channel) lines.push(`CH: ${pad(channel)}`);
                lines.push(elapsed(i.at ?? s.received[0]?.at ?? clock()));
                if (AUTO_SWITCH.has(i.type)) lines.push(i.paused ? 'PAUSED' : `CH ${pad(channel ?? 16)} after ${Math.ceil(remaining(i) / 1000)} sec`);
                if (s.screen === 'rcvdAlarm' && i.type === 'individual' && s.individualReply === 'auto') lines.push('Connected');
                return { ...panel(RECEIVED[i.type] ?? i.type, lines), alarm: s.screen === 'rcvdAlarm' };
            }
            case 'ackConfirm':
                return panel(RECEIVED.individual, [nameOf(c.address), c.channel ? `CH: ${pad(c.channel)}` : '', '', c.action === 'unable' ? 'Unable to comply' : 'Able to comply', 'Transmit?'].filter((l, i) => l || i === 2));
            case 'rcvdAccepted':
                return panel(RECEIVED[c.received] ?? 'RX', [nameOf(c.from?.from), `CH: ${pad(s.channel)}`, elapsed(c.at)]);
            case 'posInfo': {
                const p = formatPosition(c?.reply?.position ?? null);
                return panel('POS INFO', [nameOf(c?.reply?.from), p ? `POS: ${p[0]}` : 'NO POSITION DATA', p ? `     ${p[1]}` : '']);
            }
            case 'distressHold': {
                const left = Math.max(1, Math.ceil((DISTRESS_HOLD - (clock() - (s.distressHold?.t0 ?? clock()))) / 1000));
                return panel('!!DISTRESS!!', [NATURE_LABELS_HX[s.nature], `POS: ${pos[0]}`, `     ${pos[1]}`, `TIME: ${time}`, `Time for ${left}sec.`], { progress: radio.holdProgress() });
            }
            case 'distressSent':
                return { ...panel('!!DISTRESS!!', [NATURE_LABELS_HX[s.nature], `POS: ${pos[0]}`, `     ${pos[1]}`, '', 'Transmitting']), channel: '70' };
            case 'distressWait': {
                const left = Math.max(0, Math.ceil(((s.distress.paused ? s.distress.left : s.distress.nextAt - clock())) / 1000));
                return panel('!!DISTRESS!!', [(s.distress.nature ?? 'Undesignated').toUpperCase(), `POS: ${pos[0]}`, `     ${pos[1]}`, `TX IN: ${pad(Math.floor(left / 60))}:${pad(left % 60)}`, '', s.distress.paused ? 'Retransmission is now pausing' : 'Waiting for ACK'], { blink: ! s.distress.paused });
            }
            case 'cancelConfirm':
                return panel('!!DISTRESS!!', ['CANCEL', '', 'Do you want to', 'cancel a DIST?']);
            case 'cancelDone':
                return panel('!!DISTRESS!!', ['DIST CANCEL', 'Transmitted']);
            case 'distressAcked':
                return panel('!!DISTRESS!!', ['RX ACKNOWLEDGED', nameOf(s.received[0]?.from), elapsed(s.received[0]?.at ?? clock())]);
            default:
                return base;
        }
    }

    return radio;
}
