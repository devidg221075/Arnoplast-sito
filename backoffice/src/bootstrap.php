<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$configFile = getenv('BACKOFFICE_CONFIG') ?: __DIR__ . '/../config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Backoffice non configurato: copia config.sample.php in config.php e compila i valori.\n");
}
\Backoffice\App::boot(require $configFile);
