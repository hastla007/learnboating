// Zeichnung des IC-M323 bzw. IC-M423 (SVG) im Stil der Frontansicht, ohne
// Halterung und ohne Herstellerlogo: dunkles Gehäuse, links senkrechte
// Lautsprecherschlitze, darunter Kabeldurchführung und DISTRESS unter der
// roten Klappe, in der Mitte das orange Display mit Softkey-Leiste (◀ ▶
// zum Blättern) und vier Softkeys, rechts CH▲/CH▼, ◀ ENT ▶, MENU und
// CLEAR, unten rechts 16/C und der Drehknopf VOL/SQL·PWR. Links der Hörer
// mit runder Sprechmuschel, ▲/▼, HI/LO (IC-M423 zusätzlich 16/C) und
// Sprechtaste. Die Tasten leuchten bei eingeschaltetem Gerät
// (Hintergrundbeleuchtung). Gleiche Schnittstelle wie device-svg.js; ein
// Ziel "sk:LABEL" markiert den Softkey mit dieser Beschriftung.

import { createBacklight, glowFilter } from './backlight.js';
import { drawHandset } from './handset.js';

const NS = 'http://www.w3.org/2000/svg';
const LCD_INK = '#2b1806';
const LCD_LIGHT = '#f6a640';
const MONO = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';
const SANS = 'Arial, Helvetica, sans-serif';
const PRINT = '#e4e6ea';

// Beleuchtete Tasten: heller Rand und Beschriftung, kupferfarbene Kante.
const RIM = { on: '#f0d29a', off: '#3a3c41' };
const EDGE = { on: '#6b4322', off: '#141517' };
const LABEL = { on: '#fff1d2', off: '#8c9097' };

// Koordinaten der Frontansicht (Foto, Gerät ohne Halterung) auf die
// Zeichenfläche: Gehäuse x 180–990, y 22–412.
const Q = (px, py) => [51.56 + px * 0.988, -368.26 + py * 0.988];

// Die Anzeige wird in einer Fläche von 400 × 197 geschrieben und in das
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
    grad('m323-frame', [[0, '#55585e'], [0.08, '#3a3c41'], [1, '#1c1d20']]);
    grad('m323-face', [[0, '#3a3c41'], [1, '#292b2f']]);
    grad('m323-ledge', [[0, '#26282b'], [1, '#141517']]);
    grad('m323-bezel', [[0, '#202124'], [1, '#101113']]);
    grad('m323-lcd', [[0, '#f7b24d'], [0.55, '#f29d33'], [1, '#e6892a']]);
    grad('m323-key', [[0, '#2e3034'], [1, '#121315']]);
    grad('m323-slot', [[0, '#5b5e64'], [0.25, '#0f1012'], [1, '#1a1b1e']], { x1: 0, x2: 1, y1: 0, y2: 0 });
    grad('m323-red', [[0, '#e0353f'], [1, '#8e0f16']]);
    grad('m323-blue', [[0, '#4a8fea'], [1, '#1b54b0']]);
    grad('m323-hinge', [[0, '#c4c7cc'], [1, '#5f6268']]);
    radial('m323-knob', [[0, '#3f4247'], [0.75, '#1f2023'], [1, '#111214']]);
    radial('m323-cap', [[0, '#383b40'], [1, '#17181b']]);
    radial('m323-gland', [[0, '#3a3c40'], [1, '#121315']]);
    const shadow = el('filter', { id: 'm323-shadow', x: '-15%', y: '-15%', width: '140%', height: '140%' }, d);
    el('feDropShadow', { dx: 0, dy: 1.5, stdDeviation: 1.3, 'flood-color': '#000', 'flood-opacity': 0.6 }, shadow);
    glowFilter(d, 'm323-glow', NS, 1.4);
}

// Beleuchtete Taste zwischen zwei Fotopunkten; label darf mehrzeilig sein
// (z. B. ['▲', 'CH']), bar zeichnet den Strich der Softkeys.
function key(svg, light, id, a, b, label, { ariaLabel = null, size = 13, rx = 9, bar = false } = {}) {
    const [x1, y1] = Q(...a);
    const [x2, y2] = Q(...b);
    const w = x2 - x1;
    const h = y2 - y1;
    const g = el('g', { 'data-key': id, tabindex: '0', role: 'button', 'aria-label': ariaLabel ?? [label].flat().join(' '), class: 'fg-control', style: 'cursor:pointer' }, svg);
    light.add(el('rect', { x: x1 - 2, y: y1 - 2, width: w + 4, height: h + 4, rx: rx + 2, fill: EDGE.on, filter: 'url(#m323-shadow)' }, g), { fill: EDGE.on }, { fill: EDGE.off });
    light.add(el('rect', { x: x1, y: y1, width: w, height: h, rx, fill: 'url(#m323-key)', stroke: RIM.on, 'stroke-width': 2.6 }, g), { stroke: RIM.on }, { stroke: RIM.off });
    el('rect', { x: x1 + 4, y: y1 + 3, width: w - 8, height: h * 0.35, rx: rx - 3, fill: '#ffffff', 'fill-opacity': 0.06, 'pointer-events': 'none' }, g);
    const lit = { fill: LABEL.on, filter: 'url(#m323-glow)' };
    const dark = { fill: LABEL.off, filter: null };
    if (bar) {
        light.add(el('rect', { x: x1 + w / 2 - 12, y: y1 + h / 2 - 2.5, width: 24, height: 5, rx: 2, ...lit, 'pointer-events': 'none' }, g), lit, dark);
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

// Das IC-M423 nutzt dieselbe Zeichnung mit eigener Typenbezeichnung und
// zusätzlicher 16/C-Taste am Hörer.
export function renderDeviceM423(container) {
    return renderDeviceM323(container, { model: 'IC-M423', mic16: true });
}

export function renderDeviceM323(container, { model = 'IC-M323', mic16 = false } = {}) {
    const svg = el('svg', { viewBox: '0 0 1000 430', role: 'group', 'aria-label': `Funkgerät ${model} (Nachbildung)`, style: 'width:100%;height:auto;user-select:none;-webkit-user-select:none' });
    container.appendChild(svg);
    defs(svg);
    const light = createBacklight();

    // Gehäuse mit unterer Leiste
    const [bx1, by1] = Q(130, 395);
    const [bx2, by2] = Q(950, 790);
    el('rect', { x: bx1, y: by1, width: bx2 - bx1, height: by2 - by1, rx: 34, fill: 'url(#m323-frame)', stroke: '#0d0e10', 'stroke-width': 3 }, svg);
    el('rect', { x: bx1 + 4, y: by1 + 3, width: bx2 - bx1 - 8, height: by2 - by1 - 7, rx: 31, fill: 'none', stroke: '#ffffff', 'stroke-opacity': 0.12, 'stroke-width': 2 }, svg);
    const [fx1, fy1] = Q(145, 410);
    const [fx2, fy2] = Q(940, 762);
    el('rect', { x: fx1, y: fy1, width: fx2 - fx1, height: fy2 - fy1, rx: 26, fill: 'url(#m323-face)', stroke: '#16171a', 'stroke-width': 2 }, svg);
    el('rect', { x: fx1 + 14, y: fy2 + 4, width: fx2 - fx1 - 28, height: by2 - fy2 - 12, rx: 8, fill: 'url(#m323-ledge)' }, svg);

    // Senkrechte Lautsprecherschlitze
    for (let i = 0; i < 7; i++) {
        const [sx, sy] = Q(150 + i * 29.5, 440);
        const [, sy2] = Q(0, 625);
        el('rect', { x: sx, y: sy, width: 13.5, height: sy2 - sy, rx: 6.7, fill: 'url(#m323-slot)', stroke: '#0b0c0d', 'stroke-width': 1 }, svg);
    }

    // Display mit Rahmen und Typenzeile; Striche zu den Softkeys
    const [dbx1, dby1] = Q(352, 447);
    const [dbx2, dby2] = Q(752, 680);
    el('rect', { x: dbx1, y: dby1, width: dbx2 - dbx1, height: dby2 - dby1, rx: 24, fill: 'url(#m323-bezel)', stroke: '#0b0c0d', 'stroke-width': 2 }, svg);
    const [mx, my] = Q(730, 440);
    el('text', { x: mx, y: my, 'text-anchor': 'end', 'font-size': 15, 'font-weight': 700, fill: PRINT, 'font-family': SANS, 'letter-spacing': 0.5 }, svg, model);
    el('text', { x: mx - 74, y: my, 'text-anchor': 'end', 'font-size': 9.5, 'font-weight': 700, fill: PRINT, 'font-family': SANS }, svg, 'VHF MARINE');
    const [lx1, ly1] = Q(387, 475);
    const [lx2, ly2] = Q(722, 640);
    const lcdBox = { x: lx1, y: ly1, width: lx2 - lx1, height: ly2 - ly1 };
    light.add(el('rect', { ...lcdBox, rx: 6, fill: 'url(#m323-lcd)' }, svg), { fill: 'url(#m323-lcd)' }, { fill: '#4b4840' });
    const k = lcdBox.width / LCD_W;
    const lcd = el('g', { 'font-family': MONO, fill: LCD_INK, transform: `translate(${lcdBox.x} ${lcdBox.y}) scale(${k})` }, svg);
    for (const px of [437, 515, 595, 672]) {
        const [tx, ty1] = Q(px, 657);
        const [, ty2] = Q(px, 672);
        el('line', { x1: tx, y1: ty1, x2: tx, y2: ty2, stroke: '#8a8d93', 'stroke-width': 3, 'stroke-linecap': 'round' }, svg);
    }

    // Softkeys
    const softkeys = [[397, 467], [478, 547], [560, 630], [641, 711]].map(([a, b], i) => key(svg, light, `soft${i + 1}`, [a, 692], [b, 740], '', { ariaLabel: `Softkey ${i + 1}`, bar: true, rx: 11 }));

    // Kabeldurchführung mit Kabel
    const [gx, gy] = Q(207, 685);
    el('path', { d: `M${gx - 26} ${gy + 64} C ${gx - 32} ${gy + 84}, ${gx - 36} ${gy + 104}, ${gx - 38} 432`, fill: 'none', stroke: '#141517', 'stroke-width': 12 }, svg);
    el('path', { d: `M${gx - 16} ${gy + 6} L ${gx - 38} ${gy + 62} L ${gx - 16} ${gy + 68} L ${gx + 8} ${gy + 12} Z`, fill: '#1a1b1e', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    for (let i = 0; i < 5; i++) el('line', { x1: gx - 16 - i * 4, y1: gy + 18 + i * 9, x2: gx + 4 - i * 4, y2: gy + 24 + i * 9, stroke: '#3a3c41', 'stroke-width': 2 }, svg);
    el('circle', { cx: gx, cy: gy, r: 34, fill: 'url(#m323-gland)', stroke: '#0b0c0d', 'stroke-width': 2 }, svg);
    el('circle', { cx: gx, cy: gy, r: 22, fill: '#1a1b1e', stroke: '#4a4d52', 'stroke-width': 1.5 }, svg);

    // DISTRESS mit Klappe
    const [hx1, hy1] = Q(260, 637);
    const [hx2, hy2] = Q(350, 655);
    const [dx1, dy1] = Q(277, 640);
    const [dx2, dy2] = Q(335, 752);
    el('rect', { x: dx1 - 4, y: dy1, width: dx2 - dx1 + 8, height: dy2 - dy1 + 4, rx: 7, fill: '#141517', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    const distress = el('g', { 'data-key': 'distress', tabindex: '0', role: 'button', 'aria-label': 'DISTRESS (3 Sekunden halten)', class: 'fg-control', style: 'cursor:pointer' }, svg);
    el('rect', { x: dx1 + 2, y: hy2 + 4, width: dx2 - dx1 - 4, height: dy2 - hy2 - 6, rx: 5, fill: 'url(#m323-red)', stroke: '#5b080d', 'stroke-width': 2 }, distress);
    el('text', { x: (dx1 + dx2) / 2, y: (hy2 + dy2) / 2 + 4, 'text-anchor': 'middle', 'font-size': 11, 'font-weight': 700, fill: '#ffffff', 'font-family': SANS, 'pointer-events': 'none' }, distress, 'DISTRESS');
    el('rect', { x: hx1, y: hy1, width: hx2 - hx1, height: hy2 - hy1, rx: 5, fill: 'url(#m323-hinge)', stroke: '#3a3c41', 'stroke-width': 1 }, svg);
    const cover = el('g', { 'data-key': 'distressCover', tabindex: '0', role: 'button', 'aria-label': 'Schutzklappe der DISTRESS-Taste', class: 'fg-control', style: 'cursor:pointer' }, svg);
    const coverClosed = { y: hy1 + 6, height: dy2 - hy1 - 4 };
    const coverOpen = { y: hy1 - 28, height: 28 };
    const coverLid = el('rect', { x: dx1 - 2, ...coverClosed, width: dx2 - dx1 + 4, rx: 6, fill: '#e2333d', 'fill-opacity': 0.55, stroke: '#9b1219', 'stroke-width': 2 }, cover);

    // Tastenkreuz, MENU, CLEAR
    key(svg, light, 'up', [805, 432], [880, 467], ['▲', 'CH'], { ariaLabel: 'Kanal auf / ▲', size: 11.5, rx: 12 });
    key(svg, light, 'left', [772, 475], [805, 532], '◀', { ariaLabel: 'links / Softkeys blättern', size: 15, rx: 11 });
    key(svg, light, 'enter', [817, 477], [870, 530], 'ENT', { size: 14, rx: 14 });
    key(svg, light, 'right', [882, 475], [915, 532], '▶', { ariaLabel: 'rechts / Softkeys blättern', size: 15, rx: 11 });
    key(svg, light, 'down', [805, 540], [880, 575], ['CH', '▼'], { ariaLabel: 'Kanal ab / ▼', size: 11.5, rx: 12 });
    key(svg, light, 'menu', [772, 590], [837, 625], 'MENU', { size: 13, rx: 11 });
    key(svg, light, 'clr', [850, 590], [915, 625], 'CLEAR', { size: 12.5, rx: 11 });

    // 16/C
    const [cx16, cy16] = Q(775, 715);
    const ch16 = el('g', { 'data-key': 'ch16', tabindex: '0', role: 'button', 'aria-label': '16/C: Kanal 16, 1 Sekunde Anrufkanal', class: 'fg-control', style: 'cursor:pointer' }, svg);
    light.add(el('circle', { cx: cx16, cy: cy16, r: 26, fill: '#9a6532', filter: 'url(#m323-shadow)' }, ch16), { fill: '#9a6532' }, { fill: '#2a2b2e' });
    el('circle', { cx: cx16, cy: cy16, r: 22, fill: 'url(#m323-blue)', stroke: '#0e3d86', 'stroke-width': 1.5 }, ch16);
    el('text', { x: cx16, y: cy16 + 5, 'text-anchor': 'middle', 'font-size': 13.5, 'font-weight': 700, fill: '#ffffff', 'font-family': SANS, 'pointer-events': 'none' }, ch16, '16/C');

    // Drehknopf VOL/SQL·PWR: Ring drehen, Kappe drücken
    const [kx, ky] = Q(865, 702);
    const [vx, vy] = Q(790, 640);
    el('text', { x: vx, y: vy, 'text-anchor': 'middle', 'font-size': 10.5, 'font-weight': 700, fill: PRINT, 'font-family': SANS }, svg, 'VOL/SQL');
    el('rect', { x: vx - 20, y: vy + 6, width: 27, height: 12, rx: 2, fill: 'none', stroke: PRINT, 'stroke-width': 1.2 }, svg);
    el('text', { x: vx - 6.5, y: vy + 15.5, 'text-anchor': 'middle', 'font-size': 9, 'font-weight': 700, fill: PRINT, 'font-family': SANS }, svg, 'PWR');
    const [ix, iy] = Q(819, 654);
    el('circle', { cx: ix, cy: iy, r: 6, fill: 'none', stroke: PRINT, 'stroke-width': 1.5 }, svg);
    el('circle', { cx: ix, cy: iy, r: 2, fill: PRINT }, svg);
    const ring = el('g', { 'data-knob': 'dial', tabindex: '0', role: 'slider', 'aria-label': 'Drehknopf VOL/SQL', class: 'fg-control', style: 'cursor:ns-resize;touch-action:none' }, svg);
    el('circle', { cx: kx, cy: ky, r: 45, fill: 'url(#m323-knob)', stroke: '#0b0c0d', 'stroke-width': 2, filter: 'url(#m323-shadow)' }, ring);
    for (let i = 0; i < 32; i++) {
        const a = (i * 11.25) * Math.PI / 180;
        el('line', { x1: kx + Math.sin(a) * 38, y1: ky - Math.cos(a) * 38, x2: kx + Math.sin(a) * 44, y2: ky - Math.cos(a) * 44, stroke: '#0a0a0b', 'stroke-width': 1.6 }, ring);
    }
    const pointer = el('line', { x1: kx, y1: ky - 29, x2: kx, y2: ky - 38, stroke: '#cfd2d7', 'stroke-width': 3, 'stroke-linecap': 'round' }, ring);
    const cap = el('g', { 'data-key': 'knob', tabindex: '0', role: 'button', 'aria-label': 'Drehknopf drücken (kurz: Lautstärke/Rauschsperre/Kanal, 1 Sekunde: Ein/Aus)', class: 'fg-control', style: 'cursor:pointer' }, svg);
    el('circle', { cx: kx, cy: ky, r: 26, fill: 'url(#m323-cap)', stroke: '#0b0c0d', 'stroke-width': 1.5 }, cap);
    for (const [ox, oy] of [[0, 0], [-7, -4], [7, -4], [-7, 5], [7, 5], [0, -9], [0, 9]]) el('circle', { cx: kx + ox, cy: ky + oy, r: 1.6, fill: '#0b0c0d', 'pointer-events': 'none' }, cap);

    // Hörer vor der linken Gehäusekante
    drawHandset(svg, { prefix: 'm323', model: 'roundGrey', x: 24, y: 12, scale: 0.97, bottom: 430, mic16 });

    const highlight = el('rect', { fill: 'none', stroke: '#e3b505', 'stroke-width': 4, 'stroke-dasharray': '10 6', rx: 14, visibility: 'hidden', 'pointer-events': 'none' }, svg);

    function text(x, y, value, attrs = {}) {
        el('text', { x, y, 'font-size': 17, ...attrs }, lcd, value);
    }

    function drawSoftkeyBar(d) {
        for (const f of fields(d.softkeys ?? [])) {
            if (! f.label) continue;
            const x = 14 + f.start * 93;
            const w = f.span * 93 - 3;
            el('rect', { x, y: 170, width: w, height: 26, rx: 2, fill: LCD_INK }, lcd);
            text(x + w / 2, 189, f.label, { 'text-anchor': 'middle', 'font-size': 18, fill: LCD_LIGHT, 'font-weight': 700 });
        }
        if (d.arrows) {
            text(1, 189, '◀', { 'font-size': 12 });
            text(388, 189, '▶', { 'font-size': 12 });
        }
    }

    function drawPanel(p) {
        if (p.title) {
            el('rect', { x: 0, y: 28, width: LCD_W, height: 24, fill: LCD_INK }, lcd);
            text(200, 46, `≡ ${p.title} ≡`, { 'text-anchor': 'middle', 'font-size': 17, fill: LCD_LIGHT, 'font-weight': 700 });
        }
        let y = 72;
        if (p.items) {
            const first = Math.max(0, Math.min(p.cursor - 2, p.items.length - 5));
            p.items.slice(first, first + 5).forEach((item, i) => {
                const selected = first + i === p.cursor;
                if (selected) el('rect', { x: 4, y: y - 17, width: 392, height: 22, fill: LCD_INK }, lcd);
                text(10, y, item, { 'font-size': 17, fill: selected ? LCD_LIGHT : LCD_INK });
                y += 22;
            });
        }
        (p.lines ?? []).forEach((line) => {
            text(200, y, line, { 'text-anchor': 'middle', 'font-size': 18, class: p.blink ? 'fg-blink' : '' });
            y += 22;
        });
        if (p.editor) {
            const chars = p.editor.chars;
            const width = chars.length * 16;
            chars.forEach((c, i) => {
                const x = 200 - width / 2 + i * 16;
                text(x, y + 6, c, { 'font-size': 20 });
                if (i === p.editor.cursor) el('rect', { x: x - 1, y: y + 10, width: 13, height: 3, fill: LCD_INK }, lcd);
            });
            if (p.footer) text(200, y + 36, p.footer, { 'text-anchor': 'middle', 'font-size': 15 });
        }
        if (typeof p.progress === 'number') {
            el('rect', { x: 40, y: 128, width: 320, height: 14, fill: 'none', stroke: LCD_INK, 'stroke-width': 2 }, lcd);
            el('rect', { x: 42, y: 130, width: 316 * p.progress, height: 10, fill: LCD_INK }, lcd);
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
        text(80, 22, [st.power, st.group].filter(Boolean).join('  '), { 'font-size': 19 });
        if (st.tag) text(256, 25, '★', { 'font-size': 22 });
        [st.dup ? 'DUP' : '', st.call ? 'CALL' : ''].filter(Boolean).forEach((label, i) => text(396, 22 + i * 18, label, { 'text-anchor': 'end', 'font-size': 14 }));
        drawSoftkeyBar(d);

        if (d.panel) {
            drawPanel(d.panel);

            return;
        }

        if (d.watch) text(10, 52, d.watch, { 'font-size': 16 });
        d.lines.forEach((line, i) => text(12, 107 + i * 24, line, { 'font-size': 21 }));
        const channel = String(d.channel ?? '');
        el('text', { x: 0, y: 0, transform: 'translate(362 128) scale(0.78 1)', 'text-anchor': 'end', 'font-size': channel.length <= 2 ? 126 : 84, 'font-weight': 700, 'font-family': SANS }, lcd, channel);
        text(370, 156, d.channelName, { 'text-anchor': 'end', 'font-size': 19 });
        if (d.window) {
            el('rect', { x: 30, y: 50, width: 340, height: 84, rx: 6, fill: LCD_LIGHT, stroke: LCD_INK, 'stroke-width': 2 }, lcd);
            text(46, 84, `${d.window.label} ${d.window.value}`, { 'font-size': 22, 'font-weight': 700 });
            if (d.window.level !== null) {
                el('rect', { x: 46, y: 100, width: 308, height: 14, fill: 'none', stroke: LCD_INK, 'stroke-width': 2 }, lcd);
                el('rect', { x: 48, y: 102, width: 304 * d.window.level, height: 10, fill: LCD_INK }, lcd);
            }
        }
    }

    return {
        svg,
        update(display, radioState, target) {
            drawDisplay(display);
            const level = radioState.knobMode === 'sql' ? radioState.sql / 10 : radioState.vol / 20;
            pointer.setAttribute('transform', `rotate(${-135 + level * 270} ${kx} ${ky})`);
            Object.entries(radioState.coverOpen ? coverOpen : coverClosed).forEach(([name, value]) => coverLid.setAttribute(name, value));

            let node = null;
            if (target?.startsWith('sk:')) {
                const index = (display.softkeys ?? []).indexOf(target.slice(3));
                node = index >= 0 ? softkeys[index] : svg.querySelector('[data-key="right"]');
            } else if (target) {
                node = svg.querySelector(`[data-key="${target}"], [data-knob="${target}"]`);
            }
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
