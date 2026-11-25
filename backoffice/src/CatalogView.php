<?php
declare(strict_types=1);

namespace Backoffice;

/** Schermata «Catalogo»: confronto con gli IDML (italiano e inglese), approvazione per gruppi, bozza in attesa di «Pubblica». */
final class CatalogView
{
    private static function li(array $items): string
    {
        return $items ? '<ul>' . implode('', array_map(static fn ($t) => '<li>' . $t . '</li>', $items)) . '</ul>' : '<p class="meta">Nessuno.</p>';
    }

    /** Bozza in attesa (per linea) con il pulsante per scartarla. */
    public static function drafts(string $csrf): string
    {
        $e = App::e(...);
        $rows = array_filter(Content::all(CatalogPatch::KIND), static fn ($r) => Content::status($r) !== Content::LIVE && !$r['deleted']);
        if (!$rows) {
            return '';
        }
        $h = '<section class="card"><h2>Bozza del catalogo in attesa di «Pubblica»</h2>';
        foreach ($rows as $r) {
            $p = $r['draft'] + CatalogPatch::empty();
            $name = CatalogDiff::lines()[$r['item_id']] ?? $r['item_id'];
            $h .= '<form method="post" action="index.php?p=catalog" class="inline-form"><input type="hidden" name="csrf" value="' . $e($csrf) . '">'
                . '<input type="hidden" name="action" value="discard"><input type="hidden" name="line" value="' . $e($r['item_id']) . '">'
                . '<p><strong>' . $e($name) . '</strong>: ' . count($p['articles']) . ' articoli corretti, ' . count($p['new']) . ' nuovi, ' . count($p['products']) . ' schede prodotto. '
                . '<button class="btn secondary">Scarta la bozza</button></p></form>';
        }
        return $h . '<p class="meta">Le modifiche arrivano sul sito con «Pubblica» (menu a sinistra).</p></section>';
    }

    public static function form(string $csrf, string $line): string
    {
        $e = App::e(...);
        $opts = '';
        foreach (CatalogDiff::lines() as $slug => $name) {
            $opts .= '<option value="' . $e($slug) . '"' . ($slug === $line ? ' selected' : '') . '>' . $e($name) . '</option>';
        }
        return '<form method="post" action="index.php?p=catalog" enctype="multipart/form-data" class="card narrow">'
            . '<input type="hidden" name="csrf" value="' . $e($csrf) . '">'
            . '<h2>Confronta un catalogo con il sito</h2>'
            . '<p class="meta">Carica i due file IDML di InDesign, italiano e inglese, della stessa linea. Vedi le differenze con quello che c\'è oggi sul sito '
            . 'e scegli quali accettare. Le modifiche restano in bozza finché non pubblichi.</p>'
            . '<label>Linea<select name="line">' . $opts . '</select></label>'
            . '<label>IDML italiano<input type="file" name="idml_it" accept=".idml" required></label>'
            . '<label>IDML inglese<input type="file" name="idml_en" accept=".idml" required></label>'
            . '<button class="btn">Confronta</button></form>';
    }

    /** Differenze e gruppi da approvare. */
    public static function report(array $r, string $csrf, string $line): string
    {
        $e = App::e(...);
        $h = '<section class="card"><h2>Riepilogo</h2><ul>'
            . '<li>Righe lette: <strong>' . $r['rows'] . '</strong> (italiano e inglese abbinate riga per riga); articoli distinti: ' . $r['distinct'] . ', già nel sito: ' . $r['known'] . '</li>'
            . '<li>Tolti dal catalogo: <strong>' . count($r['gone']) . '</strong> (non cambiano il sito) · righe con codice EAN: ' . $r['ean'] . ' (già inseriti nei dati, non mostrati)</li>'
            . '<li>Schede prodotto trovate: ' . $r['cards'] . ' su ' . $r['products'] . '; caratteristiche italiane uguali al sito: ' . $r['feat_same'] . '</li>'
            . '</ul></section>';
        if ($r['problems'] || $r['card_problems']) {
            $h .= '<section class="card"><h2>Da controllare</h2>' . self::li(array_map($e, array_merge($r['problems'], $r['card_problems']))) . '</section>';
        }
        $h .= '<form method="post" action="index.php?p=catalog"><input type="hidden" name="csrf" value="' . $e($csrf) . '">'
            . '<input type="hidden" name="action" value="approve"><input type="hidden" name="line" value="' . $e($line) . '">';
        $group = static function (string $key, int $n, string $body) use ($e): string {
            if ($n === 0) {
                return '<section class="card"><h2>' . $e(CatalogPatch::GROUPS[$key]) . '</h2><p class="meta">Nessuna differenza.</p></section>';
            }
            return '<section class="card"><h2>' . $e(CatalogPatch::GROUPS[$key]) . ' (' . $n . ')</h2>'
                . '<label class="check"><input type="checkbox" name="g[]" value="' . $e($key) . '" checked> Accetta tutto questo gruppo</label>' . $body . '</section>';
        };
        $cut = static fn (array $items): array => array_slice($items, 0, 60);
        $more = static fn (array $items): string => count($items) > 60 ? '<p class="meta">… e altri ' . (count($items) - 60) . '.</p>' : '';

        // articoli nuovi: scheda di destinazione modificabile
        $newItems = [];
        foreach ($r['new'] as $n) {
            $sel = '<select name="dest[' . $e($n['code']) . ']" aria-label="Scheda di ' . $e($n['code']) . '">';
            foreach ($r['product_names'] as $slug => $name) {
                $sel .= '<option value="' . $e($slug) . '"' . ($slug === $n['product'] ? ' selected' : '') . '>' . $e($name) . '</option>';
            }
            $newItems[] = '<code>' . $e($n['code']) . '</code> ' . $e($n['it']) . ' — EN: ' . $e($n['en'] ?? '—') . ' — nella scheda: ' . $sel . '</select>';
        }
        $h .= $group('new', count($r['new']), self::li($newItems));
        $desc = array_filter($r['changed'], static fn ($c) => $c['field'] === 'descrizione');
        $h .= $group('desc_it', count($desc), self::li(array_map(
            fn ($c) => '<code>' . $e($c['code']) . '</code> «' . $e($c['old']) . '» → «' . $e($c['new']) . '»', $cut($desc))) . $more($desc));
        $h .= $group('dati', count($r['dati']), self::li(array_map(
            fn ($c) => '<code>' . $e($c['code']) . '</code> ' . $e($c['field'] === 'imb' ? 'box' : 'conf.') . ': ' . $e($c['old']) . ' → ' . $e($c['new']), $cut($r['dati']))) . $more($r['dati']));
        $h .= $group('feat_it', count($r['feat_changed']), self::li(array_map(
            fn ($c) => $e($r['product_names'][$c['product']] ?? $c['product']) . ': ' . count($c['old']) . ' → ' . count($c['new']) . ' punti', $r['feat_changed'])));
        $h .= $group('desc_en', count($r['desc_en']), self::li(array_map(
            fn ($c) => '<code>' . $e($c['code']) . '</code> ' . ($c['old'] !== '' ? '«' . $e($c['old']) . '» → ' : 'nuova: ') . '«' . $e($c['new']) . '»', $cut($r['desc_en']))) . $more($r['desc_en']));
        $h .= $group('prod_en', count($r['prod_en']), self::li(array_map(
            fn ($c) => $e($r['product_names'][$c['product']] ?? $c['product']) . ': '
                . ($c['name_new'] !== null ? 'nome «' . $e($c['name_old']) . '» → «' . $e($c['name_new']) . '»' : 'nome invariato')
                . ($c['feat_new'] !== null ? ', ' . count($c['feat_new']) . ' caratteristiche in inglese' : ''), $cut($r['prod_en']))) . $more($r['prod_en']));
        return $h . '<p><button class="btn">Salva i gruppi scelti come bozza</button> <span class="meta">Il sito non cambia finché non premi «Pubblica».</span></p></form>';
    }
}
