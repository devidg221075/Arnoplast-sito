// Logica lista richiesta (ES module, senza dipendenze). Funzioni pure sulle liste: non mutano l'input.
export const STORAGE_KEY = 'arnoplast.quote.v1';
const KEY = STORAGE_KEY;
/** Quantità massima per riga (totale salvato per codice). */
export const QTY_MAX = 99999;

export function isValidQty(n) {
  return typeof n === 'number' && Number.isInteger(n) && n >= 1;
}

const cleanCode = (c) => (typeof c === 'string' ? c.trim() : '');

export function addItem(list, item) {
  const base = Array.isArray(list) ? list : [];
  const code = cleanCode(item && item.code);
  if (!code || !isValidQty(item.qty)) return base.map((i) => ({ ...i }));
  const description = typeof item.description === 'string' ? item.description : '';
  if (base.some((i) => i.code === code)) {
    return base.map((i) => (i.code === code ? { ...i, qty: i.qty + item.qty } : { ...i }));
  }
  return [...base.map((i) => ({ ...i })), { code, description, qty: item.qty }];
}

/** Vero se aggiungere qty al codice lascia il totale della riga entro QTY_MAX. */
export function canAdd(list, code, qty) {
  const c = cleanCode(code);
  const cur = (Array.isArray(list) ? list : []).find((i) => i.code === c);
  return (cur ? cur.qty : 0) + qty <= QTY_MAX;
}

export function setQty(list, code, qty) {
  const base = Array.isArray(list) ? list : [];
  if (!isValidQty(qty)) return base.map((i) => ({ ...i }));
  const c = cleanCode(code);
  return base.map((i) => (i.code === c ? { ...i, qty } : { ...i }));
}

export function removeItem(list, code) {
  const c = cleanCode(code);
  return (Array.isArray(list) ? list : []).filter((i) => i.code !== c).map((i) => ({ ...i }));
}

export function load(storage) {
  try {
    const raw = storage && storage.getItem(KEY);
    if (raw == null) return [];
    const data = JSON.parse(raw);
    if (!Array.isArray(data)) return [];
    return data
      .filter((i) => i && typeof i === 'object' && typeof i.code === 'string' && i.code.trim() !== '' && isValidQty(i.qty) && i.qty <= QTY_MAX)
      .map((i) => ({ code: i.code.trim(), description: typeof i.description === 'string' ? i.description : '', qty: i.qty }));
  } catch {
    return [];
  }
}

export function save(storage, list) {
  try {
    if (storage) storage.setItem(KEY, JSON.stringify(list));
  } catch {
    /* quota / modalità privata: ignorato */
  }
}

const oneLine = (v) => {
  const s = v == null ? '' : String(v).replace(/\r\n|\r|\n/g, ' ').trim();
  return s === '' ? '-' : s;
};

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
    ...items.map((i) => `${i.code} | ${i.description} | q.tà: ${i.qty}`),
  ];
  return lines.join('\n');
}
