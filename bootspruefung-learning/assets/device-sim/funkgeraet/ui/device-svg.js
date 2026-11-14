// Zeichnung des IC-M505 (SVG) im Stil der Frontansicht: graues Gehäuse mit
// Typenleiste, Lautsprecherschlitze links, orange beleuchtetes Display mit
// „WATERPROOF“, DISTRESS unter roter Klappe und MENU/CLR/HAIL/IC rechts,
// POWER/H/L/SCAN/DIAL unten, VOL/SQL-Regler, großer Kanalwahlknopf mit
// ENTER, 16/C und Kabeldurchführung; ohne Halterung und ohne Herstellerlogo.
// Daneben der Hörer mit Sprechtaste. Jedes Bedienteil trägt data-key bzw.
// data-knob; die Oberfläche (app.js) hängt die Bedienung daran. Die
// Anzeige wird live in die Displayfläche geschrieben. Eingeschaltet leuchten
// die Tasten am Gerät und das Display (Hintergrundbeleuchtung).

import { createBacklight, glowFilter } from './backlight.js';
import { drawHandset } from './handset.js';

const NS = 'http://www.w3.org/2000/svg';

function el(name, attrs = {}, parent = null, text = null) {
    const node = document.createElementNS(NS, name);
    for (const [key, value] of Object.entries(attrs)) node.setAttribute(key, value);
    if (text !== null) node.textContent = text;
    if (parent) parent.appendChild(node);

    return node;
}

const KEY_TEXT = '#f4f5f7';
const LABEL = '#d9dce1';
const LCD = '#f4a23a';
const LCD_INK = '#2b1806';
const MONO = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';
const SANS = 'Arial, Helvetica, sans-serif';
const LIT = {
    label: [{ fill: '#fff1d2', filter: 'url(#m505-glow)' }, { fill: '#9a9ea6', filter: null }],
    rim: [{ stroke: '#f4ddb0' }, { stroke: '#141518' }],
};

// Koordinaten aus der Frontansicht (Foto, Gerät ohne Halterung) auf die
// Zeichenfläche: Gehäuse x 205–980, y 32–530.
const P = (px, py) => [205 + (px - 55) * 1.0403, 32 + (py - 12) * 1.0101];
const S = (v) => v * 1.04;

function defs(svg) {
    const d = el('defs', {}, svg);
    const grad = (id, stops, attrs = {}) => {
        const g = el('linearGradient', { id, x1: 0, y1: 0, x2: 0, y2: 1, ...attrs }, d);
        stops.forEach(([o, c]) => el('stop', { offset: o, 'stop-color': c }, g));
    };
    const radial = (id, stops, attrs = {}) => {
        const g = el('radialGradient', { id, ...attrs }, d);
        stops.forEach(([o, c]) => el('stop', { offset: o, 'stop-color': c }, g));
    };
    grad('m505-body', [[0, '#62666d'], [0.12, '#4a4e55'], [0.6, '#3b3e44'], [1, '#2c2e33']]);
    grad('m505-strip', [[0, '#6a6e75'], [1, '#4a4d53']]);
    grad('m505-key', [[0, '#5a5e65'], [0.5, '#3c3f45'], [1, '#2b2d32']]);
    grad('m505-bezel', [[0, '#2a2c30'], [1, '#18191c']]);
    grad('m505-lcd', [[0, '#f7b24d'], [0.55, '#f29d33'], [1, '#e6892a']]);
    grad('m505-red', [[0, '#d9333d'], [1, '#8e0f16']]);
    radial('m505-knob', [[0, '#55595f'], [0.7, '#2f3136'], [1, '#1c1d21']], { cx: '40%', cy: '35%', r: '65%' });
    radial('m505-cap', [[0, '#4d5056'], [1, '#26282c']], { cx: '40%', cy: '35%', r: '70%' });
    radial('m505-metal', [[0, '#f1f2f4'], [0.6, '#b9bcc2'], [1, '#7b7f86']], { cx: '40%', cy: '35%', r: '70%' });
    const shadow = el('filter', { id: 'm505-shadow', x: '-10%', y: '-10%', width: '130%', height: '130%' }, d);
    el('feDropShadow', { dx: 0, dy: 2, stdDeviation: 1.6, 'flood-color': '#000', 'flood-opacity': 0.55 }, shadow);
    glowFilter(d, 'm505-glow', NS, 1.4);
}

// Taste; lit: beleuchtete Taste am Gerät (Rand und Beschriftung leuchten).
function key(svg, { id, x, y, w, h, label, sub = null, ariaLabel = null, size = 17, lit = false }) {
    const g = el('g', { 'data-key': id, tabindex: '0', role: 'button', 'aria-label': ariaLabel ?? label, class: 'fg-control', style: 'cursor:pointer' }, svg);
    el('rect', { x, y, width: w, height: h, rx: 9, fill: 'url(#m505-key)', stroke: lit ? LIT.rim[0].stroke : '#141518', 'stroke-width': 2, filter: 'url(#m505-shadow)', ...(lit ? { 'data-lit': 'rim' } : {}) }, g);
    el('rect', { x: x + 3, y: y + 2, width: w - 6, height: h / 2 - 2, rx: 7, fill: '#ffffff', 'fill-opacity': 0.06, 'pointer-events': 'none' }, g);
    el('text', { x: x + w / 2, y: y + h / 2 + size * 0.36, 'text-anchor': 'middle', 'font-size': size, 'font-weight': 700, fill: KEY_TEXT, 'font-family': SANS, 'pointer-events': 'none', ...(lit ? { ...LIT.label[0], 'data-lit': 'label' } : {}) }, g, label);
    if (sub) el('text', { x: x + w / 2, y: y - 8, 'text-anchor': 'middle', 'font-size': 13, 'font-weight': 700, fill: LABEL, 'font-family': SANS, 'pointer-events': 'none' }, svg, sub);

    return g;
}

// Taste nach Fotokoordinaten (linke obere und rechte untere Ecke).
function photoKey(svg, id, [px1, py1], [px2, py2], label, options = {}) {
    const [x1, y1] = P(px1, py1);
    const [x2, y2] = P(px2, py2);

    return key(svg, { id, x: x1, y: y1, w: x2 - x1, h: y2 - y1, label, lit: true, ...options });
}

function knob(svg, { id, cx, cy, r, label, ariaLabel }) {
    const g = el('g', { 'data-knob': id, tabindex: '0', role: 'slider', 'aria-label': ariaLabel, class: 'fg-control', style: 'cursor:ns-resize;touch-action:none' }, svg);
    for (let i = 0; i < 11; i++) {
        const a = (-135 + i * 27) * Math.PI / 180;
        el('circle', { cx: cx + Math.sin(a) * (r + 13), cy: cy - Math.cos(a) * (r + 13), r: 2.4, fill: '#eceef1' }, g);
    }
    el('circle', { cx, cy, r, fill: 'url(#m505-knob)', stroke: '#141518', 'stroke-width': 2, filter: 'url(#m505-shadow)' }, g);
    // Griffrillen
    for (let i = 0; i < 24; i++) {
        const a = (i * 15) * Math.PI / 180;
        el('line', { x1: cx + Math.sin(a) * (r - 4), y1: cy - Math.cos(a) * (r - 4), x2: cx + Math.sin(a) * r, y2: cy - Math.cos(a) * r, stroke: '#121315', 'stroke-width': 1.6 }, g);
    }
    el('circle', { cx, cy, r: r * 0.62, fill: 'url(#m505-cap)', 'pointer-events': 'none' }, g);
    const pointer = el('line', { x1: cx, y1: cy - r * 0.2, x2: cx, y2: cy - r + 6, stroke: '#f2f4f7', 'stroke-width': 3.5, 'stroke-linecap': 'round' }, g);
    if (label) el('text', { x: cx + r + 18, y: cy + 22, 'font-size': 18, 'font-weight': 700, fill: KEY_TEXT, 'font-family': SANS }, svg, label);

    return { g, pointer, cx, cy };
}

export function renderDevice(container) {
    const svg = el('svg', { viewBox: '0 0 1000 560', role: 'group', 'aria-label': 'Funkgerät IC-M505 (Nachbildung)', style: 'width:100%;height:auto;user-select:none;-webkit-user-select:none' });
    container.appendChild(svg);
    defs(svg);

    // Gehäuse mit Typenleiste
    el('rect', { x: 205, y: 32, width: 775, height: 498, rx: 34, fill: 'url(#m505-body)', stroke: '#1b1c1f', 'stroke-width': 3 }, svg);
    el('rect', { x: 213, y: 40, width: 759, height: 482, rx: 28, fill: 'none', stroke: '#ffffff', 'stroke-opacity': 0.08, 'stroke-width': 2 }, svg);
    const [sx1, sy1] = P(340, 16);
    const [sx2, sy2] = P(596, 52);
    el('rect', { x: sx1, y: sy1, width: sx2 - sx1, height: sy2 - sy1, rx: 6, fill: 'url(#m505-strip)', stroke: '#2a2c30', 'stroke-width': 1.5 }, svg);
    el('text', { x: (sx1 + sx2) / 2, y: sy2 - 11, 'text-anchor': 'middle', 'font-size': 15, 'font-weight': 700, fill: '#eef0f2', 'font-family': SANS, 'letter-spacing': 1 }, svg, 'VHF MARINE   IC-M505');

    // Lautsprecherschlitze
    for (let i = 0; i < 5; i++) {
        const [x1, y1] = P(102, 80 + i * 43);
        const [x2] = P(270, 0);
        el('path', { d: `M${x1} ${y1} Q ${(x1 + x2) / 2} ${y1 + 9} ${x2} ${y1} L ${x2} ${y1 + 15} Q ${(x1 + x2) / 2} ${y1 + 24} ${x1} ${y1 + 15} Z`, fill: '#1c1d20', stroke: '#5c6067', 'stroke-width': 1, 'stroke-opacity': 0.5 }, svg);
    }

    // Display mit Rahmen; die Anzeige wird live hineingeschrieben.
    const [bx1, by1] = P(286, 80);
    const [bx2, by2] = P(650, 302);
    el('rect', { x: bx1, y: by1, width: bx2 - bx1, height: by2 - by1, rx: 20, fill: 'url(#m505-bezel)', stroke: '#101113', 'stroke-width': 2 }, svg);
    const [lx1, ly1] = P(305, 95);
    const [lx2, ly2] = P(622, 272);
    const lcdBox = { x: lx1, y: ly1, width: lx2 - lx1, height: ly2 - ly1 };
    const lcdLight = el('rect', { ...lcdBox, rx: 8, fill: 'url(#m505-lcd)' }, svg);
    el('text', { x: (bx1 + bx2) / 2, y: by2 - 9, 'text-anchor': 'middle', 'font-size': 13, 'font-weight': 700, 'font-style': 'italic', fill: '#c9ccd1', 'font-family': SANS, 'letter-spacing': 1 }, svg, 'WATERPROOF');
    // Die Anzeige ist für eine Fläche von 386 × 216 ab (392, 102) geschrieben
    // und wird in die Displayfläche eingepasst.
    const k = Math.min(lcdBox.width / 386, lcdBox.height / 216);
    const tx = lcdBox.x + (lcdBox.width - 386 * k) / 2 - 392 * k;
    const ty = lcdBox.y + (lcdBox.height - 216 * k) / 2 - 102 * k;
    const lcd = el('g', { 'font-family': MONO, fill: LCD_INK, transform: `translate(${tx} ${ty}) scale(${k})` }, svg);

    // DISTRESS mit Klappe (oben rechts)
    const [dx1, dy1] = P(678, 58);
    const [dx2, dy2] = P(740, 138);
    el('rect', { x: dx1, y: dy1, width: dx2 - dx1, height: dy2 - dy1, rx: 8, fill: '#1b1c1f', stroke: '#0e0f11', 'stroke-width': 2 }, svg);
    const distress = el('g', { 'data-key': 'distress', tabindex: '0', role: 'button', 'aria-label': 'DISTRESS-Taste (5 Sekunden halten)', class: 'fg-control', style: 'cursor:pointer' }, svg);
    el('rect', { x: dx1 + 7, y: dy1 + 22, width: dx2 - dx1 - 14, height: dy2 - dy1 - 30, rx: 6, fill: 'url(#m505-red)', stroke: '#5b080d', 'stroke-width': 2 }, distress);
    el('text', { x: (dx1 + dx2) / 2, y: dy2 - 20, 'text-anchor': 'middle', 'font-size': 9.5, 'font-weight': 700, fill: '#ffffff', 'font-family': SANS, 'pointer-events': 'none' }, distress, 'DISTRESS');
    // Schutzklappe; geöffnet klappt sie nach oben weg.
    const cover = el('g', { 'data-key': 'distressCover', tabindex: '0', role: 'button', 'aria-label': 'Schutzklappe der DISTRESS-Taste', class: 'fg-control', style: 'cursor:pointer' }, svg);
    const coverClosed = { y: dy1 + 4, height: dy2 - dy1 - 8 };
    const coverOpen = { y: dy1 - 26, height: 26 };
    const coverLid = el('rect', { x: dx1 + 3, ...coverClosed, width: dx2 - dx1 - 6, rx: 6, fill: '#e2333d', 'fill-opacity': 0.55, stroke: '#9b1219', 'stroke-width': 2 }, cover);
    el('rect', { x: dx1 + 10, y: dy1 + 8, width: 6, height: 20, rx: 2, fill: '#ffffff', 'fill-opacity': 0.35, 'pointer-events': 'none' }, cover);

    // Tasten rechts
    photoKey(svg, 'menu', [682, 156], [746, 193], 'MENU');
    photoKey(svg, 'clr', [682, 206], [746, 243], 'CLR');
    const [rxX, rxY] = P(714, 256);
    el('text', { x: rxX, y: rxY, 'text-anchor': 'middle', 'font-size': 12, 'font-weight': 700, fill: LABEL, 'font-family': SANS }, svg, 'RX ◀))');
    photoKey(svg, 'hail', [682, 268], [746, 306], 'HAIL');
    photoKey(svg, 'ic', [682, 330], [746, 370], 'IC');

    // 16/C
    const [c16x, c16y] = P(624, 368);
    const ch16 = el('g', { 'data-key': 'ch16', tabindex: '0', role: 'button', 'aria-label': '16/C: Kanal 16, 1 Sekunde Anrufkanal', class: 'fg-control', style: 'cursor:pointer' }, svg);
    el('circle', { cx: c16x + 6, cy: c16y, r: S(25), fill: 'url(#m505-metal)', stroke: '#2b2d31', 'stroke-width': 2, filter: 'url(#m505-shadow)' }, ch16);
    el('circle', { cx: c16x - 3, cy: c16y, r: S(16), fill: '#1d5fc4', stroke: '#0f3576', 'stroke-width': 1.5 }, ch16);
    el('text', { x: c16x - 3, y: c16y + 6, 'text-anchor': 'middle', 'font-size': 16, 'font-weight': 700, fill: '#ffffff', 'font-family': SANS, 'pointer-events': 'none' }, ch16, '16');
    el('text', { x: c16x + 22, y: c16y + 6, 'text-anchor': 'middle', 'font-size': 14, 'font-weight': 700, fill: '#2b2d31', 'font-family': SANS, 'pointer-events': 'none' }, ch16, 'C');

    // Tasten unten
    photoKey(svg, 'power', [108, 328], [168, 363], 'POWER', { ariaLabel: 'POWER: Ein, 1 Sekunde halten Aus', size: 13 });
    photoKey(svg, 'hl', [186, 328], [243, 363], 'H/L', { ariaLabel: 'H/L: Sendeleistung, gehalten Umschalttaste' });
    photoKey(svg, 'scan', [263, 328], [322, 363], 'SCAN', { sub: 'TAG' });
    photoKey(svg, 'dial', [340, 328], [402, 363], 'DIAL', { ariaLabel: 'DIAL: zurück zum Betriebskanal, 1 Sekunde Zweikanalwache' });
    const [dgx, dgy] = P(371, 322);
    el('text', { x: dgx - 14, y: dgy, 'text-anchor': 'middle', 'font-size': 12, 'font-weight': 700, fill: LABEL, 'font-family': SANS }, svg, 'DUAL');
    el('rect', { x: dgx + 4, y: dgy - 11, width: 30, height: 14, rx: 2, fill: LABEL }, svg);
    el('text', { x: dgx + 19, y: dgy, 'text-anchor': 'middle', 'font-size': 11, 'font-weight': 700, fill: '#3b3e44', 'font-family': SANS }, svg, 'GRP');

    // Regler VOL und SQL, Kanalwahlknopf mit ENTER
    const [vx, vy] = P(160, 455);
    const [qx, qy] = P(305, 455);
    const vol = knob(svg, { id: 'vol', cx: vx, cy: vy, r: S(29), label: 'VOL', ariaLabel: 'Lautstärke VOL' });
    const sql = knob(svg, { id: 'sql', cx: qx, cy: qy, r: S(29), label: 'SQL', ariaLabel: 'Rauschsperre SQL' });
    const [kx, ky] = P(500, 414);
    const channel = knob(svg, { id: 'channel', cx: kx, cy: ky, r: S(82), label: null, ariaLabel: 'Kanalwahlknopf' });
    channel.g.querySelectorAll('circle[r="2.4"]').forEach((dot) => dot.remove());
    const enter = el('g', { 'data-key': 'enter', tabindex: '0', role: 'button', 'aria-label': 'ENTER (Kanalwahlknopf drücken)', class: 'fg-control', style: 'cursor:pointer' }, svg);
    el('circle', { cx: kx, cy: ky, r: S(50), fill: 'url(#m505-cap)', stroke: '#141518', 'stroke-width': 1.5 }, enter);
    const [ex, ey] = P(606, 474);
    el('text', { x: ex, y: ey, 'text-anchor': 'middle', 'font-size': 16, 'font-weight': 700, fill: KEY_TEXT, 'font-family': SANS }, svg, 'ENTER');

    // Kabeldurchführung mit Hörerkabel wie bei den anderen Geräten (ohne
    // Funktion): Gummitülle, Knickschutz mit Rillen schräg nach unten, Kabel.
    const [mx, my] = P(705, 446);
    el('path', { d: `M${mx - 26} ${my + 64} C ${mx - 32} ${my + 84}, ${mx - 36} 545, ${mx - 38} 562`, fill: 'none', stroke: '#141517', 'stroke-width': 13 }, svg);
    el('path', { d: `M${mx - 15} ${my + 6} L ${mx - 37} ${my + 62} L ${mx - 15} ${my + 68} L ${mx + 9} ${my + 13} Z`, fill: '#1a1b1e', stroke: '#0b0c0d', 'stroke-width': 1.5 }, svg);
    for (let i = 0; i < 5; i++) el('line', { x1: mx - 15 - i * 4, y1: my + 17 + i * 9.5, x2: mx + 4 - i * 4, y2: my + 23 + i * 9.5, stroke: '#3a3c41', 'stroke-width': 2 }, svg);
    el('circle', { cx: mx, cy: my, r: S(32), fill: 'url(#m505-knob)', stroke: '#0b0c0d', 'stroke-width': 2 }, svg);
    el('circle', { cx: mx, cy: my, r: S(21), fill: '#1a1b1e', stroke: '#4a4d52', 'stroke-width': 1.5 }, svg);

    // Hörer vor der linken Gehäusekante
    drawHandset(svg, { prefix: 'm505', model: 'round', x: 30, y: 34, scale: 1.15, bottom: 560 });

    // Hintergrundbeleuchtung: Display und beleuchtete Tasten
    const light = createBacklight();
    light.add(lcdLight, { fill: 'url(#m505-lcd)' }, { fill: '#4b4840' });
    svg.querySelectorAll('[data-lit]').forEach((node) => light.add(node, ...LIT[node.dataset.lit]));

    const highlight = el('rect', { fill: 'none', stroke: '#e3b505', 'stroke-width': 4, 'stroke-dasharray': '10 6', rx: 14, visibility: 'hidden', 'pointer-events': 'none' }, svg);

    function setKnob(k, level) {
        const angle = -135 + (level / 10) * 270;
        k.pointer.setAttribute('transform', `rotate(${angle} ${k.cx} ${k.cy})`);
    }

    let channelAngle = 0;

    function text(x, y, value, attrs = {}) {
        el('text', { x, y, 'font-size': 18, ...attrs }, lcd, value);
    }

    function drawDisplay(d) {
        lcd.replaceChildren();
        light.set(d.on);
        if (! d.on) return;
        const st = d.status;
        text(404, 126, [st.tx ? 'TX' : st.busy ? 'BUSY' : '', st.power].filter(Boolean).join(' '), { 'font-size': 17 });
        text(560, 126, [st.group, st.dup ? 'DUP' : ''].filter(Boolean).join(' '), { 'font-size': 17 });
        if (st.call) text(700, 126, 'CALL', { 'font-size': 17 });

        if (d.panel) {
            const p = d.panel;
            text(585, 152, p.title, { 'text-anchor': 'middle', 'font-size': 17, 'font-weight': 700 });
            if (p.subtitle) text(404, 176, p.subtitle, { 'font-size': 17 });
            let y = 200;
            if (p.items) {
                const first = Math.max(0, Math.min(p.cursor - 2, p.items.length - 5));
                p.items.slice(first, first + 5).forEach((item, i) => {
                    const selected = first + i === p.cursor;
                    text(404, y, `${selected ? '→' : ' '}${item}`, { 'font-size': 17, 'font-weight': selected ? 700 : 400 });
                    y += 21;
                });
            }
            if (p.editor) {
                const chars = p.editor.chars;
                chars.forEach((c, i) => {
                    const x = 410 + i * 15;
                    text(x, 214, c, { 'font-size': 20 });
                    if (i === p.editor.cursor) el('rect', { x: x - 1, y: 218, width: 13, height: 3, fill: LCD_INK }, lcd);
                });
            }
            (p.lines ?? []).forEach((line) => {
                text(585, y, line, { 'text-anchor': 'middle', 'font-size': 18, class: p.blink ? 'fg-blink' : '' });
                y += 23;
            });
            if (typeof p.progress === 'number') {
                el('rect', { x: 430, y: 250, width: 310, height: 14, fill: 'none', stroke: LCD_INK, 'stroke-width': 2 }, lcd);
                el('rect', { x: 432, y: 252, width: 306 * p.progress, height: 10, fill: LCD_INK }, lcd);
            }
            if (p.footer) text(585, 306, p.footer, { 'text-anchor': 'middle', 'font-size': 15 });

            return;
        }

        el('text', { x: 768, y: 268, 'text-anchor': 'end', 'font-size': 112, 'font-weight': 700, 'font-family': 'Arial, sans-serif' }, lcd, d.channel);
        text(768, 302, d.channelName, { 'text-anchor': 'end', 'font-size': 16 });
        if (d.watch) text(404, 152, d.watch, { 'font-size': 16 });
        d.lines.forEach((line, i) => text(404, 214 + i * 24, line, { 'font-size': 18, 'font-weight': d.alarm && i < 2 ? 700 : 400 }));
    }

    return {
        svg,
        update(display, radioState, target) {
            drawDisplay(display);
            setKnob(vol, radioState.vol);
            setKnob(sql, radioState.sql);
            channel.pointer.setAttribute('transform', `rotate(${channelAngle} ${channel.cx} ${channel.cy})`);
            Object.entries(radioState.coverOpen ? coverOpen : coverClosed).forEach(([k, v]) => coverLid.setAttribute(k, v));

            const node = target ? svg.querySelector(`[data-key="${target}"], [data-knob="${target}"]`) : null;
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
        rotateChannel(steps) {
            channelAngle += steps * 15;
        },
    };
}
