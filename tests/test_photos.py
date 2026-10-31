"""Foto prodotto ritagliate dai cataloghi (arnoplast/extract/photos.py)."""
import json
from pathlib import Path

import pytest
from PIL import Image, ImageDraw

from arnoplast.catalog import load_catalog
from arnoplast.extract.build import SOURCES, find_titles
from arnoplast.extract.pdf import PAGE_H, page_words
from arnoplast.extract.photos import (
    NO_PHOTO, PHOTO_OVERRIDES, PageImage, choose_photo, extract_photo, parse_images_xml,
    pdf_images, trim_lines, trim_white, whiten,
)

ROOT = Path(__file__).resolve().parent.parent
needs_pdfs = pytest.mark.skipif(not all(Path(p).exists() for p in SOURCES.values()),
                                reason="PDF sorgente mancanti")


# --- unità ------------------------------------------------------------------------

def test_parse_images_xml_mirrored_and_off_page():
    xml = "\n".join([
        '<page number="9" position="absolute" top="0" left="0" height="841" width="595">',
        '<image top="58" left="0" width="249" height="388" src="x-9_2.jpg"/>',
        '<image top="118" left="596" width="-497" height="349" src="x-9_3.jpg"/>',  # specchiata
        '<image top="793" left="624" width="-20" height="38" src="x-9_1.jpg"/>',   # fuori pagina
        '<page number="10" position="absolute" top="0" left="0" height="841" width="595">',
    ])
    imgs = parse_images_xml(xml, {"x-9_2.jpg": (346, 539), "x-9_3.jpg": (692, 485)}, {"x-9_3.jpg"})
    assert set(imgs) == {9, 10} and imgs[10] == []
    a, b = imgs[9]
    assert (a.x0, a.y0, a.x1, a.y1, a.px_w, a.flip_x) == (0, 58, 249, 446, 346, False)
    assert round(a.ppi) == 100
    assert (b.x0, b.x1, b.flip_x, b.gray) == (99, 596, True, True)


def _im(x0, y0, x1, y1, gray=False, px=300):
    return PageImage(x0, y0, x1, y1, px, px, "f.jpg", False, False, gray)


def test_choose_photo_biggest_in_band_skips_icons_and_masks():
    photo = _im(0, 60, 250, 440)
    qr = _im(440, 20, 506, 86)                 # QR code: troppo piccolo
    other_band = _im(0, 470, 300, 800)         # foto del prodotto sotto
    mask = _im(0, 58, 252, 442, gray=True)     # sfumatura in grigi sopra la foto
    assert choose_photo([qr, mask, photo, other_band], (40, 460)) is photo
    assert choose_photo([qr, photo, other_band], (460, PAGE_H)) is other_band
    assert choose_photo([qr], (40, 460)) is None


def test_trim_and_whiten():
    im = Image.new("RGB", (200, 120), (246, 246, 246))       # fondo da studio quasi bianco
    d = ImageDraw.Draw(im)
    d.rectangle((0, 0, 199, 3), fill=(227, 6, 19))           # banda rossa del titolo che sconfina
    d.rectangle((60, 40, 140, 90), fill=(20, 20, 20))        # il prodotto
    out = trim_white(trim_lines(trim_white(trim_lines(whiten(im)))))
    assert out.getpixel((0, 0)) == (255, 255, 255)
    assert abs(out.width - 89) <= 2 and abs(out.height - 59) <= 2  # prodotto + 4 px di margine
    assert whiten(Image.new("RGB", (1, 1), (120, 60, 30))).getpixel((0, 0)) == (120, 60, 30)


# --- PDF reali --------------------------------------------------------------------

def _band(line, page, i):
    words = page_words(SOURCES[line], page)
    titles = find_titles(words, line)
    y1 = titles[i + 1].y0 - 4 if i + 1 < len(titles) else PAGE_H
    return titles[i].text, (max(0.0, titles[i].y0 - 6), y1)


@needs_pdfs
def test_copripalo_prisma_photo_is_the_big_left_photo(tmp_path):
    title, box = _band("agricoltura", 9, 0)
    assert title == "COPRIPALO PRISMA"
    ph = extract_photo(SOURCES["agricoltura"], 9, box, tmp_path / "p.png")
    x0, y0, x1, y1 = ph.bbox
    assert x0 < 5 and 50 < y0 < 70 and 240 < x1 < 260 and 430 < y1 < 450  # sotto la banda del titolo
    assert ph.check is None and ph.dpi == 100
    with Image.open(tmp_path / "p.png") as im:
        assert im.size == tuple(ph.size)
        assert im.width <= 346 and im.height <= 539          # pixel nativi: nessun ingrandimento
        assert im.width > 300                                 # non un'icona o un dettaglio


@needs_pdfs
def test_two_products_on_one_page_get_their_own_photos(tmp_path):
    (t1, b1), (t2, b2) = _band("agricoltura", 14, 0), _band("agricoltura", 14, 1)
    assert (t1, t2) == ("COPRIPALO LATINA", "COPRIPALO SPHERA")
    p1 = extract_photo(SOURCES["agricoltura"], 14, b1, tmp_path / "a.png")
    p2 = extract_photo(SOURCES["agricoltura"], 14, b2, tmp_path / "b.png")
    assert b1[0] <= p1.bbox[1] and p1.bbox[3] <= b1[1] + 2
    assert b2[0] <= p2.bbox[1] and p2.bbox[3] <= b2[1] + 2
    assert p1.check is None and p2.check is None


@needs_pdfs
def test_mirrored_photo_keeps_page_orientation(tmp_path):
    """Garden p.9: la foto e' specchiata in pagina; il ritaglio deve somigliare al render."""
    from arnoplast.extract.photos import load_image, mean_diff, render_region
    pdf = SOURCES["garden"]
    im = choose_photo(pdf_images(pdf)[9], _band("garden", 9, 0)[1])
    assert im.flip_x
    raw = load_image(pdf, im)
    seen = render_region(pdf, 9, (im.x0, im.y0, im.x1, im.y1), round(im.ppi))
    assert mean_diff(raw, seen) < 25
    assert mean_diff(raw.transpose(Image.FLIP_LEFT_RIGHT), seen) > mean_diff(raw, seen)


@needs_pdfs
def test_no_photo_override_writes_nothing(tmp_path):
    out = tmp_path / "x.png"
    assert extract_photo(SOURCES["agricoltura"], 68, (0, PAGE_H), out, NO_PHOTO) is None
    assert not out.exists()


# --- manifest versionato -----------------------------------------------------------

def test_photos_manifest_covers_catalog_and_lists_checks():
    photos = json.loads((ROOT / "data" / "photos.json").read_text(encoding="utf-8"))
    c = load_catalog(ROOT / "data" / "catalog.json")
    images = {p.image for l in c.lines for f in l.families for p in f.products}
    assert images <= set(photos)
    checks = {k for k, v in photos.items() if v["check"]}
    assert len(checks) <= 5
    nophoto = {f"images/{line}/{slug}.jpg" for (line, slug), v in PHOTO_OVERRIDES.items() if v == NO_PHOTO}
    assert nophoto <= checks
    for k, v in photos.items():
        if v["size"]:
            assert v["size"][0] >= 100 and v["size"][1] >= 100, k
