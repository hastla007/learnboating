// Gerätelogik Icom IC-M330 (Stufe 5c der Gerätesimulation), nach dem
// Geräteprofil aus der englischen Bedienungsanleitung (deutsche Version des
// Geräts: Kanalgruppen INT, DSC und ATIS). Bedienelemente: DISTRESS 3 s
// unter der Klappe, Tastenkreuz ▲▼◄► mit ENT, MENU, CLR, Drehknopf
// [PWR/VOL/SQL] (1 s = Ein/Aus, 1× drücken Lautstärke, 2× Rauschsperre,
// drehen = Lautstärke), 16/C (kurz 16, 1 s Anrufkanal), vier Softkeys mit
// Seiten (kein Umlauf), die ◄/► umschalten. DSC über den Bildschirm
// "OTHER DSC" (Formular Type/Address/Category/Mode/Channel) und den
// Bildschirm "DISTRESS" (Nature, Position).
//
// Gleiche Schnittstelle und gleiche Ereignisnamen wie die übrigen Geräte.
// Annahmen, wo die Anleitung schweigt: DE-Softkey-Seiten (ohne LO/DX und
// WX), beide Tasten unter einem breiten Softkey wirken, nach dem Senden des
// Notalarms hört das Gerät auf Kanal 16, Einzel- und Gruppenanruf sind
// Routine, bei einer Küstenfunkstelle (MMSI 00…) entfällt die Kanalwahl,
// Individual ACK steht auf Manual, Position-/Polling-/Test-ACK auf Auto,
// CH Auto SW auf Accept (10 s), ATIS sperrt die DSC-Funktionen.
//
// Variante "ic-m94de": Handfunkgerät Icom IC-M94DE (deutsche Version) mit
// derselben DSC-Bedienung (Compose Distress, Compose Other, Empfang), aber
// drei Softkeys auf sechs Seiten (DTRS/OTHER DSC/UNREAD …), Ein/Aus-Taste
// (1 s), VOL/SQL-Taste (1× Lautstärke, 2× Rauschsperre, Einstellen mit
// ▲▼◄►, 1 s Monitor), Tastensperre (CLR 1 s), Menü mit „Settings“
// (Radio, DSC) und Sendeleistung HI/LO (6 W/1 W). Keine Hörertasten.

import { CHANNEL_GROUPS, channelInfo, stepChannel, stepIntership } from '../channels.js';
import { NATURES } from './icm505.js';

export const NATURE_LABELS_M330 = ['Undesignated', 'Fire/Explosion', 'Flooding', 'Collision', 'Grounding', 'Capsizing', 'Sinking', 'Adrift', 'Abandoning', 'Piracy', 'MOB'];

export const DEVICE_M330 = {
    key: 'ic-m330',
    name: 'Icom IC-M330',
    keys: ['distressCover', 'distress', 'up', 'down', 'left', 'right', 'enter', 'menu', 'clr', 'ch16', 'knob', 'soft1', 'soft2', 'soft3', 'soft4', 'micUp', 'micDown', 'micHiLo', 'mic16'],
    knobs: ['dial'],
};

export const DEVICE_M94DE = {
    key: 'ic-m94de',
    name: 'Icom IC-M94DE',
    keys: ['distressCover', 'distress', 'up', 'down', 'left', 'right', 'enter', 'menu', 'clr', 'ch16', 'power', 'volsql', 'soft1', 'soft2', 'soft3'],
    knobs: [],
};

// Softkey-Seiten des IC-M94DE nach der Anleitung (S. 6).
export const PAGES_M94DE = [
    ['DTRS', 'OTHER DSC', 'UNREAD'],
    ['SCAN', 'DW', 'CH'],
    ['HI/LO', 'AIS', 'WPT'],
    ['NAV', 'MOB', 'AQUA'],
    ['★', 'NAME', 'BKLT'],
    ['LOG', '', ''],
];
// Beschriftung am IC-M94DE ↔ Funktion in der gemeinsamen Logik.
const M94_IN = { DTRS: 'DISTRESS', CH: 'CHAN', UNREAD: 'LOG', IGNORE: 'IGN', ACCEPT: 'ACPT' };
const M94_OUT = { IGN: 'IGNORE', ACPT: 'ACCEPT', DISTRESS: 'DTRS', CHAN: 'CH' };
// Menü des IC-M94DE (S. 64) und die Namen in der gemeinsamen Logik.
const MENU_M94 = {
    main: ['Compose Distress', 'Compose Other', 'Unread List', 'AIS', 'Navigation', 'GPS', 'AquaQuake', 'DSC Log', 'Settings', 'Radio Info'],
    settings: ['Configuration', 'Radio', 'DSC', 'AIS', 'Navigation'],
    radio: ['Dual/Tri-Watch', 'Channel Group', 'Call Channel', 'Monitor', 'Favorite CH', 'RT Indicator', 'Channel Display', 'Scan Type', 'Scan Timer'],
    dsc: ['Position Input', 'Individual ID', 'Group ID', 'Auto ACK', 'CH Auto Switch', 'DSC Switch', 'MOB Auto Set', 'Alarm Status', 'CH 70 SQL Level', 'DST at PW OFF', 'Loop Test'],
    log: ['Received', 'Transmitted'],
};
const MENU_M94_ALIAS = {
    'Compose Distress': 'Distress', 'Compose Other': 'Other DSC', Radio: 'Radio Settings', DSC: 'DSC Settings', 'Dual/Tri-Watch': 'Dual/Tri-watch',
    Received: 'Received Call Log', Transmitted: 'Transmitted Call Log',
};

// Softkey-Seiten der Werksbelegung (deutsche Version, angenommen).
export const PAGES_M330 = [
    ['DISTRESS', 'DISTRESS', 'OTHER DSC', 'OTHER DSC'],
    ['SCAN', 'DW', 'CHAN', 'HI/LO'],
    ['AQUA', '★', 'NAME', 'BKLT'],
    ['LOG', '', '', ''],
];

const LONG = 1000;
// Zustände, in denen das Gerät nicht empfängt (eigene Aussendung, DISTRESS halten).
const BUSY = ['callTx', 'distressSent', 'distressHold'];
const DISTRESS_HOLD = 3000;
const DISTRESS_REPEAT = 4 * 60 * 1000;
const TRANSMIT_MS = 1000;
const AUTO_SW = 10000;
const WINDOW_MS = 5000;
const SCAN_STEP = 500;
const POSITION_STALE = 4 * 60 * 60 * 1000;

const MAIN_MENU = ['Distress', 'Other DSC', 'GPS', 'Configuration', 'Radio Settings', 'DSC Settings', 'DSC Log', 'Radio Info'];
const RADIO_SETTINGS = ['Scan Type', 'Scan Timer', 'Dual/Tri-watch', 'Channel Group', 'Call Channel', 'FAV Settings', 'FAV on MIC', 'CH Display', 'CH Close-up'];
const DSC_SETTINGS = ['Position Input', 'Individual ID', 'Group ID', 'Auto ACK', 'CH Auto SW', 'Data Output', 'Alarm Status', 'CH 70 SQL Level', 'Self-Test'];
const DSC_LOG = ['Received Call Log', 'Transmitted Call Log'];
const GROUPS = ['INT', 'DSC', 'ATIS'];
const AVAILABLE = new Set([
    'Distress', 'Other DSC', 'GPS', 'Radio Settings', 'DSC Settings', 'DSC Log', 'Radio Info', 'Dual/Tri-watch', 'Channel Group',
    'Position Input', 'Individual ID', 'Group ID', 'Received Call Log', 'Transmitted Call Log',
]);
const TYPE_LABELS = { individual: 'Individual', all_ships: 'All Ships', group: 'Group', test: 'Test', individual_ack: 'Individual ACK', test_ack: 'Test ACK' };
const MESSAGE_TYPES = ['individual', 'all_ships', 'group', 'test'];
const TITLES = {
    individual: 'INDIVIDUAL CALL', group: 'GROUP CALL', all_ships: 'ALL SHIPS CALL', individual_ack: 'INDIVIDUAL CALL', test: 'TEST CALL',
    test_ack: 'TEST CALL',
};
const TX_TEXT = { individual: 'Individual Call', group: 'Group Call', all_ships: 'All Ships Call', individual_ack: 'Individual ACK', test: 'Test Call', test_ack: 'Test ACK' };
const RECEIVED = {
    distress: 'RCVD DISTRESS', distress_ack: 'RCVD DTRS ACK', distress_relay: 'RCVD DTRS RELAY', individual: 'RCVD INDIVIDUAL',
    group: 'RCVD GROUP CALL', all_ships: 'RCVD ALL SHIPS', geographic: 'RCVD GEOGRAPHICAL', position_request: 'RCVD POS REQUEST',
    polling_request: 'RCVD POLLING', test_call: 'RCVD TEST CALL',
};
const DISTRESS_TYPES = new Set(['distress', 'distress_relay', 'distress_ack']);
// Empfangene Anrufe mit Kanalautomatik (CH Auto SW = Accept).
const AUTO_SWITCH = new Set(['distress', 'distress_relay', 'distress_ack', 'group', 'all_ships', 'geographic']);
const EDITORS = {
    lat: { title: 'POSITION INPUT', label: 'LAT:', template: '##°##.####N', row: '0123456789NS' },
    lon: { title: 'POSITION INPUT', label: 'LON:', template: '###°##.####E', row: '0123456789WE' },
    utc: { title: 'POSITION INPUT', label: 'UTC:', template: '##:##', row: '0123456789' },
    indId: { title: 'MANUAL INPUT', label: 'IND:', template: '#########', row: '0123456789' },
    groupId: { title: 'MANUAL INPUT', label: 'GRP:', template: '0########', row: '0123456789' },
    addIndv: { title: 'INDIVIDUAL ID', label: 'IND:', template: '#########', row: '0123456789' },
    addGroup: { title: 'GROUP ID', label: 'GRP:', template: '0########', row: '0123456789' },
};

function pad(n, width = 2) {
    return String(n).padStart(width, '0');
}

function formatPosition(pos) {
    if (! pos) return null;
    const [latD, latM, ns, lonD, lonM, ew] = pos;

    return [`${pad(latD)}°${pad(Math.floor(latM))}${ns}`, `${pad(lonD, 3)}°${pad(Math.floor(lonM))}${ew}`];
}

export function createIcM94DE(options = {}) {
    return createIcM330({ ...options, variant: 'ic-m94de' });
}

// inland: false sperrt die Kanalgruppe ATIS (Binnenfunk am Handfunkgerät,
// siehe createHx891); das Gerät meldet dann inland_blocked.
export function createIcM330({ own, clock = () => Date.now(), emit = () => {}, addressBook = [], directory = [], variant = 'ic-m330', inland = true } = {}) {
    // Handfunkgerät IC-M94DE: drei Softkeys, Tasten statt Drehknopf.
    const handheld = variant === 'ic-m94de';
    const PAGES = handheld ? PAGES_M94DE : PAGES_M330;
    const menus = handheld
        ? { main: MENU_M94.main, radio: MENU_M94.radio, dsc: MENU_M94.dsc, log: MENU_M94.log, radioTitle: 'RADIO', dscTitle: 'DSC', logTitle: 'DSC LOG' }
        : { main: MAIN_MENU, radio: RADIO_SETTINGS, dsc: DSC_SETTINGS, log: DSC_LOG, radioTitle: 'RADIO SETTINGS', dscTitle: 'DSC SETTINGS', logTitle: 'DSC LOG' };
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
        screen: 'off',
        screenSince: 0,
        cover: false,
        page: 0,
        window: null,
        windowUntil: 0,
        menu: null,
        cursor: 0,
        nature: 0,
        position: null,
        utc: null,
        positionSetAt: null,
        editor: null,
        form: null,
        call: null,
        distress: null,
        incoming: null,
        received: [],
        sent: [],
        book: addressBook.filter((a) => ! a.group).map((a) => ({ ...a })),
        groups: addressBook.filter((a) => a.group).map((a) => ({ ...a })),
        terminateFrom: null,
        onCall: false,
        // IC-M94DE: Seite der Softkeys in Bildschirmen mit mehr als drei
        // Funktionen, Tastensperre und Monitor (Rauschsperre offen).
        subpage: 0,
        locked: false,
        monitor: false,
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
            knobMode: s.window === 'sql' ? 'sql' : 'vol', page: s.page, softkeys: softkeys(),
        };
    }

    // Anrufe, die während der eigenen Aussendung eintreffen.
    let pending = [];

    function setScreen(screen) {
        const changed = s.screen !== screen;
        s.screen = screen;
        s.screenSince = clock();
        if (changed) s.subpage = 0;
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
        if (s.sql <= 0 || s.monitor) return 'noise';
        if (s.sql >= 7) return 'tight';

        return 'set';
    }

    function backToNormal() {
        s.menu = null;
        s.editor = null;
        s.form = null;
        setScreen(s.distress?.waiting ? 'distressWait' : 'normal');
    }

    function dscBlocked() {
        if (s.group !== 'ATIS') return false;
        event('dsc_blocked', { group: s.group, key: 'menu' });

        return true;
    }

    // --- Softkeys ------------------------------------------------------------

    function pageLabels(index) {
        return PAGES[index].map((label) => (label === 'DW' && s.watchMode === 'tri' ? 'TW' : label));
    }

    // IC-M94DE: Funktionen eines Bildschirms in Seiten zu drei Softkeys
    // (breite Felder und leere Plätze fallen weg), Beschriftung wie am Gerät.
    function screenPagesM94() {
        const labels = [...new Set(softkeys330().filter(Boolean))].map((label) => M94_OUT[label] ?? label);
        const pages = [];
        for (let i = 0; i < labels.length; i += 3) pages.push([...labels.slice(i, i + 3), '', ''].slice(0, 3));

        return pages.length ? pages : [['', '', '']];
    }

    function softkeys() {
        if (! handheld) return softkeys330();
        if (s.screen === 'normal' || s.screen === 'off') return s.screen === 'off' ? ['', '', ''] : pageLabels(s.page);
        const pages = screenPagesM94();

        return pages[Math.min(s.subpage, pages.length - 1)];
    }

    function softkeys330() {
        switch (s.screen) {
            case 'off':
            case 'callTx':
            case 'distressHold':
            case 'distressSent':
                return ['', '', '', ''];
            case 'menu':
                return s.menu.list === 'idList' ? ['ADD', 'EDIT', 'DEL', ''] : ['EXIT', 'BACK', '', 'ENT'];
            case 'editor':
                return ['EXIT', 'BACK', '', 'FIN'];
            case 'dscForm':
                return ['EXIT', 'BACK', '', 'CALL'];
            case 'distressMenu':
            case 'channelSelect':
                return ['EXIT', 'BACK', '', 'ENT'];
            case 'callWait':
                return ['STBY', '', '', 'RESEND'];
            case 'callResult': {
                const kind = s.call?.kind;
                if (kind === 'group' || kind === 'all_ships') return ['STBY', 'INFO', '', 'RESEND'];
                if (kind === 'individual_ack' || kind === 'test_ack') return ['STBY', '', '', 'RESEND'];

                return ['STBY', '', '', ''];
            }
            case 'terminate':
                return ['OK', '', '', 'CANCEL'];
            case 'ackAlarm':
            case 'rcvdAlarm':
                return ['ALARM OFF', 'ALARM OFF', 'ALARM OFF', 'ALARM OFF'];
            case 'rcvdAction':
                if (s.incoming?.type === 'individual') return ['IGN', 'ABLE', '', 'ACPT'];

                return ['IGN', s.incoming?.paused ? 'RESUME' : 'PAUSE', '', 'ACPT'];
            case 'rcvdRequest':
                return ['STBY', 'ABLE', 'UNABLE', 'NEW CH'];
            case 'rcvdAccepted':
                return DISTRESS_TYPES.has(s.call?.received) ? ['STBY', 'INFO', 'HIST', ''] : ['STBY', 'INFO', '', ''];
            case 'distressWait':
                return ['CANCEL', '', '', 'RESEND'];
            case 'distressResend':
                return ['BACK', '', '', ''];
            case 'cancelConfirm':
                return ['BACK', '', '', 'CONTINUE'];
            case 'cancelVoice':
                return ['FINISH', '', '', 'RESEND'];
            case 'distressDone':
                return ['STBY', '', '', ''];
            case 'distressAcked':
                return ['STBY', 'INFO', 'HIST', ''];
            case 'radioInfo':
            case 'gpsInfo':
                return ['EXIT', 'BACK', '', ''];
            default:
                return handheld ? ['', '', '', ''] : pageLabels(s.page);
        }
    }

    function softkey(index) {
        const shown = softkeys()[index];
        if (! shown) return;
        const label = handheld ? M94_IN[shown] ?? shown : shown;
        event('softkey', { label });

        switch (label) {
            case 'DISTRESS':
                if (! dscBlocked()) openDistressMenu();
                break;
            case 'OTHER DSC':
                if (! dscBlocked()) openForm();
                break;
            case 'SCAN':
                if (s.scan) stopScan();
                else startScan();
                break;
            case 'DW':
            case 'TW':
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
            case 'FIN':
                editorFinish();
                break;
            case 'CALL':
                sendForm();
                break;
            case 'ADD':
                startEditor(s.menu.kind === 'groups' ? 'addGroup' : 'addIndv', () => openIdList(s.menu?.kind === 'groups'));
                break;
            case 'ALARM OFF':
                alarmOff();
                break;
            case 'IGN':
                event('call_ignored', { call: s.incoming?.type });
                s.incoming = null;
                backToNormal();
                break;
            case 'PAUSE':
            case 'RESUME':
                pauseCountdown(label === 'PAUSE');
                break;
            case 'ACPT':
                acceptIncoming();
                break;
            case 'ABLE':
                if (s.screen === 'rcvdAction') ackIncoming('able', true);
                else ackIncoming('able');
                break;
            case 'UNABLE':
                ackIncoming('unable');
                break;
            case 'NEW CH':
                s.call.back = () => setScreen('rcvdRequest');
                s.call.channelLabel = 'Intership CH';
                s.call.channel = s.call.from?.channel ?? 8;
                s.call.channelFor = 'newChannel';
                setScreen('channelSelect');
                break;
            case 'STBY':
                standby();
                break;
            case 'OK':
                s.call = null;
                s.terminateFrom = null;
                backToNormal();
                break;
            case 'CANCEL':
                if (s.screen === 'terminate') setScreen(s.terminateFrom ?? 'normal');
                else setScreen('cancelConfirm');
                break;
            case 'CONTINUE':
                sendDistressCancel();
                break;
            case 'FINISH':
                setScreen('distressDone');
                break;
            case 'RESEND':
                resend();
                break;
            case 'INFO':
            case 'HIST':
                event('call_info', { call: s.call?.received ?? s.call?.kind ?? null, label });
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

    function standby() {
        if (s.screen === 'callWait' || (s.screen === 'callResult' && s.call?.kind === 'test')) {
            s.terminateFrom = s.screen;
            setScreen('terminate');

            return;
        }
        if (s.screen === 'distressDone' || s.screen === 'distressAcked') s.distress = null;
        s.call = null;
        backToNormal();
    }

    function resend() {
        if (s.screen === 'distressWait') {
            setScreen('distressResend');
        } else if (s.screen === 'cancelVoice') {
            event('dsc_sent', { kind: 'distress_cancel', nature: s.distress?.nature ?? null, repeat: true });
        } else if (s.call) {
            transmit(s.call, true);
        }
    }

    // --- Menüs -----------------------------------------------------------------

    function openMenu(title, items, back = null, list = 'menu', kind = null) {
        s.menu = { title, items, back, list, kind };
        s.cursor = 0;
        setScreen('menu');
    }

    function openMain() {
        openMenu('MENU', menus.main);
    }

    function openSub(title, items, backFn = openMain) {
        openMenu(title, items, backFn);
    }

    // IC-M94DE: Radio und DSC liegen unter „Settings“.
    function openSettings() {
        openSub('SETTINGS', MENU_M94.settings);
    }

    function openRadioSettings() {
        openSub(menus.radioTitle, menus.radio, handheld ? openSettings : openMain);
    }

    function openDscSettings() {
        openSub(menus.dscTitle, menus.dsc, handheld ? openSettings : openMain);
    }

    function openLogMenu() {
        openSub(menus.logTitle, menus.log);
    }

    function openSelect(title, items, onSelect, backFn, cursor = 0) {
        openMenu(title, items, backFn, 'select');
        s.menu.onSelect = onSelect;
        s.cursor = cursor;
    }

    function openIdList(group) {
        const list = group ? s.groups : s.book;
        openMenu(group ? 'GROUP ID' : 'INDIVIDUAL ID', list.length ? list.map((a) => a.name) : ['No ID'], () => openDscSettings(), 'idList', group ? 'groups' : 'book');
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
        if (s.screen === 'channelSelect' && s.call?.back) {
            s.call.back();

            return;
        }
        if (s.screen === 'dscForm' && s.form?.back) {
            s.form.back();

            return;
        }
        if (s.screen === 'distressResend' || s.screen === 'cancelConfirm') {
            setScreen('distressWait');

            return;
        }
        if (s.screen === 'radioInfo' || s.screen === 'gpsInfo') {
            openMain();

            return;
        }
        s.call = null;
        backToNormal();
    }

    function receivedLines() {
        return s.received.length ? s.received.slice(0, 8).map((r) => RECEIVED[r.type]?.replace('RCVD ', '') ?? r.type) : ['No Call'];
    }

    function chooseMenuItem() {
        const shown = s.menu.items[s.cursor];
        if (s.menu.list === 'select') {
            s.menu.onSelect(shown, s.cursor);

            return;
        }
        if (s.menu.list === 'log' || s.menu.list === 'idList') return;
        const item = handheld ? MENU_M94_ALIAS[shown] ?? shown : shown;

        if (handheld && item === 'Settings') {
            event('menu', { item });
            openSettings();

            return;
        }
        if (handheld && item === 'Unread List') {
            event('menu', { item });
            openMenu('UNREAD LIST', receivedLines(), openMain, 'log');

            return;
        }
        if (! AVAILABLE.has(item)) {
            event('menu_unavailable', { item: shown });

            return;
        }
        event('menu', { item });

        switch (item) {
            case 'Distress':
                if (! dscBlocked()) openDistressMenu();
                break;
            case 'Other DSC':
                if (! dscBlocked()) openForm(openMain);
                break;
            case 'GPS':
                setScreen('gpsInfo');
                break;
            case 'Radio Settings':
                openRadioSettings();
                break;
            case 'DSC Settings':
                openDscSettings();
                break;
            case 'DSC Log':
                openLogMenu();
                break;
            case 'Radio Info':
                setScreen('radioInfo');
                event('mmsi_checked', { mmsi: own?.mmsi });
                break;
            case 'Dual/Tri-watch':
                openSelect('DUAL/TRI-WATCH', ['Dualwatch', 'Tri-watch'], (value) => {
                    s.watchMode = value === 'Tri-watch' ? 'tri' : 'dual';
                    event('set_mode', { item: 'Dual/Tri', value: s.watchMode });
                    openRadioSettings();
                }, () => openRadioSettings(), s.watchMode === 'tri' ? 1 : 0);
                break;
            case 'Channel Group':
                openSelect('CHANNEL GROUP', GROUPS, (group) => {
                    // Solange der Notalarm auf Bestätigung wartet, bleibt das
                    // Gerät im Seefunk (DSC und Kanal 16).
                    if (group === 'ATIS' && ! inland) {
                        event('inland_blocked', { group });
                        openRadioSettings();

                        return;
                    }
                    if (group !== s.group && s.distress?.waiting) {
                        event('group_blocked', { group, reason: 'distress' });
                        openRadioSettings();

                        return;
                    }
                    const ch = channelInfo(group, s.channel) ? s.channel : CHANNEL_GROUPS[group][0].number;
                    setChannel(ch, { group });
                    event('group', { group });
                    openRadioSettings();
                }, () => openRadioSettings(), GROUPS.indexOf(s.group));
                break;
            case 'Position Input':
                startEditor('lat', () => openDscSettings(), 'settings');
                break;
            case 'Individual ID':
                openIdList(false);
                break;
            case 'Group ID':
                openIdList(true);
                break;
            case 'Received Call Log':
                openMenu('RCVD CALL LOG', receivedLines(), () => openLogMenu(), 'log');
                break;
            case 'Transmitted Call Log':
                openMenu('TX CALL LOG', s.sent.length ? s.sent.slice(0, 8).map((k) => TX_TEXT[k] ?? k) : ['No Call'], () => openLogMenu(), 'log');
                break;
        }
    }

    // --- Bildschirm DISTRESS -------------------------------------------------------

    function distressItems() {
        return [`Nature: ${NATURE_LABELS_M330[s.nature]}`, 'Position'];
    }

    function openDistressMenu() {
        s.menu = null;
        s.cursor = 0;
        setScreen('distressMenu');
    }

    function chooseDistressItem() {
        if (s.cursor === 0) {
            openSelect('NATURE', NATURE_LABELS_M330, (label, index) => {
                s.nature = index;
                event('nature_selected', { nature: NATURES[s.nature], label });
                openDistressMenu();
            }, openDistressMenu, s.nature);

            return;
        }
        startEditor('lat', openDistressMenu, 'distress');
    }

    // --- Bildschirm OTHER DSC --------------------------------------------------------

    function formDefaults(type) {
        return {
            individual: { category: 'Routine', channel: 8 },
            group: { category: 'Routine', channel: 8 },
            all_ships: { category: 'Safety', channel: 16 },
            test: { category: 'Safety', channel: null },
        }[type];
    }

    function openForm(backFn = null) {
        s.form = { type: 'individual', address: null, ...formDefaults('individual'), back: backFn };
        s.cursor = 0;
        setScreen('dscForm');
    }

    function isCoast(address) {
        return Boolean(address?.mmsi?.startsWith('00'));
    }

    function formRows() {
        const f = s.form;
        const name = f.address ? (f.address.name ?? f.address.mmsi) : '---------';
        const rows = [{ field: 'type', label: handheld ? 'Message Type' : 'Type', value: TYPE_LABELS[f.type] }];
        if (f.type !== 'all_ships') rows.push({ field: 'address', label: 'Address', value: name });
        if (f.type === 'individual_ack') {
            rows.push({ field: 'comply', label: 'Comply', value: f.action === 'unable' ? 'Unable Comply' : 'Able Comply' });
            if (f.action !== 'unable' && f.channel) rows.push({ field: 'channel', label: 'Channel', value: pad(f.channel) });

            return rows;
        }
        rows.push({ field: 'category', label: 'Category', value: f.category });
        if (['individual', 'group', 'all_ships'].includes(f.type)) {
            rows.push({ field: 'mode', label: 'Mode', value: 'Telephony' });
            if (! (f.type === 'individual' && isCoast(f.address))) rows.push({ field: 'channel', label: 'Channel', value: pad(f.channel) });
        }

        return rows;
    }

    const backToForm = () => setScreen('dscForm');

    function addressList() {
        return s.form.type === 'group' ? s.groups : s.book;
    }

    function chooseFormRow() {
        const f = s.form;
        const row = formRows()[s.cursor];
        if (! row) return;
        const reply = f.type === 'individual_ack' || f.type === 'test_ack';

        switch (row.field) {
            case 'type':
                if (reply) {
                    event('menu_unavailable', { item: 'Type' });

                    return;
                }
                openSelect('MESSAGE TYPE', MESSAGE_TYPES.map((t) => TYPE_LABELS[t]), (_label, index) => {
                    const type = MESSAGE_TYPES[index];
                    const groupList = (t) => t === 'group';
                    if (groupList(type) !== groupList(f.type) || type === 'all_ships') f.address = null;
                    f.type = type;
                    Object.assign(f, formDefaults(type));
                    s.cursor = 0;
                    backToForm();
                }, backToForm, MESSAGE_TYPES.indexOf(f.type));
                break;
            case 'address':
                if (reply) {
                    event('menu_unavailable', { item: 'Address' });

                    return;
                }
                openSelect('ADDRESS', ['Manual Input', ...addressList().map((a) => a.name)], (_label, index) => {
                    if (index === 0) {
                        startEditor(f.type === 'group' ? 'groupId' : 'indId', backToForm, 'address');

                        return;
                    }
                    f.address = addressList()[index - 1];
                    s.cursor = 1;
                    backToForm();
                }, backToForm);
                break;
            case 'category': {
                const options = f.type === 'all_ships' ? ['Safety', 'Urgency'] : [f.category];
                openSelect('CATEGORY', options, (category) => {
                    f.category = category;
                    backToForm();
                }, backToForm, Math.max(0, options.indexOf(f.category)));
                break;
            }
            case 'mode':
                openSelect('MODE', ['Telephony'], backToForm, backToForm);
                break;
            case 'comply':
                openSelect('COMPLY', ['Able Comply', 'Unable Comply'], (_label, index) => {
                    f.action = index === 1 ? 'unable' : 'able';
                    backToForm();
                }, backToForm, f.action === 'unable' ? 1 : 0);
                break;
            case 'channel':
                s.call = { channelFor: 'form', channel: f.channel, channelLabel: f.type === 'all_ships' ? 'ITU CH' : 'Intership CH', back: backToForm };
                setScreen('channelSelect');
                break;
        }
    }

    function confirmChannel() {
        const c = s.call;
        if (c.channelFor === 'form') {
            s.form.channel = c.channel;
            s.call = null;
            backToForm();

            return;
        }
        // Neuer Kanal für die Bestätigung eines Einzelanrufs.
        openAckForm(c.from, 'able', c.channel);
    }

    function openAckForm(from, action, channel) {
        s.form = { type: 'individual_ack', address: from.from ?? null, action, channel: action === 'unable' ? null : channel, category: from.category ?? 'Routine', incoming: from, back: () => { s.call = { from, received: 'individual' }; setScreen('rcvdRequest'); } };
        s.cursor = 0;
        setScreen('dscForm');
    }

    function sendForm() {
        const f = s.form;
        if (! f) return;
        if (['individual', 'group', 'test'].includes(f.type) && ! f.address) {
            event('dsc_incomplete', { field: 'address' });
            s.cursor = 1;

            return;
        }
        const coast = f.type === 'individual' && isCoast(f.address);
        const call = {
            kind: f.type,
            address: f.address ?? null,
            category: f.category ?? null,
            channel: f.type === 'test' || coast ? null : f.channel ?? null,
            action: f.type === 'individual_ack' ? f.action : null,
        };
        s.form = null;
        transmit(call);
    }

    function transmit(call, repeat = false) {
        event('dsc_sent', { kind: call.kind, address: call.address, category: call.category, channel: call.channel, action: call.action, ...(repeat ? { repeat: true } : {}) });
        s.sent.unshift(call.kind);
        s.call = { ...call, sentAt: clock(), waiting: ['individual', 'test'].includes(call.kind) };
        setScreen('callTx');
    }

    function afterTransmit() {
        const c = s.call;
        if (c.waiting) {
            setScreen('callWait');

            return;
        }
        // Gruppenanruf, Anruf an alle und Bestätigung "Able": sofort auf den
        // zugewiesenen Kanal.
        if (['group', 'all_ships'].includes(c.kind) && c.channel) setChannel(c.channel);
        if (c.kind === 'individual_ack' && c.action === 'able' && c.channel) setChannel(c.channel);
        setScreen('callResult');
    }

    // --- Editor (Zeichenzeile) ---------------------------------------------------------

    function startEditor(kind, backFn = null, then = null) {
        const t = EDITORS[kind];
        const chars = t.template.split('').map((c) => (c === '#' ? '0' : c));
        const editable = t.template.split('').map((c, i) => (c === '#' || c === 'N' || c === 'E' ? i : -1)).filter((i) => i >= 0);
        if (kind === 'groupId' || kind === 'addGroup') editable.shift();
        s.editor = { kind, ...t, row: [...t.row, '←', '→'], chars, editable, pos: 0, sel: 0, back: backFn, then };
        setScreen('editor');
    }

    function editorSelect(direction) {
        const e = s.editor;
        e.sel = (e.sel + direction + e.row.length) % e.row.length;
    }

    function editorMove(direction) {
        const e = s.editor;
        e.pos = Math.max(0, Math.min(e.editable.length - 1, e.pos + direction));
    }

    // ENT übernimmt das markierte Zeichen an der Cursorstelle und rückt vor;
    // ← und → in der Zeile bewegen den Cursor. FIN schließt ab.
    function editorEnter() {
        const e = s.editor;
        const c = e.row[e.sel];
        if (c === '←' || c === '→') {
            editorMove(c === '←' ? -1 : 1);

            return;
        }
        const i = e.editable[e.pos];
        const letterSlot = /[NSEW]/.test(e.chars[i]);
        if (letterSlot !== /[NSEW]/.test(c)) {
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
        const { then, back: backFn } = e;

        // Unmögliche Werte (Breite über 90°, Länge über 180°, Minuten ab 60,
        // Uhrzeit über 23:59) übernimmt das Gerät nicht.
        const invalid = (field) => event('input_invalid', { field });
        if (e.kind === 'lat') {
            const m = text.match(/^(\d\d)°(\d\d\.\d{4})([NS])$/);
            if (! m || Number(m[1]) > 90 || Number(m[2]) >= 60) return invalid('position');
            s.pendingLat = [Number(m[1]), Number(m[2]), m[3]];
            startEditor('lon', backFn, then);
        } else if (e.kind === 'lon') {
            const m = text.match(/^(\d\d\d)°(\d\d\.\d{4})([EW])$/);
            if (! m || Number(m[1]) > 180 || Number(m[2]) >= 60) return invalid('position');
            s.position = [...s.pendingLat, Number(m[1]), Number(m[2]), m[3]];
            s.positionSetAt = clock();
            event('position_set', { position: s.position });
            startEditor('utc', backFn, then);
        } else if (e.kind === 'utc') {
            const m = text.match(/^(\d\d):(\d\d)$/);
            if (! m || Number(m[1]) > 23 || Number(m[2]) > 59) return invalid('utc');
            s.utc = text;
            event('utc_set', { utc: s.utc });
            s.editor = null;
            if (then === 'distress') openDistressMenu();
            else openDscSettings();
        } else if (e.kind === 'indId' || e.kind === 'groupId') {
            if (e.kind === 'indId' && text.startsWith('0') && ! text.startsWith('00')) {
                event('address_invalid', { mmsi: text, group: true });

                return;
            }
            s.form.address = { name: directory.find((d) => d.mmsi === text)?.name ?? null, mmsi: text };
            s.editor = null;
            s.cursor = 1;
            backToForm();
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
            openIdList(group);
        }
    }

    // --- Notalarm ------------------------------------------------------------------

    function sendDistress(repeat = false) {
        const nature = NATURES[s.nature];
        s.distress = { nature, sentAt: clock(), nextAt: clock() + DISTRESS_REPEAT, waiting: true, count: (s.distress?.count ?? 0) + 1 };
        setScreen('distressSent');
        event('dsc_sent', { kind: 'distress', nature, label: NATURE_LABELS_M330[s.nature], position: s.position, utc: s.utc, repeat });
    }

    function sendDistressCancel() {
        if (! s.distress) return;
        s.distress.waiting = false;
        event('dsc_sent', { kind: 'distress_cancel', nature: s.distress.nature });
        event('distress_repeat_cancelled', { nature: s.distress.nature, dscCancel: true });
        setChannel(16, { work: false });
        setScreen('cancelVoice');
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
                setScreen('distressAcked');

                return;
            }
            if (incoming?.type === 'individual_ack' && incoming.action === 'unable') {
                event('call_failed', { kind: 'individual' });
                c.unable = true;
                setScreen('callResult');

                return;
            }
            if (c?.kind === 'individual') {
                const channel = incoming?.channel ?? c.channel;
                if (channel) setChannel(channel);
            }
            setScreen('callResult');

            return;
        }
        if (s.screen === 'rcvdAlarm') {
            const i = s.incoming;
            i.silenced = true;
            event('alarm_stopped', { call: i.type });
            if (AUTO_SWITCH.has(i.type)) {
                i.countdownFrom = clock();
                i.paused = false;
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
        if (! i) return;
        if (i.type === 'individual') {
            s.incoming = null;
            s.call = { from: i, received: 'individual' };
            setScreen('rcvdRequest');

            return;
        }
        if (! AUTO_SWITCH.has(i.type)) return;
        const channel = DISTRESS_TYPES.has(i.type) ? 16 : i.channel ?? 16;
        s.incoming = null;
        s.menu = null;
        s.editor = null;
        s.form = null;
        setChannel(channel, { work: channel !== 16 });
        s.call = { received: i.type, from: i, at: clock() };
        setScreen('rcvdAccepted');
        event('call_accepted', { call: i.type, channel, ...(auto ? { auto: true } : {}) });
    }

    // ABLE im Empfangsbildschirm sendet sofort "Able to comply"; im
    // Bildschirm "Received Request" öffnet ABLE/UNABLE das Formular.
    function ackIncoming(action, immediate = false) {
        const i = immediate ? s.incoming : s.call?.from;
        if (! i) return;
        s.incoming = null;
        if (immediate) {
            transmit({ kind: 'individual_ack', address: i.from ?? null, category: i.category ?? 'Routine', channel: i.channel ?? null, action });

            return;
        }
        openAckForm(i, action, i.channel ?? null);
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
            case 'dscForm':
                chooseFormRow();
                break;
            case 'distressMenu':
                chooseDistressItem();
                break;
            case 'channelSelect':
                confirmChannel();
                break;
            default:
                break;
        }
    }

    const LIST_SCREENS = ['menu', 'dscForm', 'distressMenu'];

    function listLength() {
        if (s.screen === 'menu') return s.menu.items.length;
        if (s.screen === 'dscForm') return formRows().length;

        return distressItems().length;
    }

    function moveCursor(steps) {
        s.cursor = Math.max(0, Math.min(listLength() - 1, s.cursor + steps));
    }

    function stepEdit(direction) {
        s.call.channel = stepIntership(s.call.channel, direction);
    }

    // IC-M94DE: im Lautstärke-/Rauschsperre-Fenster stellen ▲▼◄► ein.
    function adjustWindow(direction) {
        s.windowUntil = clock() + WINDOW_MS;
        if (s.window === 'sql') {
            s.sql = Math.max(0, Math.min(10, s.sql + direction));
            event('squelch', { level: s.sql, state: squelchState() });
        } else {
            s.vol = Math.max(0, Math.min(20, s.vol + direction * 2));
            event('volume', { level: s.vol / 2 });
        }
    }

    function windowOpen() {
        return Boolean(s.window && clock() < s.windowUntil);
    }

    function shortPress(key) {
        if (! s.power) return;

        if (key === 'distressCover') {
            s.cover = ! s.cover;
            event('cover', { open: s.cover });

            return;
        }
        if (handheld) {
            // Tastensperre: nur Ein/Aus, Sprechtaste, DISTRESS und CLR (1 s).
            if (s.locked) {
                event('key_locked', { key });

                return;
            }
            if (key === 'volsql') {
                s.window = windowOpen() && s.window === 'vol' ? 'sql' : 'vol';
                s.windowUntil = clock() + WINDOW_MS;
                event('knob_mode', { mode: s.window });

                return;
            }
            // Lautstärke/Rauschsperre in jedem Bildschirm, auch beim Warten auf
            // die Bestätigung des Notalarms (▲/▼ wechseln dann nicht den Kanal).
            if (windowOpen() && ['up', 'down', 'left', 'right', 'enter', 'clr'].includes(key)) {
                if (key === 'enter' || key === 'clr') s.window = null;
                else adjustWindow(key === 'up' || key === 'right' ? 1 : -1);

                return;
            }
            // ◄/► blättern in Bildschirmen mit mehr als drei Softkey-Funktionen.
            if ((key === 'left' || key === 'right') && ! ['normal', 'menu', 'dscForm', 'distressMenu', 'editor', 'channelSelect'].includes(s.screen)) {
                const pages = screenPagesM94();
                const next = Math.max(0, Math.min(pages.length - 1, s.subpage + (key === 'right' ? 1 : -1)));
                if (next !== s.subpage) {
                    s.subpage = next;
                    event('softkeys', { labels: softkeys(), page: next });
                }

                return;
            }
        }
        if (key.startsWith('soft')) {
            softkey(Number(key.slice(4)) - 1);

            return;
        }
        if (key === 'knob') {
            if (LIST_SCREENS.includes(s.screen) || ['editor', 'channelSelect'].includes(s.screen)) {
                enter();

                return;
            }
            // 1× drücken: Lautstärke-Fenster, 2×: Rauschsperre-Fenster
            // (ist das Lautstärke-Fenster schon offen, genügt einmal).
            const open = s.window && clock() < s.windowUntil;
            s.window = open && s.window === 'vol' ? 'sql' : 'vol';
            s.windowUntil = clock() + WINDOW_MS;
            event('knob_mode', { mode: s.window });

            return;
        }
        if (key === 'micHiLo') {
            toggleHighPower();

            return;
        }
        if (key === 'mic16') key = 'ch16';
        if (key === 'menu') {
            if (['menu', 'editor', 'dscForm', 'distressMenu', 'channelSelect', 'radioInfo', 'gpsInfo'].includes(s.screen)) backToNormal();
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

        if (LIST_SCREENS.includes(s.screen)) {
            if (key === 'up') moveCursor(-1);
            if (key === 'down') moveCursor(1);
            // ► = nächste Ebene, ◄ = zurück.
            if (key === 'right') enter();
            if (key === 'left') back();

            return;
        }
        if (s.screen === 'editor') {
            if (key === 'up' || key === 'left') editorSelect(-1);
            if (key === 'down' || key === 'right') editorSelect(1);

            return;
        }
        if (s.screen === 'channelSelect') {
            if (key === 'up' || key === 'down' || key === 'micUp' || key === 'micDown') stepEdit(key.toLowerCase().endsWith('up') ? 1 : -1);

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
            case 'micUp':
            case 'micDown':
                // Am Hörer ab Werk alle Kanäle (FAV on MIC = Off).
                if (s.scan) { stopScan(); break; }
                if (s.screen !== 'normal' && s.screen !== 'distressWait') break;
                setChannel(stepChannel(s.group, s.channel, key.toLowerCase().endsWith('up') ? 1 : -1));
                break;
            case 'left':
            case 'right': {
                if (s.screen !== 'normal') break;
                const page = Math.max(0, Math.min(PAGES.length - 1, s.page + (key === 'right' ? 1 : -1)));
                if (page !== s.page) {
                    s.page = page;
                    event('softkeys', { labels: softkeys(), page });
                }
                break;
            }
            default:
                break;
        }
    }

    function longPress(key) {
        if (key === 'knob' || (handheld && key === 'power')) {
            if (s.power) {
                Object.assign(s, { power: false, tx: false, menu: null, editor: null, form: null, call: null, incoming: null, cover: false, scan: null, watch: 'off', window: null, distress: null, monitor: false, locked: false });
                // Manuelle Position und Zeit gehen beim Ausschalten verloren.
                Object.assign(s, { position: null, utc: null, positionSetAt: null });
                setScreen('off');
                event('power', { on: false });
            } else {
                s.power = true;
                s.page = 0;
                setScreen('normal');
                event('power', { on: true });
            }

            return;
        }
        if (! s.power) return;
        if (handheld && key === 'clr') {
            s.locked = ! s.locked;
            event('key_lock', { on: s.locked });

            return;
        }
        if (handheld && s.locked) return;
        if (handheld && key === 'volsql') {
            s.monitor = true;
            event('monitor', { on: true });

            return;
        }
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
                    setScreen(s.distressHold?.from ?? 'normal');
                    event('distress_released_early', { heldMs: clock() - p.t0 });
                }
                s.distressHold = null;

                return;
            }
            if (p.long) {
                if (key === 'volsql' && s.monitor) {
                    s.monitor = false;
                    event('monitor', { on: false });
                }

                return;
            }
            shortPress(key);
        },
        turn(knob, steps) {
            if (knob !== 'dial' || ! s.power) return;
            if (LIST_SCREENS.includes(s.screen)) {
                moveCursor(steps);
            } else if (s.screen === 'editor') {
                // Drehen bewegt in Eingabemasken den Cursor.
                editorMove(Math.sign(steps));
            } else if (s.screen === 'channelSelect') {
                stepEdit(Math.sign(steps));
            } else if (s.window === 'sql' && clock() < s.windowUntil) {
                s.windowUntil = clock() + WINDOW_MS;
                s.sql = Math.max(0, Math.min(10, s.sql + steps));
                event('squelch', { level: s.sql, state: squelchState() });
            } else {
                s.window = 'vol';
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
                if (now - p.t0 >= LONG && (key === 'knob' || key === 'ch16' || key === 'mic16' || (handheld && ['power', 'clr', 'volsql'].includes(key)))) {
                    p.long = true;
                    longPress(key);
                }
            }
            if (s.screen === 'distressHold' && s.distressHold && now - s.distressHold.t0 >= DISTRESS_HOLD) {
                s.distressHold = null;
                sendDistress(Boolean(s.distress?.waiting));
            }
            if (s.screen === 'distressSent' && now - s.screenSince >= TRANSMIT_MS) {
                // Annahme: nach dem Senden hört das Gerät auf Kanal 16.
                if (s.channel !== 16) setChannel(16, { work: false });
                setScreen('distressWait');
            }
            if (s.screen === 'callTx' && now - s.screenSince >= TRANSMIT_MS) afterTransmit();
            if (s.screen === 'rcvdAction' && s.incoming && AUTO_SWITCH.has(s.incoming.type) && ! s.incoming.paused && remaining(s.incoming) <= 0) {
                acceptIncoming(true);
            }
            if (s.scan && now - s.scan.last >= SCAN_STEP) {
                s.scan.last = now;
                s.scan.index = (s.scan.index + 1) % s.scan.list.length;
                s.channel = s.scan.list[s.scan.index];
            }
            if (s.power && s.distress?.waiting && now >= s.distress.nextAt) {
                sendDistress(true);
            }
            if (s.window && now >= s.windowUntil) s.window = null;
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

            // Position-, Polling- und Test-ACK stehen ab Werk auf Auto.
            if (call.type === 'position_request' || call.type === 'polling_request' || call.type === 'test_call') {
                const kind = { position_request: 'position_reply', polling_request: 'polling_reply', test_call: 'test_ack' }[call.type];
                event('dsc_sent', { kind, address: call.from, auto: true, position: s.position });

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
            if (! inland && s.group === 'ATIS') Object.assign(s, { group: 'INT', channel: 16 });
            // 20 Lautstärkestufen; die Lektionen nennen die Stufen des
            // IC-M505 (0–10).
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
        if (! s.power) return { on: false, softkeys: handheld ? ['', '', ''] : ['', '', '', ''] };
        const ch = current();
        const stale = positionStale() ? '??' : '';
        const pos = formatPosition(s.position)?.map((l) => stale + l) ?? ['NO POSITION'];
        const time = s.utc ? `${stale}${s.utc}` : 'NO TIME';
        const normalScreen = s.screen === 'normal';
        const base = {
            on: true,
            status: {
                busy: squelchState() === 'noise' || Boolean(s.incoming && ! s.incoming.silenced),
                tx: s.tx,
                power: handheld ? (effectiveHighPower() ? 'HI' : 'LO') : effectiveHighPower() ? '25W' : '1W',
                monitor: s.monitor,
                group: s.group,
                dup: Boolean(ch?.duplex),
                call: Boolean(s.onCall),
                tag: s.tags.has(s.channel),
                atis: s.group === 'ATIS',
                mail: s.received.length > 0,
            },
            mode: 'STBY',
            mmsi: own?.mmsi ?? null,
            channel: pad(s.channel),
            channelName: ch?.name ?? '',
            watch: s.scan ? 'SCAN' : s.watch === 'dual' ? 'DW 16' : s.watch === 'tri' ? 'TW 16' : null,
            lines: [...pos, time],
            softkeys: softkeys(),
            // Seitenmarken ◂ ▸ nur in der Softkey-Leiste des Betriebsbildschirms.
            pages: normalScreen
                ? { current: s.page, count: PAGES.length, labels: PAGES.map((_, i) => pageLabels(i)) }
                : handheld && screenPagesM94().length > 1 ? { current: s.subpage, count: screenPagesM94().length, labels: screenPagesM94() } : null,
            locked: s.locked,
            panel: null,
            window: null,
            alarm: false,
        };
        if ((normalScreen || handheld) && s.window && clock() < s.windowUntil) {
            base.window = s.window === 'sql' ? { label: 'SQL:', value: String(s.sql), level: s.sql / 10 } : { label: 'Volume:', value: String(s.vol), level: s.vol / 20 };
        }
        const panel = (title, lines = [], extra = {}) => ({ ...base, panel: { title, lines, ...extra } });
        const c = s.call;
        const to = c?.address ? `To: ${c.address.name ?? c.address.mmsi}` : c?.kind === 'all_ships' ? 'To: All Ships' : '';
        const elapsed = (t0) => {
            const sec = Math.max(0, Math.floor((clock() - t0) / 1000));
            return `Elapsed: ${pad(Math.floor(sec / 3600))}:${pad(Math.floor(sec / 60) % 60)}:${pad(sec % 60)}`;
        };
        const watt = `${handheld ? (effectiveHighPower() ? 'HI' : 'LO') : effectiveHighPower() ? '25W' : '1W'}   ${pad(s.channel)}`;

        switch (s.screen) {
            case 'menu':
                return panel(s.menu.title, [], { items: s.menu.items, cursor: s.cursor });
            case 'editor': {
                const e = s.editor;
                return panel(e.title, [e.label], { editor: { chars: e.chars, cursor: e.editable[e.pos], row: e.row, sel: e.sel } });
            }
            case 'dscForm': {
                return panel(handheld ? 'COMPOSE OTHER' : 'OTHER DSC', [], { items: formRows().map((r) => `${r.label}: ${r.value} ▸`), cursor: s.cursor });
            }
            case 'distressMenu':
                return panel(handheld ? 'COMPOSE DISTRESS' : 'DISTRESS', [], { note: 'Push [DISTRESS] for 3 sec', items: distressItems().map((i) => `${i} ▸`), cursor: s.cursor });
            case 'channelSelect':
                return panel('CHANNEL', [`${c.channelLabel}:`, `▲▼  ${pad(c.channel)}`]);
            case 'radioInfo':
                return panel('RADIO INFO', [`MMSI: ${own?.mmsi ?? '---------'}`, `ATIS ID: ${own?.atis ?? '----------'}`]);
            case 'gpsInfo':
                return panel('GPS', [...pos, time]);
            case 'callTx':
                return panel(`≡ ${TITLES[c.kind]} ≡`, ['Transmitting', TX_TEXT[c.kind]]);
            case 'callWait':
                return panel(TITLES[c.kind], ['Waiting for ACK', elapsed(c.sentAt), to], { blink: true });
            case 'callResult':
                if (c.unable) return panel(TITLES[c.kind], ['Received ACK', 'Unable to comply', to]);
                if (c.kind === 'test') return panel('TEST CALL', ['Received ACK', elapsed(c.sentAt), to.replace('To:', 'From:')]);
                if (c.kind === 'individual_ack') return panel(TITLES[c.kind], [c.action === 'unable' ? 'TXed ACK (Unable)' : 'TXed ACK (Able)', watt, to]);
                return panel(TITLES[c.kind], [watt, to, elapsed(c.sentAt)]);
            case 'terminate':
                return panel('', ['Terminate', 'the procedure.', 'Are you sure?']);
            case 'ackAlarm': {
                const i = s.incoming;
                const head = i?.type === 'distress_ack' ? 'RCVD DTRS ACK' : i?.type === 'test_ack' ? 'RCVD TEST ACK' : 'RCVD INDV ACK';
                return { ...panel('', [head, `FROM: ${i?.from?.name ?? i?.from?.mmsi ?? ''}`, i?.channel ? `CH ${pad(i.channel)}` : i?.type === 'distress_ack' ? 'CH 16' : '', i?.action === 'unable' ? 'Unable to comply' : ''].filter(Boolean)), alarm: true };
            }
            case 'rcvdAlarm':
            case 'rcvdAction': {
                const i = s.incoming;
                const channel = DISTRESS_TYPES.has(i.type) ? 16 : i.channel;
                const lines = [RECEIVED[i.type] ?? i.type, `FROM: ${i.from?.name ?? i.from?.mmsi ?? ''}`];
                if (DISTRESS_TYPES.has(i.type)) lines.push((i.nature ?? 'Undesignated').toUpperCase());
                if (s.screen === 'rcvdAction' && AUTO_SWITCH.has(i.type)) {
                    lines.push(`CH ${pad(channel ?? 16)}  ACCEPT`, i.paused ? 'PAUSED' : `AFTER ${Math.ceil(remaining(i) / 1000)} sec.`);
                } else {
                    if (channel) lines.push(`CH ${pad(channel)}`);
                    lines.push(elapsed(s.received[0]?.at ?? clock()).toUpperCase());
                }
                return { ...panel('', lines), alarm: s.screen === 'rcvdAlarm' };
            }
            case 'rcvdRequest':
                return panel('INDIVIDUAL CALL', ['Received Request', `From: ${c.from?.from?.name ?? c.from?.from?.mmsi ?? ''}`, c.from?.channel ? `CH ${pad(c.from.channel)}` : '']);
            case 'rcvdAccepted':
                return panel(`≡ ${RECEIVED[c.received] ?? ''} ≡`, [watt, `From: ${c.from?.from?.name ?? c.from?.from?.mmsi ?? ''}`, elapsed(c.at)]);
            case 'distressHold':
                return panel('!! DISTRESS !!', ['Hold Down', 'for 3 sec.'], { progress: radio.holdProgress() });
            case 'distressSent':
                return { ...panel('!! DISTRESS !!', ['Transmitting', 'Distress Alert']), channel: '70' };
            case 'distressWait': {
                const left = Math.max(0, Math.ceil((s.distress.nextAt - clock()) / 1000));
                return panel('!! DISTRESS !!', ['Waiting for ACK', 'Next TX after', `${Math.floor(left / 60)} min. ${left % 60} sec.`], { blink: true });
            }
            case 'distressResend':
                return panel('!! DISTRESS !!', ['Hold [DISTRESS]', 'for 3 sec.', 'to resend']);
            case 'cancelConfirm':
                return panel('DISTRESS CANCEL', ['will transmit', 'DSC cancel.']);
            case 'cancelVoice':
                return panel('!! DTRS CANCEL !!', ['Voice cancel', 'Press PTT & say:', 'All stations, …']);
            case 'distressDone':
                return panel('≡ DISTRESS ≡', [watt, elapsed(s.distress?.sentAt ?? clock())]);
            case 'distressAcked':
                return panel('≡ RCVD DTRS ACK ≡', [watt, `From: ${s.received[0]?.from?.name ?? s.received[0]?.from?.mmsi ?? ''}`]);
            default:
                return base;
        }
    }

    return radio;
}
