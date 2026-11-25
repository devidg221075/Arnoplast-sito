<?php
// Prova del confronto con gli IDML del catalogo: lettura tabelle, schede prodotto, differenze, file non validi.
// Uso: php backoffice/tests/catalog_test.php
declare(strict_types=1);
define('BACKOFFICE_TESTING', true);
require __DIR__ . '/../vendor/autoload.php';

use Backoffice\{App, Auth, CatalogDiff, CatalogPatch, Content, Idml, Publisher};

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fails += $ok ? 0 : 1;
}

$work = sys_get_temp_dir() . '/bo-cat-' . bin2hex(random_bytes(4));
mkdir($work);
App::boot(['site_url' => 'https://www.arnoplast.it', 'secret' => 'test-secret-test-secret-test-secret', 'db' => ['dsn' => 'sqlite:' . $work . '/db.sqlite']]);

function story(array $rows, array $head = ['Cod. EAN', 'Art.', 'Descrizione', 'Conf.', 'Box']): string
{
    $c = '';
    foreach ($head as $i => $h) {
        $c .= '<Cell Name="' . $i . ':0"><Content>' . $h . '</Content></Cell>';
    }
    foreach ($rows as $r => $row) {
        foreach ($row as $i => $v) {
            $c .= '<Cell Name="' . $i . ':' . ($r + 1) . '"><Content>' . $v . '</Content></Cell>';
        }
    }
    return '<Story><Table ColumnCount="' . count($head) . '" BodyRowCount="' . count($rows) . '">' . $c . '</Table></Story>';
}
function frame(string $story, int $x, int $y): string
{
    return '<TextFrame ParentStory="' . $story . '" ItemTransform="1 0 0 1 ' . $x . ' ' . $y . '"><Properties><PathGeometry><GeometryPathType><PathPointArray>'
        . '<PathPointType Anchor="0 0"/><PathPointType Anchor="100 50"/></PathPointArray></GeometryPathType></PathGeometry></Properties></TextFrame>';
}
function paras(string ...$p): string
{
    return '<Story><CharacterStyleRange><Content>' . implode('</Content><Br/><Content>', $p) . '</Content></CharacterStyleRange></Story>';
}
/** IDML finto: una pagina con titolo, caratteristiche e una tabella articoli. */
function makeIdml(string $path, string $title, array $feats, array $rows): string
{
    $z = new ZipArchive();
    $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('designmap.xml', '<Document><idPkg:Spread xmlns:idPkg="x" src="Spreads/Spread_a.xml"/></Document>');
    $z->addFromString('Spreads/Spread_a.xml', '<idPkg:Spread xmlns:idPkg="x"><Spread><Page Name="9" ItemTransform="1 0 0 1 0 0" GeometricBounds="0 0 800 600"/>'
        . frame('t1', 10, 10) . frame('f1', 10, 100) . frame('tb', 10, 200) . '</Spread></idPkg:Spread>');
    $z->addFromString('Stories/Story_t1.xml', paras($title));
    $z->addFromString('Stories/Story_f1.xml', paras(...$feats));
    $z->addFromString('Stories/Story_tb.xml', story($rows));
    $z->close();
    return $path;
}

// --- Lettura di un IDML
$it = makeIdml($work . '/it.idml', 'Testina Stilo', ['CARATTERISTICHE', 'Resistente', 'Leggera'],
    [['8025721033412', '1104-0501', 'Testina Stilo blister', '', '12'], ['', '1704-0115C', 'Clip', '250', '3000']]);
$en = makeIdml($work . '/en.idml', 'STILO HEAD', ['FEATURES', 'Strong', 'Light'],
    [['8025721033412', '1104-0501', 'STILO HEAD BLISTER', '', '12'], ['', '1704-0115C', 'CLIP', '250', '3000']]);
$a = Idml::open($it);
$rows = $a->rows();
check(count($rows) === 2 && $rows[0]['code'] === '1104-0501' && $rows[0]['ean'] === '8025721033412' && $rows[0]['box'] === 12 && $rows[0]['conf'] === null, 'tabella: codice, EAN, box, conf vuota');
check($rows[1]['code'] === '1704-0115C' && $rows[1]['ean'] === '' && $rows[1]['conf'] === 250 && $rows[1]['box'] === 3000, 'codice con suffisso C, conf e box numerici');
$fr = $a->frames();
check(count($fr) === 3 && $fr[1]['paras'] === ['CARATTERISTICHE', 'Resistente', 'Leggera'] && $fr[2]['table'] === true, 'riquadri: paragrafi separati dai Br, tabella riconosciuta');

// --- Abbinamento italiano/inglese e schede
[$pairs, $problems] = Idml::pair($rows, Idml::open($en)->rows());
check($problems === [] && $pairs[0][1]['description'] === 'STILO HEAD BLISTER', 'abbinamento riga per riga');
[$bad, $pb] = Idml::pair($rows, array_reverse(Idml::open($en)->rows()));
check($bad[0][1] === null && count($pb) >= 1, 'ordine diverso: segnalato, nessun abbinamento a caso');
[$cards, $cp] = Idml::cards($a, Idml::open($en), ['stilo' => 'Testina Stilo']);
check($cards['stilo']['title_en'] === 'STILO HEAD' && $cards['stilo']['features_en'] === ['Strong', 'Light'] && $cards['stilo']['aligned'] && $cp === [], 'scheda: titolo inglese e caratteristiche per posizione');
$en2 = makeIdml($work . '/en2.idml', 'STILO HEAD', ['FEATURES', 'Strong'], []);
[$c2, $p2] = Idml::cards($a, Idml::open($en2), ['stilo' => 'Testina Stilo']);
check($c2['stilo']['aligned'] === false && str_contains($p2[0], '2 punti in italiano, 1 in inglese') && $c2['stilo']['features_en'] === ['Strong'], 'punti diversi: le lingue restano indipendenti');
[$c3, $p3] = Idml::cards($a, $a, ['x' => 'Prodotto che non c\'è']);
check($c3 === [] && str_contains($p3[0], 'non trovato'), 'titolo mancante: problema segnalato');

// --- File non validi e sicurezza
file_put_contents($work . '/no.idml', 'non sono uno zip');
try {
    Idml::open($work . '/no.idml');
    check(false, 'file non zip rifiutato');
} catch (RuntimeException $ex) {
    check(str_contains($ex->getMessage(), 'non è un IDML'), 'file non zip rifiutato con messaggio chiaro');
}
$z = new ZipArchive();
$z->open($work . '/evil.idml', ZipArchive::CREATE);
$z->addFromString('designmap.xml', '<!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]><Document>&x;</Document>');
$z->close();
try {
    Idml::open($work . '/evil.idml')->frames();
    check(false, 'DOCTYPE/entità esterne rifiutate');
} catch (RuntimeException $ex) {
    check(str_contains($ex->getMessage(), 'non ammesse'), 'DOCTYPE/entità esterne rifiutate');
}

// --- Confronto con il catalogo del sito (seed)
$lines = CatalogDiff::lines();
check(isset($lines['garden']) && isset($lines['agricoltura']) && isset($lines['olive']), 'linee del catalogo: ' . implode(', ', array_keys($lines)));
$cat = CatalogDiff::catalog();
$gs = array_values(array_filter($cat['lines'], fn($l) => $l['slug'] === 'garden'))[0];
$p0 = $gs['families'][0]['products'][0];
$a0 = $p0['articles'][0];
$mk = static fn(string $dir, array $rows, string $title, array $feats) => makeIdml($work . '/' . $dir . '.idml', $title, $feats, $rows);
$itRows = [['8025721000001', $a0['code'], $a0['description']['it'] . ' nuovo testo', (string) ($a0['conf'] ?? ''), (string) $a0['imb']],
    ['', '9999-0001', 'ARTICOLO NUOVO', '', '5']];
$enRows = [['8025721000001', $a0['code'], 'ENGLISH TEXT', (string) ($a0['conf'] ?? ''), (string) $a0['imb']], ['', '9999-0001', 'NEW ITEM', '', '5']];
$r = CatalogDiff::compare(Idml::open($mk('c-it', $itRows, $p0['name']['it'], ['CARATTERISTICHE', 'Punto uno'])), Idml::open($mk('c-en', $enRows, 'EN TITLE', ['FEATURES', 'Point one'])), 'garden');
check(count($r['new']) === 1 && $r['new'][0]['code'] === '9999-0001' && $r['new'][0]['en'] === 'NEW ITEM', 'articolo nuovo riconosciuto, con l\'inglese');
check(count($r['changed']) === 1 && $r['changed'][0]['field'] === 'descrizione' && $r['changed'][0]['code'] === $a0['code'], 'descrizione cambiata segnalata, dati uguali no');
check($r['ean'] === 1 && $r['cards'] === 1 && count($r['gone']) > 300, 'EAN contati; gli articoli non presenti nel file risultano «tolti»');
try {
    CatalogDiff::compare(Idml::open($it), Idml::open($en), 'inesistente');
    check(false, 'linea sconosciuta rifiutata');
} catch (InvalidArgumentException) {
    check(true, 'linea sconosciuta rifiutata');
}


// --- Approvazione: dai gruppi alla bozza, e dalla bozza ai file del repository
$repo = dirname(__DIR__, 2);
$read = static fn (string $path): ?string => is_file($repo . '/' . $path) ? (string) file_get_contents($repo . '/' . $path) : null;
check(Publisher::files($read) === [] && CatalogPatch::pendingCount() === 0, 'senza bozze del catalogo, «Pubblica» non scrive nulla');

$report = CatalogDiff::compare(Idml::open($work . '/c-it.idml'), Idml::open($work . '/c-en.idml'), 'garden');
check(count($report['dati']) === 0 && count($report['desc_en']) >= 1 && count($report['new']) === 1, 'proposta: descrizioni inglesi e articolo nuovo');
$sugg = $report['new'][0]['product'];
check($sugg === '' || isset($report['product_names'][$sugg]), 'scheda proposta per il nuovo articolo: ' . ($sugg !== '' ? $sugg : 'nessuna'));
$target = $p0['slug'];
$patch = CatalogPatch::fromReport($report, ['desc_it', 'new', 'desc_en'], ['9999-0001' => $target]);
check(($patch['articles'][$a0['code']]['it'] ?? '') === $itRows[0][2] && ($patch['articles'][$a0['code']]['en'] ?? '') === 'ENGLISH TEXT'
    && $patch['new'][0]['product'] === $target && !isset($patch['products']['x']), 'patch dai gruppi scelti (con la scheda scelta per il nuovo articolo)');
check(CatalogPatch::fromReport($report, [], [])['articles'] === [], 'nessun gruppo scelto: nessuna modifica');
$bad = CatalogPatch::fromReport($report, ['new'], ['9999-0001' => 'scheda-che-non-esiste']);
check($bad['new'] === [], 'scheda di destinazione inesistente: articolo non aggiunto');

// patch idempotente e unione di bozze
[$c1, $p1, $e1] = CatalogPatch::apply(...[...CatalogPatch::base(), 'garden', $patch]);
[$c2, $p2, $e2] = CatalogPatch::apply($c1, $p1, $e1, 'garden', $patch);
check($c1 === $c2 && $p1 === $p2 && $e1 === $e2, 'applicare due volte la patch dà lo stesso risultato');
$added = null;
foreach ($c1['lines'] as $l) {
    foreach ($l['families'] as $f) {
        foreach ($f['products'] as $p) {
            foreach ($p['articles'] as $a) {
                if ($a['code'] === '9999-0001') {
                    $added = [$p['slug'], $a];
                }
            }
        }
    }
}
check($added !== null && $added[0] === $target && $added[1]['description']['it'] === 'ARTICOLO NUOVO' && $added[1]['kind'] === 'article' && $added[1]['pallet'] === null, 'articolo nuovo aggiunto alla scheda scelta');
check(($e1['articoli']['9999-0001'] ?? '') === 'NEW ITEM' && ($e1['articoli'][$a0['code']] ?? '') === 'ENGLISH TEXT', 'inglese scritto in en_articoli');
$withTrad = ['articoli' => [], 'tradotti_da_noi' => [$a0['code'] => 'OLD'], 'mancanti' => [$a0['code'], 'altro']];
[, , $e3] = CatalogPatch::apply(['lines' => []], [], $withTrad, 'garden', ['articles' => [$a0['code'] => ['en' => 'NEW']]]);
check($e3['articoli'][$a0['code']] === 'NEW' && !isset($e3['tradotti_da_noi'][$a0['code']]) && $e3['mancanti'] === ['altro'], 'il testo del cliente sostituisce quello «tradotto da noi» (che altrimenti lo coprirebbe)');
$m = CatalogPatch::merge(['articles' => ['A' => ['it' => 'x']], 'new' => [], 'products' => []], ['articles' => ['A' => ['en' => 'y'], 'B' => ['it' => 'z']], 'new' => [], 'products' => []]);
check($m['articles']['A'] === ['en' => 'y', 'it' => 'x'] && isset($m['articles']['B']), 'unione di bozze: valori nuovi sopra i vecchi, il resto resta');

// bozza → Content → Pubblica
Content::saveDraft(CatalogPatch::KIND, 'garden', $patch, 1);
check(CatalogPatch::pendingCount() === count($patch['articles']) + 1 && array_key_exists('Catalogo', Publisher::pending()), 'bozza contata tra le modifiche in attesa');
$eff = CatalogPatch::effective();
check(count(array_filter(CatalogDiff::catalog()['lines'], fn($l) => $l['slug'] === 'garden')) === 1 && $eff[2]['articoli']['9999-0001'] === 'NEW ITEM', 'il confronto parte dallo stato con le bozze');
$files = Publisher::files($read);
check(isset($files['data/catalog.json'], $files['data/en_articoli.json'], $files['backoffice/seed/catalogo.json']) && !isset($files['data/fiere.json']), 'Pubblica scrive catalogo, inglese e copie nel pacchetto, nient\'altro');
$out = json_decode($files['data/catalog.json'], true);
$orig = json_decode((string) $read('data/catalog.json'), true);
check(count($out['lines']) === count($orig['lines']) && $out['lines'][0] === $orig['lines'][0] && $out['lines'][2] === $orig['lines'][2], 'le altre linee non cambiano');
check($files['data/catalog.json'] === $files['backoffice/seed/catalogo.json'] && !isset($files['data/en_prodotti.json']), 'solo i file che cambiano; copia nel pacchetto uguale');
// formato byte per byte: una patch senza effetto non cambia nessun file
Content::saveDraft(CatalogPatch::KIND, 'garden', ['articles' => ['0000-0000' => ['it' => 'x']], 'new' => [], 'products' => []], 1);
check(Publisher::files($read) === [], 'patch senza effetto: i tre file escono identici byte per byte (formato del sito mantenuto)');
Content::publishAll(CatalogPatch::KIND);
check(CatalogPatch::pendingCount() === 0, 'dopo la pubblicazione la bozza non è più in attesa');

// --- Permessi: solo l'amministratore
check(Auth::canOpen(['role' => 'admin'], 'catalog') && !Auth::canOpen(['role' => 'redattore'], 'catalog'), 'pagina «Catalogo» solo per l\'amministratore');

echo $fails === 0 ? "\nTutti i controlli superati\n" : "\n$fails controlli falliti\n";
exit($fails === 0 ? 0 : 1);
