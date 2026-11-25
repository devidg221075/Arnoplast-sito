<?php
declare(strict_types=1);

namespace Backoffice;

/**
 * «Pubblica»: porta le bozze di Testi, Fiere, Galleria e Video nel repository del sito (un solo commit). GitHub Actions
 * ricostruisce il sito e lo consegna via FTP. Dopo il commit le bozze diventano la versione pubblicata.
 */
final class Publisher
{
    /** Cartella delle foto nuove nel repository (le converte la build). */
    private const FAIR_PHOTOS = 'content/photos/fiere/';
    private const GALLERY_PHOTOS = 'content/photos/galleria/';
    /** Copia dei dati nel pacchetto del backoffice (usata solo alla prima importazione): tenuta uguale ai dati del sito a ogni pubblicazione. */
    private const SEED = 'backoffice/seed/';

    /** @return array<string,int> modifiche in attesa per sezione (solo quelle con modifiche) */
    public static function pending(): array
    {
        $out = [
            'Testi' => array_sum(Texts::pendingByGroup()),
            'Fiere e Open Day' => Content::pending(Fairs::KIND),
            'Galleria' => Gallery::pending(),
            'Video e tutorial' => Content::pending(Videos::KIND),
            'Catalogo' => CatalogPatch::pendingCount(),
        ];
        return array_filter($out, static fn (int $n): bool => $n > 0);
    }

    /**
     * Tutti i file da scrivere nel repository, solo quelli che cambiano.
     *
     * @param callable(string):?string $read legge un file del repository (o null)
     * @return array<string,string> [percorso => contenuto]
     */
    public static function files(callable $read): array
    {
        $pending = self::pending();
        $files = [];
        $put = static function (string $path, string $content) use (&$files, $read): void {
            if ($read($path) !== $content) {
                $files[$path] = $content;
            }
        };
        if (isset($pending['Testi'])) {
            // Le lingue senza correzioni vanno scritte come «{}» (oggetto vuoto), non «[]»: la build vuole una mappa.
            $o = Texts::overrides(false);
            $put('data/testi.json', self::json(self::keepNote($read('data/testi.json'), ['it' => $o['it'] ?: new \stdClass(), 'en' => $o['en'] ?: new \stdClass()])));
        }
        if (isset($pending['Fiere e Open Day'])) {
            $fairs = self::fairs($read('data/fiere.json'));
            $json = self::json(self::keepNote($read('data/fiere.json'), ['fiere' => $fairs]));
            $put('data/fiere.json', $json);
            $put(self::SEED . 'fiere.json', $json);
            foreach (Content::all(Fairs::KIND) as $r) {
                $img = (string) ($r['draft']['immagine'] ?? '');
                $src = dirname(__DIR__) . '/var/content/fiere/' . basename($img);
                if (!$r['deleted'] && $img !== '' && Content::status($r) !== Content::LIVE && is_file($src)) {
                    $files[self::FAIR_PHOTOS . basename($img)] = (string) file_get_contents($src);
                }
            }
        }
        if (isset($pending['Galleria'])) {
            $export = Gallery::export(false);
            $json = self::json($export, 1); // come il file del sito: un solo spazio per livello
            $put('data/galleria.json', $json);
            $put(self::SEED . 'galleria.json', $json);
            foreach ($export['foto'] as $item) {
                $name = (string) ($item['sorgente'] ?? '');
                $src = $name === '' ? '' : Gallery::dir() . '/' . basename($name);
                if ($name !== '' && is_file($src)) {
                    $files[self::GALLERY_PHOTOS . basename($name)] = (string) file_get_contents($src);
                }
            }
        }
        if (isset($pending['Video e tutorial'])) {
            $videos = [];
            foreach (Videos::rows() as $v) {
                if (!$v['row']['deleted']) {
                    $videos[] = $v['item'];
                }
            }
            $put('data/tutorial.json', self::json(self::keepNote($read('data/tutorial.json'), ['video' => $videos])));
            $put(self::SEED . 'video.json', self::json($videos));
        }
        if (isset($pending['Catalogo'])) {
            // La patch si applica ai file attuali del repository (non al pacchetto): così non si perde nulla di quanto è già pubblicato.
            $cat = json_decode((string) $read('data/catalog.json'), true);
            $enP = json_decode((string) $read('data/en_prodotti.json'), true);
            $enA = json_decode((string) $read('data/en_articoli.json'), true);
            if (!is_array($cat) || !isset($cat['lines']) || !is_array($enP) || !is_array($enA)) {
                throw new \RuntimeException('Non riesco a leggere il catalogo dal repository: pubblicazione del catalogo annullata.');
            }
            foreach (Content::all(CatalogPatch::KIND) as $r) {
                if (!$r['deleted']) {
                    [$cat, $enP, $enA] = CatalogPatch::apply($cat, $enP, $enA, (string) $r['item_id'], $r['draft']);
                }
            }
            foreach (['data/catalog.json' => [$cat, 2, 'catalogo.json'], 'data/en_prodotti.json' => [$enP, 1, 'en_prodotti.json'], 'data/en_articoli.json' => [$enA, 1, 'en_articoli.json']] as $path => [$data, $ind, $seed]) {
                $json = self::json($data, $ind);
                $put($path, $json);
                $put(self::SEED . $seed, $json);
            }
        }
        return $files;
    }

    /**
     * Pubblica: un commit, poi le bozze diventano pubblicate. Restituisce [indirizzo del commit, sezioni pubblicate].
     * @return array{0:string, 1:string[]}
     */
    public static function publish(GitHub $gh, ?int $userId): array
    {
        $pending = self::pending();
        if (!$pending) {
            throw new \RuntimeException('Non ci sono modifiche da pubblicare.');
        }
        $files = self::files($gh->read(...));
        $url = '';
        if ($files) {
            [, $url] = $gh->commit($files, 'Contenuti dal backoffice: ' . implode(', ', array_keys($pending)));
        }
        foreach (array_keys($pending) as $section) {
            foreach (match ($section) {
                'Testi' => [Texts::KIND], 'Fiere e Open Day' => [Fairs::KIND], 'Galleria' => [Gallery::KIND, Gallery::ORDER], 'Catalogo' => [CatalogPatch::KIND], default => [Videos::KIND],
            } as $kind) {
                Content::publishAll($kind);
            }
        }
        App::log($userId, 'sito.pubblicato', implode(', ', array_keys($pending)), $url !== '' ? $url : 'nessun file cambiato');
        return [$url, array_keys($pending)];
    }

    /** Le fiere nel formato del file dati: l'ordine del file attuale, poi le nuove. */
    private static function fairs(?string $current): array
    {
        $byId = [];
        foreach (Content::all(Fairs::KIND) as $r) {
            if (!$r['deleted'] && $r['draft']) {
                $byId[(string) $r['item_id']] = $r['draft'];
            }
        }
        $out = [];
        $cur = $current !== null ? json_decode($current, true) : null;
        foreach (is_array($cur) ? ($cur['fiere'] ?? []) : [] as $f) {
            if (isset($f['id'], $byId[$f['id']])) {
                $out[] = $byId[$f['id']];
                unset($byId[$f['id']]);
            }
        }
        $rest = array_values($byId);
        usort($rest, static fn (array $a, array $b): int => strcmp((string) ($a['data_inizio'] ?? ''), (string) ($b['data_inizio'] ?? '')));
        return array_merge($out, $rest);
    }

    /** Mantiene la nota in testa al file dati (se c'è) e aggiunge i dati nuovi. */
    private static function keepNote(?string $current, array $data): array
    {
        $cur = $current !== null ? json_decode($current, true) : null;
        return is_array($cur) && isset($cur['_nota']) ? ['_nota' => $cur['_nota']] + $data : $data;
    }

    private static function json(array|object $d, int $indent = 2): string
    {
        $s = (string) json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        // Due spazi per livello, come i file del sito: i confronti su GitHub restano leggibili.
        $s = (string) preg_replace_callback('/^( +)/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), (int) (4 / $indent))), $s);
        return $s . "\n";
    }
}
