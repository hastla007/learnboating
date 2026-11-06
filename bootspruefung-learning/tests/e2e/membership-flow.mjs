// Membership-Funktionen im Browser: kostenloser Teilumfang mit Sperrhinweisen,
// Gruppenbeitritt per Link und Gruppenverwaltung durch die Bootsschule.
//   BP_URL=… BP_TOKEN=<aus membership-setup.php> node tests/e2e/membership-flow.mjs
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';

const { chromium } = createRequire(import.meta.url)('playwright');
const base = process.env.BP_URL ?? 'http://127.0.0.1:8099';
const token = process.env.BP_TOKEN;
const shots = process.env.BP_SHOTS;
const browser = await chromium.launch();
const errors = [];
const step = (s) => console.log('·', s);

async function session(user, pass) {
  const context = await browser.newContext({ viewport: { width: 1200, height: 900 } });
  const page = await context.newPage();
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('requestfailed', (r) => { if (r.failure()?.errorText === 'net::ERR_ABORTED') { return; } if (r.url().startsWith(base)) { errors.push(`${r.url()} ${r.failure()?.errorText}`); } });
  page.on('dialog', (d) => d.accept('E2E-Test'));
  await page.goto(`${base}/wp-login.php?redirect_to=${encodeURIComponent(base + '/bootsscheine/')}`);
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await page.click('#wp-submit');
  await page.waitForURL(/\/bootsscheine\/$/);

  return { context, page };
}
const shot = async (page, name) => { if (shots) { await page.screenshot({ path: `${shots}/${name}.png`, fullPage: true }); } };

const learner = await session('lerner1', 'Test-Passwort-123');
let page = learner.page;

step('Kostenloser Teilumfang: Hinweis und gesperrte Bögen');
await page.goto(`${base}/bootsscheine/sbf-see/`);
await page.getByText('Kostenloser Umfang: die ersten 3 Fragen je Modul.').waitFor();
await page.getByText(/Kostenloser Umfang: die ersten 2 Prüfungsbögen/).waitFor();
assert.equal(await page.getByRole('button', { name: /Prüfung starten|Simulation starten/ }).count(), 0, 'keine Zufallsprüfung');
assert.ok(await page.locator('.bp-paper-locked').count() > 0, 'gesperrte Bögen');
await shot(page, '40-teilumfang');

step('Lernen im Teilumfang');
await page.goto(`${base}/bootsscheine/sbf-see/lernen/`);
await page.locator('.bp-question').waitFor();

step('Gruppenbeitritt per Link');
await page.goto(`${base}/bootsscheine/gruppe/${token}/`);
await page.getByRole('heading', { name: /Einladung:/ }).waitFor();
await page.getByText('Verwaltet von Bootsschule Test').waitFor();
await shot(page, '41-gruppe-beitreten');
await page.getByRole('button', { name: 'Gruppe beitreten' }).click();
await page.waitForURL(/\/bootsscheine\/$/);
await page.goto(`${base}/bootsscheine/sbf-bin-motor/`);
await page.locator('#bp-app').getByRole('link', { name: 'Smarttrainer starten' }).or(page.locator('.bp-module-list a.bp-button').first()).first().waitFor();
assert.equal(await page.locator('.bp-locked').count(), 0, 'Gruppenplatz schaltet den Kurs voll frei');

step('Gruppenverwaltung der Bootsschule');
const school = await session('schule1', 'Schul-Passwort-123');
page = school.page;
await page.locator('#bp-app').getByRole('link', { name: 'Meine Gruppen' }).click();
await page.getByRole('heading', { name: 'Meine Gruppen', level: 1 }).waitFor();
await page.getByText('1 von 5 Plätzen belegt').waitFor();
await page.getByText(/Lea Lernt/).waitFor();
await page.getByRole('textbox', { name: 'Per E-Mail einladen' }).fill('neu1@example.invalid, neu2@example.invalid');
await page.getByRole('button', { name: 'Einladungen senden' }).click();
await page.getByText('3 von 5 Plätzen belegt').waitFor();
await shot(page, '42-gruppen-verwalten');
await page.locator('.bp-members li', { hasText: 'Lea Lernt' }).getByRole('button', { name: 'Platz freigeben' }).click();
await page.getByText('2 von 5 Plätzen belegt').waitFor();

step('Platz entzogen: Kurs wieder gesperrt');
page = learner.page;
await page.goto(`${base}/bootsscheine/sbf-bin-motor/`);
await page.locator('.bp-locked').first().waitFor();

await browser.close();
assert.deepEqual(errors, [], errors.join('\n'));
console.log('Membership-E2E bestanden.');
