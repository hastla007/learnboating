# Hinweis für das Quell-Repo: Screenreader-Werte an den Drehknöpfen der Gerätesimulation

**Repo:** `hastla007/boatingschool`, Ordner `resources/js/funkgeraet/` (Stand des Snapshots: Commit `136a243`)
**Dieses Repo ändert die Quelle nicht.** Hier steht nur, wie sie geändert gehört. Bis dahin ergänzt das WordPress-Plugin die Werte selbst: `bootspruefung-learning/assets/build/device-sim-a11y.js`.

## Problem

Die Drehknöpfe haben `role="slider"` und ein `aria-label`. Es fehlen aber `aria-valuenow`, `aria-valuemin`, `aria-valuemax` und `aria-valuetext`. Screenreader sagen deshalb nur „Schieberegler, Lautstärke VOL“ an, aber nicht, wie laut es ist oder welcher Kanal eingestellt ist. Nach WCAG 2.2, Kriterium 4.1.2 (Name, Rolle, Wert), braucht ein Slider seinen aktuellen Wert.

Betroffen sind diese Knöpfe:

| Datei | Knöpfe (`data-knob`) |
| --- | --- |
| `ui/device-svg.js` (IC-M505) | `vol`, `sql`, `channel` |
| `ui/device-svg-m503.js` (IC-M503) | `vol`, `sql`, `channel` |
| `ui/device-svg-m323.js` (IC-M323/M423) | `dial` (Lautstärke, Rauschsperre oder Kanal, je nach `knobMode`) |
| `ui/device-svg-m330.js` (IC-M330) | `dial` (Lautstärke oder Rauschsperre) |

Die Wertebereiche stammen aus `turn()` in `devices/*.js`:
- Lautstärke: IC-M505 und IC-M503 0–10, IC-M323/M423/M330 0–20.
- Rauschsperre: überall 0–10.
- Kanal: Liste `CHANNEL_GROUPS[group]` aus `channels.js`.

## Änderung

### 1. Neue Datei `ui/knob-aria.js`

```js
import { CHANNEL_GROUPS } from '../channels.js';

// Setzt aria-valuenow/-min/-max/-valuetext eines Drehknopfs (role="slider").
export function setKnobAria(node, { now, min, max, text }) {
    const attrs = { 'aria-valuenow': now, 'aria-valuemin': min, 'aria-valuemax': max, 'aria-valuetext': text };
    Object.entries(attrs).forEach(([name, value]) => {
        if (node.getAttribute(name) !== String(value)) node.setAttribute(name, String(value));
    });
}

const off = (state) => (state.power ? '' : ', Gerät aus');

export const volAria = (state, max) => ({ now: state.vol, min: 0, max, text: `Lautstärke ${state.vol} von ${max}${off(state)}` });
export const sqlAria = (state) => ({ now: state.sql, min: 0, max: 10, text: `Rauschsperre ${state.sql} von 10${off(state)}` });

export function channelAria(state) {
    const numbers = (CHANNEL_GROUPS[state.group] ?? CHANNEL_GROUPS.INT).map((c) => c.number);
    const number = String(state.channel).padStart(2, '0');
    const group = state.group && state.group !== 'INT' ? ` (${state.group})` : '';

    return { now: state.channel, min: Math.min(...numbers), max: Math.max(...numbers), text: `Kanal ${number}${group}${off(state)}` };
}
```

### 2. In `update(display, radioState, target)` der Gerätedateien aufrufen

Am Anfang jeder Datei: `import { setKnobAria, volAria, sqlAria, channelAria } from './knob-aria.js';`

**`ui/device-svg.js`** (nach `setKnob(sql, radioState.sql);`). Bei `ui/device-svg-m503.js` dasselbe:

```js
setKnobAria(svg.querySelector('[data-knob="vol"]'), volAria(radioState, 10));
setKnobAria(svg.querySelector('[data-knob="sql"]'), sqlAria(radioState));
setKnobAria(svg.querySelector('[data-knob="channel"]'), channelAria(radioState));
```

`knob()` gibt in `device-svg.js` die Gruppe als `g` zurück, in `device-svg-m503.js` nicht; deshalb überall `querySelector`.

**`ui/device-svg-m323.js`** (nach dem Setzen von `pointer`):

```js
const aria = radioState.knobMode === 'sql' ? sqlAria(radioState)
    : radioState.knobMode === 'chan' ? channelAria(radioState)
    : volAria(radioState, 20);
setKnobAria(ring, aria);
```

**`ui/device-svg-m330.js`** genauso, aber ohne den Zweig `chan`:

```js
setKnobAria(ring, radioState.knobMode === 'sql' ? sqlAria(radioState) : volAria(radioState, 20));
```

### 3. Test (Vorschlag, `resources/js/funkgeraet/tests/`)

Für jedes Gerät prüfen:
- Nach dem Rendern und dem ersten `update()` hat jeder `[data-knob][role="slider"]` ein `aria-valuenow`.
- Nach `turn('vol', 1)` und `update()` ist `aria-valuenow` um 1 höher.
- Bei IC-M323 wechselt `aria-valuetext` nach dem Drücken der Knopfkappe von „Lautstärke …“ zu „Rauschsperre …“ und dann zu „Kanal …“.

## Danach in diesem Repo

1. `tools/sync-source.sh` und `tools/sync-device-sim.sh` mit dem neuen Commit ausführen.
2. Prüfen, ob die Knöpfe die Werte jetzt selbst setzen. Der e2e-Test `tests/e2e/trainers-flow.mjs` prüft `aria-valuenow`, `aria-valuemax` und `aria-valuetext` am Lautstärkeknopf.
3. Dann `assets/build/device-sim-a11y.js` und den Block „Screenreader-Werte an den Drehknöpfen“ in `assets/build/app.js` entfernen. Beide würden sonst dieselben Werte noch einmal setzen; das schadet nicht, ist aber doppelt.
