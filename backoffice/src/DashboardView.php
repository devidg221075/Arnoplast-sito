<?php
declare(strict_types=1);

namespace Backoffice;

/** Pagina iniziale del backoffice: layout del mockup (indicatori, ultime richieste, andamento, attività, metriche). */
final class DashboardView
{
    private const STATUS = ['nuova' => 'Da gestire', 'in_lavorazione' => 'In lavorazione', 'preventivo_inviato' => 'Preventivo inviato', 'chiusa' => 'Chiusa'];

    /** Riepilogo statistiche del sito (ultimi 30 giorni), dalla stessa fonte della pagina Statistiche. */
    public static function statsPanel(array $s): string
    {
        $e = App::e(...);
        if ($s['pages'] === 0) {
            return '<section class="dashboard-panel stats-panel"><header><h2>Statistiche del sito <small>ultimi 30 giorni</small></h2><a href="index.php?p=stats">Vedi tutte →</a></header>'
                . '<p class="empty">Ancora nessuna visita registrata.</p></section>';
        }
        $kp = [['Pagine viste', $s['pages']], ['Visite', $s['visits']], ['Visitatori stimati', $s['visitors']], ['Pagine per visita', $s['pages_per_visit']]];
        $h = '<section class="dashboard-panel stats-panel"><header><h2>Statistiche del sito <small>ultimi 30 giorni</small></h2><a href="index.php?p=stats">Vedi tutte le statistiche →</a></header>'
            . '<div class="stat-mini">';
        foreach ($kp as [$label, $v]) {
            $h .= '<div class="stat-mini-item"><div class="stat-mini-num">' . $e(is_float($v) ? str_replace('.', ',', (string) $v) : number_format((int) $v, 0, ',', '.')) . '</div><div class="sub">' . $e($label) . '</div></div>';
        }
        $h .= '</div><div class="stats-grid">'
            . '<div><h3 class="mini-h">Andamento giornaliero</h3>' . StatsView::line($s['daily']) . '</div>'
            . '<div><h3 class="mini-h">Pagine più viste</h3>' . StatsView::bars(array_slice($s['top_pages'], 0, 5), $s['pages'], 'Nessun dato.') . '</div>'
            . '<div><h3 class="mini-h">Da dove arrivano</h3>' . StatsView::bars(array_map(static fn (array $r): array => ['label' => match ($r['kind']) {
                'diretto' => 'Accesso diretto', 'motore' => 'Motori di ricerca', 'social' => 'Social network', 'interno' => 'Pagine del sito', default => $r['label']}, 'n' => $r['n']], array_slice($s['sources'], 0, 5)), $s['pages'], 'Nessun dato.') . '</div>'
            . '<div><h3 class="mini-h">Dispositivi</h3>' . StatsView::bars($s['devices'], $s['pages'], 'Nessun dato.') . '</div>'
            . '<div><h3 class="mini-h">Paesi</h3>' . ($s['countries'] ? StatsView::bars(array_slice($s['countries'], 0, 5), $s['pages'], 'Nessun dato.') : '<p class="sub">' . ($s['geo_available'] ? 'Nessun dato.' : 'Disponibile con il database geografico (GeoLite2).') . '</p>') . '</div>'
            . '</div></section>';
        return $h;
    }

    public static function page(array $d, bool $admin, string $name, string $self, ?array $stats = null, ?callable $can = null): string
    {
        $e = App::e(...);
        $can ??= static fn (string $p): bool => true;
        $first = explode(' ', trim($name))[0];
        $h = '<div class="page-heading"><div><h1>Dashboard</h1><p>Buongiorno ' . $e($first) . ', ecco la panoramica di ARNOplast.</p></div>'
            . '<div class="heading-date"><span class="date-line">' . $e(date('d.m.Y')) . '</span>'
            . '<img class="deco-top" src="assets/deco-top.webp" alt="" width="566" height="70"></div></div>';

        $h .= '<div class="dashboard-kpis">';
        $tones = ['quotes' => 'green', 'orders' => 'peach', 'products' => 'green', 'new' => 'peach'];
        $icons = ['quotes' => 'requests', 'orders' => 'orders', 'products' => 'products', 'new' => 'mail'];
        $pages = ['quotes' => 'requests', 'orders' => 'orders', 'products' => 'products', 'new' => 'requests'];
        foreach ($d['kpis'] as $k => $v) {
            $placeholder = $v['value'] === null;
            $tag = $can($pages[$k]) ? 'a' : 'div';
            $href = $tag === 'a' ? ' href="index.php?p=' . $pages[$k] . '"' : '';
            $h .= '<' . $tag . ' class="dashboard-kpi ' . $tones[$k] . '"' . $href . '>' . self::icon($icons[$k])
                . '<div><span>' . $e($v['label']) . '</span><strong>' . ($placeholder ? '—' : $e((string) $v['value'])) . '</strong><small>' . $e($v['hint']) . '</small></div>'
                . ($placeholder ? '' : self::delta($v['delta'])) . ($tag === 'a' ? '<span class="kpi-arrow" aria-hidden="true">→</span>' : '') . '</' . $tag . '>';
        }
        $h .= '</div>';

        $h .= '<div class="dashboard-middle">'
            . '<section class="dashboard-panel"><header><h2>Ultime richieste</h2><a href="index.php?p=requests">Vedi tutte le richieste →</a></header>'
            . self::latest($d['latest'], $e) . '</section>'
            . '<section class="dashboard-panel"><header><h2>Andamento preventivi e ordini</h2></header>'
            . '<div class="chart-legend"><span class="green-dot"></span>Preventivi<span class="red-dot"></span>Ordini</div>'
            . self::chart($d['weekly']) . '<p class="chart-note">Ultime 8 settimane</p></section>'
            . '</div>';

        if ($stats !== null) {
            $h .= self::statsPanel($stats);
        }
        if ($admin) {
            $h .= '<div class="dashboard-bottom">'
                . '<section class="dashboard-panel"><header><h2>Attività recenti</h2><a href="index.php?p=activity">Vedi tutte →</a></header>' . self::activity($d['activity'], $e) . '</section>'
                . '<section class="dashboard-panel"><header><h2>Altre metriche</h2><small>ultimi 30 giorni</small></header>' . self::metrics($d['metrics'], $e) . '</section>'
                . '</div>';
        }
        return $h;
    }

    private static function delta(?float $v): string
    {
        if ($v === null) {
            return '';
        }
        $txt = ($v > 0 ? '+' : '') . str_replace('.', ',', (string) $v) . '%';
        return '<span class="kpi-delta' . ($v < 0 ? ' down' : '') . '" title="Rispetto al periodo precedente">' . App::e($txt) . '</span>';
    }

    private static function latest(array $rows, callable $e): string
    {
        if (!$rows) {
            return '<p class="empty">Nessuna richiesta ancora.</p>';
        }
        $h = '<div class="table-scroll"><table class="dashboard-table"><tr><th>Riferimento</th><th>Cliente</th><th>Tipo</th><th>Importo</th><th>Stato</th><th>Data</th></tr>';
        foreach ($rows as $r) {
            $label = self::STATUS[$r['status']] ?? ucfirst((string) $r['status']);
            $h .= '<tr><td><a href="index.php?p=request&amp;id=' . (int) $r['id'] . '">' . $e($r['ref']) . '</a></td>'
                . '<td>' . $e($r['name']) . ($r['company'] !== '' ? '<span class="sub"> · ' . $e($r['company']) . '</span>' : '') . '</td>'
                . '<td>' . ($r['kind'] === 'quote' ? 'Preventivo' : 'Contatto') . '</td>'
                . '<td class="money">' . ($r['total'] !== false && $r['total'] !== null ? $e(Quote::money((int) $r['total'])) : '—') . '</td>'
                . '<td><span class="status ' . $e((string) $r['status']) . '">' . $e($label) . '</span></td><td>' . $e(date('d/m/Y', strtotime((string) $r['created_at']))) . '</td></tr>';
        }
        return $h . '</table></div>';
    }

    /** Grafico a due linee (SVG): ordini in rosso, preventivi in verde. */
    private static function chart(array $weeks): string
    {
        $w = 600; $hgt = 220; $pad = 34;
        $max = max(4, max(array_map(static fn (array $x): int => max($x['quotes'], $x['orders']), $weeks)));
        $n = count($weeks);
        $step = $n > 1 ? ($w - 2 * $pad) / ($n - 1) : 0;
        $pts = static function (string $k) use ($weeks, $max, $step, $hgt, $pad): string {
            $out = [];
            foreach ($weeks as $i => $x) {
                $out[] = round($pad + $i * $step, 1) . ',' . round($hgt - $pad - ($x[$k] / $max) * ($hgt - 2 * $pad), 1);
            }
            return implode(' ', $out);
        };
        $svg = '<svg class="activity-chart" viewBox="0 0 ' . $w . ' ' . $hgt . '" role="img" aria-label="Andamento settimanale di preventivi e ordini">';
        for ($g = 0; $g <= 4; $g++) {
            $y = round($hgt - $pad - $g / 4 * ($hgt - 2 * $pad), 1);
            $svg .= '<line class="chart-grid" x1="' . $pad . '" x2="' . ($w - $pad) . '" y1="' . $y . '" y2="' . $y . '"/><text x="' . ($pad - 8) . '" y="' . ($y + 4) . '" text-anchor="end">' . round($max * $g / 4) . '</text>';
        }
        foreach ($weeks as $i => $x) {
            $svg .= '<text x="' . round($pad + $i * $step, 1) . '" y="' . ($hgt - 8) . '" text-anchor="middle">' . App::e($x['label']) . '</text>';
        }
        $svg .= '<polyline class="chart-b" points="' . $pts('orders') . '"/><polyline class="chart-a" points="' . $pts('quotes') . '"/>';
        foreach ($weeks as $i => $x) {
            $svg .= '<circle class="dot-o" cx="' . round($pad + $i * $step, 1) . '" cy="' . round($hgt - $pad - ($x['orders'] / $max) * ($hgt - 2 * $pad), 1) . '" r="4"/>'
                . '<circle class="dot-q" cx="' . round($pad + $i * $step, 1) . '" cy="' . round($hgt - $pad - ($x['quotes'] / $max) * ($hgt - 2 * $pad), 1) . '" r="4"/>';
        }
        return $svg . '</svg>';
    }

    private static function activity(array $rows, callable $e): string
    {
        if (!$rows) {
            return '<p class="empty">Nessuna attività.</p>';
        }
        $h = '<ul class="activity-list">';
        foreach ($rows as $a) {
            $h .= '<li><span class="activity-icon">' . self::icon($a['icon']) . '</span><div><strong>' . $e($a['text']) . '</strong>'
                . '<small>' . $e($a['who']) . ' · ' . $e(date('d/m H:i', strtotime((string) $a['at']))) . '</small></div></li>';
        }
        return $h . '</ul>';
    }

    private static function metrics(array $m, callable $e): string
    {
        $items = [['Visite al sito', $m['visits'], 'eye'], ['Listini scaricati', $m['downloads'], 'download'], ['Nuovi clienti', $m['clients'], 'users']];
        $h = '';
        foreach ($items as [$label, $v, $ic]) {
            $h .= '<div class="metric-row">' . self::icon($ic) . '<div><strong>' . $e(number_format((int) $v, 0, ',', '.')) . '</strong><small>' . $e($label) . '</small></div></div>';
        }
        return $h;
    }

    /** Icona dallo sprite del kit grafico, incluso una volta sola nella pagina. */
    private static function icon(string $name): string
    {
        $map = ['doc' => 'requests', 'cart' => 'orders', 'mail' => 'mail', 'users' => 'clients', 'eye' => 'eye', 'download' => 'download', 'dot' => 'activity',
            'home' => 'dashboard', 'box' => 'products', 'list' => 'activity', 'chart' => 'stats', 'shield' => 'account', 'gear' => 'modules'];
        $id = $map[$name] ?? $name;
        return '<svg class="icon" aria-hidden="true" focusable="false"><use href="#' . $id . '"/></svg>';
    }
}
