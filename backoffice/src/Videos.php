<?php
declare(strict_types=1);

namespace Backoffice;

/**
 * Elenco di Video e Tutorial. Le voci del sito stanno in seed/video.json (copia di data/tutorial.json, sezione «video»).
 * Ogni voce è una riga della tabella content (tipo «video»): la bozza è la scheda intera, la versione pubblicata è
 * quella del sito. Il link (servizio + id) non si cambia: per un altro video si elimina e si aggiunge.
 */
final class Videos
{
    public const KIND = 'video';
    public const GROUPS = [
        'video' => 'Video',
        'agricoltura' => 'Agricoltura',
        'garden' => 'Garden',
        'olive' => 'Raccolta olive',
    ];
    private const TITLE_MAX = 120;

    private static ?array $seed = null;

    /** @return array<int, array> voci del sito, nell'ordine della videoteca */
    public static function seed(): array
    {
        if (self::$seed === null) {
            $f = dirname(__DIR__) . '/seed/video.json';
            $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : [];
            self::$seed = is_array($d) ? array_values($d) : [];
        }
        return self::$seed;
    }

    public static function key(string $service, string $id): string
    {
        return $service . ':' . $id;
    }

    /** Porta nell'elenco le voci del sito che non ci sono ancora (una volta sola; le modifiche non si toccano). */
    public static function importSeed(): int
    {
        $n = 0;
        foreach (self::seed() as $v) {
            if (Content::importLive(self::KIND, self::key($v['servizio'], $v['id']), self::clean($v))) {
                $n++;
            }
        }
        return $n;
    }

    /** Voci dell'elenco con lo stato: ordine di gruppo, poi ordine della videoteca; le nuove alla fine del loro gruppo. */
    public static function rows(): array
    {
        $order = [];
        foreach (self::seed() as $i => $v) {
            $order[self::key($v['servizio'], $v['id'])] = $i;
        }
        $groups = array_keys(self::GROUPS);
        $rows = [];
        foreach (Content::all(self::KIND) as $r) {
            $item = $r['draft'] ?: ($r['published'] ?? []);
            if (!$item) {
                continue;
            }
            $rows[] = ['key' => (string) $r['item_id'], 'item' => $item, 'row' => $r, 'status' => Content::status($r),
                'seed' => $order[(string) $r['item_id']] ?? PHP_INT_MAX];
        }
        usort($rows, static fn (array $a, array $b): int =>
            [array_search($a['item']['gruppo'], $groups, true), $a['seed'], $a['key']]
            <=> [array_search($b['item']['gruppo'], $groups, true), $b['seed'], $b['key']]);
        return $rows;
    }

    /** Dal link incollato: ['servizio' => youtube|vimeo, 'id' => ...], o null se non è un link riconosciuto. */
    public static function parseUrl(string $url): ?array
    {
        $url = trim($url);
        if (preg_match('~^(?:https?://)?(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})(?:[?&#/].*)?$~i', $url, $m)) {
            return ['servizio' => 'youtube', 'id' => $m[1]];
        }
        if (preg_match('~^(?:https?://)?(?:www\.|player\.)?vimeo\.com/(?:video/)?(\d{6,12})(?:[?/#].*)?$~i', $url, $m)) {
            return ['servizio' => 'vimeo', 'id' => $m[1]];
        }
        return null;
    }

    /**
     * Dal modulo: ['item' => voce, 'errors' => [...]]. Per una voce esistente servizio e id restano quelli della bozza.
     *
     * @param ?array $existing bozza attuale della voce (null per una nuova)
     * @return array{0: array, 1: string[]}
     */
    public static function fromForm(array $post, ?array $existing): array
    {
        $errors = [];
        $item = [];
        if ($existing) {
            $item = ['servizio' => (string) $existing['servizio'], 'id' => (string) $existing['id'], 'prodotto' => $existing['prodotto'] ?? null];
        } else {
            $parsed = self::parseUrl((string) ($post['url'] ?? ''));
            if (!$parsed) {
                $errors[] = 'Il link non è riconosciuto: incolla un link YouTube (youtu.be/… o youtube.com/watch?v=…) o Vimeo (vimeo.com/…).';
            } else {
                $item = $parsed;
                if (Content::find(self::KIND, self::key($parsed['servizio'], $parsed['id']))) {
                    $errors[] = 'Questo video è già nell\'elenco: aprilo dalla tabella per modificarlo.';
                }
            }
        }
        $group = (string) ($post['gruppo'] ?? '');
        if (!isset(self::GROUPS[$group])) {
            $errors[] = 'Scegli a quale gruppo appartiene il video.';
        }
        $title = self::line((string) ($post['titolo'] ?? ''));
        if ($title === '' || mb_strlen($title) > self::TITLE_MAX) {
            $errors[] = 'Il titolo (in italiano) è obbligatorio, al massimo ' . self::TITLE_MAX . ' caratteri.';
        }
        $titleEn = self::line((string) ($post['titolo_en'] ?? ''));
        if (mb_strlen($titleEn) > self::TITLE_MAX) {
            $errors[] = 'Il titolo in inglese è troppo lungo (massimo ' . self::TITLE_MAX . ' caratteri).';
        }
        $duration = trim((string) ($post['durata'] ?? ''));
        if ($duration !== '' && !preg_match('/^\d{1,2}:[0-5]\d$/', $duration)) {
            $errors[] = 'La durata va scritta come m:ss, per esempio 1:38 (oppure lasciata vuota).';
        }
        $item += ['gruppo' => $group, 'titolo' => $title];
        if ($titleEn !== '') {
            $item['titolo_en'] = $titleEn;
        }
        if ($duration !== '') {
            $item['durata'] = $duration;
        }
        if (($item['prodotto'] ?? null) === null) {
            unset($item['prodotto']);
        }
        return [$item, $errors];
    }

    /** Campi di una voce del sito, nell'ordine e con le chiavi di data/tutorial.json (senza note interne). */
    public static function clean(array $v): array
    {
        $out = ['gruppo' => $v['gruppo'], 'servizio' => $v['servizio'], 'id' => $v['id'], 'titolo' => $v['titolo']];
        foreach (['durata', 'prodotto', 'titolo_en'] as $k) {
            if (isset($v[$k])) {
                $out[$k] = $v[$k];
            }
        }
        return $out;
    }

    public static function url(array $item): string
    {
        return $item['servizio'] === 'vimeo' ? 'https://vimeo.com/' . $item['id'] : 'https://www.youtube.com/watch?v=' . $item['id'];
    }

    private static function line(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }
}
