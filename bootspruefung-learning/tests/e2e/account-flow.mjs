// Konto und Ausfallsicherheit im Browser (To-do T60; WooCommerce aktiv):
// Prüfung am Handy ohne Netz (Puffer-Hinweis fest sichtbar) und abbrechen, „Meine Daten“ mit Kontolöschung über
// den Bestätigungsknopf, Abmeldelink der Ablauf-Erinnerung, Login-Sperre und Aufheben per Passwort-Reset.
//   WP_DIR=… BP_URL=… node tests/e2e/account-flow.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';

const { chromium } = createRequire(import.meta.url)('playwright');
const base = process.env.BP_URL ?? 'http://127.0.0.1:8099';
const shots = process.env.BP_SHOTS;
const setupScript = new URL('./account-setup.php', import.meta.url).pathname;
const setup = (mode) => execFileSync('php', [setupScript, ...(mode ? [mode] : [])], { env: process.env, encoding: 'utf8' }).trim();
const setupJson = (mode) => JSON.parse(setup(mode).split('\n').filter((l) => l.startsWith('{')).pop());
const step = (s) => console.log('·', s);
const errors = [];
const browser = await chromium.launch();
let offline = false;

async function newPage(viewport = { width: 1200, height: 900 }) {
  const context = await browser.newContext({ viewport });
  const page = await context.newPage();
  // Mit Seite und erster Stack-Zeile, damit fremde Skriptfehler zuordenbar sind.
  page.on('pageerror', (e) => errors.push(`${page.url()}: ${e.message} @ ${(e.stack || '').split('\n').slice(1, 3).join(' | ').trim()}`));
  page.on('requestfailed', (r) => { if (!offline && r.url().startsWith(base) && r.failure()?.errorText !== 'net::ERR_ABORTED') { errors.push(`${r.url()} ${r.failure()?.errorText}`); } });

  return page;
}
const shot = async (page, name) => { if (shots) { await page.screenshot({ path: `${shots}/${name}.png`, fullPage: true }); } };
async function loginForm(page, login, pass) {
  await page.goto(`${base}/wp-login.php?redirect_to=${encodeURIComponent(base + '/bootsscheine/')}`);
  await page.fill('#user_login', login);
  await page.fill('#user_pass', pass);
  await page.click('#wp-submit');
}
async function login(page, login, pass) {
  await loginForm(page, login, pass);
  await page.waitForURL(/\/bootsscheine\/$/, { waitUntil: 'commit', timeout: 90000 });
}

try {
  const env = setupJson();

  step('Prüfung am Handy: Antwort ohne Netz gepuffert, Hinweis fest sichtbar, nachgesendet');
  let page = await newPage({ width: 390, height: 844 });
  await login(page, 'konto1', 'Konto-Passwort-123');
  await page.goto(`${base}/bootsscheine/sbf-see/pruefung/`);
  await page.getByRole('button', { name: /Prüfung (starten|fortsetzen)/ }).click();
  await page.waitForURL(/\/pruefung\/\d+\/$/);
  await page.locator('.bp-answer label').first().click();
  await page.locator('.bp-save-status', { hasText: /^Gespeichert$/ }).waitFor();
  offline = true;
  await page.context().setOffline(true);
  await page.getByRole('button', { name: 'Weiter', exact: true }).click();
  await page.locator('.bp-answer label').first().click();
  const status = page.locator('.bp-save-status.is-alert', { hasText: 'Offline – 1 Antwort wird gespeichert und nachgesendet.' });
  await status.waitFor();
  assert.equal(await status.evaluate((n) => getComputedStyle(n).position), 'fixed', 'Puffer-Hinweis am Handy unten angeheftet');
  assert.ok(await status.isVisible() && (await status.boundingBox()).y + 10 < 844, 'Hinweis im sichtbaren Bereich');
  await shot(page, '80-pruefung-offline-mobil');
  await page.context().setOffline(false);
  await page.locator('.bp-save-status', { hasText: /^Gespeichert$/ }).waitFor({ timeout: 15000 });
  assert.equal(await page.locator('.bp-save-status.is-alert').count(), 0, 'Hinweis verschwindet nach dem Nachsenden');
  offline = false;
  assert.equal(await page.locator('.bp-exam-nav-item.is-answered').count(), 2);

  step('Prüfung abbrechen: Rückfrage, Bestätigung auf der Übersicht, nicht gewertet');
  let confirmText = '';
  page.once('dialog', (d) => { confirmText = d.message(); d.accept(); });
  await page.getByRole('button', { name: 'Prüfung abbrechen' }).click();
  await page.waitForURL(/\/sbf-see\/pruefung\/$/);
  await page.locator('.bp-flash', { hasText: 'Die Prüfung wurde abgebrochen und nicht gewertet.' }).waitFor();
  assert.ok(confirmText.length > 10, 'Rückfrage vor dem Abbruch');
  await page.getByRole('button', { name: 'Zufällige Prüfung starten' }).waitFor(); // keine laufende Prüfung mehr
  await shot(page, '81-pruefung-abgebrochen');
  await page.reload();
  assert.equal(await page.locator('.bp-flash').count(), 0, 'Bestätigung nur einmal');
  await page.context().close();

  step('Abmeldelink der Ablauf-Erinnerung (ohne Anmeldung)');
  page = await newPage();
  await page.goto(env.optoutBad);
  await page.getByText('Dieser Link ist ungültig oder unvollständig.').waitFor();
  await page.goto(env.optout);
  await page.getByRole('heading', { name: 'Erinnerungen vor Ablauf' }).waitFor();
  await page.getByRole('button', { name: 'Erinnerungen abbestellen' }).click();
  await page.getByRole('status').filter({ hasText: 'Erledigt: Du bekommst keine E-Mail-Erinnerungen mehr' }).waitFor();
  assert.equal(setupJson('state').optedOut, true, 'abbestellt gespeichert');
  await shot(page, '82-erinnerung-abbestellt');
  await page.getByRole('button', { name: 'Erinnerungen wieder einschalten' }).click();
  await page.getByRole('status').filter({ hasText: 'Erledigt: Wir erinnern dich wieder' }).waitFor();
  assert.equal(setupJson('state').optedOut, false, 'wieder eingeschaltet');
  await page.context().close();

  step('„Meine Daten“: Datenexport und Kontolöschung beantragen, Löschung erst per Knopf');
  page = await newPage();
  await login(page, 'konto2', 'Konto-Passwort-456');
  await page.goto(env.myData);
  await page.getByRole('heading', { name: 'Meine Daten exportieren' }).waitFor();
  await page.getByRole('button', { name: 'Datenexport anfordern' }).click();
  await page.locator('.woocommerce-message[role="status"]').waitFor();
  await page.getByRole('button', { name: 'Bestätigungsmail erneut senden' }).first().waitFor();
  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: 'Konto löschen beantragen' }).click();
  await page.locator('.woocommerce-message[role="status"]').waitFor();
  await shot(page, '83-meine-daten');
  const mail = setupJson('state').mails.filter((m) => m.to.includes('konto2@') && /confirmaction/.test(m.message)).pop();
  assert.ok(mail, 'Bestätigungsmail für die Löschung verschickt');
  assert.match(mail.message, /Konto jetzt löschen/, 'Mail erklärt den Knopf');
  const confirmUrl = (mail.message.match(/https?:\/\/\S*action=confirmaction\S*/g) || []).filter((u) => /request_id=/.test(u)).pop();
  assert.ok(confirmUrl, 'Bestätigungslink in der Mail');
  await page.goto(confirmUrl);
  await page.getByRole('button', { name: 'Konto jetzt löschen' }).waitFor();
  assert.equal(setupJson('state').konto2, true, 'Öffnen des Links allein löscht nichts');
  await page.reload(); // erneutes Öffnen (z. B. Mail-Vorschau) löscht ebenfalls nicht
  assert.equal(setupJson('state').konto2, true);
  await page.getByRole('button', { name: 'Konto jetzt löschen' }).click();
  await page.waitForLoadState('domcontentloaded');
  assert.equal(setupJson('state').konto2, false, 'nach dem Klick gelöscht');
  await page.context().close();
  page = await newPage();
  await loginForm(page, 'konto2', 'Konto-Passwort-456');
  await page.locator('#login_error').waitFor();
  await page.context().close();

  step('Login-Sperre nach Fehlversuchen, Aufheben per Passwort-Reset');
  page = await newPage();
  let locked = false;
  for (let i = 1; i <= 6 && !locked; i++) {
    await loginForm(page, 'konto1', 'falsches-Passwort');
    const msg = await page.locator('#login_error').innerText();
    locked = /Zu viele Anmeldeversuche/.test(msg);
    if (!locked) {
      assert.match(msg, /Benutzername, E-Mail-Adresse oder Passwort ist nicht korrekt/, 'neutrale Meldung vor der Sperre');
    }
  }
  assert.ok(locked, 'Sperre spätestens nach 6 Fehlversuchen');
  await loginForm(page, 'konto1', 'Konto-Passwort-123');
  assert.match(await page.locator('#login_error').innerText(), /Zu viele Anmeldeversuche/, 'gesperrt auch mit richtigem Passwort');
  await shot(page, '84-login-gesperrt');
  await page.goto(setupJson('resetkey').url, { waitUntil: 'networkidle' });
  // Das Formular erzeugt per Skript ein Vorschlagspasswort; erst danach das eigene eintragen.
  await page.locator('#pass1').fill('Neues-Konto-Passwort-789');
  await page.waitForTimeout(300);
  if ((await page.locator('#pass1').inputValue()) !== 'Neues-Konto-Passwort-789') {
    await page.locator('#pass1').fill('Neues-Konto-Passwort-789');
  }
  assert.equal(await page.locator('#pass1').inputValue(), 'Neues-Konto-Passwort-789');
  await page.locator('#wp-submit').click();
  await page.waitForURL(/action=resetpass/);
  await page.getByText(/Passwort wurde zurückgesetzt|password has been reset/i).waitFor();
  await loginForm(page, 'konto1', 'Neues-Konto-Passwort-789');
  await page.waitForURL(/\/bootsscheine\/$/, { waitUntil: 'commit', timeout: 15000 }).catch(async () => {
    throw new Error('Anmeldung nach Reset fehlgeschlagen: ' + (await page.locator('#login_error').innerText().catch(() => page.url())));
  });
  await page.locator('#bp-app').waitFor();
  await page.context().close();
} finally {
  setup('reset');
  await browser.close();
}

assert.deepEqual(errors, [], 'keine JavaScript- oder Ladefehler');
console.log('E2E Konto bestanden.');
