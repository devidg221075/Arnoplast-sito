<?php
// Prova della conversazione con il cliente: risposta in uscita, lettura della casella (server IMAP finto), antidoppioni.
// Uso: php backoffice/tests/inbox_test.php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Backoffice\{App, Conversation, Imap, Inbox, Mailer, Mime};

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fails += $ok ? 0 : 1;
}

$work = sys_get_temp_dir() . '/bo-test-' . bin2hex(random_bytes(4));
mkdir($work . '/mail', 0777, true);
$port = random_int(20000, 40000);
$server = proc_open(['python3', __DIR__ . '/fake_imap.py', (string) $port, $work . '/mail'], [], $pipes);
register_shutdown_function(static function () use ($server, $work): void {
    proc_terminate($server);
    array_map('unlink', glob($work . '/mail/*') ?: []);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}

App::boot([
    'site_url' => 'https://www.arnoplast.it', 'admin_url' => 'https://gestione.arnoplast.it', 'secret' => 'test-secret-test-secret-test-secret',
    'db' => ['dsn' => 'sqlite:' . $work . '/db.sqlite'],
    'mail' => ['host' => '127.0.0.1', 'user' => 'richieste@arnoplast.it', 'password' => 'secret', 'from' => 'richieste@arnoplast.it', 'from_name' => 'ARNOplast', 'notify_to' => ['info@arnoplast.it']],
    'imap' => ['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none'],
]);
$sent = [];
Mailer::$transport = function (array $to, array $mail, string $ref, ?string $replyTo, array $headers) use (&$sent): bool {
    $sent[] = compact('to', 'mail', 'replyTo', 'headers');
    return true;
};
$pdo = App::db();
$pdo->exec("INSERT INTO requests (ref, kind, lang, status, name, email, data, created_at, updated_at) VALUES ('P-2026-0001','quote','it','preventivo_inviato','Mario Rossi','mario@example.com','{}','2026-11-01 10:00:00','2026-11-01 10:00:00')");
$pdo->exec("INSERT INTO requests (ref, kind, lang, status, name, email, data, created_at, updated_at) VALUES ('C-2026-0001','contact','en','nuova','John Smith','john@example.com','{}','2026-11-01 10:00:00','2026-11-01 10:00:00')");
$req = $pdo->query("SELECT * FROM requests WHERE ref = 'C-2026-0001'")->fetch();
$staff = ['id' => 1, 'name' => 'Anna'];

// --- Risposta in uscita
$r = Conversation::reply($req, $staff, '', "Hello John,\n\nthe catalogue is attached to our site.\n\nBest");
check($r['ok'] && count($sent) === 1, 'risposta inviata');
check($sent[0]['to'] === ['john@example.com'] && str_contains($sent[0]['mail']['subject'], '[C-2026-0001]') && str_contains($sent[0]['mail']['subject'], 'Reply to your request'), 'oggetto in inglese con il riferimento');
check(str_contains($sent[0]['mail']['html'], 'Kind regards') && str_contains($sent[0]['mail']['html'], 'Anna - ARNOplast'), 'modello in inglese con la firma');
check($pdo->query("SELECT status FROM requests WHERE id = {$req['id']}")->fetchColumn() === 'in_lavorazione', 'stato: da nuova a in lavorazione');
check(!Conversation::reply($req, $staff, 'x', '   ')['ok'], 'risposta vuota rifiutata');
$out = $pdo->query("SELECT * FROM messages WHERE direction = 'out'")->fetch();
check($out && $out['message_id'] !== '' && (int) $out['sent_ok'] === 1, 'risposta salvata con identificativo');

// --- Casella finta
$mime = static fn (string $subject, string $body, string $extra = '', string $from = 'John Smith <john@example.com>'): string =>
    "From: $from\r\nTo: richieste@arnoplast.it\r\nSubject: $subject\r\nMessage-ID: <" . bin2hex(random_bytes(6)) . "@example.com>\r\n$extra" . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$body\r\n";
file_put_contents($work . '/mail/01.eml', $mime('Re: Reply to your request [C-2026-0001]', "Thanks, perfect.\r\n\r\nOn Mon, 3 Nov 2026 at 10:00, ARNOplast <richieste@arnoplast.it> wrote:\r\n> Hello John,\r\n> the catalogue..."));
file_put_contents($work . '/mail/02.eml', $mime('Offerta fertilizzanti', 'Pubblicità non nostra'));
$inReplyTo = "In-Reply-To: {$out['message_id']}\r\n";
file_put_contents($work . '/mail/03.eml', $mime('Re: no reference in subject', 'Answer without the reference', $inReplyTo));
file_put_contents($work . '/mail/04.eml', $mime('Automatic reply: [C-2026-0001]', 'Sono fuori ufficio', "Auto-Submitted: auto-replied\r\n"));
$mixed = "From: Mario Rossi <mario@example.com>\r\nTo: richieste@arnoplast.it\r\nSubject: =?UTF-8?B?UmU6IFByZXZlbnRpdm8gW1AtMjAyNi0wMDAxXQ==?=\r\nMessage-ID: <mixed@example.com>\r\nMIME-Version: 1.0\r\n"
    . "Content-Type: multipart/mixed; boundary=\"BB\"\r\n\r\n--BB\r\nContent-Type: multipart/alternative; boundary=\"AA\"\r\n\r\n--AA\r\nContent-Type: text/plain; charset=iso-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nVa bene, conferm=E0 l'ordine.\r\n--AA\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>html</p>\r\n--AA--\r\n--BB\r\nContent-Type: application/pdf; name=\"ordine.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\nJVBERg==\r\n--BB--\r\n";
file_put_contents($work . '/mail/05.eml', $mixed);

$res = Inbox::run();
check($res['error'] === '' && $res['checked'] === 5, 'controllo della casella: ' . json_encode($res));
check($res['imported'] === 3, 'aggiunti 3 messaggi (oggetto, risposta senza riferimento, multipart con allegato)');
$in = $pdo->query("SELECT * FROM messages WHERE direction = 'in' AND from_addr <> 'mario@example.com' ORDER BY id")->fetchAll();
check(count($in) === 2, 'due messaggi in arrivo nella conversazione di John (oggetto e In-Reply-To)');
check($pdo->query("SELECT COUNT(*) FROM messages m JOIN requests r ON r.id = m.request_id WHERE r.ref = 'P-2026-0001' AND m.direction = 'in'")->fetchColumn() == 1, 'multipart assegnato al preventivo');
$p = $pdo->query("SELECT * FROM messages WHERE message_id = '<mixed@example.com>'")->fetch();
check(str_contains($p['body_text'], "confermà l'ordine") && str_contains($p['body_text'], '1 allegato'), 'testo in latin1 decodificato e allegato segnalato');
check(!str_contains(implode("\n", array_column($pdo->query("SELECT body_text FROM messages")->fetchAll(), 'body_text')), 'Pubblicità non nostra'), 'posta non riconosciuta non importata');
check($pdo->query("SELECT status FROM requests WHERE ref = 'P-2026-0001'")->fetchColumn() === 'in_lavorazione', 'preventivo già inviato torna in lavorazione');
check(count($sent) >= 4 && str_contains(end($sent)['mail']['subject'], 'Risposta del cliente'), 'notifica ad ARNOplast per ogni risposta');

// --- Seconda esecuzione: nessun doppione, nessun comando di scrittura
$before = count($sent);
$res2 = Inbox::run();
check($res2['error'] === '' && $res2['imported'] === 0 && $res2['checked'] === 0, 'secondo controllo: niente di nuovo');
file_put_contents($work . '/mail/06.eml', $mime('Re: [C-2026-0001] another', 'Second reply'));
$res3 = Inbox::run();
check($res3['imported'] === 1 && count($sent) === $before + 1, 'messaggio nuovo preso al controllo successivo');
$log = file_get_contents($work . '/mail/_log');
check(!preg_match('/\b(STORE|COPY|MOVE|EXPUNGE|DELETE|APPEND)\b/i', $log) && str_contains($log, 'BODY.PEEK[]') && !str_contains($log, 'SELECT '), 'la casella non viene modificata (solo EXAMINE e BODY.PEEK)');

// --- Password sbagliata
App::boot(array_replace_recursive(['imap' => ['password' => 'sbagliata']], [
    'site_url' => 'https://www.arnoplast.it', 'admin_url' => 'https://x', 'secret' => 'test-secret-test-secret-test-secret',
    'db' => ['dsn' => 'sqlite:' . $work . '/db.sqlite'],
    'mail' => ['host' => '127.0.0.1', 'user' => 'richieste@arnoplast.it', 'password' => 'secret', 'from' => 'richieste@arnoplast.it'],
    'imap' => ['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none', 'password' => 'sbagliata'],
]));
$bad = Inbox::run();
check(str_contains($bad['error'], 'rifiutato') && !str_contains(json_encode($bad), 'sbagliata'), 'password sbagliata: errore chiaro, senza mostrare la password');

// --- Citazioni
[$new, $quoted] = Mime::splitQuote("Va bene.\n\nIl giorno lun 3 nov 2026 alle 10:00 ARNOplast\n<richieste@arnoplast.it> ha scritto:\n> testo");
check($new === 'Va bene.' && str_contains($quoted, 'ha scritto'), 'citazione italiana a capo ripiegata');
check(Mime::splitQuote("> solo citazione")[0] === '> solo citazione', 'solo citazione: si mostra intero');

echo $fails === 0 ? "\nTutti i controlli superati\n" : "\n$fails controlli falliti\n";
exit($fails === 0 ? 0 : 1);
