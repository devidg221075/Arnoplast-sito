"""Estrae e ottimizza le foto della home dai PDF dei cataloghi del cliente (content/source).

Uso: python tools/home_images.py <cartella-temporanea>
Richiede poppler (pdfimages, pdftoppm), ImageMagick (convert, per i JPEG2000) e Pillow con WebP.
Le foto sono le immagini incorporate nei PDF (nessuna foto esterna); i prodotti sono ritagli delle pagine.
"""
import subprocess, sys
from pathlib import Path
from PIL import Image
ROOT = Path(__file__).resolve().parent.parent
S = Path(sys.argv[1]); RAW = S/'raw'; RAW.mkdir(parents=True, exist_ok=True); (S/'prod').mkdir(exist_ok=True)
OUT = ROOT/'site/static/img/home'; OUT.mkdir(parents=True, exist_ok=True)
SRC = ROOT/'content/source'
def pdfimages(pdf, page, prefix):
    subprocess.run(['pdfimages', '-f', str(page), '-l', str(page), '-all', '-p', str(SRC/pdf), str(prefix)], check=True)
for pg in (1, 2, 3, 4, 5, 6, 8, 26): pdfimages('agricoltura-it.pdf', pg, RAW/'agr')
pdfimages('agricoltura-it.pdf', 9, S/'prod'/'agr')
pdfimages('garden-it.pdf', 8, RAW/'gar')
pdfimages('olive-it.pdf', 3, RAW/'oli')
subprocess.run(['convert', str(RAW/'oli-003-001.jp2'), str(RAW/'oli-p3.jpg')], check=True)
def render(pdf, page, dpi=200):
    p = S/f'r{dpi}_{pdf}_{page}'
    f = Path(str(p)+'.png')
    if not f.exists():
        subprocess.run(['pdftoppm','-r',str(dpi),'-png','-singlefile','-f',str(page),'-l',str(page),str(SRC/pdf),str(p)],check=True)
    return Image.open(f).convert('RGB')
def save(im, name, widths, q=72):
    for w in widths:
        w = min(w, im.width)
        r = im.resize((w, round(im.height*w/im.width)), Image.LANCZOS) if w < im.width else im
        f = OUT/f'{name}-{w}.webp'; r.save(f, 'WEBP', quality=q, method=6)
        print(f.name, r.size, f.stat().st_size)
def crop_rel(im, box): return im.crop(box)
# A hero: copertina agricoltura p.1
save(Image.open(RAW/'agr-001-001.jpg').convert('RGB'), 'frutteto', [480, 830])
# C hero: agricoltura p.26 (apertura sezione), tunnel di rete antigrandine
t = Image.open(RAW/'agr-026-000.jpg').convert('RGB'); save(t.crop((0, 380, 1008, 1100)), 'tunnel', [560, 1008], q=66)
save(t.crop((0, 120, 1008, 1424)), 'tunnel-alto', [480, 760], q=62)  # ritaglio verticale per i telefoni
# B hero: agricoltura p.6 disegno tecnico
save(Image.open(RAW/'agr-006-000.jpg').convert('RGB'), 'disegno', [480, 829])
# Stabilimento: p.4 progettazione CAD, p.5 stampaggio e magazzino
save(Image.open(RAW/'agr-004-002.jpg').convert('RGB'), 'progetto', [545])
save(Image.open(RAW/'agr-005-004.jpg').convert('RGB'), 'stampaggio', [508])
save(Image.open(RAW/'agr-005-011.jpg').convert('RGB'), 'magazzino', [502])
# Sede: p.2 + p.3 (foto unica a doppia pagina)
a = Image.open(RAW/'agr-002-000.jpg').convert('RGB'); b = Image.open(RAW/'agr-003-000.jpg').convert('RGB')
sede = Image.new('RGB', (a.width+b.width, a.height)); sede.paste(a,(0,0)); sede.paste(b,(a.width,0))
sede.save(S/'sede_full.png'); save(sede.crop((0, 120, sede.width, 120+760)), 'sede', [640, 1214])
# Linee
save(Image.open(RAW/'agr-008-000.jpg').convert('RGB').crop((0, 150, 830, 980)), 'linea-agricoltura', [720])
g = Image.open(RAW/'gar-008-000.jpg').convert('RGB'); print('gar8', g.size); save(g, 'linea-garden', [720])
o = Image.open(RAW/'oli-p3.jpg').convert('RGB'); save(o.crop((0, 0, 1243, 1243)), 'linea-olive', [560], q=50)
# Prodotti (B): render 200 dpi, coordinate a 100 dpi x2
save(Image.open(S/'prod/agr-009-001.jpg').convert('RGB'), 'prod-copripalo', [346])
save(render('garden-it.pdf', 10).crop((16, 268, 1636, 1190)), 'prod-testina', [720])
save(render('garden-it.pdf', 35).crop((126, 300, 1530, 868)), 'prod-lama', [720])
save(render('olive-it.pdf', 1).crop((330, 0, 1654, 860)), 'prod-agevolatore', [720])
