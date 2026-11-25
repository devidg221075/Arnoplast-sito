<?php
declare(strict_types=1);

namespace Backoffice;

/**
 * Modifiche approvate al catalogo, una bozza per linea (tipo «catalogo» nella tabella content). La bozza è una «patch»:
 *   articles: codice => {it?, en?, conf?, imb?}            articoli già nel sito
 *   new:      [{code, product, it, en, conf, imb, ean, kind}]   articoli nuovi, nella scheda scelta
 *   products: slug => {features?, name_en?, features_en?}   schede prodotto
 * La patch si applica sopra i file del sito (data/catalog.json, data/en_prodotti.json, data/en_articoli.json) ed è
 * idempotente: applicarla due volte dà lo stesso risultato. Così funziona sia sul seed del pacchetto sia sul file
 * attuale del repository (che «Pubblica» legge da GitHub).
 */
final class CatalogPatch
{
    public const KIND = 'catalogo';

    public static function empty(): array
    {
        return ['articles' => [], 'new' => [], 'products' => []];
    }

    private static function seed(string $name): array
    {
        $f = dirname(__DIR__) . '/seed/' . $name;
        $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        return is_array($d) ? $d : [];
    }

    /** Le tre basi del pacchetto (copie dei file del sito). @return array{0:array,1:array,2:array} */
    public static function base(): array
    {
        $cat = self::seed('catalogo.json');
        return [$cat ?: ['lines' => []], self::seed('en_prodotti.json'), self::seed('en_articoli.json')];
    }

    /** Patch di una linea (bozza). */
    public static function draft(string $line): array
    {
        $r = Content::find(self::KIND, $line);
        return $r && !$r['deleted'] ? $r['draft'] + self::empty() : self::empty();
    }

    /** Unisce una patch nuova a quella già in bozza (la nuova prevale sui singoli valori). */
    public static function merge(array $old, array $add): array
    {
        $old += self::empty();
        foreach ($add['articles'] ?? [] as $code => $chg) {
            $old['articles'][$code] = ($chg + ($old['articles'][$code] ?? []));
        }
        $codes = array_column($old['new'], 'code');
        foreach ($add['new'] ?? [] as $n) {
            $i = array_search($n['code'], $codes, true);
            if ($i === false) {
                $old['new'][] = $n;
            } else {
                $old['new'][$i] = $n;
            }
        }
        foreach ($add['products'] ?? [] as $slug => $chg) {
            $old['products'][$slug] = ($chg + ($old['products'][$slug] ?? []));
        }
        return $old;
    }

    /** Applica una patch alle tre strutture dati di una linea. Restituisce quelle modificate. */
    public static function apply(array $cat, array $enP, array $enA, string $line, array $patch): array
    {
        $patch += self::empty();
        foreach ($cat['lines'] as $li => $ln) {
            if ($ln['slug'] !== $line) {
                continue;
            }
            $has = [];
            foreach ($ln['families'] as $fi => $fam) {
                foreach ($fam['products'] as $pi => $p) {
                    $slug = $p['slug'];
                    if (isset($patch['products'][$slug]['features'])) {
                        $cat['lines'][$li]['families'][$fi]['products'][$pi]['features'] = array_values($patch['products'][$slug]['features']);
                    }
                    foreach ($p['articles'] as $ai => $a) {
                        $has[$a['code']] = true;
                        $c = $patch['articles'][$a['code']] ?? null;
                        if (!$c) {
                            continue;
                        }
                        $ref = &$cat['lines'][$li]['families'][$fi]['products'][$pi]['articles'][$ai];
                        if (isset($c['it'])) {
                            $ref['description']['it'] = $c['it'];
                        }
                        foreach (['conf', 'imb'] as $f) {
                            if (array_key_exists($f, $c)) {
                                $ref[$f] = $c[$f];
                            }
                        }
                        unset($ref);
                    }
                }
            }
            foreach ($patch['new'] as $n) {
                if (isset($has[$n['code']])) {
                    continue;
                }
                foreach ($ln['families'] as $fi => $fam) {
                    foreach ($fam['products'] as $pi => $p) {
                        if ($p['slug'] !== $n['product']) {
                            continue;
                        }
                        $art = ['code' => $n['code'], 'description' => ['it' => $n['it']], 'conf' => $n['conf'] ?? null, 'imb' => $n['imb'] ?? null, 'pallet' => null, 'kind' => $n['kind'] ?? 'article'];
                        if (!empty($n['ean'])) {
                            $art['ean'] = $n['ean'];
                        }
                        $cat['lines'][$li]['families'][$fi]['products'][$pi]['articles'][] = $art;
                        $has[$n['code']] = true;
                    }
                }
            }
        }
        // Inglese: descrizioni articoli (anche dei nuovi) e schede prodotto.
        $en = [];
        foreach ($patch['articles'] as $code => $c) {
            if (isset($c['en'])) {
                $en[$code] = $c['en'];
            }
        }
        foreach ($patch['new'] as $n) {
            if (!empty($n['en'])) {
                $en[$n['code']] = $n['en'];
            }
        }
        foreach ($en as $code => $text) {
            $enA['articoli'][$code] = $text;
            unset($enA['tradotti_da_noi'][$code]);
            if (isset($enA['mancanti']) && is_array($enA['mancanti'])) {
                $enA['mancanti'] = array_values(array_filter($enA['mancanti'], static fn ($m) => $m !== $code));
            }
        }
        foreach ($patch['products'] as $slug => $c) {
            if (!isset($c['name_en']) && !isset($c['features_en'])) {
                continue;
            }
            $cur = $enP['prodotti'][$slug] ?? ['codice' => self::firstCode($cat, $slug), 'nome_en' => '', 'features_en' => [], 'dimensions_en' => []];
            if (isset($c['name_en'])) {
                $cur['nome_en'] = $c['name_en'];
            }
            if (isset($c['features_en'])) {
                $cur['features_en'] = array_values($c['features_en']);
            }
            $enP['prodotti'][$slug] = $cur;
        }
        return [$cat, $enP, $enA];
    }

    /** Gruppi di modifiche che l'amministratore può approvare, con il nome da mostrare. */
    public const GROUPS = [
        'desc_it' => 'Descrizioni italiane corrette',
        'dati' => 'Confezioni e box cambiati',
        'new' => 'Articoli nuovi',
        'desc_en' => 'Descrizioni inglesi degli articoli',
        'feat_it' => 'Caratteristiche italiane cambiate',
        'prod_en' => 'Nomi e caratteristiche inglesi dei prodotti',
    ];

    /**
     * Patch dai gruppi approvati di un confronto.
     * @param array<string,mixed> $report risultato di CatalogDiff::compare
     * @param string[] $groups gruppi approvati (chiavi di GROUPS)
     * @param array<string,string> $dest articoli nuovi: codice => scheda scelta
     */
    public static function fromReport(array $report, array $groups, array $dest = []): array
    {
        $patch = self::empty();
        $on = array_flip($groups);
        if (isset($on['desc_it'])) {
            foreach ($report['changed'] as $c) {
                if ($c['field'] === 'descrizione') {
                    $patch['articles'][$c['code']]['it'] = $c['new'];
                }
            }
        }
        if (isset($on['dati'])) {
            foreach ($report['dati'] as $c) {
                $patch['articles'][$c['code']][$c['field']] = $c['val'];
            }
        }
        if (isset($on['desc_en'])) {
            foreach ($report['desc_en'] as $c) {
                $patch['articles'][$c['code']]['en'] = $c['new'];
            }
        }
        if (isset($on['new'])) {
            foreach ($report['new'] as $n) {
                $slug = (string) ($dest[$n['code']] ?? $n['product']);
                if (isset($report['product_names'][$slug])) {
                    $patch['new'][] = ['code' => $n['code'], 'product' => $slug, 'it' => $n['it'], 'en' => $n['en'], 'conf' => $n['conf'],
                        'imb' => $n['imb'], 'ean' => $n['ean'], 'kind' => $n['kind']];
                }
            }
        }
        if (isset($on['feat_it'])) {
            foreach ($report['feat_changed'] as $c) {
                $patch['products'][$c['product']]['features'] = $c['new'];
            }
        }
        if (isset($on['prod_en'])) {
            foreach ($report['prod_en'] as $c) {
                if ($c['name_new'] !== null) {
                    $patch['products'][$c['product']]['name_en'] = $c['name_new'];
                }
                if ($c['feat_new'] !== null) {
                    $patch['products'][$c['product']]['features_en'] = $c['feat_new'];
                }
            }
        }
        return $patch;
    }

    private static function firstCode(array $cat, string $slug): string
    {
        foreach ($cat['lines'] as $ln) {
            foreach ($ln['families'] as $fam) {
                foreach ($fam['products'] as $p) {
                    if ($p['slug'] === $slug) {
                        return (string) ($p['articles'][0]['code'] ?? '');
                    }
                }
            }
        }
        return '';
    }

    /** Le tre strutture «come sono con le bozze»: base del pacchetto + patch di ogni linea. */
    public static function effective(): array
    {
        [$cat, $enP, $enA] = self::base();
        foreach (Content::all(self::KIND) as $r) {
            if (!$r['deleted']) {
                [$cat, $enP, $enA] = self::apply($cat, $enP, $enA, (string) $r['item_id'], $r['draft'] + self::empty());
            }
        }
        return [$cat, $enP, $enA];
    }

    /** Modifiche al catalogo in bozza, non ancora pubblicate (articoli, articoli nuovi, schede). */
    public static function pendingCount(): int
    {
        $n = 0;
        foreach (Content::all(self::KIND) as $r) {
            if (Content::status($r) !== Content::LIVE) {
                $n += self::count($r['draft'] + self::empty());
            }
        }
        return $n;
    }

    public static function count(array $patch): int
    {
        return count($patch['articles']) + count($patch['new']) + count($patch['products']);
    }
}
