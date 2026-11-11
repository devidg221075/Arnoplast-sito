<?php
// Punto d'ingresso dei moduli del sito: riceve JSON, risponde JSON. Accetta solo i siti elencati in allowed_origins.
declare(strict_types=1);

use Backoffice\App;
use Backoffice\Submit;

require __DIR__ . '/../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Vary: Origin');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = in_array($origin, (array) App::config('allowed_origins', []), true);
if ($allowed) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 600');
}
$respond = static function (int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
};
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    $respond($allowed ? 204 : 403, []);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST, OPTIONS');
    $respond(405, ['ok' => false]);
}
// Un modulo inviato da un altro sito viene rifiutato: l'intestazione Origin la mette il browser, non la pagina.
if (!$allowed) {
    $respond(403, ['ok' => false, 'errors' => ['origin' => 'forbidden']]);
}
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
    $respond(415, ['ok' => false]);
}
$raw = (string) file_get_contents('php://input', false, null, 0, 200001);
if (strlen($raw) > 200000) {
    $respond(413, ['ok' => false]);
}
$in = json_decode($raw, true, 8);
if (!is_array($in)) {
    $respond(400, ['ok' => false]);
}
try {
    $r = Submit::handle($in);
    $respond($r['status'], $r['body']);
} catch (Throwable $e) {
    error_log('backoffice submit: ' . $e->getMessage());
    $respond(500, ['ok' => false]);
}
