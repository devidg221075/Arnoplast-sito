// Uso: python -m arnoplast.site.build && (cd dist && python3 -m http.server 8000) & node tools/check_perf.js [http://localhost:8000]
// Misura peso, richieste, DCL/load, LCP e CLS in emulazione mobile (390x844, CPU 4x, Fast 3G). Report: esce sempre con 0.
import { createRequire } from 'node:module';
import { execSync } from 'node:child_process';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const BASE = (process.argv[2] || 'http://localhost:8000').replace(/\/$/, '');
const PAGES = ['/it/', '/it/agricoltura/', '/it/agricoltura/copripalo/', '/it/agricoltura/copripalo/copripalo-prisma/'];
const kb = (n) => (n / 1024).toFixed(1);
const kind = (type, url) => (type === 'Document' ? 'html' : type === 'Stylesheet' ? 'css' : type === 'Script' ? 'js' : type === 'Image' || /\.(jpe?g|png|webp|svg)(\?|$)/.test(url) ? 'img' : 'altro');

const browser = await chromium.launch();
const rows = [];
for (const path of PAGES) {
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  const page = await context.newPage();
  const cdp = await context.newCDPSession(page);
  await cdp.send('Network.enable');
  await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 562.5, downloadThroughput: (1.6 * 1024 * 1024) / 8 * 0.9, uploadThroughput: (750 * 1024) / 8 * 0.9 });
  await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
  const types = {}; const bytes = { html: 0, css: 0, js: 0, img: 0, altro: 0 }; let count = 0;
  cdp.on('Network.responseReceived', (e) => { types[e.requestId] = kind(e.type, e.response.url); });
  cdp.on('Network.loadingFinished', (e) => { bytes[types[e.requestId] || 'altro'] += e.encodedDataLength; count += 1; });
  await page.addInitScript(() => {
    window.__m = { lcp: 0, cls: 0 };
    new PerformanceObserver((l) => { for (const e of l.getEntries()) window.__m.lcp = e.startTime; }).observe({ type: 'largest-contentful-paint', buffered: true });
    new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__m.cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
  });
  await page.goto(BASE + path, { waitUntil: 'load' });
  await page.waitForTimeout(1500);
  const m = await page.evaluate(() => {
    const n = performance.getEntriesByType('navigation')[0];
    return { dcl: n.domContentLoadedEventEnd, load: n.loadEventEnd, lcp: window.__m.lcp, cls: window.__m.cls };
  });
  rows.push({ path, ...m, bytes, count });
  await context.close();
}
await browser.close();

console.log(`Mobile 390x844 @2x, CPU 4x, Fast 3G (1.6 Mbps, 562 ms RTT) - ${BASE}`);
console.log('pagina | HTML KB | CSS KB | JS KB | img KB | totale KB | richieste | DCL ms | load ms | LCP ms | CLS');
for (const r of rows) {
  const b = r.bytes; const tot = b.html + b.css + b.js + b.img + b.altro;
  console.log([r.path, kb(b.html), kb(b.css), kb(b.js), kb(b.img), kb(tot), r.count, Math.round(r.dcl), Math.round(r.load), Math.round(r.lcp), r.cls.toFixed(3)].join(' | '));
}
try {
  console.log('Lighthouse: ' + execSync('npx --no-install lighthouse --version', { stdio: ['ignore', 'pipe', 'ignore'] }).toString().trim() + ' disponibile (eseguilo a parte per il punteggio).');
} catch {
  console.log('Lighthouse NON disponibile: punteggio >= 90 NON misurato; sopra solo le metriche Chromium/CDP.');
}
process.exit(0);
