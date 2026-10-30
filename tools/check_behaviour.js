// Uso: python -m arnoplast.site.build && (cd dist && python3 -m http.server 8000) & node tools/check_behaviour.js [http://localhost:8000]
// Verifica il comportamento del prototipo (lista richiesta, modulo, ricerca) con Playwright + Chromium.
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const BASE = (process.argv[2] || 'http://localhost:8000').replace(/\/$/, '');
const PRODUCT = '/it/agricoltura/copripalo/copripalo-prisma/';
const QUOTE = '/it/richiesta-preventivo/';
const origin = new URL(BASE).origin;

let failures = 0;
const ok = (cond, msg) => {
  console.log(`${cond ? 'OK  ' : 'FAIL'} ${msg}`);
  if (!cond) failures += 1;
};

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
const page = await context.newPage();
const requests = [];
const mailtos = [];
const errors = [];
page.on('request', (r) => {
  if (r.url().startsWith('mailto:')) mailtos.push(r.url());
  else requests.push(r.url());
});
page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
page.on('pageerror', (e) => errors.push(e.message));
await page.route('mailto:*', (route) => route.abort()); // mai aprire un client di posta

const stored = () => page.evaluate(() => JSON.parse(localStorage.getItem('arnoplast.quote.v1') || '[]'));
const counters = () => page.$$eval('.quote-count', (els) => els.map((e) => e.textContent.trim()));

await page.goto(BASE + PRODUCT, { waitUntil: 'networkidle' });
await page.evaluate(() => localStorage.clear());
await page.reload({ waitUntil: 'networkidle' });

const rows = page.locator('tbody tr');
const codeA = await rows.nth(0).getAttribute('id');
const codeB = await rows.nth(1).getAttribute('id');
await rows.nth(0).locator('.qty').fill('2');
await rows.nth(0).locator('.add-to-quote').click();
ok(await rows.nth(0).locator('.add-to-quote').evaluate((b) => b.classList.contains('is-added')), 'feedback "Aggiunto" sul pulsante');
await rows.nth(0).locator('.qty').fill('3');
await rows.nth(0).locator('.add-to-quote').click();
await rows.nth(1).locator('.add-to-quote').click();
let list = await stored();
ok(list.length === 2 && list[0].code === codeA && list[0].qty === 5, `codice ripetuto: quantita' sommata (${JSON.stringify(list)})`);
ok((await counters()).every((c) => c === '2'), 'contatori = 2');

await rows.nth(2).locator('.qty').fill('0');
await rows.nth(2).locator('.add-to-quote').click();
ok((await stored()).length === 2, 'quantita\' 0 non aggiunta');
ok(/non valida/.test(await rows.nth(2).locator('.qty-msg').textContent()), 'messaggio inline per quantita\' non valida');
await rows.nth(2).locator('.qty').fill('100000');
await rows.nth(2).locator('.add-to-quote').click();
ok((await stored()).length === 2, 'quantita\' oltre 99999 non aggiunta');

await page.reload({ waitUntil: 'networkidle' });
ok((await counters()).every((c) => c === '2'), 'dopo ricarica: contatori = 2');

await page.goto(BASE + QUOTE, { waitUntil: 'networkidle' });
ok(await page.locator('.quote-line').count() === 2, 'pagina richiesta: 2 righe');
ok(await page.locator('#quote-empty').isHidden(), '#quote-empty nascosto con lista piena');

const qtyB = page.locator(`.quote-line[data-code="${codeB}"] .qty`);
await qtyB.fill('7');
await qtyB.dispatchEvent('change');
ok((await stored()).find((i) => i.code === codeB).qty === 7, 'modifica quantita\' salvata');
await qtyB.fill('-3');
await qtyB.dispatchEvent('change');
ok((await qtyB.inputValue()) === '7' && (await stored()).find((i) => i.code === codeB).qty === 7, 'quantita\' non valida ripristinata');

await page.locator(`.quote-line[data-code="${codeA}"] .ql-remove`).click();
list = await stored();
ok(list.length === 1 && list[0].code === codeB, 'rimozione riga');
ok((await counters()).every((c) => c === '1'), 'contatori = 1');

// Sincronizzazione tra schede.
const page2 = await context.newPage();
await page2.goto(BASE + PRODUCT, { waitUntil: 'networkidle' });
await page2.locator('tbody tr').nth(3).locator('.add-to-quote').click();
await page.waitForTimeout(200);
ok(await page.locator('.quote-line').count() === 2, 'altra scheda: lista aggiornata via evento storage');
await page2.close();

// Lista vuota: invio bloccato.
while (await page.locator('.ql-remove').count()) await page.locator('.ql-remove').first().click();
ok(await page.locator('#quote-empty').isVisible(), '#quote-empty visibile con lista vuota');
await page.fill('#q-name', 'Mario Rossi');
await page.fill('#q-email', 'mario@example.com');
await page.check('#q-privacy');
await page.click('#quote-form button[type=submit]');
ok(/lista è vuota/.test(await page.locator('#form-message').textContent()) && mailtos.length === 0, 'lista vuota: invio bloccato con messaggio');

// Lista piena ma consenso/email mancanti.
await page.goto(BASE + PRODUCT, { waitUntil: 'networkidle' });
await page.locator('tbody tr').nth(0).locator('.add-to-quote').click();
await page.locator('tbody tr').nth(1).locator('.qty').fill('12');
await page.locator('tbody tr').nth(1).locator('.add-to-quote').click();
await page.goto(BASE + QUOTE, { waitUntil: 'networkidle' });
await page.fill('#q-name', 'Mario Rossi');
await page.fill('#q-email', 'non-una-email');
await page.click('#quote-form button[type=submit]');
const msg = await page.locator('#form-message').textContent();
ok(/email valido/.test(msg) && /privacy/.test(msg) && mailtos.length === 0, 'email non valida e consenso mancante: invio bloccato');

// Invio valido: link mailto con il corpo atteso.
await page.fill('#q-email', 'mario@example.com');
await page.fill('#q-company', 'Az. Agricola');
await page.fill('#q-notes', 'Consegna a marzo');
await page.check('#q-privacy');
await page.click('#quote-form button[type=submit]');
await page.waitForTimeout(300);
ok(mailtos.length === 1, 'invio valido: una navigazione mailto');
if (mailtos.length) {
  const u = mailtos[0];
  const body = decodeURIComponent(u.split('&body=')[1] || '');
  ok(u.startsWith('mailto:info@arnoplast.it?subject=Richiesta%20di%20preventivo&body='), 'mailto: destinatario e oggetto');
  ok(body.includes('Nome: Mario Rossi') && body.includes('Email: mario@example.com') && body.includes('Righe richieste: 2')
    && body.includes(`${codeA} |`) && body.includes(`${codeB} |`) && body.includes('q.tà: 12'), 'mailto: corpo con dati e righe');
  console.log('     mailto body:\n' + body.split('\n').map((l) => `       ${l}`).join('\n'));
}
ok(await page.locator('#form-message.ok').isVisible(), 'messaggio di ringraziamento');
ok((await stored()).length === 2, 'lista conservata dopo l\'invio');

// Ricerca.
await page.click('#site-search');
await page.keyboard.type('prisma');
await page.waitForSelector('#search-results a');
const n = await page.locator('#search-results a').count();
ok(n > 0 && n <= 8, `ricerca: ${n} risultati (max 8)`);
await page.keyboard.press('ArrowDown');
ok(await page.evaluate(() => document.activeElement.closest('#search-results') !== null), 'freccia giu\': fuoco sul primo risultato');
await page.keyboard.press('Escape');
ok(await page.locator('#search-results').evaluate((e) => e.childElementCount === 0), 'Esc chiude i risultati');
await page.keyboard.type(' 7x7');
await page.waitForSelector('#search-results a');
await page.mouse.click(200, 600);
ok(await page.locator('#search-results').evaluate((e) => e.childElementCount === 0), 'clic fuori chiude i risultati');
await page.fill('#site-search', '1807-135');
await page.waitForSelector('#search-results a');
await page.locator('#site-search').press('Enter');
await page.waitForURL(/#1807-135/);
ok(/#1807-135\d$/.test(page.url()), `Invio apre il primo risultato (${page.url().replace(BASE, '')})`);

// Menu mobile.
await page.locator('.menu-toggle').click();
ok((await page.locator('.menu-toggle').getAttribute('aria-expanded')) === 'true' && await page.locator('#site-menu a').first().isVisible(), 'menu aperto (aria-expanded=true)');
await page.keyboard.press('Escape');
ok((await page.locator('.menu-toggle').getAttribute('aria-expanded')) === 'false', 'Esc chiude il menu');

// Ricerca con indice non raggiungibile: nessun errore.
await page.route('**/search-index.json', (r) => r.abort());
await page.goto(BASE + '/it/', { waitUntil: 'networkidle' });
await page.click('#site-search');
await page.keyboard.type('prisma');
await page.waitForTimeout(300);
await page.unroute('**/search-index.json');

const foreign = requests.filter((u) => new URL(u).origin !== origin);
ok(foreign.length === 0, `nessuna richiesta esterna (${requests.length} richieste, ${new Set(requests).size} URL distinti)`);
const errs = errors.filter((e) => !/search-index\.json|ERR_FAILED/.test(e));
ok(errs.length === 0, `nessun errore in console${errs.length ? ': ' + errs.join(' | ') : ''}`);
if (process.env.SHOW_URLS) console.log([...new Set(requests)].map((u) => u.replace(origin, '')).sort().join('\n'));

await browser.close();
console.log(failures ? `\n${failures} controlli falliti` : '\nTutti i controlli superati');
process.exit(failures ? 1 : 0);
