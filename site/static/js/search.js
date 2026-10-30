// Ricerca per codice/descrizione (ES module, senza dipendenze). Indice costruito nel browser.
const norm = (s) => (typeof s === 'string' ? s : '')
  .normalize('NFD')
  .replace(/[̀-ͯ]/g, '')
  .toLowerCase();
const stripCode = (s) => norm(s).replace(/[\s-]+/g, '');

/**
 * Voci dell'indice: articoli ({code, description, url, products}) e pagine
 * ({name, kind: 'product' | 'family', parent, url}). Un articolo per codice (i prodotti
 * in cui compare sono elencati in `products`).
 */
export function buildIndex(entries) {
  const list = Array.isArray(entries) ? entries : [];
  return list
    .filter((a) => a && typeof a === 'object')
    .map((a) => (typeof a.name === 'string' && typeof a.code !== 'string'
      ? { entry: a, page: true, codeKey: '', desc: norm(`${a.name} ${a.parent || ''}`) }
      : { entry: a, page: false, codeKey: stripCode(a.code), desc: norm(a.description) }));
}

/** Ordine dei risultati: codice, poi nome di prodotto/famiglia, poi descrizione articolo. */
export function search(index, query, limit = 20) {
  if (typeof query !== 'string' || !Array.isArray(index)) return [];
  if (typeof limit !== 'number' || !(limit > 0)) return [];
  const q = norm(query).trim();
  if (q.length < 2) return [];
  const codeQ = stripCode(q);
  const words = q.split(/\s+/).filter(Boolean);
  const byCode = [];
  const byPage = [];
  const byDesc = [];
  for (const e of index) {
    if (e.page) {
      if (words.every((w) => e.desc.includes(w))) byPage.push(e.entry);
    } else if (codeQ && e.codeKey.startsWith(codeQ)) byCode.push(e.entry);
    else if (e.desc && words.every((w) => e.desc.includes(w))) byDesc.push(e.entry);
  }
  return byCode.concat(byPage, byDesc).slice(0, Math.floor(limit) || 1);
}
