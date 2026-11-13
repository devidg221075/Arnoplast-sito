<?php
// Prova dei testi fissi: bozze, ripristino, controlli, ricerca.
// Uso: php backoffice/tests/texts_test.php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Backoffice\{App, Content, Texts};

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fails += $ok ? 0 : 1;
}

$work = sys_get_temp_dir() . '/bo-texts-' . bin2hex(random_bytes(4));
mkdir($work);
App::boot(['site_url' => 'https://www.arnoplast.it', 'secret' => 'test-secret-test-secret-test-secret', 'db' => ['dsn' => 'sqlite:' . $work . '/db.sqlite']]);
$seed = Texts::seed();
check(count($seed['texts']) > 300 && count($seed['groups']) >= 10, count($seed['texts']) . ' testi in ' . count($seed['groups']) . ' gruppi');
$all = array_merge(...array_column($seed['groups'], 'keys'));
check(count($all) === count(array_unique($all)) && count($all) === count($seed['texts']), 'ogni testo è in un solo gruppo');
check(!array_filter($all, static fn (string $k): bool => str_starts_with($k, 'ck.')), 'nessun testo legale modificabile');

$od = Texts::group('openday');
$k = $od['keys'][0];
$orig = $seed['texts'][$k];
check($k === 'od.text', 'gruppo Open Day: ' . $k);
$err = Texts::saveGroup('openday', [$k => 'Visite da marzo'], [$k => ''], 1);
$row = Content::find(Texts::KIND, $k);
check($err === [] && $row['draft'] === ['it' => 'Visite da marzo'] && Content::status($row) === Content::NEW, 'correzione salvata solo per l\'italiano');
check(Texts::pendingByGroup()['openday'] === 1, 'conteggio in bozza');
Texts::saveGroup('openday', [$k => "Visite   da\nmarzo"], [$k => 'Visits from March'], 1);
check(Content::find(Texts::KIND, $k)['draft'] === ['it' => 'Visite da marzo', 'en' => 'Visits from March'], 'spazi e a capo ridotti a una riga; inglese salvato');
Texts::saveGroup('openday', [$k => $orig['it']], [$k => ''], 1);
check(Content::find(Texts::KIND, $k) === null, 'testo uguale all\'originale / campo vuoto: correzione tolta');
$errs = Texts::saveGroup('openday', [$k => str_repeat('x', 401)], [], 1);
check(isset($errs[$k]) && Content::find(Texts::KIND, $k) === null, 'testo troppo lungo rifiutato');
check(isset(Texts::saveGroup('inesistente', [], [], 1)['']), 'gruppo inesistente');
$found = Texts::search('coming soon');
check($found !== [] && in_array($k, array_column($found, 'key'), true), 'ricerca trova il testo');
Texts::saveGroup('openday', [$k => 'Aperto il sabato'], [], 1);
check(in_array('Aperto il sabato', array_column(Texts::search('sabato'), 'it'), true), 'la ricerca guarda anche le correzioni');
// pubblicato: ripristinare lascia una bozza vuota (tornerà all'originale alla pubblicazione)
App::db()->prepare('UPDATE content SET published = draft WHERE kind = ? AND item_id = ?')->execute([Texts::KIND, $k]);
Texts::saveGroup('openday', [], [], 1);
$row = Content::find(Texts::KIND, $k);
check($row !== null && $row['draft'] === [] && Content::status($row) === Content::CHANGED, 'testo già pubblicato ripristinato: modifica in sospeso');

// annulla modifiche di una pagina: tutte le correzioni sparite
Texts::saveGroup('openday', [$k => 'Aperto il sabato'], ['od.text' => 'Open on Saturday'], 1);
Texts::saveGroup('openday', [], [], 1);
check((Content::find(Texts::KIND, $k)['draft'] ?? []) === [], 'annulla modifiche: bozza della pagina svuotata');

echo $fails === 0 ? "\nTutti i controlli superati\n" : "\n$fails controlli falliti\n";
exit($fails === 0 ? 0 : 1);
