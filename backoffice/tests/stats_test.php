<?php
// Prova delle statistiche: pagine e provenienze, esclusioni, nessun IP salvato, visite e percorsi.
// Uso: php backoffice/tests/stats_test.php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Backoffice\{App, Stats};

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fails += $ok ? 0 : 1;
}

$work = sys_get_temp_dir() . '/bo-stats-' . bin2hex(random_bytes(4));
mkdir($work);
App::boot(['site_url' => 'https://www.arnoplast.it', 'secret' => 'test-secret-test-secret-test-secret', 'db' => ['dsn' => 'sqlite:' . $work . '/db.sqlite']]);
$pdo = App::db();
$ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';

check(Stats::path('/it/prodotti/agricoltura/') === '/it/prodotti/agricoltura/', 'percorso valido');
check(Stats::path('/it/prodotti') === '/it/prodotti/' && Stats::path('/') === '/', 'percorso senza barra finale normalizzato');
check(Stats::path('/admin/index.php') === null && Stats::path('/../etc') === null && Stats::path('https://x.it/it/') === null, 'pagine fuori dal sito rifiutate');
check(Stats::source('') === ['diretto', 'Accesso diretto'], 'provenienza: accesso diretto');
check(Stats::source('https://www.google.it/search?q=arno') [0] === 'motore', 'provenienza: motore di ricerca');
check(Stats::source('https://www.facebook.com/x')[0] === 'social', 'provenienza: social');
check(Stats::source('https://www.arnoplast.it/it/prodotti/?x=1') === ['interno', '/it/prodotti/'], 'provenienza: pagina del sito');
check(Stats::source('https://esempio.org/pagina')[0] === 'sito', 'provenienza: altro sito');
check(Stats::device($ua) === 'Computer' && Stats::device('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)') === 'Smartphone' && Stats::device('Mozilla/5.0 (iPad; CPU OS 17_0)') === 'Tablet', 'dispositivi riconosciuti');
check(Stats::browser($ua) === 'Safari' && Stats::browser('Mozilla/5.0 Chrome/120 Safari/537') === 'Chrome' && Stats::browser('Mozilla/5.0 Edg/120') === 'Edge', 'browser riconosciuti');

check(Stats::record(['p' => '/it/'], '203.0.113.5', $ua, '1') === false, 'Non tracciare: nessuna segnalazione');
check(Stats::record(['p' => '/it/'], '203.0.113.5', 'Googlebot/2.1 (+http://www.google.com/bot.html)') === false, 'robot esclusi');
check(Stats::record(['p' => '/admin/'], '203.0.113.5', $ua) === false, 'pagina non del sito esclusa');
check(Stats::record(['p' => '/it/prodotti/?q=1', 'r' => 'https://www.google.it/'], '203.0.113.5', $ua) === true, 'pagina valida registrata');
Stats::record(['p' => '/it/prodotti/'], '203.0.113.5', $ua);
$row = $pdo->query('SELECT * FROM hits ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
check(preg_match('/^[a-f0-9]{32}$/', (string) $row['visitor']) === 1, 'visitatore: impronta, non l\'indirizzo');
$all = implode(' ', array_map('strval', $pdo->query('SELECT * FROM hits')->fetchAll(PDO::FETCH_NUM)[0] ?? []));
check(!str_contains(json_encode($pdo->query('SELECT * FROM hits')->fetchAll(PDO::FETCH_ASSOC)), '203.0.113.5'), 'l\'indirizzo IP non compare nel database');
check($row['path'] === '/it/prodotti/' && $row['ref_kind'] === 'motore', 'pagina e provenienza salvate');
check(Stats::country('203.0.113.5') === null, 'senza database geografico il paese resta vuoto');
$pdo->exec('DELETE FROM hits');

// Dati noti: due visite. Visita 1: home → prodotti → scheda (con una pausa di 5 minuti). Visita 2: home (un altro visitatore), poi dopo un'ora contatti.
$today = date('Y-m-d');
$ins = $pdo->prepare('INSERT INTO hits (day, at, visitor, path, ref_kind, ref, device, browser, country) VALUES (?,?,?,?,?,?,?,?,?)');
$base = strtotime(date('Y-m-d 10:00:00'));
$rows = [
    ['aaa', 0, '/it/', 'diretto', 'Accesso diretto'],
    ['aaa', 120, '/it/prodotti/', 'interno', '/it/'],
    ['aaa', 420, '/it/prodotti/agricoltura/', 'interno', '/it/prodotti/'],
    ['bbb', 60, '/it/', 'motore', 'Motori di ricerca'],
    ['bbb', 3600 + 60, '/it/contatti/', 'interno', '/it/'],
];
foreach ($rows as [$v, $off, $path, $kind, $ref]) {
    $ins->execute([$today, date('Y-m-d H:i:s', $base + $off), $v, $path, $kind, $ref, 'Computer', 'Safari', 'IT']);
}
$s = Stats::summary(7);
check($s['pages'] === 5, 'pagine viste: 5');
check($s['visits'] === 3, 'visite: 3 (la pausa di un\'ora chiude la visita di bbb)');
check($s['visitors'] === 2, 'visitatori stimati: 2');
check($s['top_pages'][0] === ['label' => '/it/', 'n' => 2], 'pagina più vista: home');
check(in_array('/it/', array_column($s['entries'], 'label'), true), 'ingresso dalla home');
check(in_array('/it/prodotti/agricoltura/', array_column($s['exits'], 'label'), true), 'uscita dalla scheda prodotto');
check($s['paths'][0]['label'] === '/it/  →  /it/prodotti/  →  /it/prodotti/agricoltura/' && $s['paths'][0]['n'] === 1, 'percorso in tre passi ricostruito');
check($s['devices'] === [['label' => 'Computer', 'n' => 5]], 'dispositivi: 5 computer');
check($s['countries'][0] === ['label' => 'IT', 'n' => 5] && $s['unknown_country'] === 0, 'paesi: 5 da Italia');
check(array_sum(array_column($s['daily'], 'pages')) === 5 && count($s['daily']) === 7, 'andamento su 7 giorni');
check(Stats::summary(7)['pages'] === 5 && Stats::summary(30)['pages'] === 5, 'periodo più ampio: stessi dati');

echo $fails === 0 ? "\nTutti i controlli superati\n" : "\n$fails controlli falliti\n";
exit($fails === 0 ? 0 : 1);
