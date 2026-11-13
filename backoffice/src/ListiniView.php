<?php
declare(strict_types=1);

namespace Backoffice;

/** Schermata dei listini riservati: elenco con download; per l'amministratore, caricamento e rimozione. */
final class ListiniView
{
    /** @param array<int, array{id:string, item:array}> $rows */
    public static function page(bool $admin, array $rows, string $csrf, string $self): string
    {
        $e = App::e(...);
        $h = '<p class="meta">Listini con i prezzi, riservati ai commerciali. Ogni scaricamento viene registrato.</p>';
        $h .= '<table class="list"><tr><th>Listino</th><th>Lingua</th><th>Caricato</th><th></th></tr>';
        foreach ($rows as $r) {
            $it = $r['item'];
            $h .= '<tr><td>' . $e($it['titolo']) . '</td><td>' . $e(Listini::LANGS[$it['lingua']] ?? $it['lingua']) . '</td>'
                . '<td>' . $e(date('d/m/Y', strtotime((string) $it['caricato']))) . '</td>'
                . '<td><a href="index.php?p=listino_file&amp;id=' . $e(urlencode($r['id'])) . '">Scarica PDF</a>';
            if ($admin) {
                $h .= ' · <details class="inline"><summary>Togli</summary><form method="post" action="' . $e($self) . '">'
                    . '<input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="action" value="remove">'
                    . '<input type="hidden" name="id" value="' . $e($r['id']) . '"><button class="btn dark">Sì, togli dall\'area riservata</button></form></details>';
            }
            $h .= '</td></tr>';
        }
        if (!$rows) {
            $h .= '<tr><td colspan="4" class="empty">Nessun listino caricato.</td></tr>';
        }
        $h .= '</table>';
        if ($admin) {
            $h .= '<section class="card narrow"><h2>Carica un listino</h2>'
                . '<form method="post" action="' . $e($self) . '" enctype="multipart/form-data">'
                . '<input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="action" value="upload">'
                . '<label>File PDF (massimo 20 MB)<input type="file" name="pdf" accept="application/pdf,.pdf" required></label>'
                . '<label>Titolo<input name="titolo" maxlength="150" required placeholder="es. Listino Agricoltura 2026"></label>'
                . '<label>Lingua<select name="lingua">';
            foreach (Listini::LANGS as $k => $label) {
                $h .= '<option value="' . $e($k) . '">' . $e($label) . '</option>';
            }
            $h .= '</select></label><button class="btn">Carica e rendi disponibile</button></form></section>';
        }
        return $h;
    }
}
