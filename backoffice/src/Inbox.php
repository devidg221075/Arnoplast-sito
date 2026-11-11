<?php
declare(strict_types=1);

namespace Backoffice;

/**
 * Lettura della casella delle richieste: prende solo i messaggi che riconosce (dal riferimento C-/P-AAAA-NNNN
 * nell'oggetto o dalla conversazione già avviata) e li mette nella scheda della richiesta. Il resto della posta
 * non si tocca: i messaggi si leggono senza segnarli come letti e non si spostano né si cancellano.
 */
final class Inbox
{
    private const MAX_PER_RUN = 60;
    private const MAX_BYTES = 10_000_000;
    private const FIRST_RUN_DAYS = 14;

    /** @return array{checked:int, imported:int, skipped:int, error:string} */
    public static function run(): array
    {
        $res = ['checked' => 0, 'imported' => 0, 'skipped' => 0, 'error' => ''];
        $lock = self::lock();
        if ($lock === false) {
            $res['error'] = 'Un altro controllo è già in corso.';
            return $res;
        }
        $imap = null;
        try {
            $cfg = (array) App::config('imap', []);
            $mail = (array) App::config('mail', []);
            $host = (string) ($cfg['host'] ?? $mail['host'] ?? '');
            $user = (string) ($cfg['user'] ?? $mail['user'] ?? '');
            $password = (string) ($cfg['password'] ?? $mail['password'] ?? '');
            if ($host === '' || $user === '' || $password === '') {
                throw new \RuntimeException('La casella non è configurata in config.php');
            }
            $imap = new Imap($host, (int) ($cfg['port'] ?? 993), (string) ($cfg['encryption'] ?? 'ssl'), (bool) ($cfg['verify_peer'] ?? true));
            $imap->connect();
            $imap->login($user, $password);
            $box = $imap->select((string) ($cfg['folder'] ?? 'INBOX'));

            $validity = App::state('imap_validity');
            $last = (int) App::state('imap_last_uid', '0');
            if ($validity !== (string) $box['validity'] || $last === 0) {
                // Prima volta (o casella ricreata): si guardano gli ultimi giorni; i messaggi già visti si riconoscono dall'identificativo.
                $uids = $imap->search('SINCE ' . date('d-M-Y', time() - self::FIRST_RUN_DAYS * 86400));
                $last = 0;
            } else {
                $uids = array_values(array_filter($imap->search('UID ' . ($last + 1) . ':*'), static fn (int $u): bool => $u > $last));
            }
            foreach (array_slice($uids, 0, self::MAX_PER_RUN) as $uid) {
                $res['checked']++;
                try {
                    if ($imap->size($uid) > self::MAX_BYTES) {
                        $res['skipped']++;
                    } elseif (Conversation::receive(Mime::parse($imap->fetch($uid)))) {
                        $res['imported']++;
                    } else {
                        $res['skipped']++;
                    }
                } catch (\RuntimeException $e) {
                    throw $e; // errore di rete: si interrompe e si riprova al prossimo controllo
                } catch (\Throwable $e) {
                    $res['skipped']++;
                    App::log(null, 'posta.errore', (string) $uid, $e->getMessage());
                }
                $last = max($last, $uid);
                App::setState('imap_last_uid', (string) $last);
                App::setState('imap_validity', (string) $box['validity']);
            }
            if (!$uids) {
                App::setState('imap_validity', (string) $box['validity']);
                App::setState('imap_last_uid', (string) max($last, $box['next'] - 1));
            }
        } catch (\Throwable $e) {
            $res['error'] = mb_substr($e->getMessage(), 0, 300);
            App::log(null, 'posta.errore', '', $res['error']);
        } finally {
            $imap?->logout();
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        App::setState('imap_last_run', App::now());
        App::setState('imap_last_result', $res['error'] !== '' ? 'Errore: ' . $res['error']
            : sprintf('%d messaggi controllati, %d aggiunti alle richieste, %d ignorati', $res['checked'], $res['imported'], $res['skipped']));
        return $res;
    }

    /** @return resource|false */
    private static function lock()
    {
        $dir = dirname(__DIR__) . '/var';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $f = @fopen($dir . '/fetch.lock', 'c');
        if ($f && !flock($f, LOCK_EX | LOCK_NB)) {
            fclose($f);
            return false;
        }
        return $f ?: fopen('php://temp', 'r+'); // senza cartella var scrivibile si procede senza blocco
    }
}
