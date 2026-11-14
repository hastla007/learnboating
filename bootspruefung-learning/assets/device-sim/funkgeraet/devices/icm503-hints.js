// Schritt-Hilfen für IC-M503 mit DS-100, aus dem geräteunabhängigen Ziel
// eines Lektionsschritts (siehe icm323-hints.js). DSC läuft über den DS-100,
// Sprechfunk über das Funkgerät.

import { NATURES } from './icm505.js';
import { DS100_NATURES } from './icm503.js';

const NATURE_INDEX = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
const natureLabel = (nature) => DS100_NATURES[NATURE_INDEX[NATURES.indexOf(nature)]] ?? nature;
const SEND = 'senden mit CALL und ENT gleichzeitig (in der Simulation: CALL, dann innerhalb von 2 Sekunden ENT)';

export function hintM503(step) {
    const goal = step.goal ?? {};
    const h = (help, target) => ({ help, target });
    const receivedAck = step.receive?.call?.type === 'individual_ack';

    switch (goal.type) {
        case 'power':
            return h(goal.on ? 'POWER am Funkgerät drücken.' : 'POWER drücken: Das Funkgerät schaltet aus.', 'power');
        case 'volume':
            return h('Den Regler VOL nach rechts drehen.', 'vol');
        case 'squelch':
            return goal.state === 'noise' ? h('Den Regler SQL ganz nach links drehen.', 'sql') : h('SQL langsam nach rechts drehen, bis das Rauschen gerade verschwindet.', 'sql');
        case 'channel':
            if (receivedAck) return h('Nach der Quittung CLR (Ton aus) und ENT am DS-100: Das Funkgerät stellt den Arbeitskanal ein.', 'dsEnt');
            if (goal.circle) return h(`Mit dem Knopf CHANNEL einen Kanal des Verkehrskreises ${goal.circle} wählen.`, 'channel');
            if (step.target === 'dial') return h('DIAL drücken: zurück zum vorherigen Kanal.', 'dial');
            if (goal.channel === 16) return h('16 am Funkgerät drücken.', 'ch16');
            if (step.target === null) return h(step.help, null);
            return h(`Mit dem Knopf CHANNEL Kanal ${String(goal.channel).padStart(2, '0')} wählen.`, 'channel');
        case 'call_channel':
            return h('CALL am Funkgerät drücken (Anrufkanal, im Display CALL).', 'call');
        case 'tx_blocked':
            return h('Mit dem Knopf CHANNEL Kanal 70 wählen, dann die Sprechtaste drücken.', 'ptt');
        case 'power_level_blocked':
            return h('HI/LO drücken: Auf diesem Kanal bleibt es bei 1 W.', 'hl');
        case 'power_level':
            if (goal.channel === 16 && goal.high) return h('16 drücken: Auf Kanal 16 schaltet das IC-M503 selbst auf 25 W.', 'ch16');
            return h(`${goal.channel ? `Kanal ${String(goal.channel).padStart(2, '0')} mit CHANNEL wählen, dann ` : ''}HI/LO drücken, bis ${goal.high ? '25W' : '1W'} im Display steht.`, 'hl');
        case 'screen':
            return goal.screen === 'menu' ? h('CALL am DS-100 drücken.', 'dsCall') : h(step.help, null);
        case 'mmsi_checked':
            return h('DS-100: CALL, mit ▼ „Set-up“, ENT, „MMSI CHECK“, ENT.', 'dsCall');
        case 'position_set':
            return h('DS-100: CALL, „Entry Position/Time“, ENT. Ziffern über das Tastenfeld eingeben (N/S und E/W mit ▲/▼), dann ENT.', 'dsCall');
        case 'utc_set':
            return h('Uhrzeit über das Tastenfeld des DS-100 eingeben, dann ENT.', 'dsEnt');
        case 'nature_selected':
            return h(`DS-100: CALL, „Distress setting“, ENT, „${natureLabel(goal.nature)}“, ENT.`, 'dsCall');
        case 'cover':
            return h('Die rote Klappe am DS-100 antippen.', 'distressCover');
        case 'dsc_sent':
            return dscHint(goal, step);
        case 'dsc_received':
            return h('Nichts drücken: Der Anruf kommt gleich.', null);
        case 'alarm_stopped':
            return h('CLR am DS-100 drücken.', 'dsClr');
        case 'call_accepted':
            return h('ENT am DS-100: Das Funkgerät stellt den angekündigten Kanal ein.', 'dsEnt');
        case 'distress_repeat_cancelled':
            return h('CLR am DS-100 drücken: Die Wiederholung endet, der DS-100 sendet eine Aufhebung des Notalarms.', 'dsClr');
        case 'watch':
            return goal.mode === 'off' ? h('DUAL erneut drücken.', 'dual') : h('DUAL am Funkgerät drücken (aus dem Set-Modus vorher mit POWER heraus).', 'dual');
        case 'set_mode':
            return h('POWER (aus), dann 16 gedrückt halten und POWER drücken (mit der Maus: 16 mit gedrückter Umschalttaste anklicken, dann POWER). Mit 16 zum Punkt „DUAL/TRI“, dann den Knopf CHANNEL drehen, bis t- erscheint.', 'power');
        case 'tag':
            return h('Kanal mit CHANNEL wählen, SCAN/TAG eine Sekunde gedrückt halten.', 'scan');
        case 'scan':
            return h('SCAN/TAG kurz drücken.', 'scan');
        case 'voice':
            return h(step.help, null);
        case 'address_added':
            return h('DS-100: CALL, „Set-up“, „Address ID“, „Add an address ID“, die 9 Ziffern über das Tastenfeld, ENT.', 'dsCall');
        case 'group':
            return h(`HI/LO gedrückt halten und DIAL drücken, bis ${goal.group} im Display steht. Mit der Maus: HI/LO mit gedrückter Umschalttaste anklicken (bleibt gedrückt), dann DIAL.`, 'hl');
        case 'ch16_unavailable':
            return h('16 drücken: Im Binnenfunk gibt es keinen Kanal 16.', 'ch16');
        case 'dsc_blocked':
            return h('CALL am DS-100 drücken: DSC ist im Binnenfunk gesperrt.', 'dsCall');
        default:
            return h(step.help, step.target);
    }
}

function dscHint(goal, step) {
    const name = goal['address.name'] ?? step.address_name ?? goal['address.mmsi'] ?? '';
    const ch = goal.channel ? String(goal.channel).padStart(2, '0') : null;
    const cat = goal.category ?? 'Routine';

    switch (goal.kind) {
        case 'distress':
            return { help: 'DISTRESS am DS-100 fünf Sekunden gedrückt halten (Klappe offen).', target: 'distress' };
        case 'individual':
            return { help: `DS-100: CALL, „Individual call“, „${name}“, ENT, Kategorie „${cat}“, ENT${ch ? `, Kanal ${ch} mit ▲/▼, ENT` : ''}; ${SEND}.`, target: 'dsCall' };
        case 'group':
            return { help: `DS-100: CALL, „Group call“, „${name}“, ENT, „${cat}“, ENT, Kanal ${ch} mit ▲/▼, ENT; ${SEND}.`, target: 'dsCall' };
        case 'all_ships':
            return { help: `DS-100: CALL, „All ships call“, „${cat}“, ENT (Kanal 16 automatisch); ${SEND}.`, target: 'dsCall' };
        case 'individual_ack':
            return { help: 'ENT am DS-100 (nicht noch einmal CLR), „Able to comply“ wählen, ENT.', target: 'dsEnt' };
        default:
            return { help: step.help, target: step.target };
    }
}
