<?php
// Controlla la casella delle richieste e aggiunge le risposte dei clienti alle conversazioni.
// Da eseguire con una pianificazione (cron) ogni 5 minuti: vedi README.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../src/bootstrap.php';

$r = \Backoffice\Inbox::run();
echo date('Y-m-d H:i:s'), ' ', $r['error'] !== '' ? 'ERRORE: ' . $r['error'] : sprintf('controllati %d, aggiunti %d, ignorati %d', $r['checked'], $r['imported'], $r['skipped']), "\n";
exit($r['error'] === '' ? 0 : 1);
