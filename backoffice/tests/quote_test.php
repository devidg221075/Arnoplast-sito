<?php
// Prova del preventivo con prezzi: importi, controlli del modulo, PDF, invio con allegato, revisioni.
// Uso: php backoffice/tests/quote_test.php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Backoffice\{App, Mailer, Quote};

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fails += $ok ? 0 : 1;
}

$work = sys_get_temp_dir() . '/bo-quote-' . bin2hex(random_bytes(4));
mkdir($work);
App::boot(['site_url' => 'https://www.arnoplast.it', 'admin_url' => 'https://gestione.arnoplast.it', 'secret' => 'test-secret-test-secret-test-secret',
    'db' => ['dsn' => 'sqlite:' . $work . '/db.sqlite'], 'mail' => ['from' => 'richieste@arnoplast.it', 'notify_to' => ['info@arnoplast.it']]]);
$sent = [];
Mailer::$transport = function (array $to, array $mail, string $ref, ?string $replyTo, array $headers, array $attachments = []) use (&$sent): bool {
    $sent[] = compact('to', 'mail', 'attachments', 'headers');
    return true;
};

// --- Importi
check(Quote::parseMoney('1.234,56') === 123456 && Quote::parseMoney('12,5') === 1250 && Quote::parseMoney('12.50') === 1250 && Quote::parseMoney('€ 7') === 700, 'importi scritti in modi diversi');
check(Quote::parseMoney('') === null && Quote::parseMoney('abc') === null && Quote::parseMoney('-5') === null && Quote::parseMoney('1,234') === null && Quote::parseMoney('12,505') === null, 'importi non validi rifiutati');
check(Quote::parseDiscount('10') === 10.0 && Quote::parseDiscount('') === 0.0 && Quote::parseDiscount('12,5%') === 12.5 && Quote::parseDiscount('101') === null && Quote::parseDiscount('x') === null, 'sconti');
check(Quote::money(123456, 'it') === '€ 1.234,56' && Quote::money(123456, 'en') === '€1,234.56', 'importi mostrati in italiano e in inglese');

$form = ['code' => ['A-1', 'B-2', '', ''], 'description' => ['Uno', 'Due', '', ''], 'qty' => ['10', '3', '', ''], 'unit' => ['12,50', '1,99', '', ''], 'disc' => ['10', '', '', ''],
    'shipping_label' => 'Trasporto', 'shipping' => '25,00', 'vat_rate' => '22', 'vat_note' => '', 'valid_days' => '30', 'payment' => 'Bonifico', 'delivery' => '7 giorni', 'notes' => "Nota\ndue"];
[$data, $errors] = Quote::fromForm($form);
check($errors === [] && count($data['lines']) === 2, 'modulo valido, righe vuote ignorate');
$t = Quote::totals($data);
// riga 1: 10 × 12,50 − 10% = 112,50 ; riga 2: 3 × 1,99 = 5,97 ; imponibile 112,50 + 5,97 + 25,00 = 143,47 ; IVA 22% = 31,56 ; totale 175,03
check($t['lines'] === [11250, 597] && $t['net'] === 14347 && $t['vat'] === 3156 && $t['total'] === 17503, 'totali: ' . json_encode($t));
[, $e1] = Quote::fromForm(['code' => ['A'], 'description' => [''], 'qty' => ['0'], 'unit' => ['x'], 'disc' => ['200'], 'vat_rate' => '7', 'valid_days' => '0', 'shipping' => 'no']);
check(count($e1) >= 5, 'errori: quantità, prezzo, sconto, IVA, validità, trasporto');
check(Quote::fromForm(['code' => [''], 'qty' => ['']])[1] === ['Inserisci almeno un articolo.'], 'senza articoli rifiutato');

// --- Bozza e invio
$pdo = App::db();
$pdo->exec("INSERT INTO requests (ref, kind, lang, status, name, email, data, items, created_at, updated_at) VALUES ('P-2026-0007','quote','it','nuova','Mario Rossi','mario@example.com','{\"name\":\"Mario\",\"surname\":\"Rossi\"}','[{\"code\":\"A-1\",\"description\":\"Uno\",\"qty\":10}]','2026-11-01 10:00:00','2026-11-01 10:00:00')");
$req = $pdo->query('SELECT * FROM requests')->fetch();
$draft = Quote::draftFromRequest($req);
check($draft['lines'][0]['code'] === 'A-1' && $draft['lines'][0]['qty'] === 10 && $draft['lines'][0]['unit_cents'] === 0, 'bozza iniziale dagli articoli del cliente');
$id = Quote::saveDraft((int) $req['id'], $data, 1);
$q = Quote::find($id);
check($q['rev'] == 1 && $q['status'] === 'bozza' && (int) $q['total'] === 17503, 'bozza salvata con i totali');
Quote::saveDraft((int) $req['id'], array_replace($data, ['shipping_cents' => 0]), 1, $id);
check((int) Quote::find($id)['total'] === Quote::totals(array_replace($data, ['shipping_cents' => 0]))['total'], 'bozza aggiornata');
Quote::saveDraft((int) $req['id'], $data, 1, $id);

$pdfDraft = Quote::pdf($req, Quote::find($id));
check(str_starts_with($pdfDraft, '%PDF') && strlen($pdfDraft) > 2000, 'anteprima PDF generata');
App::setSetting('conditions_it', 'Condizioni di prova ABC.');
$res = Quote::send($req, Quote::find($id), ['id' => 1, 'name' => 'Anna'], 'Ecco il preventivo.');
check($res['ok'] && count($sent) === 1, 'preventivo inviato');
$m = $sent[0];
check($m['to'] === ['mario@example.com'] && str_contains($m['mail']['subject'], '[P-2026-0007]') && !str_contains($m['mail']['subject'], 'rev.'), 'oggetto con il riferimento');
check(count($m['attachments']) === 1 && $m['attachments'][0]['type'] === 'application/pdf' && $m['attachments'][0]['name'] === 'P-2026-0007-rev1.pdf' && str_starts_with($m['attachments'][0]['data'], '%PDF'), 'PDF allegato');
file_put_contents($work . '/sent.pdf', $m['attachments'][0]['data']);
$txt = shell_exec('pdftotext -layout ' . escapeshellarg($work . '/sent.pdf') . ' - 2>/dev/null') ?? '';
if ($txt !== '') {
    check(str_contains($txt, 'P-2026-0007') && str_contains($txt, '175,03') && str_contains($txt, 'Condizioni di prova ABC.') && str_contains($txt, 'A-1'), 'PDF: numero, totale, condizioni e articoli');
}
check(str_contains($m['mail']['html'], '175,03') && str_contains($m['mail']['html'], 'Ecco il preventivo.'), 'e-mail con totale e messaggio');
check($pdo->query("SELECT status FROM requests")->fetchColumn() === 'preventivo_inviato', 'richiesta: preventivo inviato');
check(Quote::find($id)['status'] === 'inviato' && Quote::find($id)['pdf_file'] === 'P-2026-0007-rev1.pdf' && is_file(dirname(__DIR__) . '/var/quotes/P-2026-0007-rev1.pdf'), 'copia del PDF conservata');
check(!Quote::send($req, Quote::find($id), ['id' => 1, 'name' => 'Anna'], '')['ok'] && count($sent) === 1, 'un preventivo inviato non si reinvia');
$msg = $pdo->query("SELECT * FROM messages WHERE direction = 'out'")->fetch();
check($msg && str_contains($msg['body_text'], 'Allegato: preventivo P-2026-0007') && (int) $msg['sent_ok'] === 1, 'messaggio nella conversazione');

// --- Revisione
$id2 = Quote::saveDraft((int) $req['id'], Quote::data(Quote::find($id)), 1);
check((int) Quote::find($id2)['rev'] === 2 && Quote::number($req, Quote::find($id2)) === 'P-2026-0007 rev. 2', 'revisione 2 con lo stesso numero');
$sent = [];
Quote::send($req, Quote::find($id2), ['id' => 1, 'name' => 'Anna'], '');
check(str_contains($sent[0]['mail']['subject'], 'rev. 2') && $sent[0]['attachments'][0]['name'] === 'P-2026-0007-rev2.pdf', 'e-mail della revisione 2');

// --- Inglese e invio fallito
$pdo->exec("INSERT INTO requests (ref, kind, lang, status, name, email, data, items, created_at, updated_at) VALUES ('P-2026-0008','quote','en','nuova','John Smith','john@example.com','{}',NULL,'2026-11-01 10:00:00','2026-11-01 10:00:00')");
$req2 = $pdo->query("SELECT * FROM requests WHERE ref = 'P-2026-0008'")->fetch();
$id3 = Quote::saveDraft((int) $req2['id'], $data, 1);
Mailer::$transport = fn (): bool => false;
$bad = Quote::send($req2, Quote::find($id3), ['id' => 1, 'name' => 'Anna'], '');
check(!$bad['ok'] && Quote::find($id3)['status'] === 'bozza' && $pdo->query("SELECT status FROM requests WHERE ref = 'P-2026-0008'")->fetchColumn() === 'nuova', 'invio fallito: resta bozza, richiesta invariata');
Mailer::$transport = function (array $to, array $mail, string $ref, ?string $rt, array $h, array $att = []) use (&$sent): bool { $sent = [compact('mail', 'att')]; return true; };
Quote::send($req2, Quote::find($id3), ['id' => 1, 'name' => 'Anna'], '');
check(str_starts_with($sent[0]['mail']['subject'], 'Your ARNOplast quotation') && str_contains($sent[0]['mail']['html'], 'Kind regards'), 'e-mail in inglese per la richiesta inglese');

echo $fails === 0 ? "\nTutti i controlli superati\n" : "\n$fails controlli falliti\n";
exit($fails === 0 ? 0 : 1);
