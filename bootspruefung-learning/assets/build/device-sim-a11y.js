// Screenreader-Werte für die Drehknöpfe der Gerätesimulation.
//
// Die Simulation selbst (assets/device-sim/funkgeraet) ist eine unveränderte
// Kopie aus hastla007/boatingschool (CI prüft das). Ihre Drehknöpfe haben
// role="slider", aber kein aria-valuenow/-min/-max/-valuetext; Screenreader
// können den eingestellten Wert deshalb nicht ansagen. Dieses Modul hängt
// sich an DEVICES[*].render (gleiche Modulinstanz wie app.js) und setzt die
// Werte nach jeder Aktualisierung des Geräts (device.update).
//
// Korrektur für das Quell-Repo: docs/quellrepo-hinweis-simulator-aria.md.
// Sobald die Quelle die Werte selbst setzt, kann dieses Modul entfallen.

// Lautstärkestufen je Gerät (siehe turn() in devices/*.js): IC-M505 und
// IC-M503 0–10, IC-M323/M423/M330 0–20. Rauschsperre überall 0–10.
const VOL_MAX = { 'ic-m505': 10, 'ic-m503': 10 };
const SQL_MAX = 10;

function setAttrs(node, attrs) {
    Object.entries(attrs).forEach(([name, value]) => {
        const text = String(value);
        if (node.getAttribute(name) !== text) node.setAttribute(name, text);
    });
}

function channelRange(groups, group) {
    const list = (groups && groups[group]) || (groups && groups.INT) || [];
    const numbers = list.map((c) => c.number).filter((n) => typeof n === 'number');

    return numbers.length ? { min: Math.min(...numbers), max: Math.max(...numbers) } : null;
}

function channelText(state) {
    const number = String(state.channel).padStart(2, '0');

    return state.group && state.group !== 'INT' ? `Kanal ${number} (${state.group})` : `Kanal ${number}`;
}

function values(deviceKey, knob, state, groups) {
    const off = state.power ? '' : ', Gerät aus';
    const volMax = VOL_MAX[deviceKey] ?? 20;
    const vol = { now: state.vol, min: 0, max: volMax, text: `Lautstärke ${state.vol} von ${volMax}${off}` };
    const sql = { now: state.sql, min: 0, max: SQL_MAX, text: `Rauschsperre ${state.sql} von ${SQL_MAX}${off}` };
    const channel = () => {
        const range = channelRange(groups, state.group);
        if (typeof state.channel !== 'number' || ! range) return null;

        return { now: state.channel, min: range.min, max: range.max, text: channelText(state) + off };
    };

    if (knob === 'vol') return typeof state.vol === 'number' ? vol : null;
    if (knob === 'sql') return typeof state.sql === 'number' ? sql : null;
    if (knob === 'channel') return channel();
    if (knob === 'dial') {
        // Ein Knopf für mehrere Werte (IC-M323/M423: vol/sql/chan, IC-M330: vol/sql).
        if (state.knobMode === 'sql') return typeof state.sql === 'number' ? sql : null;
        if (state.knobMode === 'chan') return channel();

        return typeof state.vol === 'number' ? vol : null;
    }

    return null;
}

export function apply(svg, deviceKey, state, groups) {
    if (! svg || ! state) return;
    svg.querySelectorAll('[data-knob][role="slider"]').forEach((node) => {
        const v = values(deviceKey, node.dataset.knob, state, groups);
        if (! v) return;
        setAttrs(node, { 'aria-valuenow': v.now, 'aria-valuemin': v.min, 'aria-valuemax': v.max, 'aria-valuetext': v.text });
    });
}

// devices: DEVICES aus funkgeraet/devices/index.js, groups: CHANNEL_GROUPS aus funkgeraet/channels.js.
export function install(devices, groups) {
    Object.values(devices).forEach((def) => {
        if (! def || typeof def.render !== 'function' || def.bpA11y) return;
        const render = def.render;
        def.render = function (...args) {
            const device = render.apply(this, args);
            if (device && typeof device.update === 'function') {
                const update = device.update;
                device.update = function (display, state, target) {
                    const result = update.call(this, display, state, target);
                    try { apply(device.svg, def.key, state, groups); } catch (e) { /* nur Zusatzinfo */ }

                    return result;
                };
            }

            return device;
        };
        def.bpA11y = true;
    });
}
