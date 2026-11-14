// Lektionssteuerung der Gerätesimulation: vergleicht die Ereignisse der
// Gerätelogik mit den Zielen der Lektionsschritte (database/data/
// geraetesimulation.php). Ein Ziel nennt Ereignistyp und Felder; Felder mit
// Punkt prüfen verschachtelte Werte ("call.type"), {min, max} prüfen
// Bereiche, Arrays werden elementweise verglichen. Gesprochenes ("spoken")
// prüft der Server; die Lektion wartet dann auf dessen Ergebnis.

// Stufe 1 mit gelbem Rahmen und Schritt-Hilfe, Stufe 2 nur mit Hinweistext,
// Stufe 3 allein. „Selbst geschafft“ zählt nur Stufe 3.
export const LEVELS = [1, 2, 3];

// Prüfungsmodus: ohne Hilfe, zwei Versuche je Funkspruch.
export const EXAM_ATTEMPTS = 2;

// Ungehörter Sprechfunk einer Gegenstation wird nach dieser Zeit wiederholt.
export const VOICE_REPEAT_MS = 8000;

function valueAt(object, path) {
    return path.split('.').reduce((value, key) => (value == null ? undefined : value[key]), object);
}

function same(expected, actual) {
    if (Array.isArray(expected)) {
        return Array.isArray(actual) && expected.length === actual.length && expected.every((v, i) => same(v, actual[i]));
    }
    if (expected && typeof expected === 'object') {
        if ('min' in expected || 'max' in expected) {
            return typeof actual === 'number' && (expected.min === undefined || actual >= expected.min) && (expected.max === undefined || actual <= expected.max);
        }

        return Object.entries(expected).every(([key, value]) => same(value, actual?.[key]));
    }

    return expected === actual;
}

// Wohin ein Funkspruch gehört: Kanal, ggf. Kanalgruppe und Sendeleistung.
export function missLabel({ channel, group = null, highPower = null }) {
    return `Kanal ${channel}${group === 'ATIS' ? ' in der Kanalgruppe ATIS' : ''}${highPower === true ? ' mit 25 W' : highPower === false ? ' mit 1 W' : ''}`;
}

export function matchesGoal(goal, event) {
    return Object.entries(goal).every(([key, expected]) => same(expected, valueAt(event, key)));
}

// say(voice) lässt eine Gegenstation sprechen und gibt {heard} zurück
// (siehe Gerätelogik hear()); exam schaltet den Prüfungsmodus ein.
export function createLessonRun(lesson, { level = 1, exam = false, schedule = (fn, ms) => setTimeout(fn, ms), receive = () => {}, say = () => ({ heard: true }) } = {}) {
    let index = 0;
    let done = false;
    let failed = false;
    let pendingSpeech = null;
    let attempts = 0;
    const results = [];
    const listeners = new Set();
    const effectiveLevel = exam ? 3 : level;

    const notify = () => listeners.forEach((fn) => fn(run.status()));

    function speak(stepIndex, voice) {
        if (done || index !== stepIndex) return;
        const { heard } = say(voice) ?? { heard: true };
        if (! heard && voice.repeat !== false) schedule(() => speak(stepIndex, voice), VOICE_REPEAT_MS);
    }

    function enterStep() {
        const step = lesson.steps[index];
        const stepIndex = index;
        attempts = 0;
        if (step?.receive) {
            // receive() meldet false, wenn das Gerät den Anruf nicht annehmen
            // konnte (z. B. ausgeschaltet): dann später noch einmal.
            const deliver = () => {
                if (done || index !== stepIndex) return;
                if (receive(step.receive.call) === false) schedule(deliver, VOICE_REPEAT_MS);
            };
            schedule(deliver, step.receive.delay_ms ?? 0);
        }
        for (const voice of [step?.voice ?? []].flat()) {
            schedule(() => speak(stepIndex, voice), voice.delay_ms ?? 0);
        }
    }

    function advance() {
        index++;
        if (index >= lesson.steps.length) {
            done = true;
        } else {
            enterStep();
        }
        notify();
    }

    const run = {
        lesson,
        level,
        get step() {
            return done ? null : lesson.steps[index];
        },
        status() {
            const step = done ? null : lesson.steps[index];

            return {
                index,
                total: lesson.steps.length,
                done,
                failed,
                exam,
                text: step?.text ?? null,
                // Stufe 1: Hinweis und Rahmen; Stufe 2: nur Hinweis; Stufe 3: nichts.
                help: step && effectiveLevel <= 2 ? step.help : null,
                target: step && effectiveLevel === 1 ? step.target : null,
                awaitingSpeech: Boolean(pendingSpeech),
                attemptsLeft: exam ? EXAM_ATTEMPTS - attempts : null,
                results,
            };
        },
        onChange(fn) {
            listeners.add(fn);

            return () => listeners.delete(fn);
        },
        // Ereignis der Gerätelogik. Gibt den Schritt zurück, wenn ein
        // gesprochener Funkspruch vom Server bewertet werden muss.
        handle(event) {
            if (done) return null;
            const step = lesson.steps[index];
            // Solange ein Funkspruch beim Server liegt, zählt kein weiterer.
            if (pendingSpeech && event.type === 'spoken') return null;

            if (! matchesGoal(step.goal, event)) {
                // Funkspruch auf dem falschen Kanal bzw. mit falscher
                // Sendeleistung: im Prüfungsmodus ein Fehlversuch.
                if (step.speech && event.type === 'spoken') {
                    const miss = { miss: true, stepIndex: index, expected: { channel: step.goal.channel ?? null, highPower: step.goal.highPower ?? null, group: step.goal.group ?? null } };
                    if (exam) {
                        attempts++;
                        const label = missLabel(miss.expected);
                        const record = { stepIndex: index, passed: false, checks: [{ label, ok: false }], text: event.text, attempt: attempts };
                        const last = results.findIndex((r) => r.stepIndex === index);
                        if (last >= 0) results[last] = record;
                        else results.push(record);
                        if (attempts >= EXAM_ATTEMPTS) {
                            failed = true;
                            done = true;
                        }
                        notify();
                    }

                    return miss;
                }

                return null;
            }

            if (step.speech) {
                pendingSpeech = { index, text: event.text };

                return { stepIndex: index, text: event.text };
            }

            advance();

            return null;
        },
        // Ergebnis der Serverbewertung eines Funkspruchs.
        speechResult(stepIndex, passed, checks = []) {
            if (! pendingSpeech || pendingSpeech.index !== stepIndex) return;
            const text = pendingSpeech.text;
            pendingSpeech = null;
            attempts++;
            const record = { stepIndex, passed, checks, text, attempt: attempts };
            const last = results.findIndex((r) => r.stepIndex === stepIndex);
            if (last >= 0) results[last] = record;
            else results.push(record);
            if (passed) {
                advance();
            } else if (exam && attempts >= EXAM_ATTEMPTS) {
                failed = true;
                done = true;
                notify();
            } else {
                notify();
            }
        },
        // Die Bewertung ist fehlgeschlagen (Server, Netz): kein Fehlversuch,
        // der Funkspruch kann noch einmal gesendet werden.
        speechError(stepIndex) {
            if (! pendingSpeech || pendingSpeech.index !== stepIndex) return;
            pendingSpeech = null;
            notify();
        },
        // Prüfungsmodus: Aufgabe ohne Lösung beenden.
        giveUp() {
            if (done) return;
            failed = true;
            done = true;
            pendingSpeech = null;
            notify();
        },
        start() {
            enterStep();
            notify();
        },
    };

    return run;
}
