<?php
declare(strict_types=1);

namespace Backoffice;

/**
 * Fiere e Open Day: stessi dati e stessi controlli di data/fiere.json del sito (la build rifiuta le voci non valide,
 * quindi il backoffice non deve farle salvare). Una fiera con data di fine passata passa da sola in «Fiere passate».
 */
final class Fairs
{
    public const KIND = 'fair';
    private const MONTHS = ['it' => ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'],
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']];
    public const IMAGE_MAX = 6_000_000;
    /** Campi della scheda (chiave del file dati => etichetta e lunghezza massima). */
    public const TEXT_FIELDS = ['nome' => 150, 'luogo' => 120, 'paese' => 80, 'periodo' => 80, 'stand' => 120, 'testo' => 3000, 'descrizione_breve' => 400,
        'luogo_en' => 120, 'paese_en' => 80, 'periodo_en' => 80, 'stand_en' => 120, 'testo_en' => 3000];

    /** Identificativo dal nome e dall'anno: "EIMA" 2026 → eima-2026. */
    public static function makeId(string $name, string $year): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', self::ascii($name)));
        $slug = trim($slug, '-');
        return trim($slug . ($year !== '' ? '-' . $year : ''), '-');
    }

    /**
     * Legge il modulo. Restituisce [fiera nel formato del file dati, errori].
     *
     * @param array|null $existing voce esistente (l'identificativo e la foto già scelta non cambiano da qui)
     * @return array{0:array, 1:string[]}
     */
    public static function fromForm(array $in, ?array $existing, ?string $newImage = null): array
    {
        $errors = [];
        $f = [];
        foreach (self::TEXT_FIELDS as $k => $max) {
            $v = $k === 'testo' || $k === 'testo_en' ? self::text((string) ($in[$k] ?? ''), $max) : self::line((string) ($in[$k] ?? ''), $max);
            if (mb_strlen($v) >= $max && mb_strlen((string) ($in[$k] ?? '')) > $max) {
                $errors[] = 'Il campo «' . self::label($k) . '» è troppo lungo (massimo ' . $max . ' caratteri).';
            }
            $f[$k] = $v;
        }
        foreach (['nome' => 'nome', 'luogo' => 'luogo', 'paese' => 'paese'] as $k => $label) {
            if ($f[$k] === '') {
                $errors[] = "Scrivi il $label della fiera.";
            }
        }
        foreach (['data_inizio', 'data_fine'] as $k) {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v !== '' && !self::validDate($v)) {
                $errors[] = 'La data non è valida (usa il selettore o AAAA-MM-GG).';
                $v = '';
            }
            $f[$k] = $v;
        }
        if ($f['data_inizio'] !== '' && $f['data_fine'] === '') {
            $f['data_fine'] = $f['data_inizio'];
        }
        if ($f['data_inizio'] === '' && $f['data_fine'] !== '') {
            $f['data_inizio'] = $f['data_fine'];
        }
        if ($f['data_inizio'] !== '' && $f['data_fine'] < $f['data_inizio']) {
            $errors[] = 'La data di fine è precedente a quella di inizio.';
        }
        $year = trim((string) ($in['anno'] ?? ''));
        if ($f['data_inizio'] !== '') {
            $year = substr($f['data_inizio'], 0, 4);
        }
        if (!preg_match('/^(19|20)\d{2}$/', $year)) {
            $errors[] = 'Indica l\'anno (se non hai le date) con quattro cifre.';
            $year = '';
        }
        $f['anno'] = $year === '' ? 0 : (int) $year;
        $link = trim((string) ($in['link'] ?? ''));
        if ($link !== '' && !preg_match('~^https://[^\s/]+\.[^\s]+$~', $link)) {
            $errors[] = 'Il link del sito della fiera deve cominciare con https://';
            $link = '';
        }
        $f['link'] = $link;
        $focal = trim((string) ($in['punto_focale'] ?? ''));
        if ($focal !== '' && !preg_match('/^(?:left|right|top|bottom|center|\d{1,3}(?:\.\d+)?%)(?: (?:left|right|top|bottom|center|\d{1,3}(?:\.\d+)?%))?$/', $focal)) {
            $errors[] = 'Il punto focale della foto non è valido (esempi: center, 30% 50%, left top).';
            $focal = '';
        }
        $f['punto_focale'] = $focal;
        $f['immagine'] = $newImage ?? (string) ($existing['immagine'] ?? '');
        if (($in['remove_image'] ?? '') === '1' && $newImage === null) {
            $f['immagine'] = '';
        }
        $f['fonte_url_sito_vecchio'] = (string) ($existing['fonte_url_sito_vecchio'] ?? '');
        $f['id'] = $existing['id'] ?? self::makeId($f['nome'], $year);
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $f['id'])) {
            $errors[] = 'Dal nome non si ricava un identificativo valido: usa lettere e numeri nel nome.';
        }
        // Come nel file dati: i campi facoltativi vuoti non si scrivono.
        foreach (['descrizione_breve', 'link', 'immagine', 'punto_focale', 'luogo_en', 'paese_en', 'testo_en', 'periodo_en', 'stand_en'] as $k) {
            if ($f[$k] === '') {
                unset($f[$k]);
            }
        }
        return [$f, array_values(array_unique($errors))];
    }

    /** Controlla una fiera già nel formato del file dati; stessi criteri di `load_fairs` della build. @return string[] */
    public static function validate(array $f): array
    {
        $e = [];
        foreach (['id', 'nome', 'luogo', 'paese', 'data_inizio', 'data_fine', 'anno', 'periodo', 'stand', 'testo', 'fonte_url_sito_vecchio'] as $k) {
            if (!array_key_exists($k, $f)) {
                $e[] = "manca $k";
            }
        }
        if ($e) {
            return $e;
        }
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $f['id'])) {
            $e[] = 'id non valido';
        }
        foreach (['data_inizio', 'data_fine'] as $k) {
            if ($f[$k] !== '' && !self::validDate((string) $f[$k])) {
                $e[] = "$k non valida";
            }
        }
        if ($f['data_inizio'] !== '' && $f['data_fine'] !== '' && $f['data_fine'] < $f['data_inizio']) {
            $e[] = 'data_fine precedente a data_inizio';
        }
        if (($f['link'] ?? '') !== '' && !preg_match('~^https://[^\s/]+\.[^\s]+$~', (string) $f['link'])) {
            $e[] = 'link non valido';
        }
        return $e;
    }

    /** Date leggibili, come nelle schede del sito: «10–14 novembre 2026»; senza date il periodo o l'anno. */
    public static function dates(array $f, string $lang = 'it'): string
    {
        $m = self::MONTHS[$lang === 'en' ? 'en' : 'it'];
        if (($f['data_inizio'] ?? '') === '') {
            return (($lang === 'en' ? ($f['periodo_en'] ?? '') : '') ?: ($f['periodo'] ?? '')) ?: (string) ($f['anno'] ?? '');
        }
        $a = new \DateTimeImmutable($f['data_inizio']);
        $b = new \DateTimeImmutable($f['data_fine'] ?: $f['data_inizio']);
        $ad = (int) $a->format('j');
        $bd = (int) $b->format('j');
        $am = $m[(int) $a->format('n') - 1];
        $bm = $m[(int) $b->format('n') - 1];
        if ($a == $b) {
            return "$ad $am " . $a->format('Y');
        }
        if ($a->format('Y-n') === $b->format('Y-n')) {
            return "{$ad}–{$bd} {$am} " . $a->format('Y');
        }
        if ($a->format('Y') === $b->format('Y')) {
            return "{$ad} {$am} – {$bd} {$bm} " . $a->format('Y');
        }
        return "{$ad} {$am} " . $a->format('Y') . " – {$bd} {$bm} " . $b->format('Y');
    }

    /** Data a partire dalla quale la fiera è «passata»: la fine, o l'inizio, o il 31 dicembre dell'anno. */
    public static function endDate(array $f): string
    {
        foreach (['data_fine', 'data_inizio'] as $k) {
            if (($f[$k] ?? '') !== '') {
                return (string) $f[$k];
            }
        }
        return ((int) ($f['anno'] ?? 0)) . '-12-31';
    }

    public static function isPast(array $f, ?string $today = null): bool
    {
        return self::endDate($f) < ($today ?? date('Y-m-d'));
    }

    /** Importa le fiere del file dati (copia in seed/). Restituisce quante ne ha aggiunte. */
    public static function importSeed(?string $file = null): int
    {
        $file ??= dirname(__DIR__) . '/seed/fiere.json';
        $d = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $n = 0;
        foreach (is_array($d) ? ($d['fiere'] ?? []) : [] as $f) {
            if (is_array($f) && isset($f['id']) && Content::importLive(self::KIND, (string) $f['id'], $f)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Foto caricata dal modulo: solo JPG, PNG o WebP, entro 6 MB, riconosciuti dal contenuto (non dal nome).
     * Si salva in var/content/fiere/<id>.<estensione>. @return array{0:?string, 1:?string} [nome file, errore]
     */
    public static function storeImage(?array $file, string $id): array
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }
        $uploaded = defined('BACKOFFICE_TESTING') || is_uploaded_file((string) ($file['tmp_name'] ?? ''));
        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK || !$uploaded) {
            return [null, 'Il caricamento della foto non è riuscito: riprova con un file più piccolo.'];
        }
        if (($file['size'] ?? 0) > self::IMAGE_MAX) {
            return [null, 'La foto è troppo grande (massimo 6 MB): riducila e riprova.'];
        }
        $info = @getimagesize((string) $file['tmp_name']);
        $ext = match ($info[2] ?? 0) {
            IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', default => null,
        };
        if ($ext === null) {
            return [null, 'La foto deve essere un file JPG, PNG o WebP.'];
        }
        if (($info[0] ?? 0) < 400 || ($info[1] ?? 0) < 250) {
            return [null, 'La foto è troppo piccola (almeno 400 × 250 pixel).'];
        }
        $dir = dirname(__DIR__) . '/var/content/fiere';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            return [null, 'Non riesco a salvare la foto sul server (cartella var non scrivibile).'];
        }
        $name = $id . '.' . $ext;
        foreach (glob($dir . '/' . $id . '.*') ?: [] as $old) {
            @unlink($old);
        }
        $moved = defined('BACKOFFICE_TESTING') ? @copy((string) $file['tmp_name'], $dir . '/' . $name) : @move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $name);
        return $moved ? [$name, null] : [null, 'Non riesco a salvare la foto sul server.'];
    }

    public static function label(string $k): string
    {
        return ['nome' => 'Nome', 'luogo' => 'Luogo', 'paese' => 'Paese', 'periodo' => 'Periodo', 'stand' => 'Stand', 'testo' => 'Testo', 'descrizione_breve' => 'Descrizione breve',
            'luogo_en' => 'Luogo (inglese)', 'paese_en' => 'Paese (inglese)', 'periodo_en' => 'Periodo (inglese)', 'stand_en' => 'Stand (inglese)', 'testo_en' => 'Testo (inglese)'][$k] ?? $k;
    }

    private static function validDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }

    private static function line(string $v, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v)), 0, $max);
    }

    private static function text(string $v, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', str_replace(["\r\n", "\r"], "\n", $v))), 0, $max);
    }

    private static function ascii(string $s): string
    {
        return strtr($s, ['à' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'À' => 'A', 'È' => 'E', 'É' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss']);
    }
}
