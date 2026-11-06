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
// Durch Seitenwechsel abgebrochene Anfragen (net::ERR_ABORTED) sind kein Fehler.
page.on('requestfailed', (r) => { if (r.failure()?.errorText === 'net::ERR_ABORTED') { return; } if (!r.url().startsWith(base)) { external.add(new URL(r.url()).host); } else { errors.push(`${r.url()} ${r.failure()?.errorText}`); } });
// Fehler externer Ressourcen (z. B. Emoji-CDN des Themes) betreffen das Plugin nicht.
page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text()); });
const shot = async (name) => { if (shots) { await page.screenshot({ path: `${shots}/${name}.png`, fullPage: true }); } };
const step = (s) => console.log('·', s);

step('Anmeldehinweis ohne Login');
await page.goto(`${base}/bootsscheine/`);
await page.getByRole('heading', { name: 'Bootsscheine' }).waitFor();
assert.ok(await page.getByRole('link', { name: 'Anmelden' }).isVisible());

step('Anmelden');
await page.getByRole('link', { name: 'Anmelden' }).click();
await page.fill('#user_login', process.env.BP_USER);
await page.fill('#user_pass', process.env.BP_PASS);
await page.click('#wp-submit');
await page.waitForURL(/\/bootsscheine\/$/);
await page.getByRole('heading', { name: 'Bootsscheine', level: 1 }).waitFor();
const cards = await page.locator('.bp-course-card').count();
assert.equal(cards, 14, 'alle 14 Kurse sichtbar');
await shot('01-uebersicht');

step('Kurs SBF See öffnen');
await page.getByRole('link', { name: 'SBF See', exact: true }).click();
await page.getByRole('heading', { name: 'SBF See', level: 1 }).waitFor();
assert.match(await page.locator('#bp-exam-h').locator('..').innerText(), /Basisfragen 5\/7 · See 18\/23 · 60 Minuten/);
assert.equal(await page.locator('.bp-paper').count(), 15);
await shot('02-kurs');

step('Smarttrainer: falsche Antwort, Erklärung, nächste Frage');
await page.getByRole('link', { name: 'Smarttrainer' }).click();
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
await page.getByRole('button', { name: 'Merken' }).click();
await page.getByRole('button', { name: 'Von Merkliste entfernen' }).waitFor();
await page.getByRole('button', { name: 'Nächste Frage' }).click();
await page.waitForFunction((t) => document.querySelector('.bp-question-text')?.innerText !== t, firstText);

step('Zurück zur Kursseite, Merkliste enthält die Frage');
await page.getByRole('navigation', { name: 'Brotkrümelnavigation' }).getByRole('link', { name: 'SBF See' }).click();
const favLink = page.getByRole('link', { name: /^Merkliste \(\d+\)$/ });
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
await page.goto(`${base}/bootsscheine/sbf-see/`);
await page.getByRole('button', { name: /Prüfung (starten|fortsetzen)/ }).click();
await page.waitForURL(/\/pruefung\/\d+\/$/);
await page.locator('.bp-timer').waitFor();
assert.equal(await page.locator('.bp-exam-nav-item').count(), 30);
assert.equal(await page.locator('.bp-feedback, .bp-answer-correct').count(), 0, 'keine Lösung während der Prüfung');
for (let i = 0; i < 3; i++) {
  await page.locator('.bp-answer label').first().click();
  await page.locator('.bp-save-status', { hasText: 'Gespeichert' }).waitFor();
  await page.getByRole('button', { name: 'Weiter', exact: true }).click();
}
assert.equal(await page.locator('.bp-exam-nav-item.is-answered').count(), 3);
await shot('04-pruefung');
const examUrl = page.url();
await page.reload();
await page.locator('.bp-exam-nav-item.is-answered').nth(2).waitFor();
assert.equal(await page.locator('.bp-exam-nav-item.is-answered').count(), 3, 'Antworten nach Neuladen erhalten');

step('Abgeben');
page.once('dialog', (d) => d.accept());
await page.getByRole('button', { name: 'Prüfung abgeben' }).click();
await page.locator('.bp-verdict').waitFor();
assert.match(await page.locator('.bp-verdict-title').innerText(), /Nicht bestanden/);
assert.equal(await page.locator('.bp-review-item').count(), 30);
await page.getByLabel('Nur falsch beantwortete anzeigen').check();
await shot('05-ergebnis');
await page.goto(examUrl);
await page.locator('.bp-verdict').waitFor();

step('Fester Bogen 1 starten und Kursseite zeigt Status');
await page.goto(`${base}/bootsscheine/sbf-see/`);
await page.getByRole('button', { name: /^Bogen 1:/ }).click();
await page.waitForURL(/\/pruefung\/\d+\/$/);
await page.getByRole('heading', { name: 'Prüfungsbogen 1' }).waitFor();
await page.goto(`${base}/bootsscheine/sbf-see/`);
await page.getByRole('button', { name: 'Bogen 1: läuft' }).waitFor();

step('Übungsmodus bei unverifiziertem Regelwerk');
await page.goto(`${base}/bootsscheine/sbf-see-bin/`);
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
