// Zeichnung des Handfunkgeräts IC-M94DE (SVG), auf gute Bedienbarkeit
// ausgelegt statt fotogetreu, ohne Herstellerlogo und mit gekürzter Antenne:
// links die Vorderansicht mit großem Display, großen Softkeys, MENU, CLR,
// Steuerkreuz mit ENT, VOL/SQL, 16/C und kleinem Lautsprecher; Sprechtaste
// und Ein/Aus-Taste groß an der linken Seite. Rechts die ganze Rückseite mit
// DISTRESS unter der roten Schutzkappe, Gürtelclip und Akkudeckel. Tasten
// und Display leuchten bei eingeschaltetem Gerät. Gleiche Schnittstelle wie
// device-svg.js; ein Ziel "sk:LABEL" markiert den Softkey mit dieser
// Beschriftung bzw. die Blättertaste zur richtigen Seite.

import { createBacklight, glowFilter } from './backlight.js';

const NS = 'http://www.w3.org/2000/svg';
const LCD_ON = '#dfe7ee';
const LCD_INK = '#16222d';
const MONO = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';
const SANS = 'Arial, Helvetica, sans-serif';
const PRINT = '#e4e6ea';
const RIM = { on: '#f4ddb0', off: '#3a3c41' };
const LABEL = { on: '#fff6e2', off: '#8c9097' };

// Zeichenfläche 1000 × 940: Vorderansicht x 90–515, Rückseite x 610–950.
const F = (x, y) => [x, y];

// Die Anzeige wird in einer Fläche von 220 × 233 geschrieben und in das
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
    grad('m94-body', [[0, '#5a5d63'], [0.12, '#3c3f44'], [0.88, '#33363b'], [1, '#55585e']], { x1: 0, x2: 1, y1: 0, y2: 0 });
    grad('m94-face', [[0, '#2e3035'], [1, '#202226']]);
    grad('m94-bezel', [[0, '#16171a'], [1, '#0b0c0d']]);
    grad('m94-lcd', [[0, '#eef3f7'], [1, '#d3dde6']]);
    grad('m94-key', [[0, '#34363b'], [1, '#141518']]);
    grad('m94-blue', [[0, '#4a8fea'], [1, '#1b54b0']]);
    grad('m94-red', [[0, '#e0353f'], [1, '#8e0f16']]);
    grad('m94-side', [[0, '#2a2c30'], [1, '#121315']], { x1: 0, x2: 1, y1: 0, y2: 0 });
    grad('m94-antenna', [[0, '#2a2c30'], [0.5, '#3e4146'], [1, '#1b1c1f']], { x1: 0, x2: 1, y1: 0, y2: 0 });
    grad('m94-back', [[0, '#4b4e54'], [1, '#3a3d42']]);
    radial('m94-knob', [[0, '#4a4d52'], [1, '#141517']]);
    const shadow = el('filter', { id: 'm94-shadow', x: '-15%', y: '-15%', width: '140%', height: '140%' }, d);
    el('feDropShadow', { dx: 0, dy: 1.5, stdDeviation: 1.3, 'flood-color': '#000', 'flood-opacity': 0.6 }, shadow);
    const bodyShadow = el('filter', { id: 'm94-bodyshadow', x: '-10%', y: '-5%', width: '125%', height: '115%' }, d);
    el('feDropShadow', { dx: 3, dy: 6, stdDeviation: 6, 'flood-color': '#000', 'flood-opacity': 0.35 }, bodyShadow);
    glowFilter(d, 'm94-glow', NS, 1.2);
}

const control = (svg, attrs) => el('g', { tabindex: '0', role: 'button', class: 'fg-control', style: 'cursor:pointer', ...attrs }, svg);

// Beleuchtete Gummitaste zwischen zwei Fotopunkten; label darf mehrzeilig sein.
function key(svg, light, id, a, b, label, { ariaLabel = null, size = 15, rx = 10, bar = false, fill = 'url(#m94-key)', ink = null } = {}) {
    const [x1, y1] = F(...a);
    const [x2, y2] = F(...b);
    const w = x2 - x1;
    const h = y2 - y1;
    const g = control(svg, { 'data-key': id, 'aria-label': ariaLabel ?? [label].flat().join(' ') });
    const rim = el('rect', { x: x1, y: y1, width: w, height: h, rx, fill, stroke: ink ? '#0e3d86' : RIM.on, 'stroke-width': ink ? 1.5 : 2.2, filter: 'url(#m94-shadow)' }, g);
    if (! ink) light.add(rim, { stroke: RIM.on }, { stroke: RIM.off });
    el('rect', { x: x1 + 4, y: y1 + 3, width: w - 8, height: h * 0.35, rx: rx - 3, fill: '#ffffff', 'fill-opacity': 0.07, 'pointer-events': 'none' }, g);
    const lit = { fill: ink ?? LABEL.on, filter: ink ? null : 'url(#m94-glow)' };
    const dark = { fill: ink ?? LABEL.off, filter: null };
    if (bar) {
        light.add(el('rect', { x: x1 + w / 2 - 10, y: y1 + h / 2 - 2, width: 20, height: 4, rx: 2, ...lit, 'pointer-events': 'none' }, g), lit, dark);
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

export function renderDeviceM94DE(container) {
    const svg = el('svg', { viewBox: '0 0 1000 940', role: 'group', 'aria-label': 'Handfunkgerät IC-M94DE (Nachbildung), Vorder- und Rückseite', style: 'width:100%;height:auto;user-select:none;-webkit-user-select:none' });
    container.appendChild(svg);
    defs(svg);
    const light = createBacklight();

    // --- Vorderansicht ---------------------------------------------------------
    // Gekürzte Antenne rechts, Buchsenabdeckung links oben
    el('rect', { x: 398, y: 8, width: 26, height: 70, rx: 13, fill: 'url(#m94-antenna)', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    el('path', { d: 'M388 70 L 434 70 L 446 112 L 376 112 Z', fill: 'url(#m94-antenna)', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    for (let i = 0; i < 4; i++) el('line', { x1: 384, y1: 80 + i * 8, x2: 438, y2: 80 + i * 8, stroke: '#4a4d52', 'stroke-width': 1.2 }, svg);
    el('rect', { x: 200, y: 64, width: 70, height: 44, rx: 11, fill: 'url(#m94-knob)', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    for (let i = 0; i < 10; i++) el('line', { x1: 208 + i * 6, y1: 68, x2: 208 + i * 6, y2: 82, stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);

    // Große Sprechtaste und Ein/Aus-Taste an der linken Seite
    const ptt = control(svg, { 'data-key': 'ptt', 'aria-label': 'Sprechtaste (PTT) gedrückt halten' });
    el('rect', { x: 86, y: 220, width: 60, height: 250, rx: 20, fill: 'url(#m94-side)', stroke: '#0b0c0d', 'stroke-width': 2.5, filter: 'url(#m94-shadow)' }, ptt);
    for (let i = 0; i < 12; i++) el('rect', { x: 94, y: 252 + i * 16, width: 22, height: 4, rx: 2, fill: '#44474c', 'pointer-events': 'none' }, ptt);
    const power = control(svg, { 'data-key': 'power', 'aria-label': 'Ein/Aus (1 Sekunde halten)' });
    el('rect', { x: 86, y: 500, width: 60, height: 86, rx: 16, fill: 'url(#m94-side)', stroke: '#0b0c0d', 'stroke-width': 2.5, filter: 'url(#m94-shadow)' }, power);
    el('text', { x: 107, y: 552, 'text-anchor': 'middle', 'font-size': 24, 'font-weight': 700, fill: '#9a9ea6', 'font-family': SANS, 'pointer-events': 'none' }, power, '⏻');

    // Gehäuse mit hellerem Seitenrand und dunkler Front
    el('rect', { x: 126, y: 104, width: 392, height: 818, rx: 72, fill: 'url(#m94-body)', stroke: '#0b0c0d', 'stroke-width': 3, filter: 'url(#m94-bodyshadow)' }, svg);
    el('rect', { x: 142, y: 120, width: 360, height: 786, rx: 60, fill: 'url(#m94-face)', stroke: '#0b0c0d', 'stroke-width': 2 }, svg);

    el('text', { x: 98, y: 210, 'text-anchor': 'middle', 'font-size': 18, 'font-weight': 700, fill: '#6b6e74', 'font-family': SANS }, svg, 'PTT');

    // Großes Display mit Rahmen
    el('rect', { x: 162, y: 146, width: 320, height: 352, rx: 18, fill: 'url(#m94-bezel)', stroke: '#050506', 'stroke-width': 2 }, svg);
    const lcdBox = { x: 172, y: 156, width: 300, height: 318 };
    light.add(el('rect', { ...lcdBox, rx: 8, fill: 'url(#m94-lcd)' }, svg), { fill: 'url(#m94-lcd)' }, { fill: '#5b6066' });
    const k = lcdBox.width / LCD_W;
    const lcd = el('g', { 'font-family': MONO, fill: LCD_INK, transform: `translate(${lcdBox.x} ${lcdBox.y}) scale(${k})` }, svg);
    el('text', { x: 322, y: 491, 'text-anchor': 'middle', 'font-size': 15, 'font-weight': 700, fill: PRINT, 'font-family': SANS, 'letter-spacing': 1 }, svg, 'IC-M94DE');

    // Softkeys groß unter den Feldern im Display
    const softkeys = [[172, 266], [275, 369], [378, 472]].map(([a, b], i) => key(svg, light, `soft${i + 1}`, [a, 510], [b, 558], '', { ariaLabel: `Softkey ${i + 1}`, bar: true, rx: 14 }));

    // MENU, CLR, VOL/SQL, 16/C
    key(svg, light, 'menu', [160, 578], [246, 638], 'MENU', { size: 19, rx: 16 });
    key(svg, light, 'clr', [398, 578], [484, 638], ['CLR', '⚿'], { ariaLabel: 'CLR (1 Sekunde: Tastensperre)', size: 17, rx: 16 });
    key(svg, light, 'volsql', [160, 654], [246, 714], ['VOL', 'SQL'], { ariaLabel: 'VOL/SQL (1×: Lautstärke, 2×: Rauschsperre, 1 Sekunde: Monitor)', size: 17, rx: 16 });
    key(svg, light, 'ch16', [398, 654], [484, 714], '16/C', { ariaLabel: '16/C: Kanal 16, 1 Sekunde Anrufkanal', size: 21, rx: 16, fill: 'url(#m94-blue)', ink: '#ffffff' });

    // Großes Steuerkreuz ▲▼◄► als Ring mit ENT in der Mitte
    const cx = 322;
    const cy = 646;
    const R = 72;
    const r = 30;
    el('circle', { cx, cy, r: R + 4, fill: '#0b0c0d' }, svg);
    const wedge = (id, angle, glyph, ariaLabel) => {
        const a1 = (angle - 44) * Math.PI / 180;
        const a2 = (angle + 44) * Math.PI / 180;
        const pt = (rad, a) => [cx + Math.sin(a) * rad, cy - Math.cos(a) * rad];
        const [o1x, o1y] = pt(R, a1);
        const [o2x, o2y] = pt(R, a2);
        const [i2x, i2y] = pt(r + 3, a2);
        const [i1x, i1y] = pt(r + 3, a1);
        const g = control(svg, { 'data-key': id, 'aria-label': ariaLabel });
        const seg = el('path', { d: `M${o1x} ${o1y} A ${R} ${R} 0 0 1 ${o2x} ${o2y} L ${i2x} ${i2y} A ${r + 3} ${r + 3} 0 0 0 ${i1x} ${i1y} Z`, fill: 'url(#m94-key)', stroke: RIM.on, 'stroke-width': 1.6 }, g);
        light.add(seg, { stroke: RIM.on }, { stroke: RIM.off });
        const [gx, gy] = pt((R + r) / 2 + 1, angle * Math.PI / 180);
        const t = el('text', { x: gx, y: gy + 5, 'text-anchor': 'middle', 'font-size': 20, 'font-weight': 700, 'font-family': SANS, fill: LABEL.on, filter: 'url(#m94-glow)', 'pointer-events': 'none' }, g, glyph);
        light.add(t, { fill: LABEL.on, filter: 'url(#m94-glow)' }, { fill: LABEL.off, filter: null });
    };
    wedge('up', 0, '▲', 'Kanal auf / ▲');
    wedge('right', 90, '▶', 'rechts / Softkey-Seite vor');
    wedge('down', 180, '▼', 'Kanal ab / ▼');
    wedge('left', 270, '◀', 'links / Softkey-Seite zurück');
    const enter = control(svg, { 'data-key': 'enter', 'aria-label': 'ENT' });
    el('circle', { cx, cy, r, fill: 'url(#m94-key)', stroke: '#0b0c0d', 'stroke-width': 1.5 }, enter);
    light.add(el('circle', { cx, cy, r: r - 6, fill: '#1d5fbf', 'fill-opacity': 0.85, 'pointer-events': 'none' }, enter), { 'fill-opacity': 0.85 }, { 'fill-opacity': 0.25 });

    // Kleiner Lautsprecher mit Mikrofon
    el('text', { x: 172, y: 752, 'font-size': 11, 'font-weight': 700, fill: '#6b6e74', 'font-family': SANS }, svg, 'MIC');
    for (let i = 0; i < 5; i++) {
        const y = 772 + i * 24;
        const inset = i === 4 ? 30 : 0;
        el('rect', { x: 176 + inset, y, width: 292 - inset * 2, height: 11, rx: 5.5, fill: '#0e0f11' }, svg);
        el('line', { x1: 182 + inset, y1: y + 12.5, x2: 462 - inset, y2: y + 12.5, stroke: '#55585e', 'stroke-opacity': 0.6, 'stroke-width': 1.2 }, svg);
    }

    // --- Ganze Rückseite mit DISTRESS, Gürtelclip und Akkudeckel ----------------
    el('text', { x: 780, y: 40, 'text-anchor': 'middle', 'font-size': 20, 'font-weight': 700, fill: '#64748b', 'font-family': SANS }, svg, 'Rückseite');
    // Von hinten: Antenne links, Buchsenabdeckung rechts
    el('rect', { x: 680, y: 60, width: 22, height: 60, rx: 11, fill: 'url(#m94-antenna)', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    el('path', { d: 'M671 112 L 711 112 L 721 148 L 661 148 Z', fill: 'url(#m94-antenna)', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    el('rect', { x: 820, y: 108, width: 60, height: 38, rx: 10, fill: 'url(#m94-knob)', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    // Seitentasten von hinten (rechts, ohne Funktion)
    el('rect', { x: 930, y: 270, width: 22, height: 210, rx: 9, fill: 'url(#m94-side)', stroke: '#0b0c0d', 'stroke-width': 2 }, svg);
    el('rect', { x: 930, y: 506, width: 22, height: 74, rx: 8, fill: 'url(#m94-side)', stroke: '#0b0c0d', 'stroke-width': 2 }, svg);
    el('rect', { x: 610, y: 140, width: 334, height: 782, rx: 64, fill: 'url(#m94-back)', stroke: '#0b0c0d', 'stroke-width': 3, filter: 'url(#m94-bodyshadow)' }, svg);
    for (const hx of [656, 898]) el('circle', { cx: hx, cy: 184, r: 13, fill: '#111214' }, svg);
    // Gürtelclip-Schiene unter DISTRESS
    el('rect', { x: 752, y: 342, width: 50, height: 92, rx: 6, fill: '#141517', stroke: '#55585e', 'stroke-width': 1.2 }, svg);
    for (const y of [370, 406]) el('circle', { cx: 777, cy: y, r: 4, fill: '#3a3c41' }, svg);
    // Akkudeckel mit Verriegelung unten
    el('path', { d: 'M640 470 Q 640 440 670 440 L 884 440 Q 914 440 914 470 L 914 840 Q 914 896 858 896 L 696 896 Q 640 896 640 840 Z', fill: '#202226', stroke: '#0b0c0d', 'stroke-width': 2 }, svg);
    el('path', { d: 'M652 480 Q 652 452 680 452 L 874 452 Q 902 452 902 480', fill: 'none', stroke: '#55585e', 'stroke-opacity': 0.6, 'stroke-width': 1.5 }, svg);
    el('rect', { x: 750, y: 862, width: 56, height: 22, rx: 5, fill: '#141517', stroke: '#55585e', 'stroke-width': 1.2 }, svg);
    el('text', { x: 777, y: 680, 'text-anchor': 'middle', 'font-size': 14, 'font-weight': 700, fill: '#55585e', 'font-family': SANS }, svg, 'Akkudeckel');
    // DISTRESS unter der roten Schutzkappe (groß)
    el('rect', { x: 712, y: 196, width: 130, height: 110, rx: 12, fill: '#1c1d20', stroke: '#6a6d73', 'stroke-width': 1.5 }, svg);
    const db1x = 724;
    const db1y = 214;
    const db2x = 830;
    const db2y = 294;
    const distress = control(svg, { 'data-key': 'distress', 'aria-label': 'DISTRESS (3 Sekunden halten)' });
    el('rect', { x: db1x, y: db1y, width: db2x - db1x, height: db2y - db1y, rx: 8, fill: 'url(#m94-red)', stroke: '#5b080d', 'stroke-width': 2 }, distress);
    el('text', { x: (db1x + db2x) / 2, y: (db1y + db2y) / 2 + 6, 'text-anchor': 'middle', 'font-size': 17, 'font-weight': 700, fill: '#ffffff', 'font-family': SANS, 'pointer-events': 'none' }, distress, 'DISTRESS');
    const cover = control(svg, { 'data-key': 'distressCover', 'aria-label': 'Schutzkappe der DISTRESS-Taste' });
    const coverClosed = { y: db1y - 12, height: db2y - db1y + 24 };
    const coverOpen = { y: db1y - 56, height: 38 };
    const coverLid = el('rect', { x: db1x - 10, ...coverClosed, width: db2x - db1x + 20, rx: 10, fill: '#e2333d', 'fill-opacity': 0.55, stroke: '#9b1219', 'stroke-width': 2 }, cover);

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
            inverted(10, 205, 200, 22, '⚿ Key Locked', 12);

            return;
        }
        for (const f of fields(d.softkeys ?? [])) {
            if (! f.label) continue;
            const x = 10 + f.start * 68;
            const w = f.span * 68 - 4;
            inverted(x, 205, w, 22, f.label, f.label.length > 7 ? 9.5 : f.label.length > 5 ? 10.5 : 12);
        }
        if (d.pages) {
            if (d.pages.current > 0) text(1, 221, '◂', { 'font-size': 11 });
            if (d.pages.current < d.pages.count - 1) text(211, 221, '▸', { 'font-size': 11 });
        }
    }

    function drawPanel(p) {
        if (p.title) {
            el('rect', { x: 0, y: 0, width: LCD_W, height: 19, fill: LCD_INK }, lcd);
            text(110, 14, p.title.startsWith('≡') || p.title.startsWith('!!') ? p.title : `≡ ${p.title} ≡`, { 'text-anchor': 'middle', 'font-size': 11, fill: LCD_ON, 'font-weight': 700 });
        }
        let y = 38;
        if (p.note) {
            text(110, y, p.note, { 'text-anchor': 'middle', 'font-size': 10 });
            y += 18;
        }
        if (p.items) {
            const visible = p.note ? 7 : 8;
            const first = Math.max(0, Math.min(p.cursor - 3, p.items.length - visible));
            p.items.slice(first, first + visible).forEach((item, i) => {
                const selected = first + i === p.cursor;
                if (selected) el('rect', { x: 2, y: y - 13, width: 216, height: 17, fill: LCD_INK }, lcd);
                text(6, y, item.length > 30 ? `${item.slice(0, 29)}…` : item, { 'font-size': 11.5, fill: selected ? LCD_ON : LCD_INK });
                y += 18;
            });
        }
        (p.lines ?? []).forEach((line) => {
            text(110, y, line, { 'text-anchor': 'middle', 'font-size': 13, class: p.blink ? 'fg-blink' : '' });
            y += 19;
        });
        if (p.editor) {
            const e = p.editor;
            const width = e.chars.length * 13;
            e.chars.forEach((c, i) => {
                const x = 110 - width / 2 + i * 13;
                text(x, y + 4, c, { 'font-size': 15 });
                if (i === e.cursor) el('rect', { x: x - 1, y: y + 8, width: 11, height: 2.5, fill: LCD_INK }, lcd);
            });
            const rowWidth = e.row.length * 15;
            e.row.forEach((c, i) => {
                const x = 110 - rowWidth / 2 + i * 15;
                if (i === e.sel) el('rect', { x: x - 2, y: y + 18, width: 14, height: 18, fill: LCD_INK }, lcd);
                text(x + 5, y + 32, c, { 'text-anchor': 'middle', 'font-size': 12, fill: i === e.sel ? LCD_ON : LCD_INK });
            });
        }
        if (typeof p.progress === 'number') {
            el('rect', { x: 20, y: 150, width: 180, height: 12, fill: 'none', stroke: LCD_INK, 'stroke-width': 2 }, lcd);
            el('rect', { x: 22, y: 152, width: 176 * p.progress, height: 8, fill: LCD_INK }, lcd);
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

        // Kopfzeilen: eigene MMSI und Akku, darunter Leistung und Kanalgruppe
        text(4, 13, `MMSI:${d.mmsi ?? '---------'}`, { 'font-size': 11 });
        el('rect', { x: 196, y: 4, width: 18, height: 9, rx: 1.5, fill: 'none', stroke: LCD_INK, 'stroke-width': 1.2 }, lcd);
        el('rect', { x: 198, y: 6, width: 14, height: 5, fill: LCD_INK }, lcd);
        el('rect', { x: 214, y: 6.5, width: 2, height: 4, fill: LCD_INK }, lcd);
        const state = st.tx ? 'TX' : st.busy ? 'BUSY' : st.monitor ? 'MONI' : '';
        if (state) inverted(4, 17, state.length * 8 + 8, 14, state, 10);
        text(110, 29, [st.power, st.group].filter(Boolean).join('  '), { 'text-anchor': 'middle', 'font-size': 12, 'font-weight': 700 });
        if (st.mail) text(176, 29, '✉', { 'font-size': 12 });

        inverted(4, 50, 40, 16, d.mode ?? 'STBY', 10.5);
        if (d.watch) inverted(4, 72, 52, 15, d.watch, 9.5);
        const channel = String(d.channel ?? '');
        el('text', { x: 0, y: 0, transform: 'translate(170 116) scale(0.86 1)', 'text-anchor': 'end', 'font-size': channel.length <= 2 ? 92 : 58, 'font-weight': 700, 'font-family': SANS }, lcd, channel);
        if (st.tag) text(176, 58, '★', { 'font-size': 20 });
        [st.call ? 'CALL' : '', st.dup ? 'DUP' : ''].filter(Boolean).forEach((label, i) => text(216, 80 + i * 15, label, { 'text-anchor': 'end', 'font-size': 10.5, 'font-weight': 700 }));
        text(110, 136, d.channelName ?? '', { 'text-anchor': 'middle', 'font-size': 14, 'font-weight': 700 });
        el('rect', { x: 14, y: 142, width: 192, height: 56, rx: 3, fill: 'none', stroke: LCD_INK, 'stroke-width': 1.3 }, lcd);
        (d.lines ?? []).slice(0, 3).forEach((line, i) => text(110, 158 + i * 17, line, { 'text-anchor': 'middle', 'font-size': 12.5 }));

        if (d.window) {
            el('rect', { x: 12, y: 60, width: 196, height: 76, rx: 5, fill: LCD_ON, stroke: LCD_INK, 'stroke-width': 1.5 }, lcd);
            text(110, 86, `${d.window.label} ${d.window.value}`, { 'text-anchor': 'middle', 'font-size': 15, 'font-weight': 700 });
            el('rect', { x: 24, y: 100, width: 172, height: 12, fill: 'none', stroke: LCD_INK, 'stroke-width': 1.5 }, lcd);
            el('rect', { x: 26, y: 102, width: 168 * d.window.level, height: 8, fill: LCD_INK }, lcd);
        }
    }

    function softkeyNode(display, label) {
        const index = (display.softkeys ?? []).indexOf(label);
        if (index >= 0) return softkeys[index];
        // Nicht sichtbar: die Blättertaste in Richtung der richtigen Seite.
        const pages = display.pages;
        const page = pages?.labels.findIndex((labels) => labels.includes(label)) ?? -1;
        if (page < 0) return null;
        const direction = page >= 0 && page < pages.current ? 'left' : 'right';

        return svg.querySelector(`[data-key="${direction}"]`);
    }

    return {
        svg,
        update(display, radioState, target) {
            drawDisplay(display);
            Object.entries(radioState.coverOpen ? coverOpen : coverClosed).forEach(([name, value]) => coverLid.setAttribute(name, value));

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
