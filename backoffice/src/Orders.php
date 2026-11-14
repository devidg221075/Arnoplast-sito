<?php
declare(strict_types=1);

namespace Backoffice;

/**
 * Ordini: nascono dal preventivo accettato. Un preventivo accettato per richiesta; il numero è O- più il numero della richiesta
 * (P-2026-0007 → O-2026-0007). Lo stato va solo avanti: confermato → in lavorazione → spedito → chiuso.
 */
final class Orders
{
    public const STATES = ['confermato' => 'Confermato', 'in_lavorazione' => 'In lavorazione', 'spedito' => 'Spedito', 'chiuso' => 'Chiuso'];

    public static function number(array $request): string
    {
        return 'O-' . substr((string) $request['ref'], 2);
    }

    /**
     * Segna il preventivo come accettato e crea l'ordine. Restituisce [id dell'ordine, errore].
     *
     * @return array{0: ?int, 1: ?string}
     */
    public static function accept(array $request, array $quote, int $userId, string $note): array
    {
        if ($quote['status'] !== 'inviato') {
            return [null, 'Si può accettare solo un preventivo già inviato al cliente.'];
        }
        $pdo = App::db();
        $st = $pdo->prepare("SELECT COUNT(*) FROM quotes WHERE request_id = ? AND status = 'accettato'");
        $st->execute([$request['id']]);
        if ((int) $st->fetchColumn() > 0) {
            return [null, 'Per questa richiesta c\'è già un preventivo accettato.'];
        }
        $now = App::now();
        $pdo->prepare("UPDATE quotes SET status = 'accettato', updated_at = ? WHERE id = ? AND status = 'inviato'")->execute([$now, $quote['id']]);
        $pdo->prepare('INSERT INTO orders (quote_id, request_id, ref, status, total, note, created_by, created_at, updated_at, updated_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$quote['id'], $request['id'], self::number($request), 'confermato', (int) $quote['total'], mb_substr($note, 0, 2000) ?: null, $userId, $now, $now, $userId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE requests SET status = ?, updated_at = ? WHERE id = ?')->execute(['in_lavorazione', $now, $request['id']]);
        App::log($userId, 'ordine.creato', self::number($request), Quote::money((int) $quote['total'], 'it'));
        return [$id, null];
    }

    /** Porta l'ordine al nuovo stato, solo in avanti. Restituisce l'errore, o null. */
    public static function advance(int $orderId, string $state, int $userId, string $note): ?string
    {
        $order = self::find($orderId);
        if (!$order) {
            return 'Ordine non trovato.';
        }
        $keys = array_keys(self::STATES);
        $from = array_search($order['status'], $keys, true);
        $to = array_search($state, $keys, true);
        if ($to === false || $to <= $from) {
            return 'Lo stato si può solo portare avanti.';
        }
        App::db()->prepare('UPDATE orders SET status = ?, note = ?, updated_at = ?, updated_by = ? WHERE id = ?')
            ->execute([$state, $note !== '' ? mb_substr($note, 0, 2000) : $order['note'], App::now(), $userId, $orderId]);
        App::log($userId, 'ordine.stato', (string) $order['ref'], $order['status'] . ' → ' . $state);
        return null;
    }

    public static function find(int $id): ?array
    {
        $st = App::db()->prepare('SELECT o.*, r.name, r.email, r.company, r.ref AS request_ref FROM orders o JOIN requests r ON r.id = o.request_id WHERE o.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return array<int, array> ordini, dal più recente; filtro facoltativo per stato. */
    public static function list(string $state = ''): array
    {
        $sql = 'SELECT o.*, r.name, r.company FROM orders o JOIN requests r ON r.id = o.request_id';
        $args = [];
        if ($state !== '' && isset(self::STATES[$state])) {
            $sql .= ' WHERE o.status = ?';
            $args[] = $state;
        }
        $st = App::db()->prepare($sql . ' ORDER BY o.created_at DESC, o.id DESC');
        $st->execute($args);
        return $st->fetchAll();
    }
}
