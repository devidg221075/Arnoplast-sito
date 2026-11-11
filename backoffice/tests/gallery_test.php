<?php
// Prova della galleria: importazione, modifica, ordine, foto nuove, eliminazione, esportazione.
// Uso: php backoffice/tests/gallery_test.php
declare(strict_types=1);
define('BACKOFFICE_TESTING', true);
require __DIR__ . '/../vendor/autoload.php';

use Backoffice\{App, Content, Gallery};

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fails += $ok ? 0 : 1;
}

$work = sys_get_temp_dir() . '/bo-gal-' . bin2hex(random_bytes(4));
mkdir($work);
App::boot(['site_url' => 'https://www.arnoplast.it', 'secret' => 'test-secret-test-secret-test-secret', 'db' => ['dsn' => 'sqlite:' . $work . '/db.sqlite']]);
$seed = Gallery::seedItems();
check(count($seed) === 145, count($seed) . ' foto nel file del sito');
check(Gallery::importSeed() === 145 && Gallery::importSeed() === 0, 'importazione una volta sola');
check(Gallery::pending() === 0 && Gallery::export(true)['foto'] == $seed && Gallery::export()['foto'] == $seed, 'dopo l\'importazione bozza = pubblicato = file del sito');
check(Gallery::order()[0] === '001' && Gallery::position('010') === 10, 'ordine del sito mantenuto');

// --- Modifica
check(Gallery::save('003', 'serre', 'Tunnel con reti antigrandine', 'Tunnel with hail nets', null, 1) === [], 'foto modificata');
$d = Content::find(Gallery::KIND, '003')['draft'];
check($d['categoria'] === 'serre' && $d['alt'] === 'Tunnel con reti antigrandine' && $d['alt_en'] === 'Tunnel with hail nets' && $d['w'] === 960, 'categoria e testi cambiati, misure intatte');
check(Gallery::save('003', 'serre', 'Solo italiano', '', null, 1) === [] && !isset(Content::find(Gallery::KIND, '003')['draft']['alt_en']), 'inglese vuoto: si toglie');
check(Gallery::save('003', 'sbagliata', 'x', '', null, 1) !== [] && Gallery::save('003', 'serre', '   ', '', null, 1) !== [], 'categoria o testo mancanti rifiutati');
check(Gallery::save('999', 'serre', 'x', '', null, 1) === ['Foto non trovata.'], 'foto inesistente');
Content::revert(Gallery::KIND, '003', 1);
check(Gallery::pending() === 0, 'annullamento');

// --- Ordine
Gallery::move('005', 1, 1);
check(Gallery::order()[0] === '005' && Gallery::order()[1] === '001' && count(Gallery::order()) === 145 && Gallery::orderChanged(), 'foto 005 spostata in prima posizione');
Gallery::move('005', 999, 1);
check(Gallery::order()[144] === '005', 'posizione oltre la fine: in fondo');
check(Gallery::export()['foto'][144]['id'] === '005' && Gallery::export(true)['foto'][0]['id'] === '001', 'esportazione: bozza riordinata, pubblicato com\'era');
Gallery::move('005', 5, 1);
check(Gallery::order()[4] === '005', 'foto 005 alla posizione 5');
Content::revert(Gallery::ORDER, 'order', 1);
check(!Gallery::orderChanged() && Gallery::order()[0] === '001' && Gallery::order()[144] === '145', 'ordine ripristinato');

// --- Foto nuove
$mk = static function (string $path, int $w, int $h, string $type = 'png') {
    $im = imagecreatetruecolor($w, $h);
    $type === 'jpg' ? imagejpeg($im, $path) : imagepng($im, $path);
};
$mk($work . '/a.png', 1200, 800);
$mk($work . '/b.jpg', 900, 700, 'jpg');
$mk($work . '/small.png', 300, 200);
file_put_contents($work . '/fake.jpg', '<?php echo 1;');
$files = ['name' => ['a.png', 'b.jpg', 'small.png', 'fake.jpg', ''], 'tmp_name' => [$work . '/a.png', $work . '/b.jpg', $work . '/small.png', $work . '/fake.jpg', ''],
    'size' => [filesize($work . '/a.png'), filesize($work . '/b.jpg'), filesize($work . '/small.png'), 13, 0], 'error' => [0, 0, 0, 0, UPLOAD_ERR_NO_FILE]];
[$added, $errors] = Gallery::addUploads($files, 'dettagli', 1);
check($added === ['146', '147'] && count($errors) === 2, 'due foto aggiunte, due rifiutate (piccola, falsa): ' . implode(' | ', $errors));
check(is_file(Gallery::dir() . '/146.png') && is_file(Gallery::dir() . '/147.jpg') && Gallery::original('146') !== null && Gallery::original('../x') === null, 'originali conservati');
$n = Content::find(Gallery::KIND, '146');
check($n['draft']['sorgente'] === '146.png' && $n['draft']['categoria'] === 'dettagli' && Content::status($n) === Content::NEW, 'foto nuova: bozza con sorgente');
check(array_slice(Gallery::order(), -2) === ['146', '147'], 'in fondo alla galleria');
check(Gallery::export()['foto'][145]['sorgente'] === '146.png' && count(Gallery::export()['foto']) === 147 && count(Gallery::export(true)['foto']) === 145, 'esportazione con le foto nuove, pubblicato invariato');
check(Gallery::addUploads(['name' => [], 'tmp_name' => [], 'size' => [], 'error' => []], 'dettagli', 1)[1] !== [] && Gallery::addUploads($files, 'xx', 1)[1] !== [], 'nessun file / categoria sbagliata');
// --- Eliminazione
Gallery::delete('146', 1);
check(Content::find(Gallery::KIND, '146') === null && Gallery::original('146') === null && !in_array('146', Gallery::order(), true), 'foto nuova eliminata subito, con il file');
Gallery::delete('010', 1);
check(Content::status(Content::find(Gallery::KIND, '010')) === Content::REMOVED && count(Gallery::export()['foto']) === 145 - 1 + 1, 'foto pubblicata: "da eliminare", esce dalla bozza');
check(in_array('010', array_column(Gallery::export(true)['foto'], 'id'), true), 'nel pubblicato c\'è ancora');
@unlink(Gallery::dir() . '/147.jpg');

echo $fails === 0 ? "\nTutti i controlli superati\n" : "\n$fails controlli falliti\n";
exit($fails === 0 ? 0 : 1);
