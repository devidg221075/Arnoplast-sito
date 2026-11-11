<?php
declare(strict_types=1);

namespace Backoffice;

use function Backoffice\Emails\quoteOffer;

/**
 * Preventivo con prezzi: bozza modificabile, calcolo dei totali, PDF e invio al cliente.
 * Gli importi si salvano in centesimi (numeri interi): nessun errore di arrotondamento.
 * Il numero è quello della richiesta (P-2026-0007); ogni versione inviata ha la sua revisione (rev. 2, 3...).
 */
final class Quote
{
    public const VAT_RATES = [22 => '22%', 10 => '10%', 4 => '4%', 0 => '0% (senza IVA)'];
    public const MAX_LINES = 60;

    /** Importo scritto dall'utente ("1.234,56", "12,5", "12.50") in centesimi; null se non valido. */
    public static function parseMoney(string $s): ?int
    {
        $s = trim(str_replace(['€', ' ', "\u{00A0}"], '', $s));
        if ($s === '') {
            return null;
        }
        if (str_contains($s, ',')) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        }
        if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $s)) {
            return null;
        }
        return (int) round(((float) $s) * 100);
    }

    /** Sconto in percentuale (0-100) da testo; vuoto = 0; null se non valido. */
    public static function parseDiscount(string $s): ?float
    {
        $s = trim(str_replace(['%', ' '], '', $s));
        if ($s === '') {
            return 0.0;
        }
        $s = str_replace(',', '.', $s);
        if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $s) || (float) $s > 100) {
            return null;
        }
        return (float) $s;
    }

    public static function money(int $cents, string $lang = 'it'): string
    {
        return $lang === 'en' ? '€' . number_format($cents / 100, 2, '.', ',') : '€ ' . number_format($cents / 100, 2, ',', '.');
    }

    /** Importo di una riga: quantità × prezzo unitario, meno lo sconto. */
    public static function lineTotal(array $l): int
    {
        return (int) round($l['qty'] * $l['unit_cents'] * (100 - $l['disc']) / 100);
    }

    /**
     * Imponibile, IVA e totale di una bozza.
     *
     * @return array{lines:int[], goods:int, shipping:int, net:int, vat:int, total:int}
     */
    public static function totals(array $d): array
    {
        $lines = array_map(self::lineTotal(...), $d['lines']);
        $goods = array_sum($lines);
        $shipping = (int) ($d['shipping_cents'] ?? 0);
        $net = $goods + $shipping;
        $vat = (int) round($net * ((float) ($d['vat_rate'] ?? 0)) / 100);
        return ['lines' => $lines, 'goods' => $goods, 'shipping' => $shipping, 'net' => $net, 'vat' => $vat, 'total' => $net + $vat];
    }

    /**
     * Legge il modulo del preventivo. Restituisce [dati, errori]: gli errori sono frasi da mostrare.
     *
     * @return array{0:array, 1:string[]}
     */
    public static function fromForm(array $in): array
    {
        $errors = [];
        $lines = [];
        $codes = (array) ($in['code'] ?? []);
        foreach ($codes as $i => $code) {
            $code = self::line((string) $code, 40);
            $desc = self::line((string) ($in['description'][$i] ?? ''), 300);
            $qtyRaw = trim((string) ($in['qty'][$i] ?? ''));
            $unitRaw = trim((string) ($in['unit'][$i] ?? ''));
            if ($code === '' && $desc === '' && $qtyRaw === '' && $unitRaw === '') {
                continue; // riga vuota
            }
            $n = count($lines) + 1;
            $qty = ctype_digit($qtyRaw) ? (int) $qtyRaw : 0;
            $unit = self::parseMoney($unitRaw);
            $disc = self::parseDiscount((string) ($in['disc'][$i] ?? ''));
            if ($code === '' && $desc === '') {
                $errors[] = "Riga $n: scrivi il codice o la descrizione.";
            }
            if ($qty < 1 || $qty > 99999) {
                $errors[] = "Riga $n: la quantità deve essere un numero intero da 1 a 99999.";
            }
            if ($unit === null) {
                $errors[] = "Riga $n: il prezzo unitario non è valido (esempio: 12,50).";
            }
            if ($disc === null) {
                $errors[] = "Riga $n: lo sconto deve essere una percentuale da 0 a 100.";
            }
            $lines[] = ['code' => $code, 'description' => $desc, 'qty' => $qty, 'unit_cents' => $unit ?? 0, 'disc' => $disc ?? 0.0];
            if (count($lines) > self::MAX_LINES) {
                $errors[] = 'Troppe righe (massimo ' . self::MAX_LINES . ').';
                break;
            }
        }
        if (!$lines) {
            $errors[] = 'Inserisci almeno un articolo.';
        }
        $shippingRaw = trim((string) ($in['shipping'] ?? ''));
        $shipping = $shippingRaw === '' ? 0 : self::parseMoney($shippingRaw);
        if ($shipping === null) {
            $errors[] = 'L\'importo di trasporto/imballo non è valido (esempio: 25,00).';
        }
        $vat = (int) ($in['vat_rate'] ?? 22);
        if (!array_key_exists($vat, self::VAT_RATES)) {
            $errors[] = 'Aliquota IVA non valida.';
        }
        $days = trim((string) ($in['valid_days'] ?? '30'));
        if (!ctype_digit($days) || (int) $days < 1 || (int) $days > 365) {
            $errors[] = 'La validità deve essere un numero di giorni da 1 a 365.';
        }
        $data = [
            'lines' => $lines,
            'shipping_label' => self::line((string) ($in['shipping_label'] ?? ''), 80),
            'shipping_cents' => $shipping ?? 0,
            'vat_rate' => $vat,
            'vat_note' => self::line((string) ($in['vat_note'] ?? ''), 200),
            'valid_days' => ctype_digit($days) ? (int) $days : 30,
            'payment' => self::line((string) ($in['payment'] ?? ''), 200),
            'delivery' => self::line((string) ($in['delivery'] ?? ''), 200),
            'notes' => self::text((string) ($in['notes'] ?? ''), 2000),
        ];
        return [$data, $errors];
    }

    /** Bozza iniziale di una richiesta: gli articoli chiesti dal cliente, con prezzi da compilare. */
    public static function draftFromRequest(array $request): array
    {
        $items = json_decode((string) $request['items'], true);
        $lines = [];
        foreach (is_array($items) ? $items : [] as $i) {
            $lines[] = ['code' => (string) ($i['code'] ?? ''), 'description' => (string) ($i['description'] ?? ''), 'qty' => (int) ($i['qty'] ?? 1), 'unit_cents' => 0, 'disc' => 0.0];
        }
        $en = $request['lang'] === 'en';
        return ['lines' => $lines, 'shipping_label' => $en ? 'Shipping and packing' : 'Trasporto e imballo', 'shipping_cents' => 0, 'vat_rate' => 22, 'vat_note' => '',
            'valid_days' => 30, 'payment' => '', 'delivery' => '', 'notes' => ''];
    }

    /** @return array[] preventivi della richiesta, dal più recente. */
    public static function forRequest(int $requestId): array
    {
        $st = App::db()->prepare('SELECT * FROM quotes WHERE request_id = ? ORDER BY rev DESC, id DESC');
        $st->execute([$requestId]);
        return $st->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $st = App::db()->prepare('SELECT * FROM quotes WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Crea la bozza (o aggiorna quella indicata) e restituisce il suo id. */
    public static function saveDraft(int $requestId, array $data, int $userId, ?int $quoteId = null): int
    {
        $pdo = App::db();
        $t = self::totals($data);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($quoteId !== null) {
            $pdo->prepare('UPDATE quotes SET data = ?, net = ?, vat = ?, total = ?, updated_at = ? WHERE id = ? AND status = \'bozza\'')
                ->execute([$json, $t['net'], $t['vat'], $t['total'], App::now(), $quoteId]);
            return $quoteId;
        }
        $st = $pdo->prepare('SELECT COALESCE(MAX(rev), 0) FROM quotes WHERE request_id = ?');
        $st->execute([$requestId]);
        $rev = (int) $st->fetchColumn() + 1;
        $pdo->prepare('INSERT INTO quotes (request_id, rev, status, data, net, vat, total, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$requestId, $rev, 'bozza', $json, $t['net'], $t['vat'], $t['total'], $userId, App::now(), App::now()]);
        return (int) $pdo->lastInsertId();
    }

    /** Dati del preventivo come lista (da JSON). */
    public static function data(array $quote): array
    {
        return json_decode((string) $quote['data'], true) ?: [];
    }

    /** Numero da mostrare: P-2026-0007, con "rev. N" dalla seconda versione. */
    public static function number(array $request, array $quote): string
    {
        return $request['ref'] . ((int) $quote['rev'] > 1 ? ' rev. ' . (int) $quote['rev'] : '');
    }

    public static function pdf(array $request, array $quote): string
    {
        $d = self::data($quote);
        $lang = $request['lang'] === 'en' ? 'en' : 'it';
        $date = $quote['sent_at'] ?: App::now();
        return (new QuotePdf())->render([
            'lang' => $lang,
            'number' => self::number($request, $quote),
            'date' => strtotime((string) $date),
            'customer' => json_decode((string) $request['data'], true) ?: [],
            'request' => $request,
            'data' => $d,
            'totals' => self::totals($d),
            'conditions' => trim(App::setting('conditions_' . $lang)),
            'logo' => dirname(__DIR__) . '/public/assets/logo-pdf.png',
        ]);
    }

    /**
     * Invia il preventivo al cliente con il PDF allegato. Il PDF e il messaggio si salvano sempre; se la posta
     * non parte il preventivo resta bozza e la richiesta non cambia stato.
     *
     * @return array{ok:bool, error:string}
     */
    public static function send(array $request, array $quote, array $user, string $message): array
    {
        if ($quote['status'] !== 'bozza') {
            return ['ok' => false, 'error' => 'Questo preventivo è già stato inviato: crea una nuova revisione.'];
        }
        $message = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', str_replace(["\r\n", "\r"], "\n", $message)));
        if (mb_strlen($message) > 3000) {
            return ['ok' => false, 'error' => 'Il messaggio è troppo lungo.'];
        }
        $lang = $request['lang'] === 'en' ? 'en' : 'it';
        $d = self::data($quote);
        $t = self::totals($d);
        $now = App::now();
        $sent = $quote;
        $sent['sent_at'] = $now;
        $pdf = self::pdf($request, $sent);
        $number = self::number($request, $quote);
        $file = $request['ref'] . '-rev' . (int) $quote['rev'] . '.pdf';
        $dir = dirname(__DIR__) . '/var/quotes';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $saved = @file_put_contents($dir . '/' . $file, $pdf) !== false;
        $mail = quoteOffer($message, $request['name'], $request['ref'], (int) $quote['rev'], $lang, (string) $user['name'],
            self::money($t['total'], $lang), self::money($t['net'], $lang), self::money($t['vat'], $lang), (int) $d['valid_days'], $file, (string) App::config('site_url'));
        $pdo = App::db();
        $st = $pdo->prepare('SELECT message_id FROM messages WHERE request_id = ? AND message_id <> \'\' ORDER BY id');
        $st->execute([$request['id']]);
        $ids = $st->fetchAll(\PDO::FETCH_COLUMN);
        $headers = $ids ? ['In-Reply-To' => (string) end($ids), 'References' => implode(' ', array_slice($ids, -10))] : [];
        [$ok, $error, $messageId] = Mailer::send([$request['email']], $mail, $request['ref'], null, $headers,
            [['name' => $file, 'data' => $pdf, 'type' => 'application/pdf']]);
        $body = ($message !== '' ? $message . "\n\n" : '') . '[Allegato: preventivo ' . $number . ' (PDF), totale ' . self::money($t['total'], $lang) . ']';
        $pdo->prepare('INSERT INTO messages (request_id, direction, user_id, from_addr, to_addr, subject, body_text, body_html, message_id, sent_ok, error, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$request['id'], 'out', $user['id'], (string) App::config('mail.from'), $request['email'], $mail['subject'], $body,
            $mail['html'], $messageId, $ok ? 1 : 0, $error, $now]);
        if ($ok) {
            $pdo->prepare('UPDATE quotes SET status = \'inviato\', sent_at = ?, updated_at = ?, pdf_file = ? WHERE id = ?')->execute([$now, $now, $saved ? $file : '', $quote['id']]);
            $pdo->prepare('UPDATE requests SET status = \'preventivo_inviato\', updated_at = ? WHERE id = ?')->execute([$now, $request['id']]);
        }
        App::log((int) $user['id'], $ok ? 'preventivo.inviato' : 'preventivo.errore', $number, $ok ? self::money($t['total']) : $error);
        return ['ok' => $ok, 'error' => $ok ? '' : 'Preventivo non inviato: ' . $error];
    }

    private static function line(string $v, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v)), 0, $max);
    }

    private static function text(string $v, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', str_replace(["\r\n", "\r"], "\n", $v))), 0, $max);
    }
}
