<?php
declare(strict_types=1);

namespace Backoffice;

/**
 * Lettura dei file IDML di InDesign (catalogo): tabelle articoli, riquadri di testo per pagina, schede prodotto.
 * Gemello di arnoplast/extract/idml.py: le due letture devono dare gli stessi risultati (vedi tests/catalog_test.php).
 * Un IDML è uno zip di XML. Si legge senza toccare nulla: niente file scritti, niente entità esterne.
 */
final class Idml
{
    private const MAX_XML = 8_000_000;  // byte per singolo XML decompresso
    private const MAX_FILES = 3000;

    /** @var array<string, string> nome file => contenuto */
    private array $xml = [];
    /** @var array<string, \DOMDocument> */
    private array $docs = [];

    private function __construct()
    {
    }

    public static function open(string $path): self
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Il file non è un IDML valido (non si apre come archivio).');
        }
        if ($zip->numFiles > self::MAX_FILES) {
            $zip->close();
            throw new \RuntimeException('Il file IDML ha troppi elementi.');
        }
        $me = new self();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!preg_match('~^(designmap\.xml|Spreads/[^/]+\.xml|Stories/[^/]+\.xml)$~', $name)) {
                continue;
            }
            $st = $zip->statIndex($i);
            if ($st === false || $st['size'] > self::MAX_XML) {
                $zip->close();
                throw new \RuntimeException('Il file IDML contiene un elemento troppo grande.');
            }
            $me->xml[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        if (!isset($me->xml['designmap.xml'])) {
            throw new \RuntimeException('Il file non sembra un IDML di InDesign (manca designmap.xml).');
        }
        return $me;
    }

    private function doc(string $name): ?\DOMDocument
    {
        if (!isset($this->xml[$name])) {
            return null;
        }
        if (!isset($this->docs[$name])) {
            if (stripos($this->xml[$name], '<!DOCTYPE') !== false || stripos($this->xml[$name], '<!ENTITY') !== false) {
                throw new \RuntimeException('Il file IDML contiene dichiarazioni non ammesse.');
            }
            $d = new \DOMDocument();
            $prev = libxml_use_internal_errors(true);
            $ok = $d->loadXML($this->xml[$name], LIBXML_NONET | LIBXML_NOBLANKS);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            if (!$ok) {
                throw new \RuntimeException('Un elemento del file IDML non si legge (' . $name . ').');
            }
            $this->docs[$name] = $d;
        }
        return $this->docs[$name];
    }

    private static function text(\DOMNode $n): string
    {
        $s = '';
        foreach ((new \DOMXPath($n instanceof \DOMDocument ? $n : $n->ownerDocument))->query('.//Content', $n) as $c) {
            $s .= $c->textContent;
        }
        return trim($s);
    }

    private static function intOrNull(string $v): ?int
    {
        $v = trim(str_replace('.', '', $v));
        return ctype_digit($v) && $v !== '' ? (int) $v : null;
    }

    /** @return array{0:int|null,1:int|null,2:int|null,3:int|null} */
    private static function columns(array $head): array
    {
        $find = static function (array $names) use ($head): ?int {
            foreach ($head as $i => $h) {
                if (in_array(strtolower(trim($h)), $names, true)) {
                    return $i;
                }
            }
            return null;
        };
        $desc = null;
        foreach ($head as $i => $h) {
            if (str_starts_with(strtolower(trim($h)), 'descri')) {
                $desc = $i;
                break;
            }
        }
        return [
            $find(['art.', 'art', 'item', 'item no.', 'code']),
            $find(['cod. ean', 'ean']),
            $desc,
            $find(['conf.', 'conf', 'pack', 'pack.', 'packing']),
        ];
    }

    /**
     * Righe di tutte le tabelle articoli, nell'ordine del file.
     * @return array<int, array{story:string,code:string,ean:string,description:string,conf:?int,box:?int,spare:bool}>
     */
    public function rows(): array
    {
        $rows = [];
        $names = array_keys($this->xml);
        sort($names);
        foreach ($names as $name) {
            if (!preg_match('~^Stories/Story_(.+)\.xml$~', $name, $m)) {
                continue;
            }
            $doc = $this->doc($name);
            $xp = new \DOMXPath($doc);
            foreach ($xp->query('//Table') as $tb) {
                $cols = (int) $tb->getAttribute('ColumnCount');
                $cells = [];
                foreach ($xp->query('./Cell', $tb) as $c) {
                    [$a, $b] = array_map('intval', explode(':', $c->getAttribute('Name')) + [0, 0]);
                    $cells[$a . ':' . $b] = self::text($c);
                }
                $head = [];
                for ($i = 0; $i < $cols; $i++) {
                    $head[$i] = $cells[$i . ':0'] ?? '';
                }
                [$ic, $ie, $id, $icf] = self::columns($head);
                $ib = null;
                foreach ($head as $i => $h) {
                    if (in_array(strtolower(trim($h)), ['box', 'carton'], true)) {
                        $ib = $i;
                    }
                }
                if ($ic === null) {
                    continue;
                }
                $joined = strtolower(implode(' ', $head));
                $spare = str_contains($joined, 'ricambio') || str_contains($joined, 'spare');
                $n = (int) ($tb->getAttribute('BodyRowCount') ?: 0);
                for ($r = 1; $r <= $n; $r++) {
                    $code = $cells[$ic . ':' . $r] ?? '';
                    if (!preg_match('~^\d{4}-\d{4}[A-Z]?$~', $code)) {
                        continue;
                    }
                    $ean = $ie !== null ? ($cells[$ie . ':' . $r] ?? '') : '';
                    $rows[] = [
                        'story' => $m[1],
                        'code' => $code,
                        'ean' => preg_match('~^\d{13}$~', $ean) ? $ean : '',
                        'description' => $id !== null ? ($cells[$id . ':' . $r] ?? '') : '',
                        'conf' => $icf !== null ? self::intOrNull($cells[$icf . ':' . $r] ?? '') : null,
                        'box' => $ib !== null ? self::intOrNull($cells[$ib . ':' . $r] ?? '') : null,
                        'spare' => $spare,
                    ];
                }
            }
        }
        return $rows;
    }

    /** @return string[] paragrafi di una storia, escluse le tabelle (i <Br/> separano i paragrafi) */
    private static function paragraphs(\DOMNode $root): array
    {
        $out = [''];
        $walk = static function (\DOMNode $e) use (&$walk, &$out): void {
            foreach ($e->childNodes as $c) {
                if (!$c instanceof \DOMElement || $c->tagName === 'Table') {
                    continue;
                }
                if ($c->tagName === 'Content') {
                    $out[count($out) - 1] .= $c->textContent;
                } elseif ($c->tagName === 'Br') {
                    $out[] = '';
                } else {
                    $walk($c);
                }
            }
        };
        $walk($root);
        return array_values(array_filter(array_map('trim', $out), static fn($t) => $t !== ''));
    }

    /**
     * Riquadri di testo di ogni pagina, con posizione e paragrafi.
     * @return array<int, array{page:string,x:float,y:float,text:string,paras:string[],table:bool}>
     */
    public function frames(): array
    {
        $frames = [];
        $design = $this->doc('designmap.xml');
        $spreads = [];
        foreach ((new \DOMXPath($design))->query('//*[@src]') as $e) {
            $src = $e->getAttribute('src');
            if (str_starts_with($src, 'Spreads/') && str_ends_with($e->nodeName, 'Spread')) {
                $spreads[] = $src;
            }
        }
        $nums = static fn(string $s): array => array_map('floatval', preg_split('~\s+~', trim($s)) ?: []);
        foreach ($spreads as $src) {
            $sd = $this->doc($src);
            if ($sd === null) {
                continue;
            }
            $xp = new \DOMXPath($sd);
            $sp = $xp->query('//Spread')->item(0);
            if (!$sp) {
                continue;
            }
            $pages = [];
            foreach ($xp->query('./Page', $sp) as $pg) {
                $t = $nums($pg->getAttribute('ItemTransform'));
                $b = $nums($pg->getAttribute('GeometricBounds'));
                if (count($t) < 6 || count($b) < 4) {
                    continue;
                }
                $pages[] = [$pg->getAttribute('Name'), $t[4], $t[4] + $b[3] - $b[1]];
            }
            foreach ($xp->query('.//TextFrame', $sp) as $tf) {
                $t = $nums($tf->getAttribute('ItemTransform'));
                if (count($t) < 6) {
                    continue;
                }
                $xs = [];
                $ys = [];
                foreach ($xp->query('.//PathPointType', $tf) as $p) {
                    $a = $nums($p->getAttribute('Anchor'));
                    if (count($a) >= 2) {
                        $xs[] = $a[0];
                        $ys[] = $a[1];
                    }
                }
                if (!$xs) {
                    continue;
                }
                $x = $t[4] + min($xs);
                $y = $t[5] + min($ys);
                $page = null;
                foreach ($pages as [$n, $a, $b]) {
                    if ($x >= $a - 5 && $x <= $b) {
                        $page = $n;
                        break;
                    }
                }
                $story = $this->doc('Stories/Story_' . $tf->getAttribute('ParentStory') . '.xml');
                if ($page === null || $story === null) {
                    continue;
                }
                $text = self::text($story);
                if ($text === '') {
                    continue;
                }
                $frames[] = [
                    'page' => $page, 'x' => $x, 'y' => $y, 'text' => $text,
                    'paras' => self::paragraphs($story->documentElement),
                    'table' => (new \DOMXPath($story))->query('//Table')->length > 0,
                ];
            }
        }
        return $frames;
    }

    /**
     * Collega italiano e inglese riga per riga (stessa struttura: la riga n è la riga n, il codice lo conferma).
     * @return array{0: array<int, array{0:array,1:?array}>, 1: string[]} coppie e problemi
     */
    public static function pair(array $it, array $en): array
    {
        $problems = [];
        if (count($it) !== count($en)) {
            $problems[] = 'righe diverse: ' . count($it) . ' in italiano, ' . count($en) . ' in inglese';
        }
        $pairs = [];
        foreach ($it as $n => $r) {
            $e = $en[$n] ?? null;
            if ($e !== null && $e['code'] !== $r['code']) {
                $problems[] = 'riga ' . ($n + 1) . ': ' . $r['code'] . ' in italiano, ' . $e['code'] . ' in inglese';
                $e = null;
            } elseif ($e !== null) {
                foreach (['ean', 'conf', 'box'] as $f) {
                    if ($r[$f] !== $e[$f]) {
                        $problems[] = $r['code'] . ': ' . $f . ' diverso tra le lingue (' . ($r[$f] ?? '—') . ' / ' . ($e[$f] ?? '—') . ')';
                    }
                }
            }
            $pairs[] = [$r, $e];
        }
        return [$pairs, $problems];
    }

    private static function norm(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        return trim((string) preg_replace('~[^\p{L}\p{N}]+~u', ' ', $s));
    }

    /**
     * Scheda di ogni prodotto: titolo e caratteristiche, italiano e inglese.
     * @param array<string,string> $names codice scheda => nome italiano
     * @return array{0: array<string, array>, 1: string[]}
     */
    public static function cards(self $it, self $en, array $names): array
    {
        $fi = $it->frames();
        $fe = $en->frames();
        $twin = static function (array $f) use ($fe): ?array {
            foreach ($fe as $e) {
                if ($e['page'] === $f['page'] && abs($e['x'] - $f['x']) < 1 && abs($e['y'] - $f['y']) < 1) {
                    return $e;
                }
            }
            return null;
        };
        $out = [];
        $problems = [];
        foreach ($names as $slug => $name) {
            $hits = array_values(array_filter($fi, static fn($f) => !$f['table'] && self::norm($f['text']) === self::norm($name)));
            if (!$hits) {
                $problems[] = $slug . ': titolo «' . $name . '» non trovato nell\'IDML';
                continue;
            }
            if (count($hits) > 1) {
                $problems[] = $slug . ': titolo presente ' . count($hits) . ' volte, uso la prima pagina';
            }
            $t = $hits[0];
            $feats = array_values(array_filter($fi, static fn($f) => $f['page'] === $t['page'] && !$f['table']
                && str_starts_with(strtoupper($f['text']), 'CARATTERISTICHE') && $f['y'] >= $t['y'] - 5));
            $feat = null;
            foreach ($feats as $f) {
                if ($feat === null || $f['y'] - $t['y'] < $feat['y'] - $t['y']) {
                    $feat = $f;
                }
            }
            $te = $twin($t);
            $fet = $feat ? $twin($feat) : null;
            $a = $feat ? array_slice($feat['paras'], 1) : [];
            $b = $fet ? array_slice($fet['paras'], 1) : [];
            $aligned = $fet !== null && count($a) === count($b);
            if ($feat && !$aligned) {
                $problems[] = $slug . ': ' . count($a) . ' punti in italiano, ' . count($b) . ' in inglese (le lingue restano indipendenti)';
            }
            $out[$slug] = ['page' => $t['page'], 'title_en' => $te['text'] ?? null, 'features_it' => $a, 'features_en' => $b, 'aligned' => $aligned];
        }
        return [$out, $problems];
    }
}
