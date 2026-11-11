<?php
declare(strict_types=1);

namespace Backoffice;

use PDO;

/** Configurazione, database e funzioni comuni. Nessun framework: PHP semplice, facile da installare su cPanel. */
final class App
{
    private static ?PDO $pdo = null;
    private static array $config = [];

    public static function boot(array $config): void
    {
        self::$config = $config;
        self::$pdo = null;
        date_default_timezone_set('Europe/Rome');
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

    public static function db(): PDO
    {
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
