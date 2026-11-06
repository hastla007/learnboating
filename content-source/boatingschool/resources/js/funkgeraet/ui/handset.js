// Hörer (Handmikrofon) der Gerätezeichnungen nach den Originalen: groß, vor
// der linken Gehäusekante, mit großer Sprechtaste (PTT) an der linken Seite
// und dem Kabel gerade nach unten. Drei Bauformen:
//   round      – runde Sprechmuschel oben, dunkle Wippe ▲/▼ und HI/LO
//                (IC-M503, IC-M505)
//   roundGrey  – runde Sprechmuschel, graue Tasten ▲/▼ versetzt und HI/LO,
//                beim IC-M423 zusätzlich 16/C (IC-M323, IC-M423)
//   egg        – IC-M330: breit, oben mit großen Rundungen, nach unten zum
//                Kabel hin schmaler; Front mit Rand, sechs gerade
//                Sprechschlitze, darunter HI/LO, die hohe Wippe ▲/▼ und 16/C
//                auf einer Höhe; kurze Sprechtaste oben links
// Gezeichnet wird in einem Raster von 200 × 420, das mit place() an die
// Stelle der Zeichenfläche gesetzt wird; die Tasten tragen data-key wie
// zuvor (ptt, micUp, micDown, micHiLo, mic16).

const NS = 'http://www.w3.org/2000/svg';
const SANS = 'Arial, Helvetica, sans-serif';
const PRINT = '#c9ccd1';

function el(name, attrs = {}, parent = null, text = null) {
    const node = document.createElementNS(NS, name);
    for (const [key, value] of Object.entries(attrs)) node.setAttribute(key, value);
    if (text !== null) node.textContent = text;
    if (parent) parent.appendChild(node);

    return node;
}

function defs(svg, p) {
    const d = el('defs', {}, svg);
    const grad = (id, stops, attrs = {}) => {
        const g = el('linearGradient', { id: `${p}-${id}`, x1: 0, y1: 0, x2: 0, y2: 1, ...attrs }, d);
        stops.forEach(([o, c]) => el('stop', { offset: o, 'stop-color': c }, g));
    };
    const radial = (id, stops) => {
        const g = el('radialGradient', { id: `${p}-${id}`, cx: '42%', cy: '35%', r: '70%' }, d);
        stops.forEach(([o, c]) => el('stop', { offset: o, 'stop-color': c }, g));
    };
    grad('body', [[0, '#45484e'], [0.45, '#2c2e33'], [1, '#191a1d']], { x1: 0, x2: 1, y1: 0, y2: 0.4 });
    grad('key', [[0, '#3a3d42'], [1, '#141517']]);
    grad('grey', [[0, '#a8aaae'], [0.5, '#8d8f93'], [1, '#6c6e72']]);
    grad('blue', [[0, '#4a8fea'], [1, '#1b54b0']]);
    grad('ptt', [[0, '#2c2e32'], [1, '#121315']], { x1: 0, x2: 1, y1: 0, y2: 0 });
    radial('grill', [[0, '#2a2c30'], [1, '#141517']]);
    const shadow = el('filter', { id: `${p}-shadow`, x: '-20%', y: '-10%', width: '140%', height: '130%' }, d);
    el('feDropShadow', { dx: 2, dy: 4, stdDeviation: 4, 'flood-color': '#000', 'flood-opacity': 0.45 }, shadow);
    const keyShadow = el('filter', { id: `${p}-kshadow`, x: '-15%', y: '-15%', width: '140%', height: '140%' }, d);
    el('feDropShadow', { dx: 0, dy: 1.5, stdDeviation: 1.2, 'flood-color': '#000', 'flood-opacity': 0.6 }, keyShadow);
}

const BODY = {
    round: 'M100 8 C 166 8 192 52 192 110 C 192 160 180 192 172 232 L 160 330 Q 154 374 100 376 Q 46 374 40 330 L 28 232 C 20 192 8 160 8 110 C 8 52 34 8 100 8 Z',
    egg: 'M42 6 L 158 6 C 190 6 200 40 200 88 C 200 140 196 180 188 200 C 178 232 160 262 140 276 Q 122 290 100 290 Q 78 290 60 276 C 40 262 22 232 12 200 C 4 180 0 140 0 88 C 0 40 10 6 42 6 Z',
};
BODY.roundGrey = BODY.round;
// Unterkante des Gehäuses je Bauform (dort sitzt der Knickschutz).
const BASE = { round: 376, roundGrey: 376, egg: 290 };

// Sprechtaste je Bauform: [Oberkante, Länge] im Raster.
const PTT = { round: [50, 104], roundGrey: [50, 104], egg: [12, 124] };

const LABELS = {
    ptt: 'Sprechtaste (PTT) gedrückt halten',
    micUp: 'Kanal auf am Hörer',
    micDown: 'Kanal ab am Hörer',
    micHiLo: 'HI/LO am Hörer',
    mic16: '16/C am Hörer',
};

/**
 * @param {SVGElement} svg
 * @param {{ prefix: string, model: 'round'|'roundGrey'|'egg', x: number, y: number, scale: number, bottom: number, mic16?: boolean }} options
 *   x/y: linke obere Ecke des Rasters, scale: Faktor, bottom: Unterkante der
 *   Zeichenfläche (dort endet das Kabel).
 */
export function drawHandset(svg, { prefix, model, x: ox, y: oy, scale: k, bottom, mic16 = false }) {
    const p = `${prefix}-hs`;
    defs(svg, p);
    const X = (x) => ox + x * k;
    const Y = (y) => oy + y * k;
    const rect = (x, y, w, h, attrs, parent) => el('rect', { x: X(x), y: Y(y), width: w * k, height: h * k, ...attrs, rx: (attrs.rx ?? 0) * k }, parent);
    const text = (x, y, value, size, attrs = {}, parent = svg) => el('text', { x: X(x), y: Y(y), 'text-anchor': 'middle', 'font-size': size * k, 'font-weight': 700, fill: PRINT, 'font-family': SANS, ...attrs }, parent, value);
    const group = (id) => el('g', { 'data-key': id, tabindex: '0', role: 'button', 'aria-label': LABELS[id], class: 'fg-control', style: 'cursor:pointer' }, svg);

    // Kabel gerade nach unten, Knickschutz mit Rillen
    const base = BASE[model];
    el('path', { d: `M${X(100)} ${Y(base + 24)} C ${X(100)} ${Y(base + 54)}, ${X(98)} ${bottom - 30}, ${X(96)} ${bottom + 2}`, fill: 'none', stroke: '#141517', 'stroke-width': 15 * k, 'stroke-linecap': 'butt' }, svg);
    rect(80, base - 10, 40, 40, { rx: 8, fill: '#1c1d20', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    for (let i = 0; i < 5; i++) rect(80, base - 4 + i * 7, 40, 2.5, { fill: '#3a3c41' }, svg);

    // Große Sprechtaste an der linken Seite, im oberen Teil des Mikrofons
    // und so lang wie beim jeweiligen Modell. Sie liegt hinter dem Gehäuse
    // und schaut nur seitlich heraus, steht also nicht ins Mikrofon.
    const [pttTop, pttLength] = PTT[model];
    const ptt = group('ptt');
    rect(-22, pttTop, 38, pttLength, { rx: 13, fill: `url(#${p}-ptt)`, stroke: '#0b0c0d', 'stroke-width': 2.5, filter: `url(#${p}-kshadow)` }, ptt);
    const grips = Math.floor((pttLength - 36) / 13);
    for (let i = 0; i < grips; i++) rect(-17, pttTop + (pttLength - (grips - 1) * 13) / 2 - 1.5 + i * 13, 12, 3, { rx: 1.5, fill: '#44474c', 'pointer-events': 'none' }, ptt);
    text(-6, pttTop - 8, 'PTT', 13, { fill: '#6b6e74' });

    // Gehäuse
    const path = BODY[model];
    el('path', { d: path, transform: `translate(${ox} ${oy}) scale(${k})`, fill: `url(#${p}-body)`, stroke: '#0b0c0d', 'stroke-width': 3 / k, filter: `url(#${p}-shadow)` }, svg);
    el('path', { d: path, transform: `translate(${ox + 7 * k} ${oy + 7 * k}) scale(${k * 0.93})`, fill: 'none', stroke: '#ffffff', 'stroke-opacity': 0.07, 'stroke-width': 2 / k }, svg);

    // Sprechmuschel bzw. Sprechschlitze
    if (model === 'egg') {
        // Front innerhalb des Gehäuserands
        el('path', { d: path, transform: `translate(${ox + 8 * k} ${oy + 9 * k}) scale(${k * 0.92})`, fill: '#26282c', stroke: '#0b0c0d', 'stroke-opacity': 0.8, 'stroke-width': 1.5 / k }, svg);
        // Sechs gerade Sprechschlitze mit hellem Rand
        [[56, 37, 172], [75, 37, 174], [94, 38, 174], [113, 38, 173], [132, 40, 171], [151, 48, 160]].forEach(([y, x1, x2]) => {
            rect(x1, y - 3.5, x2 - x1, 7, { rx: 3.5, fill: '#0d0e10' }, svg);
            el('line', { x1: X(x1 + 3), y1: Y(y - 4.5), x2: X(x2 - 3), y2: Y(y - 4.5), stroke: '#5a5d63', 'stroke-opacity': 0.7, 'stroke-width': 1.2 }, svg);
        });
    } else {
        el('circle', { cx: X(100), cy: Y(110), r: 82 * k, fill: '#232528', stroke: '#0b0c0d', 'stroke-width': 2 }, svg);
        el('circle', { cx: X(100), cy: Y(110), r: 72 * k, fill: `url(#${p}-grill)`, stroke: '#4a4d52', 'stroke-width': 1.5 }, svg);
        for (let i = -4; i <= 4; i++) {
            const dy = i * 14;
            const half = Math.sqrt(64 * 64 - dy * dy) - 4;
            rect(100 - half, 110 + dy - 4.5, half * 2, 9, { rx: 4.5, fill: '#0a0b0c' }, svg);
            el('line', { x1: X(100 - half + 4), y1: Y(110 + dy + 5.5), x2: X(100 + half - 4), y2: Y(110 + dy + 5.5), stroke: '#4f5258', 'stroke-opacity': 0.55, 'stroke-width': 1 }, svg);
        }
    }

    // Tasten
    const key = (id, [x, y, w, h], { fill = `url(#${p}-key)`, rx = 10, glyph = '', ink = '#9a9ea6', size = 18 } = {}) => {
        const g = group(id);
        rect(x, y, w, h, { rx, fill, stroke: '#0b0c0d', 'stroke-width': 2, filter: `url(#${p}-kshadow)` }, g);
        rect(x + 4, y + 3, w - 8, h * 0.35, { rx: Math.max(rx - 4, 2), fill: '#ffffff', 'fill-opacity': 0.08, 'pointer-events': 'none' }, g);
        if (glyph) text(x + w / 2, y + h / 2 + size * 0.36, glyph, size, { fill: ink, 'pointer-events': 'none' }, g);

        return g;
    };
    const rocker = ([x, y, w, h]) => {
        rect(x - 3, y - 3, w + 6, h + 6, { rx: w / 2 + 3, fill: '#0b0c0d' }, svg);
        key('micUp', [x, y, w, h / 2 - 1.5], { rx: w / 2, glyph: '△' });
        key('micDown', [x, y + h / 2 + 1.5, w, h / 2 - 1.5], { rx: w / 2, glyph: '▽' });
    };

    if (model === 'round') {
        rocker([50, 228, 42, 92]);
        text(128, 254, 'HI/LO', 14);
        key('micHiLo', [104, 262, 48, 36], { rx: 10 });
    } else if (model === 'roundGrey') {
        const grey = { fill: `url(#${p}-grey)`, ink: '#45474b', rx: 9 };
        key('micUp', [40, 226, 46, 40], { ...grey, glyph: '△' });
        key('micDown', [56, 274, 46, 40], { ...grey, glyph: '▽' });
        text(130, 250, 'HI/LO', 13);
        key('micHiLo', [108, 258, 44, 38], grey);
        if (mic16) key('mic16', [108, 304, 44, 34], { fill: `url(#${p}-blue)`, ink: '#ffffff', glyph: '16/C', size: 13, rx: 9 });
    } else {
        // Wie am Original: HI/LO und 16/C auf einer Höhe, dazwischen die
        // hohe Wippe.
        rocker([83, 180, 34, 74]);
        text(57, 188, 'HI/LO', 10);
        key('micHiLo', [40, 194, 34, 32], { rx: 9 });
        text(143, 188, '16/C', 10);
        key('mic16', [126, 194, 34, 32], { rx: 9 });
    }
}
