/*
 * CSS für die Gerätesimulation im Lernbereich. Die Klassen stammen aus dem
 * unverändert übernommenen JavaScript der Quelle (resources/js/funkgeraet).
 * Alle Regeln gelten nur innerhalb von #bp-device-sim, ohne Preflight –
 * das Theme der Website bleibt unberührt.
 */
module.exports = {
    content: ['../../bootspruefung-learning/assets/device-sim/funkgeraet/**/*.js'],
    important: '#bp-device-sim',
    darkMode: 'media',
    corePlugins: { preflight: false },
    theme: { extend: {} },
    plugins: [],
};
