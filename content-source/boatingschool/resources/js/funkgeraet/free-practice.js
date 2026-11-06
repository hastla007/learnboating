// Freies Üben (Stufe 2, Seefunk): Lernende lösen Ereignisse aus und
// reagieren frei darauf; Gegenstationen führen das Gespräch nach einfachen
// Regeln weiter. Zählt nicht zum Fortschritt. Namen, Texte und die
// Küstenfunkstelle „Nordsee Radio“ sind erfunden, Bremen Rescue Radio ist die
// echte Küstenfunkstelle.

const STATIONS = {
    MOEWE: { name: 'MOEWE', mmsi: '211839200', spoken: 'Moewe' },
    SEESTERN: { name: 'SEESTERN', mmsi: '211555000', spoken: 'Seestern' },
    'NORDSEE RADIO': { name: 'NORDSEE RADIO', mmsi: '002119999', spoken: 'Nordsee Radio' },
    'BREMEN RESCUE': { name: 'BREMEN RESCUE', mmsi: '002111240', spoken: 'Bremen Rescue Radio' },
};
const byMmsi = Object.fromEntries(Object.values(STATIONS).map((s) => [s.mmsi, s]));

export const FREE_EVENTS = [
    {
        title: 'Rufe empfangen',
        catalog: 'SRC',
        items: [
            { key: 'distress', label: 'Notalarm eines Schiffs in der Nähe' },
            { key: 'individual', label: 'Einzelanruf (Routine, Kanal 72)' },
            { key: 'safety', label: 'Sicherheitsanruf an alle' },
            { key: 'relay', label: 'Notalarm-Weiterleitung' },
            { key: 'geographic', label: 'Gebietsanruf (Kanal 26)' },
            { key: 'position', label: 'Positionsabfrage' },
            { key: 'polling', label: 'Polling' },
        ],
    },
    {
        title: 'Sprechfunk der Gegenstationen',
        catalog: 'SRC',
        items: [
            { key: 'ship72', label: 'Schiff spricht auf Kanal 72' },
            { key: 'coast16', label: 'Küstenfunkstelle spricht auf Kanal 16' },
        ],
    },
    {
        // Binnenfunk ohne DSC: nur Abhören (Konzept, Freies Üben).
        title: 'Binnenfunk (Abhören, Kanalgruppe ATIS)',
        catalog: 'UBI',
        items: [
            { key: 'inlandMeeting', label: 'Schiff ruft dich zur Begegnung (Kanal 10)' },
            { key: 'inlandMob', label: 'Notmeldung Mann über Bord (Kanal 10)' },
            { key: 'inlandCentreCalls', label: 'Revierzentrale ruft dich (Kanal 18)' },
            { key: 'inlandCentreSafety', label: 'Sicherheitsmeldung der Revierzentrale (Kanal 18)' },
        ],
    },
];

export function createFreePractice({ ship, schedule, receive, say, radio }) {
    const own = ship.vessel ? ship.vessel[0] + ship.vessel.slice(1).toLowerCase() : 'Kormoran';
    // Laufendes Gespräch: wer auf welchem Kanal mit dir spricht und wie
    // viele Funksprüche schon gewechselt wurden.
    let talk = null;

    // Gegenstationen im Seefunk: im Binnenmodus (ATIS) nicht zu hören.
    const voice = (station, channel, text, delay = 2500) => schedule(() => say({ from: station.name, channel, text, group: 'INT' }), delay);

    function startTalk(station, channel) {
        talk = { station, channel, turns: 0 };
    }

    const triggers = {
        distress() {
            receive({ type: 'distress', from: STATIONS.SEESTERN, nature: 'Flooding', position: [54, 14, 'N', 7, 48, 'E'] });
            voice(STATIONS.SEESTERN, 16, 'Mayday, mayday, mayday, this is Seestern, Seestern, Seestern, Delta Juliett Three Three Eight Eight, MMSI 211555000. Mayday Seestern, position 54 degrees 14 minutes north, 007 degrees 48 minutes east, I am taking in water, I require immediate assistance, 2 persons on board, over.', 5000);
        },
        individual() {
            receive({ type: 'individual', from: STATIONS.MOEWE, category: 'Routine', channel: 72 });
            talk = { station: STATIONS.MOEWE, channel: 72, turns: 0, awaitingAck: true };
        },
        safety() {
            receive({ type: 'all_ships', from: STATIONS['BREMEN RESCUE'], category: 'Safety', channel: 16 });
            voice(STATIONS['BREMEN RESCUE'], 16, 'Securite, securite, securite. All stations, all stations, all stations. This is Bremen Rescue Radio. Navigational warning: drifting container reported in position 54 degrees 12 minutes north, 007 degrees 45 minutes east. Out.', 6000);
        },
        relay() {
            receive({ type: 'distress_relay', from: STATIONS['BREMEN RESCUE'], nature: 'Fire' });
            voice(STATIONS['BREMEN RESCUE'], 16, 'Mayday relay, mayday relay, mayday relay. All stations, all stations, all stations. This is Bremen Rescue Radio. Motor yacht Albatros on fire in position 54 degrees 06 minutes north, 007 degrees 58 minutes east, 3 persons on board. Vessels in the vicinity proceed to assist and report to Bremen Rescue Radio. Over.', 5000);
        },
        geographic() {
            receive({ type: 'geographic', from: STATIONS['NORDSEE RADIO'], category: 'Safety', channel: 26 });
            voice(STATIONS['NORDSEE RADIO'], 26, 'Securite, securite, securite. All ships in the German Bight, this is Nordsee Radio. Gale warning: west seven to eight, increasing nine later. Out.', 7000);
        },
        position() {
            receive({ type: 'position_request', from: STATIONS.MOEWE });
        },
        polling() {
            receive({ type: 'polling_request', from: STATIONS['BREMEN RESCUE'] });
        },
        ship72() {
            voice(STATIONS.MOEWE, 72, 'Seestern, Seestern, this is Moewe. We are anchoring north of the dune, are you joining us? Over.', 0);
        },
        inlandMeeting() {
            schedule(() => say({ from: 'HANSESTADT', channel: 10, lang: 'de-DE', group: 'ATIS', text: `${own}, ${own}, hier Hansestadt. Ich bin Talfahrer bei Kilometer 551, Begegnung Steuerbord an Steuerbord. Kommen.` }), 0);
        },
        inlandMob() {
            schedule(() => say({ from: 'EISVOGEL', channel: 10, lang: 'de-DE', group: 'ATIS', text: 'Mayday, Mayday, Mayday, hier ist Eisvogel, Eisvogel, Eisvogel. Mayday Eisvogel, Rhein Kilometer 553, Mensch über Bord, die Person treibt ab, 3 Personen an Bord, erbitte sofortige Hilfe. Kommen.' }), 0);
        },
        inlandCentreCalls() {
            schedule(() => say({ from: 'REVIERZENTRALE OBERWESEL', channel: 18, lang: 'de-DE', group: 'ATIS', text: `${own}, ${own}, hier Revierzentrale Oberwesel. Bitte melden Sie Ihre Position. Kommen.` }), 0);
        },
        inlandCentreSafety() {
            schedule(() => say({ from: 'REVIERZENTRALE OBERWESEL', channel: 18, lang: 'de-DE', group: 'ATIS', text: 'Securite, Securite, Securite. An alle Funkstellen, hier Revierzentrale Oberwesel. Treibgut in der Fahrrinne bei Rhein Kilometer 554. Ende.' }), 0);
        },
        coast16() {
            voice(STATIONS['BREMEN RESCUE'], 16, 'All stations, all stations, all stations, this is Bremen Rescue Radio. Search and rescue exercise in progress off Helgoland. Out.', 0);
        },
    };

    // Reaktionen der Gegenstationen auf das, was am Gerät passiert.
    function handle(event) {
        if (event.type === 'dsc_sent') {
            const station = event.address ? byMmsi[event.address.mmsi] : null;
            switch (event.kind) {
                case 'individual':
                    if (station) {
                        // Ohne vorgeschlagenen Kanal (Küstenfunkstelle am
                        // IC-M323) nennt die Gegenstelle den Kanal.
                        const channel = event.channel ?? (station.mmsi.startsWith('00') ? 26 : 72);
                        schedule(() => receive({ type: 'individual_ack', from: station, channel }), 4000);
                        startTalk(station, channel);
                    }
                    break;
                case 'test':
                    if (station) schedule(() => receive({ type: 'test_ack', from: station }), 3000);
                    break;
                case 'individual_ack':
                    if (talk?.awaitingAck && event.action === 'able') {
                        talk.awaitingAck = false;
                        voice(talk.station, talk.channel, `${own}, this is ${talk.station.spoken}, how are you? We are going to Helgoland tonight. Over.`, 3500);
                        talk.turns = 1;
                    } else if (talk?.awaitingAck) {
                        talk = null;
                    }
                    break;
                case 'position_request':
                    if (station) schedule(() => receive({ type: 'position_reply', from: station, position: [54, 12, 'N', 7, 40, 'E'] }), 3000);
                    break;
                case 'polling_request':
                    if (station) schedule(() => receive({ type: 'polling_reply', from: station }), 3000);
                    break;
                case 'distress':
                    // Bremen Rescue bestätigt einen Notalarm nach kurzer Zeit.
                    schedule(() => receive({ type: 'distress_ack', from: STATIONS['BREMEN RESCUE'] }), 8000);
                    break;
                default:
                    break;
            }

            return;
        }

        if (event.type === 'spoken') {
            const text = event.text.toUpperCase();
            if (/\bMAYDAY\b/.test(text) && ! /RELAY/.test(text) && event.channel === 16) {
                voice(STATIONS['BREMEN RESCUE'], 16, `Mayday ${own}, this is Bremen Rescue Radio, received mayday. Over.`);

                return;
            }
            if (/MAYDAY RELAY/.test(text) && event.channel === 16) {
                voice(STATIONS['BREMEN RESCUE'], 16, `Mayday relay ${own}, this is Bremen Rescue Radio, received mayday relay. Over.`);

                return;
            }
            if (/PAN PAN/.test(text) && event.channel === 16) {
                voice(STATIONS['BREMEN RESCUE'], 16, `Pan pan ${own}, this is Bremen Rescue Radio, received. What assistance do you require? Over.`);

                return;
            }
            if (talk && event.channel === talk.channel && ! talk.awaitingAck && /\bOVER\b|\bKOMMEN\b/.test(text)) {
                talk.turns++;
                const reply = talk.turns <= 2
                    ? `${own}, this is ${talk.station.spoken}, received, go ahead. Over.`
                    : `${own}, this is ${talk.station.spoken}, thank you, ${talk.station.spoken} out.`;
                voice(talk.station, talk.channel, reply);
                if (talk.turns > 2) talk = null;
            } else if (talk && event.channel === talk.channel && /\bOUT\b|\bENDE\b/.test(text)) {
                talk = null;
            }
        }
    }

    return {
        trigger(key) {
            triggers[key]?.();
        },
        handle,
        get talk() {
            return talk;
        },
        radio,
    };
}
