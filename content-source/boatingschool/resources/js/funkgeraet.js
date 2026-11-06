// Einstieg der Gerätesimulation Funk (eigener Vite-Einstieg, nur auf der
// Seite der Gerätesimulation geladen). Die Konfiguration steht als JSON im
// Element [data-geraetesimulation-config].
import { mount } from './funkgeraet/app.js';

function start() {
    const root = document.querySelector('[data-geraetesimulation]');
    const configNode = document.querySelector('[data-geraetesimulation-config]');
    if (root && configNode) mount(root, JSON.parse(configNode.textContent));
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
else start();
