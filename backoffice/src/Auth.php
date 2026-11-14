<?php
declare(strict_types=1);

namespace Backoffice;

/** Accesso al backoffice: sessione, ruoli (admin, staff), protezione dei moduli (CSRF), limite ai tentativi. */
final class Auth
{
    /** Ruoli interni: un ruolo per persona. «staff» è il ruolo ARNOplast (vendite). */
    public const ROLES = ['admin' => 'Amministratore', 'redattore' => 'Redattore contenuti', 'staff' => 'ARNOplast (vendite)', 'commerciale' => 'Commerciale'];

    /** Permessi per ruolo. Cambiare qui è l'unico modo di cambiare cosa può fare un ruolo. */
    public const PERMISSIONS = [
        'admin' => ['dashboard', 'requests', 'quotes', 'orders', 'content', 'listini', 'listini.manage', 'stats', 'mail', 'users', 'activity', 'conditions'],
        'redattore' => ['content', 'listini'],
        'staff' => ['dashboard', 'requests', 'quotes', 'orders', 'mail', 'listini'],
        'commerciale' => ['listini'],
    ];

    /** Permesso richiesto da ogni pagina. Le pagine non elencate qui sono vietate. */
    public const PAGE_PERMISSION = [
        'dashboard' => 'dashboard', 'requests' => 'requests', 'request' => 'requests', 'quote_pdf' => 'quotes', 'orders' => 'orders', 'order' => 'orders',
        'fairs' => 'content', 'fair' => 'content', 'gallery' => 'content', 'gallery_photo' => 'content', 'gallery_img' => 'content',
        'videos' => 'content', 'video' => 'content', 'texts' => 'content', 'text_group' => 'content',
        'listini' => 'listini', 'listino_file' => 'listini',
        'stats' => 'stats', 'mail' => 'mail', 'users' => 'users', 'activity' => 'activity', 'conditions' => 'conditions',
    ];

    /** Pagine che tutti gli utenti possono aprire, a prescindere dal ruolo. */
    public const ALWAYS = ['account', 'logout'];
    public const MIN_PASSWORD = 12;
    private const IDLE_SECONDS = 7200;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        session_name('bo_session');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
        if (isset($_SESSION['seen']) && time() - $_SESSION['seen'] > self::IDLE_SECONDS) {
            self::logout();
            session_start();
        }
        $_SESSION['seen'] = time();
    }

    public static function user(): ?array
    {
        if (empty($_SESSION['uid'])) {
            return null;
        }
        $st = App::db()->prepare('SELECT id, email, name, role, active FROM users WHERE id = ?');
        $st->execute([$_SESSION['uid']]);
        $u = $st->fetch();
        return $u && (int) $u['active'] === 1 ? $u : null;
    }

    public static function can(array $user, string $permission): bool
    {
        return in_array($permission, self::PERMISSIONS[$user['role']] ?? [], true);
    }

    public static function canOpen(array $user, string $page): bool
    {
        if (in_array($page, self::ALWAYS, true)) {
            return true;
        }
        return isset(self::PAGE_PERMISSION[$page]) && self::can($user, self::PAGE_PERMISSION[$page]);
    }

    /** Pagina di partenza di ogni ruolo, dopo l'accesso. */
    public static function home(array $user): string
    {
        return match ($user['role']) {
            'redattore' => 'fairs',
            'commerciale' => 'listini',
            default => 'dashboard',
        };
    }

    public static function isAdmin(?array $user): bool
    {
        return $user !== null && $user['role'] === 'admin';
    }

    /** Vero se l'accesso riesce. Il messaggio d'errore è lo stesso per e-mail o password sbagliata. */
    public static function login(string $email, string $password): bool
    {
        $email = mb_strtolower(trim($email));
        $bucket = 'login:' . hash('sha256', $email) . ':' . App::ipHash();
        if (!App::allow($bucket, 5, 900, false) || !App::allow('login-ip:' . App::ipHash(), 20, 900, false)) {
            return false;
        }
        $st = App::db()->prepare('SELECT * FROM users WHERE email = ? AND active = 1');
        $st->execute([$email]);
        $u = $st->fetch();
        // Verifica sempre una password, anche se l'utente non esiste: il tempo di risposta non rivela se l'e-mail è registrata.
        $hash = $u['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
        if (!password_verify($password, $hash) || !$u) {
            App::allow($bucket, 5, 900);
            App::allow('login-ip:' . App::ipHash(), 20, 900);
            App::log(null, 'accesso.fallito', $email);
            return false;
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            App::db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        App::db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([App::now(), $u['id']]);
        App::log((int) $u['id'], 'accesso');
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
            session_destroy();
        }
    }

    public static function csrf(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function checkCsrf(): bool
    {
        return isset($_POST['csrf'], $_SESSION['csrf']) && is_string($_POST['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
    }

    /** Errore leggibile se la password non va bene, altrimenti null. */
    public static function passwordProblem(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            return 'La password deve avere almeno ' . self::MIN_PASSWORD . ' caratteri.';
        }
        if (strlen($password) > 200) {
            return 'La password è troppo lunga.';
        }
        return null;
    }

    public static function createUser(string $email, string $name, string $password, string $role): int
    {
        App::db()->prepare('INSERT INTO users (email, name, password_hash, role, active, created_at) VALUES (?,?,?,?,1,?)')
            ->execute([mb_strtolower(trim($email)), trim($name), password_hash($password, PASSWORD_DEFAULT), isset(self::ROLES[$role]) ? $role : 'staff', App::now()]);
        return (int) App::db()->lastInsertId();
    }
}
