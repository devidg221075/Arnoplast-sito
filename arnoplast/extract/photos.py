"""Foto prodotto ritagliate dalle pagine dei cataloghi.

Ogni fascia prodotto (dal titolo al titolo successivo, vedi build.py) contiene
una grande foto del prodotto, oltre a disegni tecnici, icone e QR code. Le
immagini incorporate nella pagina, con la loro posizione, si leggono da
`pdftohtml -xml`; la foto è l'immagine più grande della fascia (le icone e i
QR code sono scartati per dimensione). Il ritaglio si fa sul render della
pagina (così maschere, ritagli e ombre restano come stampati) alla risoluzione
nativa dell'immagine: nessun ingrandimento.

Se nella fascia non c'è un'immagine adatta, o quella scelta sembra un disegno
tecnico (quasi tutta bianca), si usa il ritaglio migliore disponibile e il
prodotto finisce nella lista "da controllare" (`check` nel manifest).
"""
from __future__ import annotations

import re
import subprocess
import tempfile
from dataclasses import asdict, dataclass
from functools import lru_cache
from pathlib import Path

from arnoplast.extract.pdf import PAGE_H, PAGE_W

# immagini più piccole di così (pt) sono icone, loghi, QR code
MIN_SIDE = 90
MIN_AREA = 120 * 120
# parte dell'immagine che deve stare nella fascia del prodotto
MIN_INSIDE = 0.6
# oltre questa quota di pixel quasi bianchi la "foto" è probabilmente un disegno
MAX_WHITE = 0.80
MIN_DPI = 72

_IMG_RE = re.compile(r'<image top="(-?[\d.]+)" left="(-?[\d.]+)" width="(-?[\d.]+)" '
                     r'height="(-?[\d.]+)" src="([^"]+)"/>')
_PAGE_RE = re.compile(r'<page number="(\d+)"')


@dataclass(frozen=True)
class PageImage:
    x0: float
    y0: float
    x1: float
    y1: float
    px_w: int  # pixel dell'immagine incorporata
    px_h: int
    src: str = ""  # file estratto da pdftohtml
    flip_x: bool = False  # in pagina è specchiata orizzontalmente
    flip_y: bool = False
    gray: bool = False  # in scala di grigi (spesso la maschera/sfumatura di una foto)

    @property
    def area(self) -> float:
        return (self.x1 - self.x0) * (self.y1 - self.y0)

    @property
    def ppi(self) -> float:
        """Risoluzione nativa (pixel per pollice) alla dimensione stampata."""
        return self.px_w / ((self.x1 - self.x0) / 72)


@dataclass
class Photo:
    page: int
    bbox: tuple[float, float, float, float]  # pt, x0 y0 x1 y1
    dpi: int  # risoluzione rispetto alla dimensione stampata
    size: tuple[int, int]  # pixel del file scritto
    check: str | None  # motivo per cui va controllata a mano (None = affidabile)

    def to_json(self) -> dict:
        return asdict(self)


def _clip(x0: float, y0: float, x1: float, y1: float) -> tuple[float, float, float, float]:
    return max(0.0, x0), max(0.0, y0), min(float(PAGE_W), x1), min(float(PAGE_H), y1)


def parse_images_xml(xml: str, sizes: dict[str, tuple[int, int]],
                     gray: set[str] = frozenset()) -> dict[int, list[PageImage]]:
    """Immagini per pagina dall'XML di `pdftohtml -xml -zoom 1` (coordinate in pt).

    Le immagini con larghezza/altezza negative (specchiate) vengono normalizzate;
    quelle fuori pagina scartate."""
    out: dict[int, list[PageImage]] = {}
    page = 0
    for line in xml.splitlines():
        m = _PAGE_RE.search(line)
        if m:
            page = int(m.group(1))
            out.setdefault(page, [])
            continue
        m = _IMG_RE.search(line)
        if not m or not page:
            continue
        top, left, w, h = (float(v) for v in m.groups()[:4])
        x0, x1 = sorted((left, left + w))
        y0, y1 = sorted((top, top + h))
        cx0, cy0, cx1, cy1 = _clip(x0, y0, x1, y1)
        if cx1 - cx0 < 1 or cy1 - cy0 < 1:
            continue
        name = Path(m.group(5)).name
        px = sizes.get(name, (0, 0))
        # se l'immagine esce dalla pagina, i pixel visibili si riducono in proporzione
        fx = (cx1 - cx0) / (x1 - x0)
        fy = (cy1 - cy0) / (y1 - y0)
        out[page].append(PageImage(cx0, cy0, cx1, cy1, round(px[0] * fx), round(px[1] * fy),
                                   name, w < 0, h < 0, name in gray))
    return out


_TMP: list[tempfile.TemporaryDirectory] = []


@lru_cache(maxsize=4)
def _extracted(pdf: Path) -> tuple[Path, dict[int, list[PageImage]]]:
    """Estrae (una volta per processo) le immagini del PDF con `pdftohtml -xml`."""
    from PIL import Image
    tmp = tempfile.TemporaryDirectory(prefix="arnoplast-img-")
    _TMP.append(tmp)  # rimossa all'uscita del processo
    base = Path(tmp.name) / "p"
    subprocess.run(["pdftohtml", "-xml", "-zoom", "1", "-q", str(pdf), str(base)],
                   check=True, capture_output=True)
    sizes, gray = {}, set()
    for f in Path(tmp.name).iterdir():
        if f.suffix.lower() in (".jpg", ".png", ".jpeg", ".ppm", ".pbm"):
            try:
                with Image.open(f) as im:
                    sizes[f.name] = im.size
                    if im.mode in ("L", "1", "LA"):
                        gray.add(f.name)
            except OSError:
                pass
    xml = base.with_suffix(".xml").read_text(encoding="utf-8", errors="replace")
    return Path(tmp.name), parse_images_xml(xml, sizes, gray)


def pdf_images(pdf: Path) -> dict[int, list[PageImage]]:
    """Immagini incorporate di tutte le pagine (`pdftohtml -xml`), con posizione e pixel."""
    return _extracted(Path(pdf))[1]


def load_image(pdf: Path, im: PageImage):
    """Pixel dell'immagine incorporata (RGB su bianco), orientata come in pagina."""
    from PIL import Image, ImageOps
    with Image.open(_extracted(Path(pdf))[0] / im.src) as raw:
        if raw.mode in ("RGBA", "LA", "P"):
            raw = raw.convert("RGBA")
            bg = Image.new("RGBA", raw.size, (255, 255, 255, 255))
            img = Image.alpha_composite(bg, raw).convert("RGB")
        elif raw.mode == "CMYK":
            img = ImageOps.invert(raw.convert("RGB")) if raw.info.get("adobe") else raw.convert("RGB")
        else:
            img = raw.convert("RGB")
    if im.flip_x:
        img = ImageOps.mirror(img)
    if im.flip_y:
        img = ImageOps.flip(img)
    return img


def _overlap(a: PageImage, b: PageImage) -> float:
    """Area comune / area della più piccola."""
    w = min(a.x1, b.x1) - max(a.x0, b.x0)
    h = min(a.y1, b.y1) - max(a.y0, b.y0)
    return max(0.0, w) * max(0.0, h) / min(a.area, b.area) if w > 0 and h > 0 else 0.0


def choose_photo(images: list[PageImage], box: tuple[float, float]) -> PageImage | None:
    """L'immagine più grande che sta (per almeno MIN_INSIDE) nella fascia y0..y1.

    Un'immagine in grigi sovrapposta quasi del tutto a una a colori è la sua
    maschera (sfumatura dei bordi): conta quella a colori."""
    y0, y1 = box
    best = None
    for im in images:
        if im.gray and any(o is not im and not o.gray and _overlap(im, o) > 0.9 for o in images):
            continue
        w, h = im.x1 - im.x0, im.y1 - im.y0
        if min(w, h) < MIN_SIDE or im.area < MIN_AREA:
            continue
        inside = max(0.0, min(im.y1, y1) - max(im.y0, y0)) * w
        if inside / im.area < MIN_INSIDE:
            continue
        if best is None or im.area > best.area:
            best = im
    return best


def render_region(pdf: Path, page: int, bbox: tuple[float, float, float, float], dpi: int):
    """Render PIL (RGB) della zona `bbox` (pt) della pagina a `dpi`."""
    from PIL import Image
    x0, y0, x1, y1 = bbox
    s = dpi / 72
    with tempfile.TemporaryDirectory() as tmp:
        out = Path(tmp) / "r"
        subprocess.run(["pdftoppm", "-r", str(dpi), "-png", "-f", str(page), "-l", str(page),
                        "-singlefile", "-x", str(round(x0 * s)), "-y", str(round(y0 * s)),
                        "-W", str(max(1, round((x1 - x0) * s))), "-H", str(max(1, round((y1 - y0) * s))),
                        str(pdf), str(out)], check=True, capture_output=True)
        with Image.open(out.with_suffix(".png")) as im:
            return im.convert("RGB")


def trim_lines(im, max_frac: float = 0.06):
    """Toglie dai bordi righe/colonne rosse (cornici e bande dei titoli che sconfinano
    sulla foto), fino a `max_frac` del lato."""
    from PIL import Image
    rgb = im.convert("RGB")
    w, h = rgb.size
    px = rgb.load()

    def colored(line) -> bool:
        n = sum(1 for r, g, b in line if r > 150 and g < 110 and b < 110)
        return n > 0.5 * len(line)

    top, bottom, left, right = 0, h, 0, w
    while top < h * max_frac and colored([px[x, top] for x in range(w)]):
        top += 1
    while h - bottom < h * max_frac and colored([px[x, bottom - 1] for x in range(w)]):
        bottom -= 1
    while left < w * max_frac and colored([px[left, y] for y in range(top, bottom)]):
        left += 1
    while w - right < w * max_frac and colored([px[right - 1, y] for y in range(top, bottom)]):
        right -= 1
    return rgb.crop((left, top, right, bottom)) if (top, bottom, left, right) != (0, h, 0, w) else im


def whiten(im, lo: int = 232, hi: int = 246):
    """Porta a bianco puro i fondi quasi bianchi (le foto di studio hanno fondi #f2-#f7):
    sopra `lo` i valori salgono in modo continuo fino a 255 da `hi` in su. Cosi' sul fondo
    neutro della griglia (mix-blend-mode: multiply) non resta un rettangolo grigiastro."""
    lut = [v if v <= lo else min(255, round(lo + (v - lo) * (255 - lo) / (hi - lo))) for v in range(256)]
    return im.convert("RGB").point(lut * 3)


def trim_white(im, pad: int = 4):
    """Toglie i bordi bianchi uniformi (lasciando `pad` px)."""
    from PIL import ImageChops, Image
    bg = Image.new("RGB", im.size, (255, 255, 255))
    diff = ImageChops.difference(im, bg).convert("L").point(lambda v: 255 if v > 18 else 0)
    bb = diff.getbbox()
    if not bb:
        return im
    x0, y0, x1, y1 = bb
    return im.crop((max(0, x0 - pad), max(0, y0 - pad), min(im.width, x1 + pad), min(im.height, y1 + pad)))


# Correzioni dopo il controllo a occhio del foglio di contatto, per (linea, slug):
# - (x, y): punto in pt dentro la foto giusta (l'immagine più piccola che lo contiene),
#   quando la più grande della fascia è una foto d'ambiente;
# - (x0, y0, x1, y1): zona da ritagliare dal render (foto composte da più immagini);
# - NO_PHOTO: la fascia non ha una foto del prodotto (solo tabelle).
NO_PHOTO = "nessuna"
PHOTO_OVERRIDES: dict[tuple[str, str], tuple | str] = {
    ("agricoltura", "copripalo-latina-omega"): (90, 110),
    ("agricoltura", "placchetta-start"): (100, 150),
    ("agricoltura", "morsa-100"): (100, 340),
    ("agricoltura", "mosch-standard-metallico-e-rapid"): (5, 568, 235, 712),
    ("agricoltura", "elastico-premontato"): (20, 312, 170, 515),
    ("agricoltura", "tubetto-pvc"): (60, 470),
    ("garden", "testina-semiautomatica-diamante"): (200, 150, 596, 410),
    ("agricoltura", "ricambi-e-viterie"): NO_PHOTO,
    ("garden", "inserti-maschio-femmina-per-testine"): NO_PHOTO,
}
# Ritagli segnalati in automatico ma controllati a occhio e corretti (aste sottili
# su fondo bianco, scambiate per disegni tecnici).
PHOTO_CHECKED: set[tuple[str, str]] = {
    ("olive", "martino-light"), ("olive", "martino-eco"), ("olive", "oleo-luxor"),
}
# Oltre questa differenza media (0-255) tra il file incorporato e il render della
# stessa zona, il file non è la foto vista in pagina (maschere, sfumature sovrapposte):
# si usa il render.
MAX_RAW_DIFF = 40


def pick_at(images: list[PageImage], point: tuple[float, float]) -> PageImage | None:
    x, y = point
    hits = [im for im in images if im.x0 <= x <= im.x1 and im.y0 <= y <= im.y1]
    return min(hits, key=lambda im: im.area) if hits else None


def is_drawing(img) -> bool:
    """Quasi tutta bianca e senza colore: probabilmente un disegno tecnico o una tabella."""
    g = img.convert("RGB").resize((64, 64))
    px = [g.getpixel((x, y)) for y in range(64) for x in range(64)]
    ink = [(r, gg, b) for r, gg, b in px if min(r, gg, b) < 235]
    if len(ink) / len(px) > 1 - MAX_WHITE:
        return False
    sat = sum(max(p) - min(p) for p in ink) / max(1, len(ink))
    return sat < 25


def mean_diff(a, b) -> float:
    """Differenza media per pixel (0-255) tra due immagini, a 48x48."""
    from PIL import ImageChops, ImageStat
    a, b = a.convert("L").resize((48, 48)), b.convert("L").resize((48, 48))
    return ImageStat.Stat(ImageChops.difference(a, b)).mean[0]


def extract_photo(pdf: Path, page: int, box: tuple[float, float], out: Path,
                  override: tuple | str | None = None, checked: bool = False) -> Photo | None:
    """Foto del prodotto nella fascia `box` di `page`, scritta in `out` (PNG).

    Si usa il file dell'immagine incorporata (senza testi o cornici disegnati sopra);
    se non corrisponde a ciò che si vede in pagina, il render della sua zona. Senza
    un'immagine adatta ricade sul render della metà sinistra della fascia e segnala il
    prodotto da controllare. Con `override == NO_PHOTO` non scrive nulla (None)."""
    if override == NO_PHOTO:
        Path(out).unlink(missing_ok=True)
        return None
    images = pdf_images(Path(pdf)).get(page, [])
    check = None
    im = None
    if override and len(override) == 4:
        bbox, dpi = tuple(float(v) for v in override), 150
        inside = [i for i in images if i.x0 >= bbox[0] - 3 and i.x1 <= bbox[2] + 3
                  and i.y0 >= bbox[1] - 3 and i.y1 <= bbox[3] + 3]
        if inside:
            dpi = round(min(i.ppi for i in inside))
        img = render_region(Path(pdf), page, bbox, max(MIN_DPI, dpi))
    else:
        im = pick_at(images, override) if override else choose_photo(images, box)
        if im is None:
            y0, y1 = box
            bbox = (0.0, y0 + 24, PAGE_W / 2, min(y1, y0 + 24 + PAGE_W / 2 * 1.3))
            dpi = 150
            img = render_region(Path(pdf), page, bbox, dpi)
            check = "nessuna foto incorporata nella fascia: ritaglio della zona sinistra"
        else:
            bbox, dpi = (im.x0, im.y0, im.x1, im.y1), round(im.ppi)
            img = load_image(Path(pdf), im)
            seen = render_region(Path(pdf), page, bbox, max(MIN_DPI, dpi))
            if mean_diff(img, seen) > MAX_RAW_DIFF:
                img = seen
            if im.ppi < MIN_DPI:
                check = f"foto a bassa risoluzione ({im.ppi:.0f} ppi)"
    img = trim_white(trim_lines(trim_white(trim_lines(whiten(img)))))
    if check is None and is_drawing(img):
        check = "immagine quasi tutta bianca e senza colore: forse un disegno tecnico"
    if checked:
        check = None
    out = Path(out)
    out.parent.mkdir(parents=True, exist_ok=True)
    img.save(out, "PNG", optimize=True)
    return Photo(page, tuple(round(v, 1) for v in bbox), dpi, img.size, check)
