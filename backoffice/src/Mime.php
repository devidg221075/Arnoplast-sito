<?php
declare(strict_types=1);

namespace Backoffice;

/**
 * Lettura di un messaggio di posta (formato RFC 5322 / MIME): intestazioni, testo e numero di allegati.
 * Niente estensioni speciali: solo funzioni standard di PHP e mbstring.
 */
final class Mime
{
    private const MAX_DEPTH = 6;
    private const MAX_TEXT = 20000;

    /**
     * @return array{subject:string, from:string, to:string, message_id:string, in_reply_to:string, references:string[],
     *               auto:bool, text:string, attachments:int}
     */
    public static function parse(string $raw): array
    {
        [$headers, $body] = self::split($raw);
        $out = ['text' => '', 'html' => '', 'attachments' => 0];
        self::walk($headers, $body, $out, 0);
        $text = $out['text'] !== '' ? $out['text'] : self::htmlToText($out['html']);
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        $auto = self::first($headers, 'auto-submitted');
        $prec = strtolower(self::first($headers, 'precedence'));
        $from = self::address(self::decode(self::first($headers, 'from')));
        return [
            'subject' => trim((string) preg_replace('/\s+/', ' ', self::decode(self::first($headers, 'subject')))),
            'from' => $from,
            'to' => trim((string) preg_replace('/\s+/', ' ', self::decode(self::first($headers, 'to')))),
            'message_id' => self::messageId(self::first($headers, 'message-id')),
            'in_reply_to' => self::messageId(self::first($headers, 'in-reply-to')),
            'references' => array_values(array_filter(array_map(self::messageId(...), preg_split('/\s+/', self::first($headers, 'references')) ?: []))),
            // Risposte automatiche, bollettini, notifiche di recapito: non sono conversazioni con il cliente.
            'auto' => ($auto !== '' && strtolower($auto) !== 'no') || in_array($prec, ['bulk', 'junk', 'list', 'auto_reply'], true)
                || self::first($headers, 'x-autoreply') !== '' || self::first($headers, 'list-id') !== ''
                || (bool) preg_match('/^(mailer-daemon|postmaster)@/i', $from),
            'text' => mb_substr($text, 0, self::MAX_TEXT),
            'attachments' => $out['attachments'],
        ];
    }

    /**
     * Solo la parte nuova di una risposta: toglie il testo citato (">", "Il giorno ... ha scritto:", "On ... wrote:").
     * Restituisce [parte nuova, parte citata]; se tagliando resterebbe il vuoto, il messaggio si mostra intero.
     *
     * @return array{0:string, 1:string}
     */
    public static function splitQuote(string $text): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $cut = null;
        foreach ($lines as $i => $l) {
            $t = trim($l);
            if (str_starts_with($t, '>') || preg_match('/^-{2,}\s*(original message|messaggio originale|forwarded|inoltrato)/i', $t) || preg_match('/^_{8,}$/', $t)) {
                $cut = $i;
                break;
            }
            if (preg_match('/(wrote|ha scritto|a écrit|schrieb|escribió)\s*:\s*$/iu', $t)) {
                // L'intestazione della citazione può andare a capo: si risale alla riga che comincia con "On"/"Il"/...
                $cut = $i;
                for ($b = $i - 1; $b >= max(0, $i - 2); $b--) {
                    if (preg_match('/^(on|il|le|am|el)\s/iu', trim($lines[$b]))) {
                        $cut = $b;
                        break;
                    }
                }
                break;
            }
        }
        if ($cut === null) {
            return [trim($text), ''];
        }
        $new = trim(implode("\n", array_slice($lines, 0, $cut)));
        return $new === '' ? [trim($text), ''] : [$new, trim(implode("\n", array_slice($lines, $cut)))];
    }

    /** @return array{0:array<string,string[]>, 1:string} */
    private static function split(string $raw): array
    {
        $raw = ltrim($raw, "\r\n");
        if (!preg_match('/\r?\n\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            return [self::headers($raw), ''];
        }
        return [self::headers(substr($raw, 0, $m[0][1])), substr($raw, $m[0][1] + strlen($m[0][0]))];
    }

    /** @return array<string,string[]> */
    private static function headers(string $block): array
    {
        $block = (string) preg_replace('/\r?\n[ \t]+/', ' ', $block);
        $h = [];
        foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
            if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m)) {
                $h[strtolower($m[1])][] = trim($m[2]);
            }
        }
        return $h;
    }

    private static function first(array $h, string $name): string
    {
        return $h[$name][0] ?? '';
    }

    /** @param array{text:string, html:string, attachments:int} $out */
    private static function walk(array $headers, string $body, array &$out, int $depth): void
    {
        [$type, $params] = self::contentType(self::first($headers, 'content-type'));
        $type = $type ?: 'text/plain';
        $disposition = strtolower(self::first($headers, 'content-disposition'));
        if (str_starts_with($type, 'multipart/') && isset($params['boundary']) && $depth < self::MAX_DEPTH) {
            $b = preg_quote($params['boundary'], '/');
            $parts = preg_split('/\r?\n?--' . $b . '(?:--)?[ \t]*\r?\n?/', $body) ?: [];
            array_shift($parts); // prima della prima delimitazione (preambolo)
            foreach ($parts as $part) {
                if (trim($part) === '') {
                    continue;
                }
                [$ph, $pb] = self::split($part);
                self::walk($ph, $pb, $out, $depth + 1);
            }
            return;
        }
        $isText = $type === 'text/plain' || $type === 'text/html';
        $attached = str_starts_with($disposition, 'attachment') || isset($params['name']) || str_contains($disposition, 'filename');
        if (!$isText || $attached) {
            if ($type !== 'message/delivery-status' && $type !== 'text/calendar' || $attached) {
                $out['attachments']++;
            }
            return;
        }
        $data = self::transfer($body, strtolower(self::first($headers, 'content-transfer-encoding')));
        $data = self::toUtf8($data, strtolower($params['charset'] ?? 'utf-8'));
        if ($type === 'text/plain' && $out['text'] === '') {
            $out['text'] = $data;
        } elseif ($type === 'text/html' && $out['html'] === '') {
            $out['html'] = $data;
        }
    }

    /** @return array{0:string, 1:array<string,string>} */
    private static function contentType(string $value): array
    {
        $parts = explode(';', $value);
        $type = strtolower(trim((string) array_shift($parts)));
        $params = [];
        foreach ($parts as $p) {
            if (preg_match('/^\s*([A-Za-z0-9*-]+)\s*=\s*"?([^"]*)"?\s*$/', $p, $m)) {
                $params[strtolower($m[1])] = $m[2];
            }
        }
        return [$type, $params];
    }

    private static function transfer(string $data, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode($data),
            'quoted-printable' => quoted_printable_decode($data),
            default => $data,
        };
    }

    private static function toUtf8(string $data, string $charset): string
    {
        if ($charset === '' || $charset === 'utf-8' || $charset === 'us-ascii') {
            return mb_scrub($data, 'UTF-8');
        }
        try {
            return mb_convert_encoding($data, 'UTF-8', $charset);
        } catch (\ValueError) {
            return mb_scrub($data, 'UTF-8');
        }
    }

    private static function decode(string $v): string
    {
        return mb_decode_mimeheader($v);
    }

    /** Indirizzo e-mail dall'intestazione From ("Nome <a@b.it>" oppure "a@b.it"), in minuscolo. */
    private static function address(string $from): string
    {
        if (preg_match('/<([^<>@\s]+@[^<>\s]+)>/', $from, $m) || preg_match('/([^\s<>"]+@[^\s<>"]+)/', $from, $m)) {
            return strtolower($m[1]);
        }
        return '';
    }

    private static function messageId(string $v): string
    {
        return preg_match('/<([^<>\s]+)>/', $v, $m) ? '<' . $m[1] . '>' : '';
    }

    private static function htmlToText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $html = (string) preg_replace('~<(script|style|head)[^>]*>.*?</\1>~is', '', $html);
        $html = (string) preg_replace('~<(br|/p|/div|/tr|/li|/h[1-6])\b[^>]*>~i', "\n", $html);
        return trim((string) preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
