"""Foto di anteprima delle fiere: content/photos/fiere/<file> -> site/static/img/fiere/<id>-<larghezza>.webp.

La foto di una fiera e' il file indicato nel campo `immagine` di data/fiere.json oppure, se il campo manca,
`<id>.jpg|.jpeg|.png|.webp`. Larghezze 480, 800 e 1200 px (mai oltre la risoluzione nativa: se la foto e' piu'
stretta, l'ultima larghezza e' quella nativa), proporzioni conservate (la scheda ritaglia a 16:10 con object-fit),
WebP qualita' 82; un file che supera 150 KB scende di qualita' a passi di 4 fino a rientrare.
Usato dalla build (genera solo le foto mancanti) e da tools/fiere_images.py (rigenera, es. foto sostituita).
"""
from __future__ import annotations

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
    return sorted(p for p in Path(out_dir).glob(f"{fid}-*.webp") if p.stem[len(fid) + 1:].isdigit())


def make(src: Path, fid: str, out_dir: Path = OUT_DIR) -> list[Path]:
    """Scrive <id>-<w>.webp in `out_dir` (toglie prima quelli vecchi della stessa fiera). Ritorna i file scritti."""
    from PIL import Image, ImageOps
    out_dir = Path(out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    for old in outputs(fid, out_dir):
        old.unlink()
    written = []
    with Image.open(src) as im:
        im = ImageOps.exif_transpose(im).convert("RGB")
        for w in widths_for(im.width):
            h = max(1, round(im.height * w / im.width))
            r = im.resize((w, h), Image.LANCZOS) if w < im.width else im
            q = QUALITY
            while True:
                buf = BytesIO()
                r.save(buf, "WEBP", quality=q, method=6)
                if buf.tell() <= MAX_BYTES or q <= MIN_QUALITY:
                    break
                q -= 4
            if buf.tell() > MAX_BYTES:
                raise ValueError(f"{src.name}: {w} px supera {MAX_BYTES // 1024} KB anche a qualita' {q}")
            dest = out_dir / f"{fid}-{w}.webp"
            dest.write_bytes(buf.getvalue())
            written.append(dest)
    return written
