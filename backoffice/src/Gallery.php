<?php
declare(strict_types=1);

namespace Backoffice;

/**
 * Galleria degli impianti: foto, categoria, testo alternativo (italiano e inglese) e ordine, in bozza.
 * Le foto nuove si conservano come originali (var/content/galleria/NNN.ext): le converte la build del sito
 * (tools/galleria_apply.py). Le 145 foto già sul sito si importano da seed/galleria.json (copia di data/galleria.json).
 */
final class Gallery
{
    public const KIND = 'gallery_photo';
    public const ORDER = 'gallery_order';
    public const CATEGORIES = ['impianti' => 'Impianti in campo', 'serre' => 'Serre e tunnel', 'dettagli' => 'Dettagli e accessori', 'disegni' => 'Disegni tecnici'];
    public const DEFAULT_ALT = 'Accessori ARNOplast su un impianto realizzato';
    public const MAX_BYTES = 10_000_000;
    public const MAX_FILES = 20;

    /** @return array[] foto del file dati (copia in seed/) */
    public static function seedItems(?string $file = null): array
    {
        $file ??= dirname(__DIR__) . '/seed/galleria.json';
        $d = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($d) ? (array) ($d['foto'] ?? []) : [];
    }

    /** Porta nel backoffice le foto già sul sito. Restituisce quante ne ha aggiunte. */
    public static function importSeed(?string $file = null): int
    {
        $items = self::seedItems($file);
        $n = 0;
        foreach ($items as $it) {
            if (isset($it['id']) && Content::importLive(self::KIND, (string) $it['id'], $it)) {
                $n++;
            }
        }
        if ($items) {
            Content::importLive(self::ORDER, 'order', ['ids' => array_map(static fn (array $i): string => (string) $i['id'], $items)]);
        }
        return $n;
    }

    /** @return array<string,array> righe per id foto */
    public static function rows(): array
    {
        $out = [];
        foreach (Content::all(self::KIND) as $r) {
            $out[(string) $r['item_id']] = $r;
        }
        return $out;
    }

    /** Identificativi nell'ordine della bozza (le foto non nell'elenco in coda). @return string[] */
    public static function order(?array $rows = null): array
    {
        $rows ??= self::rows();
        $o = Content::find(self::ORDER, 'order');
        $ids = array_values(array_filter((array) ($o['draft']['ids'] ?? []), static fn ($id): bool => isset($rows[$id])));
        $ids = array_map('strval', $ids);
        $rest = array_map('strval', array_diff(array_keys($rows), $ids));
        sort($rest, SORT_STRING);
        return array_merge($ids, $rest);
    }

    public static function orderChanged(): bool
    {
        $o = Content::find(self::ORDER, 'order');
        return $o !== null && Content::status($o) !== Content::LIVE;
    }

    public static function pending(): int
    {
        $n = count(array_filter(self::rows(), static fn (array $r): bool => Content::status($r) !== Content::LIVE));
        return $n + (self::orderChanged() ? 1 : 0);
    }

    /**
     * Modifica una foto. Restituisce gli errori (frasi).
     *
     * @return string[]
     */
    public static function save(string $id, string $category, string $alt, string $altEn, ?int $position, ?int $userId): array
    {
        $row = self::rows()[$id] ?? null;
        if (!$row) {
            return ['Foto non trovata.'];
        }
        $errors = [];
        $alt = self::line($alt, 200);
        $altEn = self::line($altEn, 200);
        if (!isset(self::CATEGORIES[$category])) {
            $errors[] = 'Scegli una categoria.';
        }
        if ($alt === '') {
            $errors[] = 'Scrivi il testo alternativo in italiano (descrive la foto a chi non la vede).';
        }
        if ($errors) {
            return $errors;
        }
        $d = $row['draft'];
        $d['categoria'] = $category;
        $d['alt'] = $alt;
        if ($altEn === '') {
            unset($d['alt_en']);
        } else {
            $d['alt_en'] = $altEn;
        }
        Content::saveDraft(self::KIND, $id, $d, $userId);
        if ($position !== null) {
            self::move($id, $position, $userId);
        }
        return [];
    }

    /** Sposta la foto alla posizione indicata (1 = prima). */
    public static function move(string $id, int $position, ?int $userId): void
    {
        $ids = array_values(array_diff(self::order(), [$id]));
        $position = max(1, min(count($ids) + 1, $position));
        array_splice($ids, $position - 1, 0, [$id]);
        Content::saveDraft(self::ORDER, 'order', ['ids' => $ids], $userId);
    }

    public static function position(string $id): int
    {
        $i = array_search($id, self::order(), true);
        return $i === false ? 0 : $i + 1;
    }

    /** Elimina: una foto nuova sparisce subito (anche il file); una pubblicata resta «da eliminare» fino a «Pubblica». */
    public static function delete(string $id, ?int $userId): void
    {
        $row = self::rows()[$id] ?? null;
        if (!$row) {
            return;
        }
        if ($row['published'] === null) {
            foreach (glob(self::dir() . '/' . $id . '.*') ?: [] as $f) {
                @unlink($f);
            }
            $ids = array_values(array_diff(self::order(), [$id]));
            Content::markDeleted(self::KIND, $id, $userId);
            $o = Content::find(self::ORDER, 'order');
            if ($o) {
                Content::saveDraft(self::ORDER, 'order', ['ids' => $ids], $userId);
            }
            return;
        }
        Content::markDeleted(self::KIND, $id, $userId);
    }

    public static function dir(): string
    {
        return dirname(__DIR__) . '/var/content/galleria';
    }

    /**
     * Aggiunge foto dal modulo (più file). Restituisce [identificativi aggiunti, errori].
     *
     * @return array{0:string[], 1:string[]}
     */
    public static function addUploads(array $files, string $category, ?int $userId): array
    {
        if (!isset(self::CATEGORIES[$category])) {
            return [[], ['Scegli la categoria delle foto nuove.']];
        }
        $list = self::normalize($files);
        if (!$list) {
            return [[], ['Scegli almeno una foto da caricare.']];
        }
        if (count($list) > self::MAX_FILES) {
            return [[], ['Al massimo ' . self::MAX_FILES . ' foto per volta.']];
        }
        $added = $errors = [];
        foreach ($list as $f) {
            $name = (string) ($f['name'] ?? 'foto');
            [$ext, $err] = self::check($f);
            if ($err !== null) {
                $errors[] = $name . ': ' . $err;
                continue;
            }
            $id = self::nextId();
            if ($id === null) {
                $errors[] = 'La galleria è piena (massimo 999 foto).';
                break;
            }
            if (!is_dir(self::dir()) && !@mkdir(self::dir(), 0750, true)) {
                $errors[] = 'Non riesco a salvare le foto sul server (cartella var non scrivibile).';
                break;
            }
            $file = $id . '.' . $ext;
            $moved = defined('BACKOFFICE_TESTING') ? @copy((string) $f['tmp_name'], self::dir() . '/' . $file) : @move_uploaded_file((string) $f['tmp_name'], self::dir() . '/' . $file);
            if (!$moved) {
                $errors[] = $name . ': non riesco a salvare il file.';
                continue;
            }
            Content::saveDraft(self::KIND, $id, ['id' => $id, 'categoria' => $category, 'alt' => self::DEFAULT_ALT, 'sorgente' => $file], $userId);
            $ids = self::order();
            $ids = array_values(array_unique(array_merge($ids, [$id])));
            Content::saveDraft(self::ORDER, 'order', ['ids' => $ids], $userId);
            $added[] = $id;
        }
        return [$added, $errors];
    }

    /** La galleria nel formato di data/galleria.json (in bozza o, con $published, come è sul sito). */
    public static function export(bool $published = false): array
    {
        $rows = self::rows();
        $items = [];
        foreach (self::order($rows) as $id) {
            $r = $rows[$id];
            $d = $published ? $r['published'] : ($r['deleted'] ? null : $r['draft']);
            if ($d !== null && $d !== []) {
                $items[] = $d;
            }
        }
        return ['foto' => $items];
    }

    /** Percorso del file originale di una foto nuova, o null. */
    public static function original(string $id): ?string
    {
        if (!preg_match('/^\d{3}$/', $id)) {
            return null;
        }
        $f = glob(self::dir() . '/' . $id . '.*') ?: [];
        return $f ? $f[0] : null;
    }

    private static function nextId(): ?string
    {
        $max = 0;
        foreach (array_keys(self::rows()) as $id) {
            $max = max($max, (int) $id);
        }
        foreach (glob(self::dir() . '/*') ?: [] as $f) {
            $max = max($max, (int) basename($f));
        }
        return $max >= 999 ? null : str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /** @return array{0:?string, 1:?string} [estensione, errore] */
    private static function check(array $f): array
    {
        $uploaded = defined('BACKOFFICE_TESTING') || is_uploaded_file((string) ($f['tmp_name'] ?? ''));
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !$uploaded) {
            return [null, 'caricamento non riuscito (file troppo grande per il server?)'];
        }
        if (($f['size'] ?? 0) > self::MAX_BYTES) {
            return [null, 'troppo grande (massimo 10 MB)'];
        }
        $info = @getimagesize((string) $f['tmp_name']);
        $ext = match ($info[2] ?? 0) {
            IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', default => null,
        };
        if ($ext === null) {
            return [null, 'deve essere un file JPG, PNG o WebP'];
        }
        if (max($info[0] ?? 0, $info[1] ?? 0) < 600) {
            return [null, 'troppo piccola (almeno 600 pixel sul lato lungo)'];
        }
        return [$ext, null];
    }

    /** @return array[] file nel formato semplice, saltando i campi vuoti */
    private static function normalize(array $files): array
    {
        if (!isset($files['name']) || !is_array($files['name'])) {
            return isset($files['error']) && $files['error'] !== UPLOAD_ERR_NO_FILE ? [$files] : [];
        }
        $out = [];
        foreach (array_keys($files['name']) as $i) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i], 'size' => $files['size'][$i], 'error' => $files['error'][$i]];
        }
        return $out;
    }

    private static function line(string $v, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v)), 0, $max);
    }
}
