<?php
declare(strict_types=1);

namespace Backoffice;

/** Dati della pagina iniziale: indicatori con variazione, ultime richieste, andamento, attività, metriche. Solo dati reali. */
final class Dashboard
{
    /** Quanti giorni per il confronto (periodo corrente contro il precedente). */
    public const WINDOW = 30;

    private static function count(string $sql, array $args = []): int
    {
        $st = App::db()->prepare($sql);
        $st->execute($args);
        return (int) $st->fetchColumn();
    }

    /** Variazione percentuale tra periodo corrente e precedente; null se il precedente è zero. */
    public static function delta(int $now, int $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
    }

    /** @return array{from:string, to:string, prev_from:string} */
    private static function window(int $days = self::WINDOW): array
    {
        $now = time();
        return ['from' => date('Y-m-d H:i:s', $now - $days * 86400), 'to' => date('Y-m-d H:i:s', $now + 1),
            'prev_from' => date('Y-m-d H:i:s', $now - 2 * $days * 86400)];
    }

    /**
     * Indicatori: preventivi da completare, ordini, richieste nuove, nuovi clienti. Ogni voce ha valore, variazione e tono.
     *
     * @return array<string, array{label:string, value:int, delta:?float}>
     */
    public static function kpis(): array
    {
        $w = self::window();
        $q = static fn (string $t, string $from, ?string $to = null): int => $to === null
            ? self::count("SELECT COUNT(*) FROM $t WHERE created_at >= ?", [$from])
            : self::count("SELECT COUNT(*) FROM $t WHERE created_at >= ? AND created_at < ?", [$from, $to]);
        $quotesNow = $q('quotes', $w['from']);
        $quotesPrev = $q('quotes', $w['prev_from'], $w['from']);
        $ordersNow = $q('orders', $w['from']);
        $ordersPrev = $q('orders', $w['prev_from'], $w['from']);
        return [
            'quotes' => ['label' => 'Preventivi da gestire', 'value' => self::count("SELECT COUNT(*) FROM quotes WHERE status = 'bozza'"),
                'delta' => self::delta($quotesNow, $quotesPrev), 'hint' => 'In attesa di una tua azione'],
            'orders' => ['label' => 'Ordini aperti', 'value' => self::count("SELECT COUNT(*) FROM orders WHERE status <> 'chiuso'"),
                'delta' => self::delta($ordersNow, $ordersPrev), 'hint' => 'Confermati e in preparazione'],
            'products' => ['label' => 'Prodotti nel catalogo', 'value' => null, 'delta' => null, 'hint' => 'Arriva con il catalogo'],
            'new' => ['label' => 'Contatti da gestire', 'value' => self::count("SELECT COUNT(*) FROM requests WHERE status = 'nuova'"),
                'delta' => null, 'hint' => 'Richieste aperte dal sito'],
        ];
    }

    /** Nuovi clienti nel periodo, con variazione: per il riquadro delle metriche. */
    public static function newClients(): array
    {
        $w = self::window();
        $now = self::count('SELECT COUNT(DISTINCT email) FROM requests WHERE created_at >= ?', [$w['from']]);
        $prev = self::count('SELECT COUNT(DISTINCT email) FROM requests WHERE created_at >= ? AND created_at < ?', [$w['prev_from'], $w['from']]);
        return ['value' => $now, 'delta' => self::delta($now, $prev)];
    }

    /** Ultime richieste con l'importo dell'ultimo preventivo. */
    public static function latest(int $limit = 5): array
    {
        $st = App::db()->prepare('SELECT id, ref, kind, name, company, status, created_at FROM requests ORDER BY created_at DESC, id DESC LIMIT ?');
        $st->bindValue(1, $limit, \PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $q = App::db()->prepare('SELECT total FROM quotes WHERE request_id = ? ORDER BY rev DESC, id DESC LIMIT 1');
            $q->execute([$r['id']]);
            $r['total'] = $q->fetchColumn();
        }
        return $rows;
    }

    /** Andamento settimanale: preventivi creati e ordini creati, ultime $weeks settimane (dalla più vecchia). */
    public static function weekly(int $weeks = 8): array
    {
        $out = [];
        $monday = strtotime('monday this week');
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $from = date('Y-m-d H:i:s', $monday - $i * 7 * 86400);
            $to = date('Y-m-d H:i:s', $monday - ($i - 1) * 7 * 86400);
            $out[] = ['label' => date('d/m', strtotime($from)),
                'quotes' => self::count('SELECT COUNT(*) FROM quotes WHERE created_at >= ? AND created_at < ?', [$from, $to]),
                'orders' => self::count('SELECT COUNT(*) FROM orders WHERE created_at >= ? AND created_at < ?', [$from, $to])];
        }
        return $out;
    }

    /** Ultime attività del registro, con una frase leggibile. */
    public static function activity(int $limit = 6): array
    {
        $st = App::db()->prepare('SELECT a.*, u.name AS who FROM activity a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT ?');
        $st->bindValue(1, $limit, \PDO::PARAM_INT);
        $st->execute();
        return array_map(static fn (array $a): array => ['at' => $a['created_at'], 'text' => self::sentence($a), 'who' => $a['who'] ?? 'sito', 'icon' => self::iconFor((string) $a['action'])], $st->fetchAll());
    }

    /** Icona per tipo di attività. */
    private static function iconFor(string $action): string
    {
        return match (true) {
            str_starts_with($action, 'ordine') => 'cart',
            str_starts_with($action, 'preventivo') => 'doc',
            str_starts_with($action, 'listino') => 'download',
            str_starts_with($action, 'accesso') || str_starts_with($action, 'utente') => 'shield',
            str_starts_with($action, 'posta') || str_starts_with($action, 'richiesta') => 'mail',
            default => 'dot',
        };
    }

    private static function stateLabel(string $key): string
    {
        return Orders::STATES[$key] ?? $key;
    }

    private static function sentence(array $a): string
    {
        $t = (string) $a['target'];
        return match ((string) $a['action']) {
            'accesso' => 'Accesso al backoffice',
            'accesso.fallito' => 'Accesso non riuscito per ' . $t,
            'uscita' => 'Uscita dal backoffice',
            'ordine.creato' => 'Ordine ' . $t . ' creato',
            'ordine.stato' => 'Ordine ' . $t . ': ' . str_replace(array_keys(Orders::STATES), array_map(self::stateLabel(...), array_keys(Orders::STATES)), (string) $a['detail']),
            'preventivo.salvato' => 'Preventivo di ' . $t . ' salvato',
            'preventivo.inviato' => 'Preventivo di ' . $t . ' inviato al cliente',
            'richiesta.risposta' => 'Risposta inviata per ' . $t,
            'posta.ricevuta' => 'Messaggio ricevuto per ' . $t,
            'listino.scaricato' => 'Listino scaricato: ' . (string) $a['detail'],
            'listino.caricato' => 'Listino caricato: ' . (string) $a['detail'],
            'video.aggiunto' => 'Video aggiunto: ' . (string) $a['detail'],
            'utente.ruolo' => 'Ruolo cambiato per ' . $t . ' (' . (string) $a['detail'] . ')',
            default => trim(str_replace('.', ' ', (string) $a['action']) . ' ' . $t),
        };
    }

    /** Metriche degli ultimi 30 giorni: visite, scaricamenti dei listini, nuovi clienti. */
    public static function metrics(): array
    {
        $from = date('Y-m-d', time() - self::WINDOW * 86400);
        $visits = self::count('SELECT COUNT(*) FROM hits WHERE day >= ?', [$from]);
        $downloads = self::count("SELECT COUNT(*) FROM activity WHERE action = 'listino.scaricato' AND created_at >= ?", [date('Y-m-d H:i:s', time() - self::WINDOW * 86400)]);
        $clients = self::count('SELECT COUNT(DISTINCT email) FROM requests WHERE created_at >= ?', [date('Y-m-d H:i:s', time() - self::WINDOW * 86400)]);
        return ['visits' => $visits, 'downloads' => $downloads, 'clients' => $clients];
    }
}
