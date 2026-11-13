<?php
declare(strict_types=1);

namespace Backoffice;

use MaxMind\Db\Reader;

/**
 * Statistiche del sito senza cookie e senza IP salvati. Ogni pagina vista manda una segnalazione (api/hit.php):
 * si conservano pagina, provenienza, tipo di dispositivo e browser, paese (solo se è presente il database geografico).
 * Il visitatore è un'impronta che cambia ogni giorno: serve a raggruppare le pagine di una stessa visita, non a
 * riconoscere una persona nel tempo. Le segnalazioni più vecchie di RETENTION_DAYS si cancellano.
 */
final class Stats
{
    public const RETENTION_DAYS = 400;
    public const VISIT_GAP = 1800; // secondi senza attività che chiudono una visita
    private const BOT = '/bot|crawl|spider|slurp|facebookexternalhit|mediapartners|lighthouse|headless|preview|monitor|curl|wget|python|go-http|axios|okhttp|http_request/i';

    private static ?Reader $geo = null;
    private static bool $geoTried = false;

    public static function enabled(): bool
    {
        return (bool) App::config('stats_enabled', true);
    }

    /**
     * Registra una pagina vista. Vero se è stata salvata; falso se è esclusa (bot, Non tracciare, pagina non valida).
     *
     * @param array{p?:string, r?:string} $in
     */
    public static function record(array $in, string $ip, string $ua, string $dnt = ''): bool
    {
        if (!self::enabled() || $dnt === '1' || $ua === '' || preg_match(self::BOT, $ua)) {
            return false;
        }
        $path = self::path((string) ($in['p'] ?? ''));
        if ($path === null) {
            return false;
        }
        [$refKind, $ref] = self::source((string) ($in['r'] ?? ''));
        $day = date('Y-m-d');
        $visitor = substr(hash_hmac('sha256', $day . '|' . $ip . '|' . $ua, (string) App::config('secret', '')), 0, 32);
        App::db()->prepare('INSERT INTO hits (day, at, visitor, path, ref_kind, ref, device, browser, country) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$day, App::now(), $visitor, $path, $refKind, $ref, self::device($ua), self::browser($ua), self::country($ip)]);
        if (random_int(1, 200) === 1) {
            App::db()->prepare('DELETE FROM hits WHERE day < ?')->execute([date('Y-m-d', time() - self::RETENTION_DAYS * 86400)]);
        }
        return true;
    }

    /** Percorso della pagina: solo pagine del sito (/, /it/…, /en/…), senza query, lunghezza limitata. */
    public static function path(string $p): ?string
    {
        if ($p === '' || $p[0] !== '/') {
            return null; // il sito manda solo il percorso (es. /it/prodotti/), mai un indirizzo completo
        }
        $p = (string) (parse_url($p, PHP_URL_PATH) ?? '');
        if ($p === '' || strlen($p) > 200 || preg_match('/[\x00-\x1F\x7F]/', $p)) {
            return null;
        }
        if ($p !== '/' && !preg_match('#^/(it|en)(/|$)#', $p)) {
            return null;
        }
        return $p === '/' ? '/' : rtrim($p, '/') . '/';
    }

    /** Da dove arriva il visitatore: [tipo, etichetta]. Il sito si riconosce dal dominio in site_url. */
    public static function source(string $referrer): array
    {
        if ($referrer === '') {
            return ['diretto', 'Accesso diretto'];
        }
        $u = parse_url($referrer);
        $host = strtolower((string) ($u['host'] ?? ''));
        $host = preg_replace('/^www\./', '', $host) ?? '';
        if ($host === '') {
            return ['diretto', 'Accesso diretto'];
        }
        $own = strtolower((string) preg_replace('/^www\./', '', (string) (parse_url((string) App::config('site_url'), PHP_URL_HOST) ?? '')));
        if ($own !== '' && $host === $own) {
            $path = self::path((string) ($u['path'] ?? '/'));
            return ['interno', $path ?? '/'];
        }
        if (preg_match('/google\.|bing\.|duckduckgo\.|yahoo\.|ecosia\.|yandex\./', $host)) {
            return ['motore', 'Motori di ricerca'];
        }
        if (preg_match('/facebook\.|instagram\.|linkedin\.|t\.co|twitter\.|x\.com|youtube\.|tiktok\./', $host)) {
            return ['social', 'Social network'];
        }
        return ['sito', substr($host, 0, 120)];
    }

    public static function device(string $ua): string
    {
        if (preg_match('/ipad|tablet|(android(?!.*mobile))/i', $ua)) {
            return 'Tablet';
        }
        return preg_match('/mobi|iphone|android|phone/i', $ua) ? 'Smartphone' : 'Computer';
    }

    public static function browser(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/edg/i', $ua) => 'Edge',
            (bool) preg_match('/opr\/|opera/i', $ua) => 'Opera',
            (bool) preg_match('/firefox|fxios/i', $ua) => 'Firefox',
            (bool) preg_match('/chrome|crios|chromium/i', $ua) => 'Chrome',
            (bool) preg_match('/safari/i', $ua) => 'Safari',
            default => 'Altro',
        };
    }

    /** Paese del visitatore (codice ISO) se è disponibile il database geografico; altrimenti null. L'IP non si salva. */
    public static function country(string $ip): ?string
    {
        if (!self::$geoTried) {
            self::$geoTried = true;
            $file = (string) App::config('geoip_file', '');
            if ($file !== '' && is_file($file)) {
                try {
                    self::$geo = new Reader($file);
                } catch (\Throwable) {
                    self::$geo = null;
                }
            }
        }
        if (self::$geo === null || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        try {
            $r = self::$geo->get($ip);
            $code = is_array($r) ? ($r['country']['iso_code'] ?? null) : null;
            return is_string($code) && preg_match('/^[A-Z]{2}$/', $code) ? $code : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Riepilogo degli ultimi $days giorni (oggi incluso).
     *
     * @return array<string, mixed>
     */
    public static function summary(int $days): array
    {
        $from = date('Y-m-d', time() - ($days - 1) * 86400);
        $st = App::db()->prepare('SELECT day, at, visitor, path, ref_kind, ref, device, browser, country FROM hits WHERE day >= ? ORDER BY visitor, at, id');
        $st->execute([$from]);
        $pages = 0;
        $visits = 0;
        $daily = [];
        $pageCount = $entry = $exit = $seqs = $sources = $devices = $browsers = $countries = [];
        $visitorDays = [];
        $unknownCountry = 0;
        $cur = null;
        $flush = static function (?array $v) use (&$entry, &$exit, &$seqs): void {
            if ($v === null || !$v['steps']) {
                return;
            }
            $entry[$v['steps'][0]] = ($entry[$v['steps'][0]] ?? 0) + 1;
            $exit[end($v['steps'])] = ($exit[end($v['steps'])] ?? 0) + 1;
            $steps = $v['steps'];
            for ($i = 0; $i + 2 < count($steps); $i++) {
                $key = $steps[$i] . '  →  ' . $steps[$i + 1] . '  →  ' . $steps[$i + 2];
                $seqs[$key] = ($seqs[$key] ?? 0) + 1;
            }
            if (count($steps) === 2) {
                $key = $steps[0] . '  →  ' . $steps[1];
                $seqs[$key] = ($seqs[$key] ?? 0) + 1;
            }
        };
        foreach ($st as $r) {
            $pages++;
            $d = (string) $r['day'];
            $daily[$d] ??= ['pages' => 0, 'visits' => 0];
            $daily[$d]['pages']++;
            $visitorDays[$d . '|' . $r['visitor']] = true;
            $pageCount[$r['path']] = ($pageCount[$r['path']] ?? 0) + 1;
            $label = (string) $r['ref'];
            $sources[$r['ref_kind'] . '|' . $label] = ($sources[$r['ref_kind'] . '|' . $label] ?? 0) + 1;
            $devices[$r['device']] = ($devices[$r['device']] ?? 0) + 1;
            $browsers[$r['browser']] = ($browsers[$r['browser']] ?? 0) + 1;
            if ($r['country'] !== null) {
                $countries[$r['country']] = ($countries[$r['country']] ?? 0) + 1;
            } else {
                $unknownCountry++;
            }
            $t = strtotime((string) $r['at']);
            $newVisit = $cur === null || $cur['visitor'] !== $r['visitor'] || $t - $cur['last'] > self::VISIT_GAP;
            if ($newVisit) {
                $flush($cur);
                $visits++;
                $daily[$d]['visits']++;
                $cur = ['visitor' => $r['visitor'], 'last' => $t, 'steps' => []];
            }
            $cur['last'] = $t;
            if (end($cur['steps']) !== $r['path']) {
                $cur['steps'][] = $r['path'];
            }
        }
        $flush($cur);
        arsort($pageCount);
        arsort($entry);
        arsort($exit);
        arsort($seqs);
        arsort($devices);
        arsort($browsers);
        arsort($countries);
        arsort($sources);
        $srcRows = [];
        foreach ($sources as $k => $n) {
            [$kind, $label] = explode('|', $k, 2);
            $srcRows[] = ['kind' => $kind, 'label' => $label, 'n' => $n];
        }
        $days_ = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', time() - $i * 86400);
            $days_[] = ['day' => $d, 'pages' => $daily[$d]['pages'] ?? 0, 'visits' => $daily[$d]['visits'] ?? 0];
        }
        return [
            'days' => $days, 'from' => $from, 'pages' => $pages, 'visits' => $visits,
            'visitors' => count($visitorDays), 'pages_per_visit' => $visits ? round($pages / $visits, 1) : 0,
            'daily' => $days_, 'top_pages' => self::top($pageCount, 12), 'entries' => self::top($entry, 8),
            'exits' => self::top($exit, 8), 'paths' => self::top($seqs, 8), 'sources' => array_slice($srcRows, 0, 10),
            'devices' => self::top($devices, 5), 'browsers' => self::top($browsers, 6),
            'countries' => self::top($countries, 12), 'unknown_country' => $unknownCountry,
            'geo_available' => (string) App::config('geoip_file', '') !== '' && is_file((string) App::config('geoip_file', '')),
        ];
    }

    /** @return array<int, array{label:string, n:int}> */
    private static function top(array $counts, int $limit): array
    {
        $out = [];
        foreach (array_slice($counts, 0, $limit, true) as $label => $n) {
            $out[] = ['label' => (string) $label, 'n' => (int) $n];
        }
        return $out;
    }
}
