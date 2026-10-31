"""Pagine dei cataloghi PDF come immagini leggere per lo sfogliatore (/it/cataloghi/<linea>/).

Uso: python tools/catalog_pages.py [linea ...]      (senza argomenti: tutte le linee di CATALOG_PDFS)

Legge content/source/<linea>-it.pdf (come la build), converte ogni pagina con pdftoppm (poppler) e scrive in
site/static/img/cataloghi/<linea>/ i WebP NNN.webp (larghezza 1200 px, qualita' 68) e NNN-t.webp (anteprima, 180 px),
poi data/cataloghi.json con numero di pagine e proporzioni. Rilanciarlo quando cambia un PDF. Richiede poppler-utils e Pillow.
"""
import json
import subprocess
import sys
import tempfile
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))
from arnoplast.site.build import CATALOG_PDFS, SOURCE_DIR  # noqa: E402

OUT = ROOT / "site" / "static" / "img" / "cataloghi"
DATA = ROOT / "data" / "cataloghi.json"


def render(slug: str) -> dict:
    pdf = SOURCE_DIR / CATALOG_PDFS[slug][0]
    dest = OUT / slug
    dest.mkdir(parents=True, exist_ok=True)
    for old in dest.glob("*.webp"):
        old.unlink()
    with tempfile.TemporaryDirectory() as tmp:
        subprocess.run(["pdftoppm", "-r", "150", "-png", str(pdf), f"{tmp}/p"], check=True)
        pages = sorted(Path(tmp).glob("p-*.png"))
        w = h = 0
        for i, p in enumerate(pages, 1):
            im = Image.open(p).convert("RGB")
            full = im.resize((1200, round(im.height * 1200 / im.width)), Image.LANCZOS)
            full.save(dest / f"{i:03d}.webp", "WEBP", quality=68, method=6)
            th = im.resize((180, round(im.height * 180 / im.width)), Image.LANCZOS)
            th.save(dest / f"{i:03d}-t.webp", "WEBP", quality=60, method=6)
            w, h = full.size
    kb = sum(f.stat().st_size for f in dest.glob("*.webp")) // 1024
    print(f"{slug}: {len(pages)} pagine, {kb} KB")
    return {"pagine": len(pages), "w": w, "h": h, "thw": 180, "thh": round(h * 180 / w)}


def main(slugs: list[str]) -> int:
    data = json.loads(DATA.read_text(encoding="utf-8")) if DATA.is_file() else {}
    for s in slugs or list(CATALOG_PDFS):
        data[s] = render(s)
    DATA.write_text(json.dumps(data, indent=1) + "\n", encoding="utf-8")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
