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
