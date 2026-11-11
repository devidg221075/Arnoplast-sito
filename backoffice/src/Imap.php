<?php
declare(strict_types=1);

namespace Backoffice;

/**
 * Client IMAP minimo, solo per leggere: nessuna estensione (l'hosting condiviso spesso non ha "imap"), nessuna modifica alla casella.
 * I messaggi si scaricano con BODY.PEEK, quindi restano "non letti" e nessuna cartella viene toccata.
 */
final class Imap
{
    /** @var resource|null */
    private $fp = null;
    private int $n = 0;

    public function __construct(private string $host, private int $port, private string $encryption = 'ssl', private bool $verifyPeer = true, private int $timeout = 20)
    {
    }

    public function connect(): void
    {
        $scheme = $this->encryption === 'none' ? 'tcp' : 'ssl';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $this->verifyPeer, 'verify_peer_name' => $this->verifyPeer, 'SNI_enabled' => true]]);
        $fp = @stream_socket_client("$scheme://{$this->host}:{$this->port}", $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new \RuntimeException("Connessione al server di posta non riuscita ($errstr)");
        }
        stream_set_timeout($fp, $this->timeout);
        $this->fp = $fp;
        $greeting = $this->line();
        if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
            throw new \RuntimeException('Risposta inattesa dal server di posta');
        }
    }

    public function login(string $user, string $password): void
    {
        if (preg_match('/[\r\n]/', $user . $password)) {
            throw new \RuntimeException('Utente o password non validi');
        }
        $r = $this->command('LOGIN ' . self::quote($user) . ' ' . self::quote($password));
        if (!$r['ok']) {
            throw new \RuntimeException('Accesso alla casella rifiutato (controlla utente e password)');
        }
    }

    /** @return array{validity:int, next:int} */
    public function select(string $mailbox = 'INBOX'): array
    {
        $r = $this->command('EXAMINE ' . self::quote($mailbox)); // sola lettura
        if (!$r['ok']) {
            throw new \RuntimeException('Cartella della posta non trovata');
        }
        $all = implode("\n", $r['lines']);
        return [
            'validity' => preg_match('/UIDVALIDITY (\d+)/', $all, $m) ? (int) $m[1] : 0,
            'next' => preg_match('/UIDNEXT (\d+)/', $all, $m) ? (int) $m[1] : 1,
        ];
    }

    /** @return int[] identificativi (UID) dei messaggi che rispondono al criterio, ad es. "UID 5:*" o "SINCE 01-Nov-2026". */
    public function search(string $criteria): array
    {
        $r = $this->command('UID SEARCH ' . $criteria);
        if (!$r['ok']) {
            throw new \RuntimeException('Ricerca nella casella non riuscita');
        }
        $uids = [];
        foreach ($r['lines'] as $l) {
            if (preg_match('/^\* SEARCH ?(.*)$/', trim($l), $m) && $m[1] !== '') {
                $uids = array_merge($uids, array_map('intval', preg_split('/\s+/', trim($m[1])) ?: []));
            }
        }
        sort($uids);
        return $uids;
    }

    public function size(int $uid): int
    {
        $r = $this->command("UID FETCH $uid (RFC822.SIZE)");
        return $r['ok'] && preg_match('/RFC822\.SIZE (\d+)/', implode("\n", $r['lines']), $m) ? (int) $m[1] : 0;
    }

    public function fetch(int $uid): string
    {
        $r = $this->command("UID FETCH $uid (BODY.PEEK[])");
        if (!$r['ok'] || !$r['literals']) {
            throw new \RuntimeException("Messaggio $uid non leggibile");
        }
        return $r['literals'][0];
    }

    public function logout(): void
    {
        if ($this->fp) {
            try {
                $this->command('LOGOUT');
            } catch (\Throwable) {
            }
            fclose($this->fp);
            $this->fp = null;
        }
    }

    private static function quote(string $s): string
    {
        return '"' . addcslashes($s, "\"\\") . '"';
    }

    /** @return array{ok:bool, lines:string[], literals:string[], status:string} */
    private function command(string $cmd): array
    {
        $tag = 'A' . str_pad((string) ++$this->n, 3, '0', STR_PAD_LEFT);
        fwrite($this->fp, "$tag $cmd\r\n");
        $lines = [];
        $literals = [];
        while (true) {
            $l = $this->line();
            while (preg_match('/\{(\d+)\}\r?\n$/', $l, $m)) {
                $literals[] = $this->bytes((int) $m[1]);
                $lines[] = $l;
                $l = $this->line();
            }
            if (str_starts_with($l, "$tag ")) {
                return ['ok' => str_starts_with($l, "$tag OK"), 'lines' => $lines, 'literals' => $literals, 'status' => trim($l)];
            }
            $lines[] = $l;
        }
    }

    private function line(): string
    {
        $l = fgets($this->fp, 65536);
        if ($l === false) {
            throw new \RuntimeException('Il server di posta ha interrotto la connessione');
        }
        return $l;
    }

    private function bytes(int $len): string
    {
        $data = '';
        while (strlen($data) < $len) {
            $chunk = fread($this->fp, min(65536, $len - strlen($data)));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('Il server di posta ha interrotto la connessione');
            }
            $data .= $chunk;
        }
        return $data;
    }
}
