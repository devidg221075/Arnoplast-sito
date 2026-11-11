<?php
declare(strict_types=1);

namespace Backoffice;

/** Schermate delle fiere: elenco (in programma / passate), scheda di modifica con anteprima. */
final class FairsView
{
    private const BADGE = [Content::NEW => 'todo', Content::CHANGED => 'todo', Content::LIVE => 'chiusa', Content::REMOVED => 'nuova'];

    public static function list(string $csrf, string $selfUrl): string
    {
        $e = App::e(...);
        $rows = Content::all(Fairs::KIND);
        $today = date('Y-m-d');
        $upcoming = $past = [];
        foreach ($rows as $r) {
            if (Fairs::isPast($r['draft'], $today)) {
                $past[] = $r;
            } else {
                $upcoming[] = $r;
            }
        }
        usort($upcoming, static fn (array $a, array $b): int => strcmp(Fairs::endDate($a['draft']), Fairs::endDate($b['draft'])));
        usort($past, static fn (array $a, array $b): int => strcmp(Fairs::endDate($b['draft']), Fairs::endDate($a['draft'])));
        $h = '<p><a class="btn" href="index.php?p=fair&amp;new=1">Nuova fiera</a></p>';
        if (!$rows) {
            return $h . '<form method="post" action="' . $e($selfUrl) . '" class="card narrow"><input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="action" value="import">'
                . '<p>Nessuna fiera nel backoffice. Puoi importare quelle che sono già sul sito (' . self::seedCount() . ' schede): restano com\'è, le modifichi dopo.</p><button class="btn dark">Importa le fiere del sito</button></form>';
        }
        foreach ([['In programma', $upcoming], ['Passate', $past]] as [$title, $list]) {
            $h .= '<h2>' . $e($title) . ' (' . count($list) . ')</h2>';
            if (!$list) {
                $h .= '<p class="empty">Nessuna fiera.</p>';
                continue;
            }
            $h .= '<table class="list"><tr><th>Fiera</th><th>Date</th><th>Luogo</th><th>Inglese</th><th>Stato</th></tr>';
            foreach ($list as $r) {
                $d = $r['draft'];
                $st = Content::status($r);
                $en = !empty($d['testo_en']) ? 'sì' : '<span class="bad">da tradurre</span>';
                $h .= '<tr><td><a href="index.php?p=fair&amp;id=' . $e(urlencode((string) $r['item_id'])) . '">' . $e($d['nome'] ?? $r['item_id']) . '</a><br><small>' . $e($r['item_id']) . '</small></td><td>'
                    . $e(Fairs::dates($d)) . '</td><td>' . $e(trim(($d['luogo'] ?? '') . ', ' . ($d['paese'] ?? ''), ', ')) . '</td><td>' . $en . '</td>'
                    . '<td><span class="status ' . self::BADGE[$st] . '">' . $e(ucfirst($st)) . '</span>' . (!empty($r['meta']['translate']) ? ' <small>traduzione richiesta</small>' : '') . '</td></tr>';
            }
            $h .= '</table>';
        }
        return $h . '<p class="meta">Le modifiche restano in bozza: il sito non cambia finché non le pubblichi (disponibile con la prossima tappa). Una fiera con data di fine passata va da sola tra le passate.</p>';
    }

    public static function seedCount(): int
    {
        $f = dirname(__DIR__) . '/seed/fiere.json';
        $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : [];
        return is_array($d) ? count($d['fiere'] ?? []) : 0;
    }

    /** @param ?array $row voce esistente, null per una nuova; @param ?array $raw valori digitati dopo un errore */
    public static function form(?array $row, string $csrf, ?array $raw): string
    {
        $e = App::e(...);
        $d = $raw ?? ($row['draft'] ?? ['paese' => 'Italia', 'paese_en' => 'Italy']);
        $v = static fn (string $k): string => $e((string) ($d[$k] ?? ''));
        $status = $row ? Content::status($row) : Content::NEW;
        $h = '<p><a href="index.php?p=fairs">← Tutte le fiere</a></p>';
        if ($row) {
            $h .= '<p class="meta">Stato: <span class="status ' . self::BADGE[$status] . '">' . $e(ucfirst($status)) . '</span> · identificativo <code>' . $e($row['item_id']) . '</code> (non cambia)</p>';
        }
        $h .= '<form method="post" action="index.php?p=fair' . ($row ? '&amp;id=' . $e(urlencode((string) $row['item_id'])) : '') . '" enctype="multipart/form-data" class="card">'
            . '<input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="action" value="save"><input type="hidden" name="MAX_FILE_SIZE" value="' . Fairs::IMAGE_MAX . '">'
            . '<div class="grid2"><label>Nome della fiera<input name="nome" value="' . $v('nome') . '" maxlength="150" required></label>'
            . '<label>Luogo<input name="luogo" value="' . $v('luogo') . '" maxlength="120" required></label>'
            . '<label>Paese<input name="paese" value="' . $v('paese') . '" maxlength="80" required></label>'
            . '<label>Sito ufficiale della fiera (https://…)<input name="link" value="' . $v('link') . '" maxlength="300" inputmode="url"></label>'
            . '<label>Data di inizio<input type="date" name="data_inizio" value="' . $v('data_inizio') . '"></label>'
            . '<label>Data di fine<input type="date" name="data_fine" value="' . $v('data_fine') . '"></label>'
            . '<label>Periodo, se non hai le date (es. Febbraio 2021)<input name="periodo" value="' . $v('periodo') . '" maxlength="80"></label>'
            . '<label>Anno (serve solo senza date)<input name="anno" value="' . $v('anno') . '" maxlength="4" inputmode="numeric"></label>'
            . '<label>Stand (padiglione e numero)<input name="stand" value="' . $v('stand') . '" maxlength="120"></label>'
            . '<label>Punto focale della foto (facoltativo: center, 30% 50%…)<input name="punto_focale" value="' . $v('punto_focale') . '" maxlength="40"></label></div>'
            . '<label>Testo della scheda<textarea name="testo" rows="5" maxlength="3000">' . $v('testo') . '</textarea></label>'
            . '<label>Descrizione breve per le schede (facoltativa: se manca si usa la prima frase del testo)<textarea name="descrizione_breve" rows="2" maxlength="400">' . $v('descrizione_breve') . '</textarea></label>'
            . '<h2>Foto</h2>' . ((($d['immagine'] ?? '') !== '') ? '<p class="meta">Foto attuale: <code>' . $v('immagine') . '</code> <label class="inline"><input type="checkbox" name="remove_image" value="1"> togli la foto</label></p>' : '<p class="meta">Nessuna foto: nella pagina compare un fondo grafico.</p>')
            . '<label>Carica una foto nuova (JPG, PNG o WebP, almeno 400 × 250 px, entro 6 MB)<input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label>'
            . '<h2>Inglese</h2><p class="meta">Lascia vuoto per usare l\'italiano. Se vuoi che la traduzione la prepari Claude, spunta la casella: la fiera risulta «traduzione richiesta» e la traduzione si fa in chat, non qui.</p>'
            . '<div class="grid2"><label>Luogo (inglese)<input name="luogo_en" value="' . $v('luogo_en') . '" maxlength="120"></label><label>Paese (inglese)<input name="paese_en" value="' . $v('paese_en') . '" maxlength="80"></label>'
            . '<label>Periodo (inglese)<input name="periodo_en" value="' . $v('periodo_en') . '" maxlength="80"></label><label>Stand (inglese)<input name="stand_en" value="' . $v('stand_en') . '" maxlength="120"></label></div>'
            . '<label>Testo (inglese)<textarea name="testo_en" rows="4" maxlength="3000">' . $v('testo_en') . '</textarea></label>'
            . '<label class="inline"><input type="checkbox" name="translate" value="1"' . (!empty($row['meta']['translate']) || !empty($raw['translate']) ? ' checked' : '') . '> Traduzione richiesta a Claude</label>'
            . '<p><button class="btn">Salva bozza</button></p></form>';
        if ($row) {
            $h .= '<div class="card narrow"><h2>Anteprima della scheda</h2>' . self::preview($d) . '</div><div class="actions">';
            if ($row['published'] !== null && $status !== Content::LIVE) {
                $h .= '<form method="post" action="index.php?p=fair&amp;id=' . $e(urlencode((string) $row['item_id'])) . '">' . '<input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="action" value="revert">'
                    . '<button class="btn dark">Annulla le modifiche (torna alla versione pubblicata)</button></form>';
            }
            $h .= '<details><summary>Elimina questa fiera</summary><form method="post" action="index.php?p=fair&amp;id=' . $e(urlencode((string) $row['item_id'])) . '">'
                . '<input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="action" value="delete">'
                . '<p>La fiera sparirà dal sito alla prossima pubblicazione. Fino ad allora puoi ripensarci salvando di nuovo la scheda.</p><button class="btn dark">Sì, elimina</button></form></details></div>';
        }
        return $h;
    }

    /** Scheda come la vede il visitatore (in forma semplice): nome, date, luogo, stand, testo breve. */
    private static function preview(array $d): string
    {
        $e = App::e(...);
        $short = trim((string) ($d['descrizione_breve'] ?? ''));
        if ($short === '') {
            $t = trim((string) preg_replace('/\s+/', ' ', (string) ($d['testo'] ?? '')));
            $short = preg_match('/^(.{1,170}?[.!?])(\s|$)/u', $t, $m) ? $m[1] : mb_substr($t, 0, 170);
        }
        return '<article class="msg"><p class="who">' . $e(Fairs::dates($d)) . ' · ' . $e(trim(($d['luogo'] ?? '') . ', ' . ($d['paese'] ?? ''), ', ')) . '</p><p class="subj">' . $e($d['nome'] ?? '') . '</p>'
            . (($d['stand'] ?? '') !== '' ? '<p class="meta">Stand ' . $e($d['stand']) . '</p>' : '') . '<p>' . $e($short) . '</p>'
            . (Fairs::isPast($d) ? '<p class="meta">Comparirà tra le fiere passate.</p>' : '<p class="meta">Comparirà tra le fiere in programma.</p>') . '</article>';
    }
}
