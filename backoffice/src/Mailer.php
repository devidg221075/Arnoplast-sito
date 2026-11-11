<?php
declare(strict_types=1);

namespace Backoffice;

use PHPMailer\PHPMailer\PHPMailer;

/** Invio via SMTP dalla casella configurata. Restituisce [riuscito, errore, Message-ID]. */
final class Mailer
{
    /** Per le prove: se impostata, riceve i messaggi invece del server di posta. */
    public static ?\Closure $transport = null;

    /**
     * Il logo del modello e-mail si incorpora nel messaggio (immagine inclusa, riferita con cid:): si vede sempre,
     * anche se la casella blocca le immagini esterne o il sito non è ancora online all'indirizzo del logo.
     */
    public static function embedLogo(PHPMailer $m, string $html): string
    {
        $file = dirname(__DIR__) . '/public/assets/logo-pdf.png';
        if (!is_file($file) || !preg_match('~src="[^"]*/static/img/email/logo\.png"~', $html)) {
            return $html;
        }
        $m->addEmbeddedImage($file, 'arnologo', 'arnoplast-logo.png', PHPMailer::ENCODING_BASE64, 'image/png');
        return (string) preg_replace('~src="[^"]*/static/img/email/logo\.png"~', 'src="cid:arnologo"', $html);
    }

    /**
     * @param string[] $to
     * @param array{subject:string, html:string, text:string} $mail
     * @param array<int,array{name:string, data:string, type:string}> $attachments allegati (nome, contenuto, tipo)
     * @param array<string,string> $headers intestazioni in più (In-Reply-To, References) per tenere unita la conversazione
     * @return array{0:bool, 1:string, 2:string}
     */
    public static function send(array $to, array $mail, string $ref, ?string $replyTo = null, array $headers = [], array $attachments = []): array
    {
        $cfg = App::config('mail');
        $host = parse_url((string) App::config('admin_url', 'https://localhost'), PHP_URL_HOST) ?: 'localhost';
        $messageId = sprintf('<%s.%s@%s>', preg_replace('/[^A-Za-z0-9-]/', '', $ref), bin2hex(random_bytes(8)), $host);
        if (self::$transport !== null) {
            return [(bool) (self::$transport)($to, $mail, $ref, $replyTo, $headers, $attachments), '', $messageId];
        }
        // Solo per le prove: con mail.dump_dir i messaggi si scrivono in una cartella e non partono.
        $dump = App::config('mail.dump_dir');
        if (is_string($dump) && $dump !== '') {
            file_put_contents(rtrim($dump, '/') . '/' . microtime(true) . '-' . bin2hex(random_bytes(3)) . '.json',
                json_encode(['to' => $to, 'reply_to' => $replyTo, 'ref' => $ref, 'headers' => $headers, 'attachments' => array_map(static fn (array $a): array => ['name' => $a['name'], 'size' => strlen($a['data']), 'data' => base64_encode($a['data'])], $attachments)] + $mail, JSON_UNESCAPED_UNICODE));
            return [true, '', $messageId];
        }
        try {
            $m = new PHPMailer(true);
            $m->isSMTP();
            $m->Host = $cfg['host'];
            $m->Port = (int) $cfg['port'];
            $m->SMTPAuth = true;
            $m->Username = $cfg['user'];
            $m->Password = $cfg['password'];
            $m->SMTPSecure = ($cfg['encryption'] ?? 'ssl') === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
            $m->Timeout = 15;
            $m->CharSet = PHPMailer::CHARSET_UTF8;
            $m->setFrom($cfg['from'], $cfg['from_name'] ?? '');
            $m->addReplyTo($replyTo ?: $cfg['from'], $replyTo ? '' : ($cfg['from_name'] ?? ''));
            foreach ($to as $addr) {
                $m->addAddress($addr);
            }
            $m->MessageID = $messageId;
            foreach ($headers as $name => $value) {
                $m->addCustomHeader($name, preg_replace('/[\r\n]+/', ' ', $value));
            }
            foreach ($attachments as $a) {
                $m->addStringAttachment($a['data'], $a['name'], PHPMailer::ENCODING_BASE64, $a['type']);
            }
            $m->Subject = $mail['subject'];
            $m->isHTML(true);
            $m->Body = self::embedLogo($m, $mail['html']);
            $m->AltBody = $mail['text'];
            $m->send();
            return [true, '', $messageId];
        } catch (\Throwable $e) {
            return [false, mb_substr($e->getMessage(), 0, 480), $messageId];
        }
    }
}
