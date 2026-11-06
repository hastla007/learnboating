// Spezialtrainer im Browser: Navigation, Praxis, Video, Funk (Texte, Buchstabieren, Vokabeln).
//   BP_URL=… BP_USER=… BP_PASS=… node tests/e2e/trainers-flow.mjs   (nach tests/e2e/setup.php)
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';

const { chromium } = createRequire(import.meta.url)('playwright');
const base = process.env.BP_URL ?? 'http://127.0.0.1:8099';
const shots = process.env.BP_SHOTS;
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1200, height: 900 }, deviceScaleFactor: Number(process.env.BP_SCALE ?? 1) });
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
// Durch Seitenwechsel abgebrochene Anfragen (net::ERR_ABORTED) sind kein Fehler.
page.on('requestfailed', (r) => { if (r.failure()?.errorText === 'net::ERR_ABORTED') { return; } if (r.url().startsWith(base)) { errors.push(`${r.url()} ${r.failure()?.errorText}`); } });
const shot = async (name) => { if (shots) { await page.screenshot({ path: `${shots}/${name}.png`, fullPage: true }); } };
const step = (s) => console.log('·', s);

await page.goto(`${base}/wp-login.php?redirect_to=${encodeURIComponent(base + '/bootsscheine/')}`);
await page.fill('#user_login', process.env.BP_USER);
await page.fill('#user_pass', process.env.BP_PASS);
await page.click('#wp-submit');
await page.waitForURL(/\/bootsscheine\/$/);

step('Kursseite zeigt Trainer');
await page.goto(`${base}/bootsscheine/sbf-see/`);
await page.getByRole('heading', { name: 'Weitere Trainer' }).waitFor();
for (const name of ['Navigationsaufgaben', 'Praxistrainer', 'Videokurs']) {
  assert.ok(await page.getByRole('link', { name, exact: true }).isVisible(), name);
}

step('Navigationsaufgabe lösen');
await page.getByRole('link', { name: 'Navigationsaufgaben', exact: true }).click();
await page.getByRole('heading', { name: 'Navigationsaufgaben', level: 1 }).waitFor();
assert.equal(await page.locator('.bp-module-list > li').count(), 15);
await page.getByRole('link', { name: 'Navigationsaufgabe 1', exact: true }).click();
await page.getByRole('heading', { name: 'Ausgangslage' }).waitFor();
assert.equal(await page.locator('.bp-nav-question').count(), 9);
assert.equal(await page.locator('.bp-model').count(), 0, 'Ergebnis erst nach Klick');
const first = page.locator('.bp-nav-question').first();
await first.locator('textarea').fill('rwK 079°');
await first.getByRole('button', { name: 'Amtliches Ergebnis anzeigen' }).click();
await first.locator('.bp-model').waitFor();
await first.getByRole('button', { name: 'richtig' }).click();
await first.locator('.bp-badge', { hasText: 'richtig' }).waitFor();
await shot('20-navigation');

step('Praxistrainer');
await page.goto(`${base}/bootsscheine/sbf-see/praxis/`);
await page.locator('.bp-question').waitFor();
await page.getByRole('button', { name: 'Antwort zeigen' }).click();
await page.locator('.bp-model').waitFor();
await shot('21-praxis');
await page.getByRole('button', { name: 'Kann ich' }).click();
await page.getByRole('button', { name: 'Antwort zeigen' }).waitFor();
await page.getByRole('link', { name: /^Pflichtmanöver \(/ }).click();
await page.getByRole('heading', { name: 'Pflichtmanöver', level: 1 }).waitFor();

step('Videokurs mit Quiz');
await page.goto(`${base}/bootsscheine/sbf-see/video/`);
await page.getByRole('heading', { name: 'Videokurs', level: 1 }).waitFor();
await page.getByRole('link', { name: 'Motor, Bootsführer und Fahrer' }).click();
await page.locator('video.bp-video').waitFor();
await page.getByText('Platzhaltervideo').waitFor();
assert.equal(await page.locator('.bp-section .bp-question').count(), 4, '4 Quizfragen');
const quiz = page.locator('.bp-section .bp-question').first();
await quiz.locator('.bp-answer label').first().click();
await quiz.getByRole('button', { name: 'Antwort prüfen' }).click();
await quiz.locator('.bp-feedback-title').waitFor();
await page.getByRole('button', { name: 'Als abgeschlossen markieren' }).click();
await page.getByRole('button', { name: 'abgeschlossen', exact: true }).waitFor();
await shot('22-video');
await page.goto(`${base}/bootsscheine/sbf-see/video/`);
await page.locator('.bp-lesson-done').first().waitFor();

step('Knoten mit Schrittgalerie');
await page.getByRole('link', { name: 'Palstek' }).click();
await page.getByRole('heading', { name: 'Schritt für Schritt' }).waitFor();
assert.equal(await page.locator('.bp-steps li').count(), 5);

step('Funk: SRC-Seefunktext auswerten');
await page.goto(`${base}/bootsscheine/src/`);
await page.getByRole('link', { name: 'Funktraining', exact: true }).click();
await page.getByRole('heading', { name: 'SRC-Seefunktexte' }).waitFor();
assert.equal(await page.locator('.bp-section').first().locator('.bp-paper').count(), 27);
await page.getByRole('link', { name: /^Text 1:/ }).click();
await page.getByLabel('Mitschrift').waitFor();
await page.getByRole('button', { name: 'Text anzeigen' }).click();
const source = await page.locator('.bp-radio-source').innerText();
await page.getByLabel('Mitschrift').fill(source);
await page.getByLabel('Übersetzung').fill('Navigational warning');
await page.getByRole('button', { name: 'Auswerten' }).click();
await page.getByText('Mitschrift: 100 % der Wörter getroffen').waitFor();
await page.getByText(/Übersetzung: \d+ % der Wörter getroffen/).waitFor();
await shot('23-funktext');
await page.getByRole('button', { name: 'Als erledigt markieren' }).click();
await page.getByRole('button', { name: 'erledigt', exact: true }).waitFor();

step('Funk: SRC-Vokabeln');
await page.goto(`${base}/bootsscheine/src/funk/`);
await page.getByRole('link', { name: 'Üben' }).first().click();
await page.locator('.bp-vocab-term').waitFor();
await page.getByRole('button', { name: 'Übersetzung zeigen' }).click();
await page.locator('.bp-vocab-solution').waitFor();
await page.getByRole('button', { name: 'Gewusst', exact: true }).click();
await page.getByRole('button', { name: 'Übersetzung zeigen' }).waitFor();

step('UBI: Abgabe (Buchstabieren)');
await page.goto(`${base}/bootsscheine/ubi/funk/`);
await page.getByRole('heading', { name: 'UBI-Abgabe' }).waitFor();
await page.locator('.bp-section', { hasText: 'UBI-Abgabe' }).getByRole('link', { name: /^Text 1:/ }).click();
await page.getByRole('button', { name: 'Buchstabierung anzeigen' }).click();
await page.getByText('KORMORAN: Kilo Oscar Romeo Mike Oscar Romeo Alfa November').waitFor();
await shot('24-ubi-abgabe');

assert.deepEqual(errors, [], 'keine JavaScript- oder Ladefehler');
await browser.close();
console.log('Trainer-E2E bestanden.');
