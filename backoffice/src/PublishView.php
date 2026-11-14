<?php
declare(strict_types=1);

namespace Backoffice;

/** Schermata «Pubblica»: cosa è in attesa, stato dell'ultima costruzione del sito, pulsante con conferma. */
final class PublishView
{
    /** @param array<string,int> $pending */
    public static function page(array $pending, ?GitHub $gh, string $csrf, ?array $run = null): string
    {
        $e = App::e(...);
        if ($gh === null) {
            return '<section class="card"><h2>La pubblicazione non è ancora collegata a GitHub</h2>'
                . '<p>Per pubblicare dal backoffice serve una chiave di GitHub con permessi minimi, scritta nel file <code>config.php</code> sul server (mai in chat o nel repository):</p>'
                . '<pre>\'github\' => [\'repo\' => \'proprietario/nome-repository\', \'branch\' => \'main\', \'token\' => \'…\'],</pre>'
                . '<p class="meta">I passi per crearla sono in <code>docs/pubblicazione-ftp.md</code>.</p></section>';
        }
        $h = '';
        if ($run !== null) {
            $label = ['in_corso' => 'in corso', 'ok' => 'riuscita', 'errore' => 'non riuscita'][$run['stato']] ?? $run['stato'];
            $h .= '<p class="preview-notice">Ultima costruzione del sito: <strong>' . $e($label) . '</strong>'
                . ($run['url'] !== '' ? ' · <a href="' . $e($run['url']) . '" target="_blank" rel="noopener">dettagli su GitHub</a>' : '') . '</p>';
        }
        if (!$pending) {
            return $h . '<section class="card"><h2>Tutto il sito è aggiornato</h2><p class="meta">Non ci sono modifiche in bozza. Quando correggi testi, fiere, galleria o video, compaiono qui.</p></section>';
        }
        $h .= '<section class="card"><h2>Modifiche in attesa</h2><table class="list"><tr><th>Sezione</th><th class="num">Voci cambiate</th></tr>';
        foreach ($pending as $section => $n) {
            $h .= '<tr><td>' . $e($section) . '</td><td class="num">' . $n . '</td></tr>';
        }
        $h .= '</table><p class="meta">Le modifiche restano in bozza finché non pubblichi. Per scartarne una, usa «Annulla» nella sezione, prima di pubblicare.</p></section>'
            . '<details class="card"><summary>Pubblica sul sito</summary><form method="post" action="index.php?p=publish">'
            . '<input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="action" value="publish">'
            . '<p>Le modifiche vanno nel repository <code>' . $e($gh->repo()) . '</code> (ramo <code>' . $e($gh->branch()) . '</code>); il sito si ricostruisce da solo e si aggiorna entro pochi minuti.</p>'
            . '<button class="btn">Sì, pubblica ora</button></form></details>';
        return $h;
    }
}
