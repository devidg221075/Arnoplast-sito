from arnoplast.extract.articles import parse_article_rows


def one(line):
    rows = parse_article_rows(line)
    assert len(rows) == 1
    return rows[0]


def test_full_row():
    a = one("1807-1351   Coprip Prisma 7x7 (senza viteria)     40   40   640")
    assert (a.code, a.conf, a.imb, a.pallet, a.kind) == ("1807-1351", 40, 40, 640, "article")
    assert a.description == {"it": "Coprip Prisma 7x7 (senza viteria)"}


def test_dash_pallet_is_none():
    a = one("1807-1357   Viteria per Cp (4pz.)    40   200   -")
    assert (a.conf, a.imb, a.pallet) == (40, 200, None)
    assert a.description == {"it": "Viteria per Cp (4pz.)"}


def test_letter_suffix_single_space():
    a = one("1807-1310GZ Viteria GZ     40    200    -")
    assert a.code == "1807-1310GZ"
    assert a.description == {"it": "Viteria GZ"}
    assert (a.conf, a.imb, a.pallet) == (40, 200, None)


def test_optional_kind():
    a = one("2008-0016   Dado a griffe M6 (optional)   32  32  -")
    assert a.kind == "optional"


def test_olive_imb_only():
    a = one("2801-0404   Agevolatore EXCELSO motore in basso   2pz")
    assert (a.conf, a.imb, a.pallet) == (None, 2, None)


def test_header_and_free_text_ignored():
    text = "\n".join([
        "   COD.        DESCRIZIONE        CONF.   IMB.   PALLET",
        "COPRIPALO PRISMA                       PP",
        "• dimensioni: 215x115x120 mm",
        "",
        "1807-1351   Coprip Prisma 7x7 (senza viteria)     40   40   640",
        "APPENDICE “ESPLOSI VITERIE” A PAG. 68",
    ])
    assert [a.code for a in parse_article_rows(text)] == ["1807-1351"]


def test_wrapped_description_joined():
    text = "\n".join([
        "      2801-0404   Agevolatore EXCELSO motore in basso      2pz",
        "                  N°2 pettini in Plastica",
        "",
        "                  Made in Italy",
    ])
    a = one(text)
    assert a.description == {"it": "Agevolatore EXCELSO motore in basso N°2 pettini in Plastica"}
    assert a.imb == 2


def test_code_alone_then_description_line():
    text = "1807-1351\n    Coprip Prisma 7x7     40   40   640\n"
    a = one(text)
    assert a.description == {"it": "Coprip Prisma 7x7"}
    assert (a.conf, a.imb, a.pallet) == (40, 40, 640)


def test_real_prisma_page_snippet():
    text = """\
   COD.                     DESCRIZIONE                         CONF.          IMB.    PALLET
                                                                                                                120
 1807-1351   Coprip Prisma 7x7 (senza viteria)                    40              40     640

 1807-1352   Coprip Prisma 7x8 (senza viteria)                    40              40     640

 1807-1353   Coprip Prisma 8x8,5 (senza viteria)                  40              40     640

 1807-1355   Coprip Prisma 9x9 (senza viteria)                    40              40     640                                          115
 1807-1357   Viteria per Cp (4pz.)                                40           200        -

 1807-0751   Mon-block (con dado e vite)                          32           320        -

 2008-0016   Dado a griffe M6 (optional)                          32              32      -

 2009-0156   Vite 6x20 per dado a griffe (optional)               32              32      -

 2009-0182   Tassello 6x30mm                                      32              32      -
                                                                                                                                      215




 2009-0181   Vite 4x40 zincata                                    32              32      -

 2003-0120   Rondella piana 6x24mm                                32              32      -

1807-1310GZ Viteria GZ                                            40           200        -
                      VITERIA RETE PIANA                   (PER CP MONVISO,
OPTIONAL DI FISSAGGIO
                                     APPENDICE “ESPLOSI VITERIE” A PAG. 68
"""
    rows = parse_article_rows(text)
    got = [(a.code, a.conf, a.imb, a.pallet, a.kind) for a in rows]
    assert got == [
        ("1807-1351", 40, 40, 640, "article"),
        ("1807-1352", 40, 40, 640, "article"),
        ("1807-1353", 40, 40, 640, "article"),
        ("1807-1355", 40, 40, 640, "article"),
        ("1807-1357", 40, 200, None, "article"),
        ("1807-0751", 32, 320, None, "article"),
        ("2008-0016", 32, 32, None, "optional"),
        ("2009-0156", 32, 32, None, "optional"),
        ("2009-0182", 32, 32, None, "article"),
        ("2009-0181", 32, 32, None, "article"),
        ("2003-0120", 32, 32, None, "article"),
        ("1807-1310GZ", 40, 200, None, "article"),
    ]
    assert rows[3].description == {"it": "Coprip Prisma 9x9 (senza viteria)"}
    assert rows[-1].description == {"it": "Viteria GZ"}


def test_olive_description_before_code():
    text = "\n".join([
        "      COD.          DESCRIZIONE          IMB.",
        "                Agevolatore EXCELSO motore in basso",
        "                Asta tel. in carbonio",
        "    2801-0404                                          2pz",
        "                N°2 pettini in Plastica",
        "",
        "                              Made in Italy",
    ])
    a = one(text)
    assert a.description == {"it": "Agevolatore EXCELSO motore in basso Asta tel. in carbonio N°2 pettini in Plastica"}
    assert (a.conf, a.imb, a.pallet) == (None, 2, None)


def test_olive_heading_line_before_code_with_description():
    text = "\n".join([
        "      COD.                           DESCRIZIONE                            IMB.",
        "                Agevolatore MARTINO ECO motore in alto",
        "    2801-0202   Asta fissa in alluminio                                      2pz",
        "                Cod ean 8025721031364",
        "",
        "                Agevolatore Oleo Carbon",
        "    2801-0403                                                                2pz",
        "                n.2 pettini in carbonio",
        "                Cd ean 8025721032583",
    ])
    rows = parse_article_rows(text)
    assert [(a.code, a.description["it"], a.imb) for a in rows] == [
        ("2801-0202", "Agevolatore MARTINO ECO motore in alto Asta fissa in alluminio", 2),
        ("2801-0403", "Agevolatore Oleo Carbon n.2 pettini in carbonio", 2),
    ]


def test_ean_row_code_alone_description_wrapped_around():
    # garden p.24: descrizione sopra e sotto la riga del codice
    from arnoplast.extract.articles import parse_ean_rows
    text = "\n".join([
        "       COD. EAN        ART.                         DESCRIZIONE                                     CONF.        BOX",
        "",
        "     8025721034013               BOBINA 1,2 KG (PESO INDICATIVO) DIAM. 2,4MM -",
        "                     1424-0124                                                                          1         4",
        "                                 230MT",
        "     8025721034020               BOBINA 1,2 KG (PESO INDICATIVO) DIAM. 3,0MM -",
        "                     1424-0130                                                                          1         4",
        "                                 155MT",
        "     8025721034044   1424-0139   BOBINA 1,2 KG (PESO INDICATIVO) DIAM. 3,9MM - 75MT                     1         4",
    ])
    rows = parse_ean_rows(text)
    assert [(a.code, a.description["it"], a.conf, a.imb) for a in rows] == [
        ("1424-0124", "BOBINA 1,2 KG (PESO INDICATIVO) DIAM. 2,4MM - 230MT", 1, 4),
        ("1424-0130", "BOBINA 1,2 KG (PESO INDICATIVO) DIAM. 3,0MM - 155MT", 1, 4),
        ("1424-0139", "BOBINA 1,2 KG (PESO INDICATIVO) DIAM. 3,9MM - 75MT", 1, 4),
    ]


def test_ean_row_with_control_character_before_code():
    # garden p.25: pdftotext emette un BEL (\x07) prima del codice
    from arnoplast.extract.articles import parse_ean_rows
    text = "\n".join([
        "  COD. EAN        ART.                     DESCRIZIONE                        CONF.               BOX",
        "8025721033283   \x071412-0140    DISPENSER 30 MT. DIAM. 4,0 SEGHETTATO                         1               12",
    ])
    rows = parse_ean_rows(text)
    assert [(a.code, a.description["it"], a.conf, a.imb) for a in rows] == [
        ("1412-0140", "DISPENSER 30 MT. DIAM. 4,0 SEGHETTATO", 1, 12)]


def test_wildcard_code_row_kept():
    # agricoltura p.51: codice stampato "1902-02…" (varie misure, non ordinabile)
    text = "\n".join([
        "      COD.                     DESCRIZIONE                    CONF.      IMB.   PALLET",
        "                   ELASTICO PREMONTATO CON",
        "      1902-02…     DUE MOSCHETTONI (VARIE MISURE A                 -       100     2400",
        "                   RICHIESTA)",
        "        2025",
    ])
    a = one(text)
    assert a.code == "1902-02…"
    assert a.description == {"it": "ELASTICO PREMONTATO CON DUE MOSCHETTONI (VARIE MISURE A RICHIESTA)"}
    assert (a.conf, a.imb, a.pallet) == (None, 100, 2400)
