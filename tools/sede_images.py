"""Foto della sede (giorno e notte) per l'hero di Azienda, dalle foto originali del cliente in content/photos.

Uso: python tools/sede_images.py
Scrive in site/static/img/home: sede-<giorno|notte>-<larghezza>.webp (800, 1280, 1600, 2000, 2560, 3200;
mai oltre la risoluzione nativa) e sede-<giorno|notte>-alto-<larghezza>.webp (ritaglio verticale per i telefoni
sulla zona dell'insegna e dell'ingresso). Richiede Pillow con WebP.
"""
from pathlib import Path
from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / "content" / "photos"
OUT = ROOT / "site" / "static" / "img" / "home"
PORTRAIT_X = (0.45, 0.90)  # parte di larghezza ritagliata per i telefoni: insegna, finestre e porta

QUALITY = {800: 86, 2000: 86, 2560: 84, 3200: 82}  # le altre a 88: la facciata liscia e il cielo mostrano subito i blocchi

OUT.mkdir(parents=True, exist_ok=True)
for name in ("giorno", "notte"):
    im = Image.open(SRC / f"sede-{name}.jpg").convert("RGB")
    crops = {f"sede-{name}": (im, (800, 1280, 1600, 2000, 2560, 3200)),
             f"sede-{name}-alto": (im.crop((round(PORTRAIT_X[0] * im.width), 0, round(PORTRAIT_X[1] * im.width), im.height)), (420, 840, 1260))}
    for stem, (img, widths) in crops.items():
        for w in widths:
            w = min(w, img.width)
            r = img.resize((w, round(img.height * w / img.width)), Image.LANCZOS) if w < img.width else img
            f = OUT / f"{stem}-{w}.webp"
            r.save(f, "WEBP", quality=QUALITY.get(w, 88), method=6)
            print(f.name, r.size, f.stat().st_size)
