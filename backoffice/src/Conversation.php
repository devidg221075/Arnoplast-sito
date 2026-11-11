<?php
declare(strict_types=1);

namespace Backoffice;

use function Backoffice\Emails\{customerReplyNotice, reply};

/**
 * Conversazione con il cliente: risposte scritte dal backoffice e risposte del cliente lette dalla casella.
 * Ogni messaggio sta nella scheda della richiesta, con il suo riferimento (C-AAAA-NNNN / P-AAAA-NNNN).
 */
final class Conversation
{
    public const REF = '/\b([CP]-\d{4}-\d{4})\b/';
    public const MAX_REPLY = 8000;

    /**
     * Risposta al cliente. Il messaggio si salva sempre, anche se l'invio fallisce (con il motivo): non si perde nulla.
     *
     * @return array{ok:bool, error:string}
     */
    public static function reply(array $request, array $user, string $subject, string $text): array
    {
        $text = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', str_replace(["\r\n", "\r"], "\n", $text)));
        $subject = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $subject));
        if ($text === '') {
            return ['ok' => false, 'error' => 'Scrivi il testo della risposta.'];
        }
        if (mb_strlen($text) > self::MAX_REPLY || mb_strlen($subject) > 200) {
            return ['ok' => false, 'error' => 'Il testo o l\'oggetto sono troppo lunghi.'];
        }
        $lang = $request['lang'] === 'en' ? 'en' : 'it';
        if ($subject === '') {
            $subject = ($lang === 'en' ? 'Reply to your request' : 'Risposta alla tua richiesta');
        }
        // Il riferimento nell'oggetto serve a riconoscere la risposta del cliente quando torna nella casella.
        if (!str_contains($subject, $request['ref'])) {
            $subject .= ' [' . $request['ref'] . ']';
        }
        $mail = reply($text, $request['name'], $request['ref'], $lang, (string) $user['name'], $subject, (string) App::config('site_url'));
        $pdo = App::db();
        $st = $pdo->prepare('SELECT message_id FROM messages WHERE request_id = ? AND message_id <> \'\' ORDER BY id');
        $st->execute([$request['id']]);
        $ids = $st->fetchAll(\PDO::FETCH_COLUMN);
        $headers = $ids ? ['In-Reply-To' => (string) end($ids), 'References' => implode(' ', array_slice($ids, -10))] : [];
        [$ok, $error, $messageId] = Mailer::send([$request['email']], $mail, $request['ref'], null, $headers);
        $pdo->prepare('INSERT INTO messages (request_id, direction, user_id, from_addr, to_addr, subject, body_text, body_html, message_id, sent_ok, error, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$request['id'], 'out', $user['id'], (string) App::config('mail.from'), $request['email'], $mail['subject'], $text,
            $mail['html'], $messageId, $ok ? 1 : 0, $error, App::now()]);
        if ($ok && $request['status'] === 'nuova') {
            $pdo->prepare('UPDATE requests SET status = ?, updated_at = ? WHERE id = ?')->execute(['in_lavorazione', App::now(), $request['id']]);
        } else {
            $pdo->prepare('UPDATE requests SET updated_at = ? WHERE id = ?')->execute([App::now(), $request['id']]);
        }
        App::log((int) $user['id'], $ok ? 'richiesta.risposta' : 'richiesta.risposta.errore', $request['ref'], $error);
        return ['ok' => $ok, 'error' => $ok ? '' : 'Risposta salvata ma non inviata: ' . $error];
    }

    /**
     * Collega un messaggio ricevuto alla sua richiesta: prima dal riferimento nell'oggetto, poi dagli
     * identificativi dei messaggi già scambiati (In-Reply-To / References). Altrimenti null: non è nostro.
     *
     * @param array{subject:string, in_reply_to:string, references:string[]} $m
     */
    public static function match(array $m): ?array
    {
        $pdo = App::db();
        if (preg_match(self::REF, $m['subject'], $x)) {
            $st = $pdo->prepare('SELECT * FROM requests WHERE ref = ?');
            $st->execute([$x[1]]);
            if ($r = $st->fetch()) {
                return $r;
            }
        }
        $ids = array_values(array_unique(array_filter(array_merge([$m['in_reply_to']], $m['references']))));
        foreach ($ids as $id) {
            $st = $pdo->prepare('SELECT r.* FROM messages m JOIN requests r ON r.id = m.request_id WHERE m.message_id = ? LIMIT 1');
            $st->execute([$id]);
            if ($r = $st->fetch()) {
                return $r;
            }
        }
        return null;
    }

    /**
     * Registra una risposta del cliente. Vero se è stata aggiunta (falso se già presente, automatica o non riconosciuta).
     *
     * @param array{subject:string, from:string, to:string, message_id:string, in_reply_to:string, references:string[], auto:bool, text:string, attachments:int} $m
     */
    public static function receive(array $m): bool
    {
        $own = mb_strtolower((string) App::config('mail.from', ''));
        if ($m['auto'] || $m['from'] === '' || ($own !== '' && $m['from'] === $own)) {
            return false;
        }
        $pdo = App::db();
        if ($m['message_id'] !== '') {
            $st = $pdo->prepare('SELECT 1 FROM messages WHERE message_id = ?');
            $st->execute([$m['message_id']]);
            if ($st->fetchColumn()) {
                return false;
            }
        }
        $r = self::match($m);
        if (!$r) {
            return false;
        }
        $text = $m['text'] !== '' ? $m['text'] : '(messaggio senza testo)';
        if ($m['attachments'] > 0) {
            $text .= "\n\n[Il messaggio contiene " . $m['attachments'] . ($m['attachments'] === 1 ? ' allegato' : ' allegati') . ': non copiati qui, si trovano nella casella ' . App::config('mail.from', '') . '.]';
        }
        $pdo->prepare('INSERT INTO messages (request_id, direction, from_addr, to_addr, subject, body_text, message_id, sent_ok, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$r['id'], 'in', $m['from'], mb_substr($m['to'], 0, 500), mb_substr($m['subject'], 0, 300), $text, $m['message_id'], 1, App::now()]);
        // Se la richiesta era chiusa o il preventivo già inviato, il cliente ha ripreso la conversazione: torna in lavorazione.
        $status = in_array($r['status'], ['chiusa', 'preventivo_inviato'], true) ? 'in_lavorazione' : $r['status'];
        $pdo->prepare('UPDATE requests SET status = ?, updated_at = ? WHERE id = ?')->execute([$status, App::now(), $r['id']]);
        App::log(null, 'posta.ricevuta', $r['ref'], $m['from']);

        $adminUrl = rtrim((string) App::config('admin_url'), '/') . '/index.php?p=request&id=' . $r['id'];
        $notice = customerReplyNotice($r['name'], Mime::splitQuote($m['text'])[0], $r['ref'], $adminUrl, $m['attachments'], (string) App::config('site_url'));
        $to = (array) App::config('mail.notify_to', []);
        if ($to) {
            [$ok, $error, $messageId] = Mailer::send($to, $notice, $r['ref'], $m['from']);
            $pdo->prepare('INSERT INTO messages (request_id, direction, from_addr, to_addr, subject, body_text, body_html, message_id, sent_ok, error, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$r['id'], 'auto', (string) App::config('mail.from'), implode(', ', $to), $notice['subject'], $notice['text'], $notice['html'], $messageId, $ok ? 1 : 0, $error, App::now()]);
        }
        return true;
    }
}
