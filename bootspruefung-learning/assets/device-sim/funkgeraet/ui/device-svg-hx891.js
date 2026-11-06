// Zeichnung des Handfunkgeräts Standard Horizon HX891BT/E (SVG), auf gute
// Bedienbarkeit ausgelegt statt fotogetreu, ohne Herstellerlogo und mit
// gekürzter Antenne: links die Vorderansicht mit großem Display, drei
// Softkeys und dem Tastenfeld (◀ CH▲ VOL+ ▶ / CLR CH▼ VOL− Sperre /
// MENU/SET … 16/S), kleiner Lautsprecher; Ein/Aus, Sprechtaste und SQL groß
// an der linken Seite. Rechts die Rückseite mit Gürtelclip, Akkudeckel und
// DISTRESS unter der roten Klappe („PULL OPEN“) am Rand. Tasten und Display
// leuchten bei eingeschaltetem Gerät. Gleiche Schnittstelle wie
// device-svg.js; ein Ziel "sk:LABEL" markiert den Softkey mit dieser
// Beschriftung bzw. die Blättertaste zur richtigen Seite.

import { createBacklight, glowFilter } from './backlight.js';

const NS = 'http://www.w3.org/2000/svg';
const LCD_ON = '#d9e3ea';
const LCD_INK = '#14273a';
const MONO = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';
const SANS = 'Arial, Helvetica, sans-serif';
const PRINT = '#e4e6ea';
const ACCENT = '#d9692b';
const RIM = { on: '#f4ddb0', off: '#3a3c41' };
const LABEL = { on: '#fff6e2', off: '#8c9097' };

// Die Anzeige wird in einer Fläche von 220 × 221 geschrieben und in das
// Display eingepasst.
const LCD_W = 220;

function el(name, attrs = {}, parent = null, text = null) {
    const node = document.createElementNS(NS, name);
    for (const [key, value] of Object.entries(attrs)) node.setAttribute(key, value);
    if (text !== null) node.textContent = text;
    if (parent) parent.appendChild(node);

    return node;
}

function defs(svg) {
    const d = el('defs', {}, svg);
    const grad = (id, stops, attrs = {}) => {
        const g = el('linearGradient', { id, x1: 0, y1: 0, x2: 0, y2: 1, ...attrs }, d);
        stops.forEach(([o, c]) => el('stop', { offset: o, 'stop-color': c }, g));
    };
    const radial = (id, stops) => {
        const g = el('radialGradient', { id, cx: '40%', cy: '35%', r: '70%' }, d);
        stops.forEach(([o, c]) => el('stop', { offset: o, 'stop-color': c }, g));
    };
    grad('hx-body', [[0, '#3a3c40'], [0.1, '#232427'], [0.9, '#1d1e21'], [1, '#36383c']], { x1: 0, x2: 1, y1: 0, y2: 0 });
    grad('hx-face', [[0, '#1f2023'], [1, '#141517']]);
    grad('hx-bezel', [[0, '#101113'], [1, '#060607']]);
    grad('hx-lcd', [[0, '#e6edf2'], [1, '#c9d5de']]);
    grad('hx-key', [[0, '#34363b'], [1, '#121315']]);
    grad('hx-white', [[0, '#ffffff'], [1, '#d2d5da']]);
    grad('hx-red', [[0, '#e0353f'], [1, '#9b111a']]);
    grad('hx-side', [[0, '#2a2c30'], [1, '#101113']], { x1: 0, x2: 1, y1: 0, y2: 0 });
    grad('hx-antenna', [[0, '#202225'], [0.5, '#35373b'], [1, '#141517']], { x1: 0, x2: 1, y1: 0, y2: 0 });
    grad('hx-back', [[0, '#2c2e32'], [1, '#202225']]);
    radial('hx-knob', [[0, '#45484d'], [1, '#111214']]);
    const shadow = el('filter', { id: 'hx-shadow', x: '-15%', y: '-15%', width: '140%', height: '140%' }, d);
    el('feDropShadow', { dx: 0, dy: 1.5, stdDeviation: 1.3, 'flood-color': '#000', 'flood-opacity': 0.6 }, shadow);
    const bodyShadow = el('filter', { id: 'hx-bodyshadow', x: '-10%', y: '-5%', width: '125%', height: '115%' }, d);
    el('feDropShadow', { dx: 3, dy: 6, stdDeviation: 6, 'flood-color': '#000', 'flood-opacity': 0.35 }, bodyShadow);
    glowFilter(d, 'hx-glow', NS, 1.2);
}

const control = (svg, attrs) => el('g', { tabindex: '0', role: 'button', class: 'fg-control', style: 'cursor:pointer', ...attrs }, svg);

// Beleuchtete Gummitaste; label darf mehrzeilig sein. ink: feste
// Beschriftungsfarbe (weiße bzw. rote Tasten), der Rand leuchtet trotzdem.
function key(svg, light, id, [x1, y1], [x2, y2], label, { ariaLabel = null, size = 16, rx = 12, bar = false, fill = 'url(#hx-key)', ink = null } = {}) {
    const w = x2 - x1;
    const h = y2 - y1;
    const g = control(svg, { 'data-key': id, 'aria-label': ariaLabel ?? [label].flat().join(' ') });
    const rim = el('rect', { x: x1, y: y1, width: w, height: h, rx, fill, stroke: RIM.on, 'stroke-width': 2.2, filter: 'url(#hx-shadow)' }, g);
    light.add(rim, { stroke: RIM.on }, { stroke: RIM.off });
    el('rect', { x: x1 + 4, y: y1 + 3, width: w - 8, height: h * 0.35, rx: rx - 3, fill: '#ffffff', 'fill-opacity': ink ? 0.15 : 0.07, 'pointer-events': 'none' }, g);
    const lit = { fill: ink ?? LABEL.on, filter: ink ? null : 'url(#hx-glow)' };
    const dark = { fill: ink ?? LABEL.off, filter: null };
    if (bar) {
        light.add(el('rect', { x: x1 + w / 2 - 12, y: y1 + h / 2 - 2.5, width: 24, height: 5, rx: 2, ...lit, 'pointer-events': 'none' }, g), lit, dark);
    } else {
        const lines = [label].flat();
        const lineHeight = size * 1.02;
        const top = y1 + h / 2 - (lines.length * lineHeight) / 2 + size * 0.8;
        lines.forEach((line, i) => {
            const t = el('text', { x: x1 + w / 2, y: top + i * lineHeight, 'text-anchor': 'middle', 'font-size': size, 'font-weight': 700, 'font-family': SANS, ...lit, 'pointer-events': 'none' }, g, line);
            if (! ink) light.add(t, lit, dark);
        });
    }

    return g;
}

// Benachbarte gleiche Beschriftungen bilden ein breites Feld.
function fields(labels) {
    const out = [];
    labels.forEach((label, i) => {
        const last = out.at(-1);
        if (last && label && last.label === label) last.span++;
        else out.push({ label, start: i, span: 1 });
    });

    return out;
}

export function renderDeviceHx891(container) {
    const svg = el('svg', { viewBox: '0 0 1000 940', role: 'group', 'aria-label': 'Handfunkgerät Standard Horizon HX891BT/E (Nachbildung), Vorder- und Rückseite', style: 'width:100%;height:auto;user-select:none;-webkit-user-select:none' });
    container.appendChild(svg);
    defs(svg);
    const light = createBacklight();

    // --- Vorderansicht ---------------------------------------------------------
    // Gekürzte Antenne links, Buchsenkappe rechts oben
    el('rect', { x: 236, y: 8, width: 30, height: 74, rx: 15, fill: 'url(#hx-antenna)', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    el('path', { d: 'M226 76 L 276 76 L 288 112 L 214 112 Z', fill: 'url(#hx-antenna)', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    el('rect', { x: 398, y: 66, width: 64, height: 42, rx: 11, fill: 'url(#hx-knob)', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    for (let i = 0; i < 9; i++) el('line', { x1: 406 + i * 6, y1: 70, x2: 406 + i * 6, y2: 84, stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);

    // Linke Seite: Ein/Aus, große Sprechtaste, SQL
    const side = (id, y1, y2, label, ariaLabel, size = 16) => {
        const g = control(svg, { 'data-key': id, 'aria-label': ariaLabel });
        el('rect', { x: 80, y: y1, width: 62, height: y2 - y1, rx: 18, fill: 'url(#hx-side)', stroke: '#0b0c0d', 'stroke-width': 2.5, filter: 'url(#hx-shadow)' }, g);
        el('text', { x: 104, y: (y1 + y2) / 2 + size * 0.36, 'text-anchor': 'middle', 'font-size': size, 'font-weight': 700, fill: '#9a9ea6', 'font-family': SANS, 'pointer-events': 'none' }, g, label);

        return g;
    };
    side('power', 196, 276, '⏻', 'Ein/Aus (1 Sekunde halten)', 24);
    const ptt = side('ptt', 296, 536, '', 'Sprechtaste (PTT) gedrückt halten');
    for (let i = 0; i < 12; i++) el('rect', { x: 90, y: 330 + i * 15, width: 22, height: 4, rx: 2, fill: '#44474c', 'pointer-events': 'none' }, ptt);
    el('text', { x: 104, y: 324, 'text-anchor': 'middle', 'font-size': 14, 'font-weight': 700, fill: '#9a9ea6', 'font-family': SANS, 'pointer-events': 'none' }, ptt, 'PTT');
    side('sql', 556, 636, 'SQL', 'SQL (drücken, dann CH▲/CH▼; 3 Sekunden halten: Monitor)', 17);

    // Gehäuse mit orangem Rand und dunkler Front
    el('rect', { x: 122, y: 104, width: 400, height: 818, rx: 66, fill: 'url(#hx-body)', stroke: '#0b0c0d', 'stroke-width': 3, filter: 'url(#hx-bodyshadow)' }, svg);
    el('rect', { x: 128, y: 110, width: 388, height: 806, rx: 61, fill: 'none', stroke: ACCENT, 'stroke-opacity': 0.55, 'stroke-width': 2 }, svg);
    el('rect', { x: 140, y: 122, width: 364, height: 782, rx: 54, fill: 'url(#hx-face)', stroke: '#0b0c0d', 'stroke-width': 2 }, svg);

    // Großes Display mit Rahmen und Typenzeile
    el('rect', { x: 160, y: 140, width: 324, height: 356, rx: 22, fill: 'url(#hx-bezel)', stroke: '#050506', 'stroke-width': 2 }, svg);
    el('text', { x: 182, y: 165, 'font-size': 15, 'font-weight': 700, fill: PRINT, 'font-family': SANS }, svg, 'GPS');
    el('text', { x: 462, y: 165, 'text-anchor': 'end', 'font-size': 15, 'font-weight': 700, 'font-style': 'italic', fill: PRINT, 'font-family': SANS }, svg, 'HX891BT/E');
    const lcdBox = { x: 172, y: 176, width: 300, height: 302 };
    light.add(el('rect', { ...lcdBox, rx: 6, fill: 'url(#hx-lcd)' }, svg), { fill: 'url(#hx-lcd)' }, { fill: '#555b61' });
    const k = lcdBox.width / LCD_W;
    const lcd = el('g', { 'font-family': MONO, fill: LCD_INK, transform: `translate(${lcdBox.x} ${lcdBox.y}) scale(${k})` }, svg);

    // Softkeys unter den Feldern im Display
    el('text', { x: 152, y: 548, 'font-size': 10, 'font-weight': 700, fill: '#6b6e74', 'font-family': SANS }, svg, 'MIC');
    const softkeys = [[178, 266], [278, 366], [378, 466]].map(([a, b], i) => key(svg, light, `soft${i + 1}`, [a, 508], [b, 552], '', { ariaLabel: `Softkey ${i + 1}`, bar: true, rx: 14 }));

    // Tastenfeld in vier Spalten
    const col = [[158, 234], [246, 322], [334, 410], [422, 498]];
    const row = [[570, 626], [638, 694], [706, 762]];
    const at = (c, r) => [[col[c][0], row[r][0]], [col[c][1], row[r][1]]];
    key(svg, light, 'left', ...at(0, 0), '◀', { ariaLabel: '◀ (Softkey-Seite zurück)', size: 22 });
    key(svg, light, 'up', ...at(1, 0), ['CH', '▲'], { ariaLabel: 'CH▲ (Kanal auf)', size: 16 });
    key(svg, light, 'volUp', ...at(2, 0), ['VOL', '+'], { ariaLabel: 'VOL+ (lauter)', size: 16, fill: 'url(#hx-white)', ink: '#1c1d20' });
    key(svg, light, 'right', ...at(3, 0), '▶', { ariaLabel: '▶ (Softkey-Seite vor)', size: 22 });
    key(svg, light, 'clr', ...at(0, 1), 'CLR', { size: 18 });
    key(svg, light, 'down', ...at(1, 1), ['CH', '▼'], { ariaLabel: 'CH▼ (Kanal ab)', size: 16 });
    key(svg, light, 'volDown', ...at(2, 1), ['VOL', '−'], { ariaLabel: 'VOL− (leiser)', size: 16, fill: 'url(#hx-white)', ink: '#1c1d20' });
    key(svg, light, 'lock', ...at(3, 1), '⚿', { ariaLabel: 'Tastensperre (1 Sekunde halten)', size: 22 });
    key(svg, light, 'menu', ...at(0, 2), ['MENU', '/SET'], { ariaLabel: 'MENU/SET (1 Sekunde halten: SETUP)', size: 15 });
    key(svg, light, 'ch16', ...at(3, 2), '16/S', { ariaLabel: '16/S: Kanal 16 (erneut: zurück), 1 Sekunde Zweitkanal 09', size: 20, fill: 'url(#hx-red)', ink: '#ffffff' });
    el('text', { x: 328, y: 742, 'text-anchor': 'middle', 'font-size': 16, 'font-weight': 700, 'font-style': 'italic', fill: '#5b5e64', 'font-family': SANS, 'letter-spacing': 2 }, svg, 'FLOATING');

    // Kleiner Lautsprecher
    for (let i = 0; i < 5; i++) {
        const y = 790 + i * 22;
        const inset = i === 4 ? 36 : 0;
        el('rect', { x: 180 + inset, y, width: 284 - inset * 2, height: 10, rx: 5, fill: '#0b0c0d' }, svg);
        el('line', { x1: 186 + inset, y1: y + 11.5, x2: 458 - inset, y2: y + 11.5, stroke: '#4a4d52', 'stroke-opacity': 0.6, 'stroke-width': 1.2 }, svg);
    }

    // --- Rückseite mit Gürtelclip, Akkudeckel und DISTRESS am Rand ---------------
    el('text', { x: 777, y: 40, 'text-anchor': 'middle', 'font-size': 20, 'font-weight': 700, fill: '#64748b', 'font-family': SANS }, svg, 'Rückseite');
    // Von hinten: Antenne rechts, Buchsenkappe links
    el('rect', { x: 852, y: 60, width: 26, height: 60, rx: 13, fill: 'url(#hx-antenna)', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    el('path', { d: 'M843 114 L 887 114 L 897 148 L 833 148 Z', fill: 'url(#hx-antenna)', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    el('rect', { x: 676, y: 108, width: 58, height: 38, rx: 10, fill: 'url(#hx-knob)', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    // Seitentasten von hinten (rechts, ohne Funktion)
    for (const [y, h] of [[196, 80], [296, 240], [556, 80]]) el('rect', { x: 938, y, width: 22, height: h, rx: 9, fill: 'url(#hx-side)', stroke: '#0b0c0d', 'stroke-width': 2 }, svg);
    el('rect', { x: 610, y: 140, width: 334, height: 782, rx: 60, fill: 'url(#hx-back)', stroke: '#0b0c0d', 'stroke-width': 3, filter: 'url(#hx-bodyshadow)' }, svg);
    el('rect', { x: 616, y: 146, width: 322, height: 770, rx: 55, fill: 'none', stroke: ACCENT, 'stroke-opacity': 0.5, 'stroke-width': 2 }, svg);
    // Gürtelclip
    el('path', { d: 'M650 170 L 760 170 L 760 410 Q 760 430 740 430 L 670 430 Q 650 430 650 410 Z', fill: '#17181b', stroke: '#0b0c0d', 'stroke-width': 2 }, svg);
    el('circle', { cx: 705, cy: 214, r: 9, fill: '#2c2e32', stroke: '#0b0c0d', 'stroke-width': 1.2 }, svg);
    el('text', { x: 705, y: 330, 'text-anchor': 'middle', 'font-size': 13, 'font-weight': 700, fill: '#4a4d52', 'font-family': SANS }, svg, 'Gürtelclip');
    // Akkudeckel mit Verriegelung unten
    el('path', { d: 'M640 480 Q 640 452 668 452 L 886 452 Q 914 452 914 480 L 914 846 Q 914 896 864 896 L 690 896 Q 640 896 640 846 Z', fill: '#191a1d', stroke: '#0b0c0d', 'stroke-width': 2 }, svg);
    for (let i = 0; i < 4; i++) el('rect', { x: 670 + i * 58, y: 500, width: 22, height: 340, rx: 8, fill: '#1f2023', stroke: '#2c2e32', 'stroke-width': 1 }, svg);
    el('rect', { x: 748, y: 862, width: 58, height: 22, rx: 5, fill: '#111214', stroke: '#4a4d52', 'stroke-width': 1.2 }, svg);
    el('text', { x: 777, y: 476, 'text-anchor': 'middle', 'font-size': 14, 'font-weight': 700, fill: '#4a4d52', 'font-family': SANS }, svg, 'Akkudeckel');

    // DISTRESS unter der roten Klappe „PULL OPEN“ (groß, am rechten Rand)
    el('rect', { x: 786, y: 196, width: 136, height: 140, rx: 12, fill: '#111214', stroke: '#55585e', 'stroke-width': 1.5 }, svg);
    const db = { x1: 800, y1: 214, x2: 908, y2: 318 };
    const distress = control(svg, { 'data-key': 'distress', 'aria-label': 'DISTRESS (3 Sekunden halten)' });
    el('rect', { x: db.x1, y: db.y1, width: db.x2 - db.x1, height: db.y2 - db.y1, rx: 10, fill: 'url(#hx-red)', stroke: '#5b080d', 'stroke-width': 2 }, distress);
    el('text', { x: (db.x1 + db.x2) / 2, y: (db.y1 + db.y2) / 2 + 6, 'text-anchor': 'middle', 'font-size': 17, 'font-weight': 700, fill: '#ffffff', 'font-family': SANS, 'pointer-events': 'none' }, distress, 'DISTRESS');
    const cover = control(svg, { 'data-key': 'distressCover', 'aria-label': 'Rote Klappe der DISTRESS-Taste (PULL OPEN)' });
    const coverClosed = { y: db.y1 - 12, height: db.y2 - db.y1 + 24 };
    const coverOpen = { y: db.y1 - 58, height: 40 };
    const coverLid = el('rect', { x: db.x1 - 8, ...coverClosed, width: db.x2 - db.x1 + 16, rx: 10, fill: '#d9232e', stroke: '#7c0d14', 'stroke-width': 2 }, cover);
    const coverText = el('text', { x: (db.x1 + db.x2) / 2, y: db.y1 + 38, 'text-anchor': 'middle', 'font-size': 15, 'font-weight': 700, fill: '#ffffff', 'font-family': SANS, 'pointer-events': 'none' }, cover);
    el('tspan', { x: (db.x1 + db.x2) / 2, dy: 0 }, coverText, 'DISTRESS');
    el('tspan', { x: (db.x1 + db.x2) / 2, dy: 22 }, coverText, 'PULL OPEN');
    el('tspan', { x: (db.x1 + db.x2) / 2, dy: 24 }, coverText, '▲');

    const highlight = el('rect', { fill: 'none', stroke: '#e3b505', 'stroke-width': 4, 'stroke-dasharray': '10 6', rx: 14, visibility: 'hidden', 'pointer-events': 'none' }, svg);

    function text(x, y, value, attrs = {}) {
        el('text', { x, y, 'font-size': 13, ...attrs }, lcd, value);
    }

    function inverted(x, y, w, h, label, size = 11) {
        el('rect', { x, y, width: w, height: h, rx: 2, fill: LCD_INK }, lcd);
        text(x + w / 2, y + h / 2 + size * 0.36, label, { 'text-anchor': 'middle', 'font-size': size, fill: LCD_ON, 'font-weight': 700 });
    }

    function drawSoftkeyBar(d) {
        if (d.locked) {
            inverted(8, 190, 204, 26, 'KEY LOCK', 13);

            return;
        }
        for (const f of fields(d.softkeys ?? [])) {
            if (! f.label) continue;
            const x = 9 + f.start * 68;
            const w = f.span * 68 - 4;
            inverted(x, 190, w, 26, f.label, f.label.length > 9 ? 9 : f.label.length > 6 ? 10 : 12);
        }
        if (d.pages) {
            text(0, 207, '◂', { 'font-size': 10 });
            text(213, 207, '▸', { 'font-size': 10 });
        }
    }

    function drawPanel(p) {
        if (p.title) {
            el('rect', { x: 0, y: 0, width: LCD_W, height: 19, fill: LCD_INK }, lcd);
            text(110, 14, p.title, { 'text-anchor': 'middle', 'font-size': 11.5, fill: LCD_ON, 'font-weight': 700 });
        }
        let y = 36;
        if (p.note) {
            el('rect', { x: 30, y: y - 12, width: 160, height: 16, rx: 2, fill: LCD_INK }, lcd);
            text(110, y, p.note, { 'text-anchor': 'middle', 'font-size': 10.5, fill: LCD_ON, 'font-weight': 700 });
            y += 19;
        }
        if (p.items) {
            const visible = p.note ? 7 : 8;
            const first = Math.max(0, Math.min(p.cursor - 3, p.items.length - visible));
            p.items.slice(first, first + visible).forEach((item, i) => {
                const selected = first + i === p.cursor;
                if (selected) el('rect', { x: 2, y: y - 13, width: 216, height: 17, fill: LCD_INK }, lcd);
                text(8, y, item.length > 28 ? `${item.slice(0, 27)}…` : item, { 'font-size': 12, fill: selected ? LCD_ON : LCD_INK });
                y += 18;
            });
        }
        (p.lines ?? []).forEach((line) => {
            text(110, y, line, { 'text-anchor': 'middle', 'font-size': 13, class: p.blink && /Waiting/.test(line) ? 'fg-blink' : '' });
            y += 18;
        });
        if (p.editor) {
            const e = p.editor;
            y = 44;
            e.lines.forEach(([label, start, end]) => {
                if (label) text(6, y, label, { 'font-size': 12, 'font-weight': 700 });
                e.chars.slice(start, end).forEach((c, j) => {
                    const x = (label ? 42 : 52) + j * 13;
                    text(x, y, c, { 'font-size': 14 });
                    if (start + j === e.cursor) el('rect', { x: x - 1, y: y + 3, width: 10, height: 2.5, fill: LCD_INK }, lcd);
                });
                y += 26;
            });
            const rowWidth = e.row.length * 13;
            e.row.forEach((c, i) => {
                const x = 110 - rowWidth / 2 + i * 13;
                if (i === e.sel) el('rect', { x: x - 2, y: 152, width: 13, height: 20, fill: LCD_INK }, lcd);
                text(x + 4.5, 167, c, { 'text-anchor': 'middle', 'font-size': 12, fill: i === e.sel ? LCD_ON : LCD_INK });
            });
        }
        if (typeof p.progress === 'number') {
            el('rect', { x: 20, y: 168, width: 180, height: 12, fill: 'none', stroke: LCD_INK, 'stroke-width': 2 }, lcd);
            el('rect', { x: 22, y: 170, width: 176 * p.progress, height: 8, fill: LCD_INK }, lcd);
        }
    }

    function drawDisplay(d) {
        lcd.replaceChildren();
        softkeys.forEach((g, i) => {
            const label = d.softkeys?.[i] ?? '';
            const aria = label ? `Softkey ${i + 1}: ${label}` : `Softkey ${i + 1}`;
            if (g.getAttribute('aria-label') !== aria) g.setAttribute('aria-label', aria);
        });
        light.set(d.on);
        if (! d.on) return;
        const st = d.status;
        drawSoftkeyBar(d);

        if (d.panel) {
            drawPanel(d.panel);

            return;
        }

        // Kopfzeile: RX/TX, Kanalgruppe, Sendeleistung, Akku
        const state = st.tx ? 'TX' : st.busy ? 'RX' : '';
        if (state) inverted(2, 2, 22, 15, state, 11);
        text(30, 14, st.group, { 'font-size': 12, 'font-weight': 700 });
        inverted(66, 2, 24, 15, st.power, 11);
        if (st.monitor) inverted(96, 2, 36, 15, 'MONI', 10);
        if (st.mail) text(140, 14, '✉', { 'font-size': 13 });
        el('rect', { x: 192, y: 4, width: 22, height: 10, rx: 1.5, fill: 'none', stroke: LCD_INK, 'stroke-width': 1.2 }, lcd);
        el('rect', { x: 194, y: 6, width: 18, height: 6, fill: LCD_INK }, lcd);
        el('rect', { x: 214, y: 7, width: 2, height: 4, fill: LCD_INK }, lcd);

        const channel = String(d.channel ?? '');
        el('text', { x: 0, y: 0, transform: 'translate(150 98) scale(0.9 1)', 'text-anchor': 'end', 'font-size': channel.length <= 2 ? 88 : 56, 'font-weight': 700, 'font-family': SANS }, lcd, channel);
        if (d.priority) text(152, 98, 'P', { 'font-size': 24, 'font-weight': 700 });
        if (d.sub) text(152, 98, 'S', { 'font-size': 24, 'font-weight': 700 });
        const right = [st.scan, d.watch ? `${d.watch} 16` : null, st.tag ? 'MEM' : null, st.dup ? 'DUP' : null].filter(Boolean);
        right.forEach((label, i) => inverted(172, 26 + i * 19, 46, 15, label, label.length > 6 ? 8.5 : 10));
        text(110, 120, d.channelName ?? '', { 'text-anchor': 'middle', 'font-size': 14, 'font-weight': 700 });
        // LAT/LON/TIME mit inversen Bezeichnungen
        el('rect', { x: 4, y: 126, width: 212, height: 58, rx: 3, fill: 'none', stroke: LCD_INK, 'stroke-width': 1.3 }, lcd);
        el('rect', { x: 4, y: 126, width: 36, height: 58, rx: 2, fill: LCD_INK }, lcd);
        (d.lines ?? []).slice(0, 3).forEach((line, i) => {
            const [label, ...rest] = line.split(' ');
            text(22, 141 + i * 18, label, { 'text-anchor': 'middle', 'font-size': 10.5, fill: LCD_ON, 'font-weight': 700 });
            text(46, 141 + i * 18, rest.join(' '), { 'font-size': 12 });
        });

        if (d.window) {
            el('rect', { x: 12, y: 30, width: 196, height: 72, rx: 5, fill: LCD_ON, stroke: LCD_INK, 'stroke-width': 1.5 }, lcd);
            text(110, 56, `${d.window.label} ${d.window.value}`, { 'text-anchor': 'middle', 'font-size': 16, 'font-weight': 700 });
            el('rect', { x: 24, y: 70, width: 172, height: 14, fill: 'none', stroke: LCD_INK, 'stroke-width': 1.5 }, lcd);
            el('rect', { x: 26, y: 72, width: 168 * d.window.level, height: 10, fill: LCD_INK }, lcd);
        }
    }

    function softkeyNode(display, label) {
        const index = (display.softkeys ?? []).indexOf(label);
        if (index >= 0) return softkeys[index];
        // Nicht sichtbar: die Blättertaste zur Seite mit diesem Softkey.
        const pages = display.pages;
        const page = pages?.labels.findIndex((labels) => labels.includes(label)) ?? -1;
        if (page < 0) return null;
        const forward = (page - pages.current + pages.count) % pages.count;

        return svg.querySelector(`[data-key="${forward <= pages.count / 2 ? 'right' : 'left'}"]`);
    }

    return {
        svg,
        update(display, radioState, target) {
            drawDisplay(display);
            Object.entries(radioState.coverOpen ? coverOpen : coverClosed).forEach(([name, value]) => coverLid.setAttribute(name, value));
            coverText.setAttribute('visibility', radioState.coverOpen ? 'hidden' : 'visible');

            let node = null;
            if (target?.startsWith('sk:')) node = softkeyNode(display, target.slice(3));
            else if (target) node = svg.querySelector(`[data-key="${target}"], [data-knob="${target}"]`);
            if (node) {
                const box = node.getBBox();
                highlight.setAttribute('x', box.x - 8);
                highlight.setAttribute('y', box.y - 8);
                highlight.setAttribute('width', box.width + 16);
                highlight.setAttribute('height', box.height + 16);
                highlight.setAttribute('visibility', 'visible');
            } else {
                highlight.setAttribute('visibility', 'hidden');
            }
        },
        rotateChannel() {},
    };
}
