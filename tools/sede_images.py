"""Foto della sede (giorno e notte) per l'hero di Azienda, dalle foto originali del cliente in content/photos.

Uso: python tools/sede_images.py
Scrive in site/static/img/home: sede-<giorno|notte>-<larghezza>.webp (800, 1280, 1600, 2000; mai oltre
la risoluzione nativa) e sede-<giorno|notte>-alto-<larghezza>.webp (ritaglio verticale per i telefoni
sulla zona dell'insegna e dell'ingresso). Richiede Pillow con WebP.
"""
from pathlib import Path
from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / "content" / "photos"
OUT = ROOT / "site" / "static" / "img" / "home"
PORTRAIT_X = (900, 1800)  # su una foto larga 2000 px: insegna, finestre e porta

OUT.mkdir(parents=True, exist_ok=True)
for name in ("giorno", "notte"):
    im = Image.open(SRC / f"sede-{name}.jpg").convert("RGB")
    sx = im.width / 2000
    crops = {f"sede-{name}": (im, (800, 1280, 1600, 2000)),
             f"sede-{name}-alto": (im.crop((round(PORTRAIT_X[0] * sx), 0, round(PORTRAIT_X[1] * sx), im.height)), (420, 840))}
    for stem, (img, widths) in crops.items():
        for w in widths:
            w = min(w, img.width)
            r = img.resize((w, round(img.height * w / img.width)), Image.LANCZOS) if w < img.width else img
            f = OUT / f"{stem}-{w}.webp"
            r.save(f, "WEBP", quality=72, method=6)
            print(f.name, r.size, f.stat().st_size)
