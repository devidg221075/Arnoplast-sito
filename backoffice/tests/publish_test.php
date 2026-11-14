<?php
// Prova di «Pubblica»: cosa è in attesa, quali file entrano nel commit, aggiornamento dello stato, errori di GitHub.
// Uso: php backoffice/tests/publish_test.php
declare(strict_types=1);
define('BACKOFFICE_TESTING', true);
require __DIR__ . '/../vendor/autoload.php';

use Backoffice\{App, Auth, Content, Fairs, Gallery, GitHub, Publisher, PublishView, Texts, Videos};

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fails += $ok ? 0 : 1;
}

$work = sys_get_temp_dir() . '/bo-pub-' . bin2hex(random_bytes(4));
mkdir($work);
App::boot(['site_url' => 'https://www.arnoplast.it', 'secret' => 'test-secret-test-secret-test-secret', 'db' => ['dsn' => 'sqlite:' . $work . '/db.sqlite']]);
Gallery::useDir($work . '/originali');
mkdir($work . '/originali');
Fairs::importSeed();
Gallery::importSeed();
Videos::importSeed();

// Il «repository» di prova: i file dati veri del sito.
$data = dirname(__DIR__, 2) . '/data/';
$repo = [];
foreach (['testi', 'fiere', 'galleria', 'tutorial'] as $n) {
    $repo['data/' . $n . '.json'] = (string) file_get_contents($data . $n . '.json');
}
$blobs = [];
$log = [];
$status = 200;
$transport = static function (string $m, string $path, ?array $body) use (&$repo, &$blobs, &$log, &$status): array {
    if ($status !== 200) {
        return [$status, ['message' => 'no']];
    }
    if (preg_match('#/contents/(.+)\?ref=#', $path, $x)) {
        $p = rawurldecode($x[1]);
        return isset($repo[$p]) ? [200, ['encoding' => 'base64', 'content' => base64_encode($repo[$p])]] : [404, ['message' => 'Not Found']];
    }
    if (str_contains($path, '/git/ref/heads/') && $m === 'GET') {
        return [200, ['object' => ['sha' => 'parent1']]];
    }
    if (str_contains($path, '/git/commits/parent1')) {
        return [200, ['tree' => ['sha' => 'tree0']]];
    }
    if ($m === 'POST' && str_ends_with($path, '/git/blobs')) {
        $sha = 'b' . count($blobs);
        $blobs[$sha] = base64_decode($body['content']);
        return [201, ['sha' => $sha]];
    }
    if ($m === 'POST' && str_ends_with($path, '/git/trees')) {
        $log['tree'] = $body['tree'];
        return [201, ['sha' => 'tree1']];
    }
    if ($m === 'POST' && str_ends_with($path, '/git/commits')) {
        $log['message'] = $body['message'];
        $log['parents'] = $body['parents'];
        return [201, ['sha' => 'commit1']];
    }
    if ($m === 'PATCH') {
        $log['patched'] = $path;
        return [200, []];
    }
    return [500, null];
};
$gh = new GitHub('proprietario/sito', 'main', 'token-di-prova', $transport);

check(Publisher::pending() === [] && Publisher::files($gh->read(...)) === [], 'dopo l\'importazione non c\'è niente da pubblicare');
try {
    Publisher::publish($gh, 1);
    check(false, 'senza modifiche pubblicare è rifiutato');
} catch (RuntimeException $ex) {
    check(str_contains($ex->getMessage(), 'Non ci sono modifiche'), 'senza modifiche pubblicare è rifiutato');
}

// --- Modifiche in tre sezioni
$g = Texts::seed()['groups'][0];
$key = $g['keys'][0];
Texts::saveGroup($g['id'], [$key => 'Testo corretto dal test'], [], 1);
$fair = Content::all(Fairs::KIND)[0];
$draft = $fair['draft'];
$draft['stand'] = 'Padiglione di prova';
Content::saveDraft(Fairs::KIND, (string) $fair['item_id'], $draft, 1);
$video = Videos::rows()[0];
$vd = $video['item'];
$vd['titolo'] = 'Titolo di prova';
Content::saveDraft(Videos::KIND, $video['key'], $vd, 1);
$p = Publisher::pending();
check(array_keys($p) === ['Testi', 'Fiere e Open Day', 'Video e tutorial'] && !isset($p['Galleria']), 'in attesa: testi, fiere e video (non la galleria)');

$files = Publisher::files($gh->read(...));
check(array_keys($files) === ['data/testi.json', 'data/fiere.json', 'data/tutorial.json'], 'file del commit: solo le sezioni cambiate');
$t = json_decode($files['data/testi.json'], true);
check($t['it'][$key] === 'Testo corretto dal test' && isset($t['_nota']), 'testi.json: correzione e nota in testa conservata');
check(str_contains($files['data/testi.json'], '"en": {}'), 'testi.json: lingua senza correzioni scritta come {} (la build vuole una mappa)');
$f = json_decode($files['data/fiere.json'], true);
$ids = array_column($f['fiere'], 'id');
check(count($f['fiere']) === count(json_decode($repo['data/fiere.json'], true)['fiere']) && in_array($fair['item_id'], $ids, true), 'fiere.json: stesse fiere');
check(array_values(array_filter($f['fiere'], static fn (array $x): bool => $x['id'] === $fair['item_id']))[0]['stand'] === 'Padiglione di prova', 'fiere.json: stand cambiato');
check(array_column(json_decode($repo['data/fiere.json'], true)['fiere'], 'id') === array_slice($ids, 0, count($ids)), 'fiere.json: ordine del file mantenuto');
$v = json_decode($files['data/tutorial.json'], true);
check(count($v['video']) === count(Videos::seed()) && in_array('Titolo di prova', array_column($v['video'], 'titolo'), true), 'tutorial.json: video con il titolo nuovo');
check(str_contains($files['data/testi.json'], "\n  \"it\": {\n    \"") && str_ends_with($files['data/testi.json'], "}\n"), 'JSON con due spazi per livello e a capo finale');

// --- Pubblicazione
[$url, $sections] = Publisher::publish($gh, 1);
check($url === 'https://github.com/proprietario/sito/commit/commit1' && $sections === ['Testi', 'Fiere e Open Day', 'Video e tutorial'], 'commit creato');
check($log['parents'] === ['parent1'] && str_contains($log['message'], 'Testi') && str_contains($log['patched'], '/git/refs/heads/main'), 'commit sul ramo giusto, con messaggio');
check(count($log['tree']) === 3 && $log['tree'][0]['path'] === 'data/testi.json', 'albero con i tre file');
check(Publisher::pending() === [] && Content::pending(Videos::KIND) === 0, 'dopo la pubblicazione non resta nulla in attesa');
check(Texts::overrides(true)['it'][$key] === 'Testo corretto dal test', 'testo ora pubblicato');

// --- Voci eliminate e foto nuove
$vkey = Videos::rows()[1]['key'];
Content::markDeleted(Videos::KIND, $vkey, 1);
copy(dirname(__DIR__) . '/tests/../public/assets/deco-top.webp', $work . '/originali/200.webp');
Content::saveDraft(Gallery::KIND, '200', ['id' => '200', 'categoria' => 'impianti', 'alt' => 'Foto nuova', 'sorgente' => '200.webp'], 1);
Content::saveDraft(Gallery::ORDER, 'order', ['ids' => array_values(array_unique(array_merge(Gallery::order(), ['200'])))], 1);
$repo['data/tutorial.json'] = $files['data/tutorial.json'];
$repo['data/testi.json'] = $files['data/testi.json'];
$repo['data/fiere.json'] = $files['data/fiere.json'];
$files = Publisher::files($gh->read(...));
check(isset($files['content/photos/galleria/200.webp']) && isset($files['data/galleria.json']), 'foto nuova: file originale e galleria.json nel commit');
$gj = json_decode($files['data/galleria.json'], true)['foto'];
check(end($gj)['sorgente'] === '200.webp' && count($gj) === 146, 'galleria.json: foto nuova in fondo, con la sorgente da convertire');
check(count(json_decode($files['data/tutorial.json'], true)['video']) === count(Videos::seed()) - 1, 'tutorial.json: video eliminato tolto');

// --- Errore di GitHub: le bozze restano
$status = 403;
try {
    Publisher::publish($gh, 1);
    check(false, 'errore di GitHub segnalato');
} catch (RuntimeException $ex) {
    check(str_contains($ex->getMessage(), 'permessi') && Publisher::pending() !== [], 'chiave senza permessi: messaggio chiaro, bozze intatte');
}
$status = 200;
Publisher::publish($gh, 1);
check(Publisher::pending() === [] && Content::find(Videos::KIND, $vkey) === null, 'riprova riuscita: video eliminato rimosso dall\'elenco');

// --- Schermata e permessi
$html = PublishView::page(['Testi' => 2], $gh, 'tok');
check(str_contains($html, 'Sì, pubblica ora') && str_contains($html, 'proprietario/sito') && str_contains($html, '<details'), 'schermata: conferma con riepilogo');
check(str_contains(PublishView::page([], $gh, 'tok'), 'Tutto il sito è aggiornato') && str_contains(PublishView::page([], null, 'tok'), 'config.php'), 'schermata: tutto aggiornato / non configurato');
check(Auth::canOpen(['role' => 'admin'], 'publish') && !Auth::canOpen(['role' => 'redattore'], 'publish') && !Auth::canOpen(['role' => 'staff'], 'publish'), 'solo l\'amministratore pubblica');
check(GitHub::fromConfig() === null, 'senza configurazione: nessun collegamento');
exit($fails > 0 ? 1 : 0);
