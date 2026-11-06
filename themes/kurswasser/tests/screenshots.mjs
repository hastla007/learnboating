// Screenshots des Themes (Desktop + Mobil) für die Abnahme, z. B. in CI nach dem Kaufablauf-Test.
//   BP_URL=http://127.0.0.1:8099 BP_SHOTS=/pfad node themes/kurswasser/tests/screenshots.mjs
// Prüft nebenbei: Status 200, keine JS-Fehler, keine sichtbaren PHP-Hinweise.
import { createRequire } from 'node:module';

const { chromium } = createRequire(import.meta.url)('playwright');
const base = process.env.BP_URL ?? 'http://127.0.0.1:8099';
const out = process.env.BP_SHOTS ?? '.';
const user = process.env.BP_USER;
const pass = process.env.BP_PASS;
const pages = (process.env.KW_PAGES ?? '/,/kurse/sbf-see/,/kurse/sbf-kombi/,/kurse/kapitaen/,/kurse/src/,/kurse/weitere/,/kurse/pakete/,/shop/,/?product_cat=gruppen,/bootsscheine/,/gibt-es-nicht/').split(',');
const browser = await chromium.launch();
const problems = [];

async function run(width, suffix, ctxOptions = {}) {
	const context = await browser.newContext({ viewport: { width, height: 900 }, ...ctxOptions });
	const page = await context.newPage();
	page.on('pageerror', (e) => problems.push(`${page.url()}: ${e.message}`));
	const shoot = async (path, name) => {
		const res = await page.goto(base + path, { waitUntil: 'load', timeout: 90000 });
		await page.waitForTimeout(800);
		const status = res ? res.status() : 0;
		const text = await page.locator('body').innerText();
		if (/(Warning|Notice|Fatal error|Deprecated):/.test(text)) {
			problems.push(`${path}: PHP-Hinweis sichtbar`);
		}
		if (status !== 200 && !path.includes('gibt-es-nicht')) {
			problems.push(`${path}: HTTP ${status}`);
		}
		await page.screenshot({ path: `${out}/theme-${name}-${suffix}.png`, fullPage: true });
		console.log('·', name, suffix, status);
	};
	for (const path of pages) {
		const name = path === '/' ? 'start' : path.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '');
		await shoot(path, name);
	}
	const exists = async (path) => (await page.request.get(base + path, { maxRedirects: 0 }).catch(() => null))?.status() === 200;
	const firstExisting = async (paths) => { for (const p of paths) { if (await exists(p)) { return p; } } return null; };
	// Produktseite: erster Treffer im Shop
	await page.goto(base + '/shop/', { waitUntil: 'load', timeout: 90000 });
	const productLink = page.locator('.wc-block-product-template a, ul.products a').first();
	if (await productLink.count()) {
		const href = await productLink.getAttribute('href');
		await shoot(href.replace(base, ''), 'produkt');
		await page.locator('button.single_add_to_cart_button').first().click().catch(() => {});
		await page.waitForLoadState('load');
		const cart = await firstExisting(['/warenkorb/', '/cart/']);
		const checkout = await firstExisting(['/kasse/', '/checkout/']);
		if (cart) { await shoot(cart, 'warenkorb'); }
		if (checkout) { await shoot(checkout, 'kasse-gast'); }
	}
	if (user && pass) {
		await page.goto(`${base}/wp-login.php`);
		await page.fill('#user_login', user);
		await page.fill('#user_pass', pass);
		await page.click('#wp-submit');
		await page.waitForLoadState('load');
		await shoot('/bootsscheine/', 'lernbereich-angemeldet');
		await shoot('/bootsscheine/tipps/', 'tipps');
		const account = await firstExisting(['/mein-konto/', '/my-account/']);
		if (account) { await shoot(account, 'konto'); }
	}
	await context.close();
}

await run(1440, 'desktop');
await run(390, 'mobil', { isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
await browser.close();
if (problems.length) {
	console.error('Probleme:\n' + problems.join('\n'));
	process.exit(1);
}
console.log('Theme-Screenshots fertig.');
