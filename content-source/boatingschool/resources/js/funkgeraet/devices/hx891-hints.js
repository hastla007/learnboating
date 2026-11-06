// Schritt-Hilfen für das Handfunkgerät Standard Horizon HX891BT/E, aus dem
// geräteunabhängigen Ziel der Lektion. Drei Softkeys auf Seiten (◀/▶):
// Seite 1 PRESET, DUAL WATCH, FM RADIO, Seite 2 TX PWR, MOB, SCAN MEMORY,
// Seite 3 SCAN … DSC über MENU/SET > DSC CALL, Einstellungen über SETUP
// (MENU/SET 1 Sekunde halten). Funksprüche (Wortlaut) bleiben unverändert.

import { NATURES } from './icm505.js';
import { NATURE_LABELS_HX } from './hx891.js';

const natureLabel = (nature) => NATURE_LABELS_HX[NATURES.indexOf(nature)] ?? nature;
const two = (ch) => String(ch).padStart(2, '0');
const page = (label) => `Mit ◀/▶ die Softkey-Seite mit ${label} aufrufen und ${label} drücken`;
const dscCall = (item) => `MENU/SET, „DSC CALL“ wählen (CH▲/CH▼) und SELECT, dann „${item}“ und SELECT`;
const typing = 'Zeichen mit ◀/▶ oder CH▲/CH▼ wählen, SELECT übernimmt es und rückt weiter';

export function hintHx891(step) {
    const goal = step.goal ?? {};
    const h = (help, target) => ({ help, target });

    switch (goal.type) {
        case 'power':
            return goal.on
                ? h('Die Ein/Aus-Taste oben an der linken Seite eine Sekunde gedrückt halten.', 'power')
                : h('Die Ein/Aus-Taste eine Sekunde gedrückt halten: Das Gerät schaltet aus.', 'power');
        case 'volume':
            return h('VOL+ drücken, bis die Lautstärke passt (Balken „VOL“ im Display).', 'volUp');
        case 'squelch':
            return goal.state === 'noise'
                ? h('SQL unten an der linken Seite drücken, dann CH▼, bis Rauschen zu hören ist.', 'sql')
                : h('SQL drücken, dann CH▲ Schritt für Schritt, bis das Rauschen gerade verschwindet.', 'sql');
        case 'channel':
            if (step.receive?.call?.type === 'individual_ack') return h('Nichts drücken: Mit der Bestätigung stellt das Gerät den Arbeitskanal selbst ein.', null);
            if (goal.circle) return h(`Mit CH▲/CH▼ einen Kanal des Verkehrskreises ${goal.circle} wählen; der Verkehrskreis steht unter der Kanalnummer.`, 'up');
            if (step.target === 'dial') return h('16/S erneut drücken: zurück zum vorherigen Arbeitskanal.', 'ch16');
            if (goal.channel === 16) return h('16/S kurz drücken.', 'ch16');
            if (step.target === null) return h(step.help, null);
            return h(`Mit CH▲/CH▼ Kanal ${two(goal.channel)} wählen.`, 'up');
        case 'call_channel':
            return h('16/S eine Sekunde gedrückt halten: Das Gerät schaltet auf den Zweitkanal 09 (Anrufkanal).', 'ch16');
        case 'tx_blocked':
            return h('Mit CH▲/CH▼ Kanal 70 wählen, dann die Sprechtaste drücken. Das Gerät sendet dort nicht.', 'ptt');
        case 'power_level_blocked':
            return h(`${page('TX PWR')}. Auf diesem Kanal bleibt es bei LO (1 W).`, 'sk:TX PWR');
        case 'power_level':
            return h(`${goal.channel ? `Zuerst mit CH▲/CH▼ Kanal ${two(goal.channel)} wählen. ` : ''}${page('TX PWR')}, bis ${goal.high ? 'HI' : 'MD (2 W) oder LO (1 W)'} oben im Display steht.${goal.high ? ' HI steht hier für die volle Leistung, die in der Prüfung an der Feststation „25 W“ heißt.' : ''}`, 'sk:TX PWR');
        case 'screen':
            return goal.screen === 'menu' ? h('MENU/SET kurz drücken.', 'menu') : h(step.help, null);
        case 'mmsi_checked':
            return h('Im Menü „MMSI/POS INFO“ wählen (CH▲/CH▼) und SELECT: Dort steht die MMSI.', 'sk:SELECT');
        case 'position_set':
            return h(`Softkey POS/TM: Breite, Länge und UTC nacheinander eingeben (${typing}), am Ende FINISH.`, 'sk:POS/TM');
        case 'utc_set':
            return h(`${dscCall('DIST ALERT MSG')}; Softkey POS/TM: Breite, Länge und UTC eingeben (${typing}), am Ende FINISH.`, 'sk:POS/TM');
        case 'nature_selected':
            return h(`${dscCall('DIST ALERT MSG')}; Softkey NATURE, „${natureLabel(goal.nature)}“ wählen und SELECT.`, 'menu');
        case 'cover':
            return h('Die rote Klappe DISTRESS (PULL OPEN) antippen (Ansicht rechts neben dem Gerät).', 'distressCover');
        case 'dsc_sent':
            return dscHint(goal, step);
        case 'dsc_received':
            return h('Nichts drücken: Die Antwort kommt gleich.', null);
        case 'alarm_stopped':
            return h('Eine beliebige Taste drücken, z. B. Softkey STOP ALARM: Der Alarm verstummt.', 'sk:STOP ALARM');
        case 'position_info':
            return h('Eine beliebige Taste schaltet den Alarm aus; danach steht die gemeldete Position im Display.', 'sk:STOP ALARM');
        case 'call_accepted':
            return h('Softkey ACCEPT: Das Gerät schaltet auf den angekündigten Kanal (ohne Eingabe nach 30 Sekunden von selbst).', 'sk:ACCEPT');
        case 'distress_repeat_cancelled':
            return h('Softkey CANCEL, dann YES: Das HX891BT/E sendet die DSC-Aufhebung des Notalarms und schaltet auf Kanal 16.', 'sk:CANCEL');
        case 'watch':
            if (goal.mode === 'off') return h('Softkey DUAL WATCH (bzw. TRIPLE WATCH) erneut drücken.', 'sk:DUAL WATCH');
            return goal.mode === 'tri' ? h(`${page('TRIPLE WATCH')}.`, 'sk:TRIPLE WATCH') : h(`${page('DUAL WATCH')}.`, 'sk:DUAL WATCH');
        case 'set_mode':
            return h('MENU/SET eine Sekunde halten (SETUP), „CH SETUP“, „MULTI WATCH“, „TRIPLE“, jeweils SELECT.', 'menu');
        case 'tag':
            return h(`Kanal mit CH▲/CH▼ wählen, dann ${page('SCAN MEMORY').replace('Mit ◀/▶', 'mit ◀/▶')}: Der Kanal kommt in den Suchlauf.`, 'sk:SCAN MEMORY');
        case 'scan':
            return h(`${page('SCAN')}. Im Display steht PRI-SCAN.`, 'sk:SCAN');
        case 'voice':
            return h('Nur zuhören. Hörst du nichts, prüfe Kanal, Lautstärke und Rauschsperre; nach einem empfangenen Notalarm geht es mit ACCEPT auf Kanal 16.', null);
        case 'address_added':
            return h(`MENU/SET eine Sekunde halten (SETUP), „DSC SETUP“, „INDIVIDUAL DIR.“, „ADD“; „MMSI“ wählen, die 9 Ziffern eingeben (${typing}), FINISH, dann „SAVE“ und SELECT. Den Namen übernimmt die Übung aus der MMSI.`, 'menu');
        case 'group':
            return h(`MENU/SET eine Sekunde halten (SETUP), „CH SETUP“, „CH GROUP“, „${goal.group === 'ATIS' ? 'ATIS' : 'INTL'}“, jeweils SELECT. Im Display steht dann ${goal.group === 'ATIS' ? 'ATIS' : 'INTL'}.`, 'menu');
        case 'ch16_unavailable':
            return h('16/S drücken: Im Binnenfunk gibt es keinen Kanal 16.', 'ch16');
        case 'dsc_blocked':
            return h('MENU/SET drücken, „DSC CALL“ wählen und SELECT: DSC ist im Binnenfunk gesperrt.', 'menu');
        default:
            return h(step.help, step.target);
    }
}

function dscHint(goal, step) {
    const name = goal['address.name'] ?? step.address_name ?? goal['address.mmsi'] ?? '';
    const ch = goal.channel ? two(goal.channel) : null;
    const memory = `„MEMORY“, „${name}“, jeweils SELECT`;
    const channel = ch ? `; Kanal ${ch} wählen (CH▲/CH▼, sonst Softkey MANUAL) und SELECT` : '';

    switch (goal.kind) {
        case 'distress':
            return { help: 'Klappe offen, DISTRESS drei Sekunden gedrückt halten (Countdown 3‑2‑1).', target: 'distress' };
        case 'individual': {
            const coast = String(goal['address.mmsi'] ?? '').startsWith('00');
            return { help: `${dscCall('INDIVIDUAL')}; ${memory}${coast ? '; bei einer Küstenfunkstelle entfällt die Kanalwahl' : channel}; YES sendet. Die Kategorie ist Routine.`, target: 'menu' };
        }
        case 'group':
            return { help: `${dscCall('GROUP')}; ${memory}${channel}; YES sendet.`, target: 'menu' };
        case 'all_ships':
            return { help: `${dscCall('ALL SHIPS')}; „${String(goal.category ?? 'Safety').toUpperCase()}“, SELECT; Kanal 16 mit SELECT übernehmen; YES sendet.`, target: 'menu' };
        case 'individual_ack':
            return { help: 'Eine Taste schaltet den Alarm aus; dann Softkey ABLE und YES: Das Gerät sendet „Able to comply“ und schaltet auf den Arbeitskanal.', target: 'sk:ABLE' };
        case 'test':
            return { help: `${dscCall('DSC TEST')}; ${memory}; YES sendet.`, target: 'menu' };
        case 'position_request':
            return { help: `${dscCall('POS REQUEST')}; ${memory}; YES sendet.`, target: 'menu' };
        case 'polling_reply':
            return { help: 'Nichts drücken: Das HX891BT/E beantwortet das Polling selbst.', target: null };
        default:
            return { help: step.help, target: step.target };
    }
}
