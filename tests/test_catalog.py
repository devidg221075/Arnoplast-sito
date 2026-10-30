from arnoplast.catalog import (
    Article, Product, Family, Line, Catalog,
    load_catalog, save_catalog, validate, text,
)


def make_product(slug="prisma", materials=None, articles=None):
    return Product(
        slug=slug, name={"it": "Prisma", "en": ""},
        materials=materials if materials is not None else ["FE"],
        features=["a"], dimensions=["10x10"], image=None, video_url=None,
        articles=articles if articles is not None else [],
    )


def make_catalog(products):
    return Catalog(lines=[Line("l", {"it": "L"}, "#fff",
                               [Family("f", {"it": "F"}, products)])])


def test_text_falls_back_to_italian():
    assert text({"it": "Prisma"}, "en") == "Prisma"
    assert text({"it": "Prisma", "en": ""}, "en") == "Prisma"
    assert text({"it": "Prisma", "en": "Prism"}, "en") == "Prism"


def test_validate_flags_bad_material():
    errs = validate(make_catalog([make_product(materials=["XX"])]))
    assert errs and any("XX" in e for e in errs)


def test_validate_flags_duplicate_slug_in_family():
    errs = validate(make_catalog([make_product("a"), make_product("a")]))
    assert errs and any("a" in e for e in errs)


def test_validate_flags_bad_kind():
    art = Article("X1", {"it": "x"}, None, None, None, "bogus")
    errs = validate(make_catalog([make_product(articles=[art])]))
    assert errs and any("bogus" in e for e in errs)


def test_validate_accepts_product_without_articles():
    assert validate(make_catalog([make_product(materials=["FE", "PET", "AL"])])) == []


def test_roundtrip_save_load(tmp_path):
    art = Article("X1", {"it": "è", "en": "e"}, 1, 2, None, "spare")
    c = make_catalog([make_product(articles=[art])])
    p = tmp_path / "c.json"
    save_catalog(c, p)
    assert "è" in p.read_text(encoding="utf-8")
    assert load_catalog(p) == c
