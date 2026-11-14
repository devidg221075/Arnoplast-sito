<?php
// Prova delle note di versione mostrate nel backoffice: titoli, elenchi, grassetto, testo escapato, file reale.
// Uso: php backoffice/tests/changelog_test.php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Backoffice\{App, Auth, Changelog};

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fails += $ok ? 0 : 1;
}

$h = Changelog::html("# Titolo\n\n## 1.0 — oggi\n\n### Novità\n- **Grassetto** e `codice`\n- <script>alert(1)</script>\n\nTesto libero.\n");
check(str_contains($h, '<h2>1.0 — oggi</h2>') && str_contains($h, '<h3>Novità</h3>') && !str_contains($h, '<h1'), 'titoli di versione e sezione');
check(str_contains($h, '<li><strong>Grassetto</strong> e <code>codice</code></li>'), 'elenco con grassetto e codice');
check(!str_contains($h, '<script') && str_contains($h, '&lt;script&gt;'), 'il testo è escapato');
check(substr_count($h, '<ul>') === 1 && substr_count($h, '</ul>') === 1, 'un solo elenco, chiuso');
check(str_contains(Changelog::html(''), 'Nessuna nota'), 'file vuoto: messaggio chiaro');

$real = Changelog::html();
check(str_contains($real, 'v') || str_contains($real, '<h2>'), 'CHANGELOG.md reale letto');
check(str_contains($real, App::version()), 'la versione corrente compare nelle note');
foreach (array_keys(Auth::ROLES) as $role) {
    check(Auth::canOpen(['role' => $role], 'changelog'), "pagina Novità aperta al ruolo $role");
}
exit($fails > 0 ? 1 : 0);
