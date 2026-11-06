// Zeichnung von IC-M503 (links) und DSC-Controller DS-100 (rechts)
// nebeneinander im Stil der Frontansichten: dunkles Gehäuse mit Rahmen und
// Typenleiste, orange beleuchtete Displays, hellorange hinterleuchtete Tasten.
// IC-M503: Lautsprecherschlitze, Display, Taste 16, DUAL/SCAN/DIAL/CALL,
// HI/LO, DIMMER, POWER, VOL/SQL, Kanalwahlknopf, Kabeldurchführung.
// DS-100: Display, Zifferntastatur, Pfeiltasten, DISTRESS unter roter
// Klappe, CLR/CALL/ENT. Anordnung wie am Original, ohne Herstellerlogo; links
// der Hörer mit Sprechtaste. Eingeschaltet leuchten Tasten und Displays
// (Hintergrundbeleuchtung), ausgeschaltet bleiben sie dunkel. Gleiche
// Schnittstelle wie device-svg.js.

import { createBacklight } from './backlight.js';
import { drawHandset } from './handset.js';

const NS = 'http://www.w3.org/2000/svg';
const KEY_TEXT = '#1c1408';
const LABEL = '#d9dce1';
const LCD_INK = '#2b1806';
const MONO = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';
const SANS = 'Arial, Helvetica, sans-serif';
// Beleuchtet / unbeleuchtet je markiertem Teil (data-lit).
const LIT = {
    face: [{ fill: 'url(#m503-key)', stroke: '#5a3b20' }, { fill: 'url(#m503-key-off)', stroke: '#141518' }],
    gloss: [{ 'fill-opacity': 0.22 }, { 'fill-opacity': 0.06 }],
    label: [{ fill: KEY_TEXT }, { fill: '#9a9ea6' }],
    sub: [{ fill: '#fbd3a6' }, { fill: '#8c9097' }],
    lcd: [{ fill: 'url(#m503-lcd)' }, { fill: '#4b4840' }],
};

// Koordinaten der Frontansicht auf die Zeichenfläche: Funkgerät ab x 170,
// DS-100 ab x 742, beide ab y 10.
const R = (px, py) => [170 + (px - 30), 10 + (py - 75)];
const D = (px, py) => [742 + (px - 612), 10 + (py - 75)];

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
    grad('m503-frame', [[0, '#2a2c30'], [1, '#161719']]);
    grad('m503-face', [[0, '#55585e'], [0.15, '#45484e'], [0.7, '#36383d'], [1, '#2b2d31']]);
    grad('m503-strip', [[0, '#5d6067'], [1, '#3f4247']]);
    grad('m503-key', [[0, '#fbd3a6'], [0.55, '#f0b27a'], [1, '#d8925a']]);
    grad('m503-key-off', [[0, '#4a4d53'], [0.5, '#34363b'], [1, '#25272b']]);
    grad('m503-bezel', [[0, '#26282c'], [1, '#141517']]);
    grad('m503-lcd', [[0, '#f7b24d'], [0.55, '#f29d33'], [1, '#e6892a']]);
    grad('m503-red', [[0, '#d9333d'], [1, '#8e0f16']]);
    grad('m503-blue', [[0, '#5d97f0'], [1, '#2e6ad6']]);
    radial('m503-knob', [[0, '#55595f'], [0.7, '#2f3136'], [1, '#1c1d21']]);
    radial('m503-cap', [[0, '#4d5056'], [1, '#26282c']]);
    const shadow = el('filter', { id: 'm503-shadow', x: '-10%', y: '-10%', width: '130%', height: '130%' }, d);
    el('feDropShadow', { dx: 0, dy: 1.5, stdDeviation: 1.2, 'flood-color': '#000', 'flood-opacity': 0.6 }, shadow);
}

// Hellorange hinterleuchtete Taste zwischen zwei Eckpunkten.
function key(svg, id, [x1, y1], [x2, y2], label, { sub = null, size = 13, ariaLabel = null, subSize = 8 } = {}) {
    const w = x2 - x1;
    const h = y2 - y1;
    const g = el('g', { 'data-key': id, tabindex: '0', role: 'button', 'aria-label': ariaLabel ?? label, class: 'fg-control', style: 'cursor:pointer' }, svg);
    el('rect', { x: x1, y: y1, width: w, height: h, rx: 7, fill: 'url(#m503-key)', stroke: '#5a3b20', 'stroke-width': 1.6, filter: 'url(#m503-shadow)', 'data-lit': 'face' }, g);
    el('rect', { x: x1 + 3, y: y1 + 2, width: w - 6, height: h * 0.4, rx: 5, fill: '#ffffff', 'fill-opacity': 0.22, 'pointer-events': 'none', 'data-lit': 'gloss' }, g);
    const ty = sub ? y1 + h * 0.52 : y1 + h / 2 + size * 0.36;
    el('text', { x: x1 + w / 2, y: ty, 'text-anchor': 'middle', 'font-size': size, 'font-weight': 700, fill: KEY_TEXT, 'font-family': SANS, 'pointer-events': 'none', 'data-lit': 'label' }, g, label);
    if (sub) {
        el('rect', { x: x1 + w / 2 - 13, y: ty + 3, width: 26, height: subSize + 3, rx: 1.5, fill: '#3a2a18', 'pointer-events': 'none' }, g);
        el('text', { x: x1 + w / 2, y: ty + subSize + 3.5, 'text-anchor': 'middle', 'font-size': subSize, 'font-weight': 700, fill: '#fbd3a6', 'font-family': SANS, 'pointer-events': 'none', 'data-lit': 'sub' }, g, sub);
    }

    return g;
}

function knob(svg, { id, cx, cy, r, ariaLabel, dots = true }) {
    const g = el('g', { 'data-knob': id, tabindex: '0', role: 'slider', 'aria-label': ariaLabel, class: 'fg-control', style: 'cursor:ns-resize;touch-action:none' }, svg);
    if (dots) {
        for (let i = 0; i < 9; i++) {
            const a = (-135 + i * 33.75) * Math.PI / 180;
            el('circle', { cx: cx + Math.sin(a) * (r + 9), cy: cy - Math.cos(a) * (r + 9), r: 1.8, fill: '#eceef1' }, g);
        }
    }
    el('circle', { cx, cy, r, fill: 'url(#m503-knob)', stroke: '#121315', 'stroke-width': 2, filter: 'url(#m503-shadow)' }, g);
    for (let i = 0; i < 24; i++) {
        const a = (i * 15) * Math.PI / 180;
        el('line', { x1: cx + Math.sin(a) * (r - 3.5), y1: cy - Math.cos(a) * (r - 3.5), x2: cx + Math.sin(a) * r, y2: cy - Math.cos(a) * r, stroke: '#111214', 'stroke-width': 1.4 }, g);
    }
    el('circle', { cx, cy, r: r * 0.66, fill: 'url(#m503-cap)', 'pointer-events': 'none' }, g);
    const pointer = el('line', { x1: cx, y1: cy - r * 0.15, x2: cx, y2: cy - r + 5, stroke: '#f2f4f7', 'stroke-width': 3, 'stroke-linecap': 'round' }, g);

    return { pointer, cx, cy };
}

function housing(svg, [x1, y1], [x2, y2], title, [tx1, tx2]) {
    el('rect', { x: x1, y: y1, width: x2 - x1, height: y2 - y1, rx: 20, fill: 'url(#m503-frame)', stroke: '#0d0e10', 'stroke-width': 2 }, svg);
    el('rect', { x: x1 + 9, y: y1 + 22, width: x2 - x1 - 18, height: y2 - y1 - 31, rx: 14, fill: 'url(#m503-face)', stroke: '#ffffff', 'stroke-opacity': 0.07, 'stroke-width': 1.5 }, svg);
    el('rect', { x: tx1, y: y1 + 3, width: tx2 - tx1, height: 19, rx: 3, fill: 'url(#m503-strip)', stroke: '#1b1c1f', 'stroke-width': 1 }, svg);
    el('text', { x: (tx1 + tx2) / 2, y: y1 + 16.5, 'text-anchor': 'middle', 'font-size': 10.5, 'font-weight': 700, fill: '#eef0f2', 'font-family': SANS, 'letter-spacing': 0.8, 'xml:space': 'preserve' }, svg, title);
}

function lcdPanel(svg, [bx1, by1], [bx2, by2], [lx1, ly1], [lx2, ly2]) {
    el('rect', { x: bx1, y: by1, width: bx2 - bx1, height: by2 - by1, rx: 8, fill: 'url(#m503-bezel)', stroke: '#0d0e10', 'stroke-width': 1.5 }, svg);
    el('rect', { x: lx1, y: ly1, width: lx2 - lx1, height: ly2 - ly1, rx: 4, fill: 'url(#m503-lcd)', 'data-lit': 'lcd' }, svg);
    el('text', { x: (bx1 + bx2) / 2, y: by2 - 3.5, 'text-anchor': 'middle', 'font-size': 7.5, 'font-weight': 700, fill: '#d0d3d8', 'font-family': SANS, 'letter-spacing': 0.6 }, svg, 'WATER RESISTANT');

    return { x: lx1, y: ly1, w: lx2 - lx1, h: ly2 - ly1 };
}

export function renderDeviceM503(container) {
    const svg = el('svg', { viewBox: '0 0 1300 400', role: 'group', 'aria-label': 'Funkgerät IC-M503 mit DSC-Controller DS-100 (Nachbildung)', style: 'width:100%;height:auto;user-select:none;-webkit-user-select:none' });
    container.appendChild(svg);
    defs(svg);

    // --- IC-M503 ---------------------------------------------------------------
    housing(svg, R(30, 75), R(590, 435), 'VHF MARINE      IC-M503', [R(242, 0)[0], R(428, 0)[0]]);
    for (let i = 0; i < 6; i++) {
        const [x1, y1] = R(95, 118 + i * 28);
        el('rect', { x: x1, y: y1, width: 120, height: 17, rx: 8.5, fill: '#1a1b1e', stroke: '#62666d', 'stroke-opacity': 0.45, 'stroke-width': 1 }, svg);
    }
    const lcd = lcdPanel(svg, R(232, 128), R(447, 222), R(240, 135), R(440, 208));
    const radioLcd = el('g', { 'font-family': MONO, fill: LCD_INK }, svg);

    // Taste 16 (blau, eingefasst)
    const ch16 = el('g', { 'data-key': 'ch16', tabindex: '0', role: 'button', 'aria-label': 'Kanal 16 (gehalten: Set-Modus beim Einschalten)', class: 'fg-control', style: 'cursor:pointer' }, svg);
    const [fx1, fy1] = R(468, 143);
    const [fx2, fy2] = R(534, 203);
    el('rect', { x: fx1, y: fy1, width: fx2 - fx1, height: fy2 - fy1, rx: 9, fill: 'url(#m503-key)', stroke: '#5a3b20', 'stroke-width': 1.6, filter: 'url(#m503-shadow)', 'data-lit': 'face' }, ch16);
    el('rect', { x: fx1 + 9, y: fy1 + 9, width: fx2 - fx1 - 18, height: fy2 - fy1 - 18, rx: 6, fill: 'url(#m503-blue)', stroke: '#1d4a99', 'stroke-width': 1.2 }, ch16);
    el('text', { x: (fx1 + fx2) / 2, y: (fy1 + fy2) / 2 + 6, 'text-anchor': 'middle', 'font-size': 17, 'font-weight': 700, fill: '#ffffff', 'font-family': SANS, 'pointer-events': 'none' }, ch16, '16');

    key(svg, 'dual', R(235, 238), R(296, 280), 'DUAL');
    key(svg, 'scan', R(313, 238), R(374, 280), 'SCAN', { sub: 'TAG' });
    key(svg, 'dial', R(392, 238), R(453, 280), 'DIAL');
    key(svg, 'call', R(470, 238), R(532, 280), 'CALL');
    key(svg, 'hl', R(235, 293), R(296, 334), 'HI/LO');
    key(svg, 'dimmer', R(470, 293), R(532, 334), 'DIMMER', { size: 11 });
    key(svg, 'power', R(155, 306), R(211, 336), 'POWER', { size: 10 });
    const [icx, icy] = R(455, 298);
    el('rect', { x: icx - 9, y: icy - 8, width: 18, height: 12, rx: 2, fill: '#d9dce1' }, svg);
    el('text', { x: icx, y: icy + 1.5, 'text-anchor': 'middle', 'font-size': 8, 'font-weight': 700, fill: '#2b2d31', 'font-family': SANS }, svg, 'IC');

    const [vx, vy] = R(148, 382);
    const [qx, qy] = R(250, 382);
    const vol = knob(svg, { id: 'vol', cx: vx, cy: vy, r: 25, ariaLabel: 'Lautstärke (VOL)' });
    const sql = knob(svg, { id: 'sql', cx: qx, cy: qy, r: 25, ariaLabel: 'Rauschsperre (SQL)' });
    el('text', { x: R(186, 0)[0], y: R(0, 356)[1], 'text-anchor': 'middle', 'font-size': 10, 'font-weight': 700, fill: LABEL, 'font-family': SANS }, svg, 'VOL');
    el('text', { x: R(288, 0)[0], y: R(0, 356)[1], 'text-anchor': 'middle', 'font-size': 10, 'font-weight': 700, fill: LABEL, 'font-family': SANS }, svg, 'SQL');
    const [kx, ky] = R(383, 362);
    const channel = knob(svg, { id: 'channel', cx: kx, cy: ky, r: 60, ariaLabel: 'Kanalwahlknopf (CHANNEL)', dots: false });

    // Kabeldurchführung mit Hörerkabel (ohne Funktion)
    const [gx, gy] = R(517, 386);
    el('path', { d: `M${gx + 3} ${gy + 50} C ${gx + 6} ${gy + 62}, ${gx + 9} ${gy + 70}, ${gx + 11} 402`, fill: 'none', stroke: '#141517', 'stroke-width': 12 }, svg);
    // Knickschutz mit Rillen wie bei den anderen Geräten
    el('path', { d: `M${gx - 11} ${gy + 10} L ${gx - 8} ${gy + 56} L ${gx + 13} ${gy + 56} L ${gx + 12} ${gy + 10} Z`, fill: '#1a1b1e', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    for (let i = 0; i < 4; i++) el('line', { x1: gx - 10, y1: gy + 28 + i * 7, x2: gx + 12, y2: gy + 28 + i * 7, stroke: '#3a3c41', 'stroke-width': 2 }, svg);
    el('circle', { cx: gx, cy: gy, r: 26, fill: 'url(#m503-knob)', stroke: '#121315', 'stroke-width': 2 }, svg);
    el('circle', { cx: gx, cy: gy, r: 13, fill: '#1d1f22' }, svg);

    // --- DS-100 -----------------------------------------------------------------
    housing(svg, D(612, 75), D(1163, 435), 'DSC CONTROLLER      DS-100', [D(705, 0)[0], D(905, 0)[0]]);
    const dsBox = lcdPanel(svg, D(678, 128), D(932, 292), D(688, 138), D(922, 268));
    const ds = el('g', { 'font-family': MONO, fill: LCD_INK }, svg);

    // Zifferntastatur
    const pad = [['d1', '1', 'QZ'], ['d2', '2', 'ABC'], ['d3', '3', 'DEF'], ['d4', '4', 'GHI'], ['d5', '5', 'JKL'], ['d6', '6', 'MNO'], ['d7', '7', 'PRS'], ['d8', '8', 'TUV'], ['d9', '9', 'WXY'], ['dsAa', 'A/a', null], ['d0', '0', '-/.'], ['dsBs', 'BS', '←']];
    const cols = [975, 1022, 1070];
    const rows = [121, 167, 213, 259];
    pad.forEach(([id, label, sub], i) => {
        const [px, py] = [cols[i % 3], rows[Math.floor(i / 3)]];
        key(svg, id, D(px, py), D(px + 41, py + 32), label, { sub, size: sub ? 12 : 13, subSize: 7 });
    });

    // Pfeiltasten
    key(svg, 'dsUp', D(1018, 313), D(1064, 347), '▲', { ariaLabel: 'DS-100 ▲' });
    key(svg, 'dsDown', D(1018, 367), D(1064, 401), '▼', { ariaLabel: 'DS-100 ▼' });
    key(svg, 'dsLeft', D(966, 330), D(1007, 390), '◀', { ariaLabel: 'DS-100 ◀' });
    key(svg, 'dsRight', D(1075, 330), D(1116, 390), '▶', { ariaLabel: 'DS-100 ▶' });

    // DISTRESS unter roter Klappe
    const [rx1, ry1] = D(683, 318);
    const [rx2, ry2] = D(750, 404);
    el('rect', { x: rx1, y: ry1, width: rx2 - rx1, height: ry2 - ry1, rx: 7, fill: '#17181b', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    const distress = el('g', { 'data-key': 'distress', tabindex: '0', role: 'button', 'aria-label': 'DISTRESS (5 Sekunden halten)', class: 'fg-control', style: 'cursor:pointer' }, svg);
    el('rect', { x: rx1 + 7, y: ry1 + 22, width: rx2 - rx1 - 14, height: ry2 - ry1 - 30, rx: 5, fill: 'url(#m503-red)', stroke: '#5b080d', 'stroke-width': 1.5 }, distress);
    el('text', { x: (rx1 + rx2) / 2, y: ry1 + 50, 'text-anchor': 'middle', 'font-size': 9, 'font-weight': 700, fill: '#ffffff', 'font-family': SANS, 'pointer-events': 'none' }, distress, 'DISTRESS');
    const cover = el('g', { 'data-key': 'distressCover', tabindex: '0', role: 'button', 'aria-label': 'Schutzklappe der DISTRESS-Taste', class: 'fg-control', style: 'cursor:pointer' }, svg);
    const coverClosed = { y: ry1 + 4, height: ry2 - ry1 - 8 };
    const coverOpen = { y: ry1 - 22, height: 22 };
    const coverLid = el('rect', { x: rx1 + 3, ...coverClosed, width: rx2 - rx1 - 6, rx: 6, fill: '#e2333d', 'fill-opacity': 0.55, stroke: '#9b1219', 'stroke-width': 1.5 }, cover);
    el('rect', { x: rx1 + 9, y: ry1 + 8, width: 5, height: 18, rx: 2, fill: '#ffffff', 'fill-opacity': 0.35, 'pointer-events': 'none' }, cover);

    key(svg, 'dsClr', D(760, 330), D(812, 388), 'CLR');
    key(svg, 'dsCall', D(818, 330), D(870, 388), 'CALL');
    key(svg, 'dsEnt', D(876, 330), D(928, 388), 'ENT');
    const [l1x, l1y] = D(820, 302);
    const [l2x] = D(926, 0);
    el('line', { x1: l1x, y1: l1y, x2: l2x, y2: l1y, stroke: LABEL, 'stroke-width': 1.5 }, svg);
    el('rect', { x: (l1x + l2x) / 2 - 11, y: l1y - 7, width: 22, height: 13, fill: '#3c3f44' }, svg);
    el('text', { x: (l1x + l2x) / 2, y: l1y + 3.5, 'text-anchor': 'middle', 'font-size': 9.5, 'font-weight': 700, fill: LABEL, 'font-family': SANS }, svg, 'TX');

    // Hörer vor der linken Gehäusekante
    drawHandset(svg, { prefix: 'm503', model: 'round', x: 24, y: 4, scale: 0.96, bottom: 400 });

    const light = createBacklight();
    svg.querySelectorAll('[data-lit]').forEach((node) => light.add(node, ...LIT[node.dataset.lit]));

    const highlight = el('rect', { fill: 'none', stroke: '#e3b505', 'stroke-width': 3.5, 'stroke-dasharray': '9 5', rx: 10, visibility: 'hidden', 'pointer-events': 'none' }, svg);
    let channelAngle = 0;

    function text(parent, x, y, value, attrs = {}) {
        el('text', { x, y, 'font-size': 12, 'xml:space': 'preserve', ...attrs }, parent, value);
    }

    function drawRadio(d) {
        radioLcd.replaceChildren();
        if (! d.on) return;
        const { x, y, w, h } = lcd;
        if (d.setMode) {
            text(radioLcd, x + 6, y + 15, 'Set mode', { 'font-size': 12, 'font-weight': 700 });
            text(radioLcd, x + 6, y + 36, d.setMode.item, { 'font-size': 12 });
            el('text', { x: x + w - 6, y: y + h - 8, 'text-anchor': 'end', 'font-size': 32, 'font-weight': 700, 'font-family': SANS }, radioLcd, d.setMode.value);

            return;
        }
        const st = d.status;
        text(radioLcd, x + 6, y + 14, [st.tx ? 'TX' : st.busy ? 'BUSY' : '', st.group].filter(Boolean).join(' '), { 'font-size': 11.5, 'font-weight': 700 });
        text(radioLcd, x + 6, y + 30, [st.power, st.call ? 'CALL' : ''].filter(Boolean).join(' '), { 'font-size': 11.5 });
        text(radioLcd, x + 6, y + 45, [st.tag ? 'TAG' : '', st.dup ? 'DUP' : ''].filter(Boolean).join(' '), { 'font-size': 11.5 });
        text(radioLcd, x + 6, y + h - 6, d.watch ?? d.channelName ?? '', { 'font-size': 10.5 });
        el('text', { x: x + w - 6, y: y + h - 9, 'text-anchor': 'end', 'font-size': 58, 'font-weight': 700, 'font-family': SANS }, radioLcd, d.channel);
    }

    function drawDs(d) {
        ds.replaceChildren();
        if (! d.on) return;
        const p = d.ds;
        const { x, y, w, h } = dsBox;
        let ty = y + 15;
        if (p.title) { text(ds, x + 6, ty, p.title, { 'font-size': 12, 'font-weight': 700 }); ty += 16; }
        if (p.items) {
            // Höchstens fünf Einträge, das ausgewählte bleibt sichtbar.
            const first = Math.max(0, Math.min(p.cursor - 2, p.items.length - 5));
            p.items.slice(first, first + 5).forEach((item, i) => {
                const selected = first + i === p.cursor;
                if (selected) el('rect', { x: x + 3, y: ty - 11, width: w - 6, height: 14, fill: LCD_INK }, ds);
                text(ds, x + 6, ty, ` ${item}`, { 'font-size': 12, fill: selected ? '#f4a23a' : LCD_INK });
                ty += 15;
            });
        }
        (p.lines ?? []).forEach((line) => { text(ds, x + 6, ty, line, { 'font-size': 12, class: p.blink ? 'fg-blink' : '' }); ty += 15; });
        if (p.editor) {
            p.editor.chars.forEach((c, i) => {
                const cx = x + 8 + i * 11;
                text(ds, cx, ty + 6, c, { 'font-size': 14 });
                if (i === p.editor.cursor) el('rect', { x: cx - 1, y: ty + 9, width: 9, height: 2.5, fill: LCD_INK }, ds);
            });
        }
        if (typeof p.progress === 'number') {
            el('rect', { x: x + 14, y: y + h - 34, width: w - 28, height: 10, fill: 'none', stroke: LCD_INK, 'stroke-width': 1.5 }, ds);
            el('rect', { x: x + 16, y: y + h - 32, width: (w - 32) * p.progress, height: 6, fill: LCD_INK }, ds);
        }
        if (p.footer) text(ds, x + w / 2, y + h - 6, p.footer, { 'text-anchor': 'middle', 'font-size': 10.5 });
    }

    function setKnob(k, level) {
        k.pointer.setAttribute('transform', `rotate(${-135 + (level / 10) * 270} ${k.cx} ${k.cy})`);
    }

    return {
        svg,
        update(display, radioState, target) {
            light.set(display.on);
            drawRadio(display);
            drawDs(display);
            setKnob(vol, radioState.vol);
            setKnob(sql, radioState.sql);
            channel.pointer.setAttribute('transform', `rotate(${channelAngle} ${channel.cx} ${channel.cy})`);
            Object.entries(radioState.coverOpen ? coverOpen : coverClosed).forEach(([k, v]) => coverLid.setAttribute(k, v));
            const node = target ? svg.querySelector(`[data-key="${target}"], [data-knob="${target}"]`) : null;
            if (node) {
                const box = node.getBBox();
                highlight.setAttribute('x', box.x - 5);
                highlight.setAttribute('y', box.y - 5);
                highlight.setAttribute('width', box.width + 10);
                highlight.setAttribute('height', box.height + 10);
                highlight.setAttribute('visibility', 'visible');
            } else {
                highlight.setAttribute('visibility', 'hidden');
            }
        },
        rotateChannel(steps) {
            channelAngle += steps * 15;
        },
    };
}
