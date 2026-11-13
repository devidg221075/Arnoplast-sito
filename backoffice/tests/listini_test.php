<?php
// Prova dei listini riservati: chi può aprire cosa, caricamento (solo PDF), download e rimozione.
// Uso: php backoffice/tests/listini_test.php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Backoffice\{App, Auth, Listini};

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fails += $ok ? 0 : 1;
}

check(Auth::canOpen(['role' => 'commerciale'], 'listini') && Auth::canOpen(['role' => 'commerciale'], 'listino_file'), 'commerciale: apre i listini e il download');
check(Auth::canOpen(['role' => 'commerciale'], 'account') && Auth::canOpen(['role' => 'commerciale'], 'logout'), 'commerciale: apre il proprio accesso e l\'uscita');
check(!Auth::canOpen(['role' => 'commerciale'], 'requests') && !Auth::canOpen(['role' => 'commerciale'], 'users') && !Auth::canOpen(['role' => 'commerciale'], 'texts'), 'commerciale: nessun\'altra pagina');
check(Auth::canOpen(['role' => 'staff'], 'requests') && Auth::canOpen(['role' => 'admin'], 'users'), 'staff e amministratore: invariati');

$work = sys_get_temp_dir() . '/bo-listini-' . bin2hex(random_bytes(4));
mkdir($work);
App::boot(['site_url' => 'https://www.arnoplast.it', 'secret' => 'test-secret-test-secret-test-secret', 'db' => ['dsn' => 'sqlite:' . $work . '/db.sqlite']]);
Listini::useDir($work . '/listini');

$pdf = $work . '/prova.pdf';
file_put_contents($pdf, "%PDF-1.4\n% prova\n");
$txt = $work . '/finto.pdf';
file_put_contents($txt, "questo non è un PDF");

[$id, $err] = Listini::save($pdf, (int) filesize($pdf), 'Listino Agricoltura 2018.pdf', 'Listino Agricoltura 2018', 'it', 1);
check($err === null && $id !== null, 'PDF valido caricato');
check(is_file(Listini::path((string) $id) ?? ''), 'il PDF è salvato nella cartella dei listini (non in quella pubblica)');
check(count(Listini::current()) === 1 && Listini::current()[0]['item']['titolo'] === 'Listino Agricoltura 2018', 'elenco: il listino è in vigore');
check(strpos((string) Listini::path((string) $id), $work) === 0, 'percorso dentro la cartella dei listini');

[, $err] = Listini::save($txt, (int) filesize($txt), 'finto.pdf', 'Finto', 'it', 1);
check($err === 'Il file non è un PDF.', 'un file che non è PDF è rifiutato');
[, $err] = Listini::save($pdf, Listini::MAX_BYTES + 1, 'grande.pdf', 'Grande', 'it', 1);
check($err !== null, 'file oltre 20 MB rifiutato');
[, $err] = Listini::save($pdf, (int) filesize($pdf), 'x.pdf', '   ', 'it', 1);
check($err !== null, 'titolo vuoto rifiutato');
[, $err] = Listini::save($pdf, (int) filesize($pdf), 'x.pdf', 'Titolo', 'fr', 1);
check($err !== null, 'lingua non ammessa rifiutata');

Listini::remove((string) $id, 1);
check(Listini::path((string) $id) === null && Listini::current() === [], 'rimosso: non più disponibile e PDF cancellato');

echo $fails === 0 ? "\nTutti i controlli superati\n" : "\n$fails controlli falliti\n";
exit($fails === 0 ? 0 : 1);
