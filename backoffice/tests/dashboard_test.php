<?php
// Prova della dashboard: indicatori e variazioni calcolati dai dati reali, ultime richieste, andamento.
// Uso: php backoffice/tests/dashboard_test.php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Backoffice\{App, Dashboard};

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fails += $ok ? 0 : 1;
}

$work = sys_get_temp_dir() . '/bo-dash-' . bin2hex(random_bytes(4));
mkdir($work);
App::boot(['site_url' => 'https://www.arnoplast.it', 'secret' => 'test-secret-test-secret-test-secret', 'db' => ['dsn' => 'sqlite:' . $work . '/db.sqlite']]);
$pdo = App::db();
$now = time();
$ago = static fn (int $days): string => date('Y-m-d H:i:s', $now - $days * 86400);

// Richieste: tre nel periodo corrente (due clienti diversi), una nel precedente.
$addReq = static function (string $ref, string $status, string $email, string $when, string $kind = 'contact') use ($pdo): int {
    $pdo->prepare('INSERT INTO requests (ref, kind, lang, status, name, email, phone, company, data, items, ip_hash, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$ref, $kind, 'it', $status, 'Cliente ' . $ref, $email, '', '', '{}', null, '', $when, $when]);
    return (int) $pdo->lastInsertId();
};
$r1 = $addReq('P-2026-0001', 'nuova', 'a@x.it', $ago(2), 'quote');
$addReq('P-2026-0002', 'in_lavorazione', 'b@x.it', $ago(10), 'quote');
$addReq('C-2026-0003', 'nuova', 'a@x.it', $ago(20));
$addReq('C-2026-0004', 'chiusa', 'c@x.it', $ago(45));

// Preventivi: uno in bozza nel periodo corrente, uno inviato nel precedente. Un ordine nel periodo corrente.
$pdo->prepare('INSERT INTO quotes (request_id, rev, status, data, net, vat, total, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
    ->execute([$r1, 1, 'bozza', '{}', 0, 0, 10000, 1, $ago(1), $ago(1)]);
$pdo->prepare('INSERT INTO quotes (request_id, rev, status, data, net, vat, total, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
    ->execute([$r1, 2, 'accettato', '{}', 0, 0, 20000, 1, $ago(40), $ago(40)]);
$pdo->prepare('INSERT INTO orders (quote_id, request_id, ref, status, total, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)')
    ->execute([2, $r1, 'O-2026-0001', 'confermato', 20000, 1, $ago(3), $ago(3)]);

$k = Dashboard::kpis();
check($k['quotes']['value'] === 1, 'preventivi da completare: 1 (la bozza)');
check($k['orders']['value'] === 1 && $k['orders']['delta'] === null, 'ordini: 1 nel periodo, nessun confronto (il periodo precedente è vuoto)');
check($k['new']['value'] === 2, 'richieste nuove: 2');
check($k['clients']['value'] === 2 && $k['clients']['delta'] === 100.0, 'nuovi clienti: 2 contro 1 (+100%)');
check(Dashboard::delta(5, 4) === 25.0 && Dashboard::delta(3, 0) === null && Dashboard::delta(0, 4) === -100.0, 'variazione percentuale');

$latest = Dashboard::latest(5);
check(count($latest) === 4 && $latest[0]['ref'] === 'P-2026-0001', 'ultime richieste: ordinate dalla più recente');
check(in_array('20000', array_map('strval', array_column($latest, 'total')), true), 'importo dell\'ultimo preventivo accanto alla richiesta');

$weeks = Dashboard::weekly(8);
check(count($weeks) === 8 && array_sum(array_column($weeks, 'orders')) === 1, 'andamento: 8 settimane, un ordine');

$m = Dashboard::metrics();
check(isset($m['visits'], $m['downloads'], $m['clients']) && $m['clients'] === 2, 'metriche: visite, scaricamenti, clienti');

$pdo->prepare("INSERT INTO activity (user_id, action, target, detail, created_at) VALUES (?,?,?,?,?)")->execute([1, 'ordine.creato', 'O-2026-0001', '€ 200,00', $ago(3)]);
$act = Dashboard::activity(6);
check($act !== [] && str_contains($act[0]['text'], 'Ordine O-2026-0001 creato'), 'attività recenti in italiano');

echo $fails === 0 ? "\nTutti i controlli superati\n" : "\n$fails controlli falliti\n";
exit($fails === 0 ? 0 : 1);
