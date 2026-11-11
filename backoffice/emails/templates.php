<?php
/**
 * Modelli e-mail (contatto e preventivo; al cliente e ad ARNOplast).
 *
 * Regole per la posta: tabelle e stili in linea, larghezza 600 px, nessuno script, nessun font esterno,
 * una sola immagine (logo PNG) con testo alternativo. Ogni valore inserito passa da e() (nessun HTML
 * dell'utente arriva nell'e-mail). Ogni modello restituisce oggetto, HTML e testo semplice.
 */
declare(strict_types=1);

namespace Backoffice\Emails;

const RED = '#e30713';
const BLACK = '#0b0b0c';
const INK = '#141416';
const MUTED = '#5c5c63';
const LINE = '#e4e4e7';
const PAPER = '#f4f4f2';
const FONT = "-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif";

/** Dati dell'azienda nel piè dell'e-mail (gli stessi del piè di pagina del sito). */
const COMPANY = [
    'name' => 'ARNO PLAST S.r.l.',
    'address' => "Via Comunale dell'Isola 65/B/C/D, 50067 Rignano sull'Arno (FI) - ITALY",
    'phone' => '+39 055 83 47 031',
    'email' => 'info@arnoplast.it',
    'vat' => 'P.IVA 01987190483',
];

const TEXTS = [
    'it' => [
        'hello' => 'Gentile %s,',
        'contact_subject' => 'Abbiamo ricevuto la tua richiesta',
        'contact_title' => 'Richiesta ricevuta',
        'contact_intro' => 'grazie per averci scritto. Abbiamo ricevuto la tua richiesta e ti risponderemo il prima possibile.',
        'quote_subject' => 'Abbiamo ricevuto la tua richiesta di preventivo',
        'quote_title' => 'Richiesta di preventivo ricevuta',
        'quote_intro' => 'grazie per la tua richiesta di preventivo. L\'abbiamo ricevuta e ti risponderemo il prima possibile.',
        'summary' => 'Riepilogo della richiesta',
        'your_message' => 'Il tuo messaggio',
        'notes' => 'Note',
        'items' => 'Articoli richiesti',
        'code' => 'Codice', 'description' => 'Descrizione', 'qty' => 'Q.tà', 'pack' => 'conf. da %d',
        'reference' => 'Riferimento',
        'reply_hint' => 'Per aggiungere informazioni puoi rispondere a questa e-mail.',
        'site' => 'Vai al sito',
        'why' => 'Ricevi questa e-mail perché hai inviato una richiesta dal sito www.arnoplast.it.',
        'reply_title' => 'La nostra risposta',
        'reply_subject' => 'Risposta alla tua richiesta',
        'reply_continue' => 'Per continuare la conversazione rispondi a questa e-mail: la nostra casella la riceve insieme al riferimento.',
        'regards' => 'Cordiali saluti',
        'name' => 'Nome', 'company' => 'Azienda', 'email' => 'Email', 'phone' => 'Telefono',
        'country' => 'Paese', 'type' => 'Tipologia', 'address' => 'Indirizzo', 'contact' => 'Referente',
    ],
    'en' => [
        'hello' => 'Dear %s,',
        'contact_subject' => 'We have received your request',
        'contact_title' => 'Request received',
        'contact_intro' => 'thank you for contacting us. We have received your request and will reply as soon as possible.',
        'quote_subject' => 'We have received your quote request',
        'quote_title' => 'Quote request received',
        'quote_intro' => 'thank you for your quote request. We have received it and will reply as soon as possible.',
        'summary' => 'Summary of your request',
        'your_message' => 'Your message',
        'notes' => 'Notes',
        'items' => 'Requested items',
        'code' => 'Code', 'description' => 'Description', 'qty' => 'Qty', 'pack' => 'pack of %d',
        'reference' => 'Reference',
        'reply_hint' => 'To add information, simply reply to this email.',
        'site' => 'Visit the website',
        'why' => 'You are receiving this email because you sent a request from www.arnoplast.it.',
        'reply_title' => 'Our reply',
        'reply_subject' => 'Reply to your request',
        'reply_continue' => 'To continue the conversation, simply reply to this email: our mailbox receives it together with the reference.',
        'regards' => 'Kind regards',
        'name' => 'Name', 'company' => 'Company', 'email' => 'Email', 'phone' => 'Phone',
        'country' => 'Country', 'type' => 'Customer type', 'address' => 'Address', 'contact' => 'Contact person',
    ],
];

function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function t(string $lang, string $key): string
{
    return TEXTS[$lang === 'en' ? 'en' : 'it'][$key];
}

/** Una riga su una sola linea: negli oggetti e nei campi brevi non devono entrare a-capo. */
function line(mixed $v): string
{
    return trim((string) preg_replace('/[\r\n\t]+/', ' ', (string) $v));
}

/** Tabella "etichetta: valore"; le righe vuote si saltano. */
function rows(array $pairs): string
{
    $out = '';
    foreach ($pairs as $label => $value) {
        $value = line($value);
        if ($value === '') {
            continue;
        }
        $out .= '<tr><td style="padding:7px 12px 7px 0;font:13px/1.4 ' . FONT . ';color:' . MUTED . ';white-space:nowrap;vertical-align:top;border-bottom:1px solid ' . LINE . '">' . e($label) . '</td>'
            . '<td style="padding:7px 0;font:15px/1.4 ' . FONT . ';color:' . INK . ';vertical-align:top;border-bottom:1px solid ' . LINE . ';word-break:break-word">' . e($value) . '</td></tr>';
    }
    return $out === '' ? '' : '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse">' . $out . '</table>';
}

function heading(string $text): string
{
    return '<p style="margin:28px 0 8px;font:700 12px/1.3 ' . FONT . ';letter-spacing:1.5px;text-transform:uppercase;color:' . RED . '">' . e($text) . '</p>';
}

function paragraph(string $text): string
{
    return '<p style="margin:0 0 14px;font:16px/1.55 ' . FONT . ';color:' . INK . '">' . nl2br(e($text)) . '</p>';
}

/** Testo libero dell'utente (messaggio, note) in un riquadro. */
function quoteBox(string $text): string
{
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:14px 16px;background:' . PAPER . ';border-left:4px solid ' . RED . ';font:15px/1.55 ' . FONT . ';color:' . INK . ';word-break:break-word">'
        . nl2br(e(trim($text))) . '</td></tr></table>';
}

/** Tabella degli articoli del preventivo. Voce: code, description, qty, conf (facoltativo), product (facoltativo). */
function itemsTable(array $items, string $lang): string
{
    $th = 'padding:8px 10px;font:700 11px/1.3 ' . FONT . ';letter-spacing:1px;text-transform:uppercase;color:#ffffff;background:' . BLACK . ';text-align:left';
    $td = 'padding:10px;font:14px/1.4 ' . FONT . ';color:' . INK . ';vertical-align:top;border-bottom:1px solid ' . LINE;
    $out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse">'
        . '<tr><th style="' . $th . '">' . e(t($lang, 'code')) . '</th><th style="' . $th . '">' . e(t($lang, 'description')) . '</th><th style="' . $th . ';text-align:right">' . e(t($lang, 'qty')) . '</th></tr>';
    foreach ($items as $i) {
        $sub = [];
        if (!empty($i['product'])) {
            $sub[] = line($i['product']);
        }
        if (!empty($i['conf'])) {
            $sub[] = sprintf(t($lang, 'pack'), (int) $i['conf']);
        }
        $out .= '<tr><td style="' . $td . ';font-family:Menlo,Consolas,monospace;white-space:nowrap">' . e(line($i['code'] ?? '')) . '</td>'
            . '<td style="' . $td . ';word-break:break-word">' . e(line($i['description'] ?? ''))
            . ($sub ? '<br><span style="font-size:12px;color:' . MUTED . '">' . e(implode(' · ', $sub)) . '</span>' : '') . '</td>'
            . '<td style="' . $td . ';text-align:right;font-weight:700;white-space:nowrap">' . e((string) (int) ($i['qty'] ?? 0)) . '</td></tr>';
    }
    return $out . '</table>';
}

function button(string $label, string $url): string
{
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:26px 0 4px"><tr><td style="background:' . RED . ';border-radius:4px">'
        . '<a href="' . e($url) . '" style="display:inline-block;padding:13px 24px;font:700 13px/1 ' . FONT . ';letter-spacing:1.5px;text-transform:uppercase;color:#ffffff;text-decoration:none">' . e($label) . ' &rarr;</a></td></tr></table>';
}

/**
 * Impaginazione comune. $o: lang, title, preheader (anteprima nella lista dei messaggi), body (HTML già
 * composto con le funzioni qui sopra), site_url, footer_note.
 */
function layout(array $o): string
{
    $lang = ($o['lang'] ?? 'it') === 'en' ? 'en' : 'it';
    $site = rtrim($o['site_url'] ?? 'https://www.arnoplast.it', '/');
    $c = COMPANY;
    return '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="light only"><title>' . e($o['title']) . '</title></head>'
        . '<body style="margin:0;padding:0;background:' . PAPER . '">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">' . e($o['preheader'] ?? '') . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . PAPER . '"><tr><td align="center" style="padding:24px 12px">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff">'
        . '<tr><td style="background:' . BLACK . ';padding:22px 28px"><a href="' . e($site) . '/' . $lang . '/" style="text-decoration:none">'
        . '<img src="' . e($site) . '/static/img/email/logo.png" width="180" height="33" alt="ARNOplast" style="display:block;border:0;font:700 22px ' . FONT . ';color:#ffffff"></a></td></tr>'
        . '<tr><td style="background:' . RED . ';height:4px;font-size:0;line-height:0">&nbsp;</td></tr>'
        . '<tr><td style="padding:32px 28px 30px">'
        . '<h1 style="margin:0 0 18px;font:800 26px/1.15 ' . FONT . ';letter-spacing:-.3px;text-transform:uppercase;color:' . BLACK . '">' . e($o['title']) . '</h1>'
        . $o['body']
        . '</td></tr>'
        . '<tr><td style="background:' . BLACK . ';padding:24px 28px;font:13px/1.6 ' . FONT . ';color:#b9b9c0">'
        . '<strong style="color:#ffffff">' . e($c['name']) . '</strong><br>' . e($c['address']) . '<br>'
        . '<a href="tel:' . e(str_replace(' ', '', $c['phone'])) . '" style="color:#ffffff;text-decoration:underline">' . e($c['phone']) . '</a> &nbsp;·&nbsp; '
        . '<a href="mailto:' . e($c['email']) . '" style="color:#ffffff;text-decoration:underline">' . e($c['email']) . '</a><br>' . e($c['vat'])
        . (empty($o['footer_note']) ? '' : '<br><br><span style="font-size:12px;color:#8d8d95">' . e($o['footer_note']) . '</span>')
        . '</td></tr></table></td></tr></table></body></html>';
}

/** Versione in testo semplice: stessa informazione, per i programmi che non mostrano l'HTML. */
function plain(string $title, array $blocks): string
{
    return $title . "\n" . str_repeat('=', mb_strlen($title)) . "\n\n" . implode("\n\n", array_filter(array_map('trim', $blocks))) . "\n\n-- \n"
        . COMPANY['name'] . "\n" . COMPANY['address'] . "\n" . COMPANY['phone'] . ' · ' . COMPANY['email'] . "\n";
}

function plainRows(array $pairs): string
{
    $out = [];
    foreach ($pairs as $label => $value) {
        if (line($value) !== '') {
            $out[] = $label . ': ' . line($value);
        }
    }
    return implode("\n", $out);
}

function plainItems(array $items, string $lang): string
{
    $out = [];
    foreach ($items as $i) {
        $row = line($i['code'] ?? '') . ' | ' . line($i['description'] ?? '') . ' | ' . t($lang, 'qty') . ' ' . (int) ($i['qty'] ?? 0);
        if (!empty($i['conf'])) {
            $row .= ' (' . sprintf(t($lang, 'pack'), (int) $i['conf']) . ')';
        }
        if (!empty($i['product'])) {
            $row .= ' - ' . line($i['product']);
        }
        $out[] = $row;
    }
    return implode("\n", $out);
}

/** Campi del modulo contatti nell'ordine in cui si mostrano. */
function contactPairs(array $d, string $lang): array
{
    $address = implode(', ', array_filter(array_map(fn ($k) => line($d[$k] ?? ''), ['address', 'zip', 'city'])));
    return [
        t($lang, 'name') => trim(line($d['name'] ?? '') . ' ' . line($d['surname'] ?? '')),
        t($lang, 'company') => $d['company'] ?? '',
        t($lang, 'contact') => $d['contact'] ?? '',
        t($lang, 'email') => $d['email'] ?? '',
        t($lang, 'phone') => $d['phone'] ?? '',
        t($lang, 'type') => $d['type'] ?? '',
        t($lang, 'address') => $address === '' ? '' : $address . (line($d['country'] ?? '') === '' ? '' : ', ' . line($d['country'])),
    ];
}

function quotePairs(array $d, string $lang): array
{
    return [
        t($lang, 'name') => $d['name'] ?? '',
        t($lang, 'company') => $d['company'] ?? '',
        t($lang, 'email') => $d['email'] ?? '',
        t($lang, 'phone') => $d['phone'] ?? '',
        t($lang, 'country') => $d['country'] ?? '',
    ];
}

/** 1. Contatto: conferma al cliente (nella lingua della pagina da cui ha scritto). */
function contactCustomer(array $d, string $ref, string $lang = 'it', string $site = 'https://www.arnoplast.it'): array
{
    $name = trim(line($d['name'] ?? '') . ' ' . line($d['surname'] ?? ''));
    $hello = sprintf(t($lang, 'hello'), $name);
    $body = paragraph($hello) . paragraph(t($lang, 'contact_intro'))
        . heading(t($lang, 'your_message')) . quoteBox($d['message'] ?? '')
        . heading(t($lang, 'summary')) . rows([t($lang, 'reference') => $ref] + contactPairs($d, $lang))
        . '<p style="margin:22px 0 0;font:14px/1.5 ' . FONT . ';color:' . MUTED . '">' . e(t($lang, 'reply_hint')) . '</p>'
        . button(t($lang, 'site'), rtrim($site, '/') . '/' . ($lang === 'en' ? 'en' : 'it') . '/');
    return [
        'subject' => t($lang, 'contact_subject') . ' [' . $ref . ']',
        'html' => layout(['lang' => $lang, 'title' => t($lang, 'contact_title'), 'preheader' => t($lang, 'contact_intro'), 'body' => $body, 'site_url' => $site, 'footer_note' => t($lang, 'why')]),
        'text' => plain(t($lang, 'contact_title'), [$hello, t($lang, 'contact_intro'), t($lang, 'your_message') . ":\n" . trim((string) ($d['message'] ?? '')),
            plainRows([t($lang, 'reference') => $ref] + contactPairs($d, $lang)), t($lang, 'reply_hint')]),
    ];
}

/** 2. Contatto: notifica ad ARNOplast (sempre in italiano), con il link alla conversazione nel backoffice. */
function contactStaff(array $d, string $ref, string $adminUrl, string $pageLang = 'it', string $site = 'https://www.arnoplast.it'): array
{
    $name = trim(line($d['name'] ?? '') . ' ' . line($d['surname'] ?? ''));
    $pairs = ['Riferimento' => $ref, 'Lingua della pagina' => $pageLang === 'en' ? 'Inglese' : 'Italiano'] + contactPairs($d, 'it');
    $body = paragraph('Nuova richiesta di contatto dal sito, da ' . $name . '.')
        . heading('Messaggio') . quoteBox($d['message'] ?? '')
        . heading('Dati del cliente') . rows($pairs)
        . button('Apri nel backoffice', $adminUrl);
    return [
        'subject' => 'Nuova richiesta di contatto: ' . $name . ' [' . $ref . ']',
        'html' => layout(['lang' => 'it', 'title' => 'Nuova richiesta di contatto', 'preheader' => $name . ': ' . mb_substr(line($d['message'] ?? ''), 0, 90), 'body' => $body, 'site_url' => $site,
            'footer_note' => 'Notifica automatica del sito. Rispondi dal backoffice per tenere tutta la conversazione in un solo posto.']),
        'text' => plain('Nuova richiesta di contatto', ["Messaggio:\n" . trim((string) ($d['message'] ?? '')), plainRows($pairs), 'Apri nel backoffice: ' . $adminUrl]),
    ];
}

/** 3. Preventivo: conferma al cliente. */
function quoteCustomer(array $d, array $items, string $ref, string $lang = 'it', string $site = 'https://www.arnoplast.it'): array
{
    $hello = sprintf(t($lang, 'hello'), line($d['name'] ?? ''));
    $notes = trim((string) ($d['notes'] ?? ''));
    $body = paragraph($hello) . paragraph(t($lang, 'quote_intro'))
        . heading(t($lang, 'items') . ' (' . count($items) . ')') . itemsTable($items, $lang)
        . ($notes === '' ? '' : heading(t($lang, 'notes')) . quoteBox($notes))
        . heading(t($lang, 'summary')) . rows([t($lang, 'reference') => $ref] + quotePairs($d, $lang))
        . '<p style="margin:22px 0 0;font:14px/1.5 ' . FONT . ';color:' . MUTED . '">' . e(t($lang, 'reply_hint')) . '</p>'
        . button(t($lang, 'site'), rtrim($site, '/') . '/' . ($lang === 'en' ? 'en' : 'it') . '/');
    return [
        'subject' => t($lang, 'quote_subject') . ' [' . $ref . ']',
        'html' => layout(['lang' => $lang, 'title' => t($lang, 'quote_title'), 'preheader' => t($lang, 'quote_intro'), 'body' => $body, 'site_url' => $site, 'footer_note' => t($lang, 'why')]),
        'text' => plain(t($lang, 'quote_title'), [$hello, t($lang, 'quote_intro'), t($lang, 'items') . ":\n" . plainItems($items, $lang),
            $notes === '' ? '' : t($lang, 'notes') . ":\n" . $notes, plainRows([t($lang, 'reference') => $ref] + quotePairs($d, $lang)), t($lang, 'reply_hint')]),
    ];
}

/** 4. Preventivo: notifica ad ARNOplast (sempre in italiano). */
function quoteStaff(array $d, array $items, string $ref, string $adminUrl, string $pageLang = 'it', string $site = 'https://www.arnoplast.it'): array
{
    $name = line($d['name'] ?? '');
    $notes = trim((string) ($d['notes'] ?? ''));
    $pairs = ['Riferimento' => $ref, 'Lingua della pagina' => $pageLang === 'en' ? 'Inglese' : 'Italiano'] + quotePairs($d, 'it');
    $body = paragraph('Nuova richiesta di preventivo dal sito, da ' . $name . (line($d['company'] ?? '') === '' ? '' : ' (' . line($d['company']) . ')') . '.')
        . heading('Articoli richiesti (' . count($items) . ')') . itemsTable($items, 'it')
        . ($notes === '' ? '' : heading('Note del cliente') . quoteBox($notes))
        . heading('Dati del cliente') . rows($pairs)
        . button('Apri nel backoffice', $adminUrl);
    return [
        'subject' => 'Nuova richiesta di preventivo: ' . $name . ' [' . $ref . ']',
        'html' => layout(['lang' => 'it', 'title' => 'Nuova richiesta di preventivo', 'preheader' => $name . ': ' . count($items) . ' articoli', 'body' => $body, 'site_url' => $site,
            'footer_note' => 'Notifica automatica del sito. Rispondi dal backoffice per tenere tutta la conversazione in un solo posto.']),
        'text' => plain('Nuova richiesta di preventivo', ["Articoli:\n" . plainItems($items, 'it'), $notes === '' ? '' : "Note:\n" . $notes, plainRows($pairs), 'Apri nel backoffice: ' . $adminUrl]),
    ];
}

/** 5. Risposta di ARNOplast al cliente, scritta dal backoffice (nella lingua della richiesta). */
function reply(string $text, string $customerName, string $ref, string $lang, string $signer, string $subject, string $site = 'https://www.arnoplast.it'): array
{
    $hello = sprintf(t($lang, 'hello'), line($customerName));
    $sign = line($signer) === '' ? 'ARNOplast' : line($signer) . ' - ARNOplast';
    $paragraphs = '';
    foreach (preg_split("/\n{2,}/", trim($text)) ?: [] as $p) {
        $paragraphs .= paragraph($p);
    }
    $body = paragraph($hello) . $paragraphs . paragraph(t($lang, 'regards') . ",\n" . $sign)
        . '<p style="margin:26px 0 0;padding-top:14px;border-top:1px solid ' . LINE . ';font:13px/1.5 ' . FONT . ';color:' . MUTED . '">'
        . e(t($lang, 'reference')) . ': <strong>' . e($ref) . '</strong><br>' . e(t($lang, 'reply_continue')) . '</p>';
    return [
        'subject' => $subject,
        'html' => layout(['lang' => $lang, 'title' => t($lang, 'reply_title'), 'preheader' => mb_substr(line($text), 0, 110), 'body' => $body, 'site_url' => $site, 'footer_note' => t($lang, 'why')]),
        'text' => plain(t($lang, 'reply_title'), [$hello, trim($text), t($lang, 'regards') . ",\n" . $sign, t($lang, 'reference') . ': ' . $ref . "\n" . t($lang, 'reply_continue')]),
    ];
}

/** 6. Notifica ad ARNOplast: il cliente ha risposto (sempre in italiano). */
function customerReplyNotice(string $customerName, string $text, string $ref, string $adminUrl, int $attachments = 0, string $site = 'https://www.arnoplast.it'): array
{
    $name = line($customerName);
    $body = paragraph($name . ' ha risposto alla richiesta ' . $ref . '.') . quoteBox($text)
        . ($attachments > 0 ? paragraph('Il messaggio contiene ' . $attachments . ($attachments === 1 ? ' allegato' : ' allegati') . ': non vengono copiati nel backoffice, aprili dalla casella richieste@arnoplast.it.') : '')
        . button('Apri la conversazione', $adminUrl);
    return [
        'subject' => 'Risposta del cliente: ' . $name . ' [' . $ref . ']',
        'html' => layout(['lang' => 'it', 'title' => 'Risposta del cliente', 'preheader' => $name . ': ' . mb_substr(line($text), 0, 90), 'body' => $body, 'site_url' => $site,
            'footer_note' => 'Notifica automatica. Rispondi dal backoffice per tenere tutta la conversazione in un solo posto.']),
        'text' => plain('Risposta del cliente', [$name . ' ha risposto alla richiesta ' . $ref . ".\n\n" . trim($text), $attachments > 0 ? "Allegati nel messaggio: $attachments (aprili dalla casella richieste@arnoplast.it)." : '', 'Apri la conversazione: ' . $adminUrl]),
    ];
}
