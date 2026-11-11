<?php
// Prova delle fiere: controlli identici alla build, bozze, pubblicato, date, foto.
// Uso: php backoffice/tests/fairs_test.php
declare(strict_types=1);
define('BACKOFFICE_TESTING', true);
require __DIR__ . '/../vendor/autoload.php';

use Backoffice\{App, Content, Fairs};

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fails += $ok ? 0 : 1;
}

$work = sys_get_temp_dir() . '/bo-fairs-' . bin2hex(random_bytes(4));
mkdir($work);
App::boot(['site_url' => 'https://www.arnoplast.it', 'secret' => 'test-secret-test-secret-test-secret', 'db' => ['dsn' => 'sqlite:' . $work . '/db.sqlite']]);

// --- Dati del sito: il controllo del backoffice accetta tutte le fiere che la build accetta
$seed = json_decode((string) file_get_contents(__DIR__ . '/../seed/fiere.json'), true)['fiere'];
$bad = array_filter($seed, static fn (array $f): bool => Fairs::validate($f) !== []);
check(count($seed) >= 30 && !$bad, count($seed) . ' fiere del sito tutte valide');
check(Fairs::importSeed() === count($seed) && Fairs::importSeed() === 0, 'importazione: una volta sola');
$rows = Content::all(Fairs::KIND);
check(count(array_filter($rows, static fn (array $r): bool => Content::status($r) === Content::LIVE)) === count($seed), 'tutte "pubblicate" dopo l\'importazione');
foreach ($seed as $f) {
    if (($f['data_inizio'] ?? '') !== '' && Fairs::dates($f) === '') { check(false, 'date vuote per ' . $f['id']); }
}
$interpoma = array_values(array_filter($seed, static fn (array $f): bool => $f['id'] === 'interpoma-2026'))[0];
check(Fairs::dates($interpoma) === '25–27 novembre 2026' && Fairs::dates($interpoma, 'en') === '25–27 November 2026', 'date: ' . Fairs::dates($interpoma));
check(Fairs::dates(['data_inizio' => '2026-11-30', 'data_fine' => '2026-12-03']) === '30 novembre – 3 dicembre 2026', 'date a cavallo di mese');
check(Fairs::dates(['data_inizio' => '2026-05-04', 'data_fine' => '2026-05-04']) === '4 maggio 2026', 'un solo giorno');
check(Fairs::dates(['data_inizio' => '', 'data_fine' => '', 'periodo' => 'Febbraio 2021', 'anno' => 2021]) === 'Febbraio 2021', 'senza date: periodo');
check(Fairs::isPast(['data_fine' => '2026-10-01', 'data_inizio' => '2026-09-28', 'anno' => 2026], '2026-10-02') && !Fairs::isPast(['data_fine' => '2026-10-02', 'data_inizio' => '', 'anno' => 2026], '2026-10-02'), 'passata dal giorno dopo la fine');

// --- Modulo
$form = ['nome' => 'EIMA Bologna', 'luogo' => 'Bologna', 'paese' => 'Italia', 'data_inizio' => '2027-11-10', 'data_fine' => '2027-11-14', 'periodo' => '', 'anno' => '', 'stand' => 'Pad. 12',
    'testo' => "Prima riga.\nSeconda.", 'link' => 'https://www.eima.it', 'testo_en' => 'English text', 'luogo_en' => '', 'paese_en' => 'Italy'];
[$f, $err] = Fairs::fromForm($form, null);
check($err === [] && $f['id'] === 'eima-bologna-2027' && $f['anno'] === 2027 && !isset($f['luogo_en']) && $f['fonte_url_sito_vecchio'] === '' && Fairs::validate($f) === [], 'nuova fiera valida: id ' . $f['id']);
check(array_key_exists('periodo', $f) && array_key_exists('stand', $f) && array_key_exists('testo', $f), 'campi obbligatori sempre presenti, anche vuoti');
[, $e1] = Fairs::fromForm(['nome' => '', 'data_inizio' => '2027-13-01', 'link' => 'http://x.it', 'punto_focale' => 'sopra'], null);
check(count($e1) >= 5, 'errori: nome, luogo, paese, data, link, punto focale (' . count($e1) . ')');
[, $e2] = Fairs::fromForm($form + [], null) + [1 => []];
[, $e3] = Fairs::fromForm(array_replace($form, ['data_fine' => '2027-11-09']), null);
check(in_array('La data di fine è precedente a quella di inizio.', $e3, true), 'fine prima dell\'inizio rifiutata');
[$f2] = Fairs::fromForm(array_replace($form, ['data_inizio' => '', 'data_fine' => '', 'anno' => '2027', 'periodo' => 'Autunno 2027']), null);
check($f2['data_inizio'] === '' && $f2['anno'] === 2027 && Fairs::validate($f2) === [], 'senza date, con anno e periodo');
[, $e4] = Fairs::fromForm(array_replace($form, ['data_inizio' => '', 'data_fine' => '', 'anno' => '']), null);
check(count($e4) >= 1, 'senza date né anno rifiutata');
[$f3] = Fairs::fromForm(array_replace($form, ['nome' => 'Cambio nome']), ['id' => 'eima-bologna-2027', 'immagine' => 'foto.webp', 'fonte_url_sito_vecchio' => 'https://old']);
check($f3['id'] === 'eima-bologna-2027' && $f3['immagine'] === 'foto.webp' && $f3['fonte_url_sito_vecchio'] === 'https://old', 'in modifica l\'identificativo, la foto e la fonte restano');
[$f4] = Fairs::fromForm(array_replace($form, ['remove_image' => '1']), ['id' => 'x', 'immagine' => 'foto.webp']);
check(!isset($f4['immagine']), 'foto tolta');
[$f5, $e5] = Fairs::fromForm(array_replace($form, ['nome' => '<b>x</b>']), null);
check($e5 === [] && $f5['nome'] === '<b>x</b>' && $f5['id'] === 'b-x-b-2027', 'testo con codice: tenuto com\'è (si mostra sempre in forma sicura), id ripulito');

// --- Bozza e pubblicazione
check(Content::saveDraft(Fairs::KIND, $f['id'], $f, 1, ['translate' => true]) === true, 'nuova fiera: bozza creata');
$row = Content::find(Fairs::KIND, $f['id']);
check(Content::status($row) === Content::NEW && $row['meta']['translate'] === true, 'stato "nuova", traduzione richiesta');
$live = Content::find(Fairs::KIND, 'interpoma-2026');
Content::saveDraft(Fairs::KIND, 'interpoma-2026', array_replace($live['draft'], ['stand' => 'Hall B']), 1);
$live2 = Content::find(Fairs::KIND, 'interpoma-2026');
check(Content::status($live2) === Content::CHANGED && $live2['published']['stand'] === $live['published']['stand'], 'modifica: la versione pubblicata non cambia');
Content::revert(Fairs::KIND, 'interpoma-2026', 1);
check(Content::status(Content::find(Fairs::KIND, 'interpoma-2026')) === Content::LIVE, 'annulla le modifiche');
Content::markDeleted(Fairs::KIND, 'interpoma-2026', 1);
check(Content::status(Content::find(Fairs::KIND, 'interpoma-2026')) === Content::REMOVED, 'fiera pubblicata: "da eliminare"');
Content::saveDraft(Fairs::KIND, 'interpoma-2026', $live['draft'], 1);
check(Content::status(Content::find(Fairs::KIND, 'interpoma-2026')) === Content::LIVE, 'salvando di nuovo l\'eliminazione si annulla');
Content::markDeleted(Fairs::KIND, $f['id'], 1);
check(Content::find(Fairs::KIND, $f['id']) === null, 'fiera mai pubblicata: eliminata subito');
check(Content::pending(Fairs::KIND) === 0, 'nessuna modifica in sospeso');

// --- Foto
$img = $work . '/p.png';
imagepng(imagecreatetruecolor(800, 500), $img);
[$name, $imgErr] = Fairs::storeImage(['error' => UPLOAD_ERR_OK, 'tmp_name' => $img, 'size' => filesize($img)], 'prova-foto');
check($name === 'prova-foto.png' && $imgErr === null && is_file(dirname(__DIR__) . '/var/content/fiere/prova-foto.png'), 'foto PNG salvata');
@unlink(dirname(__DIR__) . '/var/content/fiere/prova-foto.png');
file_put_contents($work . '/f.png', '<?php echo 1;');
check(Fairs::storeImage(['error' => UPLOAD_ERR_OK, 'tmp_name' => $work . '/f.png', 'size' => 13], 'x')[1] !== null, 'file che non è un\'immagine rifiutato (anche se si chiama .png)');
imagepng(imagecreatetruecolor(100, 100), $work . '/s.png');
check(Fairs::storeImage(['error' => UPLOAD_ERR_OK, 'tmp_name' => $work . '/s.png', 'size' => 500], 'x')[1] !== null, 'foto troppo piccola rifiutata');
check(Fairs::storeImage(['error' => UPLOAD_ERR_OK, 'tmp_name' => $img, 'size' => 7_000_000], 'x')[1] !== null, 'foto troppo grande rifiutata');
check(Fairs::storeImage(null, 'x') === [null, null] && Fairs::storeImage(['error' => UPLOAD_ERR_NO_FILE], 'x') === [null, null], 'nessuna foto: nessun errore');

echo $fails === 0 ? "\nTutti i controlli superati\n" : "\n$fails controlli falliti\n";
exit($fails === 0 ? 0 : 1);
