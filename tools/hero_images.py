"""Foto degli hero (carosello della home e pagine Agricoltura/Azienda) dalle foto originali del cliente.

Uso: python tools/hero_images.py [nome ...]      (senza nomi: tutte le foto di PHOTOS)
Legge content/photos/<nome>.jpg e scrive in site/static/img/home:
  <nome>-<larghezza>.webp       800, 1280, 1600, 2000, 2560, 3200 (mai oltre la risoluzione nativa)
  <nome>-alto-<larghezza>.webp  420, 840, 1260: ritaglio verticale per i telefoni (fascia PHOTOS[nome])
Ogni file deve restare <= 250 KB (i test lo verificano): se una foto molto dettagliata lo supera, la qualita'
di quella larghezza scende a passi di 2 fino a rientrare. Le foto con "fuoco" ricevono una sfocatura graduale
dal basso (primo piano fuori fuoco, come a diaframma aperto): e' la zona sotto la velatura scura dell'hero e
quella che pesa di piu' (fogliame fitto). Richiede Pillow con WebP.

Per sostituire una foto (o aggiungere la variante serale "-notte") basta mettere il JPEG in content/photos,
aggiungerlo qui sotto con la fascia del ritaglio verticale e rilanciare lo script; poi aggiornare HERO_SLIDES
(home) o HEROES/NIGHT_HEROES (pagine) in arnoplast/site/build.py.
"""
import sys
from pathlib import Path

from PIL import Image, ImageFilter

Image.MAX_IMAGE_PIXELS = None
ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / "content" / "photos"
OUT = ROOT / "site" / "static" / "img" / "home"

# Foto -> crop: parte della larghezza (da, a) tenuta nel ritaglio verticale per i telefoni;
#         fuoco: (inizio, pieno, sigma) sfocatura del primo piano, da `inizio` a `pieno` dell'altezza (None: nessuna);
#         q: qualita' per larghezza al posto di QUALITY (i ritagli verticali sono l'LCP sui telefoni: leggeri).
AGRI = {"crop": (0.47, 0.92), "fuoco": (0.58, 0.85, 6), "q": {420: 80, 840: 75, 1260: 76}}
PHOTOS = {
    "sede-giorno": {"crop": (0.45, 0.90)},   # insegna, finestre e porta
    "sede-notte": {"crop": (0.45, 0.90)},
    "agricoltura-giorno": AGRI,              # palo centrale a sinistra, luce dorata a destra
    "agricoltura-notte": AGRI,               # stesso ritaglio: il passaggio giorno/notte non sposta l'inquadratura
}
WIDTHS = (800, 1280, 1600, 2000, 2560, 3200)
PORTRAIT_WIDTHS = (420, 840, 1260)
QUALITY = {800: 86, 2000: 86, 2560: 84, 3200: 82}  # le altre a 88: superfici lisce e cielo mostrano subito i blocchi
MAX_BYTES = 250 * 1024


def defocus(im: Image.Image, start: float, full: float, sigma: float) -> Image.Image:
    """Sfocatura che cresce dall'altezza `start` (nulla) a `full` (piena) e resta piena fino al fondo."""
    w, h = im.size
    ramp = Image.linear_gradient("L").resize((1, h)).point(
        lambda v: max(0, min(255, round((v / 255 - start) / (full - start) * 255)))).resize((w, h))
    base = im.filter(ImageFilter.GaussianBlur(0.8))  # toglie la grana dell'ingrandimento prima della compressione
    return Image.composite(im.filter(ImageFilter.GaussianBlur(sigma)), base, ramp)


def save(img: Image.Image, stem: str, widths, quality: dict) -> None:
    for w in widths:
        w = min(w, img.width)
        r = img.resize((w, round(img.height * w / img.width)), Image.LANCZOS) if w < img.width else img
        f = OUT / f"{stem}-{w}.webp"
        q = quality.get(w, QUALITY.get(w, 88))
        while True:
            r.save(f, "WEBP", quality=q, method=6)
            if f.stat().st_size <= MAX_BYTES or q <= 50:
                break
            q -= 2
        print(f.name, r.size, f.stat().st_size, f"q{q}")


def main(names) -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    for name in names:
        cfg = PHOTOS[name]
        (x0, x1), q = cfg["crop"], cfg.get("q", {})
        im = Image.open(SRC / f"{name}.jpg").convert("RGB")
        if cfg.get("fuoco"):
            im = defocus(im, *cfg["fuoco"])
        save(im, name, WIDTHS, q)
        save(im.crop((round(x0 * im.width), 0, round(x1 * im.width), im.height)), f"{name}-alto", PORTRAIT_WIDTHS, q)


if __name__ == "__main__":
    unknown = [n for n in sys.argv[1:] if n not in PHOTOS]
    if unknown:
        sys.exit(f"foto non in PHOTOS: {', '.join(unknown)}\n\n{__doc__}")
    main(sys.argv[1:] or list(PHOTOS))
