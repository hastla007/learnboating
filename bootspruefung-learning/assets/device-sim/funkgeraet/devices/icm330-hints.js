// Schritt-Hilfen für das IC-M330, aus dem geräteunabhängigen Ziel der
// Lektion. Softkeys liegen auf Seiten (◄/► blättern): Seite 1 DISTRESS und
// OTHER DSC, Seite 2 SCAN, DW, CHAN, HI/LO, Seite 3 AQUA, ★, NAME, BKLT,
// Seite 4 LOG. Funksprüche (Wortlaut) bleiben unverändert.

import { NATURES } from './icm505.js';
import { NATURE_LABELS_M330 } from './icm330.js';

const natureLabel = (nature) => NATURE_LABELS_M330[NATURES.indexOf(nature)] ?? nature;
const two = (ch) => String(ch).padStart(2, '0');
const page2 = (label) => `Mit ◄/► die Softkey-Seite mit ${label} aufrufen und ${label} drücken`;
const typing = 'Zeichen in der Zeile mit ◄/► markieren, ENT übernimmt es und rückt weiter';

export function hintM330(step) {
    const goal = step.goal ?? {};
    const h = (help, target) => ({ help, target });

    switch (goal.type) {
        case 'power':
            return goal.on ? h('Den Drehknopf PWR/VOL/SQL eine Sekunde gedrückt halten.', 'knob') : h('Den Drehknopf eine Sekunde gedrückt halten: Das Gerät schaltet aus.', 'knob');
        case 'volume':
            return h('Den Drehknopf nach rechts drehen; er stellt die Lautstärke ein (Fenster „Volume:“).', 'dial');
        case 'squelch':
            return goal.state === 'noise'
                ? h('Den Drehknopf zweimal kurz drücken, bis „SQL:“ erscheint, dann ganz nach links drehen.', 'knob')
                : h('Im SQL-Fenster (Drehknopf zweimal drücken) langsam nach rechts drehen, bis das Rauschen gerade verschwindet.', 'knob');
        case 'channel':
            if (step.receive?.call?.type === 'individual_ack') return h('Nach der Bestätigung Softkey ALARM OFF: Das Gerät stellt den Arbeitskanal ein.', 'sk:ALARM OFF');
            if (goal.circle) return h(`Mit ▲/▼ einen Kanal des Verkehrskreises ${goal.circle} wählen.`, 'up');
            if (step.target === 'dial') return h(`${page2('CHAN')}: zurück zum vorherigen Betriebskanal.`, 'sk:CHAN');
            if (goal.channel === 16) return h('16/C kurz drücken.', 'ch16');
            if (step.target === null) return h(step.help, null);
            return h(`Mit ▲/▼ Kanal ${two(goal.channel)} wählen.`, 'up');
        case 'call_channel':
            return h('16/C eine Sekunde gedrückt halten; im Display erscheint CALL.', 'ch16');
        case 'tx_blocked':
            return h('Mit ▲/▼ Kanal 70 wählen, dann die Sprechtaste drücken. Das Gerät verweigert das Senden.', 'ptt');
        case 'power_level_blocked':
            return h(`${page2('HI/LO')} (oder HI/LO am Hörer). Auf diesem Kanal bleibt es bei 1 W.`, 'sk:HI/LO');
        case 'power_level':
            return h(`${goal.channel ? `Zuerst mit ▲/▼ Kanal ${two(goal.channel)} wählen. ` : ''}${page2('HI/LO')}, bis ${goal.high ? '25W' : '1W'} im Display steht.`, 'sk:HI/LO');
        case 'screen':
            return goal.screen === 'menu' ? h('MENU drücken.', 'menu') : h(step.help, null);
        case 'mmsi_checked':
            return h('MENU, „Radio Info“ wählen (▲/▼ oder Drehknopf) und ENT: Dort steht die MMSI.', 'menu');
        case 'position_set':
            return h(`MENU, „DSC Settings“, „Position Input“, ENT. ${typing}. Breite mit Softkey FIN abschließen, dann die Länge eingeben und FIN.`, 'menu');
        case 'utc_set':
            return h(`Position und UTC-Zeit eingeben (im Bildschirm DISTRESS über „Position“): ${typing}; jede Zeile mit Softkey FIN abschließen.`, 'enter');
        case 'nature_selected':
            return h(`Softkey DISTRESS (Seite 1) oder MENU, „Distress“; „Nature“, ENT, „${natureLabel(goal.nature)}“ wählen und ENT.`, 'sk:DISTRESS');
        case 'cover':
            return h('Die rote Klappe antippen.', 'distressCover');
        case 'dsc_sent':
            return dscHint(goal, step);
        case 'dsc_received':
            return h('Nichts drücken: Der Anruf kommt gleich.', null);
        case 'alarm_stopped':
            return h('Softkey ALARM OFF drücken.', 'sk:ALARM OFF');
        case 'call_accepted':
            return h('Softkey ACPT drücken: Das Gerät schaltet auf den angekündigten Kanal (ohne Eingabe nach 10 Sekunden von selbst).', 'sk:ACPT');
        case 'distress_repeat_cancelled':
            return h('Softkey CANCEL, dann CONTINUE: Das IC-M330 sendet die DSC-Aufhebung des Notalarms und schaltet auf Kanal 16.', 'sk:CANCEL');
        case 'watch':
            if (goal.mode === 'off') return h('Softkey DW (bzw. TW) erneut drücken.', 'sk:DW');
            return goal.mode === 'tri' ? h(`${page2('TW')}.`, 'sk:TW') : h(`${page2('DW')}.`, 'sk:DW');
        case 'set_mode':
            return h('MENU, „Radio Settings“, „Dual/Tri-watch“, „Tri-watch“, ENT.', 'menu');
        case 'tag':
            return h('Kanal mit ▲/▼ wählen, dann mit ◄/► die Softkey-Seite mit ★ aufrufen und ★ drücken.', 'sk:★');
        case 'scan':
            return h(`${page2('SCAN')}. Im Display steht SCAN.`, 'sk:SCAN');
        case 'voice':
            return h('Nur zuhören. Hörst du nichts, prüfe Kanal, Lautstärke und Rauschsperre; nach einem empfangenen Notalarm geht es mit ACPT auf Kanal 16.', null);
        case 'address_added':
            return h('MENU, „DSC Settings“, „Individual ID“, Softkey ADD, die 9 Ziffern eingeben (Zeichen mit ◄/► markieren, ENT), am Ende Softkey FIN.', 'menu');
        case 'group':
            return h(`MENU, „Radio Settings“, „Channel Group“, „${goal.group}“, ENT.`, 'menu');
        case 'ch16_unavailable':
            return h('16/C drücken: Im Binnenfunk gibt es keinen Kanal 16.', 'ch16');
        case 'dsc_blocked':
            return h('MENU, „Other DSC“ wählen: DSC ist im Binnenfunk gesperrt.', 'menu');
        default:
            return h(step.help, step.target);
    }
}

function dscHint(goal, step) {
    const name = goal['address.name'] ?? step.address_name ?? goal['address.mmsi'] ?? '';
    const ch = goal.channel ? two(goal.channel) : null;
    const form = 'Softkey OTHER DSC (Seite 1)';
    const address = `„Address“, ENT, „${name}“, ENT`;

    switch (goal.kind) {
        case 'distress':
            return { help: 'Klappe offen, DISTRESS drei Sekunden gedrückt halten.', target: 'distress' };
        case 'individual': {
            const coast = String(goal['address.mmsi'] ?? '').startsWith('00');
            return {
                help: `${form}; „Type: Individual“ ist eingestellt. ${address}; ${ch ? `„Channel“, ENT, Kanal ${ch} mit ▲/▼ wählen, ENT; ` : coast ? 'bei einer Küstenfunkstelle entfällt die Kanalwahl; ' : ''}dann Softkey CALL. Die Kategorie ist Routine.`,
                target: 'sk:OTHER DSC',
            };
        }
        case 'group':
            return { help: `${form}; „Type“, ENT, „Group“, ENT. ${address}; „Channel“, ENT, Kanal ${ch} wählen, ENT; Softkey CALL.`, target: 'sk:OTHER DSC' };
        case 'all_ships':
            return { help: `${form}; „Type“, ENT, „All Ships“, ENT. „Category“, ENT, „${goal.category}“, ENT; Kanal 16 bleibt eingestellt; Softkey CALL.`, target: 'sk:OTHER DSC' };
        case 'individual_ack':
            return { help: 'Softkey ABLE: Das Gerät sendet sofort „Able to comply“ und schaltet auf den Arbeitskanal.', target: 'sk:ABLE' };
        case 'test':
            return { help: `${form}; „Type“, ENT, „Test“, ENT. ${address}; Softkey CALL.`, target: 'sk:OTHER DSC' };
        default:
            return { help: step.help, target: step.target };
    }
}
