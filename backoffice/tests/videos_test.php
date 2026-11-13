<?php
// Prova di Video e Tutorial: link riconosciuti, modulo, importazione, eliminazione e annullamento.
// Uso: php backoffice/tests/videos_test.php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Backoffice\{App, Content, Videos};

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fails += $ok ? 0 : 1;
}

$work = sys_get_temp_dir() . '/bo-videos-' . bin2hex(random_bytes(4));
mkdir($work);
App::boot(['site_url' => 'https://www.arnoplast.it', 'secret' => 'test-secret-test-secret-test-secret', 'db' => ['dsn' => 'sqlite:' . $work . '/db.sqlite']]);

check(count(Videos::seed()) === 37, '37 voci del sito nel seed');
check(Videos::parseUrl('https://youtu.be/6t_hUfHWVwQ') === ['servizio' => 'youtube', 'id' => '6t_hUfHWVwQ'], 'link youtu.be');
check(Videos::parseUrl('https://www.youtube.com/watch?v=rKYen5uid5w&t=10') === ['servizio' => 'youtube', 'id' => 'rKYen5uid5w'], 'link youtube.com/watch con parametri');
check(Videos::parseUrl('https://vimeo.com/684684219') === ['servizio' => 'vimeo', 'id' => '684684219'], 'link Vimeo');
check(Videos::parseUrl('https://example.com/video') === null && Videos::parseUrl('youtube.com/watch?v=corta') === null, 'link non riconosciuti rifiutati');

[$item, $errors] = Videos::fromForm(['url' => 'https://youtu.be/wGBNoeV_srQ', 'gruppo' => 'olive', 'titolo' => 'Martino', 'durata' => '1:05'], null);
check($errors === [] && $item['servizio'] === 'youtube' && $item['durata'] === '1:05', 'modulo valido: nuovo video');
[, $errors] = Videos::fromForm(['url' => 'nessun link', 'gruppo' => 'nope', 'titolo' => '', 'durata' => '65'], null);
check(count($errors) === 4, 'modulo con quattro errori segnalati: ' . count($errors));
[, $errors] = Videos::fromForm(['gruppo' => 'garden', 'titolo' => 'Nuovo titolo', 'durata' => '0:30'], ['servizio' => 'vimeo', 'id' => '268614956', 'prodotto' => 'olive/x/y']);
check($errors === [], 'voce esistente: si modifica senza link');

$n = Videos::importSeed();
check($n === 37 && Videos::importSeed() === 0, 'importazione del seed una sola volta');
$rows = Videos::rows();
check(count($rows) === 37 && $rows[0]['status'] === Content::LIVE, 'elenco: 37 voci, stato «sul sito»');

$key = Videos::key('vimeo', '268614956');
Content::saveDraft(Videos::KIND, $key, ['gruppo' => 'garden', 'servizio' => 'vimeo', 'id' => '268614956', 'titolo' => 'Oleo Carbon (prova)'], 1);
$r = Content::find(Videos::KIND, $key);
check(Content::status($r) === Content::NEW || Content::status($r) === Content::CHANGED, 'modifica: stato modificato');
Content::revert(Videos::KIND, $key, 1);
check(Content::status(Content::find(Videos::KIND, $key)) === Content::LIVE, 'annullamento: torna sul sito');

Content::markDeleted(Videos::KIND, $key, 1);
check(Content::status(Content::find(Videos::KIND, $key)) === Content::REMOVED, 'eliminazione: segnata da eliminare');
Content::revert(Videos::KIND, $key, 1);
check(Content::status(Content::find(Videos::KIND, $key)) === Content::LIVE, 'annullamento dell\'eliminazione');

$newKey = Videos::key('youtube', 'wGBNoeV_srQ');
check(Content::find(Videos::KIND, $newKey) !== null, 'video già in elenco non si duplica');
Content::saveDraft(Videos::KIND, 'youtube:AAAAAAAAAAA', ['gruppo' => 'video', 'servizio' => 'youtube', 'id' => 'AAAAAAAAAAA', 'titolo' => 'Nuovo'], 1);
check(Content::status(Content::find(Videos::KIND, 'youtube:AAAAAAAAAAA')) === Content::NEW, 'nuovo video: stato nuovo');
Content::markDeleted(Videos::KIND, 'youtube:AAAAAAAAAAA', 1);
check(Content::find(Videos::KIND, 'youtube:AAAAAAAAAAA') === null, 'nuovo video eliminato: sparisce dall\'elenco');

echo $fails === 0 ? "\nTutti i controlli superati\n" : "\n$fails controlli falliti\n";
exit($fails === 0 ? 0 : 1);
