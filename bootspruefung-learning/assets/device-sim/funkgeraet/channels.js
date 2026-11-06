// Kanaltabellen der Gerätesimulation. INT nach der Kanalliste der
// Icom-Anleitungen (internationale Kanäle 01–28, 60–88), ATIS nach dem
// Kanalplan Binnenschifffahrtsfunk (RAINWAT; siehe Konzept). Frequenzen
// werden nicht gebraucht, nur was das Gerät anzeigt und erzwingt.

const DUPLEX_INT = new Set([1, 2, 3, 4, 5, 7, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 60, 61, 62, 63, 64, 65, 66, 78, 79, 80, 81, 82, 83, 84, 85, 86]);

const INT_NAMES = {
    6: 'SAFETY', 8: 'COMMERCIAL', 9: 'CALLING', 10: 'COMMERCIAL', 13: 'BRIDGE COM', 15: 'ON BOARD',
    16: 'DISTRESS', 17: 'ON BOARD', 67: 'SAR', 69: 'PLEASURE', 70: 'DSC', 72: 'SHIP-SHIP', 73: 'SAFETY', 77: 'SHIP-SHIP',
};

function intChannels() {
    const list = [];
    for (let ch = 1; ch <= 28; ch++) list.push(ch);
    for (let ch = 60; ch <= 88; ch++) list.push(ch);

    return list.map((ch) => ({
        number: ch,
        label: String(ch).padStart(2, '0'),
        duplex: DUPLEX_INT.has(ch),
        // 15/17 Bordverkehr und 75/76 navigationsbezogen: höchstens 1 W.
        lowPowerOnly: [15, 17, 75, 76].includes(ch),
        // Kanal 70 ist dem DSC vorbehalten, Sprechfunk ist dort nicht möglich.
        receiveOnly: ch === 70,
        name: INT_NAMES[ch] ?? (DUPLEX_INT.has(ch) ? 'PORT OPR' : 'INTL'),
    }));
}

// Verkehrskreise nach dem Handbuch Binnenschifffahrtsfunk und der
// Prüfungspraxis (Vorgabe der Bootsschule): Schiff–Schiff 06, 08, 10, 72,
// 77; Schiff–Hafen 11–14, 71, 74; Nautische Information 18–20, 22–28, 73,
// 78–82, 84–86 (kein Kanal 21); Funkverkehr an Bord 15, 17.
const ATIS_PLAN = [
    [6, 'Schiff-Schiff'], [8, 'Schiff-Schiff'], [10, 'Schiff-Schiff'], [72, 'Schiff-Schiff'], [77, 'Schiff-Schiff'],
    [11, 'Schiff-Hafen'], [12, 'Schiff-Hafen'], [13, 'Schiff-Hafen'], [14, 'Schiff-Hafen'], [71, 'Schiff-Hafen'], [74, 'Schiff-Hafen'],
    [15, 'An Bord'], [17, 'An Bord'],
    ...[18, 19, 20, 22, 23, 24, 25, 26, 27, 28, 73, 78, 79, 80, 81, 82, 84, 85, 86].map((ch) => [ch, 'Nautische Information']),
];

function atisChannels() {
    return ATIS_PLAN.slice().sort((a, b) => a[0] - b[0]).map(([ch, circle]) => ({
        number: ch,
        label: String(ch).padStart(2, '0'),
        duplex: circle === 'Nautische Information' && ch >= 18 && ch !== 73,
        // Im Binnenfunk sind 25 W nur in der Nautischen Information zulässig.
        lowPowerOnly: circle !== 'Nautische Information',
        receiveOnly: false,
        name: circle === 'Nautische Information' ? 'NAUT INFO' : circle === 'Schiff-Hafen' ? 'SHIP-PORT' : circle === 'An Bord' ? 'ON BOARD' : 'SHIP-SHIP',
        circle,
    }));
}

export const CHANNEL_GROUPS = {
    INT: intChannels(),
    // Die DSC-Gruppe deutscher Geräte entspricht für den Sprechfunk den
    // internationalen Kanälen.
    DSC: intChannels(),
    ATIS: atisChannels(),
};

export function channelInfo(group, number) {
    return CHANNEL_GROUPS[group].find((c) => c.number === number) ?? null;
}

// Arbeitskanal für DSC-Anrufe (Intership/ITU CH): Kanäle, auf denen
// gesendet werden darf (nicht Kanal 70), für Schiff-Schiff nur Simplex.
// coast: Anruf an eine Küstenfunkstelle, deren Kanäle sind Duplex.
export function stepIntership(number, direction, { coast = false } = {}) {
    const list = CHANNEL_GROUPS.INT.filter((c) => ! c.receiveOnly && (coast || ! c.duplex));
    const index = list.findIndex((c) => c.number === number);
    if (index === -1) {
        // Nicht in der Liste (z. B. Duplexkanal): nächster Kanal in Drehrichtung.
        const ahead = direction > 0 ? list.find((c) => c.number > number) : [...list].reverse().find((c) => c.number < number);

        return (ahead ?? list[direction > 0 ? 0 : list.length - 1]).number;
    }

    return list[(index + direction + list.length) % list.length].number;
}

export function stepChannel(group, number, direction) {
    const list = CHANNEL_GROUPS[group];
    const index = list.findIndex((c) => c.number === number);
    const next = (index + direction + list.length) % list.length;

    return list[index === -1 ? 0 : next].number;
}
