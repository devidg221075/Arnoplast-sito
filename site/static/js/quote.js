// Logica lista richiesta (ES module, senza dipendenze). Funzioni pure sulle liste: non mutano l'input.
// Voce: { code, description, qty, product, conf }. La quantita' e' in confezioni da `conf` pezzi:
// la stessa voce (codice + prodotto + conf) si somma, voci con confezione o prodotto diversi restano separate.
export const STORAGE_KEY = 'arnoplast.quote.v2';
/** Chiave del formato precedente (voci senza prodotto/confezione): migrata in lettura. */
export const LEGACY_KEY = 'arnoplast.quote.v1';
/** Quantità massima per riga (totale salvato per voce). */
export const QTY_MAX = 99999;

export function isValidQty(n) {
  return typeof n === 'number' && Number.isInteger(n) && n >= 1;
}

const cleanCode = (c) => (typeof c === 'string' ? c.trim() : '');
const cleanConf = (c) => (typeof c === 'number' && Number.isInteger(c) && c >= 1 ? c : null);
const cleanProduct = (p) => (typeof p === 'string' ? p.trim() : '');

/** Identita' di una voce: codice + prodotto di origine + confezione. */
export const itemKey = (i) => `${cleanCode(i && i.code)}|${cleanProduct(i && i.product)}|${cleanConf(i && i.conf) ?? ''}`;

const norm = (i) => ({
  code: cleanCode(i.code),
  description: typeof i.description === 'string' ? i.description : '',
  qty: i.qty,
  product: cleanProduct(i.product),
  conf: cleanConf(i.conf),
});

export function addItem(list, item) {
  const base = Array.isArray(list) ? list : [];
  if (!item || !cleanCode(item.code) || !isValidQty(item.qty)) return base.map((i) => ({ ...i }));
  const next = norm(item);
  const key = itemKey(next);
  if (base.some((i) => itemKey(i) === key)) {
    return base.map((i) => (itemKey(i) === key ? { ...i, qty: i.qty + next.qty } : { ...i }));
  }
  return [...base.map((i) => ({ ...i })), next];
}

/** Vero se aggiungere qty alla voce `key` lascia il totale entro QTY_MAX. */
export function canAdd(list, key, qty) {
  const cur = (Array.isArray(list) ? list : []).find((i) => itemKey(i) === key);
  return (cur ? cur.qty : 0) + qty <= QTY_MAX;
}

export function setQty(list, key, qty) {
  const base = Array.isArray(list) ? list : [];
  if (!isValidQty(qty)) return base.map((i) => ({ ...i }));
  return base.map((i) => (itemKey(i) === key ? { ...i, qty } : { ...i }));
}

export function removeItem(list, key) {
  return (Array.isArray(list) ? list : []).filter((i) => itemKey(i) !== key).map((i) => ({ ...i }));
}

function parse(raw) {
  const data = JSON.parse(raw);
  if (!Array.isArray(data)) return [];
  return data
    .filter((i) => i && typeof i === 'object' && typeof i.code === 'string' && i.code.trim() !== '' && isValidQty(i.qty) && i.qty <= QTY_MAX)
    .map(norm);
}

export function load(storage) {
  try {
    if (!storage) return [];
    const raw = storage.getItem(STORAGE_KEY);
    if (raw != null) return parse(raw);
    const legacy = storage.getItem(LEGACY_KEY); // formato v1: nessun prodotto/confezione
    return legacy == null ? [] : parse(legacy);
  } catch {
    return [];
  }
}

export function save(storage, list) {
  try {
    if (storage) storage.setItem(STORAGE_KEY, JSON.stringify(list));
  } catch {
    /* quota / modalità privata: ignorato */
  }
}

const oneLine = (v) => {
  const s = v == null ? '' : String(v).replace(/\r\n|\r|\n/g, ' ').trim();
  return s === '' ? '-' : s;
};

/** Riga della lista: codice | descrizione | q.tà N conf. (da C) – prodotto. */
export const itemLine = (i) => `${i.code} | ${i.description} | q.tà ${i.qty}`
  + `${cleanConf(i.conf) ? ` conf. (da ${i.conf})` : ''}${cleanProduct(i.product) ? ` – ${cleanProduct(i.product)}` : ''}`;

export function emailBody(list, form) {
  const f = form || {};
  const items = Array.isArray(list) ? list : [];
  const notes = f.notes == null ? '' : String(f.notes).replace(/\r\n?/g, '\n').trim();
  const lines = [
    'Richiesta di preventivo',
    '',
    `Nome: ${oneLine(f.name)}`,
    `Azienda: ${oneLine(f.company)}`,
    `Email: ${oneLine(f.email)}`,
    `Telefono: ${oneLine(f.phone)}`,
    `Paese: ${oneLine(f.country)}`,
    `Note: ${notes === '' ? '-' : notes}`,
    '',
    `Righe richieste: ${items.length}`,
    ...items.map(itemLine),
  ];
  return lines.join('\n');
}
