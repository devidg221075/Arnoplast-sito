<?php
// Prova della riapertura del database dopo una connessione chiusa («MySQL server has gone away»).
// Uso: php backoffice/tests/db_test.php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Backoffice\{App, Content};

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fails += $ok ? 0 : 1;
}

$work = sys_get_temp_dir() . '/bo-db-' . bin2hex(random_bytes(4));
mkdir($work);
App::boot(['site_url' => 'https://x.it', 'secret' => str_repeat('a', 40), 'db' => ['dsn' => 'sqlite:' . $work . '/db.sqlite']]);
Content::saveDraft('prova', 'a', ['n' => 1], 1);
check(Content::find('prova', 'a') !== null, 'scrittura e lettura prima della riapertura');

App::reconnect();
check(Content::find('prova', 'a')['draft']['n'] === 1, 'dopo la riapertura i dati ci sono ancora (nuova connessione)');

check(App::connectionLost(new RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away')), 'errore 2006 riconosciuto');
check(App::connectionLost(new RuntimeException('Lost connection to MySQL server during query')), 'errore 2013 riconosciuto');
check(!App::connectionLost(new RuntimeException('Duplicate entry')), 'un altro errore non è scambiato per una connessione chiusa');

// Pausa lunga con la connessione ancora buona: il controllo non deve dare errori.
$r = new ReflectionProperty(App::class, 'lastUse');
$r->setValue(null, microtime(true) - 10.0);
check(Content::find('prova', 'a') !== null, 'dopo una pausa lunga il database risponde');
exit($fails > 0 ? 1 : 0);
