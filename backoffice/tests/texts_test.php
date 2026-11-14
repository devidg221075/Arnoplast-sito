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

// campi precompilati col testo attuale: salvare senza cambiare non crea modifiche
$cIt = []; $cEn = [];
foreach (Texts::group('contacts')['keys'] as $ck) { $cIt[$ck] = $seed['texts'][$ck]['it']; $cEn[$ck] = $seed['texts'][$ck]['en']; }
Texts::saveGroup('contacts', $cIt, $cEn, 1);
check(Texts::pendingByGroup()['contacts'] === 0, 'salvare senza cambiare: nessuna modifica in sospeso');

// --- Testi lunghi: storia dell'azienda e imballaggi
check(Texts::maxLen('story.s1.text') === 3000 && Texts::maxLen('pack.p1') === 3000 && Texts::maxLen('od.text') === 400, 'limiti: 3000 per storia e imballaggi, 400 per gli altri');
check(Texts::group('story') !== null && count(Texts::group('story')['keys']) === 14 && count(Texts::group('packaging')['keys']) === 48, 'gruppi «Azienda: la storia» (14 testi) e «Azienda: imballaggi» (48)');
$sk = 'story.s1.text';
$long = str_repeat('Storia aggiornata dal test. ', 60);
check(Texts::saveGroup('story', [$sk => $long], [], 1) === [] && Texts::overrides()['it'][$sk] === trim($long), 'paragrafo lungo (oltre 400 caratteri) salvato');
check(Texts::saveGroup('story', [$sk => str_repeat('x', 3001)], [], 1) !== [], 'oltre 3000 caratteri: rifiutato');
$long2 = Texts::saveGroup('packaging', ['pack.r1.codice' => 'ZZ 99'], [], 1);
check($long2 === [] && Texts::overrides()['it']['pack.r1.codice'] === 'ZZ 99', 'cella della tabella imballaggi corretta');

// --- Dati societari (una colonna, solo amministratore), collegamenti, tipologie utente, alt e descrizioni
$corp = Texts::group('corp');
check($corp !== null && ($corp['neutral'] ?? false) && ($corp['admin_only'] ?? false) && count($corp['keys']) === 11, 'gruppo «Dati societari»: 11 voci, una colonna, solo amministratore');
check(!Texts::canEdit($corp, false) && Texts::canEdit($corp, true) && Texts::canEdit(Texts::group('openday'), false), 'il redattore non modifica i dati societari');
check(Texts::saveGroup('corp', ['corp.phone1' => '+39 055 111 222'], [], 1) === [] && Texts::overrides()['it']['corp.phone1'] === '+39 055 111 222' && Texts::overrides()['en']['corp.phone1'] === '+39 055 111 222', 'telefono salvato per italiano e inglese insieme');
check(Texts::saveGroup('corp', ['corp.email' => 'non-una-email'], [], 1) !== [], 'e-mail non valida rifiutata');
check(Texts::saveGroup('corp', ['corp.email' => 'vendite@arnoplast.it'], [], 1) === [], 'e-mail valida accettata');
check(Texts::saveGroup('links', ['link.facebook' => 'http://insicuro.example'], [], 1) !== [], 'collegamento senza https rifiutato');
check(Texts::saveGroup('links', ['link.facebook' => 'https://www.facebook.com/arnoplast-nuova'], [], 1) === [], 'collegamento https accettato');
check(count(Texts::group('usertypes')['keys']) === 12 && count(Texts::group('heroalt')['keys']) === 5 && count(Texts::group('meta')['keys']) === 2, 'gruppi tipologie utente (12), alt delle testate (5), descrizioni (2)');
check(count(Texts::search('Rea', 60, false)) < count(Texts::search('Rea', 60, true)) || Texts::search('numero rea', 60, false) === [], 'la ricerca non mostra i dati societari al redattore');

echo $fails === 0 ? "\nTutti i controlli superati\n" : "\n$fails controlli falliti\n";
exit($fails === 0 ? 0 : 1);
