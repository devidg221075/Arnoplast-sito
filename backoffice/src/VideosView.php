<?php
declare(strict_types=1);

namespace Backoffice;

/** Schermate di Video e Tutorial: elenco con filtro per gruppo, modulo per aggiungere, scheda di un video. */
final class VideosView
{
    public static function list(string $csrf, string $group, array $rows, ?array $raw): string
    {
        $e = App::e(...);
        $h = '<form method="get" action="index.php" class="filters"><input type="hidden" name="p" value="videos">'
            . '<label>Gruppo<select name="g"><option value="">Tutti</option>';
        foreach (Videos::GROUPS as $k => $label) {
            $h .= '<option value="' . $e($k) . '"' . ($group === $k ? ' selected' : '') . '>' . $e($label) . '</option>';
        }
        $h .= '</select></label><button class="btn">Mostra</button></form>';

        $shown = array_filter($rows, static fn (array $r): bool => $group === '' || $r['item']['gruppo'] === $group);
        $h .= '<table class="list"><tr><th>Gruppo</th><th>Titolo</th><th>Link</th><th>Stato</th><th></th></tr>';
        foreach ($shown as $r) {
            $it = $r['item'];
            $h .= '<tr><td>' . $e(Videos::GROUPS[$it['gruppo']] ?? $it['gruppo']) . '</td><td>' . $e($it['titolo']) . '</td>'
                . '<td><a href="' . $e(Videos::url($it)) . '" target="_blank" rel="noopener">' . ($it['servizio'] === 'vimeo' ? 'Vimeo' : 'YouTube') . '</a></td>'
                . '<td>' . self::badge($r['status']) . '</td>'
                . '<td><a href="index.php?p=video&amp;id=' . $e(urlencode($r['key'])) . '">Modifica</a></td></tr>';
        }
        if (!$shown) {
            $h .= '<tr><td colspan="5" class="empty">Nessun video in questo gruppo.</td></tr>';
        }
        $h .= '</table>';

        $h .= '<section class="card narrow"><h2>Aggiungi un video</h2>'
            . '<p class="meta">Incolla il link del video (YouTube o Vimeo). Resta in bozza finché non lo pubblichi.</p>'
            . self::fields('videos', $csrf, null, $raw, 'add') . '</section>';
        return $h;
    }

    public static function form(array $row, string $csrf, ?array $raw): string
    {
        $e = App::e(...);
        $it = $row['draft'] ?: ($row['published'] ?? []);
        $h = '<p><a href="index.php?p=videos">← Tutti i video</a></p>'
            . '<section class="card narrow"><p class="meta">Link: <a href="' . $e(Videos::url($it)) . '" target="_blank" rel="noopener">' . $e(Videos::url($it)) . '</a> · '
            . self::badge(Content::status($row)) . '</p>'
            . self::fields('video', $csrf, $row, $raw, 'save') . '</section>';
        $h .= '<details class="card narrow"><summary>Annulla modifiche di questo video</summary><form method="post" action="index.php?p=video&amp;id=' . $e(urlencode($row['item_id'])) . '">'
            . '<input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="action" value="revert">'
            . '<p>Riporta il video alla versione del sito. Il sito non cambia finché non pubblichi.</p><button class="btn dark">Sì, annulla</button></form></details>';
        $h .= '<details class="card narrow"><summary>Elimina questo video dall\'elenco</summary><form method="post" action="index.php?p=video&amp;id=' . $e(urlencode($row['item_id'])) . '">'
            . '<input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="action" value="delete">'
            . '<p>Sparisce dal sito alla prossima pubblicazione. Fino ad allora puoi ripensarci annullando le modifiche.</p><button class="btn dark">Sì, elimina</button></form></details>';
        return $h;
    }

    private static function fields(string $target, string $csrf, ?array $row, ?array $raw, string $action): string
    {
        $e = App::e(...);
        $it = $row ? ($row['draft'] ?: ($row['published'] ?? [])) : [];
        $v = static fn (string $k): string => $raw !== null ? (string) ($raw[$k] ?? '') : (string) ($it[$k] ?? '');
        $self = $target === 'video' ? 'index.php?p=video&amp;id=' . $e(urlencode((string) $row['item_id'])) : 'index.php?p=videos';
        $h = '<form method="post" action="' . $self . '"><input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="action" value="' . $e($action) . '">';
        if ($target === 'videos') {
            $h .= '<label>Link del video<input name="url" value="' . $e((string) ($raw['url'] ?? '')) . '" maxlength="300" placeholder="https://youtu.be/…" required></label>';
        }
        $h .= '<div class="grid2"><label>Gruppo<select name="gruppo">';
        $sel = $v('gruppo');
        foreach (Videos::GROUPS as $k => $label) {
            $h .= '<option value="' . $e($k) . '"' . ($sel === $k ? ' selected' : '') . '>' . $e($label) . '</option>';
        }
        $h .= '</select></label>'
            . '<label>Durata (m:ss, facoltativa)<input name="durata" value="' . $e($v('durata')) . '" maxlength="5" inputmode="numeric"></label></div>'
            . '<label>Titolo in italiano<input name="titolo" value="' . $e($v('titolo')) . '" maxlength="' . 120 . '" required></label>'
            . '<label>Titolo in inglese (facoltativo)<input name="titolo_en" value="' . $e($v('titolo_en')) . '" maxlength="120"></label>'
            . '<p><button class="btn">' . ($action === 'add' ? 'Aggiungi come bozza' : 'Salva bozza') . '</button></p></form>';
        return $h;
    }

    private static function badge(string $status): string
    {
        $label = [Content::NEW => 'Nuovo', Content::LIVE => 'Sul sito', Content::CHANGED => 'Modificato', Content::REMOVED => 'Da eliminare'][$status] ?? $status;
        $cls = $status === Content::LIVE ? 'chiusa' : 'todo';
        return '<span class="status ' . $cls . '">' . App::e($label) . '</span>';
    }
}
