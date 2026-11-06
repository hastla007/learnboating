// Praxis-Anmeldung im Browser (ohne Prüfungsbuchung): Kachel, Profil, Formular, Bestätigung, Admin-Ansicht.
//   BP_URL=… node tests/e2e/practice-flow.mjs   (nach practice-setup.php)
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';

const { chromium } = createRequire(import.meta.url)('playwright');
const base = process.env.BP_URL ?? 'http://127.0.0.1:8099';
const shots = process.env.BP_SHOTS;
const browser = await chromium.launch();
const errors = [];
const step = (s) => console.log('·', s);

async function session(user, pass) {
  const page = await (await browser.newContext({ viewport: { width: 1200, height: 1000 } })).newPage();
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto(`${base}/wp-login.php?redirect_to=${encodeURIComponent(base + '/bootsscheine/')}`);
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await page.click('#wp-submit');
  await page.waitForURL(/\/(bootsscheine|wp-admin)\//);

  return page;
}
const shot = async (page, name) => { if (shots) { await page.screenshot({ path: `${shots}/${name}.png`, fullPage: true }); } };

const page = await session('lerner1', 'Test-Passwort-123');
step('Kachel „Praxis & Prüfung“ auf der Kursseite');
await page.goto(`${base}/bootsscheine/sbf-see/`);
await page.getByRole('link', { name: 'Zur Anmeldung' }).click();
await page.getByRole('heading', { name: 'Vervollständige zuerst dein Profil.' }).waitFor();

step('Profil vervollständigen');
await page.getByLabel('Straße und Hausnummer *').fill('Hafenstraße 1');
await page.getByLabel('PLZ *').fill('20457');
await page.getByLabel('Ort *', { exact: true }).fill('Hamburg');
await page.getByLabel('Geburtsdatum *').fill('1990-05-17');
await page.getByRole('button', { name: 'Speichern' }).click();
await page.getByRole('heading', { name: 'Praxis & Prüfung Motorboot' }).waitFor();
await shot(page, '60-praxis-formular');

step('Formular ausfüllen und absenden');
const ortValue = await page.locator('select[name="ort"] option', { hasText: 'E2E-Hafen' }).getAttribute('value');
await page.locator('select[name="ort"]').selectOption(ortValue);
await page.getByLabel('Wunschdatum *').fill('2027-05-01');
await page.getByLabel('Geburtsort *').fill('Kiel');
await page.getByLabel('Nationalität *').fill('Deutsch');
await page.getByLabel('Telefonnummer *').fill('040123456');
await page.getByLabel('Weiblich').check();
await page.getByRole('button', { name: 'Anmeldung absenden' }).click();
await page.getByRole('heading', { name: 'Deine Anmeldung ist eingegangen.' }).waitFor();
assert.ok(await page.getByText('Prüfstelle Elbe').isVisible(), 'Prüfungsort übernommen');
await shot(page, '61-praxis-eingegangen');

step('Bootsschule sieht die Anmeldung');
const admin = await session('admin', 'Admin-Passwort-123');
await admin.goto(`${base}/wp-admin/admin.php?page=bootspruefung-praxis`);
await admin.getByRole('cell', { name: /Lea Lernt/ }).waitFor();
await admin.locator('table.bp-table a').first().click();
await admin.getByText('Prüfstelle Elbe').first().waitFor();
await shot(admin, '62-praxis-admin');

await browser.close();
assert.deepEqual(errors, [], errors.join('\n'));
console.log('Praxis-E2E bestanden.');
