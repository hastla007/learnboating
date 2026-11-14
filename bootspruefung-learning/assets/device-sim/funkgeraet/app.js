// Oberfläche der Gerätesimulation: verbindet Gerätelogik, Zeichnung,
// Lektionen, Prüfungsmodus, Freies Üben und Server. Konfiguration
// (Lektionen, Prüfungsreihen, Übungsschiff, URLs, Fortschritt) kommt aus
// einem JSON-Block der Seite; Gesprochenes bewertet der Server mit denselben
// Regeln wie die Praxis-Prüfungssimulation. Gegenstationen sprechen mit der
// Sprachausgabe des Browsers, bis Sprecheraufnahmen vorliegen.

import { DEVICES, deviceCatalogs, deviceText, examLessons, lessonAvailable, prepareLesson } from './devices/index.js';
import { createLessonRun, EXAM_ATTEMPTS, missLabel } from './lessons.js';
import { createFreePractice, FREE_EVENTS } from './free-practice.js';

const LEVEL_LABELS = { 1: 'Mit Hilfe', 2: 'Nur Hinweis', 3: 'Allein' };
const MODES = { lessons: 'Lektionen', exam: 'Prüfung', free: 'Freies Üben' };
const MISSED = {
    off: () => 'Dein Gerät ist aus – nichts zu hören.',
    channel: (voice, radio) => `Auf Kanal ${voice.channel} wurde gesprochen, dein Gerät steht auf Kanal ${radio.state.channel}.`,
    volume: () => 'Die Lautstärke ist ganz zugedreht.',
    group: (voice, radio) => (voice.group === 'ATIS' ? `Das war Binnenfunk (Kanalgruppe ATIS), dein Gerät steht in der Kanalgruppe ${radio.state.group}.` : 'Das war Seefunk, dein Gerät steht im Binnenmodus (ATIS).'),
    squelch: () => 'Die Rauschsperre ist zu weit zugedreht – schwache Stationen werden weggedrückt.',
};
const START = { power: true, vol: 5, sql: 3, channel: 16, position: [54, 10.0, 'N', 7, 52.0, 'E'], utc: '12:30' };
const START_INLAND = { power: true, vol: 5, sql: 3, group: 'ATIS', channel: 10, highPower: false };
const SERVICES = { SRC: 'Seefunk', UBI: 'Binnenfunk' };

// Darstellung, die Tailwind-Klassen nicht abdecken: Vollbild (echt über die
// Fullscreen-API oder ersatzweise als fester Rahmen), Gerät an die
// Fensterhöhe angepasst, blinkende Displayzeilen.
const STYLE = `
.fg-root:fullscreen, .fg-root.fg-pseudo-full { background: #f1f5f9; overflow-y: auto; padding: 1rem 1.25rem; }
.fg-root.fg-pseudo-full { position: fixed; inset: 0; z-index: 60; }
@media (prefers-color-scheme: dark) { .fg-root:fullscreen, .fg-root.fg-pseudo-full { background: #0f172a; } }
.fg-root:fullscreen .fg-device svg, .fg-root.fg-pseudo-full .fg-device svg { max-height: calc(100vh - 9rem); }
.fg-root.fg-wide .fg-device svg { max-height: calc(100vh - 11rem); }
.fg-root:fullscreen .fg-device, .fg-root.fg-pseudo-full .fg-device { min-height: calc(100vh - 12rem); display: flex; align-items: center; justify-content: center; }
.fg-root:fullscreen .fg-sidebar, .fg-root.fg-pseudo-full .fg-sidebar { max-height: calc(100vh - 5.5rem); overflow-y: auto; }
@keyframes fg-blink { 50% { opacity: 0.25; } }
.fg-blink { animation: fg-blink 1s steps(1) infinite; }
.fg-locked svg { filter: grayscale(1); opacity: 0.35; }
`;

function addStyle() {
    if (document.getElementById('fg-style')) return;
    const style = document.createElement('style');
    style.id = 'fg-style';
    style.textContent = STYLE;
    document.head.appendChild(style);
}

// Merken, ob die Lektionen ausgeblendet sind (nur Komfort, darf fehlen).
function readPref(key, fallback) {
    try { return window.localStorage.getItem(key) ?? fallback; } catch { return fallback; }
}
function writePref(key, value) {
    try { window.localStorage.setItem(key, value); } catch { /* egal */ }
}

function h(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs)) {
        if (key === 'class') node.className = value;
        else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
        else if (value !== null && value !== undefined && value !== false) node.setAttribute(key, value === true ? '' : value);
    }
    for (const child of children.flat()) {
        if (child === null || child === undefined || child === false) continue;
        node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
    }

    return node;
}

async function postJson(url, body) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
        body: JSON.stringify(body),
    });
    if (! response.ok) throw new Error(`HTTP ${response.status}`);

    return response.json();
}

const button = (label, onclick, extra = '') => h('button', { type: 'button', class: `text-xs px-2 py-1 rounded border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 ${extra}`, onclick }, label);

export function mount(root, config) {
    // Gerätewahl: zuletzt gewähltes Gerät (nur Komfort, darf fehlen).
    // Funkscheine des Kurses; je Gerät nur die, die das Gerät abdeckt (die
    // Handfunkgeräte nur Seefunk). Geräte ohne passenden Funkschein fehlen –
    // außer Handfunkgeräten: Sie erscheinen gesperrt mit der Begründung.
    const courseCatalogs = config.catalogs ?? ['SRC'];
    const deviceList = (config.devices ?? [{ key: 'ic-m505', name: 'Icom IC-M505' }])
        .filter((d) => DEVICES[d.key] && (DEVICES[d.key].handheld || courseCatalogs.some((c) => deviceCatalogs(d.key).includes(c))));
    // Zu Beginn ein Gerät, das sich bedienen lässt.
    let deviceKey = (deviceList.find((d) => courseCatalogs.some((c) => deviceCatalogs(d.key).includes(c))) ?? deviceList[0]).key;
    try {
        const saved = window.localStorage.getItem('geraetesimulation.device');
        if (deviceList.some((d) => d.key === saved)) deviceKey = saved;
    } catch { /* ohne Speicher: erstes Gerät */ }
    const progressByDevice = Object.fromEntries(deviceList.map((d) => [d.key, { ...(config.progress?.[d.key] ?? {}) }]));
    const progressOf = () => progressByDevice[deviceKey];
    const deviceName = () => DEVICES[deviceKey].name;
    const visibleLessons = () => config.lessons.filter((l) => lessonAvailable(l, deviceKey));
    const exams = config.exams ?? {};
    const lessonByKey = Object.fromEntries(config.lessons.map((l) => [l.key, l]));
    const catalogs = () => courseCatalogs.filter((c) => deviceCatalogs(deviceKey).includes(c));
    // Ausgangslage für Freies Üben und den Start: Seefunk, in reinen
    // Binnenkursen der Binnenmodus.
    const defaultStart = () => (catalogs().includes('SRC') ? START : START_INLAND);
    // Prüfungsreihen der Funkscheine, die das gewählte Gerät abdeckt.
    const visibleExams = () => Object.entries(exams).filter(([, info]) => catalogs().includes(info.catalog ?? 'SRC'));
    const speechLang = () => run?.lesson.lang ?? config.speechLang ?? 'en-GB';
    let mode = 'lessons';
    let run = null;
    let lastSpeech = null;
    let lastMiss = null;
    let exam = null;
    let free = null;
    let audio = null;
    // Zeitsprünge im Freien Üben verschieben die Uhr des Geräts.
    let timeOffset = 0;
    const clock = () => Date.now() + timeOffset;
    const log = [];

    // Zeitgesteuerte Ereignisse der laufenden Lektion bzw. Übung; beim Wechsel
    // werden sie verworfen, damit kein alter Anruf in die neue Übung platzt.
    const timers = new Set();
    const schedule = (fn, ms) => {
        const id = setTimeout(() => { timers.delete(id); fn(); }, ms);
        timers.add(id);
    };
    const clearTimers = () => { timers.forEach(clearTimeout); timers.clear(); window.speechSynthesis?.cancel(); };

    // Handfunkgeräte: kein Binnenfunk (ATIS lässt sich nicht wählen).
    const makeRadio = () => DEVICES[deviceKey].create({ own: config.ship, addressBook: config.addressBook, directory: config.directory ?? [], clock, emit: (event) => onRadioEvent(event), inland: deviceCatalogs(deviceKey).includes('UBI') });
    let radio = makeRadio();

    // --- Aufbau ---------------------------------------------------------------
    const deviceBox = h('div', { class: 'fg-device bg-slate-100 dark:bg-slate-900 rounded-2xl p-3 sm:p-5' });
    // Gesperrtes Handfunkgerät: Gerät ausgegraut und nicht bedienbar, darüber
    // ein Schloss-Schild; die Begründung steht im Kasten über dem Gerät.
    const lockBadge = h('div', { class: 'hidden absolute inset-0 flex items-center justify-center p-4 pointer-events-none' },
        h('div', { class: 'max-w-sm text-center rounded-xl bg-white/95 dark:bg-slate-800/95 shadow-lg border border-red-200 dark:border-red-800 px-4 py-3' },
            h('p', { class: 'text-2xl', 'aria-hidden': 'true' }, '🔒'),
            h('p', { class: 'font-semibold text-red-800 dark:text-red-200' }, 'Binnenfunk mit dem Handfunkgerät nicht erlaubt'),
            h('p', { class: 'text-xs text-slate-600 dark:text-slate-300 mt-1' }, 'Auf Sportbooten in Deutschland verboten – Begründung oben.')));
    const deviceWrap = h('div', { class: 'relative' }, deviceBox, lockBadge);
    const inlandCard = h('div', { class: 'hidden mb-3 p-4 rounded-lg border border-red-200 bg-red-50 text-red-950 dark:border-red-800 dark:bg-red-950/40 dark:text-red-50 text-sm space-y-2', role: 'alert' });
    const sidebar = h('aside', { class: 'fg-sidebar bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-4 space-y-4 text-sm' });
    const typeBox = h('form', { class: 'mt-3 flex gap-2', onsubmit: (e) => { e.preventDefault(); sendTyped(); } },
        h('input', { type: 'text', name: 'spruch', class: 'flex-1 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm', placeholder: 'Funkspruch eintippen (statt sprechen)', 'aria-label': 'Funkspruch eintippen' }),
        h('button', { type: 'submit', class: 'px-3 py-2 rounded-lg bg-slate-800 text-white text-sm' }, 'Senden'),
    );
    // Kurze Bedienhilfe zur Sprechtaste direkt unter dem Gerät.
    const pttNote = h('p', { class: 'mt-2 text-xs text-slate-600 dark:text-slate-400' });
    const pttWhere = () => DEVICES[deviceKey].pttWhere ?? 'am Hörer';
    const showPttNote = () => pttNote.replaceChildren(
        h('strong', { class: 'font-semibold' }, 'Sprechtaste (PTT): '),
        `${pttWhere()} mit der Maus gedrückt halten oder die Leertaste halten.`);
    const transcript = h('p', { class: 'mt-2 text-xs text-slate-600 dark:text-slate-400 min-h-[1rem]', 'aria-live': 'polite' });
    const radioLog = h('div', { class: 'mt-3 rounded-xl bg-white dark:bg-slate-800 shadow-sm p-3 text-xs space-y-1', 'aria-live': 'polite' });
    // Leiste über dem Gerät: Lektionen ein-/ausblenden und Vollbild. Beides
    // geht auch im Vollbild; ausgeblendet zeigt eine Zeile den aktuellen Schritt.
    addStyle();
    root.classList.add('fg-root');
    let lessonsHidden = readPref('geraetesimulation.lessons', 'shown') === 'hidden';
    const pill = (active) => `inline-flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-full border ${active ? 'bg-slate-800 text-white border-slate-800 dark:bg-slate-200 dark:text-slate-900 dark:border-slate-200' : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50 dark:bg-slate-800 dark:text-slate-200 dark:border-slate-600 dark:hover:bg-slate-700'}`;
    const lessonsButton = h('button', { type: 'button', onclick: () => setLessonsHidden(! lessonsHidden) });
    const fullButton = h('button', { type: 'button', onclick: () => toggleFull() });
    // Hinweis beim Wechsel auf ein Handfunkgerät: Die Prüfung findet an einer
    // Feststation statt; Lektionen und Prüfung bleiben bei „25 W“.
    let handheldNoteClosed = false;
    const handheldNote = h('div', { class: 'hidden mb-3 p-3 rounded-lg border border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-800 dark:bg-sky-900/30 dark:text-sky-100 text-sm', role: 'note' });
    function showHandheldNote() {
        const show = Boolean(DEVICES[deviceKey].handheld) && ! handheldNoteClosed && ! locked();
        handheldNote.classList.toggle('hidden', ! show);
        if (! show) return;
        handheldNote.replaceChildren(
            h('div', { class: 'flex items-start gap-2' },
                h('div', { class: 'flex-1 space-y-1' },
                    h('p', { class: 'font-semibold' }, `Hinweis: ${DEVICES[deviceKey].name} ist ein Handfunkgerät`),
                    h('p', {}, 'In der praktischen SRC-Prüfung wird an einer Feststation geprüft. Für Not-, Dringlichkeits- und Sicherheitsmeldungen wird dort volle Leistung mit „25 W“ verlangt – auch wenn du am Handfunkgerät übst. Die Lektionen und der Prüfungsmodus bleiben deshalb bei „25 W“; am Handfunkgerät wählst du dafür HI (das Gerät sendet höchstens 6 W).'),
                    h('p', {}, 'Für die Prüfungsvorbereitung übe besser an einem der fest eingebauten Geräte (IC-M505, IC-M323, IC-M423, IC-M503 + DS-100, IC-M330): Sie entsprechen den Geräten in den meisten Prüfungen.'),
                    // Binnenfunk (Deutschland): am Handfunkgerät gesperrt.
                    courseCatalogs.includes('UBI') ? h('p', {}, h('strong', { class: 'font-semibold' }, 'Binnenfunk: '), 'Am Handfunkgerät gesperrt – auf Sportbooten ist Binnenfunk mit dem Handfunkgerät in Deutschland verboten. Binnenfunk-Lektionen und die UBI-Prüfung übst du an einem fest eingebauten Gerät.') : null,
                ),
                h('button', { type: 'button', class: 'text-xs underline shrink-0', onclick: () => { handheldNoteClosed = true; showHandheldNote(); } }, 'Ausblenden'),
            ),
        );
    }
    // --- Binnenfunk am Handfunkgerät gesperrt ------------------------------
    // Im reinen Binnenkurs ist das Handfunkgerät ganz gesperrt. Im Seefunk
    // erscheint der Kasten, wenn jemand ATIS wählen will oder „Warum?“ antippt.
    let inlandInfo = null; // null | 'atis' | 'info'
    const lockedByCourse = () => catalogs().length === 0;
    const locked = () => lockedByCourse() || inlandInfo !== null;
    const fixedDevices = () => deviceList.filter((d) => ! DEVICES[d.key]?.handheld && deviceCatalogs(d.key).includes('UBI'));
    function showInlandLock() {
        const on = locked();
        deviceBox.classList.toggle('fg-locked', on);
        deviceBox.inert = on;
        typeBox.inert = on;
        typeBox.classList.toggle('opacity-40', on);
        pttNote.classList.toggle('opacity-40', on);
        lockBadge.classList.toggle('hidden', ! on);
        inlandCard.classList.toggle('hidden', ! on);
        handheldNote.classList.toggle('hidden', on || ! DEVICES[deviceKey].handheld || handheldNoteClosed);
        if (! on) return;
        if (radio.state.tx) pttUp();
        const name = deviceName();
        const intro = lockedByCourse()
            ? `Dieser Kurs bereitet auf den Binnenfunk (UBI) vor. Binnenfunk mit einem Handfunkgerät ist auf Sportbooten in Deutschland nicht erlaubt – deshalb ist das ${name} hier gesperrt. Übe an einem fest eingebauten Gerät:`
            : inlandInfo === 'atis'
                ? `Du wolltest die Kanalgruppe ATIS (Binnenfunk) einstellen. Binnenfunk mit einem Handfunkgerät ist auf Sportbooten in Deutschland nicht erlaubt – das ${name} bleibt im Seefunk (INT). Den Binnenfunk übst du an einem fest eingebauten Gerät:`
                : `Binnenfunk-Lektionen und die UBI-Prüfung gibt es am ${name} nicht: Binnenfunk mit einem Handfunkgerät ist auf Sportbooten in Deutschland nicht erlaubt. Den Binnenfunk übst du an einem fest eingebauten Gerät:`;
        const point = (title, text) => h('li', {}, h('strong', { class: 'font-semibold' }, `${title}: `), text);
        inlandCard.replaceChildren(
            h('p', { class: 'font-semibold text-base' }, '🔒 Binnenfunk am Handfunkgerät: auf Sportbooten verboten'),
            h('p', {}, intro),
            h('div', { class: 'flex flex-wrap gap-2' },
                fixedDevices().map((d) => h('button', { type: 'button', class: 'text-xs font-medium px-3 py-1.5 rounded-full bg-white text-slate-800 border border-slate-300 hover:bg-slate-50 dark:bg-slate-800 dark:text-slate-100 dark:border-slate-600', onclick: () => switchDevice(d.key) }, d.name.replace(/^Icom /, ''))),
                lockedByCourse() ? null : h('button', { type: 'button', class: 'text-xs font-medium px-3 py-1.5 rounded-full bg-slate-800 text-white dark:bg-slate-200 dark:text-slate-900', onclick: () => { inlandInfo = null; showInlandLock(); renderSidebar(); } }, `Verstanden – Seefunk am ${name.replace(/^(Icom|Standard Horizon) /, '')} weiter üben`)),
            h('ul', { class: 'list-disc pl-5 space-y-1' },
                point('Verbot', 'Auf deutschen Binnenschifffahrtsstraßen sind UKW-Handfunkgeräte auf Kleinfahrzeugen (Sportboote unter 20 m Länge) im Binnenschifffahrtsfunk nicht zugelassen.'),
                point('Was vorgeschrieben ist', 'Wer binnen am Funkverkehr teilnimmt – freiwillig oder weil es vorgeschrieben ist, etwa an Schleusen oder bei bestimmten Schlechtwetterregeln –, braucht eine fest eingebaute UKW-Anlage mit automatischer Senderkennung (ATIS) und das UBI.'),
                point('Einzige Ausnahme', 'Schiffe über 20 m dürfen Handfunkgeräte benutzen, aber nur im Verkehrskreis „Funkverkehr an Bord“ (z. B. Absprachen zwischen Brücke und Vorschiff beim Ankern) und mit höchstens 1 W. Für Schleusen, Brücken und Revierzentralen (Nautische Information) sind sie nie zugelassen.'),
                point('Grundlage', 'Regionale Vereinbarung über den Binnenschifffahrtsfunk (RAINWAT); in Deutschland teilt die Bundesnetzagentur die Frequenzen zu. RAINWAT erlaubt den Staaten inzwischen, Handfunkgeräte für Sportboote freizugeben – Deutschland hält aber, anders als etwa die Niederlande, am Verbot fest.'),
                point('Warum', 'Die Kanäle sollen für die Berufsschifffahrt und Sicherheitsmeldungen frei bleiben. Ein Handfunkgerät sendet aus geringer Höhe: Binnen (Ufer, Bebauung, Berge) ist es schlecht zu verstehen, blockiert den Kanal im Nahbereich aber trotzdem.'),
                point('Prüfung', 'Die praktische UBI-Prüfung findet an einer fest eingebauten Anlage statt (Notmeldung mit „25 W“).'),
                point('Seefunk', `Auf See ist ein Handfunkgerät als Haupt- oder Zweitgerät erlaubt.${catalogs().includes('SRC') ? ` Den Seefunk (SRC) übst du am ${name} weiter.` : ''}`),
            ),
            h('details', {},
                h('summary', { class: 'cursor-pointer font-semibold' }, 'Die Verkehrskreise im Binnenfunk'),
                h('table', { class: 'mt-2 w-full text-xs' },
                    h('thead', {}, h('tr', { class: 'text-left' }, h('th', { class: 'pr-2 py-1' }, 'Verkehrskreis'), h('th', { class: 'pr-2 py-1' }, 'Wer spricht mit wem'), h('th', { class: 'py-1' }, 'Für Sportboote'))),
                    h('tbody', {}, [
                        ['Schiff – Schiff', 'Absprachen zwischen Booten und Schiffen, z. B. beim Überholen', 'sehr wichtig'],
                        ['Nautische Information', 'Revierzentralen, Schleusen und Brücken', 'sehr wichtig'],
                        ['Schiff – Hafenbehörde', 'Hafenmeister und Behörden an Land', 'wichtig beim Einlaufen'],
                        ['Funkverkehr an Bord', 'Absprachen auf dem eigenen Schiff (nur über 20 m)', 'ohne Bedeutung – nur hier wären Handfunkgeräte erlaubt'],
                        ['Öffentlicher Nachrichtenaustausch', 'Verbindung ins Telefonnetz', 'praktisch bedeutungslos'],
                    ].map((row) => h('tr', { class: 'border-t border-red-200 dark:border-red-800 align-top' }, row.map((cell) => h('td', { class: 'pr-2 py-1' }, cell))))))),
        );
    }
    const stepLine = h('p', { class: 'hidden mb-2 px-3 py-2 rounded-lg bg-amber-50 text-amber-900 dark:bg-amber-900/30 dark:text-amber-100 text-sm', 'aria-live': 'polite' });
    const toolbar = h('div', { class: 'flex items-center justify-end gap-2 mb-3' }, lessonsButton, fullButton);
    const grid = h('div', {},
        h('div', {}, handheldNote, inlandCard, stepLine, deviceWrap, pttNote, typeBox, transcript, radioLog),
        sidebar,
    );
    root.replaceChildren(toolbar, grid);

    const isFull = () => document.fullscreenElement === root || root.classList.contains('fg-pseudo-full');
    function showLayout() {
        grid.className = lessonsHidden ? 'grid gap-4' : 'grid lg:grid-cols-[minmax(0,1fr)_22rem] gap-4';
        sidebar.classList.toggle('hidden', lessonsHidden);
        root.classList.toggle('fg-wide', lessonsHidden);
        lessonsButton.className = pill(! lessonsHidden);
        lessonsButton.setAttribute('aria-pressed', lessonsHidden ? 'false' : 'true');
        lessonsButton.replaceChildren(h('span', { 'aria-hidden': 'true' }, '☰'), lessonsHidden ? 'Lektionen einblenden' : 'Lektionen ausblenden');
        fullButton.className = pill(false);
        fullButton.replaceChildren(h('span', { 'aria-hidden': 'true' }, '⛶'), isFull() ? 'Vollbild beenden' : 'Vollbild');
        showStepLine();
    }
    function setLessonsHidden(hidden) {
        lessonsHidden = hidden;
        writePref('geraetesimulation.lessons', hidden ? 'hidden' : 'shown');
        showLayout();
    }
    function toggleFull() {
        if (document.fullscreenElement === root) {
            document.exitFullscreen?.();
        } else if (root.classList.contains('fg-pseudo-full')) {
            root.classList.remove('fg-pseudo-full');
            showLayout();
        } else if (document.fullscreenEnabled && root.requestFullscreen) {
            // Ohne Fullscreen-API (z. B. iPhone): fester Rahmen über der Seite.
            root.requestFullscreen().catch(() => { root.classList.add('fg-pseudo-full'); showLayout(); });
        } else {
            root.classList.add('fg-pseudo-full');
            showLayout();
        }
    }
    document.addEventListener('fullscreenchange', showLayout);
    // Ersatz-Vollbild mit Esc beenden.
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && root.classList.contains('fg-pseudo-full')) {
            root.classList.remove('fg-pseudo-full');
            showLayout();
        }
    });
    // Aktueller Schritt, solange die Lektionen ausgeblendet sind.
    function showStepLine() {
        const status = lessonsHidden && run ? run.status() : null;
        stepLine.classList.toggle('hidden', ! status);
        if (! status) return;
        const where = exam ? `Aufgabe ${exam.index + 1} von ${exam.lessons.length}` : `Schritt ${Math.min(status.index + 1, status.total)} von ${status.total}`;
        // In der Prüfung verrät die Zeile die Schritte nicht.
        const text = status.done ? (status.failed ? 'Aufgabe nicht bestanden.' : 'Geschafft.') : exam ? '' : status.text ?? '';
        stepLine.replaceChildren(...[
            h('strong', { class: 'font-semibold' }, `${run.lesson.title} · ${status.done ? 'fertig' : where}${text ? ': ' : ''}`),
            text,
            ! exam && status.help ? h('span', { class: 'block text-xs mt-1 opacity-80' }, status.help) : null,
            status.done ? stepLineActions() : null,
        ].filter((child) => child !== null && child !== ''));
    }
    // Fertig: wie geht es weiter? Dieselben Knöpfe wie in der Seitenleiste;
    // was nur dort zu sehen ist (Auswertung, Lektionsliste), blendet sie ein.
    function stepLineActions() {
        const action = (label, onclick) => h('button', { type: 'button', class: 'text-xs font-semibold px-3 py-1 rounded-full bg-amber-900 text-amber-50 dark:bg-amber-100 dark:text-amber-950', onclick }, label);
        const showLessons = () => setLessonsHidden(false);
        let buttons;
        if (exam) {
            const last = exam.index === exam.lessons.length - 1;
            buttons = [last
                ? action('Prüfung auswerten', () => { showLessons(); finishExam(); })
                : action('Nächste Aufgabe', () => { exam.index++; startExamTask(); })];
        } else {
            const lesson = run.lesson;
            const level = run.level;
            buttons = [
                level < 3 ? action(`Weiter: ${LEVEL_LABELS[level + 1]}`, () => startRun(lesson, { level: level + 1 })) : null,
                action('Alle Lektionen', () => { run = null; clearTimers(); showLessons(); renderSidebar(); render(); }),
            ];
        }

        return h('span', { class: 'flex flex-wrap gap-2 mt-2' }, buttons);
    }
    let device = null;

    // --- Ton und Stimmen ------------------------------------------------------
    const ensureAudio = () => {
        if (! audio && window.AudioContext) audio = new AudioContext();
    };

    function beep(frequency = 1800, ms = 120) {
        if (! audio || radio.state.vol === 0) return;
        const osc = audio.createOscillator();
        const gain = audio.createGain();
        gain.gain.value = 0.04 * radio.state.vol;
        osc.frequency.value = frequency;
        osc.connect(gain).connect(audio.destination);
        osc.start();
        osc.stop(audio.currentTime + ms / 1000);
    }

    const pitches = {};
    function speakAloud(text, from, lang = 'en-GB') {
        const synth = window.speechSynthesis;
        if (! synth || ! window.SpeechSynthesisUtterance) return;
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = lang;
        const voice = synth.getVoices().find((v) => v.lang === lang) ?? synth.getVoices().find((v) => v.lang?.startsWith(lang.slice(0, 2)));
        if (voice) utterance.voice = voice;
        // Jede Gegenstation klingt etwas anders.
        pitches[from] ??= 0.8 + (Object.keys(pitches).length % 4) * 0.15;
        utterance.pitch = pitches[from];
        utterance.volume = Math.min(1, radio.state.vol / 7);
        synth.speak(utterance);
    }

    function addLog(entry) {
        log.unshift(entry);
        log.length = Math.min(log.length, 8);
        renderLog();
    }

    // Eine Gegenstation spricht: hörbar nach den Regeln der Gerätelogik.
    function say(voice) {
        if (! voice.group && run) voice = { ...voice, group: run.lesson.catalog === 'UBI' ? 'ATIS' : 'INT' };
        const result = radio.hear(voice);
        if (result.heard) {
            addLog({ who: voice.from, channel: voice.channel, text: voice.text });
            speakAloud(voice.text, voice.from, voice.lang ?? speechLang());
        } else {
            const showHint = mode === 'free' || (mode === 'lessons' && run && run.level <= 2);
            if (showHint) addLog({ hint: MISSED[result.reason](voice, radio) });
        }
        render();

        return result;
    }

    // --- Bedienung ------------------------------------------------------------
    const turnKnob = (knob, steps) => {
        radio.turn(knob, steps);
        if (knob === 'channel') device.rotateChannel(steps);
        render();
    };

    // Eingerastete Tasten (Zweitasten-Bedienung mit der Maus: Umschalttaste +
    // Klick hält z. B. H/L gedrückt, bis die nächste Taste losgelassen wird).
    const latched = new Map();
    // Loslass-Funktionen der gerade gedrückten Tasten (Fensterwechsel).
    const heldKeys = new Set();
    // Sprechtaste sichtbar gedrückt (rot), egal ob per Maus oder Leertaste.
    function showPtt(on) {
        const node = device?.svg.querySelector('[data-key="ptt"]');
        const body = node?.querySelector('rect');
        if (! body) return;
        body.style.fill = on ? '#dc2626' : '';
        body.style.stroke = on ? '#7f1d1d' : '';
        body.style.filter = on ? 'drop-shadow(0 0 6px rgba(239, 68, 68, 0.85))' : '';
        node.setAttribute('aria-pressed', on ? 'true' : 'false');
    }
    const showLatch = (node, on) => {
        node.style.filter = on ? 'brightness(1.8)' : '';
        node.setAttribute('aria-pressed', on ? 'true' : 'false');
    };
    function releaseLatched() {
        for (const [key, node] of latched) {
            radio.keyUp(key);
            showLatch(node, false);
        }
        latched.clear();
    }
    // Neues Gerät bzw. neue Gerätelogik: nichts mehr gedrückt.
    function forgetLatched() {
        latched.forEach((node) => showLatch(node, false));
        latched.clear();
    }

    // Zeichnet das gewählte Gerät und hängt die Bedienung an seine Teile.
    function mountDevice() {
        deviceBox.replaceChildren();
        latched.clear();
        heldKeys.clear();
        device = DEVICES[deviceKey].render(deviceBox);
        showPttNote();
        const latchKeys = DEVICES[deviceKey].latch ?? [];
        device.svg.querySelectorAll('[data-key]').forEach((node) => {
            const key = node.dataset.key;
            // Gedrückt per Zeiger bzw. Tastatur; beim Fokuswechsel loslassen.
            let held = false;
            node.style.touchAction = 'none';
            const toggleLatch = () => {
                if (latched.has(key)) {
                    latched.delete(key);
                    radio.keyUp(key);
                    showLatch(node, false);
                } else {
                    radio.keyDown(key);
                    latched.set(key, node);
                    showLatch(node, true);
                }
                render();
            };
            const press = () => {
                ensureAudio();
                held = true;
                heldKeys.add(release);
                if (key === 'ptt') pttDown();
                else radio.keyDown(key);
                render();
            };
            function release() {
                if (! held) return;
                held = false;
                heldKeys.delete(release);
                if (key === 'ptt') pttUp();
                else radio.keyUp(key);
                if (key !== 'ptt' && latched.size) releaseLatched();
                render();
            }
            node.addEventListener('pointerdown', (e) => {
                e.preventDefault();
                if ((e.shiftKey || e.ctrlKey) && latchKeys.includes(key)) {
                    ensureAudio();
                    toggleLatch();

                    return;
                }
                node.setPointerCapture?.(e.pointerId);
                press();
            });
            node.addEventListener('pointerup', release);
            node.addEventListener('pointercancel', release);
            node.addEventListener('keydown', (e) => {
                if ((e.key !== 'Enter' && e.key !== ' ') || e.repeat) return;
                e.preventDefault();
                if (e.shiftKey && latchKeys.includes(key)) toggleLatch();
                else press();
            });
            node.addEventListener('keyup', (e) => {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); release(); }
            });
            node.addEventListener('blur', release);
        });

        // Taste in der Mitte eines Drehknopfs (ENTER bzw. VOL/SQL-Kappe): das
        // Mausrad dreht dort den Knopf.
        const wheel = DEVICES[deviceKey].wheel;
        if (wheel.key) device.svg.querySelector(`[data-key="${wheel.key}"]`)?.addEventListener('wheel', (e) => { e.preventDefault(); turnKnob(wheel.knob, e.deltaY < 0 ? 1 : -1); }, { passive: false });

        device.svg.querySelectorAll('[data-knob]').forEach((node) => {
            const knob = node.dataset.knob;
            const turn = (steps) => turnKnob(knob, steps);
            let startY = null;
            node.addEventListener('wheel', (e) => { e.preventDefault(); turn(e.deltaY < 0 ? 1 : -1); }, { passive: false });
            node.addEventListener('pointerdown', (e) => { ensureAudio(); startY = e.clientY; node.setPointerCapture?.(e.pointerId); });
            node.addEventListener('pointermove', (e) => {
                if (startY === null) return;
                const steps = Math.trunc((startY - e.clientY) / 18);
                if (steps !== 0) { turn(steps); startY -= steps * 18; }
            });
            const end = () => { startY = null; };
            node.addEventListener('pointerup', end);
            node.addEventListener('pointercancel', end);
            node.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowUp' || e.key === 'ArrowRight') { e.preventDefault(); turn(1); }
                if (e.key === 'ArrowDown' || e.key === 'ArrowLeft') { e.preventDefault(); turn(-1); }
            });
        });
    }
    mountDevice();

    // Leertaste als Sprechtaste, außer beim Tippen und auf einem Bedienteil
    // des Geräts (dort drückt die Leertaste dieses Teil). Auch wenn gerade ein
    // Button den Fokus hat, sendet die Leertaste; Buttons gehen mit Enter.
    let spacePtt = false;
    document.addEventListener('keydown', (e) => {
        if (e.code !== 'Space') return;
        if (e.target.closest('input, textarea, select, [contenteditable="true"], [data-key], [data-knob]') || locked()) return;
        e.preventDefault();
        if (e.repeat) return;
        ensureAudio();
        spacePtt = true;
        pttDown();
    });
    document.addEventListener('keyup', (e) => {
        // Losgelassen wird auch, wenn der Fokus inzwischen woanders ist.
        if (e.code !== 'Space' || ! spacePtt) return;
        e.preventDefault();
        spacePtt = false;
        pttUp();
    });
    // Fenster verliert den Fokus: Sprechtaste und gedrückte Tasten loslassen.
    window.addEventListener('blur', () => {
        if (spacePtt) { spacePtt = false; pttUp(); }
        [...heldKeys].forEach((release) => release());
        if (latched.size) releaseLatched();
        render();
    });

    // --- Sprechtaste und Spracherkennung ------------------------------------
    const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    let recognition = null;
    let heard = '';

    function pttDown() {
        // Schon auf Sendung (Maus und Leertaste zugleich): nichts doppelt
        // starten. Gesperrtes Handfunkgerät: nicht senden.
        if (radio.state.tx || recognition || locked()) return;
        showPtt(true);
        if (! radio.pttDown()) { render(); return; }
        window.speechSynthesis?.cancel();
        heard = '';
        transcript.textContent = 'Sendet … sprich jetzt.';
        if (Recognition) {
            recognition = new Recognition();
            recognition.lang = speechLang();
            recognition.continuous = true;
            recognition.interimResults = true;
            recognition.onresult = (event) => {
                heard = Array.from(event.results).map((r) => r[0].transcript).join(' ');
                transcript.textContent = heard;
            };
            recognition.onerror = () => { transcript.textContent = 'Spracherkennung nicht verfügbar – tippe den Funkspruch unten ein.'; };
            try { recognition.start(); } catch { recognition = null; }
        }
        render();
    }

    function pttUp() {
        showPtt(false);
        if (! radio.state.tx) return;
        radio.pttUp();
        // Die Spracherkennung liefert erst später: der Funkspruch gehört zu
        // dieser Übung, diesem Gerät und dem Kanal beim Loslassen.
        const sentRun = run;
        const sentRadio = radio;
        const channel = radio.state.channel;
        const finish = () => {
            if (run !== sentRun || radio !== sentRadio) return;
            if (heard.trim()) transmit(heard.trim(), channel);
            else if (! Recognition) transcript.textContent = 'Dieser Browser hat keine Spracherkennung – tippe den Funkspruch unten ein.';
            render();
        };
        if (recognition) {
            recognition.onend = finish;
            recognition.stop();
            recognition = null;
        } else {
            finish();
        }
    }

    function transmit(text, channel = radio.state.channel) {
        addLog({ who: 'Du', channel, text, own: true });
        radio.spoken(text, channel);
    }

    function sendTyped() {
        const input = typeBox.querySelector('input');
        const text = input.value.trim();
        if (! text || locked()) return;
        if (radio.pttDown()) {
            radio.pttUp();
            transcript.textContent = text;
            transmit(text);
            input.value = '';
        }
        render();
    }

    // --- Ereignisse ---------------------------------------------------------
    function onRadioEvent(event) {
        if (event.type === 'inland_blocked') {
            inlandInfo = 'atis';
            showInlandLock();
            renderSidebar();
        }
        if (event.type === 'dsc_received' || event.type === 'dsc_sent') beep(2100, 200);
        if (mode === 'free' && free) free.handle(event);
        if (! run) return;
        const pending = run.handle(event);
        if (pending?.miss) {
            lastMiss = pending;
            renderSidebar();
        } else if (pending) {
            lastMiss = null;
            evaluateSpeech(pending);
        }
    }

    async function evaluateSpeech({ stepIndex, text }) {
        const current = run;
        lastSpeech = { stepIndex, checking: true, checks: [] };
        renderSidebar();
        try {
            const result = await postJson(config.urls.evaluate, { lesson: current.lesson.key, step: stepIndex, text });
            if (current !== run) return;
            lastSpeech = { stepIndex, checking: false, ...result };
            current.speechResult(stepIndex, result.passed, result.checks);
        } catch {
            if (current !== run) return;
            lastSpeech = { stepIndex, checking: false, error: true, checks: [] };
            // Ein Serverfehler ist kein Fehlversuch: der Schritt bleibt offen.
            current.speechError(stepIndex);
        }
        renderSidebar();
    }

    function freshRadio(start) {
        if (radio.state.tx) pttUp();
        clearTimers();
        forgetLatched();
        radio = makeRadio();
        radio.setup(start);
        lastSpeech = null;
        lastMiss = null;
        log.length = 0;
        renderLog();
    }

    function startRun(original, options) {
        const lesson = prepareLesson(original, deviceKey);
        freshRadio(lesson.start);
        run = createLessonRun(lesson, { ...options, schedule, receive: (call) => {
            // false: Gerät aus bzw. im Binnenmodus – die Lektion ruft später erneut.
            const taken = radio.receive(call) !== false;
            render();

            return taken;
        }, say });
        run.onChange((status) => {
            if (status.done && ! options.exam) finishLesson(lesson, options.level);
            if (status.done && options.exam) recordExamTask(status);
            renderSidebar();
            render();
        });
        run.start();
        renderSidebar();
        render();
    }

    // --- Lektionen ------------------------------------------------------------
    async function finishLesson(lesson, level) {
        const progress = progressOf();
        progress[lesson.key] = Math.max(progress[lesson.key] ?? 0, level);
        try { await postJson(config.urls.progress, { lesson: lesson.key, level, device: deviceKey }); } catch { /* Fortschritt bleibt lokal sichtbar */ }
        renderSidebar();
    }

    function lessonList() {
        const progress = progressOf();
        const lessons = visibleLessons();
        const units = [];
        for (const lesson of lessons) {
            let unit = units.find((u) => u.name === lesson.unit);
            if (! unit) units.push(unit = { name: lesson.unit, lessons: [] });
            unit.lessons.push(lesson);
        }
        const solo = lessons.filter((l) => (progress[l.key] ?? 0) >= 3).length;

        return h('div', { class: 'space-y-3' },
            h('p', { class: 'text-xs text-slate-600 dark:text-slate-400' }, `Am ${deviceName()}: ${solo} von ${lessons.length} selbst geschafft`),
            units.map((unit) => h('details', { class: 'group', open: unit.lessons.some((l) => (progress[l.key] ?? 0) < 3) ? true : null },
                h('summary', { class: 'cursor-pointer text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-1' },
                    `${unit.name} · ${unit.lessons.filter((l) => (progress[l.key] ?? 0) >= 3).length}/${unit.lessons.length}`),
                unit.lessons.map((lesson) => {
                    const best = progress[lesson.key] ?? 0;

                    return h('div', { class: 'py-2 border-b border-slate-100 dark:border-slate-700' },
                        h('div', { class: 'flex items-center gap-2' },
                            h('span', { class: `w-3 h-3 rounded-full ${best >= 3 ? 'bg-emerald-500' : best > 0 ? 'bg-amber-400' : 'border border-slate-400'}`, 'aria-hidden': 'true' }),
                            h('span', { class: 'flex-1 font-medium text-slate-800 dark:text-slate-100' }, deviceText(lesson.title, deviceKey)),
                            lesson.pruefung ? h('span', { class: 'text-[11px] px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300' }, `Prüfung ${lesson.pruefung}`) : null,
                        ),
                        h('div', { class: 'flex gap-1 mt-1 pl-5' }, [1, 2, 3].map((level) => h('button', {
                            type: 'button',
                            class: `text-[11px] px-2 py-1 rounded border ${best >= level ? 'border-emerald-500 text-emerald-700 dark:text-emerald-300' : 'border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300'} hover:bg-slate-50 dark:hover:bg-slate-700`,
                            onclick: () => startRun(lesson, { level }),
                        }, LEVEL_LABELS[level]))),
                    );
                }),
            )),
        );
    }

    function speechFeedback(status) {
        const speech = lastSpeech && (status.awaitingSpeech || lastSpeech.stepIndex === status.index || status.done) ? lastSpeech : null;
        const miss = lastMiss && lastMiss.stepIndex === status.index && ! status.done ? lastMiss : null;

        return h('div', { class: 'space-y-1' },
            miss ? h('p', { class: 'text-red-700 dark:text-red-300' }, `Dieser Funkspruch gehört auf ${missLabel(miss.expected)}.`) : null,
            speech?.checking ? h('p', { class: 'text-slate-500' }, 'Funkspruch wird geprüft …') : null,
            speech?.error ? h('p', { class: 'text-red-700 dark:text-red-300' }, 'Die Prüfung ist fehlgeschlagen. Bitte noch einmal senden.') : null,
            (speech?.checks ?? []).map((c) => h('p', { class: c.ok ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300' }, `${c.ok ? '✓' : '✗'} ${c.label}`)),
            speech && ! speech.checking && ! speech.passed && ! speech.error && ! status.done ? h('p', { class: 'text-slate-600 dark:text-slate-300' }, 'Noch nicht vollständig – sende den Funkspruch noch einmal.') : null,
        );
    }

    function lessonPanel() {
        const status = run.status();
        const lesson = run.lesson;

        return h('div', { class: 'space-y-3' },
            h('button', { type: 'button', class: 'text-xs text-slate-600 dark:text-slate-400 underline', onclick: () => { run = null; clearTimers(); renderSidebar(); render(); } }, '← Alle Lektionen'),
            h('h2', { class: 'font-semibold text-base text-slate-800 dark:text-white' }, lesson.title),
            h('p', { class: 'text-slate-600 dark:text-slate-300' }, lesson.intro),
            h('p', { class: 'text-xs text-slate-500 dark:text-slate-400' }, `${LEVEL_LABELS[run.level]} · ${status.done ? 'fertig' : `Schritt ${status.index + 1} von ${status.total}`}`),
            status.done
                ? h('div', { class: 'p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-200' },
                    run.level >= 3 ? 'Geschafft – selbst, ohne Hilfe.' : 'Geschafft. Versuch es als Nächstes mit weniger Hilfe.',
                    run.level < 3 ? h('button', { type: 'button', class: 'block mt-2 underline', onclick: () => startRun(lesson, { level: run.level + 1 }) }, `Weiter: ${LEVEL_LABELS[run.level + 1]}`) : null)
                : h('div', { class: 'p-3 rounded-lg bg-slate-50 dark:bg-slate-700/50 space-y-2' },
                    h('p', { class: 'font-medium text-slate-800 dark:text-slate-100' }, status.text),
                    status.help ? h('p', { class: 'text-slate-600 dark:text-slate-300' }, status.help) : null),
            speechFeedback(status),
        );
    }

    // --- Prüfungsmodus --------------------------------------------------------
    function startExam(series) {
        exam = { series, lessons: examLessons(exams[series], deviceKey), index: 0, tasks: {}, summary: null };
        startExamTask();
    }

    function startExamTask() {
        const lesson = lessonByKey[exam.lessons[exam.index]];
        // Binnenprüfung: Gerät zu Beginn aus und in der Kanalgruppe INT.
        const start = exams[exam.series].start;
        startRun(start ? { ...lesson, start } : lesson, { exam: true, level: 3 });
    }

    function recordExamTask(status) {
        if (! exam) return;
        exam.tasks[run.lesson.key] = { passed: ! status.failed, results: status.results };
    }

    async function finishExam() {
        // Die Antwort kann kommen, wenn längst eine andere Prüfung oder ein
        // anderes Gerät gewählt ist: dann nur speichern, nichts anzeigen.
        const current = exam;
        const device = deviceKey;
        const passed = Object.entries(current.tasks).filter(([, t]) => t.passed).map(([key]) => key);
        run = null;
        clearTimers();
        current.summary = { loading: true };
        renderSidebar();
        try {
            const summary = await postJson(config.urls.exam, { series: current.series, passed, device });
            exams[current.series].best = { ...(exams[current.series].best ?? {}), [device]: summary.best };
            current.summary = summary;
        } catch {
            current.summary = { error: true, passed: passed.length, total: current.lessons.length };
        }
        if (exam === current) renderSidebar();
    }

    function examPanel() {
        if (! exam) {
            return h('div', { class: 'space-y-3' },
                h('p', { class: 'text-slate-600 dark:text-slate-300' }, 'Aufgaben wie in der praktischen Prüfung: am Stück, ohne Hilfe, zwei Versuche je Funkspruch. Am Ende siehst du jede Aufgabe mit erfüllten und fehlenden Prüfpunkten und der Musterlösung.'),
                catalogs().includes('UBI') ? h('p', { class: 'text-xs text-slate-500 dark:text-slate-400' }, 'UBI: Das Gerät ist zu Beginn aus und steht in der Kanalgruppe INT – einschalten und auf ATIS stellen gehört zur Aufgabe.') : null,
                visibleExams().map(([series, info]) => h('div', { class: 'p-3 rounded-lg border border-slate-200 dark:border-slate-700 space-y-2' },
                    h('div', { class: 'flex items-center gap-2' },
                        h('span', { class: 'font-semibold text-slate-800 dark:text-white flex-1' }, info.title ?? `Reihe ${series}`),
                        info.best?.[deviceKey] !== null && info.best?.[deviceKey] !== undefined ? h('span', { class: 'text-xs text-slate-500' }, `bestes Ergebnis ${info.best[deviceKey]} von ${info.lessons.length}`) : null),
                    h('ol', { class: 'list-decimal pl-5 text-xs text-slate-600 dark:text-slate-300' }, examLessons(info, deviceKey).map((key) => h('li', {}, deviceText(lessonByKey[key]?.title ?? key, deviceKey)))),
                    button(`${info.title ?? `Reihe ${series}`} starten`, () => startExam(series), 'font-semibold'),
                )),
            );
        }

        if (exam.summary) return examSummary();

        const status = run.status();
        const lesson = run.lesson;
        const last = exam.index === exam.lessons.length - 1;

        return h('div', { class: 'space-y-3' },
            h('p', { class: 'text-xs text-slate-500 dark:text-slate-400' }, `${exams[exam.series].title ?? `Reihe ${exam.series}`} · Aufgabe ${exam.index + 1} von ${exam.lessons.length}`),
            h('h2', { class: 'font-semibold text-base text-slate-800 dark:text-white' }, lesson.title),
            h('p', { class: 'text-slate-700 dark:text-slate-200' }, lesson.intro),
            status.done
                ? h('div', { class: `p-3 rounded-lg ${status.failed ? 'bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200' : 'bg-emerald-50 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-200'}` },
                    status.failed ? 'Aufgabe nicht bestanden.' : 'Aufgabe bestanden.',
                    h('div', { class: 'mt-2' }, last ? button('Prüfung auswerten', finishExam, 'font-semibold') : button('Nächste Aufgabe', () => { exam.index++; startExamTask(); }, 'font-semibold')))
                : h('div', { class: 'space-y-2' },
                    status.awaitingSpeech ? h('p', { class: 'text-slate-500' }, 'Funkspruch wird geprüft …') : null,
                    h('p', { class: 'text-xs text-slate-500 dark:text-slate-400' }, `Versuche für den nächsten Funkspruch: ${status.attemptsLeft} von ${EXAM_ATTEMPTS}`),
                    lastMiss && lastMiss.stepIndex === status.index ? h('p', { class: 'text-red-700 dark:text-red-300' }, 'Falscher Kanal oder falsche Sendeleistung – das war ein Fehlversuch.') : null,
                    lastSpeech && ! lastSpeech.checking && ! lastSpeech.passed && ! lastSpeech.error && lastSpeech.stepIndex === status.index ? h('p', { class: 'text-red-700 dark:text-red-300' }, 'Funkspruch unvollständig – das war ein Fehlversuch.') : null,
                    lastSpeech?.error && lastSpeech.stepIndex === status.index ? h('p', { class: 'text-red-700 dark:text-red-300' }, 'Die Prüfung des Funkspruchs ist fehlgeschlagen – kein Fehlversuch. Bitte noch einmal senden.') : null,
                    button('Aufgabe aufgeben', () => run.giveUp())),
            button('Prüfung abbrechen', () => { exam = null; run = null; clearTimers(); renderSidebar(); render(); }, 'mt-2'),
        );
    }

    function examSummary() {
        const s = exam.summary;
        if (s.loading) return h('p', { class: 'text-slate-500' }, 'Prüfung wird ausgewertet …');

        return h('div', { class: 'space-y-3' },
            h('h2', { class: 'font-semibold text-base text-slate-800 dark:text-white' }, `${exams[exam.series].title ?? `Reihe ${exam.series}`}: ${s.passed} von ${s.total} Aufgaben bestanden`),
            s.error ? h('p', { class: 'text-red-700 dark:text-red-300' }, 'Das Ergebnis konnte nicht gespeichert werden; die Musterlösungen fehlen deshalb.') : null,
            exam.lessons.map((key, i) => {
                const task = exam.tasks[key];
                const solutions = s.solutions?.[key] ?? [];

                return h('details', { class: 'p-2 rounded-lg border border-slate-200 dark:border-slate-700' },
                    h('summary', { class: `cursor-pointer font-medium ${task?.passed ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'}` }, `${task?.passed ? '✓' : '✗'} ${i + 1}. ${deviceText(lessonByKey[key]?.title ?? key, deviceKey)}`),
                    (task?.results ?? []).map((r) => h('div', { class: 'mt-2 space-y-0.5' },
                        r.text ? h('p', { class: 'text-xs italic text-slate-500' }, `„${r.text}“`) : null,
                        r.checks.map((c) => h('p', { class: `text-xs ${c.ok ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'}` }, `${c.ok ? '✓' : '✗'} ${c.label}`)))),
                    task && ! task.results?.length && ! task.passed ? h('p', { class: 'mt-2 text-xs text-slate-500' }, 'Geräteaktionen nicht vollständig ausgeführt oder aufgegeben.') : null,
                    solutions.map((text) => h('p', { class: 'mt-2 text-xs text-slate-700 dark:text-slate-200 whitespace-pre-line' }, h('strong', {}, 'Musterlösung: '), text)),
                );
            }),
            button('Zur Übersicht', () => { exam = null; renderSidebar(); }),
        );
    }

    // --- Freies Üben ----------------------------------------------------------
    function startFree() {
        freshRadio(defaultStart());
        timeOffset = 0;
        run = null;
        free = createFreePractice({ ship: config.ship, schedule, receive: (call) => { radio.receive(call); render(); }, say, radio: () => radio });
        renderSidebar();
        render();
    }

    function freePanel() {
        if (! free) startFree();

        return h('div', { class: 'space-y-3' },
            h('p', { class: 'text-slate-600 dark:text-slate-300' }, 'Löse Ereignisse aus und reagiere frei darauf. Freies Üben zählt nicht zum Fortschritt.'),
            FREE_EVENTS.filter((group) => catalogs().includes(group.catalog)).map((group) => h('div', { class: 'space-y-1' },
                h('h3', { class: 'text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400' }, group.title),
                h('div', { class: 'flex flex-wrap gap-1' }, group.items.map((item) => button(item.label, () => { ensureAudio(); free.trigger(item.key); render(); }))),
            )),
            h('div', { class: 'space-y-1' },
                h('h3', { class: 'text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400' }, 'Zeit'),
                h('div', { class: 'flex flex-wrap gap-1' },
                    button('+5 Minuten', () => jump(5 * 60 * 1000)),
                    button('+4 Stunden', () => jump(4 * 60 * 60 * 1000 + 60 * 1000))),
            ),
            catalogs().includes('SRC') ? h('p', { class: 'text-xs text-slate-500 dark:text-slate-400' }, `Eigene Anrufe im Seefunk: Möwe, Seestern und Nordsee Radio antworten auf deine DSC-Anrufe und Funksprüche, Bremen Rescue auf einen Notalarm.${catalogs().includes('UBI') ? ' Im Binnenfunk wird nur abgehört; Gespräche mit Antwort übst du in den Lektionen.' : ''}`) : null,
            button('Neu starten', startFree),
        );
    }

    function jump(ms) {
        timeOffset += ms;
        radio.tick();
        addLog({ hint: `Zeit vorgestellt um ${ms >= 3600000 ? `${Math.round(ms / 3600000)} Stunden` : `${Math.round(ms / 60000)} Minuten`}.` });
        render();
    }

    // --- Seitenleiste ---------------------------------------------------------
    function switchDevice(next) {
        if (next === deviceKey || ! DEVICES[next]) return;
        deviceKey = next;
        try { window.localStorage.setItem('geraetesimulation.device', next); } catch { /* egal */ }
        // Bei jedem Wechsel auf ein Handfunkgerät erscheint der Hinweis wieder.
        handheldNoteClosed = false;
        inlandInfo = null;
        run = null;
        exam = null;
        free = null;
        clearTimers();
        // Laufende Sendung abbrechen: Spracherkennung beenden, Sprechtaste los.
        if (recognition) {
            recognition.onresult = null;
            recognition.onend = null;
            try { recognition.stop(); } catch { /* egal */ }
            recognition = null;
        }
        spacePtt = false;
        showPtt(false);
        radio = makeRadio();
        mountDevice();
        showHandheldNote();
        showInlandLock();
        if (mode === 'free') startFree();
        else freshRadio(defaultStart());
        renderSidebar();
        render();
    }

    function switchMode(next) {
        if (next === mode) return;
        mode = next;
        run = null;
        exam = null;
        free = null;
        clearTimers();
        if (mode === 'free') startFree();
        else freshRadio(defaultStart());
        renderSidebar();
        render();
    }

    // Kurs mit Binnenfunk am Handfunkgerät: Binnenfunk fehlt, mit Begründung.
    function inlandSideNote() {
        if (! DEVICES[deviceKey].handheld || ! courseCatalogs.includes('UBI') || mode === 'free' || run || exam) return null;

        return h('p', { class: 'p-2 rounded-lg bg-red-50 text-red-900 dark:bg-red-950/40 dark:text-red-100 text-xs' },
            `🔒 ${mode === 'exam' ? 'UBI-Prüfungen' : 'Binnenfunk-Lektionen'} sind am Handfunkgerät gesperrt. `,
            h('button', { type: 'button', class: 'underline font-semibold', onclick: () => { inlandInfo = 'info'; showInlandLock(); renderSidebar(); } }, 'Warum?'));
    }

    function renderSidebar() {
        showStepLine();
        sidebar.replaceChildren(
            h('div', { class: 'text-xs text-slate-600 dark:text-slate-400' },
                h('div', { class: 'font-semibold text-slate-800 dark:text-white text-sm' }, `${deviceName()} · ${lockedByCourse() ? 'Binnenfunk gesperrt' : catalogs().map((c) => SERVICES[c]).join(' und ')}`),
                [config.ship.label, `Rufzeichen ${config.ship.callsign}`, catalogs().includes('SRC') ? `MMSI ${config.ship.mmsi}` : null, catalogs().includes('UBI') && config.ship.atis ? `ATIS ${config.ship.atis}` : null].filter(Boolean).join(' · ')),
            deviceList.length > 1 ? h('label', { class: 'flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300' }, 'Gerät',
                h('select', { class: 'flex-1 rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-xs py-1', 'aria-label': 'Funkgerät wählen', onchange: (e) => switchDevice(e.target.value) },
                    deviceList.map((d) => h('option', { value: d.key, selected: d.key === deviceKey ? true : null }, d.name)))) : null,
            h('div', { class: 'flex gap-1', role: 'tablist' }, Object.entries(MODES).map(([key, label]) => h('button', {
                type: 'button',
                role: 'tab',
                'aria-selected': mode === key ? 'true' : 'false',
                class: `flex-1 text-xs px-2 py-1.5 rounded-lg ${mode === key ? 'bg-slate-800 text-white dark:bg-slate-200 dark:text-slate-900' : 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200'}`,
                onclick: () => switchMode(key),
            }, label))),
            lockedByCourse()
                ? h('p', { class: 'p-3 rounded-lg bg-red-50 text-red-900 dark:bg-red-950/40 dark:text-red-100 text-xs' }, `Am ${deviceName()} gibt es in diesem Kurs nichts zu üben: Binnenfunk mit dem Handfunkgerät ist auf Sportbooten verboten. Wähle oben ein fest eingebautes Gerät.`)
                : h('div', { class: 'space-y-3' }, inlandSideNote(), mode === 'lessons' ? (run ? lessonPanel() : lessonList()) : mode === 'exam' ? examPanel() : freePanel()),
            h('details', { class: 'text-xs text-slate-600 dark:text-slate-400' },
                h('summary', { class: 'cursor-pointer font-semibold' }, 'Hilfe zur Bedienung'),
                h('ul', { class: 'list-disc pl-4 mt-2 space-y-1' },
                    (DEVICES[deviceKey].controls ?? []).map((text) => h('li', {}, text)),
                    h('li', {}, `Sprechtaste ${pttWhere()} oder Leertaste gedrückt halten und sprechen; ohne Spracherkennung den Funkspruch eintippen.`),
                    h('li', {}, 'Gegenstationen sprechen mit der Stimme des Browsers; mitlesen kannst du im Funkverkehr unter dem Gerät.'),
                ),
            ),
        );
    }

    function renderLog() {
        const lines = log.length
            ? log.map((entry) => entry.hint
                ? h('p', { class: 'text-amber-700 dark:text-amber-300' }, entry.hint)
                : h('p', { class: entry.own ? 'text-slate-500 dark:text-slate-400' : 'text-slate-800 dark:text-slate-100' }, h('strong', {}, `${entry.who} · Kanal ${String(entry.channel).padStart(2, '0')}: `), entry.text))
            : [h('p', { class: 'text-slate-500 dark:text-slate-400' }, 'Noch nichts gehört.')];
        radioLog.replaceChildren(h('div', { class: 'font-semibold text-slate-700 dark:text-slate-200' }, 'Funkverkehr'), ...lines);
    }

    // --- Darstellung ----------------------------------------------------------
    function render() {
        const display = radio.display();
        const status = run?.status();
        device.update(display, radio.state, status?.target ?? null);
    }

    let lastBeep = 0;
    setInterval(() => {
        radio.tick();
        const display = radio.display();
        const now = Date.now();
        if ((display.alarm || radio.state.screen === 'distressHold') && now - lastBeep > 700) {
            beep(display.alarm ? 2200 : 1400, 180);
            lastBeep = now;
        }
        render();
    }, 100);

    radio.setup(defaultStart());
    showLayout();
    showHandheldNote();
    showInlandLock();
    renderSidebar();
    renderLog();
    render();
}
