<?php
// Prova degli ordini: nascono dal preventivo accettato, un solo ordine per richiesta, stati solo in avanti.
// Uso: php backoffice/tests/orders_test.php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Backoffice\{App, Orders, Quote};

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fails += $ok ? 0 : 1;
}

$work = sys_get_temp_dir() . '/bo-orders-' . bin2hex(random_bytes(4));
mkdir($work);
App::boot(['site_url' => 'https://www.arnoplast.it', 'secret' => 'test-secret-test-secret-test-secret', 'db' => ['dsn' => 'sqlite:' . $work . '/db.sqlite']]);
$pdo = App::db();

$t = date('Y-m-d H:i:s');
$pdo->prepare('INSERT INTO requests (ref, kind, lang, status, name, email, phone, company, data, items, ip_hash, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
    ->execute(['P-2026-0007', 'quote', 'it', 'nuova', 'Marco Rossi', 'marco@esempio.it', '', 'Agrimec Srl', '{}', null, '', $t, $t]);
$req = $pdo->query('SELECT * FROM requests WHERE ref = \'P-2026-0007\'')->fetch();
$data = ['lines' => [['code' => 'A-1', 'description' => 'Uno', 'qty' => 10, 'unit_cents' => 1250, 'disc' => 0.0]], 'shipping_cents' => 0, 'shipping_label' => '',
    'vat_rate' => 22, 'vat_note' => '', 'valid_days' => 30, 'payment' => '', 'delivery' => '', 'notes' => ''];
$qid = Quote::saveDraft((int) $req['id'], $data, 1);
$quote = Quote::find($qid);

check(Orders::number($req) === 'O-2026-0007', 'numero d\'ordine: O- più il numero della richiesta');
[, $err] = Orders::accept($req, $quote, 1, '');
check($err !== null, 'un preventivo in bozza non si può accettare');

$pdo->prepare("UPDATE quotes SET status = 'inviato', sent_at = ? WHERE id = ?")->execute([$t, $qid]);
$quote = Quote::find($qid);
[$oid, $err] = Orders::accept($req, $quote, 1, 'Confermato per telefono');
check($err === null && $oid !== null, 'preventivo inviato accettato: ordine creato');
$order = Orders::find((int) $oid);
check($order['ref'] === 'O-2026-0007' && $order['status'] === 'confermato' && (int) $order['total'] === (int) $quote['total'], 'ordine con numero, stato iniziale e totale del preventivo');
check(Quote::find($qid)['status'] === 'accettato', 'il preventivo risulta accettato');
check(Orders::find((int) $oid)['note'] === 'Confermato per telefono', 'nota della conferma salvata');

// Una seconda revisione della stessa richiesta: non si accetta un secondo ordine.
$data2 = $data;
$qid2 = Quote::saveDraft((int) $req['id'], $data2, 1);
$pdo->prepare("UPDATE quotes SET status = 'inviato' WHERE id = ?")->execute([$qid2]);
[, $err] = Orders::accept($req, Quote::find($qid2), 1, '');
check($err !== null && str_contains($err, 'già un preventivo accettato'), 'una sola accettazione per richiesta');

// Stati: solo in avanti.
check(Orders::advance((int) $oid, 'confermato', 1, '') !== null, 'stesso stato: rifiutato');
check(Orders::advance((int) $oid, 'in_lavorazione', 1, '') === null, 'confermato → in lavorazione');
check(Orders::advance((int) $oid, 'confermato', 1, '') !== null, 'indietro: rifiutato');
check(Orders::advance((int) $oid, 'chiuso', 1, '') === null, 'in lavorazione → chiuso (saltando lo spedito)');
check(Orders::advance((int) $oid, 'spedito', 1, '') !== null, 'da chiuso non si torna indietro');
check(Orders::advance(999, 'spedito', 1, '') !== null, 'ordine inesistente');

check(count(Orders::list('')) === 1 && count(Orders::list('chiuso')) === 1 && Orders::list('spedito') === [], 'elenco e filtro per stato');
check(Orders::number(['ref' => 'C-2026-0001']) === 'O-2026-0001', 'numero coerente anche per altre sigle');

echo $fails === 0 ? "\nTutti i controlli superati\n" : "\n$fails controlli falliti\n";
exit($fails === 0 ? 0 : 1);
