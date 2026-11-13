<?php
declare(strict_types=1);

namespace Backoffice;

/** Schermate dei testi fissi: elenco dei gruppi con ricerca, modulo di un gruppo con italiano e inglese affiancati. */
final class TextsView
{
    public static function index(string $q): string
    {
        $e = App::e(...);
        $pending = Texts::pendingByGroup();
        $h = '<form method="get" action="index.php" class="filters"><input type="hidden" name="p" value="texts"><label>Cerca un testo (anche per una parola)<input type="search" name="q" value="' . $e($q) . '" maxlength="80" placeholder="es. preventivo, Open Day, Scarica"></label><button class="btn">Cerca</button></form>';
        if ($q !== '') {
            $found = Texts::search($q);
            $h .= '<h2>Risultati (' . count($found) . ')</h2>';
            if (!$found) {
                $h .= '<p class="empty">Nessun testo trovato.</p>';
            } else {
                $h .= '<table class="list"><tr><th>Testo italiano</th><th>Testo inglese</th><th>Pagina</th></tr>';
                foreach ($found as $f) {
                    $h .= '<tr><td>' . $e($f['it']) . '</td><td>' . $e($f['en']) . '</td><td><a href="index.php?p=text_group&amp;g=' . $e($f['group']) . '#t-' . $e(strtr($f['key'], '.', '-')) . '">' . $e($f['group_name']) . '</a></td></tr>';
                }
                $h .= '</table>';
            }
        }
        $h .= '<h2>Per pagina</h2><table class="list"><tr><th>Gruppo</th><th>Cosa contiene</th><th class="num">Testi</th><th>Modifiche</th></tr>';
        foreach (Texts::seed()['groups'] as $g) {
            $n = $pending[$g['id']] ?? 0;
            $h .= '<tr><td><a href="index.php?p=text_group&amp;g=' . $e($g['id']) . '">' . $e($g['name']) . '</a></td><td>' . $e($g['help']) . '</td><td class="num">' . count($g['keys'])
                . '</td><td>' . ($n > 0 ? '<span class="status todo">' . $n . ' in bozza</span>' : '') . '</td></tr>';
        }
        return $h . '</table><p class="meta">I testi legali (privacy, cookie e banner) non si modificano da qui: li fornisce ARNOplast. Le modifiche restano in bozza finché non si pubblica. '
            . 'La storia dell\'azienda e gli imballaggi sono testi lunghi e si gestiscono a parte.</p>';
    }

    public static function group(array $g, string $csrf, ?array $raw): string
    {
        $e = App::e(...);
        $seed = Texts::seed()['texts'];
        $drafts = Texts::drafts();
        $live = Texts::overrides(true);
        $h = '<p><a href="index.php?p=texts">← Tutti i testi</a></p><p class="meta">' . $e($g['help']) . ' Sopra ogni campo c\'è il testo che il sito mostra ora. Scrivi nel campo solo se vuoi cambiarlo; lascia vuoto per non cambiare. Su una riga sola, al massimo '
            . (int) Texts::seed()['max_len'] . ' caratteri.</p><form method="post" action="index.php?p=text_group&amp;g=' . $e($g['id']) . '" class="card">'
            . '<input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="action" value="save">'
            . '<table class="list texttable"><tr><th>Italiano</th><th>Inglese</th></tr>';
        foreach ($g['keys'] as $k) {
            $base = $seed[$k];
            $d = $drafts[$k]['draft'] ?? [];
            $cur = ['it' => (string) ($raw['it'][$k] ?? $d['it'] ?? ''), 'en' => (string) ($raw['en'][$k] ?? $d['en'] ?? '')];
            $modified = isset($drafts[$k]) && $d !== [];
            $h .= '<tr id="t-' . $e(strtr($k, '.', '-')) . '"><td colspan="2" class="tkey"><code>' . $e($k) . '</code>' . ($modified ? ' <span class="status todo">Modificato</span>' : '') . '</td></tr><tr>';
            foreach (['it', 'en'] as $lang) {
                $long = mb_strlen($base[$lang]) > 80;
                $shown = $live[$lang][$k] ?? $base[$lang];
                $field = $long
                    ? '<textarea name="' . $lang . '[' . $e($k) . ']" rows="3" maxlength="400" aria-label="Nuovo testo, ' . $lang . '">' . $e($cur[$lang]) . '</textarea>'
                    : '<input name="' . $lang . '[' . $e($k) . ']" maxlength="400" value="' . $e($cur[$lang]) . '" aria-label="Nuovo testo, ' . $lang . '">';
                $h .= '<td><p class="meta"><small>Sul sito ora:</small>' . $e($shown) . '</p>' . '<small>Nuovo testo:</small>' . $field . '</td>';
            }
            $h .= '</tr>';
        }
        return $h . '</table><p><button class="btn">Salva bozza</button></p></form>';
    }
}
