// Kaufablauf im Browser (WooCommerce aktiv): Sperrhinweis mit Produktlink,
// Kontopflicht für Gäste, Checkout als Block und klassisch mit Überweisung,
// Freischaltung nach Zahlungseingang, „Mein Lernen“.
//   WP_DIR=… BP_URL=… node tests/e2e/checkout-flow.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';

const { chromium } = createRequire(import.meta.url)('playwright');
const base = process.env.BP_URL ?? 'http://127.0.0.1:8099';
const shots = process.env.BP_SHOTS;
const setupScript = new URL('./checkout-setup.php', import.meta.url).pathname;
const setup = (mode) => execFileSync('php', [setupScript, ...(mode ? [mode] : [])], { env: process.env, encoding: 'utf8' }).trim();
// Letzte JSON-Zeile der Setup-Ausgabe (PHP-Hinweise davor ignorieren).
const setupJson = (mode) => JSON.parse(setup(mode).split('\n').filter((l) => l.startsWith('{')).pop());
const step = (s) => console.log('·', s);
const errors = [];
const browser = await chromium.launch();

async function newPage() {
  const context = await browser.newContext({ viewport: { width: 1200, height: 1000 } });
  const page = await context.newPage();
  page.on('pageerror', (e) => errors.push(e.message));

  return page;
}
const shot = async (page, name) => { if (shots) { await page.screenshot({ path: `${shots}/${name}.png`, fullPage: true }); } };
async function login(page) {
  await page.goto(`${base}/wp-login.php?redirect_to=${encodeURIComponent(base + '/bootsscheine/')}`);
  await page.fill('#user_login', 'kaeufer1');
  await page.fill('#user_pass', 'Kauf-Passwort-123');
  await page.click('#wp-submit');
  await page.waitForURL(/\/bootsscheine\/$/, { waitUntil: 'commit', timeout: 90000 });
}

let current = null;
try {
  let env = setupJson();

  step('Widerrufsfunktion: /vertrag-widerrufen/ führt nicht ins Leere');
  const withdrawal = current = await newPage();
  const hop = await withdrawal.request.get(`${base}/vertrag-widerrufen/`, { maxRedirects: 0 });
  assert.equal(hop.status(), 302, 'feste Adresse leitet weiter');
  // Eingeschaltet: Formular der WooCommerce-Funktion; ausgeschaltet: Widerrufsbelehrung.
  assert.match(hop.headers().location ?? '', /withdraw|widerruf/i);
  if (/withdraw/.test(hop.headers().location ?? '')) {
    const form = await withdrawal.goto(`${base}/vertrag-widerrufen/`);
    assert.equal(form.status(), 200, 'Formular erreichbar');
    await withdrawal.locator('form input[type="email"]').first().waitFor();
  }
  await withdrawal.context().close();

  step('Gast: Lernprodukt verlangt Kundenkonto (Block-Checkout)');
  const guest = current = await newPage();
  await guest.goto(`${base}/?add-to-cart=${env.product}`);
  await guest.goto(env.checkout);
  await guest.waitForLoadState('networkidle');
  // Checkout-Block für Gäste mit Lernprodukt: Konto-Hinweis statt Formular und Bestellknopf.
  await guest.locator('.bp-checkout-account').waitFor();
  assert.match(await guest.locator('.bp-checkout-account').innerText(), /Kundenkonto erforderlich/);
  assert.equal(await guest.getByRole('button', { name: /place order|bestellen|zahlungspflichtig/i }).count(), 0, 'kein Bestellknopf für Gäste');
  await shot(guest, '50-gast-checkout');

  step('Gast registriert sich und kehrt zur Kasse zurück');
  await guest.locator('a.bp-checkout-register').click();
  await guest.locator('form.woocommerce-form-register #reg_email').fill(`gast${Date.now()}@example.invalid`);
  await guest.locator('form.woocommerce-form-register button[name="register"]').click();
  await guest.waitForURL((u) => u.href.startsWith(env.checkout));
  await guest.waitForLoadState('networkidle');
  assert.equal(await guest.locator('.bp-checkout-account').count(), 0, 'nach Registrierung: normale Kasse');
  await shot(guest, '50b-nach-registrierung');

  step('Kunde: Sperrhinweis mit Produktlink');
  const page = current = await newPage();
  await login(page);
  await page.goto(`${base}/bootsscheine/sbf-bin-segel/`);
  await page.getByText('Freischalten mit:').first().waitFor();
  const offer = page.getByRole('link', { name: 'Lernpaket SBF Binnen Segel' }).first();
  await shot(page, '51-sperrhinweis');
  await offer.click();
  await page.locator('button.single_add_to_cart_button').click();
  await page.waitForLoadState('networkidle');

  step('Block-Checkout mit Überweisung');
  await page.goto(env.checkout);
  // § 312j Abs. 3 BGB: Beschriftung „Zahlungspflichtig bestellen“.
  const place = page.getByRole('button', { name: /zahlungspflichtig bestellen/i });
  await place.waitFor({ timeout: 30000 });
  await shot(page, '52-block-checkout');
  // § 356 Abs. 5 BGB: ohne Zustimmung zum sofortigen Beginn keine Bestellung.
  const consent = page.getByRole('checkbox', { name: /ausdrücklich zu/ });
  await consent.waitFor();
  await place.click();
  await page.getByText(/Bitte bestätige die Zustimmung zum sofortigen Beginn/).first().waitFor({ timeout: 30000 });
  assert.doesNotMatch(page.url(), /order-received/, 'ohne Zustimmung keine Bestellung');
  await consent.check();
  await place.click();
  await page.waitForURL(/order-received/, { timeout: 60000 });
  await page.getByText(/sobald die Zahlung eingegangen ist/).waitFor();
  await shot(page, '53-bestaetigung');

  step('Vor Zahlungseingang „Zahlung offen“ statt Kaufangebot, danach frei');
  await page.goto(`${base}/bootsscheine/sbf-bin-segel/`);
  // QA-03/UX-01: bei offener Zahlung Hinweis statt erneutem Kaufangebot (kein Doppelkauf).
  await page.getByText('Zahlung noch offen').first().waitFor();
  assert.equal(await page.getByText('Freischalten mit:').count(), 0, 'bei offener Zahlung kein erneutes Kaufangebot');
  console.log('  ', setup('pay'));
  await page.goto(`${base}/bootsscheine/sbf-bin-segel/smarttrainer/`);
  await page.locator('.bp-module-list a.bp-topic').first().waitFor();
  assert.equal(await page.getByText('Freischalten mit:').count(), 0, 'kein Sperrhinweis mehr für gekaufte Funktionen');
  await page.goto(env.account);
  await page.getByText('aktiv', { exact: true }).first().waitFor(); // exakt: nicht „(immer aktiv)“ im Einwilligungsbanner
  await shot(page, '54-mein-lernen');

  step('Klassischer Checkout');
  env = setupJson('classic');
  await page.goto(`${base}/?add-to-cart=${env.product}`);
  await page.goto(env.checkout);
  await page.locator('#place_order').waitFor();
  if (await page.locator('#payment_method_bacs').count()) {
    await page.locator('#payment_method_bacs').check({ force: true });
  }
  // update_order_review ersetzt den Bereich mit dem Kästchen: erst nach dem Neuladen setzen …
  const classicConsent = page.locator('#bp_digital_consent');
  await page.waitForFunction(() => document.querySelectorAll('.blockUI').length === 0, null, { timeout: 30000 });
  assert.equal(await classicConsent.getAttribute('aria-required'), 'true', 'Kästchen als Pflichtfeld ausgezeichnet');
  assert.match(await page.locator('#bp_digital_consent_field a').innerText(), /öffnet in neuem Fenster/);
  await classicConsent.check();
  assert.match((await page.locator('#place_order').innerText()).trim(), /^Zahlungspflichtig bestellen$/);
  await shot(page, '55-klassisch');
  // … und unmittelbar vor dem Absenden prüfen, dass der Haken noch gesetzt ist.
  await page.waitForFunction(() => document.querySelectorAll('.blockUI').length === 0, null, { timeout: 30000 });
  assert.equal(await classicConsent.isChecked(), true, 'Haken nach Aktualisierung der Kasse erhalten');
  await page.locator('#place_order').click();
  await page.waitForURL(/order-received/, { timeout: 60000 });
  await page.getByText(/sobald die Zahlung eingegangen ist/).waitFor();
  console.log('  ', setup('pay'));
  await page.goto(`${base}/bootsscheine/sbf-bin-segel/smarttrainer/`);
  await page.locator('.bp-module-list a.bp-topic').first().waitFor();

} catch (e) {
  // Für die Fehlersuche in CI: Zustand der aktuellen Seite sichern.
  if (current && shots) {
    await current.screenshot({ path: `${shots}/checkout-fehler.png`, fullPage: true }).catch(() => {});
    console.log((await current.locator('body').innerText().catch(() => '')).slice(0, 3000));
  }
  await browser.close();
  setup('reset');
  throw e;
}
await browser.close();
setup('reset');
assert.deepEqual(errors, [], errors.join('\n'));
console.log('Checkout-E2E bestanden.');
