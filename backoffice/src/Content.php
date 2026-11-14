<?php
declare(strict_types=1);

namespace Backoffice;

/**
 * Archivio dei contenuti modificabili (fiere, galleria, pagine, download): per ogni voce una bozza (`draft`) e la
 * versione pubblicata (`published`, quella che è sul sito). Finché non si pubblica, il sito non cambia.
 */
final class Content
{
    public const NEW = 'nuova';
    public const LIVE = 'pubblicata';
    public const CHANGED = 'modificata';
    public const REMOVED = 'da eliminare';

    /** @return array[] voci di un tipo (riga del database, con draft/published già decodificati). */
    public static function all(string $kind): array
    {
        $st = App::db()->prepare('SELECT * FROM content WHERE kind = ?');
        $st->execute([$kind]);
        return array_map(self::decode(...), $st->fetchAll());
    }

    public static function find(string $kind, string $itemId): ?array
    {
        $st = App::db()->prepare('SELECT * FROM content WHERE kind = ? AND item_id = ?');
        $st->execute([$kind, $itemId]);
        $r = $st->fetch();
        return $r ? self::decode($r) : null;
    }

    /** Salva la bozza (crea la voce se manca). Restituisce vero se è stata creata. */
    public static function saveDraft(string $kind, string $itemId, array $draft, ?int $userId, ?array $meta = null): bool
    {
        $pdo = App::db();
        $json = json_encode($draft, JSON_UNESCAPED_UNICODE);
        $metaJson = $meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE);
        $st = $pdo->prepare('UPDATE content SET draft = ?, meta = COALESCE(?, meta), deleted = 0, updated_by = ?, updated_at = ? WHERE kind = ? AND item_id = ?');
        $st->execute([$json, $metaJson, $userId, App::now(), $kind, $itemId]);
        if ($st->rowCount() > 0) {
            return false;
        }
        if (self::find($kind, $itemId)) {
            return false; // nessuna modifica ai dati (stesso contenuto)
        }
        $pdo->prepare('INSERT INTO content (kind, item_id, draft, published, deleted, meta, updated_by, updated_at) VALUES (?,?,?,NULL,0,?,?,?)')
            ->execute([$kind, $itemId, $json, $metaJson, $userId, App::now()]);
        return true;
    }

    /** Voce già pubblicata (importata dal sito): bozza e pubblicato coincidono. */
    public static function importLive(string $kind, string $itemId, array $item): bool
    {
        if (self::find($kind, $itemId)) {
            return false;
        }
        $json = json_encode($item, JSON_UNESCAPED_UNICODE);
        App::db()->prepare('INSERT INTO content (kind, item_id, draft, published, deleted, meta, updated_by, updated_at) VALUES (?,?,?,?,0,NULL,NULL,?)')
            ->execute([$kind, $itemId, $json, $json, App::now()]);
        return true;
    }

    /** Segna per l'eliminazione (una voce mai pubblicata si cancella subito). */
    public static function markDeleted(string $kind, string $itemId, ?int $userId): void
    {
        $r = self::find($kind, $itemId);
        if (!$r) {
            return;
        }
        if ($r['published'] === null) {
            App::db()->prepare('DELETE FROM content WHERE kind = ? AND item_id = ?')->execute([$kind, $itemId]);
            return;
        }
        App::db()->prepare('UPDATE content SET deleted = 1, updated_by = ?, updated_at = ? WHERE kind = ? AND item_id = ?')->execute([$userId, App::now(), $kind, $itemId]);
    }

    /** Annulla le modifiche: la bozza torna uguale alla versione pubblicata. */
    public static function revert(string $kind, string $itemId, ?int $userId): void
    {
        $r = self::find($kind, $itemId);
        if ($r && $r['published'] !== null) {
            App::db()->prepare('UPDATE content SET draft = published, deleted = 0, updated_by = ?, updated_at = ? WHERE kind = ? AND item_id = ?')
                ->execute([$userId, App::now(), $kind, $itemId]);
        }
    }

    /** Pubblica tutte le voci di un tipo: la bozza diventa la versione pubblicata e le voci da eliminare spariscono. */
    public static function publishAll(string $kind): void
    {
        $pdo = App::db();
        $pdo->prepare('DELETE FROM content WHERE kind = ? AND deleted = 1')->execute([$kind]);
        $pdo->prepare('UPDATE content SET published = draft WHERE kind = ?')->execute([$kind]);
        $pdo->prepare("DELETE FROM content WHERE kind = ? AND draft = '[]'")->execute([$kind]);
    }

    /** nuova / pubblicata / modificata / da eliminare. */
    public static function status(array $row): string
    {
        if ($row['deleted']) {
            return self::REMOVED;
        }
        if ($row['published'] === null) {
            return self::NEW;
        }
        return $row['draft'] == $row['published'] ? self::LIVE : self::CHANGED;
    }

    /** Quante voci hanno modifiche non ancora pubblicate. */
    public static function pending(string $kind): int
    {
        return count(array_filter(self::all($kind), static fn (array $r): bool => self::status($r) !== self::LIVE));
    }

    private static function decode(array $r): array
    {
        $r['draft'] = json_decode((string) $r['draft'], true) ?: [];
        $r['published'] = $r['published'] === null ? null : (json_decode((string) $r['published'], true) ?: []);
        $r['meta'] = $r['meta'] === null ? [] : (json_decode((string) $r['meta'], true) ?: []);
        $r['deleted'] = (int) $r['deleted'] === 1;
        return $r;
    }
}
