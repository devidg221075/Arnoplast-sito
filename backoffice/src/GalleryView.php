<?php
declare(strict_types=1);

namespace Backoffice;

/** Schermate della galleria: griglia con filtro per categoria, aggiunta di foto, scheda di una foto. */
final class GalleryView
{
    /** Indirizzo di una miniatura o di un ingrandimento: dal sito per le foto già pubblicate, dal backoffice per le nuove. */
    private static function img(array $row, bool $thumb): string
    {
        $id = (string) $row['item_id'];
        if (isset($row['draft']['sorgente'])) {
            return 'index.php?p=gallery_img&amp;id=' . App::e($id);
        }
        $base = rtrim((string) App::config('assets_url', ''), '/');
        return $base === '' ? '' : App::e($base . '/static/img/galleria/' . $id . ($thumb ? '-t' : '') . '.webp');
    }

    public static function list(string $csrf, string $cat): string
    {
        $e = App::e(...);
        $rows = Gallery::rows();
        $h = '';
        if (!$rows) {
            $n = count(Gallery::seedItems());
            return '<form method="post" action="index.php?p=gallery" class="card narrow"><input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="action" value="import">'
                . '<p>La galleria è vuota nel backoffice. Puoi importare le ' . $n . ' foto che sono già sul sito: restano come sono, le modifichi dopo.</p><button class="btn dark">Importa le foto del sito</button></form>';
        }
        $h .= '<form method="post" action="index.php?p=gallery" enctype="multipart/form-data" class="card"><input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="action" value="add">'
            . '<h2>Aggiungi foto</h2><div class="grid2"><label>Foto (JPG, PNG o WebP; fino a ' . Gallery::MAX_FILES . ' per volta, 10 MB ciascuna)<input type="file" name="photos[]" multiple accept="image/jpeg,image/png,image/webp" required></label>'
            . '<label>Categoria<select name="categoria">';
        foreach (Gallery::CATEGORIES as $k => $label) {
            $h .= '<option value="' . $e($k) . '">' . $e($label) . '</option>';
        }
        $h .= '</select></label></div><p class="meta">Le foto nuove vanno in fondo alla galleria con un testo alternativo provvisorio: aprile una per una per descriverle e spostarle. Il sito le mostra dopo «Pubblica».</p><button class="btn">Carica</button></form>';
        $ids = Gallery::order($rows);
        $counts = array_fill_keys(array_keys(Gallery::CATEGORIES), 0);
        foreach ($rows as $r) {
            if (!$r['deleted']) {
                $counts[$r['draft']['categoria'] ?? 'impianti'] = ($counts[$r['draft']['categoria'] ?? 'impianti'] ?? 0) + 1;
            }
        }
        $h .= '<p class="chips"><a class="chip' . ($cat === '' ? ' on' : '') . '" href="index.php?p=gallery">Tutte (' . array_sum($counts) . ')</a>';
        foreach (Gallery::CATEGORIES as $k => $label) {
            $h .= ' <a class="chip' . ($cat === $k ? ' on' : '') . '" href="index.php?p=gallery&amp;cat=' . $e($k) . '">' . $e($label) . ' (' . $counts[$k] . ')</a>';
        }
        $h .= '</p>' . (Gallery::orderChanged() ? '<p class="flash">L\'ordine delle foto è stato cambiato (bozza).</p>' : '') . '<ul class="gallery-grid">';
        $pos = 0;
        foreach ($ids as $id) {
            $r = $rows[$id];
            $pos++;
            $d = $r['draft'];
            if ($cat !== '' && ($d['categoria'] ?? 'impianti') !== $cat) {
                continue;
            }
            $st = Content::status($r);
            $src = self::img($r, true);
            $h .= '<li class="gitem' . ($r['deleted'] ? ' gone' : '') . '"><a href="index.php?p=gallery_photo&amp;id=' . $e($id) . '">'
                . ($src !== '' ? '<img src="' . $src . '" alt="' . $e((string) ($d['alt'] ?? '')) . '" loading="lazy" width="160" height="120">' : '<span class="noimg">' . $e($id) . '</span>')
                . '</a><p><strong>' . $pos . '</strong> · ' . $e(Gallery::CATEGORIES[$d['categoria'] ?? 'impianti'] ?? '')
                . ($st !== Content::LIVE ? ' <span class="status todo">' . $e(ucfirst($st)) . '</span>' : '') . '</p></li>';
        }
        return $h . '</ul>';
    }

    public static function photo(array $row, string $csrf, ?array $raw): string
    {
        $e = App::e(...);
        $id = (string) $row['item_id'];
        $d = $raw ?? $row['draft'];
        $st = Content::status($row);
        $order = Gallery::order();
        $pos = Gallery::position($id);
        $i = $pos - 1;
        $src = self::img($row, false);
        $h = '<p><a href="index.php?p=gallery">← Tutta la galleria</a>' . ($i > 0 ? ' · <a href="index.php?p=gallery_photo&amp;id=' . $e($order[$i - 1]) . '">← foto precedente</a>' : '')
            . ($i < count($order) - 1 ? ' · <a href="index.php?p=gallery_photo&amp;id=' . $e($order[$i + 1]) . '">foto successiva →</a>' : '') . '</p>'
            . '<p class="meta">Foto <code>' . $e($id) . '</code> · posizione ' . $pos . ' di ' . count($order) . ' · stato: <span class="status ' . ($st === Content::LIVE ? 'chiusa' : 'todo') . '">' . $e(ucfirst($st)) . '</span>'
            . (isset($row['draft']['sorgente']) ? ' · foto nuova: la converte la build del sito' : '') . '</p><div class="cols"><section class="card">'
            . ($src !== '' ? '<img class="gphoto" src="' . $src . '" alt="' . $e((string) ($row['draft']['alt'] ?? '')) . '">' : '<p class="empty">Anteprima non disponibile (imposta assets_url in config.php).</p>')
            . '</section><section class="card"><form method="post" action="index.php?p=gallery_photo&amp;id=' . $e($id) . '"><input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="action" value="save">'
            . '<label>Categoria<select name="categoria">';
        foreach (Gallery::CATEGORIES as $k => $label) {
            $h .= '<option value="' . $e($k) . '"' . (($d['categoria'] ?? 'impianti') === $k ? ' selected' : '') . '>' . $e($label) . '</option>';
        }
        return $h . '</select></label>'
            . '<label>Testo alternativo, italiano (descrive la foto a chi non la vede)<textarea name="alt" rows="3" maxlength="200" required>' . $e((string) ($d['alt'] ?? '')) . '</textarea></label>'
            . '<label>Testo alternativo, inglese (se vuoto: «ARNOplast accessories on an installed system»)<textarea name="alt_en" rows="3" maxlength="200">' . $e((string) ($d['alt_en'] ?? '')) . '</textarea></label>'
            . '<label>Posizione nella galleria (1 = prima)<input name="position" value="' . (int) ($raw['position'] ?? $pos) . '" inputmode="numeric" maxlength="3"></label>'
            . '<button class="btn">Salva bozza</button></form></section></div><div class="actions">'
            . ($row['published'] !== null && $st !== Content::LIVE ? '<form method="post" action="index.php?p=gallery_photo&amp;id=' . $e($id) . '"><input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="action" value="revert"><button class="btn dark">Annulla le modifiche</button></form>' : '')
            . '<details><summary>Elimina questa foto</summary><form method="post" action="index.php?p=gallery_photo&amp;id=' . $e($id) . '"><input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="action" value="delete">'
            . '<p>La foto sparirà dal sito alla prossima pubblicazione' . ($row['published'] === null ? ' (essendo nuova, sparisce subito anche da qui)' : '') . '.</p><button class="btn dark">Sì, elimina</button></form></details></div>';
    }
}
