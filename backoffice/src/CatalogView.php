<?php
declare(strict_types=1);

namespace Backoffice;

/** Schermata «Catalogo»: carica i due IDML (italiano e inglese) e mostra le differenze con il sito. Non scrive nulla. */
final class CatalogView
{
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
            . '<p class="meta">Carica i due file IDML di InDesign, italiano e inglese, della stessa linea. Vedi le differenze con quello che c\'è oggi sul sito; '
            . 'per ora non cambia nulla.</p>'
            . '<label>Linea<select name="line">' . $opts . '</select></label>'
            . '<label>IDML italiano<input type="file" name="idml_it" accept=".idml" required></label>'
            . '<label>IDML inglese<input type="file" name="idml_en" accept=".idml" required></label>'
            . '<button class="btn">Confronta</button></form>';
    }

    public static function report(array $r): string
    {
        $e = App::e(...);
        $li = static fn (array $items): string => $items ? '<ul>' . implode('', array_map(static fn ($t) => '<li>' . $t . '</li>', $items)) . '</ul>' : '<p class="meta">Nessuno.</p>';
        $h = '<section class="card"><h2>Riepilogo</h2><ul>'
            . '<li>Righe lette: <strong>' . $r['rows'] . '</strong> (italiano e inglese abbinate riga per riga)</li>'
            . '<li>Articoli distinti: ' . $r['distinct'] . ', già nel sito: ' . $r['known'] . '</li>'
            . '<li><strong>Nuovi: ' . count($r['new']) . '</strong> · <strong>tolti dal catalogo: ' . count($r['gone']) . '</strong> · <strong>dati o descrizioni cambiati: ' . count($r['changed']) . '</strong></li>'
            . '<li>Righe con codice EAN (oggi assente dal sito): ' . $r['ean'] . '</li>'
            . '<li>Schede prodotto trovate: ' . $r['cards'] . ' su ' . $r['products'] . '; caratteristiche italiane uguali al sito: ' . $r['feat_same'] . ', diverse: ' . count($r['feat_changed']) . '; con testo inglese: ' . $r['feat_en'] . '</li>'
            . '</ul></section>';
        if ($r['problems'] || $r['card_problems']) {
            $h .= '<section class="card"><h2>Da controllare</h2>' . $li(array_map($e, array_merge($r['problems'], $r['card_problems']))) . '</section>';
        }
        $h .= '<section class="card"><h2>Articoli nuovi</h2>' . $li(array_map(
            fn ($n) => '<code>' . $e($n['code']) . '</code> ' . $e($n['it']) . ' — EN: ' . $e($n['en'] ?? '—'), $r['new'])) . '</section>';
        $h .= '<section class="card"><h2>Articoli del sito non più nel catalogo</h2>' . $li(array_map(
            fn ($g) => '<code>' . $e($g['code']) . '</code> (' . $e($g['product']) . ')', $r['gone'])) . '</section>';
        $h .= '<section class="card"><h2>Cambiamenti (sito → catalogo)</h2>' . $li(array_map(
            fn ($c) => '<code>' . $e($c['code']) . '</code> ' . $e($c['field']) . ': «' . $e($c['old']) . '» → «' . $e($c['new']) . '»', array_slice($r['changed'], 0, 200))) . '</section>';
        return $h;
    }
}
