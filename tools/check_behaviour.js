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

const stored = () => page.evaluate(() => JSON.parse(localStorage.getItem('arnoplast.quote.v2') || '[]'));
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
    && body.includes(`${codeA} |`) && body.includes(`${codeB} |`) && body.includes('q.tà 12'), 'mailto: corpo con dati e righe');
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

// Tetto di 99999 sul totale di una riga.
await page.goto(BASE + PRODUCT, { waitUntil: 'networkidle' });
await page.evaluate(() => localStorage.clear());
await page.reload({ waitUntil: 'networkidle' });
const capRow = page.locator('tbody tr').nth(0);
await capRow.locator('.qty').fill('99999');
await capRow.locator('.add-to-quote').click();
await capRow.locator('.qty').fill('1');
await capRow.locator('.add-to-quote').click();
ok(/massima per riga: 99\.999/.test(await capRow.locator('.qty-msg').textContent())
  && (await capRow.locator('.qty').getAttribute('aria-invalid')) === 'true', 'tetto 99999: secondo inserimento rifiutato con messaggio');
ok((await stored())[0].qty === 99999, `tetto 99999: quantita' salvata resta 99999 (${JSON.stringify(await stored())})`);
await page.reload({ waitUntil: 'networkidle' });
ok((await stored()).length === 1 && (await stored())[0].qty === 99999 && (await counters()).every((c) => c === '1'), 'tetto 99999: dopo ricarica resta 99999');
await page.evaluate(() => localStorage.setItem('arnoplast.quote.v2', JSON.stringify([{ code: 'X', description: '', qty: 199998 }])));
await page.goto(BASE + QUOTE, { waitUntil: 'networkidle' });
ok(await page.locator('.quote-line').count() === 0, 'riga salvata oltre 99999 scartata al caricamento');

// Home: contatore in testata, ingresso ricerca della pagina.
await page.evaluate(() => localStorage.setItem('arnoplast.quote.v2', JSON.stringify([{ code: '1807-1351', description: 'Coprip Prisma 7x7', qty: 3 }])));
await page.goto(`${BASE}/it/`, { waitUntil: 'networkidle' });
ok((await counters()).every((c) => c === '1'), 'home: contatori = 1');
await page.locator('label[for="site-search"]:not(.sr-only)').first().click();
ok(await page.evaluate(() => document.activeElement && document.activeElement.id === 'site-search'), "home: l'ingresso ricerca porta il fuoco su #site-search");
await page.keyboard.type('prisma');
await page.waitForSelector('#search-results a');
ok(await page.locator('#search-results a').count() > 0, 'home: tendina risultati');
await page.keyboard.press('Escape');

// Ricerca con indice non raggiungibile: nessun errore.
await page.route('**/search-index.json', (r) => r.abort());
await page.goto(BASE + '/it/', { waitUntil: 'networkidle' });
await page.click('#site-search');
await page.keyboard.type('prisma');
await page.waitForTimeout(300);
await page.unroute('**/search-index.json');

// Stesso codice con confezione/prodotto diversi (1807-1310GZ in Prisma e Qubo): voci separate, con confezione e prodotto.
await page.goto(BASE + PRODUCT, { waitUntil: 'networkidle' });
await page.evaluate(() => localStorage.clear());
for (const prod of ['copripalo-prisma', 'copripalo-qubo']) {
  await page.goto(`${BASE}/it/agricoltura/copripalo/${prod}/`, { waitUntil: 'networkidle' });
  await page.locator('tbody tr[id="1807-1310GZ"] .add-to-quote').click();
}
const packs = await stored();
ok(packs.length === 2 && packs[0].conf !== packs[1].conf && packs.every((i) => i.product), `stesso codice, due confezioni: due voci (${packs.map((i) => `${i.product} da ${i.conf}`).join('; ')})`);
await page.goto(BASE + QUOTE, { waitUntil: 'networkidle' });
ok(await page.locator('.quote-line').count() === 2 && (await page.locator('.ql-meta').allTextContents()).every((t) => /conf\. da \d+ – Copripalo/.test(t)), 'pagina richiesta: confezione e prodotto su ogni riga');
await page.fill('#q-name', 'Mario Rossi');
await page.fill('#q-email', 'mario@example.com');
await page.check('#q-privacy');
await page.click('#quote-form button[type=submit]');
const copy = await page.locator('textarea.copy-text').inputValue();
ok(/info@arnoplast\.it/.test(await page.locator('#form-message').textContent()) && copy.includes('conf. (da') && copy.split('1807-1310GZ').length === 3, 'testo copiabile con le due righe e conf.');

// Telefono (390 px): nessuno scroll orizzontale e bottone visibile, su famiglie, prodotti e preventivo.
const noOverflow = () => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);
for (const path of ['/it/agricoltura/copripalo/', '/it/agricoltura/copripalo/copripalo-prisma/', '/it/agricoltura/copripalo/copripalo-qubo/',
  '/it/agricoltura/moschettoni-e-tiranti/colibri/', '/it/garden/testine/', QUOTE, '/it/', '/it/agricoltura/', '/it/garden/', '/it/olive/', '/it/azienda/']) {
  await page.goto(BASE + path, { waitUntil: 'networkidle' });
  ok(await noOverflow(), `390 px ${path}: nessun overflow orizzontale`);
  if (path.includes('copripalo-')) {
    const b = await page.locator('.add-to-quote').first().boundingBox();
    ok(b && b.x >= 0 && b.x + b.width <= 390, `390 px ${path}: bottone Aggiungi dentro lo schermo (x=${Math.round(b.x)}-${Math.round(b.x + b.width)})`);
  }
}

// Schede prodotto (famiglia): foto caricate, scheda intera cliccabile, link video sopra la scheda.
await page.goto(BASE + '/it/agricoltura/copripalo/', { waitUntil: 'networkidle' });
await page.evaluate(async () => { for (let y = 0; y <= document.body.scrollHeight; y += 400) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 50)); } });
await page.waitForTimeout(600);
const cardImgs = await page.$$eval('.pcard img', (els) => els.map((i) => ({ ok: i.complete && i.naturalWidth > 0, alt: i.alt, w: i.naturalWidth, src: i.currentSrc })));
ok(cardImgs.length === 20 && cardImgs.every((i) => i.ok && i.alt), `schede: ${cardImgs.length} foto caricate, tutte con alt`);
ok(cardImgs.every((i) => /\/photos\//.test(i.src)), 'schede: foto ritagliate (non la pagina del catalogo)');
const vid = page.locator('.pcard-video').first();
await vid.scrollIntoViewIfNeeded();
const vb = await vid.boundingBox();
ok(await page.evaluate(([x, y]) => !!document.elementFromPoint(x, y).closest('.pcard-video'), [vb.x + vb.width / 2, vb.y + vb.height / 2])
  && (await vid.getAttribute('target')) === '_blank' && /noopener/.test(await vid.getAttribute('rel')), 'schede: link video cliccabile sopra la scheda, nuova scheda con noopener');
const meta = page.locator('.pcard-meta').nth(3);
await meta.scrollIntoViewIfNeeded();
const mb = await meta.boundingBox();
await page.mouse.click(mb.x + 10, mb.y + mb.height / 2); // il clic arriva al link esteso sulla scheda
await page.waitForURL(/copripalo-monviso\/$/);
ok(true, 'schede: clic sul corpo della scheda apre la pagina prodotto');
ok(await page.locator('.product-videos .video-link').count() === 2 && await page.locator('img.product-photo').evaluate((i) => i.complete && i.naturalWidth > 0), 'Monviso: foto ritagliata e 2 link video');

// Animazioni: dopo lo scorrimento tutto e' visibile e i contatori arrivano al valore della pagina.
const allShown = (pg) => pg.evaluate(() => [...document.querySelectorAll('[data-reveal], [data-reveal="stagger"] > *, .hx-c, .hx-in > *')]
  .every((el) => parseFloat(getComputedStyle(el).opacity) > 0.99 && !el.classList.contains('rv-wait')));
const scrollAll = (pg) => pg.evaluate(async () => { for (let y = 0; y <= document.body.scrollHeight; y += 300) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 60)); } });
for (const path of ['/it/', '/it/agricoltura/', '/it/azienda/']) {
  const want = [...(await (await fetch(BASE + path)).text()).matchAll(/data-countup>(\d+)</g)].map((m) => m[1]);
  await page.goto(BASE + path, { waitUntil: 'networkidle' });
  await scrollAll(page);
  await page.waitForTimeout(3500);
  const got = await page.evaluate(() => [...document.querySelectorAll('[data-countup]')].map((e) => e.textContent));
  ok(await allShown(page), `${path}: dopo lo scorrimento tutti i blocchi animati sono visibili`);
  ok(JSON.stringify(want) === JSON.stringify(got), `${path}: contatori al valore della pagina (${got.join(', ')})`);
  ok(await page.evaluate(() => document.querySelectorAll('img[fetchpriority="high"]').length === 1), `${path}: una sola immagine prioritaria`);
}
// Movimento ridotto: nessuna animazione, contenuto visibile subito senza scorrere.
const calm = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
const p3 = await calm.newPage();
for (const path of ['/it/', '/it/garden/', '/it/azienda/']) {
  await p3.goto(BASE + path, { waitUntil: 'networkidle' });
  const still = await p3.evaluate(() => [...document.querySelectorAll('.hx-img, .hx-c, [data-parallax], [data-reveal] > *')]
    .every((el) => getComputedStyle(el).animationName === 'none' && getComputedStyle(el).transform === 'none'));
  ok(still && await allShown(p3), `movimento ridotto ${path}: nessuna animazione, tutto visibile`);
}
await calm.close();

// Senza JavaScript: avviso con mailto e telefono, nessun modulo inerte.
const nojs = await browser.newContext({ viewport: { width: 390, height: 844 }, javaScriptEnabled: false });
const p2 = await nojs.newPage();
await p2.goto(BASE + PRODUCT);
ok(await p2.locator('.noscript-note a[href^="mailto:info@arnoplast.it"]').isVisible() && !(await p2.locator('.add-row').first().isVisible()), 'senza JS (prodotto): avviso mailto, nessun bottone inerte');
await p2.goto(BASE + QUOTE);
ok(await p2.locator('.noscript-note a[href^="mailto:info@arnoplast.it"]').isVisible() && !(await p2.locator('#quote-form').isVisible()), 'senza JS (preventivo): avviso mailto, modulo nascosto');
for (const path of ['/it/', '/it/olive/', '/it/azienda/']) {
  await p2.goto(BASE + path);
  await p2.waitForTimeout(1200);
  ok(await allShown(p2), `senza JS ${path}: hero e sezioni visibili`);
}
await p2.goto(BASE + '/it/');
ok(await p2.locator('.hs-links a').count() === 4 && await p2.locator('.hs-links a').last().isVisible() && !(await p2.locator('.hs-ctrl').isVisible())
  && await p2.locator('img[fetchpriority="high"]').evaluate((i) => i.complete && i.naturalWidth > 0), 'senza JS /it/: prima foto, link alle 4 sezioni visibili, comandi nascosti');
await nojs.close();

// --- Carosello dell'hero della home.
const watch = (pg) => {
  pg.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  pg.on('pageerror', (e) => errors.push(e.message));
  pg.on('request', (r) => requests.push(r.url()));
};
const hs = (pg) => pg.evaluate(() => {
  const idx = (sel) => [...document.querySelectorAll(sel)].findIndex((e) => e.classList.contains('is-active'));
  return {
    pic: idx('.hs-pic'), cap: idx('.hs-cap'),
    dot: [...document.querySelectorAll('.hs-dot')].findIndex((d) => d.getAttribute('aria-current') === 'true'),
    live: document.querySelector('.hs-caps').getAttribute('aria-live'),
    visibleCaps: [...document.querySelectorAll('.hs-cap')].filter((c) => getComputedStyle(c).visibility === 'visible').length,
    srcs: [...document.querySelectorAll('.hs-pic img')].map((i) => i.currentSrc),
    srcsets: [...document.querySelectorAll('.hs-pic img, .hs-pic source')].map((e) => e.getAttribute('srcset') || ''),
  };
});
// Slide i attiva ovunque (foto, didascalia, indicatore) e una sola didascalia visibile (finita la dissolvenza).
const at = (st, i) => st.pic === i && st.cap === i && st.dot === i && st.visibleCaps === 1;
const deskCtx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const d1 = await deskCtx.newPage();
watch(d1);
await d1.goto(BASE + '/it/?luce=giorno', { waitUntil: 'domcontentloaded' });
let st = await hs(d1);
ok(at(st, 0) && st.srcsets.filter(Boolean).length === 2 && st.live === 'off', `carosello: all'apertura solo la prima foto ha srcset (${st.srcsets.filter(Boolean).length} su ${st.srcsets.length}), aria-live=off`);
await d1.waitForLoadState('load');
await d1.waitForTimeout(3500);
st = await hs(d1);
ok(st.srcsets.every(Boolean) && st.srcs.every((u) => u && !/-3200\.webp/.test(u)), `carosello 1440: dopo load + inattivita' tutte le foto caricate, nessuna da 3200 (${st.srcs.map((u) => u.split('/').pop()).join(', ')})`);
ok(await d1.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), '1440 px /it/: nessun overflow orizzontale');
// Rotazione automatica (mouse fuori dal carosello, nessun fuoco dentro).
await d1.mouse.move(700, 40);
await d1.waitForTimeout(7200 - 3500);
st = await hs(d1);
ok(at(st, 1) && st.live === 'off', `carosello: rotazione automatica alla slide 2 dopo ~6,5 s (slide ${st.pic + 1}, aria-live=${st.live})`);
// Comandi: successiva, frecce, indicatori, precedente.
await d1.locator('.hs-next').click();
await d1.waitForTimeout(800);
st = await hs(d1);
ok(at(st, 2) && st.live === 'polite', `carosello: "successiva" porta alla slide 3 (aria-live=${st.live} col fuoco nel carosello)`);
await d1.keyboard.press('ArrowRight');
await d1.waitForTimeout(800);
ok(at(await hs(d1), 3), 'carosello: freccia destra -> slide 4');
await d1.keyboard.press('ArrowRight');
await d1.waitForTimeout(800);
ok(at(await hs(d1), 0), 'carosello: freccia destra dall\'ultima torna alla prima');
await d1.keyboard.press('ArrowLeft');
await d1.waitForTimeout(800);
ok(at(await hs(d1), 3), 'carosello: freccia sinistra dalla prima va all\'ultima');
await d1.locator('.hs-dot').nth(1).click();
await d1.waitForTimeout(800);
ok(at(await hs(d1), 1), 'carosello: clic sull\'indicatore 2');
await d1.locator('.hs-prev').click();
await d1.waitForTimeout(800);
ok(at(await hs(d1), 0), 'carosello: "precedente" -> slide 1');
// Pausa: ferma la rotazione anche fuori dal carosello.
await d1.locator('.hs-pause').click();
const pausedLabel = await d1.locator('.hs-pause').getAttribute('aria-label');
await d1.mouse.move(700, 40);
await d1.evaluate(() => document.activeElement.blur());
await d1.waitForTimeout(8000);
st = await hs(d1);
ok(at(st, 0) && st.live === 'polite' && /Riprendi/.test(pausedLabel), `carosello: in pausa resta sulla slide 1 per 8 s (aria-live=${st.live}, pulsante "${pausedLabel}")`);
await d1.locator('.hs-pause').click();
// Ricerca in uso: la rotazione si ferma mentre si scrive, poi la ricerca funziona.
await d1.locator('label[for="site-search"]:not(.sr-only)').first().click();
await d1.mouse.move(700, 40);
await d1.keyboard.type('prisma', { delay: 120 });
await d1.waitForTimeout(7500);
ok(at(await hs(d1), 0), 'carosello: nessun cambio di slide mentre si scrive nella ricerca');
await d1.waitForSelector('#search-results a');
ok(await d1.locator('#search-results a').count() > 0, 'home 1440: ricerca dalla home con risultati');
await d1.keyboard.press('Escape');
// Mouse sopra il carosello: pausa temporanea.
await d1.evaluate(() => document.activeElement.blur());
await d1.mouse.move(1100, 500);
await d1.waitForTimeout(7500);
ok(at(await hs(d1), 0), 'carosello: nessun cambio di slide col mouse sopra');
await d1.mouse.move(700, 40);
await d1.waitForTimeout(7500);
ok(at(await hs(d1), 1), 'carosello: tolto il mouse la rotazione riprende');
await deskCtx.close();

// Di sera (?luce=notte): foto notturne su tutte le slide che le hanno, anche quelle caricate dopo.
for (const [w, h] of [[1440, 900], [390, 844]]) {
  const c = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: w < 600 ? 2 : 1 });
  const pg = await c.newPage();
  watch(pg);
  await pg.goto(BASE + '/it/?luce=notte', { waitUntil: 'load' });
  await pg.waitForTimeout(4000);
  const night = await pg.evaluate(() => [...document.querySelectorAll('.hs-pic')].map((p) => ({
    has: !!p.querySelector('[data-night-srcset]'), src: p.querySelector('img').currentSrc })));
  ok(night.every((n) => n.src && (n.has ? /-notte-/.test(n.src) : !/-notte-/.test(n.src))) && night.filter((n) => n.has).length === 2,
    `${w} px ?luce=notte: foto notturne sulle slide che le hanno (${night.map((n) => n.src.split('/').pop()).join(', ')})`);
  if (w < 600) ok(/-alto-840\.webp$/.test(night[0].src), `390 px @2x: prima foto dal ritaglio verticale da 840 px (${night[0].src.split('/').pop()})`);
  await c.close();
}

// Swipe su touch screen (telefono): sinistra = successiva, destra = precedente.
const touch = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
const t1 = await touch.newPage();
watch(t1);
await t1.goto(BASE + '/it/', { waitUntil: 'load' });
const cdpT = await touch.newCDPSession(t1);
const swipe = async (x0, x1) => {
  await cdpT.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: x0, y: 300 }] });
  for (let k = 1; k <= 5; k++) await cdpT.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x0 + (x1 - x0) * k / 5, y: 302 }] });
  await cdpT.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await t1.waitForTimeout(800);
};
await swipe(320, 80);
ok(at(await hs(t1), 1), 'carosello: swipe verso sinistra -> slide 2');
await swipe(80, 320);
ok(at(await hs(t1), 0), 'carosello: swipe verso destra -> slide 1');
await touch.close();

// Movimento ridotto: nessuna rotazione ne' zoom, comandi manuali attivi, pulsante pausa nascosto.
const calm2 = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
const r1 = await calm2.newPage();
watch(r1);
await r1.goto(BASE + '/it/', { waitUntil: 'load' });
await r1.mouse.move(700, 40);
await r1.waitForTimeout(8000);
st = await hs(r1);
ok(at(st, 0) && await r1.locator('.hs-pause').isHidden() && st.live === 'polite', `movimento ridotto: resta sulla slide 1 dopo 8 s, pausa nascosta, aria-live=${st.live}`);
await r1.locator('.hs-next').click();
await r1.waitForTimeout(400);
ok(at(await hs(r1), 1) && await r1.evaluate(() => [...document.querySelectorAll('.hs-pic img, .hs-fill')].every((e) => getComputedStyle(e).animationName === 'none')),
  'movimento ridotto: "successiva" funziona, nessuna animazione');
await calm2.close();

const foreign = requests.filter((u) => new URL(u).origin !== origin);
ok(foreign.length === 0, `nessuna richiesta esterna (${requests.length} richieste, ${new Set(requests).size} URL distinti)`);
// Messaggio di Chromium (non un errore JS) quando lo script naviga mentre una transizione tra pagine e' in corso.
const errs = errors.filter((e) => !/search-index\.json|ERR_FAILED|^Transition was aborted because of invalid state/.test(e));
ok(errs.length === 0, `nessun errore in console${errs.length ? ': ' + errs.join(' | ') : ''}`);
if (process.env.SHOW_URLS) console.log([...new Set(requests)].map((u) => u.replace(origin, '')).sort().join('\n'));

await browser.close();
console.log(failures ? `\n${failures} controlli falliti` : '\nTutti i controlli superati');
process.exit(failures ? 1 : 0);
