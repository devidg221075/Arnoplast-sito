<?php
declare(strict_types=1);

namespace Backoffice;

/** Parte della scheda richiesta dedicata al preventivo: elenco versioni, modulo con i prezzi, conferma di invio. */
final class QuoteView
{
    private const EXTRA_ROWS = 3;

    /**
     * @param array  $r      richiesta
     * @param ?array $raw    valori già digitati (dopo un errore di compilazione), come arrivano dal modulo
     * @return string        HTML della sezione
     */
    public static function render(array $r, string $self, string $csrf, ?array $raw, ?int $editId, ?int $confirmId): string
    {
        $e = App::e(...);
        $lang = $r['lang'] === 'en' ? 'en' : 'it';
        $quotes = Quote::forRequest((int) $r['id']);
        $html = '<h2 id="preventivo">Preventivo con prezzi</h2>';

        if ($confirmId !== null && ($q = self::own($quotes, $confirmId)) && $q['status'] === 'bozza') {
            return $html . self::confirm($r, $q, $self, $csrf, $lang);
        }
        if ($quotes) {
            $html .= '<table class="list"><tr><th>Versione</th><th>Stato</th><th class="num">Totale</th><th>Data</th><th></th></tr>';
            foreach ($quotes as $q) {
                $sent = in_array($q['status'], ['inviato', 'accettato'], true);
                $html .= '<tr><td>' . $e(Quote::number($r, $q)) . '</td><td><span class="status ' . ($sent ? 'chiusa' : 'todo') . '">' . ($q['status'] === 'accettato' ? 'Accettato' : ($sent ? 'Inviato' : 'Bozza')) . '</span></td>'
                    . '<td class="num">' . $e(Quote::money((int) $q['total'], 'it')) . '</td><td>' . $e(date('d/m/Y H:i', strtotime((string) ($q['sent_at'] ?: $q['updated_at'])))) . '</td><td>'
                    . '<a href="index.php?p=quote_pdf&amp;id=' . (int) $q['id'] . '" target="_blank" rel="noopener">' . ($sent ? 'Apri il PDF inviato' : 'Anteprima PDF') . '</a>'
                    . ($sent ? '' : ' · <a href="' . $e($self . '&q=' . (int) $q['id']) . '#preventivo">Modifica</a>') . '</td></tr>';
            }
            $html .= '</table>';
        }
        // Accettazione: il cliente ha risposto sì al preventivo inviato. Crea l'ordine (una sola volta per richiesta).
        $accepted = array_values(array_filter($quotes, static fn (array $x): bool => $x['status'] === 'accettato'));
        $toAccept = array_values(array_filter($quotes, static fn (array $x): bool => $x['status'] === 'inviato'));
        if ($accepted) {
            $html .= '<p class="flash ok">Preventivo accettato: ordine <strong>' . $e(Orders::number($r)) . '</strong> · <a href="index.php?p=orders">Vai agli ordini</a></p>';
        } elseif ($toAccept) {
            $html .= '<form method="post" action="' . $e($self) . '" class="card narrow"><input type="hidden" name="csrf" value="' . $e($csrf) . '">'
                . '<input type="hidden" name="action" value="quote_accept"><input type="hidden" name="quote_id" value="' . (int) $toAccept[0]['id'] . '">'
                . '<h2>Il cliente ha accettato?</h2><p class="meta">Quando il cliente conferma (per e-mail o telefono), segna qui il preventivo come accettato: nasce l\'ordine.</p>'
                . '<label>Note sulla conferma (facoltative)<textarea name="note" rows="2" maxlength="2000"></textarea></label>'
                . '<button class="btn">Segna come accettato e crea l\'ordine</button></form>';
        }
        $draft = null;
        foreach ($quotes as $q) {
            if ($q['status'] === 'bozza' && ($editId === null || (int) $q['id'] === $editId)) {
                $draft = $q;
                break;
            }
        }
        if ($draft === null) {
            $label = $quotes ? 'Nuova revisione (copia dell\'ultima versione)' : 'Prepara il preventivo';
            return $html . '<form method="post" action="' . $e($self) . '" class="card narrow">' . '<input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="action" value="quote_new">'
                . '<p class="meta">' . ($quotes ? 'Il preventivo inviato non si modifica: si crea una nuova revisione, che manterrà lo stesso numero con «rev. ' . ((int) $quotes[0]['rev'] + 1) . '».'
                    : 'Parte dagli articoli chiesti dal cliente: tu inserisci prezzi, sconti, trasporto, IVA e condizioni.') . '</p><button class="btn">' . $e($label) . '</button></form>';
        }
        return $html . self::form($r, $draft, $self, $csrf, $raw, $lang);
    }

    private static function form(array $r, array $q, string $self, string $csrf, ?array $raw, string $lang): string
    {
        $e = App::e(...);
        $d = Quote::data($q);
        $rows = [];
        if ($raw !== null) {
            foreach ((array) ($raw['code'] ?? []) as $i => $code) {
                $rows[] = [(string) $code, (string) ($raw['description'][$i] ?? ''), (string) ($raw['qty'][$i] ?? ''), (string) ($raw['unit'][$i] ?? ''), (string) ($raw['disc'][$i] ?? '')];
            }
            $v = fn (string $k, string $def = ''): string => (string) ($raw[$k] ?? $def);
        } else {
            foreach ($d['lines'] as $l) {
                $rows[] = [(string) $l['code'], (string) $l['description'], (string) $l['qty'], $l['unit_cents'] > 0 ? number_format($l['unit_cents'] / 100, 2, ',', '') : '',
                    $l['disc'] > 0 ? rtrim(rtrim(number_format($l['disc'], 2, ',', ''), '0'), ',') : ''];
            }
            $map = ['shipping_label' => $d['shipping_label'], 'shipping' => $d['shipping_cents'] > 0 ? number_format($d['shipping_cents'] / 100, 2, ',', '') : '', 'vat_rate' => (string) $d['vat_rate'],
                'vat_note' => $d['vat_note'], 'valid_days' => (string) $d['valid_days'], 'payment' => $d['payment'], 'delivery' => $d['delivery'], 'notes' => $d['notes']];
            $v = fn (string $k, string $def = ''): string => (string) ($map[$k] ?? $def);
        }
        $spare = max(self::EXTRA_ROWS, 0);
        for ($i = 0; $i < $spare && count($rows) < Quote::MAX_LINES; $i++) {
            $rows[] = ['', '', '', '', ''];
        }
        $h = '<form method="post" action="' . $e($self) . '" class="card quote-form">'
            . '<input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="action" value="quote_save"><input type="hidden" name="quote_id" value="' . (int) $q['id'] . '">'
            . '<p class="meta">Bozza <strong>' . $e(Quote::number($r, $q)) . '</strong> · il PDF sarà in ' . ($lang === 'en' ? 'inglese' : 'italiano') . '. Prezzi senza IVA; scrivi gli importi come 12,50. Righe vuote in fondo per aggiungere altri articoli.</p>'
            . '<table class="list qtable"><tr><th>Codice</th><th>Descrizione</th><th class="num">Q.tà</th><th class="num">Prezzo unit.</th><th class="num">Sconto %</th><th class="num">Importo</th></tr>';
        $t = Quote::totals($d);
        foreach ($rows as $i => $row) {
            $h .= '<tr><td><input name="code[]" value="' . $e($row[0]) . '" maxlength="40" aria-label="Codice"></td><td><input name="description[]" value="' . $e($row[1]) . '" maxlength="300" aria-label="Descrizione"></td>'
                . '<td><input name="qty[]" value="' . $e($row[2]) . '" inputmode="numeric" class="n" aria-label="Quantità"></td><td><input name="unit[]" value="' . $e($row[3]) . '" inputmode="decimal" class="n" aria-label="Prezzo unitario"></td>'
                . '<td><input name="disc[]" value="' . $e($row[4]) . '" inputmode="decimal" class="n" aria-label="Sconto"></td><td class="num">' . (isset($t['lines'][$i]) && $raw === null ? $e(Quote::money($t['lines'][$i])) : '') . '</td></tr>';
        }
        $h .= '</table><div class="grid2">'
            . '<label>Trasporto / imballo (descrizione)<input name="shipping_label" value="' . $e($v('shipping_label')) . '" maxlength="80"></label>'
            . '<label>Importo trasporto / imballo (€)<input name="shipping" value="' . $e($v('shipping')) . '" inputmode="decimal"></label>'
            . '<label>Aliquota IVA<select name="vat_rate">';
        foreach (Quote::VAT_RATES as $rate => $label) {
            $h .= '<option value="' . $rate . '"' . ((string) $rate === $v('vat_rate', '22') ? ' selected' : '') . '>' . $e($label) . '</option>';
        }
        $h .= '</select></label><label>Nota sull\'IVA (facoltativa, appare nel PDF)<input name="vat_note" value="' . $e($v('vat_note')) . '" maxlength="200"></label>'
            . '<label>Validità dell\'offerta (giorni)<input name="valid_days" value="' . $e($v('valid_days', '30')) . '" inputmode="numeric"></label>'
            . '<label>Pagamento<input name="payment" value="' . $e($v('payment')) . '" maxlength="200" placeholder="es. bonifico a 30 giorni"></label>'
            . '<label>Consegna<input name="delivery" value="' . $e($v('delivery')) . '" maxlength="200" placeholder="es. 7-10 giorni lavorativi"></label></div>'
            . '<label>Note per il cliente<textarea name="notes" rows="3" maxlength="2000">' . $e($v('notes')) . '</textarea></label>';
        if ($raw === null) {
            $h .= '<table class="kv totals"><tr><th>Imponibile (articoli + trasporto)</th><td>' . $e(Quote::money($t['net'])) . '</td></tr><tr><th>IVA ' . (int) $d['vat_rate'] . '%</th><td>' . $e(Quote::money($t['vat']))
                . '</td></tr><tr><th><strong>Totale</strong></th><td><strong>' . $e(Quote::money($t['total'])) . '</strong></td></tr></table>';
        }
        return $h . '<p><button class="btn" name="do" value="save">Salva bozza</button> <button class="btn dark" name="do" value="send">Salva e vai all\'invio</button> '
            . '<a href="index.php?p=quote_pdf&amp;id=' . (int) $q['id'] . '" target="_blank" rel="noopener">Anteprima PDF (ultima bozza salvata)</a></p></form>';
    }

    private static function confirm(array $r, array $q, string $self, string $csrf, string $lang): string
    {
        $e = App::e(...);
        $d = Quote::data($q);
        $t = Quote::totals($d);
        $zero = count(array_filter($d['lines'], static fn (array $l): bool => $l['unit_cents'] === 0));
        $default = $lang === 'en'
            ? "Please find attached our quotation. We remain at your disposal for any question."
            : "In allegato trovi il nostro preventivo. Restiamo a disposizione per qualsiasi chiarimento.";
        $warn = '';
        if (trim(App::setting('conditions_' . $lang)) === '') {
            $warn .= '<p class="flash">Le condizioni generali in ' . ($lang === 'en' ? 'inglese' : 'italiano') . ' non sono ancora state inserite: il PDF partirebbe senza.</p>';
        }
        if ($zero > 0) {
            $warn .= '<p class="flash">' . $zero . ($zero === 1 ? ' riga ha' : ' righe hanno') . ' prezzo zero. Se è voluto (omaggio) puoi inviare; altrimenti torna alla bozza.</p>';
        }
        return '<section class="card">' . $warn . '<h2>Conferma l\'invio</h2><table class="kv"><tr><th>A</th><td>' . $e($r['name']) . ' &lt;' . $e($r['email']) . '&gt;</td></tr>'
            . '<tr><th>Preventivo</th><td>' . $e(Quote::number($r, $q)) . '</td></tr><tr><th>Totale IVA inclusa</th><td><strong>' . $e(Quote::money($t['total'])) . '</strong> (imponibile ' . $e(Quote::money($t['net'])) . ')</td></tr>'
            . '<tr><th>PDF</th><td><a href="index.php?p=quote_pdf&amp;id=' . (int) $q['id'] . '" target="_blank" rel="noopener">Controlla il PDF prima di inviare</a></td></tr></table>'
            . '<form method="post" action="' . $e($self) . '">' . '<input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="action" value="quote_send"><input type="hidden" name="quote_id" value="' . (int) $q['id'] . '">'
            . '<label>Messaggio che accompagna il PDF (si scrive nell\'e-mail, sotto la frase iniziale)<textarea name="message" rows="5" maxlength="3000">' . $e($default) . '</textarea></label>'
            . '<p><button class="btn">Invia il preventivo al cliente</button> <a href="' . $e($self . '&q=' . (int) $q['id']) . '#preventivo">Torna alla bozza</a></p></form></section>';
    }

    private static function own(array $quotes, int $id): ?array
    {
        foreach ($quotes as $q) {
            if ((int) $q['id'] === $id) {
                return $q;
            }
        }
        return null;
    }
}
