// Ricerca per codice/descrizione (ES module, senza dipendenze). Indice costruito nel browser.
const norm = (s) => (typeof s === 'string' ? s : '')
  .normalize('NFD')
  .replace(/[̀-ͯ]/g, '')
  .toLowerCase();
const stripCode = (s) => norm(s).replace(/[\s-]+/g, '');

export function buildIndex(articles) {
  const list = Array.isArray(articles) ? articles : [];
  return list
    .filter((a) => a && typeof a === 'object')
    .map((a) => ({
      article: a,
      codeKey: stripCode(a.code),
      desc: norm(a.description),
    }));
}

export function search(index, query, limit = 20) {
  if (typeof query !== 'string' || !Array.isArray(index)) return [];
  if (typeof limit !== 'number' || !(limit > 0)) return [];
  const q = norm(query).trim();
  if (q.length < 2) return [];
  const codeQ = stripCode(q);
  const words = q.split(/\s+/).filter(Boolean);
  const byCode = [];
  const byDesc = [];
  for (const e of index) {
    if (codeQ && e.codeKey.startsWith(codeQ)) byCode.push(e.article);
    else if (e.desc && words.every((w) => e.desc.includes(w))) byDesc.push(e.article);
  }
  return byCode.concat(byDesc).slice(0, Math.floor(limit) || 1);
}
