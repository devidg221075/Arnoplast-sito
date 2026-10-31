"""Estrae e ottimizza le foto della home dai PDF dei cataloghi del cliente (content/source).

Uso: python tools/home_images.py <cartella-temporanea>
Richiede poppler (pdfimages), ImageMagick (convert, per i JPEG2000) e Pillow con WebP.
Le foto sono le immagini incorporate nei PDF (nessuna foto esterna).
"""
import subprocess, sys
from pathlib import Path
from PIL import Image
ROOT = Path(__file__).resolve().parent.parent
S = Path(sys.argv[1]); RAW = S/'raw'; RAW.mkdir(parents=True, exist_ok=True)
OUT = ROOT/'site/static/img/home'; OUT.mkdir(parents=True, exist_ok=True)
SRC = ROOT/'content/source'
def pdfimages(pdf, page, prefix):
    subprocess.run(['pdfimages', '-f', str(page), '-l', str(page), '-all', '-p', str(SRC/pdf), str(prefix)], check=True)
for pg in (4, 5, 8): pdfimages('agricoltura-it.pdf', pg, RAW/'agr')
for pg in (1, 4, 5): pdfimages('garden-it.pdf', pg, RAW/'gar')
for pg in (3, 11): pdfimages('olive-it.pdf', pg, RAW/'oli')
subprocess.run(['convert', str(RAW/'oli-003-001.jp2'), str(RAW/'oli-p3.jpg')], check=True)
subprocess.run(['convert', str(RAW/'oli-011-001.jp2'), str(RAW/'oli-p11.jpg')], check=True)
def save(im, name, widths, q=72):
    for w in widths:
        w = min(w, im.width)
        r = im.resize((w, round(im.height*w/im.width)), Image.LANCZOS) if w < im.width else im
        f = OUT/f'{name}-{w}.webp'; r.save(f, 'WEBP', quality=q, method=6)
        print(f.name, r.size, f.stat().st_size)
def crop_rel(im, box): return im.crop(box)
# Hero della home (carosello) e della linea Agricoltura: foto originali del cliente, vedi tools/hero_images.py
from PIL import ImageFilter
# Hero linea Garden: copertina garden p.1, prato e alberi
g = Image.open(RAW/'gar-001-001.jpg').convert('RGB'); save(g, 'prato', [480, 830])
save(g.crop((240, 0, 240 + 416, 555)), 'prato-alto', [416], q=64)
# Hero linea Olive: p.11 raccolta con abbacchiatore; p.3 verticale per i telefoni
# Fogliame fitto e rumoroso (JPEG2000 a 150 dpi): leggero filtro antirumore prima della compressione,
# sotto la velatura scura dell'hero non si nota e il file resta entro il budget.
ol = Image.open(RAW/'oli-p11.jpg').convert('RGB')
for w, q in ((640, 50), (1100, 45)):
    save(ol.resize((w, round(ol.height*w/ol.width)), Image.LANCZOS).filter(ImageFilter.GaussianBlur(.6)), 'oliveto', [w], q=q)
op = Image.open(RAW/'oli-p3.jpg').convert('RGB').crop((0, 160, 1243, 1759))
save(op.resize((420, round(op.height*420/op.width)), Image.LANCZOS).filter(ImageFilter.MedianFilter(3)), 'oliveto-alto', [420], q=42)
# La sede (hero di Azienda) viene dalle foto originali del cliente, giorno e notte: vedi tools/hero_images.py
# Filiera: p.4 progettazione CAD, p.5 stampaggio e magazzino
save(Image.open(RAW/'agr-004-002.jpg').convert('RGB'), 'progetto', [545])
save(Image.open(RAW/'agr-005-004.jpg').convert('RGB'), 'stampaggio', [508])
save(Image.open(RAW/'agr-005-011.jpg').convert('RGB'), 'magazzino', [502])
# Linee
save(Image.open(RAW/'agr-008-000.jpg').convert('RGB').crop((0, 150, 830, 980)), 'linea-agricoltura', [720])
# Garden: tre testine fotografate su fondo bianco (garden-it.pdf p.4 e p.5, "Dall'idea al prodotto finito"),
# composte a grandezza nativa (nessun ingrandimento) su fondo neutro.
from PIL import ImageChops, ImageStat
BG = (236, 236, 232)
garden = Image.new('RGB', (960, 640), BG)
def whiten(im):
    """Porta a bianco puro lo sfondo fotografico (mediana dei bordi), cosi' la moltiplicazione non lascia riquadri."""
    w, h = im.size
    edges = [im.crop(b) for b in ((0, 0, w, 6), (0, h - 6, w, h), (0, 0, 6, h), (w - 6, 0, w, h))]
    m = max(200, min(min(min(ImageStat.Stat(e).median) for e in edges), 250)) - 6
    return im.point(lambda v: min(255, round(v * 255 / m)))
for f, x, y in (('gar-004-007.jpg', 190, 60), ('gar-005-009.jpg', 410, 150), ('gar-004-002.jpg', 0, 340)):
    im = whiten(Image.open(RAW/f).convert('RGB'))
    garden.paste(ImageChops.multiply(garden.crop((x, y, x + im.width, y + im.height)), im), (x, y))
save(garden, 'linea-garden', [960])
o = Image.open(RAW/'oli-p3.jpg').convert('RGB'); save(o.crop((0, 0, 1243, 1243)), 'linea-olive', [560], q=50)
