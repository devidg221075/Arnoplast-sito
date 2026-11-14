<?php
declare(strict_types=1);

namespace Backoffice;

/** Pagina iniziale del backoffice: layout del mockup (indicatori, ultime richieste, andamento, attività, metriche). */
final class DashboardView
{
    private const STATUS = [
        'nuova' => ['Da gestire', 'amber'], 'in_lavorazione' => ['In lavorazione', 'blue'],
        'preventivo_inviato' => ['Preventivo inviato', 'violet'], 'chiusa' => ['Chiusa', 'green'],
    ];

    /** Riepilogo statistiche del sito (ultimi 30 giorni), dalla stessa fonte della pagina Statistiche. */
    public static function statsPanel(array $s): string
    {
        $e = App::e(...);
        if ($s['pages'] === 0) {
            return '<section class="panel stats-panel"><div class="panel-head"><h2>Statistiche del sito <small>ultimi 30 giorni</small></h2><a class="more" href="index.php?p=stats">Vedi tutte →</a></div>'
                . '<p class="empty">Ancora nessuna visita registrata.</p></section>';
        }
        $kp = [['Pagine viste', $s['pages']], ['Visite', $s['visits']], ['Visitatori stimati', $s['visitors']], ['Pagine per visita', $s['pages_per_visit']]];
        $h = '<section class="panel stats-panel"><div class="panel-head"><h2>Statistiche del sito <small>ultimi 30 giorni</small></h2><a class="more" href="index.php?p=stats">Vedi tutte le statistiche →</a></div>'
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

    public static function page(array $d, bool $admin, string $name, string $self, ?array $stats = null): string
    {
        $e = App::e(...);
        $h = '<div class="hello"><p class="hello-title">Buongiorno, ' . $e(explode(' ', trim($name))[0]) . '</p><p class="hello-sub">Ecco la panoramica di ARNOplast.</p></div>';
        $h .= '<div class="kpi-row">';
        $tones = ['quotes' => 'tint-green', 'orders' => 'tint-red', 'products' => 'tint-cream', 'new' => 'tint-pink'];
        $icons = ['quotes' => 'doc', 'orders' => 'cart', 'products' => 'box', 'new' => 'mail'];
        foreach ($d['kpis'] as $k => $v) {
            $placeholder = $v['value'] === null;
            $h .= '<div class="kpi-card ' . $tones[$k] . '"><div class="kpi-ic">' . self::icon($icons[$k]) . '</div><div class="kpi-body">'
                . '<div class="kpi-label">' . $e($v['label']) . '</div><div class="kpi-num">' . ($placeholder ? '—' : $e((string) $v['value'])) . '</div>'
                . '<div class="kpi-hint">' . $e($v['hint']) . '</div></div>'
                . ($placeholder ? '' : self::delta($v['delta'], $v['delta'] === null)) . '</div>';
        }
        $h .= '</div>';

        $h .= '<div class="dash-grid">'
            . '<section class="panel span-2"><div class="panel-head"><h2>Ultime richieste</h2><a class="more" href="index.php?p=requests">Vedi tutte le richieste →</a></div>'
            . self::latest($d['latest'], $e) . '</section>'
            . '<section class="panel"><div class="panel-head"><h2>Andamento preventivi e ordini</h2><span class="legend-inline"><span class="dot-red"></span>Ordini <span class="dot-green"></span>Preventivi</span></div>'
            . self::chart($d['weekly']) . '<p class="muted">Ultime 8 settimane</p></section>'
            . '</div>';

        if ($stats !== null) {
            $h .= self::statsPanel($stats);
        }
        $h .= '<div class="dash-grid bottom">'
            . ($admin ? '<section class="panel"><div class="panel-head"><h2>Attività recenti</h2><a class="more" href="index.php?p=activity">Vedi tutte →</a></div>' . self::activity($d['activity'], $e) . '</section>' : '')
            . ($admin ? '<section class="panel"><div class="panel-head"><h2>Altre metriche <small>ultimi 30 giorni</small></h2></div>' . self::metrics($d['metrics'], $e) . '</section>' : '')
            . '</div>';
        return $h;
    }

    private static function delta(?float $v, bool $none): string
    {
        if ($none || $v === null) {
            return '<span class="delta flat">' . ($none ? '—' : 'nuovo') . '</span>';
        }
        $cls = $v > 0 ? 'up' : ($v < 0 ? 'down' : 'flat');
        $txt = ($v > 0 ? '+' : '') . str_replace('.', ',', (string) $v) . '%';
        return '<span class="delta ' . $cls . '">' . App::e($txt) . '</span>';
    }

    private static function latest(array $rows, callable $e): string
    {
        if (!$rows) {
            return '<p class="empty">Nessuna richiesta ancora.</p>';
        }
        $h = '<div class="table-scroll"><table class="latest"><tr><th>Riferimento</th><th>Cliente</th><th>Tipo</th><th class="num">Importo</th><th>Stato</th><th>Data</th></tr>';
        foreach ($rows as $r) {
            [$label, $tone] = self::STATUS[$r['status']] ?? [ucfirst((string) $r['status']), 'grey'];
            $h .= '<tr><td><a href="index.php?p=request&amp;id=' . (int) $r['id'] . '">' . $e($r['ref']) . '</a></td>'
                . '<td>' . $e($r['name']) . ($r['company'] !== '' ? '<span class="sub"> · ' . $e($r['company']) . '</span>' : '') . '</td>'
                . '<td>' . ($r['kind'] === 'quote' ? 'Preventivo' : 'Contatto') . '</td>'
                . '<td class="num">' . ($r['total'] !== false && $r['total'] !== null ? $e(Quote::money((int) $r['total'])) : '—') . '</td>'
                . '<td><span class="pill pill-' . $tone . '">' . $e($label) . '</span></td><td>' . $e(date('d/m/Y', strtotime((string) $r['created_at']))) . '</td></tr>';
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
        $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $hgt . '" role="img" aria-label="Andamento settimanale di preventivi e ordini">';
        for ($g = 0; $g <= 4; $g++) {
            $y = round($hgt - $pad - $g / 4 * ($hgt - 2 * $pad), 1);
            $svg .= '<line class="grid" x1="' . $pad . '" x2="' . ($w - $pad) . '" y1="' . $y . '" y2="' . $y . '"/><text class="tick" x="' . ($pad - 8) . '" y="' . ($y + 4) . '" text-anchor="end">' . round($max * $g / 4) . '</text>';
        }
        foreach ($weeks as $i => $x) {
            $svg .= '<text class="tick" x="' . round($pad + $i * $step, 1) . '" y="' . ($hgt - 8) . '" text-anchor="middle">' . App::e($x['label']) . '</text>';
        }
        $svg .= '<polyline class="ln-orders" fill="none" points="' . $pts('orders') . '"/><polyline class="ln-quotes" fill="none" points="' . $pts('quotes') . '"/>';
        foreach ($weeks as $i => $x) {
            $svg .= '<circle class="dot-orders" cx="' . round($pad + $i * $step, 1) . '" cy="' . round($hgt - $pad - ($x['orders'] / $max) * ($hgt - 2 * $pad), 1) . '" r="4"/>'
                . '<circle class="dot-quotes" cx="' . round($pad + $i * $step, 1) . '" cy="' . round($hgt - $pad - ($x['quotes'] / $max) * ($hgt - 2 * $pad), 1) . '" r="4"/>';
        }
        return $svg . '</svg>';
    }

    private static function activity(array $rows, callable $e): string
    {
        if (!$rows) {
            return '<p class="empty">Nessuna attività.</p>';
        }
        $h = '<ul class="feed">';
        foreach ($rows as $a) {
            $h .= '<li><span class="feed-ic">' . self::icon($a['icon']) . '</span><div><div class="feed-text">' . $e($a['text']) . '</div>'
                . '<div class="sub">' . $e($a['who']) . ' · ' . $e(date('d/m H:i', strtotime((string) $a['at']))) . '</div></div></li>';
        }
        return $h . '</ul>';
    }

    private static function metrics(array $m, callable $e): string
    {
        $items = [['Visite al sito', $m['visits'], 'eye'], ['Listini scaricati', $m['downloads'], 'download'], ['Nuovi clienti', $m['clients'], 'users']];
        $h = '<ul class="metrics">';
        foreach ($items as [$label, $v, $ic]) {
            $h .= '<li><span class="feed-ic">' . self::icon($ic) . '</span><div><div class="metric-num">' . $e(number_format((int) $v, 0, ',', '.')) . '</div><div class="sub">' . $e($label) . '</div></div></li>';
        }
        return $h . '</ul>';
    }

    private static function icon(string $name): string
    {
        $paths = [
            'doc' => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z M14 2v6h6',
            'cart' => 'M3 3h2l2.4 12.6A2 2 0 0 0 9.4 17H18a2 2 0 0 0 2-1.6L21 8H6',
            'mail' => 'M3 5h18v14H3z M3 5l9 7 9-7',
            'users' => 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M23 21v-2a4 4 0 0 0-3-3.9',
            'eye' => 'M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z',
            'download' => 'M12 3v12 M7 10l5 5 5-5 M5 21h14',
            'dot' => 'M12 12m-3 0a3 3 0 1 0 6 0 3 3 0 1 0-6 0',
            'home' => 'M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
            'box' => 'M21 8l-9-5-9 5 9 5 9-5z M3 8v8l9 5 9-5V8',
            'list' => 'M8 6h13 M8 12h13 M8 18h13 M3 6h.01 M3 12h.01 M3 18h.01',
            'chart' => 'M18 20V10 M12 20V4 M6 20v-6',
            'shield' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',
            'gear' => 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
        ];
        return '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' . ($paths[$name] ?? $paths['dot']) . '"/></svg>';
    }
}
