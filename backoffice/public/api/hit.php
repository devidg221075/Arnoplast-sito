<?php
// Segnalazione di una pagina vista dal sito (senza cookie). Risponde sempre 204: non dice se è stata registrata.
declare(strict_types=1);

use Backoffice\App;
use Backoffice\Stats;

require __DIR__ . '/../../src/bootstrap.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, (array) App::config('allowed_origins', []), true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
// Limiti anti-abuso: 60 segnalazioni al minuto per visitatore e 600 in tutto (le eccedenze si scartano in silenzio).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::allow('hit:' . App::ipHash(), 60, 60) && App::allow('hit:all', 600, 60)) {
    $in = ['p' => substr((string) ($_POST['p'] ?? ''), 0, 300), 'r' => substr((string) ($_POST['r'] ?? ''), 0, 500)];
    Stats::record($in, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400), (string) ($_SERVER['HTTP_DNT'] ?? ''));
}
http_response_code(204);
