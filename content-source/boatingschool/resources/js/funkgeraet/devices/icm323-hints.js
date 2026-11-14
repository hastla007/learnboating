// Schritt-Hilfen für das IC-M323. Die Lektionen beschreiben ihr Ziel
// geräteunabhängig ("goal"); Hilfetext und markiertes Bedienteil der Daten
// gelten für das IC-M505. Für das IC-M323 entstehen sie hier aus dem Ziel.
// Funksprüche (Wortlaut) bleiben unverändert.

import { NATURES } from './icm505.js';
import { NATURE_LABELS } from './icm323.js';

const natureLabel = (nature) => NATURE_LABELS[NATURES.indexOf(nature)] ?? nature;
const channelText = (ch) => (ch === 16 ? '16/C drücken' : `mit ▲/▼ Kanal ${String(ch).padStart(2, '0')} wählen`);

export function hintM323(step) {
    const goal = step.goal ?? {};
    const h = (help, target) => ({ help, target });

    switch (goal.type) {
        case 'power':
            return goal.on ? h('Den Drehknopf VOL/SQL • PWR eine Sekunde gedrückt halten.', 'knob') : h('Den Drehknopf eine Sekunde gedrückt halten: Das Gerät schaltet aus.', 'knob');
        case 'volume':
            return h('Den Drehknopf nach rechts drehen; ohne Drücken stellt er die Lautstärke ein.', 'dial');
        case 'squelch':
            return goal.state === 'noise'
                ? h('Den Drehknopf kurz drücken, bis „SQL:“ erscheint, dann ganz nach links drehen (OPEN).', 'knob')
                : h('Im SQL-Fenster (Drehknopf kurz drücken) langsam nach rechts drehen, bis das Rauschen gerade verschwindet.', 'knob');
        case 'channel':
            if (step.receive?.call?.type === 'individual_ack') return h('Nach der Bestätigung Softkey ALARM OFF: Das Gerät stellt den Arbeitskanal ein.', 'sk:ALARM OFF');
            if (goal.circle) return h(`Mit ▲/▼ einen Kanal des Verkehrskreises ${goal.circle} wählen.`, 'up');
            if (step.target === 'dial') return h('Softkey CHAN drücken: zurück zum vorherigen Betriebskanal.', 'sk:CHAN');
            if (goal.channel === 16) return h('16/C kurz drücken.', 'ch16');
            if (step.target === null) return h(step.help, null);
            return h(`${channelText(goal.channel).replace(/^m/, 'M')}.`, 'up');
        case 'call_channel':
            return h('16/C eine Sekunde gedrückt halten; im Display erscheint CALL.', 'ch16');
        case 'tx_blocked':
            return h('Mit ▲/▼ Kanal 70 wählen, dann die Sprechtaste drücken. Das Gerät verweigert das Senden.', 'ptt');
        case 'power_level_blocked':
            return h('Softkey HI/LO drücken (oder HI/LO am Hörer). Auf diesem Kanal bleibt es bei 1 W.', 'sk:HI/LO');
        case 'power_level':
            return h(`${goal.channel ? `Zuerst ${channelText(goal.channel)}, dann ` : ''}Softkey HI/LO drücken, bis ${goal.high ? '25W' : '1W'} im Display steht.`, 'sk:HI/LO');
        case 'screen':
            return goal.screen === 'menu' ? h('MENU drücken.', 'menu') : h(step.help, null);
        case 'mmsi_checked':
            return h('MENU, „MMSI/GPS Info“ wählen (Drehknopf oder ▲/▼) und ENT.', 'menu');
        case 'position_set':
            return h('MENU, „DSC Settings“, „Position Input“, ENT. Ziffer mit dem Drehknopf wählen, ENT übernimmt und rückt weiter; ENT auf der letzten Stelle schließt ab.', 'menu');
        case 'utc_set':
            return h('Ziffern mit dem Drehknopf wählen, ENT übernimmt; ENT auf der letzten Stelle schließt ab.', 'dial');
        case 'nature_selected':
            return h(`MENU, „DSC Calls“, „Distress Call“, „${natureLabel(goal.nature)}“ wählen und ENT.`, 'menu');
        case 'cover':
            return h('Die rote Klappe antippen.', 'distressCover');
        case 'dsc_sent':
            return dscHint(goal, step);
        case 'dsc_received':
            return h('Nichts drücken: Der Anruf kommt gleich.', null);
        case 'alarm_stopped':
            return h('Softkey ALARM OFF drücken.', 'sk:ALARM OFF');
        case 'call_accepted':
            return h('Softkey ACPT drücken: Das Gerät schaltet auf den angekündigten Kanal.', 'sk:ACPT');
        case 'distress_repeat_cancelled':
            return h('Softkey CANCEL, dann CONTINUE und FINISH. Das IC-M323 sendet eine DSC-Aufhebung des Notalarms und schaltet auf Kanal 16.', 'sk:CANCEL');
        case 'watch':
            return goal.mode === 'off' ? h('Softkey DW erneut drücken.', 'sk:DW') : h('Softkey DW drücken.', 'sk:DW');
        case 'set_mode':
            return h('MENU, „Radio Settings“, „Dual/Tri-Watch“, „Tri-watch“, ENT.', 'menu');
        case 'tag':
            return h('Mit ▶ die Softkey-Leiste weiterblättern, bis ★ erscheint; Kanal mit ▲/▼ wählen und ★ drücken.', 'sk:★');
        case 'scan':
            return h('Softkey SCAN drücken. Im Display steht SCAN 16.', 'sk:SCAN');
        case 'voice':
            return h('Nur zuhören. Hörst du nichts, prüfe Kanal, Lautstärke und Rauschsperre; nach einem empfangenen Notalarm geht es erst mit ACPT auf Kanal 16.', null);
        case 'address_added':
            return h('MENU, „DSC Settings“, „Individual ID“, Softkey ADD, die 9 Ziffern eingeben (Drehknopf, ENT je Ziffer), am Ende ENT.', 'menu');
        case 'group':
            return h(`MENU, „Radio Settings“, „CHAN Group“, „${goal.group}“, ENT.`, 'menu');
        case 'ch16_unavailable':
            return h('16/C drücken: Im Binnenfunk gibt es keinen Kanal 16.', 'ch16');
        case 'dsc_blocked':
            return h('MENU, „DSC Calls“ wählen: DSC ist im Binnenfunk gesperrt.', 'menu');
        default:
            return h(step.help, step.target);
    }
}

function dscHint(goal, step) {
    const name = goal['address.name'] ?? step.address_name ?? goal['address.mmsi'] ?? '';
    const ch = goal.channel ? String(goal.channel).padStart(2, '0') : null;

    switch (goal.kind) {
        case 'distress':
            return { help: 'DISTRESS drei Sekunden gedrückt halten (Klappe offen).', target: 'distress' };
        case 'individual':
            return {
                help: `MENU, „DSC Calls“, „Individual Call“, „${name}“, ENT; ${ch ? `Kanal ${ch} (Intership CH) mit dem Drehknopf oder ▲/▼ wählen, ENT; ` : 'bei einer Küstenfunkstelle entfällt die Kanalwahl; '}dann Softkey CALL. Die Kategorie ist am IC-M323 immer Routine.`,
                target: 'menu',
            };
        case 'group':
            return { help: `MENU, „DSC Calls“, „Group Call“, „${name}“, ENT; Kanal ${ch} wählen, ENT; Softkey CALL.`, target: 'menu' };
        case 'all_ships':
            return { help: `MENU, „DSC Calls“, „All Ships Call“, „${goal.category}“, ENT; Kanal 16 mit ENT bestätigen; Softkey CALL.`, target: 'menu' };
        case 'individual_ack':
            return { help: 'Softkey ACK, „Able to Comply“ wählen, ENT, dann Softkey CALL.', target: 'sk:ACK' };
        case 'test':
            return { help: 'MENU, „DSC Calls“, „Test Call“, Station wählen, ENT, Softkey CALL.', target: 'menu' };
        default:
            return { help: step.help, target: step.target };
    }
}

// IC-M423: gleiche Bedienung, der Rücksprung-Softkey heißt CH/WX.
export function hintM423(step) {
    const hint = hintM323(step);

    return {
        help: hint.help?.replace(/Softkey CHAN\b/g, 'Softkey CH/WX').replace(/IC-M323/g, 'IC-M423'),
        target: hint.target === 'sk:CHAN' ? 'sk:CH/WX' : hint.target,
    };
}
