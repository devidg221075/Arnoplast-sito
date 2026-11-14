<?php
// Backoffice: accesso, richieste e conversazioni, utenti, registro delle attività.
declare(strict_types=1);

use Backoffice\App;
use Backoffice\Auth;
use Backoffice\Conversation;
use Backoffice\Inbox;
use Backoffice\Content;
use Backoffice\Fairs;
use Backoffice\FairsView;
use Backoffice\Gallery;
use Backoffice\GalleryView;
use Backoffice\Mime;
use Backoffice\Texts;
use Backoffice\TextsView;
use Backoffice\Quote;
use Backoffice\QuoteView;
use Backoffice\Listini;
use Backoffice\Orders;
use Backoffice\Stats;
use Backoffice\StatsView;
use Backoffice\ListiniView;
use Backoffice\Videos;
use Backoffice\VideosView;

require __DIR__ . '/../src/bootstrap.php';

const STATUSES = ['nuova' => 'Nuova', 'in_lavorazione' => 'In lavorazione', 'preventivo_inviato' => 'Preventivo inviato', 'chiusa' => 'Chiusa'];
const KINDS = ['contact' => 'Contatto', 'quote' => 'Preventivo'];

header('Content-Type: text/html; charset=utf-8');
$assets = (string) App::config('assets_url', '');
$assetsOrigin = preg_match('~^(https://[A-Za-z0-9.-]+|http://(localhost|127\.0\.0\.1))(:\d+)?$~', rtrim($assets, '/')) ? ' ' . rtrim($assets, '/') : '';
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'$assetsOrigin; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

Auth::start();
$e = App::e(...);
$page = is_string($_GET['p'] ?? null) ? $_GET['p'] : 'requests';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$url = static fn (string $p, array $q = []): string => 'index.php?' . http_build_query(['p' => $p] + $q);
$go = static function (string $to): never {
    header('Location: ' . $to, true, 303);
    exit;
};
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$say = static function (string $text, bool $ok = true): void {
    $_SESSION['flash'] = [$text, $ok];
};

/** Impaginazione comune. */
$render = static function (string $title, string $body, ?array $user = null) use ($e, $url, $flash): never {
    $nav = '';
    if ($user) {
        $all = ['requests' => 'Richieste', 'orders' => 'Ordini', 'fairs' => 'Fiere', 'gallery' => 'Galleria', 'videos' => 'Video', 'listini' => 'Listini', 'stats' => 'Statistiche',
            'texts' => 'Testi', 'mail' => 'Posta', 'conditions' => 'Condizioni PDF', 'users' => 'Utenti', 'activity' => 'Registro attività'];
        $links = array_filter($all, static fn (string $p): bool => Auth::canOpen($user, $p), ARRAY_FILTER_USE_KEY) + ['account' => 'Il mio accesso'];
        foreach ($links as $p => $label) {
            $nav .= '<a href="' . $e($url($p)) . '">' . $e($label) . '</a>';
        }
        $nav = '<nav class="nav">' . $nav . '<form method="post" action="' . $e($url('logout')) . '"><input type="hidden" name="csrf" value="' . $e(Auth::csrf()) . '">'
            . '<button class="link">Esci (' . $e($user['name']) . ')</button></form></nav>';
    }
    echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . $e($title) . ' · Backoffice ' . $e(App::config('site_name', '')) . '</title>'
        . '<link rel="stylesheet" href="assets/admin.css"></head><body>'
        . '<header class="top"><a class="brand" href="' . $e($url('requests')) . '">' . $e(App::config('site_name', '')) . ' <span>backoffice' . (App::version() !== '' ? ' v' . $e(App::version()) : '') . '</span></a>' . $nav . '</header>'
        . '<main class="wrap">' . ($flash ? '<p class="flash' . ($flash[1] ? ' ok' : '') . '" role="status">' . $e($flash[0]) . '</p>' : '')
        . '<h1>' . $e($title) . '</h1>' . $body . '</main></body></html>';
    exit;
};
$csrfField = static fn (): string => '<input type="hidden" name="csrf" value="' . $e(Auth::csrf()) . '">';
$date = static fn (?string $d): string => $d ? date('d/m/Y H:i', strtotime($d)) : '';

if ($isPost && empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    http_response_code(413);
    $render('Caricamento troppo grande', '<p>Il server ha rifiutato l\'invio perché i file superano il limite consentito (' . $e((string) ini_get('post_max_size')) . '). Carica meno foto per volta, oppure più piccole. <a href="' . $e($url('gallery')) . '">Torna alla galleria</a></p>');
}
if ($isPost && !Auth::checkCsrf()) {
    http_response_code(400);
    $render('Sessione scaduta', '<p>Il modulo non è più valido. <a href="' . $e($url('requests')) . '">Riprova</a>.</p>');
}

// --- Primo utente: solo se non esiste ancora nessuno e con il codice scritto in config.php.
$hasUsers = (int) App::db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
if (!$hasUsers) {
    $token = (string) App::config('setup_token', '');
    $error = '';
    if ($isPost && $page === 'setup') {
        $problem = Auth::passwordProblem((string) ($_POST['password'] ?? ''));
        if ($token === '' || !hash_equals($token, (string) ($_POST['token'] ?? '')) || !App::allow('setup:' . App::ipHash(), 5, 900)) {
            $error = 'Codice di installazione non valido.';
        } elseif (!filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL) || trim((string) ($_POST['name'] ?? '')) === '') {
            $error = 'Inserisci nome ed e-mail validi.';
        } elseif ($problem) {
            $error = $problem;
        } else {
            $id = Auth::createUser($_POST['email'], $_POST['name'], $_POST['password'], 'admin');
            App::log($id, 'utente.creato', mb_strtolower($_POST['email']), 'primo amministratore');
            $say('Amministratore creato. Ora puoi accedere. Svuota setup_token in config.php.');
            $go($url('login'));
        }
    }
    $render('Prima installazione', ($error ? '<p class="flash">' . $e($error) . '</p>' : '')
        . '<form method="post" action="' . $e($url('setup')) . '" class="card narrow">' . $csrfField()
        . '<label>Codice di installazione (setup_token in config.php)<input type="password" name="token" required autocomplete="off"></label>'
        . '<label>Nome<input name="name" required maxlength="150"></label><label>E-mail<input type="email" name="email" required maxlength="190"></label>'
        . '<label>Password (almeno ' . Auth::MIN_PASSWORD . ' caratteri)<input type="password" name="password" required minlength="' . Auth::MIN_PASSWORD . '" autocomplete="new-password"></label>'
        . '<button class="btn">Crea amministratore</button></form>');
}

$user = Auth::user();

if ($page === 'logout' && $isPost) {
    if ($user) {
        App::log((int) $user['id'], 'uscita');
    }
    Auth::logout();
    $go($url('login'));
}

if (!$user) {
    $error = '';
    if ($isPost && $page === 'login') {
        if (Auth::login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
            $go($url('requests'));
        }
        $error = 'Accesso non riuscito. Controlla e-mail e password; dopo 5 tentativi falliti bisogna aspettare 15 minuti.';
    }
    $render('Accesso', ($error ? '<p class="flash">' . $e($error) . '</p>' : '')
        . '<form method="post" action="' . $e($url('login')) . '" class="card narrow">' . $csrfField()
        . '<label>E-mail<input type="email" name="email" required autocomplete="username" autofocus></label>'
        . '<label>Password<input type="password" name="password" required autocomplete="current-password"></label><button class="btn">Accedi</button></form>');
}
$uid = (int) $user['id'];
if (!Auth::canOpen($user, $page)) {
    $go($url(Auth::home($user)));
}

// --- PDF di un preventivo: quello inviato (copia esatta) oppure l'anteprima della bozza.
if ($page === 'quote_pdf') {
    $q = Quote::find((int) ($_GET['id'] ?? 0));
    $rq = $q ? App::db()->prepare('SELECT * FROM requests WHERE id = ?') : null;
    $rq?->execute([$q['request_id'] ?? 0]);
    $req = $rq ? $rq->fetch() : false;
    if (!$q || !$req) {
        http_response_code(404);
        $render('Preventivo non trovato', '<p><a href="' . $e($url('requests')) . '">Torna all\'elenco</a></p>', $user);
    }
    $file = dirname(__DIR__) . '/var/quotes/' . basename((string) $q['pdf_file']);
    $pdf = $q['status'] === 'inviato' && $q['pdf_file'] !== '' && is_file($file) ? (string) file_get_contents($file) : Quote::pdf($req, $q);
    header_remove('Content-Security-Policy');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $req['ref'] . '-rev' . $q['rev'] . ($q['status'] === 'bozza' ? '-bozza' : '')) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

// --- Fiere e Open Day (solo amministratore): elenco e scheda di modifica. Le modifiche restano in bozza.
if (($page === 'fairs' || $page === 'fair') && Auth::can($user, 'content')) {
    if ($page === 'fairs') {
        if ($isPost && ($_POST['action'] ?? '') === 'import') {
            $n = Fairs::importSeed();
            App::log($uid, 'fiere.importate', '', (string) $n);
            $say($n > 0 ? "Importate $n fiere dal sito." : 'Nessuna fiera nuova da importare.');
            $go($url('fairs'));
        }
        $render('Fiere', FairsView::list(Auth::csrf(), $url('fairs')), $user);
    }
    $row = isset($_GET['id']) ? Content::find(Fairs::KIND, (string) $_GET['id']) : null;
    if (isset($_GET['id']) && !$row) {
        http_response_code(404);
        $render('Fiera non trovata', '<p><a href="' . $e($url('fairs')) . '">Torna alle fiere</a></p>', $user);
    }
    $self = $url('fair', $row ? ['id' => $row['item_id']] : ['new' => 1]);
    if ($isPost) {
        $action = (string) ($_POST['action'] ?? '');
        if ($row && $action === 'delete') {
            Content::markDeleted(Fairs::KIND, $row['item_id'], $uid);
            App::log($uid, 'fiera.eliminata', $row['item_id']);
            $say('Fiera segnata per l\'eliminazione: sparirà dal sito alla prossima pubblicazione.');
            $go($url('fairs'));
        }
        if ($row && $action === 'revert') {
            Content::revert(Fairs::KIND, $row['item_id'], $uid);
            App::log($uid, 'fiera.annullata', $row['item_id']);
            $say('Modifiche annullate.');
            $go($self);
        }
        [$fair, $errors] = Fairs::fromForm($_POST, $row['draft'] ?? null);
        $newId = (string) $fair['id'];
        if (!$row && $newId !== '' && Content::find(Fairs::KIND, $newId)) {
            $errors[] = 'Esiste già una fiera con questo nome e questo anno: aprila dall\'elenco.';
        }
        [$photo, $photoError] = $errors || $newId === '' ? [null, null] : Fairs::storeImage($_FILES['photo'] ?? null, $newId);
        if ($photoError) {
            $errors[] = $photoError;
        }
        if (!$errors && $photo !== null) {
            $fair['immagine'] = $photo;
        }
        $errors = array_merge($errors, $errors ? [] : Fairs::validate($fair));
        if ($errors) {
            $_SESSION['fair_form'] = $_POST;
            $say(implode(' ', array_slice($errors, 0, 3)), false);
            $go($self);
        }
        $meta = ['translate' => (($_POST['translate'] ?? '') === '1')];
        $created = Content::saveDraft(Fairs::KIND, $fair['id'], $fair, $uid, $meta);
        App::log($uid, $created ? 'fiera.creata' : 'fiera.salvata', $fair['id']);
        $say($created ? 'Fiera creata (bozza).' : 'Bozza salvata.');
        $go($url('fair', ['id' => $fair['id']]));
    }
    $raw = $_SESSION['fair_form'] ?? null;
    unset($_SESSION['fair_form']);
    $render($row ? 'Fiera: ' . ($row['draft']['nome'] ?? $row['item_id']) : 'Nuova fiera', FairsView::form($row, Auth::csrf(), is_array($raw) ? $raw : null), $user);
}

// --- Galleria (solo amministratore): griglia, aggiunta, scheda di una foto. Le modifiche restano in bozza.
if (($page === 'gallery' || $page === 'gallery_photo' || $page === 'gallery_img') && Auth::can($user, 'content')) {
    if ($page === 'gallery_img') { // originale di una foto nuova (non ancora sul sito), solo per chi ha fatto l'accesso
        $f = Gallery::original((string) ($_GET['id'] ?? ''));
        if ($f === null) {
            http_response_code(404);
            exit;
        }
        $info = @getimagesize($f);
        header_remove('Content-Security-Policy');
        header('Content-Type: ' . ($info['mime'] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($f));
        header('Cache-Control: private, max-age=300');
        readfile($f);
        exit;
    }
    if ($page === 'gallery') {
        if ($isPost) {
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'import') {
                $n = Gallery::importSeed();
                App::log($uid, 'galleria.importata', '', (string) $n);
                $say($n > 0 ? "Importate $n foto dal sito." : 'Nessuna foto nuova da importare.');
            } elseif ($action === 'add') {
                [$added, $errors] = Gallery::addUploads($_FILES['photos'] ?? [], (string) ($_POST['categoria'] ?? ''), $uid);
                App::log($uid, 'galleria.aggiunte', '', (string) count($added));
                $msg = ($added ? count($added) . (count($added) === 1 ? ' foto aggiunta' : ' foto aggiunte') . ' (in fondo alla galleria, in bozza). ' : '') . implode(' ', array_slice($errors, 0, 3));
                $say(trim($msg), !$errors);
            }
            $go($url('gallery'));
        }
        $cat = is_string($_GET['cat'] ?? null) && isset(Gallery::CATEGORIES[$_GET['cat']]) ? $_GET['cat'] : '';
        $render('Galleria', GalleryView::list(Auth::csrf(), $cat), $user);
    }
    $id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
    $row = Gallery::rows()[$id] ?? null;
    if (!$row) {
        http_response_code(404);
        $render('Foto non trovata', '<p><a href="' . $e($url('gallery')) . '">Torna alla galleria</a></p>', $user);
    }
    $self = $url('gallery_photo', ['id' => $id]);
    if ($isPost) {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'delete') {
            Gallery::delete($id, $uid);
            App::log($uid, 'galleria.eliminata', $id);
            $say('Foto eliminata.');
            $go($url('gallery'));
        }
        if ($action === 'revert') {
            Content::revert(Gallery::KIND, $id, $uid);
            App::log($uid, 'galleria.annullata', $id);
            $say('Modifiche annullate.');
            $go($self);
        }
        $pos = ctype_digit(trim((string) ($_POST['position'] ?? ''))) ? (int) $_POST['position'] : null;
        $errors = Gallery::save($id, (string) ($_POST['categoria'] ?? ''), (string) ($_POST['alt'] ?? ''), (string) ($_POST['alt_en'] ?? ''), $pos, $uid);
        if ($errors) {
            $_SESSION['gallery_form'] = $_POST;
            $say(implode(' ', $errors), false);
            $go($self);
        }
        App::log($uid, 'galleria.salvata', $id);
        $say('Bozza salvata.');
        $go($self);
    }
    $raw = $_SESSION['gallery_form'] ?? null;
    unset($_SESSION['gallery_form']);
    $render('Foto ' . $id, GalleryView::photo($row, Auth::csrf(), is_array($raw) ? $raw : null), $user);
}

// --- Testi fissi del sito, per pagina (solo amministratore). Le modifiche restano in bozza.
// --- Video e tutorial (solo amministratore): elenco con gruppi, aggiunta, scheda di un video. Le modifiche restano in bozza.
if (($page === 'videos' || $page === 'video') && Auth::can($user, 'content')) {
    Videos::importSeed();
    if ($page === 'videos') {
        if ($isPost) {
            [$item, $errors] = Videos::fromForm($_POST, null);
            if ($errors) {
                $_SESSION['video_form'] = $_POST;
                $say(implode(' ', array_slice($errors, 0, 3)), false);
                $go($url('videos'));
            }
            $key = Videos::key($item['servizio'], $item['id']);
            Content::saveDraft(Videos::KIND, $key, $item, $uid);
            App::log($uid, 'video.aggiunto', $key);
            $say('Video aggiunto (bozza).');
            $go($url('video', ['id' => $key]));
        }
        $raw = $_SESSION['video_form'] ?? null;
        unset($_SESSION['video_form']);
        $group = is_string($_GET['g'] ?? null) && isset(Videos::GROUPS[$_GET['g']]) ? $_GET['g'] : '';
        $render('Video e tutorial', VideosView::list(Auth::csrf(), $group, Videos::rows(), is_array($raw) ? $raw : null), $user);
    }
    $id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
    $row = $id !== '' ? Content::find(Videos::KIND, $id) : null;
    if (!$row) {
        http_response_code(404);
        $render('Video non trovato', '<p><a href="' . $e($url('videos')) . '">Torna ai video</a></p>', $user);
    }
    $self = $url('video', ['id' => $row['item_id']]);
    if ($isPost) {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'delete') {
            Content::markDeleted(Videos::KIND, $row['item_id'], $uid);
            App::log($uid, 'video.eliminato', $row['item_id']);
            $say('Video segnato per l\'eliminazione: sparirà dal sito alla prossima pubblicazione.');
            $go($url('videos'));
        }
        if ($action === 'revert') {
            Content::revert(Videos::KIND, $row['item_id'], $uid);
            App::log($uid, 'video.annullato', $row['item_id']);
            $say('Modifiche annullate.');
            $go($self);
        }
        [$item, $errors] = Videos::fromForm($_POST, $row['draft'] ?: ($row['published'] ?? null));
        if ($errors) {
            $_SESSION['video_form'] = $_POST;
            $say(implode(' ', array_slice($errors, 0, 3)), false);
            $go($self);
        }
        Content::saveDraft(Videos::KIND, $row['item_id'], $item, $uid);
        App::log($uid, 'video.salvato', $row['item_id']);
        $say('Bozza salvata.');
        $go($self);
    }
    $raw = $_SESSION['video_form'] ?? null;
    unset($_SESSION['video_form']);
    $render('Video: ' . ($row['draft']['titolo'] ?? $row['item_id']), VideosView::form($row, Auth::csrf(), is_array($raw) ? $raw : null), $user);
}

// --- Listini riservati (tutti gli utenti interni, commerciali compresi): elenco e download. Caricare o togliere: solo amministratore.
if ($page === 'listino_file') {
    $id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
    $path = Listini::path($id);
    $row = $path !== null ? Content::find(Listini::KIND, $id) : null;
    if ($path === null || !$row) {
        http_response_code(404);
        $render('Listino non trovato', '<p><a href="' . $e($url('listini')) . '">Torna ai listini</a></p>', $user);
    }
    App::log($uid, 'listino.scaricato', $id, (string) $row['published']['titolo']);
    header('Content-Type: application/pdf');
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: attachment; filename="' . (string) $row['published']['nome_file'] . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}
if ($page === 'listini') {
    if ($isPost) {
        $action = (string) ($_POST['action'] ?? '');
        if (Auth::can($user, 'listini.manage') && $action === 'upload') {
            $f = $_FILES['pdf'] ?? null;
            if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $say(Listini::uploadError(is_array($f) ? (int) $f['error'] : UPLOAD_ERR_NO_FILE), false);
                $go($url('listini'));
            }
            [, $error] = Listini::save((string) $f['tmp_name'], (int) $f['size'], (string) $f['name'], (string) ($_POST['titolo'] ?? ''), (string) ($_POST['lingua'] ?? ''), $uid);
            $say($error ?? 'Listino caricato: ora è disponibile ai commerciali.', $error === null);
            $go($url('listini'));
        }
        if (Auth::can($user, 'listini.manage') && $action === 'remove') {
            Listini::remove((string) ($_POST['id'] ?? ''), $uid);
            $say('Listino tolto dall\'area riservata.');
            $go($url('listini'));
        }
    }
    $render('Listini riservati', ListiniView::page(Auth::can($user, 'listini.manage'), Listini::current(), Auth::csrf(), $url('listini')), $user);
}

// --- Statistiche del sito (solo amministratore): senza cookie, nessun IP salvato.
if ($page === 'stats' && Auth::can($user, 'stats')) {
    $days = in_array((int) ($_GET['d'] ?? 30), [7, 30, 90], true) ? (int) $_GET['d'] : 30;
    $render('Statistiche', StatsView::page(Stats::summary($days)), $user);
}

// --- Ordini (nascono dai preventivi accettati). Lo stato va solo avanti.
if ($page === 'orders') {
    $st = is_string($_GET['s'] ?? null) ? $_GET['s'] : '';
    $rows = Orders::list($st);
    $body = '<div class="chips"><a class="chip' . ($st === '' ? ' on' : '') . '" href="' . $e($url('orders')) . '">Tutti</a>';
    foreach (Orders::STATES as $k => $label) {
        $body .= '<a class="chip' . ($st === $k ? ' on' : '') . '" href="' . $e($url('orders', ['s' => $k])) . '">' . $e($label) . '</a>';
    }
    $body .= '</div><table class="list"><tr><th>Ordine</th><th>Cliente</th><th class="num">Totale IVA esclusa</th><th>Stato</th><th>Aggiornato</th></tr>';
    foreach ($rows as $o) {
        $body .= '<tr><td><a href="' . $e($url('order', ['id' => $o['id']])) . '">' . $e($o['ref']) . '</a></td><td>' . $e($o['name'] . ($o['company'] !== '' ? ' · ' . $o['company'] : ''))
            . '</td><td class="num">' . $e(Quote::money((int) $o['total'])) . '</td><td><span class="status">' . $e(Orders::STATES[$o['status']] ?? $o['status']) . '</span></td><td>' . $e($date($o['updated_at'])) . '</td></tr>';
    }
    if (!$rows) {
        $body .= '<tr><td colspan="5" class="empty">Nessun ordine.</td></tr>';
    }
    $render('Ordini', $body . '</table>', $user);
}
if ($page === 'order') {
    $oid = (int) ($_GET['id'] ?? 0);
    $o = $oid ? Orders::find($oid) : null;
    if (!$o) {
        http_response_code(404);
        $render('Ordine non trovato', '<p><a href="' . $e($url('orders')) . '">Torna agli ordini</a></p>', $user);
    }
    $self = $url('order', ['id' => $oid]);
    if ($isPost && ($_POST['action'] ?? '') === 'state') {
        $err = Orders::advance($oid, (string) ($_POST['state'] ?? ''), $uid, trim((string) ($_POST['note'] ?? '')));
        $say($err ?? 'Stato dell\'ordine aggiornato.', $err === null);
        $go($self);
    }
    $keys = array_keys(Orders::STATES);
    $next = array_slice($keys, array_search($o['status'], $keys, true) + 1);
    $body = '<p class="meta">Dal preventivo <a href="' . $e($url('request', ['id' => $o['request_id']])) . '#preventivo">' . $e($o['request_ref']) . '</a> · '
        . $e($o['name']) . ($o['company'] !== '' ? ' · ' . $e($o['company']) : '') . ' · ' . $e($o['email']) . '</p>'
        . '<section class="card narrow"><table class="kv"><tr><th>Ordine</th><td>' . $e($o['ref']) . '</td></tr><tr><th>Totale IVA esclusa</th><td>' . $e(Quote::money((int) $o['total'])) . '</td></tr>'
        . '<tr><th>Stato</th><td><span class="status">' . $e(Orders::STATES[$o['status']]) . '</span></td></tr><tr><th>Creato</th><td>' . $e($date($o['created_at'])) . '</td></tr></table>';
    if ($o['note']) {
        $body .= '<p class="meta">Note: ' . $e($o['note']) . '</p>';
    }
    if ($next) {
        $body .= '<form method="post" action="' . $e($self) . '" class="actions">' . $csrfField() . '<input type="hidden" name="action" value="state">'
            . '<label>Passa a<select name="state">' . implode('', array_map(static fn (string $k): string => '<option value="' . $e($k) . '">' . $e(Orders::STATES[$k]) . '</option>', $next)) . '</select></label>'
            . '<label>Note (facoltative)<input name="note" maxlength="2000"></label><button class="btn">Aggiorna stato</button></form>';
    } else {
        $body .= '<p class="meta">Ordine concluso.</p>';
    }
    $render('Ordine ' . $o['ref'], $body . '</section>', $user);
}

if (($page === 'texts' || $page === 'text_group') && Auth::can($user, 'content')) {
    if ($page === 'texts') {
        $render('Testi del sito', TextsView::index(trim(is_string($_GET['q'] ?? null) ? mb_substr($_GET['q'], 0, 80) : '')), $user);
    }
    $g = Texts::group(is_string($_GET['g'] ?? null) ? $_GET['g'] : '');
    if (!$g) {
        http_response_code(404);
        $render('Gruppo non trovato', '<p><a href="' . $e($url('texts')) . '">Torna ai testi</a></p>', $user);
    }
    if ($isPost) {
        $it = is_array($_POST['it'] ?? null) ? $_POST['it'] : [];
        $en = is_array($_POST['en'] ?? null) ? $_POST['en'] : [];
        if (($_POST['action'] ?? '') === 'revert') {
            Texts::saveGroup($g['id'], [], [], $uid); // campi vuoti: tolte tutte le correzioni della pagina
            App::log($uid, 'testi.annullati', $g['id']);
            $say('Modifiche di questa pagina annullate.');
            $go($url('text_group', ['g' => $g['id']]));
        }
        $errors = Texts::saveGroup($g['id'], $it, $en, $uid);
        App::log($uid, 'testi.salvati', $g['id']);
        $say($errors ? implode(' ', array_slice($errors, 0, 3)) : 'Bozza salvata.', !$errors);
        $go($url('text_group', ['g' => $g['id']]));
    }
    $render($g['name'], TextsView::group($g, Auth::csrf(), null), $user);
}

// --- Condizioni generali del PDF (testo del cliente, in italiano e in inglese). Solo amministratore.
if ($page === 'conditions' && Auth::can($user, 'conditions')) {
    if ($isPost) {
        foreach (['it', 'en'] as $l) {
            App::setSetting('conditions_' . $l, mb_substr(trim(str_replace(["\r\n", "\r"], "\n", (string) ($_POST['conditions_' . $l] ?? ''))), 0, 8000));
        }
        App::log($uid, 'condizioni.salvate');
        $say('Condizioni generali salvate.');
        $go($url('conditions'));
    }
    $render('Condizioni generali del preventivo', '<p class="meta">Questo testo compare in fondo a ogni PDF di preventivo, nella lingua della richiesta. Va fornito e verificato da ARNOplast: non viene scritto dal backoffice. Se lo lasci vuoto, il PDF parte senza condizioni.</p>'
        . '<form method="post" action="' . $e($url('conditions')) . '" class="card">' . $csrfField()
        . '<label>Italiano<textarea name="conditions_it" rows="12" maxlength="8000">' . $e(App::setting('conditions_it')) . '</textarea></label>'
        . '<label>Inglese<textarea name="conditions_en" rows="12" maxlength="8000">' . $e(App::setting('conditions_en')) . '</textarea></label><button class="btn">Salva</button></form>', $user);
}

/** Testo di un messaggio: per le risposte dei clienti la parte nuova in chiaro e il testo citato ripiegato. */
$showText = static function (array $m) use ($e): string {
    if ($m['direction'] !== 'in') {
        return '<pre>' . $e($m['body_text']) . '</pre>';
    }
    [$new, $quoted] = Mime::splitQuote((string) $m['body_text']);
    return '<pre>' . $e($new) . '</pre>' . ($quoted === '' ? '' : '<details><summary>Mostra il testo citato</summary><pre>' . $e($quoted) . '</pre></details>');
};

// --- Scheda di una richiesta: dati, articoli, conversazione, stato e note interne.
if ($page === 'request') {
    $st = App::db()->prepare('SELECT * FROM requests WHERE id = ?');
    $st->execute([(int) ($_GET['id'] ?? 0)]);
    $r = $st->fetch();
    if (!$r) {
        http_response_code(404);
        $render('Richiesta non trovata', '<p><a href="' . $e($url('requests')) . '">Torna all\'elenco</a></p>', $user);
    }
    $self = $url('request', ['id' => $r['id']]);
    if ($isPost) {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'quote_accept' && Auth::can($user, 'quotes')) {
            $aq = Quote::find((int) ($_POST['quote_id'] ?? 0));
            if (!$aq || (int) $aq['request_id'] !== (int) $r['id']) {
                $say('Preventivo non trovato.', false);
                $go($self . '#preventivo');
            }
            [$oid, $err] = Orders::accept($r, $aq, $uid, mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 2000));
            $say($err ?? 'Preventivo accettato: ordine creato.', $err === null);
            $go($err === null ? $url('order', ['id' => $oid]) : $self . '#preventivo');
        }
        if (in_array($action, ['quote_new', 'quote_save', 'quote_send'], true)) {
            $qid = (int) ($_POST['quote_id'] ?? 0);
            $q = $qid ? Quote::find($qid) : null;
            if ($q && (int) $q['request_id'] !== (int) $r['id']) {
                $q = null;
            }
            if ($action === 'quote_new') {
                $quotes = Quote::forRequest((int) $r['id']);
                $hasDraft = array_filter($quotes, static fn (array $x): bool => $x['status'] === 'bozza');
                if (!$hasDraft) {
                    $base = $quotes ? Quote::data($quotes[0]) : Quote::draftFromRequest($r);
                    $newId = Quote::saveDraft((int) $r['id'], $base, $uid);
                    App::log($uid, 'preventivo.bozza', $r['ref'], 'rev ' . ($quotes ? (int) $quotes[0]['rev'] + 1 : 1));
                    $go($self . '&q=' . $newId . '#preventivo');
                }
                $go($self . '#preventivo');
            }
            if (!$q || $q['status'] !== 'bozza') {
                $say('Questo preventivo non si può più modificare.', false);
                $go($self . '#preventivo');
            }
            if ($action === 'quote_save') {
                [$data, $errors] = Quote::fromForm($_POST);
                if ($errors) {
                    $_SESSION['quote_form'] = $_POST;
                    $say(implode(' ', array_slice($errors, 0, 4)), false);
                    $go($self . '&q=' . $q['id'] . '#preventivo');
                }
                Quote::saveDraft((int) $r['id'], $data, $uid, (int) $q['id']);
                App::log($uid, 'preventivo.salvato', $r['ref']);
                if (($_POST['do'] ?? '') === 'send') {
                    $go($self . '&confirm=' . $q['id'] . '#preventivo');
                }
                $say('Bozza salvata.');
                $go($self . '&q=' . $q['id'] . '#preventivo');
            }
            $res = Quote::send($r, $q, $user, (string) ($_POST['message'] ?? ''));
            $say($res['ok'] ? 'Preventivo inviato al cliente.' : $res['error'], $res['ok']);
            $go($self . '#preventivo');
        }
        if (isset($_POST['status'], STATUSES[$_POST['status']]) && $_POST['status'] !== $r['status']) {
            App::db()->prepare('UPDATE requests SET status = ?, updated_at = ? WHERE id = ?')->execute([$_POST['status'], App::now(), $r['id']]);
            App::log($uid, 'richiesta.stato', $r['ref'], $r['status'] . ' → ' . $_POST['status']);
            $say('Stato aggiornato.');
        }
        if (($_POST['action'] ?? '') === 'reply') {
            $res = Conversation::reply($r, $user, (string) ($_POST['subject'] ?? ''), (string) ($_POST['text'] ?? ''));
            $say($res['ok'] ? 'Risposta inviata al cliente.' : $res['error'], $res['ok']);
            $go($self);
        }
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($note !== '') {
            App::db()->prepare('INSERT INTO messages (request_id, direction, user_id, body_text, created_at) VALUES (?,?,?,?,?)')
                ->execute([$r['id'], 'note', $uid, mb_substr($note, 0, 5000), App::now()]);
            App::db()->prepare('UPDATE requests SET updated_at = ? WHERE id = ?')->execute([App::now(), $r['id']]);
            App::log($uid, 'richiesta.nota', $r['ref']);
            $say('Nota salvata.');
        }
        $go($self);
    }
    $data = json_decode($r['data'], true) ?: [];
    $labels = ['name' => 'Nome', 'surname' => 'Cognome', 'email' => 'E-mail', 'phone' => 'Telefono', 'company' => 'Azienda', 'contact' => 'Referente',
        'type' => 'Tipologia', 'address' => 'Indirizzo', 'zip' => 'CAP', 'city' => 'Località', 'country' => 'Paese'];
    $rows = '';
    foreach ($labels as $k => $label) {
        if (trim((string) ($data[$k] ?? '')) !== '') {
            $rows .= '<tr><th>' . $e($label) . '</th><td>' . $e($data[$k]) . '</td></tr>';
        }
    }
    $body = '<p class="meta"><span class="tag ' . $e($r['kind']) . '">' . $e(KINDS[$r['kind']] ?? $r['kind']) . '</span> <strong>' . $e($r['ref']) . '</strong> · ricevuta il ' . $e($date($r['created_at']))
        . ' · pagina in ' . ($r['lang'] === 'en' ? 'inglese' : 'italiano') . '</p><div class="cols"><section class="card"><h2>Cliente</h2><table class="kv">' . $rows . '</table>';
    $items = json_decode((string) $r['items'], true);
    if (is_array($items) && $items) {
        $body .= '<h2>Articoli richiesti (' . count($items) . ')</h2><table class="list"><tr><th>Codice</th><th>Descrizione</th><th class="num">Q.tà</th></tr>';
        foreach ($items as $i) {
            $sub = array_filter([$i['product'] ?? '', empty($i['conf']) ? '' : 'conf. da ' . (int) $i['conf']]);
            $body .= '<tr><td class="code">' . $e($i['code']) . '</td><td>' . $e($i['description']) . ($sub ? '<br><small>' . $e(implode(' · ', $sub)) . '</small>' : '')
                . '</td><td class="num">' . (int) $i['qty'] . '</td></tr>';
        }
        $body .= '</table>';
    }
    $body .= '</section><section class="card"><h2>Stato e note interne</h2><form method="post" action="' . $e($self) . '">' . $csrfField() . '<label>Stato<select name="status">';
    foreach (STATUSES as $k => $label) {
        $body .= '<option value="' . $e($k) . '"' . ($k === $r['status'] ? ' selected' : '') . '>' . $e($label) . '</option>';
    }
    $body .= '</select></label><label>Nota interna (non viene inviata al cliente)<textarea name="note" rows="4" maxlength="5000"></textarea></label><button class="btn">Salva</button></form></section></div>';
    $raw = $_SESSION['quote_form'] ?? null;
    unset($_SESSION['quote_form']);
    $body .= QuoteView::render($r, $self, Auth::csrf(), is_array($raw) ? $raw : null, isset($_GET['q']) ? (int) $_GET['q'] : null, isset($_GET['confirm']) ? (int) $_GET['confirm'] : null);
    $ms = App::db()->prepare('SELECT m.*, u.name AS author FROM messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.request_id = ? ORDER BY m.id');
    $ms->execute([$r['id']]);
    $body .= '<h2>Conversazione</h2><ol class="thread">';
    foreach ($ms as $m) {
        $who = match ($m['direction']) {
            'in' => 'Cliente · ' . $m['from_addr'],
            'note' => 'Nota interna · ' . ($m['author'] ?? ''),
            'auto' => 'E-mail automatica a ' . $m['to_addr'],
            default => 'Risposta a ' . $m['to_addr'] . ($m['author'] ? ' · ' . $m['author'] : ''),
        };
        $body .= '<li class="msg ' . $e($m['direction']) . '"><p class="who">' . $e($who) . ' <time>' . $e($date($m['created_at'])) . '</time>'
            . ((int) $m['sent_ok'] === 1 ? '' : ' <strong class="bad">NON INVIATA: ' . $e($m['error']) . '</strong>') . '</p>'
            . ($m['subject'] !== '' ? '<p class="subj">' . $e($m['subject']) . '</p>' : '')
            . ($m['direction'] === 'auto' ? '<details><summary>Mostra il testo</summary><pre>' . $e($m['body_text']) . '</pre></details>' : $showText($m)) . '</li>';
    }
    $lang = $r['lang'] === 'en' ? 'en' : 'it';
    $defaultSubject = ($lang === 'en' ? 'Reply to your request' : 'Risposta alla tua richiesta') . ' [' . $r['ref'] . ']';
    $body .= '</ol><h2>Rispondi al cliente</h2><form method="post" action="' . $e($self) . '" class="card">' . $csrfField() . '<input type="hidden" name="action" value="reply">'
        . '<p class="meta">Scrivi in ' . ($lang === 'en' ? 'inglese: il cliente ha scritto dalla pagina inglese' : 'italiano') . '. L\'e-mail parte da ' . $e(App::config('mail.from', '')) . ' con la grafica di ARNOplast, a ' . $e($r['email']) . '.</p>'
        . '<label>Oggetto<input name="subject" maxlength="200" value="' . $e($defaultSubject) . '"></label>'
        . '<label>Testo della risposta<textarea name="text" rows="9" maxlength="' . Conversation::MAX_REPLY . '" required></textarea></label><button class="btn">Invia la risposta</button></form>';
    $render('Richiesta di ' . $r['name'], $body, $user);
}

// --- Posta: stato della lettura della casella e controllo manuale (solo amministratore).
if ($page === 'mail' && Auth::can($user, 'mail')) {
    if ($isPost) {
        $res = Inbox::run();
        App::log($uid, 'posta.controllo', '', $res['error'] !== '' ? $res['error'] : $res['imported'] . ' aggiunti');
        $say($res['error'] !== '' ? 'Controllo non riuscito: ' . $res['error'] : sprintf('Controllo fatto: %d messaggi controllati, %d aggiunti alle richieste.', $res['checked'], $res['imported']), $res['error'] === '');
        $go($url('mail'));
    }
    $last = App::state('imap_last_run');
    $render('Posta', '<section class="card"><p>Casella letta: <strong>' . $e(App::config('imap.user', App::config('mail.user', ''))) . '</strong></p>'
        . '<p>Ultimo controllo: ' . ($last === '' ? '<em>mai</em>' : $e($date($last))) . '<br><small>' . $e(App::state('imap_last_result')) . '</small></p>'
        . '<p class="meta">Il backoffice prende solo i messaggi con un riferimento (per esempio C-2026-0001) nell\'oggetto, o che rispondono a una conversazione già avviata. Gli altri restano nella casella, senza essere segnati come letti. Il controllo automatico si imposta con una pianificazione (cron) sul server: vedi il file README.</p>'
        . '<form method="post" action="' . $e($url('mail')) . '">' . $csrfField() . '<button class="btn">Controlla la posta ora</button></form></section>', $user);
}

// --- Utenti (solo amministratore).
if ($page === 'users' && Auth::can($user, 'users')) {
    if ($isPost) {
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
            $problem = Auth::passwordProblem((string) ($_POST['password'] ?? ''));
            $exists = App::db()->prepare('SELECT 1 FROM users WHERE email = ?');
            $exists->execute([$email]);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || trim((string) ($_POST['name'] ?? '')) === '') {
                $say('Inserisci nome ed e-mail validi.', false);
            } elseif ($problem) {
                $say($problem, false);
            } elseif ($exists->fetchColumn()) {
                $say('Esiste già un utente con questa e-mail.', false);
            } else {
                Auth::createUser($email, (string) $_POST['name'], (string) $_POST['password'], (string) ($_POST['role'] ?? 'staff'));
                App::log($uid, 'utente.creato', $email, (string) ($_POST['role'] ?? 'staff'));
                $say('Utente creato.');
            }
        } elseif ($action === 'role' && (int) ($_POST['id'] ?? 0) !== $uid && isset(Auth::ROLES[(string) ($_POST['role'] ?? '')])) {
            $target = (int) $_POST['id'];
            $newRole = (string) $_POST['role'];
            $cur = App::db()->prepare('SELECT role, email FROM users WHERE id = ?');
            $cur->execute([$target]);
            $row = $cur->fetch();
            $lastAdmin = $row && $row['role'] === 'admin' && $newRole !== 'admin'
                && (int) App::db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn() <= 1;
            if (!$row) {
                $say('Utente non trovato.', false);
            } elseif ($lastAdmin) {
                $say('Non si può togliere il ruolo all\'ultimo amministratore attivo.', false);
            } else {
                App::db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $target]);
                App::log($uid, 'utente.ruolo', (string) $row['email'], $row['role'] . ' → ' . $newRole);
                $say('Ruolo aggiornato.');
            }
        } elseif ($action === 'toggle' && (int) ($_POST['id'] ?? 0) !== $uid) {
            App::db()->prepare('UPDATE users SET active = 1 - active WHERE id = ?')->execute([(int) $_POST['id']]);
            App::log($uid, 'utente.attivo', (string) (int) $_POST['id']);
            $say('Utente aggiornato.');
        }
        $go($url('users'));
    }
    $body = '<table class="list"><tr><th>Nome</th><th>E-mail</th><th>Ruolo</th><th>Ultimo accesso</th><th>Stato</th><th></th></tr>';
    foreach (App::db()->query('SELECT * FROM users ORDER BY name') as $u) {
        $body .= '<tr><td>' . $e($u['name']) . '</td><td>' . $e($u['email']) . '</td><td>' . $e(Auth::ROLES[$u['role']] ?? $u['role']) . '</td><td>' . ((int) $u['id'] === $uid ? $e(Auth::ROLES[$u['role']] ?? $u['role']) : '<form method="post" action="' . $e($url('users')) . '" class="inline-form">' . $csrfField()
            . '<input type="hidden" name="action" value="role"><input type="hidden" name="id" value="' . (int) $u['id'] . '"><select name="role" aria-label="Ruolo di ' . $e($u['name']) . '">'
            . implode('', array_map(static fn (string $k, string $label): string => '<option value="' . $e($k) . '"' . ($k === $u['role'] ? ' selected' : '') . '>' . $e($label) . '</option>', array_keys(Auth::ROLES), Auth::ROLES))
            . '</select> <button class="link">Cambia</button></form>') . '</td><td>' . $e($date($u['last_login_at']))
            . '</td><td>' . ((int) $u['active'] ? 'Attivo' : 'Disattivato') . '</td><td>' . ((int) $u['id'] === $uid ? '' : '<form method="post" action="' . $e($url('users')) . '">' . $csrfField()
            . '<input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="' . (int) $u['id'] . '"><button class="link">' . ((int) $u['active'] ? 'Disattiva' : 'Riattiva') . '</button></form>') . '</td></tr>';
    }
    $body .= '</table><h2>Nuovo utente</h2><form method="post" action="' . $e($url('users')) . '" class="card narrow">' . $csrfField() . '<input type="hidden" name="action" value="create">'
        . '<label>Nome<input name="name" required maxlength="150"></label><label>E-mail<input type="email" name="email" required maxlength="190"></label>'
        . '<label>Ruolo<select name="role">' . implode('', array_map(static fn (string $k, string $label): string => '<option value="' . $e($k) . '">' . $e($label) . '</option>', array_keys(Auth::ROLES), Auth::ROLES)) . '</select></label>'
        . '<label>Password (almeno ' . Auth::MIN_PASSWORD . ' caratteri)<input type="password" name="password" required minlength="' . Auth::MIN_PASSWORD . '" autocomplete="new-password"></label><button class="btn">Crea utente</button></form>';
    $render('Utenti', $body, $user);
}

// --- Registro delle attività (solo amministratore).
if ($page === 'activity' && Auth::can($user, 'activity')) {
    $body = '<table class="list"><tr><th>Quando</th><th>Chi</th><th>Azione</th><th>Oggetto</th><th>Dettaglio</th></tr>';
    foreach (App::db()->query('SELECT a.*, u.name FROM activity a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 300') as $a) {
        $body .= '<tr><td>' . $e($date($a['created_at'])) . '</td><td>' . $e($a['name'] ?? 'sito') . '</td><td>' . $e($a['action']) . '</td><td>' . $e($a['target']) . '</td><td>' . $e($a['detail']) . '</td></tr>';
    }
    $render('Registro attività', $body . '</table><p class="meta">Ultime 300 voci.</p>', $user);
}

// --- Cambio della propria password.
if ($page === 'account') {
    if ($isPost) {
        $st = App::db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$uid]);
        $problem = Auth::passwordProblem((string) ($_POST['new'] ?? ''));
        if (!password_verify((string) ($_POST['current'] ?? ''), (string) $st->fetchColumn())) {
            $say('La password attuale non è corretta.', false);
        } elseif ($problem) {
            $say($problem, false);
        } else {
            App::db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash((string) $_POST['new'], PASSWORD_DEFAULT), $uid]);
            session_regenerate_id(true);
            App::log($uid, 'password.cambiata');
            $say('Password aggiornata.');
        }
        $go($url('account'));
    }
    $render('Il mio accesso', '<p class="meta">' . $e($user['name']) . ' · ' . $e($user['email']) . ' · ' . $e(Auth::ROLES[$user['role']] ?? '') . '</p>'
        . '<form method="post" action="' . $e($url('account')) . '" class="card narrow">' . $csrfField()
        . '<label>Password attuale<input type="password" name="current" required autocomplete="current-password"></label>'
        . '<label>Nuova password (almeno ' . Auth::MIN_PASSWORD . ' caratteri)<input type="password" name="new" required minlength="' . Auth::MIN_PASSWORD . '" autocomplete="new-password"></label><button class="btn">Cambia password</button></form>', $user);
}

// --- Elenco delle richieste, con filtri.
$where = [];
$args = [];
$kind = $_GET['kind'] ?? '';
$status = $_GET['status'] ?? '';
$q = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
if (isset(KINDS[$kind])) {
    $where[] = 'kind = ?';
    $args[] = $kind;
}
if (isset(STATUSES[$status])) {
    $where[] = 'status = ?';
    $args[] = $status;
}
if ($q !== '') {
    $where[] = '(name LIKE ? OR email LIKE ? OR company LIKE ? OR ref LIKE ?)';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 100)) . '%';
    array_push($args, $like, $like, $like, $like);
}
$st = App::db()->prepare('SELECT id, ref, kind, status, name, email, company, created_at, (SELECT direction FROM messages m WHERE m.request_id = requests.id AND m.direction IN (\'in\', \'out\') ORDER BY m.id DESC LIMIT 1) AS last_dir FROM requests' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 200');
$st->execute($args);
$list = $st->fetchAll();
$opt = static function (array $map, string $cur, string $all) use ($e): string {
    $o = '<option value="">' . $e($all) . '</option>';
    foreach ($map as $k => $label) {
        $o .= '<option value="' . $e($k) . '"' . ($k === $cur ? ' selected' : '') . '>' . $e($label) . '</option>';
    }
    return $o;
};
$body = '<form method="get" action="index.php" class="filters"><input type="hidden" name="p" value="requests">'
    . '<label>Tipo<select name="kind">' . $opt(KINDS, (string) $kind, 'Tutti') . '</select></label><label>Stato<select name="status">' . $opt(STATUSES, (string) $status, 'Tutti') . '</select></label>'
    . '<label>Cerca<input type="search" name="q" value="' . $e($q) . '" placeholder="Nome, e-mail, azienda, riferimento" maxlength="100"></label><button class="btn">Filtra</button></form>';
if (!$list) {
    $body .= '<p class="empty">Nessuna richiesta' . ($where ? ' con questi filtri' : ' ricevuta finora') . '.</p>';
} else {
    $body .= '<table class="list"><tr><th>Riferimento</th><th>Tipo</th><th>Cliente</th><th>Stato</th><th>Ricevuta</th></tr>';
    foreach ($list as $r) {
        $body .= '<tr><td><a href="' . $e($url('request', ['id' => $r['id']])) . '">' . $e($r['ref']) . '</a></td><td><span class="tag ' . $e($r['kind']) . '">' . $e(KINDS[$r['kind']] ?? $r['kind']) . '</span></td>'
            . '<td>' . $e($r['name']) . ($r['company'] !== '' ? ' · ' . $e($r['company']) : '') . '<br><small>' . $e($r['email']) . '</small></td>'
            . '<td><span class="status ' . $e($r['status']) . '">' . $e(STATUSES[$r['status']] ?? $r['status']) . '</span>' . ($r['last_dir'] === 'in' && $r['status'] !== 'chiusa' ? ' <span class="status todo">Da rispondere</span>' : '') . '</td><td>' . $e($date($r['created_at'])) . '</td></tr>';
    }
    $body .= '</table>' . (count($list) === 200 ? '<p class="meta">Mostrate le 200 più recenti: usa i filtri per restringere.</p>' : '');
}
$render('Richieste', $body, $user);
