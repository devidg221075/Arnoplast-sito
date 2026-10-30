import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  addItem, setQty, removeItem, isValidQty, load, save, emailBody,
} from '../site/static/js/quote.js';
import { canAdd, QTY_MAX, STORAGE_KEY } from '../site/static/js/quote.js';

const KEY = 'arnoplast.quote.v1';
const mem = (initial) => {
  const m = new Map(initial ? [[KEY, initial]] : []);
  return { getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, String(v)), m };
};

test('addItem_merges_same_code', () => {
  let l = addItem([], { code: 'A1', description: 'Primo', qty: 2 });
  l = addItem(l, { code: ' A1 ', description: 'Altro', qty: 3 });
  assert.deepEqual(l, [{ code: 'A1', description: 'Primo', qty: 5 }]);
  l = addItem(l, { code: 'a1', description: 'Minuscolo', qty: 1 });
  assert.equal(l.length, 2);
});

test('addItem_rejects_zero_negative_decimal_empty', () => {
  const base = [{ code: 'A1', description: 'x', qty: 1 }];
  for (const q of [0, -2, 1.5, '', null, undefined, NaN, '3']) {
    assert.deepEqual(addItem(base, { code: 'B', description: 'y', qty: q }), base);
    assert.deepEqual(setQty(base, 'A1', q), base);
  }
});

test('isValidQty', () => {
  assert.equal(isValidQty(1), true);
  assert.equal(isValidQty(40), true);
  for (const q of [0, -2, 1.5, '', '3', null, undefined, NaN, Infinity]) assert.equal(isValidQty(q), false);
});

test('addItem_does_not_mutate_input', () => {
  const base = [{ code: 'A1', description: 'x', qty: 1 }];
  const snap = JSON.stringify(base);
  const out = addItem(base, { code: 'A1', description: 'x', qty: 2 });
  assert.equal(JSON.stringify(base), snap);
  assert.notEqual(out, base);
  setQty(base, 'A1', 9); removeItem(base, 'A1');
  assert.equal(JSON.stringify(base), snap);
});

test('setQty_and_removeItem', () => {
  const base = [{ code: 'A1', description: 'x', qty: 1 }, { code: 'B', description: 'y', qty: 2 }];
  assert.equal(setQty(base, 'B', 7)[1].qty, 7);
  assert.deepEqual(removeItem(base, 'A1'), [base[1]]);
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
  assert.deepEqual(load(mem(raw)), [{ code: 'A', description: 'd', qty: 2 }]);
});

test('save_roundtrip_and_swallows_errors', () => {
  const s = mem();
  const l = [{ code: 'A', description: 'd', qty: 2 }];
  save(s, l);
  assert.deepEqual(load(s), l);
  assert.doesNotThrow(() => save({ setItem() { throw new Error('quota'); } }, l));
  assert.doesNotThrow(() => save(undefined, l));
  assert.doesNotThrow(() => save(null, l));
});

const form = { name: 'Mario', company: 'ACME', email: 'm@a.it', phone: '123', country: 'IT', notes: 'ciao' };

test('emailBody_one_line_per_code', () => {
  const l = [{ code: 'A1', description: 'Tubo', qty: 2 }, { code: 'B2', description: 'Raccordo', qty: 10 }];
  const lines = emailBody(l, form).split('\n');
  assert.ok(lines.includes('A1 | Tubo | q.tà: 2'));
  assert.ok(lines.includes('B2 | Raccordo | q.tà: 10'));
  assert.equal(lines.filter((x) => x.includes(' | q.tà: ')).length, 2);
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
  assert.ok(body.includes('A1 | Tubo <i>&" | q.tà: 1'));
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
  const l = [{ code: 'A1', description: 'd', qty: 99999 }];
  assert.equal(canAdd(l, 'A1', 1), false);
  assert.equal(canAdd(l, ' A1 ', 1), false);
  assert.equal(canAdd([{ code: 'A1', description: 'd', qty: 50000 }], 'A1', 50000), false);
  assert.equal(canAdd([], 'B', 100000), false);
});

test('canAdd_true_up_to_exactly_max', () => {
  assert.equal(canAdd([{ code: 'A1', description: 'd', qty: 99998 }], 'A1', 1), true);
  assert.equal(canAdd([{ code: 'A1', description: 'd', qty: 99999 }], 'B2', 99999), true);
  assert.equal(canAdd([], 'A1', 99999), true);
});

test('load_drops_items_over_qty_max', () => {
  const raw = JSON.stringify([
    { code: 'A', description: 'd', qty: 100000 },
    { code: 'B', description: 'e', qty: 99999 },
  ]);
  assert.deepEqual(load(mem(raw)), [{ code: 'B', description: 'e', qty: 99999 }]);
});
