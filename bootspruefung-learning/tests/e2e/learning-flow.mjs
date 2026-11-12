// End-to-End-Durchlauf des Lernbereichs mit Playwright (ohne Test-Runner).
//   BP_URL=http://127.0.0.1:8099 BP_USER=lerner1 BP_PASS=… [BP_SHOTS=dir] node tests/e2e/learning-flow.mjs
import { createRequire } from 'node:module';
// CommonJS-Auflösung, damit auch ein global installiertes Playwright (NODE_PATH) gefunden wird.
const { chromium } = createRequire(import.meta.url)('playwright');
import assert from 'node:assert/strict';

const base = process.env.BP_URL ?? 'http://127.0.0.1:8099';
const shots = process.env.BP_SHOTS;
const scale = Number(process.env.BP_SCALE ?? 1);
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1200, height: 900 }, deviceScaleFactor: scale });
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
const external = new Set();
let offlineStep = false; // gewollt ohne Netz (Prüfung offline): fehlgeschlagene Anfragen sind dort kein Fehler
// Durch Seitenwechsel abgebrochene Anfragen (net::ERR_ABORTED) sind kein Fehler.
page.on('requestfailed', (r) => { if (r.failure()?.errorText === 'net::ERR_ABORTED' || offlineStep) { return; } if (!r.url().startsWith(base)) { external.add(new URL(r.url()).host); } else { errors.push(`${r.url()} ${r.failure()?.errorText}`); } });
// Fehler externer Ressourcen (z. B. Emoji-CDN des Themes) betreffen das Plugin nicht.
page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text()); });
const shot = async (name) => { if (shots) { await page.screenshot({ path: `${shots}/${name}.png`, fullPage: true }); } };
const step = (s) => console.log('·', s);

step('Anmeldehinweis ohne Login');
await page.goto(`${base}/bootsscheine/`);
await page.getByRole('heading', { name: 'XXXX' }).waitFor();
assert.ok(await page.locator('#bp-app').getByRole('link', { name: 'Anmelden' }).isVisible());

step('Anmelden');
await page.locator('#bp-app').getByRole('link', { name: 'Anmelden' }).click();
await page.fill('#user_login', process.env.BP_USER);
await page.fill('#user_pass', process.env.BP_PASS);
await page.click('#wp-submit');
await page.waitForURL(/\/bootsscheine\/$/, { waitUntil: 'commit', timeout: 90000 });
await page.locator('.bp-dash-hero h1').waitFor();
const cards = await page.locator('.bp-course-card').count();
assert.equal(cards, 14, 'alle 14 Kurse sichtbar');
await shot('01-uebersicht');

step('Kurs SBF See öffnen');
await page.locator('#bp-app').getByRole('link', { name: 'SBF See', exact: true }).click();
await page.getByRole('heading', { name: 'SBF See', level: 1 }).waitFor();
await page.locator('.bp-areas').waitFor();
await shot('02-kurs');
await page.locator('#bp-app').getByRole('link', { name: 'Prüfung simulieren', exact: true }).click();
await page.locator('#bp-exam-h').waitFor();
assert.match(await page.locator('#bp-exam-h').locator('..').innerText(), /Basisfragen 5\/7 · See 18\/23 · 60 Minuten/);
assert.equal(await page.locator('.bp-paper').count(), 15);
await page.getByRole('navigation', { name: 'Brotkrümelnavigation' }).getByRole('link', { name: 'SBF See' }).click();
await page.locator('.bp-areas').waitFor();

step('Smarttrainer: falsche Antwort, Erklärung, nächste Frage');
await page.locator('#bp-app').getByRole('link', { name: 'Smarttrainer' }).click();
await page.getByRole('heading', { name: 'Smarttrainer', level: 1 }).waitFor();
await page.locator('.bp-topic-groups').waitFor();
await page.locator('#bp-app').getByRole('link', { name: 'Weiterlernen mit dem Smarttrainer' }).click();
await page.locator('.bp-question').waitFor();
const firstText = await page.locator('.bp-question-text').innerText();
assert.ok(firstText.length > 5);
await page.locator('.bp-answer label').first().click();
await page.getByRole('button', { name: 'Antwort prüfen' }).click();
await page.locator('.bp-feedback-title').waitFor();
const verdict = await page.locator('.bp-feedback-title').innerText();
assert.ok(['Richtig!', 'Leider falsch.'].includes(verdict));
assert.equal(await page.locator('.bp-answer-correct').count(), 1, 'richtige Antwort markiert');
await shot('03-lernmodus-feedback');
// Umschalter mit fester Beschriftung, Zustand über aria-pressed.
await page.getByRole('button', { name: 'Merken', pressed: false }).click();
await page.getByRole('button', { name: 'Merken', pressed: true }).waitFor();
await page.getByRole('button', { name: 'Nächste Frage' }).click();
await page.waitForFunction((t) => document.querySelector('.bp-question-text')?.innerText !== t, firstText);

step('Smarttrainer mobil (390 px): Rückmeldung über der fixierten Knopfleiste (UX-07)');
await page.setViewportSize({ width: 390, height: 844 });
await page.emulateMedia({ reducedMotion: 'reduce' });
await page.locator('.bp-answer label').first().click();
await page.getByRole('button', { name: 'Antwort prüfen' }).click();
await page.locator('.bp-feedback-title').waitFor();
const layout = await page.evaluate(() => {
  const f = document.querySelector('.bp-feedback').getBoundingClientRect();
  const bar = document.querySelector('.bp-question > .bp-actions').getBoundingClientRect();
  return { top: f.top, bottom: f.bottom, bar: bar.top, focus: document.activeElement?.textContent ?? '' };
});
assert.ok(layout.top >= 0 && layout.top < layout.bar - 40, `Rückmeldung beginnt sichtbar über der Leiste (${JSON.stringify(layout)})`);
assert.ok(layout.bottom <= layout.bar + 1 || layout.top <= 120, 'Rückmeldung endet über der Leiste oder beginnt oben im Bild');
assert.match(layout.focus, /Nächste Frage/, 'Fokus auf „Nächste Frage“');
await page.setViewportSize({ width: 1200, height: 900 });
await page.emulateMedia({ reducedMotion: null });
await page.getByRole('button', { name: 'Nächste Frage' }).click();
await page.locator('.bp-question').waitFor();

step('Zurück zur Kursseite, Favoriten enthalten die Frage');
await page.getByRole('navigation', { name: 'Brotkrümelnavigation' }).getByRole('link', { name: 'SBF See' }).click();
const favLink = page.locator('#bp-app').getByRole('link', { name: /^Favoriten \(\d+\)$/ });
await favLink.waitFor();
assert.ok(Number((await favLink.innerText()).match(/\d+/)[0]) >= 1);

step('Offene Frage im FKN-Kurs');
await page.goto(`${base}/bootsscheine/fkn/lernen/`);
await page.locator('.bp-question').waitFor();
await page.getByRole('button', { name: 'Musterantwort anzeigen' }).click();
await page.locator('.bp-model').waitFor();
await page.getByRole('button', { name: 'Gewusst', exact: true }).click();
await page.locator('.bp-feedback-title', { hasText: 'Richtig!' }).waitFor();

step('Prüfungssimulation SBF See');
await page.goto(`${base}/bootsscheine/sbf-see/pruefung/`);
await page.getByRole('button', { name: /Prüfung (starten|fortsetzen)/ }).click();
await page.waitForURL(/\/pruefung\/\d+\/$/);
await page.locator('.bp-timer').waitFor();
assert.equal(await page.locator('.bp-exam-nav-item').count(), 30);
assert.equal(await page.locator('.bp-feedback, .bp-answer-correct').count(), 0, 'keine Lösung während der Prüfung');
for (let i = 0; i < 3; i++) {
  await page.locator('.bp-answer label').first().click();
  await page.locator('.bp-save-status', { hasText: /^Gespeichert$/ }).waitFor();
  await page.getByRole('button', { name: 'Weiter', exact: true }).click();
}
assert.equal(await page.locator('.bp-exam-nav-item.is-answered').count(), 3);

step('Frage markieren');
const flag = page.getByRole('button', { name: 'Markieren', exact: true });
assert.equal(await flag.getAttribute('aria-pressed'), 'false');
await flag.click();
await page.locator('.bp-save-status', { hasText: 'Markierung gespeichert' }).waitFor();
assert.equal(await flag.getAttribute('aria-pressed'), 'true');
assert.equal(await page.locator('.bp-exam-nav-item.is-flagged').count(), 1);
assert.equal(await page.locator('.bp-exam-nav-item.is-flagged').getAttribute('aria-label'), 'Frage 4, nicht beantwortet, markiert');
await page.locator('.bp-exam-flag-count', { hasText: '1 Frage markiert' }).waitFor();
await page.getByLabel('Nur markierte Fragen zeigen').check();
assert.equal(await page.locator('.bp-exam-nav li:not([hidden])').count(), 1, 'Filter: nur die markierte (aktuelle) Frage');
await page.getByLabel('Nur markierte Fragen zeigen').uncheck();
await shot('04-pruefung');
const examUrl = page.url();
await page.reload();
await page.locator('.bp-exam-nav-item.is-answered').nth(2).waitFor();
assert.equal(await page.locator('.bp-exam-nav-item.is-answered').count(), 3, 'Antworten nach Neuladen erhalten');
assert.equal(await page.locator('.bp-exam-nav-item.is-flagged').count(), 1, 'Markierung nach Neuladen erhalten');

step('Prüfung ohne Netz: Antwort puffern, Hinweis, nachsenden (QA-02)');
offlineStep = true;
await page.context().setOffline(true);
await page.locator('.bp-exam-nav-item').nth(4).click();
await page.locator('.bp-answer label').first().click();
await page.locator('.bp-save-status', { hasText: 'Offline – 1 Antwort wird gespeichert und nachgesendet.' }).waitFor();
assert.equal(await page.locator('.bp-exam-nav-item.is-answered').count(), 4);
assert.equal(await page.evaluate(() => Object.keys(localStorage).filter((k) => /^bp-exam-\d+-5$/.test(k)).length), 1, 'Antwort im Browser zwischengespeichert');
await page.context().setOffline(false);
await page.locator('.bp-save-status', { hasText: /^Gespeichert$/ }).waitFor({ timeout: 15000 });
assert.equal(await page.evaluate(() => Object.keys(localStorage).filter((k) => /^bp-exam-/.test(k)).length), 0, 'Zwischenspeicher nach Bestätigung leer');
offlineStep = false;
await page.reload();
await page.locator('.bp-exam-nav-item.is-answered').nth(3).waitFor();
assert.equal(await page.locator('.bp-exam-nav-item.is-answered').count(), 4, 'nachgesendete Antwort ist auf dem Server');

step('Abgeben');
let confirmText = '';
page.once('dialog', (d) => { confirmText = d.message(); d.accept(); });
await page.getByRole('button', { name: 'Prüfung abgeben' }).click();
await page.locator('.bp-verdict').waitFor();
assert.match(confirmText, /Du hast 1 Frage markiert\./, 'Abgabe-Dialog nennt die Markierungen');
assert.equal(await page.locator('.bp-review-item.is-flagged').count(), 1, 'Ergebnis zeigt die markierte Frage');
await page.locator('.bp-review-item.is-flagged .bp-badge', { hasText: 'markiert' }).waitFor();
await page.locator('.bp-result-flagged', { hasText: 'Du hattest 1 Frage markiert.' }).waitFor();
assert.match(await page.locator('.bp-verdict-title').innerText(), /Nicht bestanden/);
assert.equal(await page.locator('.bp-review-item').count(), 30);
await page.getByLabel('Nur falsch beantwortete anzeigen').check();
await shot('05-ergebnis');
await page.goto(examUrl);
await page.locator('.bp-verdict').waitFor();

step('Fester Bogen 1 starten und Kursseite zeigt Status');
await page.goto(`${base}/bootsscheine/sbf-see/pruefung/`);
await page.getByRole('button', { name: /^Bogen 1:/ }).click();
await page.waitForURL(/\/pruefung\/\d+\/$/);
await page.getByRole('heading', { name: 'Prüfungsbogen 1' }).waitFor();
await page.goto(`${base}/bootsscheine/sbf-see/pruefung/`);
await page.getByRole('button', { name: 'Bogen 1: läuft' }).waitFor();

step('Übungsmodus bei unverifiziertem Regelwerk');
await page.goto(`${base}/bootsscheine/sbf-see-bin/pruefung/`);
await page.getByText('Übungsmodus', { exact: true }).waitFor();

step('Tipps & Tricks');
await page.goto(`${base}/bootsscheine/tipps/`);
await page.getByText('Mit dem Smarttrainer starten').click();
await page.getByText('dreimal hintereinander richtig').waitFor();

step('Mobile Ansicht');
await page.setViewportSize({ width: 390, height: 844 });
await page.goto(`${base}/bootsscheine/sbf-see/lernen/`);
await page.locator('.bp-question').waitFor();
const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
assert.equal(overflow, false, 'kein horizontales Scrollen auf dem Handy');
await shot('06-mobil');

assert.deepEqual(errors, [], 'keine JavaScript- oder Ladefehler');
if (external.size) { console.log('  (externe Hosts nicht erreichbar:', [...external].join(', ') + ')'); }
await browser.close();
console.log('E2E bestanden.');
