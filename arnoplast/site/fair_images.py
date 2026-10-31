"""Foto di anteprima delle fiere: content/photos/fiere/<file> -> site/static/img/fiere/<id>-<larghezza>.webp.

La foto di una fiera e' il file indicato nel campo `immagine` di data/fiere.json oppure, se il campo manca,
`<id>.jpg|.jpeg|.png|.webp`. Larghezze 480, 800 e 1200 px (mai oltre la risoluzione nativa: se la foto e' piu'
stretta, l'ultima larghezza e' quella nativa), proporzioni conservate (la scheda ritaglia a 16:10 con object-fit),
WebP qualita' 82; un file che supera 150 KB scende di qualita' a passi di 4 fino a rientrare (minimo 50; se nemmeno
cosi' rientra, quella larghezza si salta con un avviso e restano le minori: errore solo se non ne resta nessuna).
Versioni per l'hero della home (slide della fiera, a tutto schermo): <id>-hero-<larghezza>.webp a 800, 1280, 1600,
2000 e 2560 px (mai oltre la nativa), proporzioni conservate (in pagina object-fit: cover e object-position dal campo
`punto_focale`), qualita' 82; un file che supera 250 KB scende di qualita' fino a rientrare (minimo 40, poi come sopra).
Usato dalla build (genera solo le foto mancanti) e da tools/fiere_images.py (rigenera, es. foto sostituita).
"""
from __future__ import annotations

import sys
from io import BytesIO
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent.parent
SRC_DIR = ROOT / "content" / "photos" / "fiere"
OUT_DIR = ROOT / "site" / "static" / "img" / "fiere"
URL_PREFIX = "/static/img/fiere/"
WIDTHS = (480, 800, 1200)
QUALITY = 82
MIN_QUALITY = 50
MAX_BYTES = 150 * 1024
EXTS = (".jpg", ".jpeg", ".png", ".webp")
HERO_WIDTHS = (800, 1280, 1600, 2000, 2560)
HERO_MAX_BYTES = 250 * 1024
HERO_MIN_QUALITY = 40
HERO = "hero"  # file <id>-hero-<larghezza>.webp


def widths_for(native: int, targets: tuple[int, ...] = WIDTHS) -> list[int]:
    """Larghezze da generare: quelle sotto la nativa, piu' la nativa (al massimo la piu' grande). Mai ingrandimenti."""
    top = min(native, max(targets))
    return sorted({w for w in targets if w < top} | {top})


def source_for(fair: dict, src_dir: Path = SRC_DIR) -> Path | None:
    """Foto originale della fiera, se c'e'."""
    if fair.get("immagine"):
        p = Path(src_dir) / fair["immagine"]
        return p if p.is_file() else None
    for ext in EXTS:
        p = Path(src_dir) / f"{fair['id']}{ext}"
        if p.is_file():
            return p
    return None


def outputs(fid: str, out_dir: Path = OUT_DIR) -> list[Path]:
    """WebP delle schede (<id>-<w>.webp)."""
    return sorted(p for p in Path(out_dir).glob(f"{fid}-*.webp") if p.stem[len(fid) + 1:].isdigit())


def hero_outputs(fid: str, out_dir: Path = OUT_DIR) -> list[Path]:
    """WebP dell'hero della home (<id>-hero-<w>.webp)."""
    pre = f"{fid}-{HERO}-"
    return sorted((p for p in Path(out_dir).glob(f"{pre}*.webp") if p.stem[len(pre):].isdigit()),
                  key=lambda p: int(p.stem[len(pre):]))


def _write(im, w: int, dest: Path, max_bytes: int, min_q: int, name: str) -> Path | None:
    """WebP largo `w` entro `max_bytes` (qualita' da 82 in giu' fino a `min_q`); None se non rientra."""
    from PIL import Image
    h = max(1, round(im.height * w / im.width))
    r = im.resize((w, h), Image.LANCZOS) if w < im.width else im
    q = QUALITY
    while True:
        buf = BytesIO()
        r.save(buf, "WEBP", quality=q, method=6)
        if buf.tell() <= max_bytes or q <= min_q:
            break
        q = max(min_q, q - 4)
    if buf.tell() > max_bytes:
        print(f"{name}: {w} px supera {max_bytes // 1024} KB anche a qualita' {q}: larghezza saltata", file=sys.stderr)
        return None
    dest.write_bytes(buf.getvalue())
    return dest


def _make(src: Path, fid: str, out_dir: Path, hero: bool) -> list[Path]:
    from PIL import Image, ImageOps
    out_dir = Path(out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    for old in (hero_outputs if hero else outputs)(fid, out_dir):
        old.unlink()
    stem, targets, limit, min_q = ((f"{fid}-{HERO}", HERO_WIDTHS, HERO_MAX_BYTES, HERO_MIN_QUALITY) if hero
                                   else (fid, WIDTHS, MAX_BYTES, MIN_QUALITY))
    written = []
    with Image.open(src) as im:
        im = ImageOps.exif_transpose(im).convert("RGB")
        for w in widths_for(im.width, targets):
            f = _write(im, w, out_dir / f"{stem}-{w}.webp", limit, min_q, Path(src).name)
            if f is None:
                break  # le larghezze maggiori peserebbero ancora di piu'
            written.append(f)
    if not written:
        raise ValueError(f"{Path(src).name}: nessuna versione entro {limit // 1024} KB")
    return written


def make(src: Path, fid: str, out_dir: Path = OUT_DIR) -> list[Path]:
    """Scrive <id>-<w>.webp in `out_dir` (toglie prima quelli vecchi della stessa fiera). Ritorna i file scritti."""
    return _make(src, fid, out_dir, hero=False)


def make_hero(src: Path, fid: str, out_dir: Path = OUT_DIR) -> list[Path]:
    """Scrive <id>-hero-<w>.webp in `out_dir` (toglie prima quelli vecchi della stessa fiera). Ritorna i file scritti."""
    return _make(src, fid, out_dir, hero=True)
