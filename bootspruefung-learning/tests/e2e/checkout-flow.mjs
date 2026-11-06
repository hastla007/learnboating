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
  await page.waitForURL(/\/bootsscheine\/$/);
}

let current = null;
try {
  let env = setupJson();

  step('Gast: Lernprodukt verlangt Kundenkonto (Block-Checkout)');
  const guest = current = await newPage();
  await guest.goto(`${base}/?add-to-cart=${env.product}`);
  await guest.goto(env.checkout);
  await guest.waitForLoadState('networkidle');
  const guestText = await guest.locator('body').innerText();
  // Nur Beobachtung: Die Darstellung des Checkout-Blocks für Gäste hängt von der Woo-Version ab.
  // Fachlich geprüft wird die Kontopflicht in woocommerce-test.php (Filter, Optionen, Store-API-Sperre).
  const placeVisible = await guest.getByRole('button', { name: /place order/i }).isVisible().catch(() => false);
  console.log('   Gast-Kasse:', /log in|logged in|create an account|konto/i.test(guestText) ? 'Konto-Hinweis sichtbar' : 'kein Konto-Hinweis', placeVisible ? '(Bestellknopf sichtbar)' : '(kein Bestellknopf)');
  await shot(guest, '50-gast-checkout');

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
  const place = page.getByRole('button', { name: /place order|bestellung aufgeben|kaufen/i });
  await place.waitFor({ timeout: 30000 });
  await shot(page, '52-block-checkout');
  await place.click();
  await page.waitForURL(/order-received/, { timeout: 60000 });
  await page.getByText(/sobald die Zahlung eingegangen ist/).waitFor();
  await shot(page, '53-bestaetigung');

  step('Vor Zahlungseingang gesperrt, danach frei');
  await page.goto(`${base}/bootsscheine/sbf-bin-segel/`);
  await page.getByText('Freischalten mit:').first().waitFor();
  console.log('  ', setup('pay'));
  await page.goto(`${base}/bootsscheine/sbf-bin-segel/`);
  await page.locator('.bp-module-list a.bp-button').first().waitFor();
  assert.equal(await page.getByText('Freischalten mit:').count(), 0, 'kein Sperrhinweis mehr für gekaufte Funktionen');
  await page.goto(env.account);
  await page.getByText('aktiv').first().waitFor();
  await shot(page, '54-mein-lernen');

  step('Klassischer Checkout');
  env = setupJson('classic');
  await page.goto(`${base}/?add-to-cart=${env.product}`);
  await page.goto(env.checkout);
  await page.locator('#place_order').waitFor();
  if (await page.locator('#payment_method_bacs').count()) {
    await page.locator('#payment_method_bacs').check({ force: true });
  }
  await shot(page, '55-klassisch');
  await page.locator('#place_order').click();
  await page.waitForURL(/order-received/, { timeout: 60000 });
  await page.getByText(/sobald die Zahlung eingegangen ist/).waitFor();
  console.log('  ', setup('pay'));
  await page.goto(`${base}/bootsscheine/sbf-bin-segel/`);
  await page.locator('.bp-module-list a.bp-button').first().waitFor();

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
