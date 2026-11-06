// Zeichnung des IC-M330 (SVG) im Stil der Frontansicht, ohne Halterung und
// ohne Herstellerlogo: dunkles Gehäuse, links Lautsprecherschlitze, darunter
// die Kabeldurchführung und DISTRESS unter der roten Klappe, in der Mitte
// das orange Display mit Softkey-Leiste (Seitenmarken ◂ ▸, breite Felder
// DISTRESS und OTHER DSC) und vier Softkeys, rechts CH▲/CH▼, ◀ ENT ▶, MENU
// und CLR, unten rechts 16/C und der Drehknopf PWR/VOL/SQL. Links der Hörer
// mit HI/LO, ▲/▼, 16/C und Sprechtaste. Die Tasten leuchten bei
// eingeschaltetem Gerät (Hintergrundbeleuchtung). Gleiche Schnittstelle wie
// device-svg.js; ein Ziel "sk:LABEL" markiert den Softkey mit dieser
// Beschriftung bzw. die Blättertaste zur richtigen Seite.

import { createBacklight, glowFilter } from './backlight.js';
import { drawHandset } from './handset.js';

const NS = 'http://www.w3.org/2000/svg';
const LCD_INK = '#2b1806';
const LCD_LIGHT = '#f6a640';
const MONO = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';
const SANS = 'Arial, Helvetica, sans-serif';
const PRINT = '#e4e6ea';

// Beleuchtete Tasten: heller Rand und Beschriftung, kupferfarbene Kante.
const RIM = { on: '#f4ddb0', off: '#3a3c41' };
const EDGE = { on: '#6b4322', off: '#141517' };
const LABEL = { on: '#fff1d2', off: '#8c9097' };

// Koordinaten der Frontansicht (Foto, Gerät ohne Halterung) auf die
// Zeichenfläche: Gehäuse x 180–988, y 25–377.
const Q = (px, py) => [180 + (px - 25) * 0.6906, 25 + (py - 15) * 0.6906];

// Die Anzeige wird in einer Fläche von 400 × 204 geschrieben und in das
// Display eingepasst.
const LCD_W = 400;

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
    grad('m330-frame', [[0, '#4a4d53'], [0.1, '#34363b'], [1, '#1b1c1f']]);
    grad('m330-face', [[0, '#36383d'], [1, '#26282c']]);
    grad('m330-bezel', [[0, '#1f2023'], [1, '#0f1012']]);
    grad('m330-lcd', [[0, '#f7b24d'], [0.55, '#f29d33'], [1, '#e6892a']]);
    grad('m330-key', [[0, '#2e3034'], [1, '#121315']]);
    grad('m330-red', [[0, '#e0353f'], [1, '#8e0f16']]);
    grad('m330-blue', [[0, '#4a8fea'], [1, '#1b54b0']]);
    grad('m330-hinge', [[0, '#c4c7cc'], [1, '#5f6268']]);
    radial('m330-knob', [[0, '#3f4247'], [0.75, '#1f2023'], [1, '#111214']]);
    radial('m330-cap', [[0, '#383b40'], [1, '#17181b']]);
    radial('m330-gland', [[0, '#3a3c40'], [1, '#121315']]);
    const shadow = el('filter', { id: 'm330-shadow', x: '-15%', y: '-15%', width: '140%', height: '140%' }, d);
    el('feDropShadow', { dx: 0, dy: 1.5, stdDeviation: 1.3, 'flood-color': '#000', 'flood-opacity': 0.6 }, shadow);
    glowFilter(d, 'm330-glow', NS, 1.4);
}

// Beleuchtete Taste zwischen zwei Fotopunkten; label darf mehrzeilig sein
// (z. B. ['▲', 'CH']), bar zeichnet den Strich der Softkeys.
function key(svg, light, id, a, b, label, { ariaLabel = null, size = 13, rx = 9, bar = false } = {}) {
    const [x1, y1] = Q(...a);
    const [x2, y2] = Q(...b);
    const w = x2 - x1;
    const h = y2 - y1;
    const g = el('g', { 'data-key': id, tabindex: '0', role: 'button', 'aria-label': ariaLabel ?? [label].flat().join(' '), class: 'fg-control', style: 'cursor:pointer' }, svg);
    light.add(el('rect', { x: x1 - 2, y: y1 - 2, width: w + 4, height: h + 4, rx: rx + 2, fill: EDGE.on, filter: 'url(#m330-shadow)' }, g), { fill: EDGE.on }, { fill: EDGE.off });
    light.add(el('rect', { x: x1, y: y1, width: w, height: h, rx, fill: 'url(#m330-key)', stroke: RIM.on, 'stroke-width': 2.6 }, g), { stroke: RIM.on }, { stroke: RIM.off });
    el('rect', { x: x1 + 4, y: y1 + 3, width: w - 8, height: h * 0.35, rx: rx - 3, fill: '#ffffff', 'fill-opacity': 0.06, 'pointer-events': 'none' }, g);
    const lit = { fill: LABEL.on, filter: 'url(#m330-glow)' };
    const dark = { fill: LABEL.off, filter: null };
    if (bar) {
        light.add(el('rect', { x: x1 + w / 2 - 11, y: y1 + h / 2 - 2, width: 22, height: 4, rx: 2, ...lit, 'pointer-events': 'none' }, g), lit, dark);
    } else {
        const lines = [label].flat();
        const lineHeight = size * 1.05;
        const top = y1 + h / 2 - (lines.length * lineHeight) / 2 + size * 0.82;
        lines.forEach((line, i) => {
            const t = el('text', { x: x1 + w / 2, y: top + i * lineHeight, 'text-anchor': 'middle', 'font-size': size, 'font-weight': 700, 'font-family': SANS, ...lit, 'pointer-events': 'none' }, g, line);
            light.add(t, lit, dark);
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

export function renderDeviceM330(container) {
    const svg = el('svg', { viewBox: '0 0 1000 410', role: 'group', 'aria-label': 'Funkgerät IC-M330 (Nachbildung)', style: 'width:100%;height:auto;user-select:none;-webkit-user-select:none' });
    container.appendChild(svg);
    defs(svg);
    const light = createBacklight();

    // Gehäuse
    el('rect', { x: 180, y: 25, width: 808, height: 352, rx: 38, fill: 'url(#m330-frame)', stroke: '#0d0e10', 'stroke-width': 3 }, svg);
    el('rect', { x: 184, y: 28, width: 800, height: 345, rx: 35, fill: 'none', stroke: '#ffffff', 'stroke-opacity': 0.12, 'stroke-width': 2 }, svg);
    el('rect', { x: 190.4, y: 35.4, width: 787.2, height: 324.6, rx: 28, fill: 'url(#m330-face)', stroke: '#16171a', 'stroke-width': 2 }, svg);
    el('rect', { x: 210, y: 364, width: 748, height: 6, rx: 3, fill: '#6a6d73', 'fill-opacity': 0.45 }, svg);

    // Lautsprecherschlitze
    el('rect', { x: 199, y: 63, width: 186, height: 190, rx: 18, fill: '#1f2023', stroke: '#101113', 'stroke-width': 1.5 }, svg);
    for (const cy of [96.1, 127.2, 157.6, 188.7, 219.7]) {
        el('rect', { x: 222, y: cy - 8.5, width: 144, height: 17, rx: 8.5, fill: '#0f1012' }, svg);
        el('line', { x1: 228, y1: cy + 9.5, x2: 360, y2: cy + 9.5, stroke: '#6a6d73', 'stroke-opacity': 0.55, 'stroke-width': 1.6 }, svg);
    }

    // Display mit Rahmen und Typenzeile; Striche zu den Softkeys
    el('rect', { x: 401, y: 63, width: 369.5, height: 231.3, rx: 24, fill: 'url(#m330-bezel)', stroke: '#0b0c0d', 'stroke-width': 2 }, svg);
    el('text', { x: 732, y: 95, 'text-anchor': 'end', 'font-size': 13.5, 'font-weight': 700, fill: PRINT, 'font-family': SANS, 'letter-spacing': 0.5 }, svg, 'IC-M330');
    el('text', { x: 666, y: 95, 'text-anchor': 'end', 'font-size': 8.5, 'font-weight': 700, fill: PRINT, 'font-family': SANS }, svg, 'VHF MARINE');
    const [lx1, ly1] = Q(393, 130);
    const [lx2, ly2] = Q(825, 350);
    const lcdBox = { x: lx1, y: ly1, width: lx2 - lx1, height: ly2 - ly1 };
    light.add(el('rect', { ...lcdBox, rx: 6, fill: 'url(#m330-lcd)' }, svg), { fill: 'url(#m330-lcd)' }, { fill: '#4b4840' });
    const k = lcdBox.width / LCD_W;
    const lcd = el('g', { 'font-family': MONO, fill: LCD_INK, transform: `translate(${lcdBox.x} ${lcdBox.y}) scale(${k})` }, svg);
    for (const x of [456.2, 535.6, 620.6, 703.4]) el('line', { x1: x, y1: 270, x2: x, y2: 281, stroke: PRINT, 'stroke-width': 2.4, 'stroke-linecap': 'round' }, svg);

    // Softkeys
    const softkeys = [[385, 480], [503, 598], [620, 715], [735, 832]].map(([a, b], i) => key(svg, light, `soft${i + 1}`, [a, 418], [b, 475], '', { ariaLabel: `Softkey ${i + 1}`, bar: true, rx: 11 }));

    // Kabeldurchführung mit Kabel
    const [gx, gy] = Q(140, 400);
    el('path', { d: `M${gx - 24} ${gy + 60} C ${gx - 30} ${gy + 80}, ${gx - 34} ${gy + 100}, ${gx - 36} 412`, fill: 'none', stroke: '#141517', 'stroke-width': 12 }, svg);
    el('path', { d: `M${gx - 14} ${gy + 6} L ${gx - 34} ${gy + 58} L ${gx - 14} ${gy + 64} L ${gx + 8} ${gy + 12} Z`, fill: '#1a1b1e', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    for (let i = 0; i < 5; i++) el('line', { x1: gx - 14 - i * 4, y1: gy + 16 + i * 9, x2: gx + 4 - i * 4, y2: gy + 22 + i * 9, stroke: '#3a3c41', 'stroke-width': 2 }, svg);
    el('circle', { cx: gx, cy: gy, r: 30, fill: 'url(#m330-gland)', stroke: '#0b0c0d', 'stroke-width': 2 }, svg);
    el('circle', { cx: gx, cy: gy, r: 20, fill: '#1a1b1e', stroke: '#4a4d52', 'stroke-width': 1.5 }, svg);

    // DISTRESS mit Klappe
    const [dx1, dy1] = Q(215, 355);
    const [dx2, dy2] = Q(340, 505);
    el('rect', { x: dx1, y: dy1 + 4, width: dx2 - dx1, height: dy2 - dy1 - 4, rx: 8, fill: '#141517', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    const distress = el('g', { 'data-key': 'distress', tabindex: '0', role: 'button', 'aria-label': 'DISTRESS (3 Sekunden halten)', class: 'fg-control', style: 'cursor:pointer' }, svg);
    el('rect', { x: dx1 + 6, y: dy1 + 24, width: dx2 - dx1 - 12, height: dy2 - dy1 - 30, rx: 6, fill: 'url(#m330-red)', stroke: '#5b080d', 'stroke-width': 2 }, distress);
    el('text', { x: (dx1 + dx2) / 2, y: dy2 - 32, 'text-anchor': 'middle', 'font-size': 12.5, 'font-weight': 700, fill: '#ffffff', 'font-family': SANS, 'pointer-events': 'none' }, distress, 'DISTRESS');
    el('rect', { x: dx1 + 2, y: dy1, width: dx2 - dx1 - 4, height: 18, rx: 5, fill: 'url(#m330-hinge)', stroke: '#3a3c41', 'stroke-width': 1 }, svg);
    const cover = el('g', { 'data-key': 'distressCover', tabindex: '0', role: 'button', 'aria-label': 'Schutzklappe der DISTRESS-Taste', class: 'fg-control', style: 'cursor:pointer' }, svg);
    const coverClosed = { y: dy1 + 12, height: dy2 - dy1 - 14 };
    const coverOpen = { y: dy1 - 28, height: 28 };
    const coverLid = el('rect', { x: dx1 + 2, ...coverClosed, width: dx2 - dx1 - 4, rx: 7, fill: '#e2333d', 'fill-opacity': 0.55, stroke: '#9b1219', 'stroke-width': 2 }, cover);

    // Tastenkreuz, MENU, CLR
    key(svg, light, 'up', [978, 80], [1068, 135], ['▲', 'CH'], { ariaLabel: 'Kanal auf / ▲', size: 11.5, rx: 12 });
    key(svg, light, 'left', [900, 150], [970, 225], '◀', { ariaLabel: 'links / Softkey-Seite zurück', size: 16, rx: 12 });
    key(svg, light, 'enter', [980, 155], [1065, 225], 'ENT', { size: 14, rx: 14 });
    key(svg, light, 'right', [1080, 150], [1150, 225], '▶', { ariaLabel: 'rechts / Softkey-Seite vor', size: 16, rx: 12 });
    key(svg, light, 'down', [978, 245], [1068, 300], ['CH', '▼'], { ariaLabel: 'Kanal ab / ▼', size: 11.5, rx: 12 });
    key(svg, light, 'menu', [895, 270], [965, 320], 'MENU', { size: 11.5, rx: 11 });
    key(svg, light, 'clr', [1078, 270], [1150, 320], 'CLR', { size: 13, rx: 11 });

    // 16/C
    const [cx16, cy16] = Q(905, 418);
    const ch16 = el('g', { 'data-key': 'ch16', tabindex: '0', role: 'button', 'aria-label': '16/C: Kanal 16, 1 Sekunde Anrufkanal', class: 'fg-control', style: 'cursor:pointer' }, svg);
    light.add(el('circle', { cx: cx16, cy: cy16, r: 31, fill: '#9a6532', filter: 'url(#m330-shadow)' }, ch16), { fill: '#9a6532' }, { fill: '#2a2b2e' });
    el('circle', { cx: cx16, cy: cy16, r: 26, fill: 'url(#m330-blue)', stroke: '#0e3d86', 'stroke-width': 1.5 }, ch16);
    el('text', { x: cx16, y: cy16 + 5.5, 'text-anchor': 'middle', 'font-size': 15, 'font-weight': 700, fill: '#ffffff', 'font-family': SANS, 'pointer-events': 'none' }, ch16, '16/C');

    // Drehknopf PWR/VOL/SQL: Ring drehen, Kappe drücken
    const [kx, ky] = Q(1065, 435);
    el('text', { x: 808, y: 247, 'text-anchor': 'middle', 'font-size': 10.5, 'font-weight': 700, fill: PRINT, 'font-family': SANS }, svg, 'VOL/SQL');
    el('rect', { x: 794, y: 251, width: 28, height: 12, rx: 2, fill: PRINT }, svg);
    el('text', { x: 808, y: 260.5, 'text-anchor': 'middle', 'font-size': 9, 'font-weight': 700, fill: '#1c1d20', 'font-family': SANS }, svg, 'PWR');
    el('circle', { cx: 843, cy: 257, r: 5.5, fill: 'none', stroke: PRINT, 'stroke-width': 1.5 }, svg);
    el('circle', { cx: 843, cy: 257, r: 1.8, fill: PRINT }, svg);
    const ring = el('g', { 'data-knob': 'dial', tabindex: '0', role: 'slider', 'aria-label': 'Drehknopf PWR/VOL/SQL', class: 'fg-control', style: 'cursor:ns-resize;touch-action:none' }, svg);
    el('circle', { cx: kx, cy: ky, r: 52, fill: 'url(#m330-knob)', stroke: '#0b0c0d', 'stroke-width': 2, filter: 'url(#m330-shadow)' }, ring);
    for (let i = 0; i < 36; i++) {
        const a = (i * 10) * Math.PI / 180;
        el('line', { x1: kx + Math.sin(a) * 45, y1: ky - Math.cos(a) * 45, x2: kx + Math.sin(a) * 51, y2: ky - Math.cos(a) * 51, stroke: '#0a0a0b', 'stroke-width': 1.6 }, ring);
    }
    const pointer = el('line', { x1: kx, y1: ky - 33, x2: kx, y2: ky - 44, stroke: '#cfd2d7', 'stroke-width': 3, 'stroke-linecap': 'round' }, ring);
    const cap = el('g', { 'data-key': 'knob', tabindex: '0', role: 'button', 'aria-label': 'Drehknopf drücken (1×: Lautstärke, 2×: Rauschsperre, 1 Sekunde: Ein/Aus)', class: 'fg-control', style: 'cursor:pointer' }, svg);
    el('circle', { cx: kx, cy: ky, r: 27, fill: 'url(#m330-cap)', stroke: '#0b0c0d', 'stroke-width': 1.5 }, cap);

    // Hörer vor der linken Gehäusekante
    drawHandset(svg, { prefix: 'm330', model: 'egg', x: 25, y: 14, scale: 1.04, bottom: 410 });

    const highlight = el('rect', { fill: 'none', stroke: '#e3b505', 'stroke-width': 4, 'stroke-dasharray': '10 6', rx: 14, visibility: 'hidden', 'pointer-events': 'none' }, svg);

    function text(x, y, value, attrs = {}) {
        el('text', { x, y, 'font-size': 17, ...attrs }, lcd, value);
    }

    function drawSoftkeyBar(d) {
        for (const f of fields(d.softkeys ?? [])) {
            if (! f.label) continue;
            const x = 12 + f.start * 94.5;
            const w = f.span * 94.5 - 3;
            el('rect', { x, y: 174, width: w, height: 27, rx: 2, fill: LCD_INK }, lcd);
            text(x + w / 2, 194, f.label, { 'text-anchor': 'middle', 'font-size': 18, fill: LCD_LIGHT, 'font-weight': 700 });
        }
        if (d.pages) {
            if (d.pages.current > 0) text(1, 194, '◂', { 'font-size': 14 });
            if (d.pages.current < d.pages.count - 1) text(389, 194, '▸', { 'font-size': 14 });
        }
    }

    function drawPanel(p) {
        if (p.title) {
            el('rect', { x: 0, y: 28, width: LCD_W, height: 24, fill: LCD_INK }, lcd);
            text(200, 46, p.title.startsWith('≡') || p.title.startsWith('!!') ? p.title : `≡ ${p.title} ≡`, { 'text-anchor': 'middle', 'font-size': 17, fill: LCD_LIGHT, 'font-weight': 700 });
        }
        let y = 72;
        if (p.note) {
            text(200, y, p.note, { 'text-anchor': 'middle', 'font-size': 16 });
            y += 23;
        }
        if (p.items) {
            const visible = p.note ? 4 : 5;
            const first = Math.max(0, Math.min(p.cursor - 2, p.items.length - visible));
            p.items.slice(first, first + visible).forEach((item, i) => {
                const selected = first + i === p.cursor;
                if (selected) el('rect', { x: 4, y: y - 17, width: 392, height: 22, fill: LCD_INK }, lcd);
                text(10, y, item, { 'font-size': 17, fill: selected ? LCD_LIGHT : LCD_INK });
                y += 23;
            });
        }
        (p.lines ?? []).forEach((line) => {
            text(200, y, line, { 'text-anchor': 'middle', 'font-size': 19, class: p.blink ? 'fg-blink' : '' });
            y += 23;
        });
        if (p.editor) {
            const e = p.editor;
            const width = e.chars.length * 19;
            e.chars.forEach((c, i) => {
                const x = 200 - width / 2 + i * 19;
                text(x, y + 4, c, { 'font-size': 22 });
                if (i === e.cursor) el('rect', { x: x - 1, y: y + 8, width: 15, height: 3, fill: LCD_INK }, lcd);
            });
            // Zeichenzeile, markiertes Zeichen invers
            const rowWidth = e.row.length * 25;
            e.row.forEach((c, i) => {
                const x = 200 - rowWidth / 2 + i * 25;
                if (i === e.sel) el('rect', { x: x - 4, y: y + 20, width: 22, height: 24, fill: LCD_INK }, lcd);
                text(x + 7, y + 38, c, { 'text-anchor': 'middle', 'font-size': 18, fill: i === e.sel ? LCD_LIGHT : LCD_INK });
            });
        }
        if (typeof p.progress === 'number') {
            el('rect', { x: 40, y: 136, width: 320, height: 14, fill: 'none', stroke: LCD_INK, 'stroke-width': 2 }, lcd);
            el('rect', { x: 42, y: 138, width: 316 * p.progress, height: 10, fill: LCD_INK }, lcd);
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
        const state = st.tx ? 'TX' : st.busy ? 'BUSY' : '';
        if (state) {
            el('rect', { x: 4, y: 5, width: state.length * 10.2 + 10, height: 21, rx: 2, fill: LCD_INK }, lcd);
            text(9, 21, state, { 'font-size': 17, fill: LCD_LIGHT, 'font-weight': 700 });
        }
        text(92, 22, [st.power, st.group].filter(Boolean).join('  '), { 'font-size': 20 });
        if (st.mail) text(262, 22, '✉', { 'font-size': 19 });
        drawSoftkeyBar(d);

        if (d.panel) {
            drawPanel(d.panel);

            return;
        }

        el('rect', { x: 140, y: 36, width: 60, height: 26, rx: 3, fill: LCD_INK }, lcd);
        text(170, 55, d.mode, { 'text-anchor': 'middle', 'font-size': 17, fill: LCD_LIGHT, 'font-weight': 700 });
        if (d.watch) text(10, 55, d.watch, { 'font-size': 16 });
        d.lines.forEach((line, i) => text(14, 92 + i * 33, line, { 'font-size': 26 }));
        const channel = String(d.channel ?? '');
        el('text', { x: 0, y: 0, transform: 'translate(352 156) scale(0.78 1)', 'text-anchor': 'end', 'font-size': channel.length <= 2 ? 150 : 96, 'font-weight': 700, 'font-family': SANS }, lcd, channel);
        if (st.tag) text(360, 54, '★', { 'font-size': 26 });
        [st.call ? 'CALL' : '', st.dup ? 'DUP' : ''].filter(Boolean).forEach((label, i) => text(396, 90 + i * 20, label, { 'text-anchor': 'end', 'font-size': 14 }));
        text(396, 169, d.channelName, { 'text-anchor': 'end', 'font-size': 12 });
        if (d.window) {
            el('rect', { x: 30, y: 58, width: 340, height: 84, rx: 6, fill: LCD_LIGHT, stroke: LCD_INK, 'stroke-width': 2 }, lcd);
            text(46, 92, `${d.window.label} ${d.window.value}`, { 'font-size': 22, 'font-weight': 700 });
            el('rect', { x: 46, y: 108, width: 308, height: 14, fill: 'none', stroke: LCD_INK, 'stroke-width': 2 }, lcd);
            el('rect', { x: 48, y: 110, width: 304 * d.window.level, height: 10, fill: LCD_INK }, lcd);
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
            const level = radioState.knobMode === 'sql' ? radioState.sql / 10 : radioState.vol / 20;
            pointer.setAttribute('transform', `rotate(${-135 + level * 270} ${kx} ${ky})`);
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
