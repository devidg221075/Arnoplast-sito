<?php
declare(strict_types=1);

namespace Backoffice;

/** Mostra CHANGELOG.md dentro il backoffice: titoli, elenchi e grassetto, tutto con testo escapato. */
final class Changelog
{
    public static function file(): string
    {
        return dirname(__DIR__) . '/CHANGELOG.md';
    }

    public static function html(?string $markdown = null): string
    {
        $md = $markdown ?? (is_file(self::file()) ? (string) file_get_contents(self::file()) : '');
        if (trim($md) === '') {
            return '<p class="empty">Nessuna nota di versione disponibile.</p>';
        }
        $inline = static fn (string $t): string => (string) preg_replace(['/\*\*(.+?)\*\*/u', '/`([^`]+)`/u'], ['<strong>$1</strong>', '<code>$1</code>'], App::e($t));
        $out = '';
        $open = false;
        foreach (preg_split('/\R/', $md) ?: [] as $line) {
            $line = rtrim($line);
            if (preg_match('/^- (.*)$/', $line, $m)) {
                $out .= ($open ? '' : '<ul>') . '<li>' . $inline($m[1]) . '</li>';
                $open = true;
                continue;
            }
            if ($open) {
                $out .= '</ul>';
                $open = false;
            }
            if (preg_match('/^# /', $line) || $line === '') {
                continue;
            }
            if (preg_match('/^## (.*)$/', $line, $m)) {
                $out .= '<h2>' . $inline($m[1]) . '</h2>';
            } elseif (preg_match('/^### (.*)$/', $line, $m)) {
                $out .= '<h3>' . $inline($m[1]) . '</h3>';
            } else {
                $out .= '<p class="meta">' . $inline($line) . '</p>';
            }
        }
        return $out . ($open ? '</ul>' : '');
    }
}
