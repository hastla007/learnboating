// Seite „Prüfung anmelden“ im Browser: Kachel, Reiter im Kombikurs, Prüfungsformen,
// Anleitung, Filter der Prüfungsausschüsse, FAQ.
//   BP_URL=… node tests/e2e/guide-flow.mjs   (nach guide-setup.php)
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';

const { chromium } = createRequire(import.meta.url)('playwright');
const base = process.env.BP_URL ?? 'http://127.0.0.1:8099';
const shots = process.env.BP_SHOTS;
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1200, height: 1000 } });
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
const step = (s) => console.log('·', s);
const shot = async (name) => { if (shots) { await page.screenshot({ path: `${shots}/${name}.png`, fullPage: true }); } };

await page.goto(`${base}/wp-login.php?redirect_to=${encodeURIComponent(base + '/bootsscheine/')}`);
await page.fill('#user_login', 'lerner1');
await page.fill('#user_pass', 'Test-Passwort-123');
await page.click('#wp-submit');
await page.waitForURL(/\/bootsscheine\/$/, { waitUntil: 'commit', timeout: 90000 });

step('Kachel im Kombikurs SBF See + Binnen');
await page.goto(`${base}/bootsscheine/sbf-see-bin/`);
await page.locator('#bp-app').getByRole('link', { name: 'Prüfung anmelden', exact: true }).click();
await page.getByRole('heading', { name: 'SBF-See-Prüfung buchen', level: 1 }).waitFor();
await page.getByText('30 Fragen: 7 Basisfragen aus 72, 23 See-Fragen aus 213').first().waitFor();
await page.getByText('Prüfungsgebühr:').waitFor();
await page.getByRole('heading', { name: 'So meldest du dich an' }).waitFor();
await shot('70-pruefung-anmelden');

step('Reiter SBF Binnen: Kombi-Variante zuerst');
await page.locator('#bp-app').getByRole('link', { name: 'SBF Binnen', exact: true }).click();
await page.getByRole('heading', { name: 'SBF-Binnen-Prüfung buchen', level: 1 }).waitFor();
const firstVariant = await page.locator('.bp-variant h3').first().innerText();
assert.equal(firstVariant, 'Binnen-Teil bei gemeinsamer Prüfung See und Binnen');

step('Prüfungsausschüsse filtern');
const all = await page.locator('.bp-committees > li.bp-card').count();
assert.equal(all, 18, 'alle 18 Prüfungsausschüsse');
await page.getByRole('searchbox', { name: 'Nach Ort, PLZ oder Name filtern' }).fill('Aurich');
await page.waitForFunction(() => document.querySelectorAll('.bp-committees > li.bp-card').length === 1);
await page.getByRole('heading', { name: /Prüfungsausschuss Aurich/ }).waitFor();

step('FAQ aufklappen');
await page.getByText('Wie läuft der Prüfungstag ab?').click();
await page.getByText(/Theorie und Praxis können am selben Tag/).waitFor();
await shot('71-pruefungsausschuesse');

await browser.close();
assert.deepEqual(errors, [], errors.join('\n'));
console.log('Prüfungsanmeldung-E2E bestanden.');
