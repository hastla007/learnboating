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
try {
  await page.waitForURL(/\/bootsscheine\/$/, { waitUntil: 'commit', timeout: 20000 });
} catch (e) {
  // Diagnose: In CI blieb die Weiterleitung nach dem Login vereinzelt aus.
  const body = (await page.locator('body').innerText().catch(() => '')).slice(0, 300).replace(/\s+/g, ' ');
  console.log('  (Login-Weiterleitung ausgeblieben, URL:', page.url(), '| Seite:', body, ')');
  await page.goto(`${base}/bootsscheine/`);
}
await page.locator('.bp-dash-hero h1').waitFor({ timeout: 60000 });

step('Kursseite zeigt Trainer');
await page.goto(`${base}/bootsscheine/sbf-see/`);
await page.getByRole('heading', { name: 'Lernbereiche' }).waitFor();
for (const name of ['Navigationsaufgaben', 'Praxistrainer', 'Knoten']) {
  assert.ok(await page.locator('#bp-app').getByRole('link', { name, exact: true }).isVisible(), name);
}
assert.ok(await page.locator('.bp-hub-card-video').first().isVisible(), 'Videokurs als große Karte oben');

step('Navigationsaufgabe lösen');
await page.locator('#bp-app').getByRole('link', { name: 'Navigationsaufgaben', exact: true }).click();
await page.getByRole('heading', { name: 'Navigationsaufgaben', level: 1 }).waitFor();
assert.equal(await page.locator('.bp-module-list > li').count(), 15);
await page.locator('#bp-app').getByRole('link', { name: 'Navigationsaufgabe 1', exact: true }).click();
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
await page.locator('#bp-app').getByRole('link', { name: /^Pflichtmanöver \(/ }).click();
await page.getByRole('heading', { name: 'Pflichtmanöver', level: 1 }).waitFor();

step('Videokurs mit Quiz');
// Kapitelmenü links (Kapitel ggf. aufklappen), Player rechts
const openLesson = async (name) => {
  const item = page.locator('#bp-app a.bp-vc-lesson', { hasText: name }).first();
  await item.evaluate((n) => { n.closest('details').open = true; });
  await item.click();
  await page.locator('a.bp-vc-lesson.is-active', { hasText: name }).waitFor();
};
await page.goto(`${base}/bootsscheine/sbf-see/video/`);
await page.locator('.bp-vc-side').getByText('Videokurs', { exact: true }).waitFor();
assert.ok(await page.locator('a.bp-vc-lesson.is-active').count() === 1, 'Videokurs öffnet direkt eine Lektion');
await openLesson('Motor, Bootsführer und Fahrer');
await page.locator('video.bp-video').waitFor();
await page.getByText('Platzhaltervideo').waitFor();
assert.equal(await page.locator('.bp-section .bp-question').count(), 4, '4 Quizfragen');
const quiz = page.locator('.bp-section .bp-question').first();
await quiz.locator('.bp-answer label').first().click();
await quiz.getByRole('button', { name: 'Antwort prüfen' }).click();
await quiz.locator('.bp-feedback-title').waitFor();
await page.getByRole('button', { name: 'Als abgeschlossen markieren' }).click();
await page.getByRole('button', { name: 'abgeschlossen', exact: true }).waitFor();
await page.locator('.bp-vc-status', { hasText: 'Abgeschlossen' }).waitFor();
assert.match(await page.locator('.bp-vc-count').innerText(), /^[1-9]\d* \/ \d+ Lektionen$/);
await shot('22-video');
await page.goto(`${base}/bootsscheine/sbf-see/video/`);
await page.locator('.bp-lesson-done').first().waitFor({ state: 'attached' });

step('Knoten mit Schrittgalerie');
await openLesson('Palstek');
await page.getByRole('heading', { name: 'Schritt für Schritt' }).waitFor();
assert.equal(await page.locator('.bp-steps li').count(), 5);

step('Funk: SRC-Seefunktext auswerten');
await page.goto(`${base}/bootsscheine/src/`);
await page.locator('#bp-app').getByRole('link', { name: 'Funktraining', exact: true }).click();
await page.getByRole('heading', { name: 'SRC-Seefunktexte' }).waitFor();
assert.equal(await page.locator('.bp-section', { hasText: 'SRC-Seefunktexte' }).locator('.bp-paper').count(), 27);
await page.locator('.bp-section', { hasText: 'SRC-Seefunktexte' }).getByRole('link', { name: /^Text 1:/ }).click();
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
await page.locator('#bp-app').getByRole('link', { name: 'Üben' }).first().click();
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

step('Funkpraxis SRC-01 auswerten');
await page.goto(`${base}/bootsscheine/src/funk/`);
await page.getByRole('heading', { name: 'SRC-Funkpraxis' }).waitFor();
assert.equal(await page.locator('.bp-section', { hasText: 'SRC-Funkpraxis' }).locator('.bp-paper').count(), 42);
await page.goto(`${base}/bootsscheine/src/funk/praxis/SRC-01/`);
await page.getByRole('heading', { name: 'Lage' }).waitFor();
await page.getByLabel('Rang').fill('Notverkehr');
await page.getByLabel('Meldung (Sprechfunk)').fill('MAYDAY MAYDAY MAYDAY this is');
await page.getByRole('button', { name: 'Auswerten' }).click();
await page.getByText(/von \d+ Prüfpunkten erfüllt/).waitFor();
assert.ok(await page.locator('.bp-checklist .is-ok').count() > 0);
assert.ok(await page.locator('.bp-checklist .is-missing').count() > 0);
assert.ok(await page.locator('.bp-practice-result .bp-model').count() >= 4, 'Musterlösung je Feld');
await shot('25-funkpraxis');
await page.getByRole('button', { name: 'Lage auf Englisch' }).click();
await page.locator('p[lang="en"]').waitFor();

step('Gerätesimulation (Original-JS der Quelle)');
step('Muster-Funksprüche und Buchstabiertafel (SRC, Kombi)');
await page.goto(`${base}/bootsscheine/src/funk/muster/`);
await page.getByRole('heading', { name: 'Muster-Funksprüche', level: 1 }).waitFor();
assert.ok(await page.locator('.bp-sample').count() >= 4, 'je Meldungsart ein Beispiel');
assert.match(await page.locator('.bp-sample[open] .bp-sample-msg').innerText(), /MAYDAY/);
await page.goto(`${base}/bootsscheine/src-ubi/funk/buchstabiertafel/`);
await page.getByRole('heading', { name: 'Internationale Buchstabiertafel', level: 1 }).waitFor();
assert.equal(await page.locator('.bp-spell-grid').first().locator('li').count(), 26);
await page.getByRole('heading', { name: 'Ziffern (UBI)' }).waitFor();
await page.locator('.bp-spell-search').fill('DB5');
assert.equal(await page.locator('.bp-spell-out').innerText(), 'DELTA BRAVO FIVE');

await page.goto(`${base}/bootsscheine/src/funk/geraet/`);
await page.locator('#bp-device-sim svg').first().waitFor();
await page.getByRole('combobox', { name: 'Gerät' }).waitFor();
assert.ok((await page.locator('#bp-device-sim').innerText()).includes('Icom IC-M505'));
await page.locator('#bp-device-sim').getByRole('button', { name: 'Mit Hilfe' }).first().click();
await page.locator('#bp-device-sim').getByText('Schalte das Funkgerät ein', { exact: false }).first().waitFor();
await shot('26-geraet');
await page.reload();
await page.locator('#bp-device-sim svg').first().waitFor();
await page.locator('#bp-device-sim').getByRole('tab', { name: 'Prüfung', exact: true }).click();
await page.locator('#bp-device-sim').getByText('SRC Reihe a').first().waitFor();

assert.deepEqual(errors, [], 'keine JavaScript- oder Ladefehler');
await browser.close();
console.log('Trainer-E2E bestanden.');
