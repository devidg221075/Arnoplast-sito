<?php
declare(strict_types=1);

namespace Backoffice;

/**
 * Testi fissi del sito (titoli, pulsanti, descrizioni) raggruppati per pagina. Gli originali stanno in seed/testi.json
 * (esportato dal sito); qui si salvano solo le correzioni, come bozza. Campo vuoto = testo originale.
 */
final class Texts
{
    public const KIND = 'text';
    private static ?array $seed = null;

    public static function seed(): array
    {
        if (self::$seed === null) {
            $f = dirname(__DIR__) . '/seed/testi.json';
            $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
            self::$seed = is_array($d) ? $d : ['groups' => [], 'texts' => [], 'max_len' => 400];
        }
        return self::$seed;
    }

    public static function group(string $id): ?array
    {
        foreach (self::seed()['groups'] as $g) {
            if ($g['id'] === $id) {
                return $g;
            }
        }
        return null;
    }

    /** @return array<string,array> bozze per chiave (solo le voci con correzioni) */
    public static function drafts(): array
    {
        $out = [];
        foreach (Content::all(self::KIND) as $r) {
            $out[(string) $r['item_id']] = $r;
        }
        return $out;
    }

    /** Quante correzioni in sospeso per gruppo: [id gruppo => numero]. */
    public static function pendingByGroup(): array
    {
        $drafts = self::drafts();
        $out = [];
        foreach (self::seed()['groups'] as $g) {
            $out[$g['id']] = count(array_filter($g['keys'], static fn (string $k): bool => isset($drafts[$k]) && Content::status($drafts[$k]) !== Content::LIVE));
        }
        return $out;
    }

    /** Gruppi riservati all'amministratore (dati societari, collegamenti): il redattore non li vede né li modifica. */
    public static function canEdit(array $group, bool $admin): bool
    {
        return $admin || !($group['admin_only'] ?? false);
    }

    /** Controlli di formato dei valori che non sono frasi (stessi della build): indirizzi https ed e-mail. */
    public static function valueProblem(string $key, string $v): ?string
    {
        if (str_starts_with($key, 'link.') && !preg_match('#^https://[^\s<>"\']{4,300}$#', $v)) {
            return 'deve essere un indirizzo che inizia con https://';
        }
        if ($key === 'corp.email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            return 'deve essere un indirizzo e-mail valido';
        }
        return null;
    }

    /** Lunghezza massima di un testo: una riga breve, oppure più lungo per i paragrafi (storia, imballaggi). */
    public static function maxLen(string $key): int
    {
        $seed = self::seed();
        foreach ((array) ($seed['long_prefixes'] ?? []) as $prefix) {
            if (str_starts_with($key, (string) $prefix)) {
                return (int) ($seed['max_len_long'] ?? 3000);
            }
        }
        return (int) ($seed['max_len'] ?? 400);
    }

    /**
     * Salva le correzioni di un gruppo. $it e $en: chiave → testo scritto. Restituisce gli errori (chiave → frase);
     * le voci valide si salvano comunque.
     *
     * @return array<string,string>
     */
    public static function saveGroup(string $groupId, array $it, array $en, ?int $userId): array
    {
        $g = self::group($groupId);
        if (!$g) {
            return ['' => 'Gruppo non trovato.'];
        }
        $seed = self::seed();
        $errors = [];
        if ($g['neutral'] ?? false) {
            $en = $it; // dati uguali per ogni lingua: una sola colonna
        }
        foreach ($g['keys'] as $key) {
            $base = $seed['texts'][$key];
            $max = self::maxLen($key);
            $draft = [];
            foreach (['it' => $it, 'en' => $en] as $lang => $values) {
                $v = trim((string) preg_replace('/\s+/u', ' ', (string) ($values[$key] ?? '')));
                if ($v === '' || $v === $base[$lang]) {
                    continue; // vuoto o uguale all'originale: nessuna correzione
                }
                if (mb_strlen($v) > $max || preg_match('/[\x00-\x1F\x7F]/u', $v)) {
                    $errors[$key] = "«{$key}» ({$lang}): massimo {$max} caratteri.";
                    continue 2;
                }
                if (($problem = self::valueProblem($key, $v)) !== null) {
                    $errors[$key] = "«{$key}»: {$problem}.";
                    continue 2;
                }
                $draft[$lang] = $v;
            }
            $row = Content::find(self::KIND, $key);
            if ($draft) {
                Content::saveDraft(self::KIND, $key, $draft, $userId);
            } elseif ($row) {
                if ($row['published'] === null) {
                    App::db()->prepare('DELETE FROM content WHERE kind = ? AND item_id = ?')->execute([self::KIND, $key]);
                } elseif ($row['draft'] !== []) {
                    Content::saveDraft(self::KIND, $key, [], $userId); // torna all'originale al prossimo «Pubblica»
                }
            }
        }
        return $errors;
    }

    /**
     * Le correzioni nel formato di data/testi.json (quello che la build legge): ['it' => [chiave => testo], 'en' => [...]].
     * @param bool $published true = versione pubblicata, false = bozza
     */
    public static function overrides(bool $published = false): array
    {
        $out = ['it' => [], 'en' => []];
        foreach (self::drafts() as $key => $r) {
            if ($published ? $r['published'] === null : $r['deleted']) {
                continue;
            }
            foreach ((array) ($published ? $r['published'] : $r['draft']) as $lang => $text) {
                if (isset($out[$lang]) && is_string($text) && $text !== '') {
                    $out[$lang][$key] = $text;
                }
            }
        }
        ksort($out['it']);
        ksort($out['en']);
        return $out;
    }

    /** Ricerca nei testi (originali e correzioni): [chiave, gruppo, it, en]. */
    public static function search(string $q, int $limit = 60, bool $admin = true): array
    {
        $q = mb_strtolower(trim($q));
        if ($q === '') {
            return [];
        }
        $seed = self::seed();
        $drafts = self::drafts();
        $byKey = [];
        foreach ($seed['groups'] as $g) {
            foreach ($g['keys'] as $k) {
                $byKey[$k] = $g;
            }
        }
        $out = [];
        foreach ($seed['texts'] as $k => $t) {
            if (!$admin && ($byKey[$k]['admin_only'] ?? false)) {
                continue; // dati societari e collegamenti: solo amministratore
            }
            $it = $drafts[$k]['draft']['it'] ?? $t['it'];
            $en = $drafts[$k]['draft']['en'] ?? $t['en'];
            if (str_contains(mb_strtolower($k . ' ' . $it . ' ' . $en), $q)) {
                $out[] = ['key' => $k, 'group' => $byKey[$k]['id'], 'group_name' => $byKey[$k]['name'], 'it' => $it, 'en' => $en];
                if (count($out) >= $limit) {
                    break;
                }
            }
        }
        return $out;
    }
}
