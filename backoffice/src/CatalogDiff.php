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
    /** Il catalogo del sito con le bozze già approvate (seed + patch). */
    public static function catalog(): array
    {
        return CatalogPatch::effective()[0];
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
     * Confronto e proposta di modifiche (gruppi da approvare).
     * @return array<string,mixed>
     */
    public static function compare(Idml $it, Idml $en, string $line): array
    {
        [$catalog, $enP, $enA] = CatalogPatch::effective();
        $ln = null;
        foreach ($catalog['lines'] as $l) {
            if ($l['slug'] === $line) {
                $ln = $l;
            }
        }
        if ($ln === null) {
            throw new \InvalidArgumentException('Linea sconosciuta.');
        }
        $known = [];
        $prods = [];
        $family = [];   // prime 4 cifre del codice => [slug => quanti articoli], per proporre la scheda dei nuovi
        foreach ($ln['families'] as $fam) {
            foreach ($fam['products'] as $p) {
                $prods[$p['slug']] = $p;
                foreach ($p['articles'] as $a) {
                    $known[$a['code']] ??= [$p['slug'], $a];
                    $k = substr($a['code'], 0, 4);
                    $family[$k][$p['slug']] = ($family[$k][$p['slug']] ?? 0) + 1;
                }
            }
        }
        [$pairs, $problems] = Idml::pair($it->rows(), $en->rows());
        $enNow = static fn (string $code): string => (string) ($enA['articoli'][$code] ?? $enA['tradotti_da_noi'][$code] ?? '');

        $new = [];
        $changed = [];
        $dati = [];
        $descEn = [];
        $seen = [];
        $ean = 0;
        $noEn = 0;
        foreach ($pairs as [$r, $e]) {
            $noEn += $e === null ? 1 : 0;
            $ean += $r['ean'] !== '' ? 1 : 0;
            if (isset($seen[$r['code']])) {
                continue;
            }
            $seen[$r['code']] = true;
            $enText = $e['description'] ?? '';
            if (!isset($known[$r['code']])) {
                $fam = $family[substr($r['code'], 0, 4)] ?? [];
                arsort($fam);
                $new[] = ['code' => $r['code'], 'it' => $r['description'], 'en' => $e['description'] ?? null, 'conf' => $r['conf'], 'imb' => $r['box'],
                    'ean' => $r['ean'], 'kind' => $r['spare'] ? 'spare' : 'article', 'product' => (string) (array_key_first($fam) ?? '')];
                continue;
            }
            [$slug, $a] = $known[$r['code']];
            if (self::norm($a['description']['it'] ?? '') !== self::norm($r['description'])) {
                $changed[] = ['code' => $r['code'], 'product' => $slug, 'field' => 'descrizione', 'old' => $a['description']['it'] ?? '', 'new' => $r['description']];
            }
            foreach ([['conf', $a['conf'] ?? null, $r['conf']], ['imb', $a['imb'] ?? null, $r['box']]] as [$f, $old, $cur]) {
                if (($old ?: null) !== $cur) {
                    $dati[] = ['code' => $r['code'], 'product' => $slug, 'field' => $f, 'old' => (string) ($old ?? '—'), 'new' => (string) ($cur ?? '—'), 'val' => $cur];
                }
            }
            if ($enText !== '' && self::norm($enNow($r['code'])) !== self::norm($enText)) {
                $descEn[] = ['code' => $r['code'], 'old' => $enNow($r['code']), 'new' => $enText];
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
        $featIt = [];
        $prodEn = [];
        foreach ($cards as $slug => $c) {
            if ($c['ambiguous']) {
                continue; // pagina con più prodotti: non si propone nulla in automatico
            }
            $old = $prods[$slug]['features'] ?? [];
            if (array_map([self::class, 'norm'], $old) === array_map([self::class, 'norm'], $c['features_it'])) {
                $same++;
            } else {
                $featIt[] = ['product' => $slug, 'old' => $old, 'new' => $c['features_it']];
            }
            $cur = $enP['prodotti'][$slug] ?? [];
            $nameNew = (string) ($c['title_en'] ?? '');
            $nameDiff = $nameNew !== '' && self::norm((string) ($cur['nome_en'] ?? '')) !== self::norm($nameNew);
            $featDiff = $c['features_en'] && array_map([self::class, 'norm'], $cur['features_en'] ?? []) !== array_map([self::class, 'norm'], $c['features_en']);
            if ($nameDiff || $featDiff) {
                $prodEn[] = ['product' => $slug, 'name_old' => (string) ($cur['nome_en'] ?? ''), 'name_new' => $nameDiff ? $nameNew : null,
                    'feat_old' => $cur['features_en'] ?? [], 'feat_new' => $featDiff ? $c['features_en'] : null];
            }
        }
        return [
            'rows' => count($pairs), 'distinct' => count($seen), 'known' => count(array_intersect_key($seen, $known)),
            'new' => $new, 'gone' => array_map(static fn ($c) => ['code' => $c, 'product' => $known[$c][0]], $gone),
            'changed' => $changed, 'dati' => $dati, 'desc_en' => $descEn, 'ean' => $ean, 'no_en' => $noEn, 'problems' => $problems,
            'cards' => count($cards), 'products' => count($prods), 'feat_same' => $same,
            'feat_changed' => $featIt, 'prod_en' => $prodEn, 'feat_en' => count(array_filter($cards, static fn ($c) => $c['features_en'])), 'card_problems' => $cardProblems,
            'product_names' => array_map(static fn ($p) => (string) $p['name']['it'], $prods),
        ];
    }
}
