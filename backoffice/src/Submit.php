<?php
declare(strict_types=1);

namespace Backoffice;

use function Backoffice\Emails\{contactCustomer, contactStaff, quoteCustomer, quoteStaff};

/**
 * Ricezione dei moduli del sito (contatti e preventivo): controlli lato server, salvataggio, due e-mail.
 * La richiesta si salva sempre prima di spedire: se la posta non parte, non si perde nulla.
 */
final class Submit
{
    public const MAX_ITEMS = 200;

    /** @return array{status:int, body:array} */
    public static function handle(array $in): array
    {
        $kind = ($in['kind'] ?? '') === 'quote' ? 'quote' : (($in['kind'] ?? '') === 'contact' ? 'contact' : '');
        if ($kind === '') {
            return self::fail(400, ['kind' => 'invalid']);
        }
        // Trappola antispam: i visitatori veri non la compilano. Si risponde "ok" senza salvare nulla.
        if (trim((string) ($in['website'] ?? '')) !== '') {
            return ['status' => 200, 'body' => ['ok' => true, 'ref' => '']];
        }
        $ip = App::ipHash();
        if (!App::allow('submit:' . $ip, 5, 600, false) || !App::allow('submit-day:' . $ip, 30, 86400, false)) {
            return self::fail(429, ['rate' => 'too_many']);
        }
        $lang = ($in['lang'] ?? 'it') === 'en' ? 'en' : 'it';
        [$data, $items, $errors] = $kind === 'contact' ? self::contact($in) : self::quote($in);
        if ($errors) {
            return self::fail(422, $errors);
        }
        App::allow('submit:' . $ip, 5, 600);
        App::allow('submit-day:' . $ip, 30, 86400);

        $pdo = App::db();
        $name = $kind === 'contact' ? trim($data['name'] . ' ' . $data['surname']) : $data['name'];
        // Due invii nello stesso istante possono chiedere lo stesso riferimento: il vincolo di unicità lo impedisce e si riprova.
        for ($try = 1; ; $try++) {
            try {
                $pdo->beginTransaction();
                $ref = self::nextRef($kind);
                $pdo->prepare('INSERT INTO requests (ref, kind, lang, status, name, email, phone, company, data, items, ip_hash, created_at, updated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$ref, $kind, $lang, 'nuova', $name, $data['email'], $data['phone'] ?? '', $data['company'] ?? '',
                    json_encode($data, JSON_UNESCAPED_UNICODE), $items === null ? null : json_encode($items, JSON_UNESCAPED_UNICODE), $ip, App::now(), App::now()]);
                $id = (int) $pdo->lastInsertId();
                $text = $kind === 'contact' ? $data['message'] : ($data['notes'] ?? '');
                $pdo->prepare('INSERT INTO messages (request_id, direction, from_addr, to_addr, subject, body_text, created_at) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$id, 'in', $data['email'], '', $kind === 'contact' ? 'Richiesta di contatto dal sito' : 'Richiesta di preventivo dal sito', $text, App::now()]);
                $pdo->commit();
                break;
            } catch (\PDOException $ex) {
                $pdo->rollBack();
                if ($try >= 4) {
                    throw $ex;
                }
            }
        }
        App::log(null, 'richiesta.ricevuta', $ref, $kind);

        $site = (string) App::config('site_url');
        $adminUrl = rtrim((string) App::config('admin_url'), '/') . '/index.php?p=request&id=' . $id;
        $toCustomer = $kind === 'contact' ? contactCustomer($data, $ref, $lang, $site) : quoteCustomer($data, $items, $ref, $lang, $site);
        $toStaff = $kind === 'contact' ? contactStaff($data, $ref, $adminUrl, $lang, $site) : quoteStaff($data, $items, $ref, $adminUrl, $lang, $site);
        self::deliver($id, $ref, [$data['email']], $toCustomer, null);
        self::deliver($id, $ref, (array) App::config('mail.notify_to', []), $toStaff, $data['email']);

        return ['status' => 200, 'body' => ['ok' => true, 'ref' => $ref]];
    }

    private static function deliver(int $requestId, string $ref, array $to, array $mail, ?string $replyTo): void
    {
        if (!$to) {
            return;
        }
        [$ok, $error, $messageId] = Mailer::send($to, $mail, $ref, $replyTo);
        App::db()->prepare('INSERT INTO messages (request_id, direction, from_addr, to_addr, subject, body_text, body_html, message_id, sent_ok, error, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$requestId, 'auto', (string) App::config('mail.from'), implode(', ', $to), $mail['subject'], $mail['text'],
            $mail['html'], $messageId, $ok ? 1 : 0, $error, App::now()]);
        if (!$ok) {
            App::log(null, 'email.errore', $ref, $error);
        }
    }

    /** Riferimento progressivo per tipo e anno: C-2026-0001 (contatto), P-2026-0001 (preventivo). */
    private static function nextRef(string $kind): string
    {
        $prefix = ($kind === 'contact' ? 'C' : 'P') . '-' . date('Y') . '-';
        $st = App::db()->prepare('SELECT ref FROM requests WHERE ref LIKE ? ORDER BY ref DESC LIMIT 1');
        $st->execute([$prefix . '%']);
        $last = $st->fetchColumn();
        return $prefix . str_pad((string) (($last ? (int) substr((string) $last, strlen($prefix)) : 0) + 1), 4, '0', STR_PAD_LEFT);
    }

    private static function fail(int $status, array $errors): array
    {
        return ['status' => $status, 'body' => ['ok' => false, 'errors' => $errors]];
    }

    /** Testo su una riga, senza caratteri di controllo, entro $max caratteri. */
    private static function str(array $in, string $key, int $max, array &$errors, bool $required = false): string
    {
        $v = $in[$key] ?? '';
        if (!is_string($v)) {
            $errors[$key] = 'invalid';
            return '';
        }
        $v = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v));
        if ($required && $v === '') {
            $errors[$key] = 'required';
        } elseif (mb_strlen($v) > $max) {
            $errors[$key] = 'too_long';
        }
        return $v;
    }

    /** Testo su più righe (messaggio, note): a-capo ammessi, altri caratteri di controllo tolti. */
    private static function text(array $in, string $key, int $max, array &$errors, bool $required = false): string
    {
        $v = $in[$key] ?? '';
        if (!is_string($v)) {
            $errors[$key] = 'invalid';
            return '';
        }
        $v = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', str_replace(["\r\n", "\r"], "\n", $v)));
        if ($required && $v === '') {
            $errors[$key] = 'required';
        } elseif (mb_strlen($v) > $max) {
            $errors[$key] = 'too_long';
        }
        return $v;
    }

    private static function email(array $in, array &$errors): string
    {
        $v = self::str($in, 'email', 254, $errors, true);
        if ($v !== '' && (!filter_var($v, FILTER_VALIDATE_EMAIL) || preg_match('/[\s,;<>]/', $v))) {
            $errors['email'] = 'invalid';
        }
        return $v;
    }

    private static function contact(array $in): array
    {
        $e = [];
        $d = [
            'name' => self::str($in, 'name', 100, $e, true), 'surname' => self::str($in, 'surname', 100, $e),
            'email' => self::email($in, $e), 'phone' => self::str($in, 'phone', 40, $e, true),
            'company' => self::str($in, 'company', 150, $e), 'contact' => self::str($in, 'contact', 150, $e),
            'type' => self::str($in, 'type', 60, $e, true), 'address' => self::str($in, 'address', 200, $e),
            'city' => self::str($in, 'city', 100, $e), 'zip' => self::str($in, 'zip', 20, $e), 'country' => self::str($in, 'country', 80, $e),
            'message' => self::text($in, 'message', 4000, $e, true),
        ];
        if ($d['type'] !== '' && !in_array($d['type'], (array) App::config('contact_types', []), true)) {
            $e['type'] = 'invalid';
        }
        if (($in['privacy'] ?? false) !== true) {
            $e['privacy'] = 'required';
        }
        return [$d, null, $e];
    }

    private static function quote(array $in): array
    {
        $e = [];
        $d = [
            'name' => self::str($in, 'name', 150, $e, true), 'company' => self::str($in, 'company', 150, $e),
            'email' => self::email($in, $e), 'phone' => self::str($in, 'phone', 40, $e), 'country' => self::str($in, 'country', 80, $e),
            'notes' => self::text($in, 'notes', 4000, $e),
        ];
        if (($in['privacy'] ?? false) !== true) {
            $e['privacy'] = 'required';
        }
        $items = [];
        $raw = $in['items'] ?? null;
        if (!is_array($raw) || !$raw || count($raw) > self::MAX_ITEMS) {
            $e['items'] = !is_array($raw) || !$raw ? 'required' : 'too_many';
        } else {
            foreach ($raw as $i) {
                $x = [];
                if (!is_array($i)) {
                    $e['items'] = 'invalid';
                    break;
                }
                $code = self::str($i, 'code', 40, $x, true);
                $desc = self::str($i, 'description', 300, $x);
                $product = self::str($i, 'product', 150, $x);
                $qty = $i['qty'] ?? null;
                $conf = $i['conf'] ?? null;
                if ($x || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 .\/_-]*$/', $code) || !is_int($qty) || $qty < 1 || $qty > 99999
                    || !($conf === null || (is_int($conf) && $conf >= 1 && $conf <= 1000000))) {
                    $e['items'] = 'invalid';
                    break;
                }
                $items[] = ['code' => $code, 'description' => $desc, 'qty' => $qty, 'conf' => $conf, 'product' => $product];
            }
        }
        return [$d, $items, $e];
    }
}
