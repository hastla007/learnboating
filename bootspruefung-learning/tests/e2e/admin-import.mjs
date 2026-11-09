// Admin-Durchlauf: Content-Paket hochladen, Vorschau, Freigabe, Import (REST-Schritte), Content-Browser.
//   BP_URL=… BP_ADMIN=admin BP_ADMIN_PASS=… BP_ZIP=build/<paket>.zip node tests/e2e/admin-import.mjs
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';

const { chromium } = createRequire(import.meta.url)('playwright');
const base = process.env.BP_URL ?? 'http://127.0.0.1:8099';
const shots = process.env.BP_SHOTS;
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: Number(process.env.BP_SCALE ?? 1) });
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
const shot = async (name) => { if (shots) { await page.screenshot({ path: `${shots}/${name}.png`, fullPage: true }); } };
const step = (s) => console.log('·', s);

step('Admin-Login');
await page.goto(`${base}/wp-login.php`);
await page.fill('#user_login', process.env.BP_ADMIN);
await page.fill('#user_pass', process.env.BP_ADMIN_PASS);
await page.click('#wp-submit');
await page.waitForURL(/wp-admin/);

step('Paket hochladen');
await page.goto(`${base}/wp-admin/admin.php?page=bootspruefung-import`);
await page.setInputFiles('input[name="package"]', process.env.BP_ZIP);
await page.getByRole('button', { name: 'Hochladen und prüfen' }).click();
await page.getByText('Paket hochgeladen und geprüft.').waitFor();
assert.match(await page.locator('h2').first().innerText(), /validated|geprüft/);

step('Vorschau und Freigabe');
await page.getByRole('button', { name: 'Vorschau erstellen (Dry-Run)' }).click();
await page.getByRole('heading', { name: 'Vorschau (Dry-Run)' }).waitFor();
await shot('10-admin-vorschau');
await page.fill('input[name="note"]', 'E2E-Test');
await page.getByRole('button', { name: 'Freigeben', exact: true }).click();
await page.getByRole('button', { name: 'Import starten' }).waitFor();

step('Import mit Fortschritt');
await page.getByRole('button', { name: 'Import starten' }).click();
await page.getByRole('heading', { name: /published|veröffentlicht/ }).waitFor({ timeout: 120000 });
await page.getByRole('heading', { name: 'Protokoll' }).waitFor();
await shot('11-admin-ergebnis');

step('Content-Browser');
for (const [slug, text] of [['bootspruefung', 'Bestand'], ['bootspruefung-courses', 'SBF See'], ['bootspruefung-questions', 'FKN-0001'], ['bootspruefung-media', 'elwis/'], ['bootspruefung-rules', 'Zeitlimit'], ['bootspruefung-rights', 'Fragen nach Katalog']]) {
  await page.goto(`${base}/wp-admin/admin.php?page=${slug}`);
  await page.getByText(text).first().waitFor();
}
await shot('12-admin-kurse');

step('Rechtefreigabe für Praxiskarten SBF-SEE');
await page.goto(`${base}/wp-admin/admin.php?page=bootspruefung-rights`);
const row = page.locator('tr', { hasText: 'SBF-SEE' }).filter({ has: page.locator('input[name="group"][value="SBF-SEE"]') }).first();
await row.locator('input[name="reason"]').fill('Eigene Inhalte (E2E)');
await row.getByRole('button', { name: 'Anwenden' }).click();
await page.getByText(/Einträge aktualisiert/).waitFor();
await shot('13-admin-rechte');

assert.deepEqual(errors, []);
await browser.close();
console.log('Admin-E2E bestanden.');
