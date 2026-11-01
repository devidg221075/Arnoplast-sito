// Controllo della Content-Security-Policy: serve il sito con le intestazioni di _headers e visita le pagine,
// provando le funzioni che caricano risorse (galleria, sfogliatore, videoteca, mappa, social, video della home).
// Uso: node tools/check_csp.js <cartella-del-sito-costruito>   (esce con 1 se c'e' una violazione)
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
import http from 'node:http'; import fs from 'node:fs'; import path from 'node:path';
const root = path.resolve(process.argv[2]);
const hdr = fs.readFileSync(path.join(root, '_headers'), 'utf8').split('\n').slice(1).map(l => l.trim()).filter(Boolean)
  .map(l => [l.slice(0, l.indexOf(':')), l.slice(l.indexOf(':') + 1).trim()]);
const types = { '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'text/javascript', '.json': 'application/json', '.webp': 'image/webp', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.pdf': 'application/pdf', '.png': 'image/png' };
const srv = http.createServer((q, r) => {
  let p = decodeURIComponent(q.url.split('?')[0]); let f = path.join(root, p);
  if (fs.existsSync(f) && fs.statSync(f).isDirectory()) f = path.join(f, 'index.html');
  if (!fs.existsSync(f)) { r.writeHead(404); return r.end('no'); }
  r.writeHead(200, Object.fromEntries([['Content-Type', types[path.extname(f)] || 'application/octet-stream'], ...hdr]));
  fs.createReadStream(f).pipe(r);
}).listen(0, async () => {
  const base = 'http://localhost:' + srv.address().port; const viol = []; const notes = new Set();
  const b = await chromium.launch();
  for (const [w, h] of [[1440, 900], [390, 844]]) {
    const p = await b.newPage({ viewport: { width: w, height: h } });
    p.on('console', m => { const tx = m.text();
      if (!(m.location().url || '').startsWith(base)) return; // solo i messaggi delle nostre pagine: le finestre di YouTube, Vimeo ecc. hanno una politica propria
      if (/X-Frame-Options/i.test(tx)) { notes.add(tx.slice(0, 160)); return; } // rifiuto del servizio esterno, non della nostra politica
      if (/Content Security Policy|Refused to/i.test(tx)) viol.push(tx.slice(0, 200)); });
    p.on('pageerror', e => viol.push('pageerror: ' + e.message));
    await p.addInitScript(() => document.addEventListener('securitypolicyviolation', e => location.origin === top.location.origin && console.log('Content Security Policy: ' + e.violatedDirective + ' ' + e.blockedURI)));
    p.setDefaultTimeout(4000);
    const go = async u => { await p.goto(base + u); await p.waitForTimeout(700); };
    const act = async (label, fn) => { try { await fn(); } catch (e) { viol.push('azione non riuscita: ' + label); } };
    for (const u of ['/it/', '/it/agricoltura/', '/it/agricoltura/copripalo/', '/it/agricoltura/copripalo/copripalo-prisma/', '/it/azienda/', '/it/azienda/imballaggi/', '/it/fiere/', '/it/cataloghi/', '/it/richiesta-preventivo/', '/it/cookie/', '/it/area-riservata/', '/en/']) await go(u);
    await go('/it/'); await act('social', async () => { await p.locator('[data-fb-load]').click(); await p.waitForTimeout(1500); });
    await go('/it/'); await act('video home', async () => { await p.locator('.av-open').click(); await p.waitForTimeout(1500); });
    await go('/it/contatti/'); await act('mappa', async () => { await p.locator('[data-map-load]').click(); await p.waitForTimeout(1500); });
    await go('/it/video/'); await act('videoteca', async () => { await p.locator('.vt-open').first().click(); await p.waitForTimeout(1500); await p.keyboard.press('Escape'); });
    await go('/it/cataloghi/garden/'); await act('sfogliatore', async () => { await p.locator('.fb-nav.next').click(); await p.waitForTimeout(600); });
    await go('/it/galleria/'); await act('galleria', async () => { await p.locator('.gl-item a').first().click(); await p.waitForTimeout(600); });
    await go('/it/'); await p.fill('#site-search', 'prisma').catch(() => {}); await p.waitForTimeout(600);
    await p.close();
  }
  await b.close(); srv.close();
  const uniq = [...new Set(viol)];
  if (notes.size) console.log('NOTA (non e\' la nostra politica):\n' + [...notes].join('\n'));
  console.log(uniq.length ? 'VIOLAZIONI:\n' + uniq.join('\n') : 'OK: nessuna violazione della Content-Security-Policy');
  process.exit(uniq.length ? 1 : 0);
});
