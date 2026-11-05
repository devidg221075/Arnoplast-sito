"""Struttura del sito: menu e sottomenu, voce attiva, lingua e /en/, footer, CONTACTS, Azienda, Imballaggi, Fiere,
Cataloghi, redirect nuovi, sitemap."""
import json
import re
from datetime import date
from pathlib import Path

import pytest

from arnoplast.catalog import load_catalog
from arnoplast.site import build as B
from arnoplast.site.redirects import load_redirects

ROOT = Path(__file__).resolve().parent.parent
TOP = ["Home", "Azienda", "Fiere", "Prodotti", "Galleria", "Video e Tutorial", "Contatti"]
SUBS = {"Azienda": ["Chi siamo", "Imballaggi"], "Prodotti": ["Agricoltura", "Garden", "Raccolta olive", "Cataloghi e download"]}


@pytest.fixture(scope="module")
def site(tmp_path_factory):
    mp = pytest.MonkeyPatch()
    mp.setenv("ARNO_BUILD_DATE", "2026-10-31")
    out = tmp_path_factory.mktemp("site")
    B.build_site(load_catalog(ROOT / "data" / "catalog.json"), out)
    mp.undo()
    return out


def html(out, rel):
    return (out / rel / "index.html").read_text(encoding="utf-8")


def nav_html(h):
    return re.search(r'<nav id="site-menu".*?</nav>', h, re.S).group(0)


def top_labels(h):
    n = nav_html(h)
    menu = re.search(r'<ul class="menu">(.*)</ul>\s*<div class="nav-aside">', n, re.S).group(1)
    menu = re.sub(r'<ul class="sub".*?</ul>', "", menu, flags=re.S)
    return [re.sub(r"<[^>]+>", "", x).strip() for x in re.findall(r'<li[^>]*>\s*(?:<a [^>]*>|<button [^>]*>)(.*?)(?:</a>|<svg)', menu, re.S)]


def all_pages(out):
    return [p for p in out.rglob("index.html")]


# --- menu ---------------------------------------------------------------------------------------------

def test_menu_order_and_submenus_on_every_page(site):
    pages = [p for p in all_pages(site) if p.relative_to(site).parts[0] != "en"]  # home inglese: menu in inglese, testato a parte
    assert len(pages) > 150
    for p in pages:
        h = p.read_text(encoding="utf-8")
        assert top_labels(h)[:7] == TOP, p
        n = nav_html(h)
        for parent, kids in SUBS.items():
            btn = re.search(rf'<button type="button" class="menu-btn" aria-expanded="false" aria-controls="(sub-\d+)">{parent}<', n)
            assert btn, (p, parent)
            sub = re.search(rf'<ul class="sub" id="{btn.group(1)}">(.*?)</ul>', n, re.S).group(1)
            assert [re.sub(r"<[^>]+>", "", x).strip() for x in re.findall(r"<a [^>]*>(.*?)</a>", sub)] == kids
        assert "Applicazioni" not in n
        assert 'class="reserved-link" href="/it/area-riservata/"' in n
        assert '<ul class="lang"' in n and 'href="/en/" hreflang="en"' in n and 'href="/it/" hreflang="it"' in n
        assert "/static/css/nav.css" in h and "nav.js" not in h  # il menu viaggia in coda ad anim.js


@pytest.mark.parametrize("rel,current,parent", [
    ("it", "Home", None),
    ("it/azienda", "Chi siamo", "Azienda"),
    ("it/azienda/imballaggi", "Imballaggi", "Azienda"),
    ("it/fiere", "Fiere", None),
    ("it/agricoltura", "Agricoltura", "Prodotti"),
    ("it/cataloghi", "Cataloghi e download", "Prodotti"),
    ("it/galleria", "Galleria", None),
    ("it/video", "Video e Tutorial", None),
    ("it/contatti", "Contatti", None),
])
def test_active_menu_item(site, rel, current, parent):
    n = nav_html(html(site, rel))
    cur = re.findall(r'<a [^>]*aria-current="page"[^>]*>([^<]+)<', n)
    assert cur == [current]
    actives = re.findall(r'<li class="has-sub is-active">\s*<button[^>]*>([^<]+)<', n)
    assert actives == ([parent] if parent else [])


def test_subpages_highlight_their_section(site):
    n = nav_html(html(site, "it/agricoltura/copripalo/copripalo-prisma"))
    assert re.findall(r'<a href="([^"]+)" aria-current="true">', n) == ["/it/agricoltura/"]
    assert 'aria-current="page"' not in n.split('<div class="nav-aside">')[0]
    assert re.search(r'<li class="has-sub is-active">\s*<button[^>]*>Prodotti<', n)
    for rel in ("it/richiesta-preventivo", "it/cookie"):
        assert "is-active" not in nav_html(html(site, rel))
    assert 'class="reserved-link" href="/it/area-riservata/" aria-current="page"' in html(site, "it/area-riservata")


def test_nav_state_picks_longest_match():
    items = B.nav_state("/it/azienda/imballaggi/", "it")
    az = next(i for i in items if i["key"] == "menu.company")
    assert az["active"] and [c.get("current") for c in az["children"]] == [None, True]
    assert not any(i.get("active") for i in B.nav_state("/it/richiesta-preventivo/", "it"))


def test_nav_js_is_classic_and_accessible():
    js = (ROOT / "site/static/js/nav.js").read_text(encoding="utf-8")
    assert "import " not in js and "export " not in js
    for k in ("Escape", "ArrowDown", "ArrowUp", "aria-expanded", "focusout"):
        assert k in js
    css = (ROOT / "site/static/css/nav.css").read_text(encoding="utf-8")
    assert ":hover > .sub" in css and ":focus-within > .sub" in css
    assert (ROOT / "site/static/css/main.css").stat().st_size < 30_000
    assert (ROOT / "site/static/css/nav.css").stat().st_size < 12_000


# --- lingua ---------------------------------------------------------------------------------------------

def test_published_anim_js_carries_the_menu(site):
    pub = (site / "static/js/anim.js").read_text(encoding="utf-8")
    src = ROOT / "site/static/js"
    assert pub.startswith((src / "anim.js").read_text(encoding="utf-8").rstrip())
    assert pub.endswith((src / "nav.js").read_text(encoding="utf-8"))


def test_english_home_page(site):
    h = html(site, "en")
    assert '<meta name="robots" content="noindex">' in h
    assert '<html lang="en">' in h
    assert "Plastic accessories for those who work the land" in h
    assert 'href="/en/azienda/"' in h and 'href="/it/azienda/"' not in h  # link tutti in inglese, il selettore lingua resta su /en/
    assert re.search(r'href="/en/" hreflang="en" lang="en" aria-current="true"', h)
    labels = top_labels(h)
    assert 'class="site-footer"' in h and labels[0] == "Home" and "Company" in labels and "Contacts" in labels
    assert re.search(r'href="/it/" hreflang="it" lang="it"', h)
    assert re.search(r'href="/it/" hreflang="it" lang="it" aria-current="true"', html(site, "it"))


# --- footer e dati societari ----------------------------------------------------------------------------

def test_contacts_data():
    c = B.CONTACTS
    assert c["name"] == "ARNO PLAST S.r.l."
    assert c["street"] == "Via Comunale dell'Isola 65/B/C/D" and c["city"] == "50067 Rignano sull'Arno (FI)"
    assert c["country"] == "ITALY" and c["vat"] == "01987190483" and c["rea"] == "FI355293"
    assert c["capital"] == "49.580,00 i.v." and c["email"] == "info@arnoplast.it"
    assert c["phones"] == [("+39 055 83 47 031", "+390558347031"), ("+39 055 83 47 033", "+390558347033")]
    assert "fax" not in c and "Commerciale" not in json.dumps(c)


def test_footer_on_every_page(site):
    for p in [p for p in all_pages(site) if p.relative_to(site).parts[0] != "en"]:  # la home inglese ha la footer in inglese
        h = p.read_text(encoding="utf-8")
        f = re.search(r'<footer class="site-footer">.*?</footer>', h, re.S).group(0)
        assert "arnoplast-logo-negativo.svg" in f
        for s in ("ARNO PLAST S.r.l.", "Via Comunale dell&#39;Isola 65/B/C/D", "50067 Rignano sull&#39;Arno (FI) - ITALY",
                  "P.IVA 01987190483", "Iscr. Rea FI355293", "Cap. Soc. 49.580,00 i.v."):
            assert s in f, (p, s)
        assert 'href="tel:+390558347031"' in f and 'href="tel:+390558347033"' in f
        assert 'href="mailto:info@arnoplast.it"' in f
        for url in ("https://www.facebook.com/arnoplast", "https://it.linkedin.com/company/arnoplast-s-r-l",
                    "https://www.instagram.com/arnoplast/", "https://apps.apple.com/it/app/arno-plast/id1534894744",
                    "https://play.google.com/store/apps/details?id=com.arnoplast.keyidea&amp;hl=it&amp;gl=IT"):
            assert f'<a href="{url}" target="_blank" rel="noopener">' in f or f'href="{url}" target="_blank" rel="noopener"' in f, url
        light = re.search(r'<nav class="wrap ft-nav".*?</nav>', f, re.S).group(0)
        labels = [re.sub(r"<[^>]+>", "", x).strip() for x in re.findall(r"<a [^>]*>(.*?)</a>", light, re.S)]
        assert labels == TOP + ["Area riservata", "Cookie e privacy"]  # una sola voce: la pagina rimanda alla privacy policy
        assert 'href="/it/cookie/"' in light and B.PRIVACY_URL not in light
        assert "© 2026 ARNOplast. Tutti i diritti riservati." in f
        assert "Commerciale" not in f and "Vittorio" not in f


def test_footer_year_follows_build_date(tmp_path, monkeypatch):
    monkeypatch.setenv("ARNO_BUILD_DATE", "2031-01-02")
    B.build_site(load_catalog(ROOT / "data" / "catalog.json"), tmp_path)
    assert "© 2031 ARNOplast." in html(tmp_path, "it/contatti")


def test_contacts_page_and_home_use_new_data(site):
    h = html(site, "it/contatti")
    assert "ARNO PLAST S.r.l." in h and 'href="tel:+390558347033"' in h and "Fax" not in h
    assert B.MAPS_URL.startswith("https://www.google.com/maps/") and "<iframe" not in h
    home = html(site, "it")
    assert 'href="tel:+390558347031"' in home and "Via Comunale dell&#39;Isola" in home


# --- Azienda ----------------------------------------------------------------------------------------------

def test_company_page_has_client_history_verbatim(site):
    h = html(site, "it/azienda").replace("&#39;", "'")
    for s in ("Da dove siamo nati", "Arno Plast - La storia", "Chi siamo oggi, cosa facciamo e dove vogliamo andare", "Arno Plast oggi",
              "ARNOplast s.r.l. nasce nel 1975 come ARNOstampi sdf.",
              "Nel 1983 la nuova denominazione ARNOplast s.r.l. contraddistingue il primo allineamento strategico. L’azienda implementa",
              "A metà anni Ottanta inizia anche una limitata produzione di accessori a proprio marchio",
              "Come risultato di questa situazione decide di trarre il massimo dalle proprie capacità innovative.",
              "la ARNOcommerciale s.r.l.",
              "posizione leader nel mercato italiano.", "Mediterraneo e in diversi Paesi oltre oceano."):
        assert s in h, s
    years = re.findall(r'<p class="co-year"><span>([^<]+)</span>', h)
    assert years == ["1975", "1983", "Anni Ottanta", "Anni Novanta", "Oggi"]
    assert 'data-reveal="stagger"' in h and 'class="hx-media" data-parallax' in h
    assert 'href="/it/azienda/imballaggi/"' in h and 'href="/it/contatti/"' in h
    assert f'href="{B.MAPS_URL}"'.replace("&", "&amp;") in html(site, "it/azienda") and "<iframe" not in h
    assert "co-photos" in h  # i dati gia' presenti restano


# --- Imballaggi ------------------------------------------------------------------------------------------

def test_packaging_page_hero_day_night(site):
    h = html(site, "it/azienda/imballaggi")
    assert "<title>Imballaggi - ARNOplast</title>" in h
    img = re.findall(r'<img\b[^>]*fetchpriority="high"[^>]*>', h)
    assert len(img) == 1 and "imballaggi-giorno-" in img[0].split("data-night-srcset")[0]
    night = re.findall(r'data-night-srcset="([^"]+)"', h)
    assert len(night) == 2 and all("imballaggi-notte" in n for n in night)
    assert "luce=(notte|giorno)" in h and "getHours()" in h


def test_packaging_content_is_faithful_to_old_page(site):
    """Testo e tabella sono quelli del sito vecchio (pagina salvata dal cliente), senza aggiunte."""
    h = html(site, "it/azienda/imballaggi")
    assert "Contenuto in arrivo" not in h
    assert "<h2 class=\"pk-h\"" in h and "I nostri imballaggi" in h
    assert "decreto legislativo 3 settembre 2020, n. 116" in h
    assert "<strong>etichettatura ambientale</strong>" in h and "<strong>corretto smaltimento</strong>" in h
    assert "tabella sotto riportata" in h and "Ricorda di verificare sempre le disposizioni del tuo Comune." in h
    cards = re.findall(r'<li class="pk-card pk-(\w+)">\s*<h3 class="pk-name">([^<]+)</h3>.*?<p class="pk-code">.*?</span>([^<]+)</p>\s*<p class="pk-coll">([^<]+)</p>', h, re.S)
    assert len(cards) == 14
    got = {(n, c.strip(), r) for _, n, c, r in cards}
    assert ("Scatola Cartone", "PAP 20", "Raccolta Carta") in got
    assert ("Etichetta Adesiva", "PAP 20", "Indifferenziata") in got
    assert ("Bancale Legno", "FOR 50", "Raccolta Isola Ecologica") in got
    assert ("Flange e Tubi Cartoni Rocche", "PAP 21", "Raccolta Carta") in got
    assert sorted({k for k, *_ in cards}) == ["carta", "indif", "isola", "plastica"]


def test_packaging_empty_data_shows_coming_soon(tmp_path):
    f = tmp_path / "vuoto.json"
    f.write_text(json.dumps({"titolo": "Imballaggi", "paragrafi": []}), encoding="utf-8")
    d = B.load_packaging(f)
    assert d["paragrafi"] == [] and d["tabella"] == [] and d["sezione"] == ""


def test_packaging_text_comes_from_data_file(tmp_path):
    f = tmp_path / "imb.json"
    f.write_text(json.dumps({"titolo": "Imballaggi", "paragrafi": ["Primo.", " ", "Secondo."],
                             "tabella": [{"imballaggio": "Cassa", "codice": "FOR 50", "raccolta": "Raccolta Isola Ecologica"},
                                         {"imballaggio": "Altro", "codice": "X", "raccolta": "Boh"}]}), encoding="utf-8")
    d = B.load_packaging(f)
    assert d["paragrafi"] == ["Primo.", "Secondo."]
    assert [r["tipo"] for r in d["tabella"]] == ["isola", "altro"]


def test_rich_escapes_everything_but_bold():
    assert str(B.rich("a **b** <script>x</script> & c")) == "a <strong>b</strong> &lt;script&gt;x&lt;/script&gt; &amp; c"


def test_packaging_webp_files_small():
    files = sorted((ROOT / "site/static/img/home").glob("imballaggi-*.webp"))
    names = {f.stem for f in files}
    for k in ("giorno", "notte"):
        assert {f"imballaggi-{k}-800", f"imballaggi-{k}-2000", f"imballaggi-{k}-alto-840"} <= names
    for f in files:
        assert f.stat().st_size <= 250 * 1024, f


# --- Fiere ------------------------------------------------------------------------------------------------

def test_fairs_data_structure():
    fairs = B.load_fairs()
    assert len(fairs) == 30 and len({f["id"] for f in fairs}) == 30
    for f in fairs:
        assert set(B.FAIR_FIELDS) <= set(f)
        assert f["fonte_url_sito_vecchio"].startswith("https://www.arnoplast.it/")
        if f["data_inizio"]:
            assert date.fromisoformat(f["data_fine"]) >= date.fromisoformat(f["data_inizio"])
            assert int(f["data_inizio"][:4]) == f["anno"]
    by = {f["id"]: f for f in fairs}
    assert by["fruit-attraction-2026"]["stand"] == "Padiglione 8, Stand A15"
    assert (by["interpoma-2026"]["data_inizio"], by["interpoma-2026"]["data_fine"]) == ("2026-11-25", "2026-11-27")
    assert by["eima-2026"]["data_inizio"] == "2026-11-10" and by["luv-2026"]["data_fine"] == "2026-10-22"
    # Date incerte nel sito vecchio: vuote, mai inventate.
    for k in ("eima-2021", "fruit-logistica-2021"):
        assert by[k]["data_inizio"] == by[k]["data_fine"] == "" and by[k]["periodo"] == "Febbraio 2021"
    assert by["luv-2026"]["luogo"] == "" and by["interpoma-2018"]["luogo"] == ""


def test_fairs_split_with_fixed_date():
    fairs = B.load_fairs()
    up, past = B.split_fairs(fairs, date(2026, 10, 31))
    assert [f["id"] for f in up] == ["eima-2026", "interpoma-2026"]
    assert [y for y, _ in past] == sorted({y for y, _ in past}, reverse=True) and past[0][0] == 2026
    assert [f["id"] for f in past[0][1]] == ["luv-2026", "fruit-attraction-2026", "agri-lazio-expo-2026",
                                             "myplant-garden-2026", "fruit-logistica-2026"]
    up2, _ = B.split_fairs(fairs, date(2026, 9, 1))
    assert [f["id"] for f in up2][:2] == ["agri-lazio-expo-2026", "fruit-attraction-2026"] and len(up2) == 5
    assert B.split_fairs(fairs, date(2026, 11, 14))[0][0]["id"] == "eima-2026"  # l'ultimo giorno e' ancora "prossima"
    assert [f["id"] for f in B.split_fairs(fairs, date(2026, 11, 15))[0]] == ["interpoma-2026"]


def test_fair_dates_labels():
    f = {"data_inizio": "2023-11-30", "data_fine": "2023-12-03", "periodo": "", "anno": 2023}
    assert B.fair_dates(f) == "30 novembre – 3 dicembre 2023"
    assert B.fair_dates(dict(f, data_inizio="2026-11-10", data_fine="2026-11-14")) == "10–14 novembre 2026"
    assert B.fair_dates(dict(f, data_inizio="", data_fine="", periodo="Febbraio 2021")) == "Febbraio 2021"


def test_bad_fairs_file_is_a_clear_error(tmp_path):
    f = tmp_path / "f.json"
    f.write_text(json.dumps({"fiere": [{"id": "x"}]}), encoding="utf-8")
    with pytest.raises(ValueError, match="senza"):
        B.load_fairs(f)


def test_home_hero_first_slide_is_eima_with_the_client_photo(site):
    """Stato reale: la prima slide dell'hero e' EIMA, con la foto del cliente e la priorita' alta sull'immagine."""
    h = html(site, "it")
    first = re.findall(r'<img[^>]*fetchpriority="high"[^>]*>', h)
    assert len(first) == 1 and "eima-2026-hero-" in first[0]
    assert 'data-fair-end="2026-11-14"' in h and 'data-fair-end="2026-11-27"' in h
    # Slide fiera "pulita": il markup parte gia' con la classe, cosi' il titolo fisso non lampeggia sopra la foto.
    assert re.search(r'<section class="a-hero hs is-fairslide"', h)
    css = (ROOT / "site/static/css/home.css").read_text(encoding="utf-8")
    assert ".js .hs.is-fairslide .a-ctas" in css and ".js .hs.is-fairslide .hx-title" in css
    js = (ROOT / "site/static/js/hero-slides.js").read_text(encoding="utf-8")
    assert js.count("is-fairslide") >= 2


def test_fairs_page(site):
    h = html(site, "it/fiere")
    assert '<section class="page-head">' in h and 'class="hx ' not in h  # hero compatto
    nxt = re.search(r'id="prossime".*?</section>', h, re.S).group(0)
    assert re.findall(r'<li class="fr-card" id="([^"]+)"', nxt) == ["eima-2026", "interpoma-2026"]
    assert nxt.count('href="/it/contatti/"') == 2 and "10–14 novembre 2026" in nxt
    past = re.search(r'id="passate".*?</section>', h, re.S).group(0)
    years = [int(y) for y in re.findall(r'<h3 class="fr-y">(\d{4})</h3>', past)]
    assert years == sorted(years, reverse=True) and years[0] == 2026 and years[-1] == 2014
    assert "<details>" in past and "Padiglione 8, Stand A15" in past and "Hall 34 stand D2" in past
    assert "Open Day" in h and "Struttura aperta alle visite del pubblico — in arrivo." in h
    # Le due prossime fiere hanno la foto del cliente (content/photos/fiere): schede con immagine, senza fallback tipografico.
    assert nxt.count("<img") == 2 and "eima-2026-480.webp" in nxt and "interpoma-2026-480.webp" in nxt
    past_imgs = re.search(r'id="passate".*?</section>', h, re.S).group(0)
    assert "<img" not in past_imgs


# --- Cataloghi, segnaposto, redirect, sitemap ----------------------------------------------------------

def test_catalogs_page(site):
    h = html(site, "it/cataloghi")
    pdfs = re.findall(r'<a class="btn btn-ghost" href="(/downloads/[^"]+\.pdf)" download>', h)
    assert pdfs == [f"/downloads/{pub}" for _, pub in B.CATALOG_PDFS.values()]
    for url in pdfs:
        f = site / url.lstrip("/")
        assert f.is_file() and f"{f.stat().st_size / 1e6:.1f}".replace(".", ",") + " MB" in h
    assert "Catalogo 2026" in h and "Catalogo 2025" in h
    assert "apps.apple.com" in h and "play.google.com" in h


def test_placeholders_and_old_applications_url(site):
    assert "<title>Galleria - ARNOplast</title>" in html(site, "it/galleria")
    assert "<title>Video e Tutorial - ARNOplast</title>" in html(site, "it/video")
    assert "Il sito non imposta cookie" in html(site, "it/cookie") and "in preparazione" not in html(site, "it/cookie")
    moved = html(site, "it/applicazioni")
    assert 'content="0; url=/it/galleria/"' in moved and "noindex" in moved
    assert "/it/applicazioni/ /it/galleria/ 301" in (site / "_redirects").read_text(encoding="utf-8")


def test_new_redirects():
    rows = dict(load_redirects(ROOT / "data/redirects.csv"))
    olds = [o for o, _ in load_redirects(ROOT / "data/redirects.csv")]
    assert len(olds) == len(set(olds))
    want = {"/arno-plast-la-nostra-storia-e-chi-siamo": "/it/azienda/", "/imballaggi": "/it/azienda/imballaggi/",
            "/fiere-passate": "/it/fiere/", "/fiere-future": "/it/fiere/", "/open-day": "/it/fiere/",
            "/categoria/fiere-passate": "/it/fiere/", "/categoria/fiere-future": "/it/fiere/",
            "/eima-7-11-novembre-2018.html": "/it/fiere/", "/galleria-fotografica-accessori-impianti": "/it/galleria/",
            "/area-download": "/it/cataloghi/", "/sfoglia-catalogo-garden": "/it/cataloghi/",
            "/video-arno-plast": "/it/video/", "/tutorial-agricoltura": "/it/video/",
            "/video-tutorial-giardinaggio": "/it/video/", "/contatti-arno-plast": "/it/contatti/",
            "/richiedi-preventivo-o-informazioni": "/it/richiesta-preventivo/",
            "/arno-plast-articoli-in-plastica-per-agricoltura-e-giardinaggio/restricted-area": "/it/area-riservata/",
            "/en": "/en/", "/en/our-history-and-who-we-are": "/en/", "/applicazioni": "/it/galleria/"}
    for o, n in want.items():
        assert rows.get(o) == n, o
    assert "/privacy-policy-arno-plast" not in rows
    assert rows["/catalogo-agricoltura-professionale/copripalo-arno-plast"] == "/it/agricoltura/copripalo/"


def test_sitemap_has_new_pages_and_not_english(site):
    locs = re.findall(r"<loc>([^<]+)</loc>", (site / "sitemap.xml").read_text(encoding="utf-8"))
    for rel in ("azienda/", "azienda/imballaggi/", "fiere/", "cataloghi/", "galleria/", "video/", "cookie/"):
        assert f"{B.SITE_URL}/it/{rel}" in locs
    assert not any("/en/" in u or "applicazioni" in u for u in locs)


def test_no_external_requests_on_new_pages(site):
    for rel in ("it/azienda", "it/azienda/imballaggi", "it/fiere", "it/cataloghi", "en"):
        h = html(site, rel)
        assert not re.search(r'\s(?:src|srcset)="https?://', h) and not re.search(r'<link[^>]+href="https?://(?!www\.arnoplast\.it)', h)
        assert "<iframe" not in h and "fonts.googleapis" not in h
