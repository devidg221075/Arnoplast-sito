<?php
// Prova della ruolistica: cosa può aprire e fare ogni ruolo, pagina di partenza, pagine sconosciute.
// Uso: php backoffice/tests/roles_test.php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Backoffice\{Auth};

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fails += $ok ? 0 : 1;
}

$pages = array_keys(Auth::PAGE_PERMISSION);
$expect = [
    'admin' => $pages,
    'redattore' => ['fairs', 'fair', 'gallery', 'gallery_photo', 'gallery_img', 'videos', 'video', 'texts', 'text_group', 'listini', 'listino_file'],
    'staff' => ['dashboard', 'requests', 'request', 'quote_pdf', 'orders', 'order', 'mail', 'listini', 'listino_file'],
    'commerciale' => ['listini', 'listino_file'],
];
foreach ($expect as $role => $allowed) {
    $u = ['role' => $role];
    $got = array_values(array_filter($pages, static fn (string $p): bool => Auth::canOpen($u, $p)));
    sort($got);
    $want = $allowed;
    sort($want);
    check($got === $want, "$role: apre esattamente le sue pagine (" . count($got) . ')');
}
foreach (['admin', 'redattore', 'staff', 'commerciale'] as $role) {
    check(Auth::canOpen(['role' => $role], 'account') && Auth::canOpen(['role' => $role], 'logout'), "$role: il proprio accesso e l'uscita sempre aperti");
    check(!Auth::canOpen(['role' => $role], 'pagina_inventata'), "$role: pagina sconosciuta vietata");
}
check(Auth::home(['role' => 'admin']) === 'dashboard' && Auth::home(['role' => 'staff']) === 'dashboard', 'amministratore e ARNOplast partono dalla dashboard');
check(Auth::home(['role' => 'redattore']) === 'fairs', 'redattore parte dalle fiere');
check(Auth::home(['role' => 'commerciale']) === 'listini', 'commerciale parte dai listini');
foreach (['admin', 'redattore', 'staff', 'commerciale'] as $role) {
    check(Auth::canOpen(['role' => $role], Auth::home(['role' => $role])), "$role: la pagina di partenza è aperta");
}
check(Auth::can(['role' => 'admin'], 'listini.manage') && !Auth::can(['role' => 'redattore'], 'listini.manage') && !Auth::can(['role' => 'staff'], 'listini.manage'), 'caricare e togliere listini: solo amministratore');
check(!Auth::can(['role' => 'redattore'], 'requests') && !Auth::can(['role' => 'redattore'], 'stats'), 'redattore: nessuna richiesta e nessuna statistica');
check(count(Auth::ROLES) === 4 && isset(Auth::ROLES['redattore']), 'quattro ruoli, incluso il redattore');

echo $fails === 0 ? "\nTutti i controlli superati\n" : "\n$fails controlli falliti\n";
exit($fails === 0 ? 0 : 1);
