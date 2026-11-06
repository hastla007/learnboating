// Geräteregister der Gerätesimulation: Logik, Zeichnung und Schritt-Hilfen
// je Gerät. Lektionen sind geräteunabhängig; prepareLesson() wendet die
// Anpassungen eines Geräts an (Schritt-Feld "device": {<gerät>: {...}}) und
// ersetzt Hilfetext und Markierung durch die Hilfen des Geräts.

import { createIcM505 } from './icm505.js';
import { createIcM323, createIcM423 } from './icm323.js';
import { hintM323, hintM423 } from './icm323-hints.js';
import { createIcM503 } from './icm503.js';
import { hintM503 } from './icm503-hints.js';
import { createIcM330, createIcM94DE } from './icm330.js';
import { hintM330 } from './icm330-hints.js';
import { hintM94DE } from './icm94de-hints.js';
import { createHx891 } from './hx891.js';
import { hintHx891 } from './hx891-hints.js';
import { renderDevice } from '../ui/device-svg.js';
import { renderDeviceM323, renderDeviceM423 } from '../ui/device-svg-m323.js';
import { renderDeviceM503 } from '../ui/device-svg-m503.js';
import { renderDeviceM330 } from '../ui/device-svg-m330.js';
import { renderDeviceM94DE } from '../ui/device-svg-m94de.js';
import { renderDeviceHx891 } from '../ui/device-svg-hx891.js';

// Bedienhilfe je Gerät ("Hilfe zur Bedienung" in der Seitenleiste).
const LATCH_HELP = 'Mit der Maus geht das so: die erste Taste mit gedrückter Umschalttaste anklicken (sie bleibt gedrückt), dann die zweite Taste.';
const CONTROLS = {
    'ic-m505': [
        'Tasten anklicken oder antippen; DISTRESS (5 Sekunden), POWER (Aus), DIAL (Wache) und SCAN (TAG) gedrückt halten.',
        'Drehknöpfe mit dem Mausrad, durch Ziehen nach oben/unten oder mit den Pfeiltasten; den großen Knopf drücken = ENTER.',
        `H/L gedrückt halten und DIAL drücken: Kanalgruppe wechseln (INT, ATIS für den Binnenfunk, DSC). 16/C gedrückt halten und POWER drücken: Set-Modus. ${LATCH_HELP}`,
    ],
    'ic-m323': [
        'Drehknopf VOL/SQL • PWR: 1 Sekunde halten = Ein/Aus, kurz drücken = Lautstärke, Rauschsperre, Kanalwahl; drehen mit dem Mausrad, durch Ziehen oder mit den Pfeiltasten.',
        'Softkeys unter dem Display; mit ◀/▶ die Leiste weiterblättern. MENU öffnet die Menüs, ENT bestätigt, CLEAR geht zurück.',
        '16/C kurz = Kanal 16, 1 Sekunde = Anrufkanal. DISTRESS unter der Klappe 3 Sekunden halten.',
    ],
    'ic-m503': [
        'Am Funkgerät: POWER, HI/LO, DUAL, SCAN, CALL und 16; Kanal mit dem Knopf CHANNEL (Mausrad, Ziehen oder Pfeiltasten).',
        `HI/LO gedrückt halten und DIAL drücken: Kanalgruppe. 16 gedrückt halten und POWER drücken: Set-Modus. ${LATCH_HELP}`,
        'DSC am DS-100: CALL öffnet das Menü, ▲▼ wählen, ENT bestätigt, CLR geht zurück; gesendet wird mit CALL und dann ENT. DISTRESS unter der Klappe 5 Sekunden halten.',
    ],
    'ic-m330': [
        'Drehknopf PWR/VOL/SQL: 1 Sekunde halten = Ein/Aus, einmal drücken = Lautstärke, zweimal = Rauschsperre; drehen mit dem Mausrad, durch Ziehen oder mit den Pfeiltasten.',
        'Softkeys auf Seiten: mit ◀/▶ blättern (Seite 1 DISTRESS und OTHER DSC, Seite 2 SCAN, DW, CHAN, HI/LO, Seite 3 ★ …).',
        'MENU öffnet das Menü; ▲▼ wählen, ENT oder ▶ weiter, CLR oder ◀ zurück. 16/C kurz = Kanal 16, 1 Sekunde = Anrufkanal. DISTRESS unter der Klappe 3 Sekunden halten.',
    ],
};
CONTROLS['ic-m423'] = CONTROLS['ic-m323'];
CONTROLS.hx891bte = [
    'Linke Seite: Ein/Aus 1 Sekunde halten, darunter die Sprechtaste (PTT) und SQL (drücken, dann CH▲/CH▼; 3 Sekunden halten = Monitor).',
    'CH▲/CH▼ wählen den Kanal, VOL+/VOL− die Lautstärke. 16/S kurz = Kanal 16 (erneut drücken: zurück), 1 Sekunde = Zweitkanal 09.',
    'Drei Softkeys auf Seiten: mit ◀/▶ blättern (Seite 1 DUAL WATCH, Seite 2 TX PWR und SCAN MEMORY, Seite 3 SCAN …).',
    'MENU/SET öffnet das Menü (DSC CALL, MMSI/POS INFO …), 1 Sekunde gehalten SETUP; CH▲/CH▼ wählen, Softkey SELECT weiter, BACK zurück, CLR zum Funkbetrieb. Ein Alarm verstummt mit einer beliebigen Taste. 🔑 1 Sekunde = Tastensperre.',
    'DISTRESS sitzt an der Seite unter der roten Klappe (Ansicht rechts): Klappe öffnen, DISTRESS 3 Sekunden halten.',
    'Binnenfunk (CH GROUP „ATIS“) ist gesperrt: Mit dem Handfunkgerät ist er auf Sportbooten in Deutschland verboten.',
];
CONTROLS['ic-m94de'] = [
    'Ein/Aus-Taste links am Gerät 1 Sekunde halten. Die Sprechtaste (PTT) sitzt links darüber.',
    'VOL/SQL einmal drücken = Lautstärke, zweimal = Rauschsperre; mit ▲▼◀▶ einstellen, ENT übernimmt. VOL/SQL 1 Sekunde halten = Monitor.',
    'Drei Softkeys auf Seiten: mit ◀/▶ blättern (Seite 1 DTRS, OTHER DSC, UNREAD, Seite 2 SCAN, DW, CH, Seite 3 HI/LO …).',
    'MENU öffnet das Menü (Compose Distress, Compose Other, Settings …); ▲▼ wählen, ENT oder ▶ weiter, CLR oder ◀ zurück. CLR 1 Sekunde = Tastensperre ein/aus. 16/C kurz = Kanal 16, 1 Sekunde = Anrufkanal.',
    'DISTRESS sitzt auf der Rückseite oben unter der Schutzkappe (Ansicht rechts): Kappe öffnen, DISTRESS 3 Sekunden halten.',
];

// latch: Tasten, die man für eine Zweitasten-Bedienung gedrückt hält; mit
// der Maus rasten sie per Umschalttaste + Klick ein.
export const DEVICES = {
    'ic-m505': { key: 'ic-m505', name: 'Icom IC-M505', create: createIcM505, render: renderDevice, hint: null, wheel: { key: 'enter', knob: 'channel' }, latch: ['hl', 'ch16'], controls: CONTROLS['ic-m505'] },
    'ic-m323': { key: 'ic-m323', name: 'Icom IC-M323', create: createIcM323, render: renderDeviceM323, hint: hintM323, wheel: { key: 'knob', knob: 'dial' }, controls: CONTROLS['ic-m323'] },
    // "like": Lektions-Anpassungen, Ausblendungen und Ersatzaufgaben gelten
    // wie für dieses Gerät, solange keine eigenen angegeben sind.
    'ic-m423': { key: 'ic-m423', name: 'Icom IC-M423', like: 'ic-m323', create: createIcM423, render: renderDeviceM423, hint: hintM423, wheel: { key: 'knob', knob: 'dial' }, controls: CONTROLS['ic-m423'] },
    'ic-m503': { key: 'ic-m503', name: 'Icom IC-M503 + DS-100', create: createIcM503, render: renderDeviceM503, hint: hintM503, wheel: { key: null, knob: 'channel' }, latch: ['hl', 'ch16'], controls: CONTROLS['ic-m503'] },
    // DSC Klasse D wie das IC-M323 (kein Senden von Positions-/Polling-
    // Abfragen, dafür Testanruf); Bedienung über Softkey-Seiten und das
    // Formular OTHER DSC.
    'ic-m330': { key: 'ic-m330', name: 'Icom IC-M330', like: 'ic-m323', create: createIcM330, render: renderDeviceM330, hint: hintM330, wheel: { key: 'knob', knob: 'dial' }, controls: CONTROLS['ic-m330'] },
    // handheld: Handfunkgerät (Hinweis zur Prüfung an der Feststation;
    // Lektionstexte bleiben bei „25 W“, am Gerät heißt das HI). Nur
    // Seefunk: Binnenfunk mit dem Handfunkgerät ist auf Sportbooten in
    // Deutschland verboten, die Bootsschule lässt es nicht üben (das Gerät
    // ist in Binnenkursen gesperrt, ATIS lässt sich nicht wählen).
    // Handfunkgerät eines anderen Herstellers (DSC Klasse H, mit
    // Positionsabfrage und Testanruf).
    hx891bte: { key: 'hx891bte', name: 'Standard Horizon HX891BT/E', create: createHx891, render: renderDeviceHx891, hint: hintHx891, wheel: { key: null, knob: null }, controls: CONTROLS.hx891bte, pttWhere: 'links am Gerät', handheld: true, catalogs: ['SRC'] },
    // Handfunkgerät mit der DSC-Bedienung des IC-M330.
    'ic-m94de': { key: 'ic-m94de', name: 'Icom IC-M94DE', like: 'ic-m330', create: createIcM94DE, render: renderDeviceM94DE, hint: hintM94DE, wheel: { key: null, knob: null }, controls: CONTROLS['ic-m94de'], pttWhere: 'links am Gerät', handheld: true, catalogs: ['SRC'] },
};

const shortName = (deviceKey) => DEVICES[deviceKey].name.replace(/^(Icom|Standard Horizon) /, '');

// Das Gerät und seine Vorbilder ("like", auch über mehrere Stufen), z. B.
// ic-m94de → ic-m330 → ic-m323.
export function lineage(deviceKey) {
    const chain = [];
    for (let key = deviceKey; key && DEVICES[key] && ! chain.includes(key); key = DEVICES[key].like ?? null) chain.push(key);

    return chain;
}

// Funkscheine, die ein Gerät abdeckt (ohne Angabe Seefunk und Binnenfunk).
export function deviceCatalogs(deviceKey) {
    return DEVICES[deviceKey]?.catalogs ?? ['SRC', 'UBI'];
}

export function lessonAvailable(lesson, deviceKey) {
    if (! deviceCatalogs(deviceKey).includes(lesson.catalog ?? 'SRC')) return false;

    return ! lesson.devices || lineage(deviceKey).some((key) => lesson.devices.includes(key));
}

// Angaben in Lektionstexten, die für dieses Gerät anders lauten (z. B.
// Sendeleistung 6 W statt 25 W).
export function deviceText(text, deviceKey) {
    if (typeof text !== 'string') return text;

    return (DEVICES[deviceKey]?.texts ?? []).reduce((out, [from, to]) => out.replaceAll(from, to), text);
}

export function prepareLesson(lesson, deviceKey) {
    const device = DEVICES[deviceKey];
    const chain = lineage(deviceKey);

    return {
        ...lesson,
        title: deviceText(lesson.title, deviceKey),
        intro: deviceText(lesson.intro, deviceKey),
        steps: lesson.steps.map((step) => {
            const source = chain.find((key) => step.device?.[key]);
            const override = source ? step.device[source] : {};
            const merged = { ...step, ...override };
            // Vom verwandten Gerät übernommene Texte nennen dessen Namen.
            if (source && source !== deviceKey && merged.text) merged.text = merged.text.replaceAll(shortName(source), shortName(deviceKey));
            merged.text = deviceText(merged.text, deviceKey);
            if (! device?.hint || 'help' in override) return { ...merged, help: deviceText(merged.help, deviceKey) };
            // Funksprüche behalten ihren Wortlaut als Hilfe.
            if (merged.speech) return { ...merged, target: 'ptt' };
            const hint = device.hint(merged);

            return { ...merged, help: hint.help, target: hint.target };
        }),
    };
}

// Lektionen einer Prüfungsreihe für ein Gerät (Ersatz für Aufgaben, die das
// Gerät nicht kann; auch die des Vorbilds).
export function examLessons(exam, deviceKey) {
    const source = lineage(deviceKey).find((key) => exam.substitutes?.[key]);
    const substitutes = source ? exam.substitutes[source] : {};

    return exam.lessons.map((key) => substitutes[key] ?? key);
}
