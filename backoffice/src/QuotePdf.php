<?php
declare(strict_types=1);

namespace Backoffice;

/**
 * PDF del preventivo (A4): intestazione ARNOplast, dati del cliente, tabella degli articoli con prezzi e sconti,
 * totali, pagamento/consegna/note e condizioni generali (testo che inserisce il cliente nelle impostazioni).
 * Usa i caratteri standard del PDF (Helvetica): serve solo il set di lettere dell'italiano e dell'inglese.
 */
final class QuotePdf extends \FPDF
{
    private const BLACK = [11, 11, 12];
    private const RED = [227, 7, 19];
    private const GREY = [92, 92, 99];
    private const LINE = [228, 228, 231];

    private const TEXT = [
        'it' => ['title' => 'PREVENTIVO', 'to' => 'Spett.le', 'date' => 'Data', 'valid' => 'Valido fino al', 'number' => 'Numero', 'code' => 'Codice', 'desc' => 'Descrizione',
            'qty' => 'Q.tà', 'unit' => 'Prezzo', 'disc' => 'Sc.%', 'amount' => 'Importo', 'goods' => 'Totale articoli', 'net' => 'Imponibile', 'vat' => 'IVA',
            'total' => 'TOTALE', 'payment' => 'Pagamento', 'delivery' => 'Consegna', 'notes' => 'Note', 'conditions' => 'Condizioni generali', 'page' => 'Pagina', 'of' => 'di',
            'phone' => 'Tel.', 'country' => 'Paese'],
        'en' => ['title' => 'QUOTATION', 'to' => 'To', 'date' => 'Date', 'valid' => 'Valid until', 'number' => 'Number', 'code' => 'Code', 'desc' => 'Description',
            'qty' => 'Qty', 'unit' => 'Price', 'disc' => 'Disc.%', 'amount' => 'Amount', 'goods' => 'Items total', 'net' => 'Taxable amount', 'vat' => 'VAT',
            'total' => 'TOTAL', 'payment' => 'Payment', 'delivery' => 'Delivery', 'notes' => 'Notes', 'conditions' => 'General conditions', 'page' => 'Page', 'of' => 'of',
            'phone' => 'Tel.', 'country' => 'Country'],
    ];

    private array $o = [];
    private string $lang = 'it';

    /** @param array{lang:string, number:string, date:int, customer:array, request:array, data:array, totals:array, conditions:string, logo:string} $o */
    public function render(array $o): string
    {
        $this->o = $o;
        $this->lang = $o['lang'] === 'en' ? 'en' : 'it';
        parent::__construct('P', 'mm', 'A4');
        $this->SetCompression(function_exists('gzcompress')); // senza zlib il PDF resta valido, solo più pesante
        $this->SetMargins(14, 14, 14);
        $this->SetAutoPageBreak(false);
        $this->SetTitle($this->enc($this->tx('title') . ' ' . $o['number']));
        $this->SetAuthor('ARNOplast');
        $this->AliasNbPages();
        $this->AddPage();
        $this->customerBlock();
        $this->itemsTable();
        $this->totalsBlock();
        $this->textBlocks();
        return $this->Output('S');
    }

    public function Header(): void
    {
        $this->SetFillColor(...self::BLACK);
        $this->Rect(0, 0, 210, 26, 'F');
        $this->SetFillColor(...self::RED);
        $this->Rect(0, 26, 210, 1.6, 'F');
        if (is_file($this->o['logo'])) {
            $this->Image($this->o['logo'], 14, 8.5, 52);
        }
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 15);
        $this->SetXY(100, 7);
        $this->Cell(96, 7, $this->enc($this->tx('title')), 0, 2, 'R');
        $this->SetFont('Helvetica', '', 10);
        $this->Cell(96, 6, $this->enc($this->o['number']), 0, 0, 'R');
        $this->SetY(34);
        $this->SetTextColor(...self::BLACK);
    }

    public function Footer(): void
    {
        $c = \Backoffice\Emails\COMPANY;
        $this->SetY(-16);
        $this->SetDrawColor(...self::LINE);
        $this->Line(14, $this->GetY(), 196, $this->GetY());
        $this->SetFont('Helvetica', '', 7.5);
        $this->SetTextColor(...self::GREY);
        $this->Cell(0, 5, $this->enc($c['name'] . ' - ' . $c['address']), 0, 1, 'C');
        $this->Cell(0, 4, $this->enc($c['phone'] . ' - ' . $c['email'] . ' - ' . $c['vat'] . '   |   ' . $this->tx('page') . ' ' . $this->PageNo() . ' ' . $this->tx('of') . ' {nb}'), 0, 0, 'C');
    }

    private function customerBlock(): void
    {
        $c = $this->o['customer'];
        $r = $this->o['request'];
        $y = $this->GetY();
        $this->SetFont('Helvetica', 'B', 8);
        $this->SetTextColor(...self::RED);
        $this->Cell(95, 5, $this->enc(mb_strtoupper($this->tx('to'))), 0, 1);
        $this->SetTextColor(...self::BLACK);
        $this->SetFont('Helvetica', 'B', 11);
        $name = trim((string) ($c['name'] ?? $r['name']) . ' ' . (string) ($c['surname'] ?? ''));
        $this->Cell(95, 5.5, $this->enc($name), 0, 1);
        $this->SetFont('Helvetica', '', 9.5);
        foreach (array_filter([(string) ($c['company'] ?? ''), (string) $r['email'], ($r['phone'] ?? '') !== '' ? $this->tx('phone') . ' ' . $r['phone'] : '',
            trim(implode(', ', array_filter([(string) ($c['address'] ?? ''), trim(($c['zip'] ?? '') . ' ' . ($c['city'] ?? '')), (string) ($c['country'] ?? '')])))]) as $l) {
            $this->Cell(95, 4.8, $this->enc($l), 0, 1);
        }
        $end = $this->GetY();
        $valid = $this->o['date'] + ((int) $this->o['data']['valid_days']) * 86400;
        $this->SetXY(120, $y);
        foreach ([[$this->tx('number'), $this->o['number']], [$this->tx('date'), $this->day($this->o['date'])], [$this->tx('valid'), $this->day($valid)]] as [$k, $v]) {
            $this->SetX(120);
            $this->SetFont('Helvetica', '', 9);
            $this->SetTextColor(...self::GREY);
            $this->Cell(28, 5.5, $this->enc($k), 0, 0);
            $this->SetFont('Helvetica', 'B', 9.5);
            $this->SetTextColor(...self::BLACK);
            $this->Cell(48, 5.5, $this->enc($v), 0, 1);
        }
        $this->SetY(max($end, $this->GetY()) + 6);
    }

    /** Colonne: codice, descrizione, quantità, prezzo, sconto, importo (larghezza totale 182 mm). */
    private const COLS = [26, 70, 14, 26, 14, 32];

    private function tableHeader(): void
    {
        $this->SetFillColor(...self::BLACK);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 8);
        $labels = ['code', 'desc', 'qty', 'unit', 'disc', 'amount'];
        $align = ['L', 'L', 'R', 'R', 'R', 'R'];
        foreach (self::COLS as $i => $w) {
            $this->Cell($w, 7, $this->enc(mb_strtoupper($this->tx($labels[$i]))), 0, 0, $align[$i], true);
        }
        $this->Ln();
        $this->SetTextColor(...self::BLACK);
    }

    private function itemsTable(): void
    {
        $d = $this->o['data'];
        $this->tableHeader();
        $this->SetDrawColor(...self::LINE);
        foreach ($d['lines'] as $i => $l) {
            $this->SetFont('Helvetica', '', 9);
            $descLines = $this->wrap((string) $l['description'], self::COLS[1] - 2);
            $h = max(6.5, count($descLines) * 4.4 + 2.4);
            if ($this->GetY() + $h > 262) {
                $this->AddPage();
                $this->tableHeader();
            }
            $x = $this->GetX();
            $y = $this->GetY();
            $this->SetFont('Courier', '', 8.5);
            $this->SetXY($x, $y + 1.2);
            $this->Cell(self::COLS[0], 4.4, $this->enc((string) $l['code']), 0, 0);
            $this->SetFont('Helvetica', '', 9);
            $this->SetXY($x + self::COLS[0], $y + 1.2);
            $this->MultiCell(self::COLS[1], 4.4, $this->enc(implode("\n", $descLines)), 0, 'L');
            $cx = $x + self::COLS[0] + self::COLS[1];
            $this->SetXY($cx, $y + 1.2);
            $this->Cell(self::COLS[2], 4.4, (string) (int) $l['qty'], 0, 0, 'R');
            $this->Cell(self::COLS[3], 4.4, $this->enc(Quote::money((int) $l['unit_cents'], $this->lang)), 0, 0, 'R');
            $this->Cell(self::COLS[4], 4.4, $l['disc'] > 0 ? $this->enc($this->num((float) $l['disc'])) : '', 0, 0, 'R');
            $this->SetFont('Helvetica', 'B', 9);
            $this->Cell(self::COLS[5], 4.4, $this->enc(Quote::money($this->o['totals']['lines'][$i], $this->lang)), 0, 0, 'R');
            $this->SetY($y + $h);
            $this->Line($x, $y + $h, $x + array_sum(self::COLS), $y + $h);
        }
        if (($d['shipping_cents'] ?? 0) > 0) {
            if ($this->GetY() + 8 > 262) {
                $this->AddPage();
            }
            $this->SetFont('Helvetica', '', 9);
            $y = $this->GetY();
            $this->SetXY(14 + self::COLS[0], $y + 1.2);
            $this->Cell(self::COLS[1], 4.4, $this->enc((string) ($d['shipping_label'] !== '' ? $d['shipping_label'] : ($this->lang === 'en' ? 'Shipping and packing' : 'Trasporto e imballo'))), 0, 0);
            $this->SetXY(14 + array_sum(self::COLS) - self::COLS[5], $y + 1.2);
            $this->SetFont('Helvetica', 'B', 9);
            $this->Cell(self::COLS[5], 4.4, $this->enc(Quote::money((int) $d['shipping_cents'], $this->lang)), 0, 0, 'R');
            $this->SetY($y + 7);
            $this->Line(14, $y + 7, 14 + array_sum(self::COLS), $y + 7);
        }
        $this->Ln(4);
    }

    private function totalsBlock(): void
    {
        $t = $this->o['totals'];
        $d = $this->o['data'];
        if ($this->GetY() + 34 > 262) {
            $this->AddPage();
        }
        $rows = [[$this->tx('net'), Quote::money($t['net'], $this->lang), false], [$this->tx('vat') . ' ' . (int) $d['vat_rate'] . '%', Quote::money($t['vat'], $this->lang), false]];
        $x = 110;
        foreach ($rows as [$k, $v]) {
            $this->SetX($x);
            $this->SetFont('Helvetica', '', 9.5);
            $this->SetTextColor(...self::GREY);
            $this->Cell(38, 6, $this->enc($k), 0, 0);
            $this->SetTextColor(...self::BLACK);
            $this->Cell(34, 6, $this->enc($v), 0, 1, 'R');
        }
        $this->SetX($x);
        $this->SetFillColor(...self::BLACK);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 11);
        $this->Cell(38, 9, $this->enc($this->tx('total')), 0, 0, 'L', true);
        $this->Cell(34, 9, $this->enc(Quote::money($t['total'], $this->lang)), 0, 1, 'R', true);
        $this->SetTextColor(...self::BLACK);
        if (($d['vat_note'] ?? '') !== '') {
            $this->SetX($x);
            $this->SetFont('Helvetica', 'I', 8);
            $this->SetTextColor(...self::GREY);
            $this->MultiCell(72, 4, $this->enc((string) $d['vat_note']), 0, 'R');
            $this->SetTextColor(...self::BLACK);
        }
        $this->Ln(5);
    }

    private function textBlocks(): void
    {
        $d = $this->o['data'];
        foreach (['payment', 'delivery', 'notes'] as $k) {
            if (trim((string) ($d[$k] ?? '')) === '') {
                continue;
            }
            $this->block($this->tx($k), (string) $d[$k], 9.5, 4.8);
        }
        if ($this->o['conditions'] !== '') {
            $this->block($this->tx('conditions'), $this->o['conditions'], 7.5, 3.6);
        }
    }

    private function block(string $title, string $text, float $size, float $lh): void
    {
        if ($this->GetY() + 16 > 262) {
            $this->AddPage();
        }
        $this->SetFont('Helvetica', 'B', 8);
        $this->SetTextColor(...self::RED);
        $this->Cell(0, 5, $this->enc(mb_strtoupper($title)), 0, 1);
        $this->SetTextColor(...self::BLACK);
        $this->SetFont('Helvetica', '', $size);
        foreach (explode("\n", str_replace("\r", '', $text)) as $para) {
            $lines = $this->wrap($para, 182);
            foreach ($lines ?: [''] as $line) {
                if ($this->GetY() + $lh > 262) {
                    $this->AddPage();
                    $this->SetFont('Helvetica', '', $size);
                }
                $this->Cell(0, $lh, $this->enc($line), 0, 1);
            }
        }
        $this->Ln(3);
    }

    /** Spezza un testo in righe che stanno nella larghezza data (mm), con il carattere corrente. */
    private function wrap(string $text, float $width): array
    {
        $out = [];
        foreach (explode("\n", $text) as $para) {
            $line = '';
            foreach (preg_split('/\s+/', trim($para)) ?: [] as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($line !== '' && $this->GetStringWidth($this->enc($try)) > $width) {
                    $out[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            $out[] = $line;
        }
        return $out;
    }

    private function tx(string $k): string
    {
        return self::TEXT[$this->lang][$k];
    }

    private function day(int $ts): string
    {
        return $this->lang === 'en' ? date('d M Y', $ts) : date('d/m/Y', $ts);
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, $this->lang === 'en' ? '.' : ',', ''), '0'), $this->lang === 'en' ? '.' : ',');
    }

    /** Testo UTF-8 → codifica del PDF standard (Windows-1252: lettere accentate e simbolo dell'euro). */
    private function enc(string $s): string
    {
        return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    }
}
