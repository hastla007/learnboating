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

// Displays, in denen der Kanal- bzw. Kombiknopf nicht den Kanal/die Lautstärke stellt, sondern
// die Markierung im Menü bewegt (siehe turn() in devices/*.js). Dort gibt es keinen Zahlenwert.
const SELECT_SCREENS = {
    'ic-m505': ['menu', 'nature', 'callAddress', 'callCategory', 'callChannel', 'ackAction', 'setMode', 'editor'],
    'ic-m323': ['menu', 'ackChoice', 'editor', 'callChannel'],
    'ic-m423': ['menu', 'ackChoice', 'editor', 'callChannel'],
    'ic-m330': ['menu', 'dscForm', 'distressMenu', 'editor', 'channelSelect'],
};
// Texte kommen übersetzbar aus dem Plugin (Strings.php, sim_aria_*); die deutschen Werte sind nur Ersatz.
const FALLBACK = {
    sim_aria_select: 'Menüauswahl im Display: Pfeiltasten bewegen die Markierung',
    sim_aria_channel: 'Kanal {channel}',
    sim_aria_channel_group: 'Kanal {channel} ({group})',
    sim_aria_volume: 'Lautstärke {value} von {max}',
    sim_aria_squelch: 'Rauschsperre {value} von {max}',
    sim_aria_off: ', Gerät aus',
};

function t(key, vars = {}) {
    const strings = (typeof window !== 'undefined' && window.bpLearning && window.bpLearning.i18n) || {};
    let text = strings[key] || FALLBACK[key] || key;
    Object.keys(vars).forEach((name) => {
        text = text.split('{' + name + '}').join(String(vars[name]));
    });

    return text;
}

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

    return state.group && state.group !== 'INT' ? t('sim_aria_channel_group', { channel: number, group: state.group }) : t('sim_aria_channel', { channel: number });
}

function values(deviceKey, knob, state, groups) {
    const off = state.power ? '' : t('sim_aria_off');
    const volMax = VOL_MAX[deviceKey] ?? 20;
    const vol = { now: state.vol, min: 0, max: volMax, text: t('sim_aria_volume', { value: state.vol, max: volMax }) + off };
    const sql = { now: state.sql, min: 0, max: SQL_MAX, text: t('sim_aria_squelch', { value: state.sql, max: SQL_MAX }) + off };
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
    const selecting = (SELECT_SCREENS[deviceKey] ?? []).includes(state.screen);
    svg.querySelectorAll('[data-knob][role="slider"]').forEach((node) => {
        const knob = node.dataset.knob;
        if (selecting && (knob === 'channel' || knob === 'dial')) {
            ['aria-valuenow', 'aria-valuemin', 'aria-valuemax'].forEach((name) => node.removeAttribute(name));
            setAttrs(node, { 'aria-valuetext': t('sim_aria_select') });

            return;
        }
        const v = values(deviceKey, knob, state, groups);
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
