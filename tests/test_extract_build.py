"""Test dell'estrazione dai PDF reali (skippati se i PDF mancano)."""
import json
from pathlib import Path

import pytest

from arnoplast.catalog import validate
from arnoplast.extract.articles import parse_ean_rows, parse_raw_rows, strip_ean
from arnoplast.extract.build import (
    SOURCES, build_catalog, display_name, slugify, suspect_rows,
)

HAVE_PDFS = all(Path(p).exists() for p in SOURCES.values())
needs_pdfs = pytest.mark.skipif(not HAVE_PDFS, reason="PDF sorgente mancanti")


# --- unità pure (nessun PDF) -------------------------------------------------

def test_strip_ean_variants():
    assert strip_ean("Agevolatore EXCELSO N°2 pettini Cod. 802572103357") == \
        "Agevolatore EXCELSO N°2 pettini"
    assert strip_ean("Chiodo ferma t. alette 11 cm in PA - Cod ean 8025721030893 (conf. 200pz.)") == \
        "Chiodo ferma t. alette 11 cm in PA (conf. 200pz.)"
    assert strip_ean("Telo 6x50mt Cod ean 8025721034228 - MADE IN ITALY (80gr/mq)") == \
        "Telo 6x50mt - MADE IN ITALY (80gr/mq)"
    assert strip_ean("n.2 pettini in carbonio Cd ean 8025721032583") == "n.2 pettini in carbonio"
    assert strip_ean("Vite 6x20") == "Vite 6x20"


def test_parse_ean_rows_conf_box_and_spares():
    text = "\n".join([
        "  COD. EAN        ART.          DESCRIZIONE                  CONF.     BOX",
        "8025721030343   1401-0125   DISPENSER 45 MT. Ø 2,5 TONDO        1       15",
        "                1404-0000   BOBINA 4 LB. - QUALSIASI SEZIONE    1        4",
        "",
        "  COD. EAN        ART.     DESCRIZIONE PARTI DI RICAMBIO                BOX",
        "                2411-0305   MOLLA                                         4",
    ])
    rows = parse_ean_rows(text)
    assert [(a.code, a.conf, a.imb, a.pallet, a.kind) for a in rows] == [
        ("1401-0125", 1, 15, None, "article"),
        ("1404-0000", 1, 4, None, "article"),
        ("2411-0305", None, 4, None, "spare"),
    ]
    assert rows[0].description == {"it": "DISPENSER 45 MT. Ø 2,5 TONDO"}


def test_parse_raw_rows_single_and_split_lines():
    text = "\n".join([
        "COD. DESCRIZIONE CONF. IMB. PALLET",
        "1807-1301 Coprip. Qubo 7x7 (senza vit.) 64 64 1024",
        "2003-0120 Rondella piana 6x24mm 32 32 -",
        "1807-1223",
        "RONDELLA MET. RIVESTITA IN PA",
        "MIS.44X10,5X7",
        "10 10 -",
        "VITERIA A CAPANNINA",
    ])
    rows = parse_raw_rows(text, ncols=3)
    assert [(a.code, a.description["it"], a.conf, a.imb, a.pallet) for a in rows] == [
        ("1807-1301", "Coprip. Qubo 7x7 (senza vit.)", 64, 64, 1024),
        ("2003-0120", "Rondella piana 6x24mm", 32, 32, None),
        ("1807-1223", "RONDELLA MET. RIVESTITA IN PA MIS.44X10,5X7", 10, 10, None),
    ]


def test_display_name_and_slug():
    assert display_name("COPRIPALO PRISMA") == "Copripalo Prisma"
    assert display_name("GANCIO ROVESCIO IN PVC") == "Gancio Rovescio in PVC"
    assert display_name("COPRIPALO ECO 5x5 – 6x6") == "Copripalo Eco 5x5 – 6x6"
    assert display_name("COPRIPALO FG 40-50-60-65-80") == "Copripalo FG 40-50-60-65-80"
    assert display_name("COPRIPALO 4 E 12 CM CON CROCE") == "Copripalo 4 e 12 cm con Croce"
    assert slugify("Copripalo Eco 5x5 – 6x6") == "copripalo-eco-5x5-6x6"
    assert slugify("Placchetta Niña") == "placchetta-nina"
    assert slugify('Testina "Batti e Vai" Elektra') == "testina-batti-e-vai-elektra"


# --- PDF reali ---------------------------------------------------------------

@pytest.fixture(scope="module")
def catalog():
    return build_catalog(SOURCES)


def _product(catalog, line_slug, name):
    line = next(l for l in catalog.lines if l.slug == line_slug)
    for fam in line.families:
        for p in fam.products:
            if p.name["it"] == name:
                return p
    raise AssertionError(f"prodotto {name!r} non trovato in {line_slug}")


@needs_pdfs
def test_copripalo_prisma_has_expected_articles(catalog):
    p = _product(catalog, "agricoltura", "Copripalo Prisma")
    by_code = {a.code: a for a in p.articles}
    assert by_code["1807-1351"].pallet == 640
    assert "1807-1310GZ" in by_code
    assert p.materials == ["PP"]
    assert any("antigrandine" in f for f in p.features)
    assert any("215x115x120" in d for d in p.dimensions)
    assert p.image == "images/agricoltura/copripalo-prisma.jpg"


@needs_pdfs
def test_every_line_has_families(catalog):
    assert [l.slug for l in catalog.lines] == ["agricoltura", "garden", "olive"]
    assert [l.accent for l in catalog.lines] == ["red", "green", "olive"]
    for line in catalog.lines:
        assert line.families, line.slug
        for fam in line.families:
            assert fam.products, f"{line.slug}/{fam.slug} senza prodotti"
            for p in fam.products:
                assert p.articles, f"{line.slug}/{fam.slug}/{p.slug} senza articoli"


@needs_pdfs
def test_validate_returns_no_errors_on_real_catalog(catalog):
    assert validate(catalog) == []


@needs_pdfs
def test_olive_articles_have_no_pallet(catalog):
    olive = next(l for l in catalog.lines if l.slug == "olive")
    assert [f.name["it"] for f in olive.families] == ["Agevolatori e macchine"]
    arts = [a for f in olive.families for p in f.products for a in p.articles]
    assert arts
    assert all(a.pallet is None for a in arts)
    assert all(a.imb is not None for a in arts)
    assert not any("8025721" in a.description["it"] for a in arts)


@needs_pdfs
def test_garbled_qubo_row_recovered(catalog):
    p = _product(catalog, "agricoltura", "Copripalo Qubo")
    a = next(a for a in p.articles if a.code == "2003-0120")
    assert (a.conf, a.imb, a.pallet) == (32, 32, None)
    assert a.description == {"it": "Rondella piana 6x24mm"}


@needs_pdfs
def test_multi_product_pages_split(catalog):
    # pag. 14: Copripalo Latina e Copripalo Sphera sulla stessa pagina
    latina = _product(catalog, "agricoltura", "Copripalo Latina")
    sphera = _product(catalog, "agricoltura", "Copripalo Sphera")
    assert latina.articles and sphera.articles
    latina_codes = {a.code for a in latina.articles}
    sphera_codes = {a.code for a in sphera.articles}
    assert "1807-1511" in latina_codes and "1807-1511" not in sphera_codes
    assert "1807-1501C" in sphera_codes and "1807-1501C" not in latina_codes
    # articoli comuni (es. Mon-block) restano in ciascun prodotto
    assert "1807-0751" in latina_codes & sphera_codes


@needs_pdfs
def test_garden_ean_table(catalog):
    p = _product(catalog, "garden", "Testina Batti e Vai Sirio Coppa Folle")
    by_code = {a.code: a for a in p.articles}
    assert by_code["1103-0301"].imb == 12
    assert by_code["2411-0305"].kind == "spare"


@needs_pdfs
def test_suspect_rows_is_list_of_pairs(catalog):
    rows = suspect_rows(catalog)
    assert isinstance(rows, list)
    assert all(isinstance(r, tuple) and len(r) == 2 for r in rows)


@needs_pdfs
def test_empty_conf_column_does_not_shift_values(catalog):
    # pag. 18: CONF. vuota, 120 sotto IMB. e 2880 sotto PALLET
    a = _product(catalog, "agricoltura", "Angolare per Paloni").articles[0]
    assert (a.code, a.conf, a.imb, a.pallet) == ("1807-0203", None, 120, 2880)


@needs_pdfs
def test_repeated_code_rows_kept(catalog):
    # pag. 53: il catalogo usa 1802-0410 per due righe diverse
    rows = [a for a in _product(catalog, "agricoltura", "Colibri").articles
            if a.code == "1802-0410"]
    assert [(a.description["it"], a.imb) for a in rows] == [
        ("Tirante Colibri in PVC", 2000),
        ("Colibri completo (gancio+tirante)", 1500),
    ]


@needs_pdfs
def test_row_near_page_footer_kept(catalog):
    # pag. 50: l'ultima riga (1803-0121) sta appena sopra il piè di pagina
    p = _product(catalog, "agricoltura", "Mosch. Standard, Metallico e Rapid")
    assert [a.code for a in p.articles] == ["1803-0202", "1803-0107", "1803-0121"]


@needs_pdfs
def test_table_order_preserved_with_repeated_codes(catalog):
    p = _product(catalog, "agricoltura", "Stop.net - Stop Net 2")
    assert [a.code for a in p.articles] == [
        "1802-0405", "1802-0401", "1802-0803", "1802-0407", "1802-0401", "1702-0806"]


# --- copertura dei codici (fix round 1) ---------------------------------------

def test_uncovered_codes_with_fake_pages():
    from arnoplast.catalog import Article, Catalog, Family, Line, Product
    from arnoplast.extract.build import uncovered_codes
    prod = Product("p", {"it": "P"}, [], [], [], None, None,
                   [Article("1807-1351", {"it": "x"}, 1, 1, 1, "article")])
    cat = Catalog([Line("agricoltura", {"it": "A"}, "red", [Family("f", {"it": "F"}, [prod])])])
    pages = {"a.pdf": ["1807-1351 Coprip\n8025721010802   \x071807-1310GZ Viteria", "nulla 1975 - 2025"]}
    got = uncovered_codes(cat, {"agricoltura": Path("a.pdf")},
                          pages=lambda pdf: pages[Path(pdf).name])
    assert got == [("agricoltura", 1, "1807-1310GZ")]


@needs_pdfs
def test_no_code_in_pdfs_is_missing_from_catalog(catalog):
    from arnoplast.extract.build import uncovered_codes
    assert uncovered_codes(catalog, SOURCES) == []


@needs_pdfs
def test_garden_wrapped_and_control_char_rows(catalog):
    velox = {a.code: a for a in _product(
        catalog, "garden", "Filo Super Professionale Highlander Velox").articles}
    for code, mt in [("1424-0124", "230MT"), ("1424-0130", "155MT"), ("1424-0133", "108MT")]:
        a = velox[code]
        assert (a.conf, a.imb) == (1, 4) and a.description["it"].endswith(mt)
    raptor = {a.code: a for a in _product(
        catalog, "garden", "Filo Super Professionale Highlander Raptor").articles}
    a = raptor["1412-0140"]
    assert (a.description["it"], a.conf, a.imb) == ("DISPENSER 30 MT. DIAM. 4,0 SEGHETTATO", 1, 12)


@needs_pdfs
def test_espositori_family(catalog):
    garden = next(l for l in catalog.lines if l.slug == "garden")
    fam = next(f for f in garden.families if f.slug == "espositori")
    assert [p.name["it"] for p in fam.products] == ["Espositori"]
    by_code = {a.code: a for a in fam.products[0].articles}
    assert by_code["5703-0002"].imb == 1 and by_code["5703-0003"].imb == 1
    # pag. 50-54 restano in Ancoraggi
    anc = next(f for f in garden.families if f.slug == "ancoraggi")
    assert "Clips per Serre" in [p.name["it"] for p in anc.products]


@needs_pdfs
def test_elastico_premontato_wildcard_kept_and_flagged(catalog):
    p = _product(catalog, "agricoltura", "Elastico Premontato")
    assert [(a.code, a.conf, a.imb, a.pallet) for a in p.articles] == [
        ("1902-02…", None, 100, 2400)]
    assert ("Elastico Premontato", "1902-02…") in suspect_rows(catalog)


# --- main(): non sovrascrive catalog.json senza --force ------------------------

def _fake_catalog():
    from arnoplast.catalog import Article, Catalog, Family, Line, Product
    a = Article("1000-0001", {"it": "Prova"}, 1, 2, 3, "article")
    p = Product("p", {"it": "P"}, [], [], [], "images/l/p.jpg", None, [a])
    return Catalog([Line("l", {"it": "L"}, "red", [Family("f", {"it": "F"}, [p])])])


@pytest.fixture
def fake_main(tmp_path, monkeypatch):
    import arnoplast.extract.build as b
    calls = []
    monkeypatch.setattr(b, "DATA_DIR", tmp_path)
    monkeypatch.setattr(b, "SOURCES", {})

    def fake_build(sources, images_dir=None, photos=None):
        calls.append(images_dir)
        if images_dir is not None and photos is not None:
            photos["images/l/p.jpg"] = {"product": "P", "page": 3, "bbox": [0, 0, 10, 10],
                                        "dpi": 100, "size": [14, 14], "check": None}
        return _fake_catalog()
    monkeypatch.setattr(b, "build_catalog", fake_build)
    monkeypatch.setattr(b, "uncovered_codes", lambda *a, **k: [])
    return b, tmp_path, calls


def test_main_default_writes_generated_and_keeps_manual_catalog(fake_main):
    b, d, calls = fake_main
    (d / "catalog.json").write_text("CORREZIONI MANUALI", encoding="utf-8")
    assert b.main([]) == 0
    assert (d / "catalog.json").read_text(encoding="utf-8") == "CORREZIONI MANUALI"
    assert (d / "catalog.generated.json").is_file()
    assert calls == [None]  # nessuna immagine
    assert not (d / "photos.json").exists()


def test_main_force_overwrites_catalog(fake_main):
    b, d, calls = fake_main
    (d / "catalog.json").write_text("vecchio", encoding="utf-8")
    assert b.main(["--force"]) == 0
    assert "1000-0001" in (d / "catalog.json").read_text(encoding="utf-8")
    assert calls == [d / "images"]


def test_main_images_only_never_writes_catalogs(fake_main):
    b, d, calls = fake_main
    (d / "catalog.json").write_text("CORREZIONI MANUALI", encoding="utf-8")
    assert b.main(["--images-only"]) == 0
    assert (d / "catalog.json").read_text(encoding="utf-8") == "CORREZIONI MANUALI"
    assert not (d / "catalog.generated.json").exists()
    assert calls == [d / "images"]
    photos = json.loads((d / "photos.json").read_text(encoding="utf-8"))
    assert photos["images/l/p.jpg"]["page"] == 3
