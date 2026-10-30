import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildIndex, search } from '../site/static/js/search.js';

const ARTICLES = [
  { code: '1807-1351', description: 'Coprip Prisma 7x7 (senza viteria)', url: '/a/1807-1351/' },
  { code: '1807-1310GZ', description: 'Coprip Prisma 5x5 con Viteria zincata', url: '/a/1807-1310gz/' },
  { code: '2801-0404', description: 'Tappo è quadro 40x40', url: '/a/2801-0404/' },
  { code: '2801-0505', url: '/a/2801-0505/' },
];
const idx = () => buildIndex(ARTICLES);
const codes = (r) => r.map((x) => x.code);

test('finds_code_with_and_without_dash', () => {
  assert.deepEqual(codes(search(idx(), '18071351')), ['1807-1351']);
  assert.deepEqual(codes(search(idx(), '1807-1351')), ['1807-1351']);
  assert.deepEqual(codes(search(idx(), '1807-135')), ['1807-1351']);
  assert.deepEqual(codes(search(idx(), '1807-1310gz')), ['1807-1310GZ']);
});

test('finds_by_description_word', () => {
  assert.deepEqual(codes(search(idx(), 'prisma')), ['1807-1351', '1807-1310GZ']);
  assert.deepEqual(codes(search(idx(), 'prisma 7x7')), ['1807-1351']);
  assert.deepEqual(codes(search(idx(), 'tapp')), ['2801-0404']);
});

test('empty_query_returns_empty', () => {
  assert.deepEqual(search(idx(), ''), []);
  assert.deepEqual(search(idx(), '   '), []);
  assert.deepEqual(search(idx(), 'a'), []);
  assert.deepEqual(search(idx(), undefined), []);
  assert.deepEqual(search(idx(), 42), []);
  assert.deepEqual(search(idx(), null), []);
});

test('respects_limit', () => {
  assert.equal(search(idx(), 'coprip', 1).length, 1);
  assert.equal(search(idx(), '18', 1).length, 1);
  assert.deepEqual(search(idx(), 'coprip', 0), []);
  assert.deepEqual(search(idx(), 'coprip', -1), []);
  const big = buildIndex(Array.from({ length: 50 }, (_, i) => ({ code: `1000-${i}`, description: 'x', url: `/${i}` })));
  assert.equal(search(big, '1000').length, 20);
});

test('case_and_accent_insensitive', () => {
  assert.deepEqual(codes(search(idx(), 'VITERIA')), ['1807-1351', '1807-1310GZ']);
  assert.deepEqual(codes(search(idx(), 'e quadro')), ['2801-0404']);
  assert.deepEqual(codes(search(idx(), 'TAPPO È')), ['2801-0404']);
});

test('query_is_trimmed_and_result_shape', () => {
  const r = search(idx(), '  1807-1351  ');
  assert.deepEqual(r, [ARTICLES[0]]);
});

test('code_matches_rank_before_description_matches', () => {
  const i = buildIndex([
    { code: '9999-0001', description: 'Vite 1807 speciale', url: '/d' },
    { code: '1807-0001', description: 'Altro', url: '/c' },
  ]);
  assert.deepEqual(codes(search(i, '1807')), ['1807-0001', '9999-0001']);
});

test('all_words_must_match', () => {
  assert.deepEqual(search(idx(), 'prisma tappo'), []);
  assert.deepEqual(codes(search(idx(), 'senza viteria')), ['1807-1351']);
});

test('no_duplicates_and_stable_order', () => {
  const i = buildIndex([
    { code: '1234-5', description: '1234 uno', url: '/x' },
    { code: '1234-6', description: 'due', url: '/y' },
    { code: '1234-7', description: 'tre', url: '/z' },
  ]);
  assert.deepEqual(codes(search(i, '1234')), ['1234-5', '1234-6', '1234-7']);
});

test('missing_description_searchable_by_code', () => {
  assert.deepEqual(codes(search(idx(), '2801-0505')), ['2801-0505']);
  assert.deepEqual(codes(search(buildIndex(undefined), 'abc')), []);
});

const MIXED = [
  { code: '1807-0751', description: 'Vite', url: '/p1/#1807-0751', products: ['Copripalo Prisma', 'Copripalo Qubo'] },
  { code: '1807-0752', description: 'Vite lunga', url: '/p1/#1807-0752', products: ['Copripalo Prisma'] },
  { name: 'Copripalo Prisma', kind: 'product', parent: 'Copripalo · Agricoltura', url: '/p1/' },
  { name: 'Espositori', kind: 'family', parent: 'Agricoltura', url: '/f2/' },
  { name: 'Gilet alta visibilita', kind: 'product', parent: 'Abbigliamento · Garden', url: '/p3/' },
];
const mixed = () => buildIndex(MIXED);

test('finds_products_and_families_by_name', () => {
  assert.deepEqual(search(mixed(), 'copripalo prisma').map((r) => r.name), ['Copripalo Prisma']);
  assert.deepEqual(search(mixed(), 'espositori').map((r) => r.name), ['Espositori']);
  assert.deepEqual(search(mixed(), 'gilet').map((r) => r.name), ['Gilet alta visibilita']);
  assert.deepEqual(search(mixed(), 'agricoltura').map((r) => r.name).sort(), ['Copripalo Prisma', 'Espositori']);
});

test('code_first_then_names_then_descriptions', () => {
  const i = buildIndex([
    { code: '5000-0001', description: 'Nastro viola', url: '/d' },
    { name: 'Viola', kind: 'family', parent: 'Garden', url: '/f' },
    { code: 'VIOL-1', description: 'altro', url: '/c' },
  ]);
  const r = search(i, 'viol');
  assert.deepEqual(r.map((x) => x.code || x.name), ['VIOL-1', 'Viola', '5000-0001']);
});

test('one_result_per_code_keeps_product_list', () => {
  const r = search(mixed(), '1807-0751');
  assert.equal(r.length, 1);
  assert.deepEqual(r[0].products, ['Copripalo Prisma', 'Copripalo Qubo']);
});
