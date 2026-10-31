"""Copia del sito da navigare senza server: si apre con doppio clic su index.html.

Uso: python tools/offline_site.py <cartella-uscita>   (crea anche <cartella-uscita>.zip)

Il sito vero usa link assoluti (/it/...) e moduli JS, che dal disco (file://) non funzionano. Qui, solo nella
copia: link resi relativi con index.html esplicito (anche i rimandi meta refresh), i tre moduli JS uniti in uno
script classico e l'indice di ricerca caricato come script. Il menu (nav.js) e le animazioni sono gia' script
classici e restano come sono. Il sito generato da arnoplast.site.build non cambia.
"""
import json
import os
import re
import shutil
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))
from arnoplast.site.build import main as build  # noqa: E402

JS = ROOT / "site" / "static" / "js"
REF = re.compile(r'((?:href|src|action)=")(/[^"]*)(")')
SRCSET = re.compile(r'(srcset=")([^"]*)(")')
REFRESH = re.compile(r'(content="0; url=)(/[^"]*)(")')
CSS_URL = re.compile(r'url\((["\']?)(/[^)"\']+)\1\)')


def local(target: str, out: Path, here: Path) -> str:
    """Percorso relativo da `here` (cartella) a un URL assoluto del sito, con index.html per le cartelle."""
    path, sep, frag = target.partition("#")
    dest = out / path.lstrip("/")
    if path.endswith("/") or dest.is_dir():
        dest = dest / "index.html"
    rel = os.path.relpath(dest, here).replace(os.sep, "/")
    return rel + (sep + frag if sep else "")


def bundle(lang: str) -> str:
    """quote.js + search.js + main.js in un solo script classico (niente moduli sotto file://)."""
    parts = []
    for name in ("quote.js", "search.js", "main.js"):
        src = (JS / name).read_text(encoding="utf-8")
        src = re.sub(r"^import .*?;\n", "", src, flags=re.M)
        names = re.findall(r"^export (?:const|function) (\w+)", src, flags=re.M)
        src = re.sub(r"^export ", "", src, flags=re.M)
        if names:  # ogni modulo nel suo ambito, come con import/export
            src = f"const {{ {', '.join(names)} }} = (() => {{\n{src}\nreturn {{ {', '.join(names)} }};\n}})();"
        parts.append(f"// --- {name}\n{src}")
    code = "\n".join(parts)
    fetch = re.search(r"fetch\(`/\$\{encodeURIComponent\(LANG\)\}/search-index\.json`[^)]*\)", code)
    assert fetch, "main.js: chiamata all'indice di ricerca non trovata"
    code = code.replace(fetch.group(0), "Promise.resolve({ ok: true, json: () => window.ARNO_SEARCH_INDEX })")
    assert "link.href = a.url;" in code, "main.js: link dei risultati non trovato"
    code = code.replace("link.href = a.url;", "link.href = window.ARNO_ROOT + a.url;")
    return "(() => {\n" + code + "\n})();\n"


def main(out: Path) -> None:
    out = Path(out).resolve()
    if out.exists():
        shutil.rmtree(out)
    build(out=out)
    lang = "it"
    index = json.loads((out / lang / "search-index.json").read_text(encoding="utf-8"))
    for e in index:
        e["url"] = local(e["url"], out, out)
    (out / lang / "search-index.js").write_text(
        "window.ARNO_SEARCH_INDEX = " + json.dumps(index, ensure_ascii=False) + ";\n", encoding="utf-8")
    (out / "static" / "js" / "bundle.js").write_text(bundle(lang), encoding="utf-8")
    for css in (out / "static" / "css").glob("*.css"):
        t = css.read_text(encoding="utf-8")
        css.write_text(CSS_URL.sub(lambda m: f"url({local(m.group(2), out, css.parent)})", t), encoding="utf-8")
    module = '<script type="module" src="/static/js/main.js"></script>'
    for page in out.rglob("*.html"):
        here = page.parent
        h = page.read_text(encoding="utf-8")
        root = os.path.relpath(out, here).replace(os.sep, "/") + "/"
        assert module in h, page
        h = h.replace(module, f'<script>window.ARNO_ROOT = "{root}";</script>'
                              f'<script src="{root}{lang}/search-index.js" defer></script>'
                              f'<script src="{root}static/js/bundle.js" defer></script>')
        h = REF.sub(lambda m: m.group(1) + local(m.group(2), out, here) + m.group(3), h)
        h = REFRESH.sub(lambda m: m.group(1) + local(m.group(2), out, here) + m.group(3), h)
        h = SRCSET.sub(lambda m: m.group(1) + ", ".join(
            (local(p.split()[0], out, here) + " " + " ".join(p.split()[1:])).strip() if p.strip().startswith("/")
            else p.strip() for p in m.group(2).split(",")) + m.group(3), h)
        h = CSS_URL.sub(lambda m: f"url({local(m.group(2), out, here)})", h)
        page.write_text(h, encoding="utf-8")
    (out / "index.html").write_text(
        '<!doctype html><meta charset="utf-8"><title>ARNOplast</title>'
        f'<meta http-equiv="refresh" content="0; url={lang}/index.html">'
        f'<a href="{lang}/index.html">Apri il sito</a>\n', encoding="utf-8")
    for f in ("_redirects", "robots.txt", "sitemap.xml"):
        (out / f).unlink(missing_ok=True)
    shutil.make_archive(str(out), "zip", out.parent, out.name)
    print(f"Apri {out / 'index.html'} (archivio: {out}.zip)")


if __name__ == "__main__":
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    main(Path(sys.argv[1]))
