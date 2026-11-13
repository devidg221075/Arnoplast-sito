<?php
declare(strict_types=1);

namespace Backoffice;

/**
 * Listini con i prezzi, riservati ai commerciali. I PDF stanno in var/listini (fuori dalla cartella pubblica) e si
 * scaricano solo da index.php?p=listino_file, dopo l'accesso. Ogni download finisce nel registro attività.
 */
final class Listini
{
    public const KIND = 'listino';
    public const MAX_BYTES = 20_000_000;
    public const LANGS = ['it' => 'Italiano', 'en' => 'Inglese'];

    private static ?string $dir = null;

    /** Solo per le prove: cartella diversa da var/listini. */
    public static function useDir(string $dir): void
    {
        self::$dir = $dir;
    }

    public static function dir(): string
    {
        return self::$dir ?? dirname(__DIR__) . '/var/listini';
    }

    /** Listini in vigore, dal più recente. */
    public static function current(): array
    {
        $out = [];
        foreach (Content::all(self::KIND) as $r) {
            if ($r['published'] !== null && !$r['deleted']) {
                $out[] = ['id' => (string) $r['item_id'], 'item' => $r['published']];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string) $b['item']['caricato'], (string) $a['item']['caricato']));
        return $out;
    }

    /**
     * Carica un listino. $tmp è il file caricato; il nome originale serve solo per il download.
     * @return array{0: ?string, 1: ?string} [id, errore]
     */
    public static function save(string $tmp, int $size, string $originalName, string $title, string $lang, ?int $userId): array
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ($title === '' || mb_strlen($title) > 150) {
            return [null, 'Il titolo è obbligatorio, al massimo 150 caratteri.'];
        }
        if (!isset(self::LANGS[$lang])) {
            return [null, 'Scegli la lingua del listino.'];
        }
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return [null, 'Il file è troppo grande (massimo 20 MB) oppure vuoto.'];
        }
        $head = is_file($tmp) ? (string) file_get_contents($tmp, false, null, 0, 5) : '';
        if ($head !== '%PDF-') {
            return [null, 'Il file non è un PDF.'];
        }
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            return [null, 'Cartella dei listini non scrivibile sul server.'];
        }
        $id = bin2hex(random_bytes(8));
        $stored = $id . '.pdf';
        if (!@copy($tmp, $dir . '/' . $stored)) {
            return [null, 'Il file non è stato salvato.'];
        }
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($originalName)) ?: 'listino.pdf';
        Content::importLive(self::KIND, $id, [
            'titolo' => $title, 'lingua' => $lang, 'file' => $stored, 'nome_file' => mb_substr($name, 0, 120),
            'dimensione' => $size, 'caricato' => App::now(),
        ]);
        App::log($userId, 'listino.caricato', $id, $title);
        return [$id, null];
    }

    /** Percorso del PDF di un listino in vigore, o null. */
    public static function path(string $id): ?string
    {
        $r = Content::find(self::KIND, $id);
        if (!$r || $r['published'] === null || $r['deleted']) {
            return null;
        }
        $file = self::dir() . '/' . basename((string) $r['published']['file']);
        return is_file($file) ? $file : null;
    }

    /** Toglie un listino dal sito riservato e cancella il PDF. */
    public static function remove(string $id, ?int $userId): void
    {
        $path = self::path($id);
        Content::markDeleted(self::KIND, $id, $userId);
        if ($path !== null) {
            @unlink($path);
        }
        App::log($userId, 'listino.eliminato', $id);
    }
}
