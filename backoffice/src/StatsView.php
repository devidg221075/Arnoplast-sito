<?php
declare(strict_types=1);

namespace Backoffice;

/** Dashboard delle statistiche: indicatori, andamento, pagine, percorsi, provenienze, dispositivi, paesi. SVG e classi CSS (niente stili inline). */
final class StatsView
{
    private const DEVICE_COLORS = ['Computer' => 'd1', 'Smartphone' => 'd2', 'Tablet' => 'd3'];

    public static function page(array $s): string
    {
        $e = App::e(...);
        $n = (int) $s['days'];
        $h = '<div class="periods">';
        foreach ([7 => 'Ultimi 7 giorni', 30 => 'Ultimi 30 giorni', 90 => 'Ultimi 90 giorni'] as $d => $label) {
            $h .= '<a class="period' . ($d === $n ? ' on' : '') . '" href="index.php?p=stats&amp;d=' . $d . '">' . $e($label) . '</a>';
        }
        $h .= '</div>';

        if ($s['pages'] === 0) {
            return $h . '<section class="card empty-state"><h2>Ancora nessuna visita</h2><p class="meta">Le statistiche compaiono appena il sito riceve visite '
                . 'con le statistiche attive. Nessun cookie: i visitatori non vengono riconosciuti tra un giorno e l\'altro.</p></section>';
        }

        $h .= '<div class="kpis">'
            . self::kpi('Pagine viste', self::fmt($s['pages']), 'in ' . $n . ' giorni')
            . self::kpi('Visite', self::fmt($s['visits']), 'gruppi di pagine con meno di 30 minuti tra l\'una e l\'altra')
            . self::kpi('Visitatori stimati', self::fmt($s['visitors']), 'persone diverse al giorno (l\'impronta cambia ogni giorno)')
            . self::kpi('Pagine per visita', (string) $s['pages_per_visit'], 'quante pagine guarda chi entra')
            . '</div>';

        $h .= '<section class="card chart-card"><div class="chart-head"><h2>Andamento</h2>'
            . '<p class="legend"><span class="sw sw-p"></span>Pagine viste <span class="sw sw-v"></span>Visite</p></div>'
            . self::line($s['daily']) . '</section>';

        $h .= '<div class="grid-2">'
            . '<section class="card"><h2>Pagine più viste</h2>' . self::bars($s['top_pages'], $s['pages'], 'Nessuna pagina.') . '</section>'
            . '<section class="card"><h2>Da dove entrano</h2>' . self::bars(array_map(static fn (array $r): array => ['label' => self::sourceLabel($r), 'n' => $r['n']], $s['sources']), $s['pages'], 'Nessuna provenienza.') . '</section>'
            . '</div>';

        $h .= '<div class="grid-2">'
            . '<section class="card"><h2>Pagine di ingresso</h2>' . self::bars($s['entries'], $s['visits'], 'Nessun dato.') . '</section>'
            . '<section class="card"><h2>Pagine di uscita</h2>' . self::bars($s['exits'], $s['visits'], 'Nessun dato.') . '</section>'
            . '</div>';

        $h .= '<section class="card"><h2>Percorsi più frequenti</h2><p class="meta">Sequenze di pagine che i visitatori hanno seguito nella stessa visita.</p>'
            . self::bars($s['paths'], $s['visits'], 'Nessun percorso ripetuto.', true) . '</section>';

        $h .= '<div class="grid-2">'
            . '<section class="card"><h2>Dispositivi</h2>' . self::donut($s['devices'], $s['pages']) . '</section>'
            . '<section class="card"><h2>Browser</h2>' . self::bars($s['browsers'], $s['pages'], 'Nessun dato.') . '</section>'
            . '</div>';

        $h .= '<section class="card"><h2>Paesi</h2>';
        if ($s['countries']) {
            $h .= self::bars(array_map(static fn (array $r): array => ['label' => self::countryName($r['label']), 'n' => $r['n']], $s['countries']), $s['pages'], 'Nessun dato.');
        }
        if (!$s['geo_available']) {
            $h .= '<p class="meta">Il paese non è ancora disponibile: serve il database geografico gratuito (MaxMind GeoLite2 Country), da caricare sul server. '
                . 'Fino ad allora le visite restano senza paese.</p>';
        } elseif ($s['unknown_country'] > 0) {
            $h .= '<p class="meta">Per ' . self::fmt($s['unknown_country']) . ' pagine il paese non è riconosciuto.</p>';
        }
        $h .= '</section>';

        $h .= '<p class="meta privacy-note">Statistiche senza cookie: l\'indirizzo IP non viene salvato, né in chiaro né in altra forma. Le segnalazioni più vecchie di '
            . Stats::RETENTION_DAYS . ' giorni vengono cancellate. Se il visitatore ha attivato «Non tracciare» nel browser, non viene conteggiato.</p>';
        return $h;
    }

    private static function kpi(string $label, string $value, string $hint): string
    {
        $e = App::e(...);
        return '<div class="kpi"><div class="kpi-label">' . $e($label) . '</div><div class="kpi-value">' . $e($value) . '</div><div class="kpi-hint">' . $e($hint) . '</div></div>';
    }

    /** @param array<int, array{day:string, pages:int, visits:int}> $daily */
    private static function line(array $daily): string
    {
        $w = 720; $hgt = 220; $pad = 28;
        $max = max(1, max(array_map(static fn (array $d): int => max($d['pages'], $d['visits']), $daily)));
        $count = count($daily);
        $step = $count > 1 ? ($w - 2 * $pad) / ($count - 1) : 0;
        $pts = static function (string $k) use ($daily, $max, $step, $w, $hgt, $pad): string {
            $out = [];
            foreach ($daily as $i => $d) {
                $x = $pad + $i * $step;
                $y = $hgt - $pad - ($d[$k] / $max) * ($hgt - 2 * $pad);
                $out[] = round($x, 1) . ',' . round($y, 1);
            }
            return implode(' ', $out);
        };
        $svg = '<svg class="line" viewBox="0 0 ' . $w . ' ' . $hgt . '" role="img" aria-label="Andamento giornaliero di pagine viste e visite">';
        for ($g = 0; $g <= 4; $g++) {
            $y = round($hgt - $pad - $g / 4 * ($hgt - 2 * $pad), 1);
            $svg .= '<line class="grid" x1="' . $pad . '" x2="' . ($w - $pad) . '" y1="' . $y . '" y2="' . $y . '"/>'
                . '<text class="tick" x="' . ($pad - 6) . '" y="' . ($y + 4) . '" text-anchor="end">' . round($max * $g / 4) . '</text>';
        }
        $every = max(1, (int) ceil($count / 8));
        foreach ($daily as $i => $d) {
            if ($i % $every === 0 || $i === $count - 1) {
                $svg .= '<text class="tick" x="' . round($pad + $i * $step, 1) . '" y="' . ($hgt - 6) . '" text-anchor="middle">' . substr($d['day'], 8, 2) . '/' . substr($d['day'], 5, 2) . '</text>';
            }
        }
        $svg .= '<polyline class="ln-p" fill="none" points="' . $pts('pages') . '"/>'
            . '<polyline class="ln-v" fill="none" points="' . $pts('visits') . '"/>';
        foreach ($daily as $i => $d) {
            $x = round($pad + $i * $step, 1);
            $svg .= '<circle class="dot-p" cx="' . $x . '" cy="' . round($hgt - $pad - ($d['pages'] / $max) * ($hgt - 2 * $pad), 1) . '" r="3"><title>'
                . App::e(substr($d['day'], 8, 2) . '/' . substr($d['day'], 5, 2)) . ': ' . $d['pages'] . ' pagine, ' . $d['visits'] . ' visite</title></circle>';
        }
        return $svg . '</svg>';
    }

    /** Barre orizzontali: ogni riga mostra la quota sul totale. */
    private static function bars(array $rows, int $total, string $empty, bool $wide = false): string
    {
        $e = App::e(...);
        if (!$rows) {
            return '<p class="empty">' . $e($empty) . '</p>';
        }
        $max = max(1, max(array_column($rows, 'n')));
        $h = '<div class="bars' . ($wide ? ' wide' : '') . '">';
        foreach ($rows as $r) {
            $pct = round($r['n'] / $max * 100, 2);
            $share = $total > 0 ? round($r['n'] / $total * 100) : 0;
            $h .= '<div class="brow"><div class="blabel">' . $e($r['label']) . '</div>'
                . '<svg class="bsvg" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true"><rect class="bfill" x="0" y="0" width="' . $pct . '" height="10" rx="2"/></svg>'
                . '<div class="bnum">' . $e(self::fmt($r['n'])) . ' <span class="bshare">' . $share . '%</span></div></div>';
        }
        return $h . '</div>';
    }

    /** Ciambella dei dispositivi (SVG, niente script). */
    private static function donut(array $rows, int $total): string
    {
        $e = App::e(...);
        if (!$rows) {
            return '<p class="empty">Nessun dato.</p>';
        }
        $sum = max(1, array_sum(array_column($rows, 'n')));
        $svg = '<svg class="donut" viewBox="0 0 42 42" role="img" aria-label="Dispositivi"><circle class="ring" cx="21" cy="21" r="15.9" fill="none" stroke-width="6"/>';
        $offset = 25;
        $legend = '<ul class="legend-list">';
        foreach ($rows as $r) {
            $pct = round($r['n'] / $sum * 100, 2);
            $cls = self::DEVICE_COLORS[$r['label']] ?? 'd4';
            $svg .= '<circle class="seg ' . $cls . '" cx="21" cy="21" r="15.9" fill="none" stroke-width="6" stroke-dasharray="' . $pct . ' ' . (100 - $pct) . '" stroke-dashoffset="' . $offset . '"/>';
            $offset -= $pct;
            $legend .= '<li><span class="sw ' . $cls . '"></span>' . $e($r['label']) . ' <strong>' . $e(self::fmt($r['n'])) . '</strong> <span class="bshare">' . round($pct) . '%</span></li>';
        }
        return '<div class="donut-wrap">' . $svg . '</svg>' . $legend . '</ul></div>';
    }

    private static function sourceLabel(array $r): string
    {
        $kind = $r['kind'] ?? '';
        return match ($kind) {
            'diretto' => 'Accesso diretto (digitato o preferito)',
            'interno' => 'Da una pagina del sito: ' . $r['label'],
            'motore' => 'Motori di ricerca',
            'social' => 'Social network',
            default => $r['label'],
        };
    }

    private static function countryName(string $code): string
    {
        $names = ['IT' => 'Italia', 'DE' => 'Germania', 'FR' => 'Francia', 'ES' => 'Spagna', 'CH' => 'Svizzera', 'AT' => 'Austria', 'GB' => 'Regno Unito',
            'US' => 'Stati Uniti', 'NL' => 'Paesi Bassi', 'BE' => 'Belgio', 'PT' => 'Portogallo', 'PL' => 'Polonia', 'RO' => 'Romania', 'GR' => 'Grecia'];
        return $names[$code] ?? $code;
    }

    private static function fmt(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }
}
