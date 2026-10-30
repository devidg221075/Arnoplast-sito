import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  addItem, setQty, removeItem, isValidQty, load, save, emailBody, itemKey,
} from '../site/static/js/quote.js';
import { canAdd, QTY_MAX, STORAGE_KEY, LEGACY_KEY } from '../site/static/js/quote.js';

const KEY = 'arnoplast.quote.v2';
// Voce normalizzata: codice, descrizione, quantita', prodotto di origine e confezione.
const it = (code, qty, o = {}) => ({ code, description: o.description ?? 'd', qty, product: o.product ?? '', conf: o.conf ?? null });
const mem = (initial) => {
  const m = new Map(initial ? [[KEY, initial]] : []);
  return { getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, String(v)), m };
};

test('addItem_merges_same_code', () => {
  let l = addItem([], { code: 'A1', description: 'Primo', qty: 2 });
  l = addItem(l, { code: ' A1 ', description: 'Altro', qty: 3 });
  assert.deepEqual(l, [it('A1', 5, { description: 'Primo' })]);
  l = addItem(l, { code: 'a1', description: 'Minuscolo', qty: 1 });
  assert.equal(l.length, 2);
});

test('addItem_rejects_zero_negative_decimal_empty', () => {
  const base = [it('A1', 1)];
  for (const q of [0, -2, 1.5, '', null, undefined, NaN, '3']) {
    assert.deepEqual(addItem(base, { code: 'B', description: 'y', qty: q }), base);
    assert.deepEqual(setQty(base, itemKey(base[0]), q), base);
  }
});

test('isValidQty', () => {
  assert.equal(isValidQty(1), true);
  assert.equal(isValidQty(40), true);
  for (const q of [0, -2, 1.5, '', '3', null, undefined, NaN, Infinity]) assert.equal(isValidQty(q), false);
});

test('addItem_does_not_mutate_input', () => {
  const base = [it('A1', 1)];
  const snap = JSON.stringify(base);
  const out = addItem(base, { code: 'A1', description: 'x', qty: 2 });
  assert.equal(JSON.stringify(base), snap);
  assert.notEqual(out, base);
  setQty(base, 'A1||', 9); removeItem(base, 'A1||');
  assert.equal(JSON.stringify(base), snap);
});

test('setQty_and_removeItem', () => {
  const base = [it('A1', 1), it('B', 2)];
  assert.equal(setQty(base, itemKey(base[1]), 7)[1].qty, 7);
  assert.deepEqual(removeItem(base, itemKey(base[0])), [base[1]]);
  assert.deepEqual(removeItem(base, 'ZZ'), base);
});

test('load_returns_empty_on_corrupt_json', () => {
  assert.deepEqual(load(mem('{not json')), []);
});

test('load_returns_empty_when_storage_throws', () => {
  assert.deepEqual(load({ getItem() { throw new Error('denied'); } }), []);
});

test('load_tolerates_missing_storage_and_non_array', () => {
  assert.deepEqual(load(undefined), []);
  assert.deepEqual(load(null), []);
  assert.deepEqual(load(mem('{"a":1}')), []);
  assert.deepEqual(load(mem('"str"')), []);
  assert.deepEqual(load(mem(null)), []);
});

test('load_drops_invalid_items_keeps_valid', () => {
  const raw = JSON.stringify([
    { code: 'A', description: 'd', qty: 2 }, { code: 'B', description: 'd', qty: 0 },
    null, 5, { code: '', description: 'd', qty: 1 }, { code: 'C', description: 'd', qty: '3' },
  ]);
  assert.deepEqual(load(mem(raw)), [it('A', 2)]);
});

test('save_roundtrip_and_swallows_errors', () => {
  const s = mem();
  const l = [it('A', 2, { product: 'Prisma', conf: 40 })];
  save(s, l);
  assert.deepEqual(load(s), l);
  assert.doesNotThrow(() => save({ setItem() { throw new Error('quota'); } }, l));
  assert.doesNotThrow(() => save(undefined, l));
  assert.doesNotThrow(() => save(null, l));
});

const form = { name: 'Mario', company: 'ACME', email: 'm@a.it', phone: '123', country: 'IT', notes: 'ciao' };

test('emailBody_one_line_per_code', () => {
  const l = [it('A1', 2, { description: 'Tubo' }), it('B2', 10, { description: 'Raccordo' })];
  const lines = emailBody(l, form).split('\n');
  assert.ok(lines.includes('A1 | Tubo | q.tà 2'));
  assert.ok(lines.includes('B2 | Raccordo | q.tà 10'));
  assert.equal(lines.filter((x) => x.includes(' | q.tà ')).length, 2);
  assert.ok(lines.includes('Nome: Mario'));
  assert.ok(lines.includes('Azienda: ACME'));
  assert.ok(lines.includes('Email: m@a.it'));
  assert.ok(lines.includes('Telefono: 123'));
  assert.ok(lines.includes('Paese: IT'));
  assert.ok(lines.includes('Note: ciao'));
  assert.ok(lines.some((x) => /2/.test(x) && /righe|riga/i.test(x)));
});

test('emailBody_keeps_html_chars_as_plain_text', () => {
  const l = [{ code: 'A1', description: 'Tubo <i>&"', qty: 1 }];
  const body = emailBody(l, { ...form, notes: '<b>&"' });
  assert.ok(body.includes('Note: <b>&"'));
  assert.ok(body.includes('A1 | Tubo <i>&" | q.tà 1'));
  assert.ok(!body.includes('&amp;') && !body.includes('&lt;'));
});

test('emailBody_missing_fields_dash_and_notes_keep_newlines', () => {
  const body = emailBody([], { notes: 'a\nb' });
  assert.ok(body.includes('Nome: -'));
  assert.ok(body.includes('Azienda: -'));
  assert.ok(body.includes('Telefono: -'));
  assert.ok(body.includes('Note: a\nb'));
  assert.ok(emailBody([], undefined).includes('Nome: -'));
  assert.ok(emailBody([], { notes: '' }).includes('Note: -'));
});

test('emailBody_crlf_in_name_cannot_inject_lines', () => {
  const body = emailBody([], { ...form, name: 'Mario\r\nBcc: evil@x.it', company: 'A\nB' });
  const lines = body.split('\n');
  assert.ok(lines.includes('Nome: Mario  Bcc: evil@x.it') || lines.includes('Nome: Mario Bcc: evil@x.it'));
  assert.ok(!lines.some((x) => x.startsWith('Bcc:')));
  assert.ok(lines.includes('Azienda: A B'));
  assert.ok(!body.includes('\r'));
});

test('qty_max_and_storage_key_exported', () => {
  assert.equal(QTY_MAX, 99999);
  assert.equal(STORAGE_KEY, KEY);
});

test('canAdd_false_when_line_total_exceeds_max', () => {
  const l = [it('A1', 99999)];
  assert.equal(canAdd(l, 'A1||', 1), false);
  assert.equal(canAdd([it('A1', 50000)], 'A1||', 50000), false);
  assert.equal(canAdd([], 'B||', 100000), false);
});

test('canAdd_true_up_to_exactly_max', () => {
  assert.equal(canAdd([it('A1', 99998)], 'A1||', 1), true);
  assert.equal(canAdd([it('A1', 99999)], 'B2||', 99999), true);
  assert.equal(canAdd([], 'A1||', 99999), true);
});

test('load_drops_items_over_qty_max', () => {
  const raw = JSON.stringify([
    { code: 'A', description: 'd', qty: 100000 },
    { code: 'B', description: 'e', qty: 99999 },
  ]);
  assert.deepEqual(load(mem(raw)), [it('B', 99999, { description: 'e' })]);
});

const P = { description: 'Viteria GZ', product: 'Prisma', conf: 40 };

test('same_code_different_pack_or_product_is_not_merged', () => {
  let l = addItem([], { code: '1807-1310GZ', qty: 1, ...P });
  l = addItem(l, { code: '1807-1310GZ', qty: 1, ...P, product: 'Qubo', conf: 64 });
  l = addItem(l, { code: '1807-1310GZ', qty: 1, ...P, product: 'Zephiro', conf: 32 });
  assert.equal(l.length, 3);
  l = addItem(l, { code: '1807-1310GZ', qty: 2, ...P });
  assert.equal(l.length, 3);
  assert.equal(l[0].qty, 3);
  assert.equal(new Set(l.map(itemKey)).size, 3);
});

test('same_product_different_conf_stays_separate', () => {
  let l = addItem([], { code: '1802-0410', qty: 1, description: 'a', product: 'Colibri', conf: 10 });
  l = addItem(l, { code: '1802-0410', qty: 1, description: 'b', product: 'Colibri', conf: 20 });
  assert.equal(l.length, 2);
});

test('setQty_removeItem_and_canAdd_act_on_one_pack_only', () => {
  const a = { code: 'X', qty: 5, ...P };
  const b = { code: 'X', qty: 7, ...P, product: 'Qubo', conf: 64 };
  const l = addItem(addItem([], a), b);
  assert.equal(setQty(l, itemKey(l[1]), 9)[0].qty, 5);
  assert.equal(setQty(l, itemKey(l[1]), 9)[1].qty, 9);
  assert.deepEqual(removeItem(l, itemKey(l[0])).map((i) => i.product), ['Qubo']);
  assert.equal(canAdd(l, itemKey(l[0]), 99994), true);
  assert.equal(canAdd(l, itemKey(l[0]), 99995), false);
});

test('emailBody_shows_pack_and_product', () => {
  const l = [it('1807-1310GZ', 2, P), it('1807-1310GZ', 1, { ...P, product: 'Qubo', conf: 64 })];
  const lines = emailBody(l, form).split('\n');
  assert.ok(lines.includes('1807-1310GZ | Viteria GZ | q.tà 2 conf. (da 40) – Prisma'));
  assert.ok(lines.includes('1807-1310GZ | Viteria GZ | q.tà 1 conf. (da 64) – Qubo'));
});

test('load_ignores_v2_garbage_fields_and_migrates_legacy_v1', () => {
  const raw = JSON.stringify([{ code: 'A', description: 'd', qty: 2, product: 5, conf: 'x' }]);
  assert.deepEqual(load(mem(raw)), [it('A', 2)]);
  const m = new Map([[LEGACY_KEY, JSON.stringify([{ code: 'Z', description: 'old', qty: 3 }])]]);
  const store = { getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, v) };
  assert.deepEqual(load(store), [it('Z', 3, { description: 'old' })]);
  save(store, [it('Z', 4)]);
  assert.deepEqual(load(store), [it('Z', 4)]);
});
