<?php
// Anteprima dei modelli con dati di esempio: php backoffice/emails/preview.php <cartella> [indirizzo del sito]
declare(strict_types=1);
require __DIR__ . '/templates.php';
use function Backoffice\Emails\{contactCustomer, contactStaff, quoteCustomer, quoteStaff};

$out = rtrim($argv[1] ?? sys_get_temp_dir(), '/');
$site = $argv[2] ?? 'https://www.arnoplast.it';
$c = ['name' => 'Mario', 'surname' => 'Rossi', 'email' => 'mario.rossi@example.com', 'phone' => '+39 333 1234567', 'company' => 'Azienda Agricola Rossi',
    'type' => 'Azienda Agricola', 'address' => 'Via Roma 1', 'zip' => '50100', 'city' => 'Firenze', 'country' => 'Italia',
    'message' => "Buongiorno,\nvorrei informazioni sui copripalo per un impianto antigrandine di 2 ettari.\nGrazie."];
$q = ['name' => 'Mario Rossi', 'company' => 'Azienda Agricola Rossi', 'email' => 'mario.rossi@example.com', 'phone' => '+39 333 1234567', 'country' => 'Italia',
    'notes' => 'Consegna entro fine mese, se possibile.'];
$items = [
    ['code' => '1807-1501C', 'description' => 'Copripalo Sphera con viteria (8×8-9×9)', 'qty' => 10, 'conf' => 24, 'product' => 'Copripalo Sphera'],
    ['code' => '1807-0751', 'description' => 'Mon-block (con dado e vite)', 'qty' => 4, 'conf' => 32, 'product' => 'Copripalo Sphera'],
    ['code' => '2203-0101', 'description' => 'Pinza LIGATEX', 'qty' => 1, 'product' => 'Ligatex'],
];
$all = [
    'contatto-cliente-it' => contactCustomer($c, 'C-2026-0001', 'it', $site),
    'contatto-cliente-en' => contactCustomer($c, 'C-2026-0001', 'en', $site),
    'contatto-arnoplast' => contactStaff($c, 'C-2026-0001', 'https://gestione.arnoplast.it/richieste/1', 'it', $site),
    'preventivo-cliente-it' => quoteCustomer($q, $items, 'P-2026-0001', 'it', $site),
    'preventivo-cliente-en' => quoteCustomer($q, $items, 'P-2026-0001', 'en', $site),
    'preventivo-arnoplast' => quoteStaff($q, $items, 'P-2026-0001', 'https://gestione.arnoplast.it/richieste/2', 'it', $site),
];
foreach ($all as $name => $m) {
    file_put_contents("$out/$name.html", $m['html']);
    file_put_contents("$out/$name.txt", 'Oggetto: ' . $m['subject'] . "\n\n" . $m['text']);
    echo $name, ' — ', $m['subject'], "\n";
}
