<?php
declare(strict_types=1);

namespace Backoffice;

use PDO;

/** Configurazione, database e funzioni comuni. Nessun framework: PHP semplice, facile da installare su cPanel. */
final class App
{
    private static ?PDO $pdo = null;
    /** Quando il database è stato usato l'ultima volta (per accorgersi di una connessione chiusa dal server). */
    private static float $lastUse = 0.0;
    private static array $config = [];

    public static function boot(array $config): void
    {
        self::$config = $config;
        self::$pdo = null;
        date_default_timezone_set('Europe/Rome');
    }

    /** Versione del backoffice (file VERSION). */
    public static function version(): string
    {
        $f = dirname(__DIR__) . '/VERSION';
        return is_file($f) ? trim((string) file_get_contents($f)) : '';
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        $v = self::$config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($v) || !array_key_exists($part, $v)) {
                return $default;
            }
            $v = $v[$part];
        }
        return $v;
    }

    /** Vero per «MySQL server has gone away» (2006) e «Lost connection» (2013): il server ha chiuso la connessione. */
    public static function connectionLost(\Throwable $e): bool
    {
        $m = strtolower($e->getMessage());
        return str_contains($m, 'server has gone away') || str_contains($m, 'lost connection') || str_contains($m, 'error while sending')
            || in_array((int) ($e instanceof \PDOException ? ($e->errorInfo[1] ?? 0) : 0), [2006, 2013], true);
    }

    /** Chiude la connessione: la prossima richiesta al database ne apre una nuova. */
    public static function reconnect(): void
    {
        self::$pdo = null;
    }

    public static function db(): PDO
    {
        // Dopo una pausa (per esempio mentre si elaborano molte foto) il server può aver chiuso la connessione: si controlla e, se serve, si riapre.
        if (self::$pdo !== null && microtime(true) - self::$lastUse > 2.0) {
            try {
                self::$pdo->query('SELECT 1');
            } catch (\PDOException $e) {
                if (!self::connectionLost($e)) {
                    throw $e;
                }
                self::$pdo = null;
            }
        }
        self::$lastUse = microtime(true);
        if (self::$pdo === null) {
            $db = self::config('db');
            self::$pdo = new PDO($db['dsn'], $db['user'] ?? null, $db['password'] ?? null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::migrate(self::$pdo);
        }
        return self::$pdo;
    }

    /** Crea le tabelle che mancano. Stesso schema per MySQL (hosting) e SQLite (prove). */
    private static function migrate(PDO $pdo): void
    {
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $long = $mysql ? 'MEDIUMTEXT' : 'TEXT';
        $end = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $tables = [
            "CREATE TABLE IF NOT EXISTS users (id $id, email VARCHAR(190) NOT NULL UNIQUE, name VARCHAR(150) NOT NULL,
                password_hash VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL DEFAULT 'staff', active TINYINT NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL, last_login_at DATETIME NULL)$end",
            "CREATE TABLE IF NOT EXISTS requests (id $id, ref VARCHAR(20) NOT NULL UNIQUE, kind VARCHAR(10) NOT NULL, lang VARCHAR(5) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'nuova', name VARCHAR(200) NOT NULL, email VARCHAR(254) NOT NULL, phone VARCHAR(40) NOT NULL DEFAULT '',
                company VARCHAR(150) NOT NULL DEFAULT '', data $long NOT NULL, items $long NULL, ip_hash CHAR(64) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)$end",
            "CREATE TABLE IF NOT EXISTS messages (id $id, request_id INT NOT NULL, direction VARCHAR(10) NOT NULL, user_id INT NULL,
                from_addr VARCHAR(254) NOT NULL DEFAULT '', to_addr VARCHAR(500) NOT NULL DEFAULT '', subject VARCHAR(300) NOT NULL DEFAULT '',
                body_text $long NOT NULL, body_html $long NULL, message_id VARCHAR(255) NOT NULL DEFAULT '', sent_ok TINYINT NOT NULL DEFAULT 1,
                error VARCHAR(500) NOT NULL DEFAULT '', created_at DATETIME NOT NULL)$end",
            "CREATE TABLE IF NOT EXISTS activity (id $id, user_id INT NULL, action VARCHAR(60) NOT NULL, target VARCHAR(120) NOT NULL DEFAULT '',
                detail VARCHAR(500) NOT NULL DEFAULT '', ip_hash CHAR(64) NOT NULL DEFAULT '', created_at DATETIME NOT NULL)$end",
            "CREATE TABLE IF NOT EXISTS state (k VARCHAR(60) NOT NULL PRIMARY KEY, v VARCHAR(500) NOT NULL DEFAULT '')$end",
            "CREATE TABLE IF NOT EXISTS settings (k VARCHAR(60) NOT NULL PRIMARY KEY, v $long NOT NULL)$end",
            "CREATE TABLE IF NOT EXISTS quotes (id $id, request_id INT NOT NULL, rev INT NOT NULL DEFAULT 1, status VARCHAR(10) NOT NULL DEFAULT 'bozza',
                data $long NOT NULL, net BIGINT NOT NULL DEFAULT 0, vat BIGINT NOT NULL DEFAULT 0, total BIGINT NOT NULL DEFAULT 0,
                created_by INT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, sent_at DATETIME NULL, pdf_file VARCHAR(120) NOT NULL DEFAULT '')$end",
            "CREATE TABLE IF NOT EXISTS orders (id $id, quote_id INT NOT NULL UNIQUE, request_id INT NOT NULL, ref VARCHAR(20) NOT NULL UNIQUE,
                status VARCHAR(20) NOT NULL DEFAULT 'confermato', total BIGINT NOT NULL DEFAULT 0, note $long NULL,
                created_by INT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, updated_by INT NULL)$end",
            "CREATE TABLE IF NOT EXISTS content (id $id, kind VARCHAR(20) NOT NULL, item_id VARCHAR(80) NOT NULL, draft $long NOT NULL, published $long NULL,
                deleted TINYINT NOT NULL DEFAULT 0, meta $long NULL, updated_by INT NULL, updated_at DATETIME NOT NULL, UNIQUE (kind, item_id))$end",
            "CREATE TABLE IF NOT EXISTS hits (id $id, day DATE NOT NULL, at DATETIME NOT NULL, visitor VARCHAR(32) NOT NULL, path VARCHAR(200) NOT NULL,
                ref_kind VARCHAR(12) NOT NULL, ref VARCHAR(120) NOT NULL, device VARCHAR(12) NOT NULL, browser VARCHAR(12) NOT NULL, country CHAR(2) NULL)$end",
            "CREATE TABLE IF NOT EXISTS throttle (id $id, bucket VARCHAR(120) NOT NULL, created_at DATETIME NOT NULL)$end",
        ];
        foreach ($tables as $sql) {
            $pdo->exec($sql);
        }
        foreach (['requests_created' => 'requests (created_at)', 'messages_request' => 'messages (request_id)',
            'throttle_bucket' => 'throttle (bucket, created_at)', 'quotes_request' => 'quotes (request_id)', 'activity_created' => 'activity (created_at)'] as $name => $on) {
            try {
                $pdo->exec("CREATE INDEX $name ON $on");
            } catch (\PDOException) {
                // l'indice esiste già
            }
        }
    }

    public static function state(string $key, string $default = ''): string
    {
        $st = self::db()->prepare('SELECT v FROM state WHERE k = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : (string) $v;
    }

    public static function setState(string $key, string $value): void
    {
        $pdo = self::db();
        $st = $pdo->prepare('UPDATE state SET v = ? WHERE k = ?');
        $st->execute([mb_substr($value, 0, 500), $key]);
        if ($st->rowCount() === 0) {
            try {
                $pdo->prepare('INSERT INTO state (k, v) VALUES (?, ?)')->execute([$key, mb_substr($value, 0, 500)]);
            } catch (\PDOException) {
                // un altro processo l'ha inserita un attimo prima: basta riprovare l'aggiornamento
                $pdo->prepare('UPDATE state SET v = ? WHERE k = ?')->execute([mb_substr($value, 0, 500), $key]);
            }
        }
    }

    public static function setting(string $key, string $default = ''): string
    {
        $st = self::db()->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : (string) $v;
    }

    public static function setSetting(string $key, string $value): void
    {
        $pdo = self::db();
        $st = $pdo->prepare('UPDATE settings SET v = ? WHERE k = ?');
        $st->execute([$value, $key]);
        if ($st->rowCount() === 0) {
            try {
                $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?)')->execute([$key, $value]);
            } catch (\PDOException) {
                $pdo->prepare('UPDATE settings SET v = ? WHERE k = ?')->execute([$value, $key]);
            }
        }
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** L'indirizzo IP non si salva mai in chiaro: solo un'impronta, per limitare gli abusi. */
    public static function ipHash(): string
    {
        return hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? 'cli', (string) self::config('secret', ''));
    }

    public static function log(?int $userId, string $action, string $target = '', string $detail = ''): void
    {
        self::db()->prepare('INSERT INTO activity (user_id, action, target, detail, ip_hash, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$userId, $action, mb_substr($target, 0, 120), mb_substr($detail, 0, 500), self::ipHash(), self::now()]);
    }

    /**
     * Limite di tentativi: vero se nel periodo ci sono meno di $max eventi per $bucket (e registra il nuovo).
     * Con $record = false controlla soltanto.
     */
    public static function allow(string $bucket, int $max, int $seconds, bool $record = true): bool
    {
        $pdo = self::db();
        $since = date('Y-m-d H:i:s', time() - $seconds);
        if (random_int(1, 50) === 1) {
            $pdo->prepare('DELETE FROM throttle WHERE created_at < ?')->execute([date('Y-m-d H:i:s', time() - 172800)]);
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM throttle WHERE bucket = ? AND created_at >= ?');
        $st->execute([$bucket, $since]);
        if ((int) $st->fetchColumn() >= $max) {
            return false;
        }
        if ($record) {
            $pdo->prepare('INSERT INTO throttle (bucket, created_at) VALUES (?,?)')->execute([$bucket, self::now()]);
        }
        return true;
    }

    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
