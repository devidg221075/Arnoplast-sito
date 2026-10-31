"""Schede prodotto delle pagine famiglia (foto ritagliata, nome, materiali, video, link)
e video YouTube da data/videos.json."""
import json
import re
from html import unescape
from pathlib import Path

import pytest
from PIL import Image

import arnoplast.site.build as sb
from arnoplast.catalog import Article, Catalog, Family, Line, Product, load_catalog
from arnoplast.site.build import MATERIALS, build_site, load_videos, photo_widths, product_videos

ROOT = Path(__file__).resolve().parent.parent


def _prod(slug, image=None, materials=("PP", "FE"), video_url=None, n=3):
    arts = [Article(f"1000-{i:04d}", {"it": "Art"}, 1, 2, 3, "article") for i in range(n)]
    return Product(slug, {"it": slug.replace("-", " ").title()}, list(materials), [], [], image, video_url, arts)


def _cat(*prods):
    return Catalog([Line("agricoltura", {"it": "Agricoltura"}, "red",
                         [Family("fam", {"it": "Famiglia"}, list(prods))])])


def _cards(html):
    return re.findall(r'<li class="pcard">(.*?)</li>\s*(?=<li class="pcard">|</ul>\s*</section>)', html, re.S)


# --- video -------------------------------------------------------------------------

def test_real_videos_json_matches_catalog_25_products_30_links():
    c = load_catalog(ROOT / "data" / "catalog.json")
    raw = load_videos(ROOT / "data" / "videos.json")
    vids = product_videos(c, raw)
    assert len(raw) == 25 and sum(len(v["videos"]) for v in raw.values()) == 30
    assert set(raw) <= set(vids)
    assert sum(len(vids[k]) for k in raw) == 30
    assert len(vids["agricoltura/copripalo/copripalo-monviso"]) == 2
    assert all(u.startswith("https://youtu.be/") for v in vids.values() for u in v)


def test_unknown_video_key_is_a_clear_error():
    with pytest.raises(ValueError, match="agricoltura/fam/non-esiste"):
        product_videos(_cat(_prod("a")), {"agricoltura/fam/non-esiste": {"videos": ["https://youtu.be/x1234567"]}})
    with pytest.raises(ValueError, match="URL non valido"):
        product_videos(_cat(_prod("a")), {"agricoltura/fam/a": {"videos": ["javascript:alert(1)"]}})


def test_video_url_from_catalog_is_merged_without_duplicates():
    c = _cat(_prod("a", video_url="https://www.youtube.com/watch?v=AAAAAAAAAAA"),
             _prod("b", video_url="https://youtu.be/CCCCCCCCCCC"))
    vids = product_videos(c, {"agricoltura/fam/a": {"videos": ["https://youtu.be/AAAAAAAAAAA",
                                                               "https://youtu.be/BBBBBBBBBBB"]}})
    assert vids == {"agricoltura/fam/a": ["https://youtu.be/AAAAAAAAAAA", "https://youtu.be/BBBBBBBBBBB"],
                    "agricoltura/fam/b": ["https://youtu.be/CCCCCCCCCCC"]}


def test_build_main_rejects_bad_videos_json_before_cleaning(tmp_path, monkeypatch):
    data = tmp_path / "data"
    data.mkdir()
    (data / "videos.json").write_text('{"x/y/z": {"videos": ["https://youtu.be/abcdefgh"]}}', encoding="utf-8")
    from arnoplast.catalog import save_catalog
    save_catalog(_cat(_prod("a")), data / "catalog.json")
    monkeypatch.setattr(sb, "DATA_DIR", data)
    out = tmp_path / "out"
    out.mkdir()
    (out / "keep.txt").write_text("x")
    with pytest.raises(ValueError, match="x/y/z"):
        sb.main(out=out)
    assert (out / "keep.txt").exists()


# --- schede ------------------------------------------------------------------------

def test_photo_widths_never_upscale():
    assert photo_widths(346) == [346]
    assert photo_widths(500) == [360, 500]
    assert photo_widths(1242) == [360, 640]
    assert photo_widths(157) == [157]
    assert photo_widths(640) == [360, 640]


@pytest.fixture
def fake_data(tmp_path, monkeypatch):
    data = tmp_path / "data"
    d = data / "images" / "agricoltura"
    d.mkdir(parents=True)
    Image.new("RGB", (910, 600), "white").save(d / "p0.jpg", "JPEG")          # fascia di pagina
    Image.new("RGB", (500, 720), (30, 60, 200)).save(d / "p0.photo.png")       # foto ritagliata
    Image.new("RGB", (910, 600), "white").save(d / "nofoto.jpg", "JPEG")      # solo pagina
    monkeypatch.setattr(sb, "DATA_DIR", data)
    return data


def test_family_cards_photo_title_materials_video_link(tmp_path, fake_data):
    prods = [_prod(f"p{i}", image="images/agricoltura/p0.jpg") for i in range(6)]
    prods.append(_prod("solo-pagina", image="images/agricoltura/nofoto.jpg", materials=(), n=1))
    c = _cat(*prods)
    videos = {"agricoltura/fam/p1": {"videos": ["https://youtu.be/AAAAAAAAAAA", "https://youtu.be/BBBBBBBBBBB"]},
              "agricoltura/fam/p2": {"videos": ["https://youtu.be/CCCCCCCCCCC"]}}
    out = tmp_path / "out"
    build_site(c, out, videos=videos)
    html = (out / "it" / "agricoltura" / "fam" / "index.html").read_text(encoding="utf-8")
    assert 'href="/static/css/cards.css"' in html
    cards = _cards(html)
    assert len(cards) == 7
    for i, card in enumerate(cards[:6]):
        img = re.search(r"<img [^>]*>", card).group(0)
        assert 'src="/photos/agricoltura/p0-500.webp"' in img
        assert 'srcset="/photos/agricoltura/p0-360.webp 360w, /photos/agricoltura/p0-500.webp 500w"' in img
        assert 'width="500" height="720"' in img and f'alt="P{i}"' in img and "sizes=" in img
        assert ('loading="lazy"' in img) == (i >= 4)
        assert ('fetchpriority="high"' in img) == (i == 0)
        assert f'<a class="pcard-link" href="/it/agricoltura/fam/p{i}/">P{i}</a>' in card
        assert 'title="Polipropilene"' in card and 'title="Ferro zincato"' in card
        assert "Polipropilene (PP)" in card and ">PP</span>" in card
        assert "3 codici" in card and "Vedi prodotto" in card
    for w in (360, 500):
        with Image.open(out / "photos" / "agricoltura" / f"p0-{w}.webp") as im:
            assert im.format == "WEBP" and im.width == w
    assert not (out / "photos" / "agricoltura" / "p0-640.webp").exists()  # mai oltre i 500 px nativi
    assert "pcard-video" not in cards[0]
    v1 = re.search(r'<a class="pcard-video"[^>]*>.*?</a>', cards[1], re.S).group(0)
    assert 'href="https://youtu.be/AAAAAAAAAAA"' in v1 and 'target="_blank"' in v1 and 'rel="noopener"' in v1
    assert "2 video" in v1 and "P1" in unescape(re.search(r'aria-label="([^"]+)"', v1).group(1))
    v2 = re.search(r'<a class="pcard-video"[^>]*>.*?</a>', cards[2], re.S).group(0)
    assert re.search(r"</svg>Video</a>", v2)
    # senza foto ritagliata: miniatura della pagina del catalogo, nessuna sigla, "1 codice"
    last = cards[6]
    assert "/thumbs/agricoltura/nofoto-" in last and "pcard-mats" not in last and "1 codice" in last


def test_product_page_uses_photo_keeps_catalog_page_and_lists_videos(tmp_path, fake_data):
    c = _cat(_prod("p0", image="images/agricoltura/p0.jpg"))
    out = tmp_path / "out"
    build_site(c, out, videos={"agricoltura/fam/p0": {"videos": ["https://youtu.be/AAAAAAAAAAA",
                                                                 "https://youtu.be/BBBBBBBBBBB"]}})
    html = (out / "it" / "agricoltura" / "fam" / "p0" / "index.html").read_text(encoding="utf-8")
    main = html.split("<main", 1)[1]
    assert re.search(r'<img class="product-photo" src="/photos/agricoltura/p0-500.webp" srcset="[^"]+" sizes="[^"]+" '
                     r'width="500" height="720" alt="P0"', main)
    assert '<a class="catalog-page" href="/images/agricoltura/p0.jpg"' in main
    assert (out / "images" / "agricoltura" / "p0.jpg").is_file()
    sec = re.search(r'<section class="product-videos".*?</section>', main, re.S).group(0)
    links = re.findall(r'<a class="video-link" href="([^"]+)" target="_blank" rel="noopener">.*?'
                       r'<span class="vl-title">([^<]+)</span>', sec, re.S)
    assert links == [("https://youtu.be/AAAAAAAAAAA", "Video 1"), ("https://youtu.be/BBBBBBBBBBB", "Video 2")]
    for bad in ("<iframe", "ytimg", "youtube.com/embed", "youtube-nocookie"):
        assert bad not in html


def test_product_without_videos_has_no_video_section(tmp_path, fake_data):
    build_site(_cat(_prod("p0", image="images/agricoltura/p0.jpg")), tmp_path / "o")
    html = (tmp_path / "o" / "it" / "agricoltura" / "fam" / "p0" / "index.html").read_text(encoding="utf-8")
    assert "product-videos" not in html and "youtu" not in html


# --- tutte le famiglie reali -------------------------------------------------------

@pytest.fixture(scope="module")
def real_site(tmp_path_factory):
    out = tmp_path_factory.mktemp("site")
    c = load_catalog(ROOT / "data" / "catalog.json")
    build_site(c, out, videos=load_videos(ROOT / "data" / "videos.json"))
    return c, out


def test_every_family_has_ordered_cards(real_site):
    c, out = real_site
    vids = product_videos(c, load_videos(ROOT / "data" / "videos.json"))
    chips = 0
    for l in c.lines:
        for f in l.families:
            html = (out / "it" / l.slug / f.slug / "index.html").read_text(encoding="utf-8")
            cards = _cards(html)
            assert len(cards) == len(f.products), (l.slug, f.slug)
            for p, card in zip(f.products, cards):
                url = f"/it/{l.slug}/{f.slug}/{p.slug}/"
                assert (out / url.strip("/") / "index.html").is_file()
                name = re.search(r'<a class="pcard-link" href="([^"]+)">([^<]+)</a>', card)
                assert name.group(1) == url and unescape(name.group(2)) == p.name["it"]
                assert re.findall(r'<li class="mat" title="([^"]+)">', card) == [MATERIALS[m] for m in p.materials]
                for img in re.findall(r"<img [^>]*>", card):
                    assert f'alt="{p.name["it"]}"' in unescape(img) and 'width="' in img and 'height="' in img
                    if "/photos/" in img:
                        assert (out / re.search(r'src="/([^"]+)"', img).group(1)).is_file()
                key = f"{l.slug}/{f.slug}/{p.slug}"
                assert ("pcard-video" in card) == (key in vids)
                chips += "pcard-video" in card
    assert chips == 25


def test_monviso_page_has_two_video_links(real_site):
    _, out = real_site
    html = (out / "it/agricoltura/copripalo/copripalo-monviso/index.html").read_text(encoding="utf-8")
    titles = re.findall(r'<span class="vl-title">([^<]+)</span>', html)
    assert titles == ["Video 1", "Video 2"]
    assert html.count('class="video-link" href="https://youtu.be/') == 2


@pytest.mark.skipif(not any((ROOT / "data" / "images").glob("*/*.photo.png")),
                    reason="foto ritagliate non generate (python -m arnoplast.extract.build --images-only)")
def test_family_pages_stay_light(real_site):
    """HTML + CSS + immagini caricate subito (non lazy, candidato piu' grande) < 400 KB."""
    c, out = real_site
    css = sum((out / "static/css" / f).stat().st_size for f in ("main.css", "motion.css", "cards.css"))
    for l in c.lines:
        for f in l.families:
            page = out / "it" / l.slug / f.slug / "index.html"
            html = page.read_text(encoding="utf-8")
            eager = [i for i in re.findall(r"<img [^>]*>", html) if 'loading="lazy"' not in i]
            img = sum((out / re.search(r'src="/([^"]+)"', i).group(1)).stat().st_size for i in eager)
            assert page.stat().st_size + css + img < 400_000, (l.slug, f.slug)
