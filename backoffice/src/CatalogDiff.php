<?php
declare(strict_types=1);

namespace Backoffice;

/**
 * Confronto tra un catalogo IDML (italiano + inglese) e il catalogo del sito (seed/catalogo.json, copia di data/catalog.json).
 * Sola lettura: non scrive nulla. Gemello di tools/idml_diff.py.
 * Le lingue restano indipendenti: i punti delle caratteristiche non si abbinano uno a uno (decisione del 25 novembre 2026).
 */
final class CatalogDiff
{
    public static function catalog(): array
    {
        $f = dirname(__DIR__) . '/seed/catalogo.json';
        $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        return is_array($d) && isset($d['lines']) ? $d : ['lines' => []];
    }

    /** @return array<string,string> slug => nome della linea */
    public static function lines(): array
    {
        $out = [];
        foreach (self::catalog()['lines'] as $l) {
            $out[$l['slug']] = (string) ($l['name']['it'] ?? $l['slug']);
        }
        return $out;
    }

    private static function norm(string $s): string
    {
        return implode(' ', preg_split('~\s+~u', mb_strtolower(trim($s), 'UTF-8')) ?: []);
    }

    /**
     * @return array{rows:int, distinct:int, known:int, new:array, gone:array, changed:array, ean:int, no_en:int,
     *               problems:string[], cards:int, products:int, feat_same:int, feat_changed:array, feat_en:int, card_problems:string[]}
     */
    public static function compare(Idml $it, Idml $en, string $line): array
    {
        $ln = null;
        foreach (self::catalog()['lines'] as $l) {
            if ($l['slug'] === $line) {
                $ln = $l;
            }
        }
        if ($ln === null) {
            throw new \InvalidArgumentException('Linea sconosciuta.');
        }
        $known = [];
        $prods = [];
        foreach ($ln['families'] as $fam) {
            foreach ($fam['products'] as $p) {
                $prods[$p['slug']] = $p;
                foreach ($p['articles'] as $a) {
                    $known[$a['code']] ??= [$p['slug'], $a];
                }
            }
        }
        [$pairs, $problems] = Idml::pair($it->rows(), $en->rows());

        $new = [];
        $changed = [];
        $seen = [];
        $ean = 0;
        $noEn = 0;
        foreach ($pairs as $n => [$r, $e]) {
            $noEn += $e === null ? 1 : 0;
            $ean += $r['ean'] !== '' ? 1 : 0;
            if (isset($seen[$r['code']])) {
                continue;
            }
            $seen[$r['code']] = true;
            if (!isset($known[$r['code']])) {
                $new[] = ['code' => $r['code'], 'it' => $r['description'], 'en' => $e['description'] ?? null];
                continue;
            }
            [$slug, $a] = $known[$r['code']];
            if (self::norm($a['description']['it'] ?? '') !== self::norm($r['description'])) {
                $changed[] = ['code' => $r['code'], 'product' => $slug, 'field' => 'descrizione', 'old' => $a['description']['it'] ?? '', 'new' => $r['description']];
            }
            foreach ([['conf', $a['conf'] ?? null, $r['conf']], ['box', $a['imb'] ?? null, $r['box']]] as [$f, $old, $cur]) {
                if (($old ?: null) !== $cur) {
                    $changed[] = ['code' => $r['code'], 'product' => $slug, 'field' => $f, 'old' => (string) ($old ?? '—'), 'new' => (string) ($cur ?? '—')];
                }
            }
        }
        $gone = array_values(array_diff(array_keys($known), array_keys($seen)));
        sort($gone);

        $names = [];
        foreach ($prods as $slug => $p) {
            $names[$slug] = (string) $p['name']['it'];
        }
        [$cards, $cardProblems] = Idml::cards($it, $en, $names);
        $same = 0;
        $featChanged = [];
        $featEn = 0;
        foreach ($cards as $slug => $c) {
            $old = array_map([self::class, 'norm'], $prods[$slug]['features'] ?? []);
            if ($old === array_map([self::class, 'norm'], $c['features_it'])) {
                $same++;
            } else {
                $featChanged[] = ['product' => $slug, 'old' => count($old), 'new' => count($c['features_it'])];
            }
            $featEn += $c['features_en'] ? 1 : 0;
        }
        return [
            'rows' => count($pairs), 'distinct' => count($seen), 'known' => count(array_intersect_key($seen, $known)),
            'new' => $new, 'gone' => array_map(static fn($c) => ['code' => $c, 'product' => $known[$c][0]], $gone),
            'changed' => $changed, 'ean' => $ean, 'no_en' => $noEn, 'problems' => $problems,
            'cards' => count($cards), 'products' => count($prods), 'feat_same' => $same,
            'feat_changed' => $featChanged, 'feat_en' => $featEn, 'card_problems' => $cardProblems,
        ];
    }
}
