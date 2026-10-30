"""Modello dati del catalogo ARNOplast."""
from __future__ import annotations

import json
from dataclasses import dataclass, field, asdict
from pathlib import Path

MATERIALS = {"FE", "FV", "PA", "PET", "PE", "PP", "PVC", "AL"}
KINDS = {"article", "spare", "optional"}


@dataclass
class Article:
    code: str
    description: dict
    conf: int | None
    imb: int | None
    pallet: int | None
    kind: str


@dataclass
class Product:
    slug: str
    name: dict
    materials: list[str]
    features: list[str]
    dimensions: list[str]
    image: str | None
    video_url: str | None
    articles: list[Article] = field(default_factory=list)


@dataclass
class Family:
    slug: str
    name: dict
    products: list[Product] = field(default_factory=list)


@dataclass
class Line:
    slug: str
    name: dict
    accent: str
    families: list[Family] = field(default_factory=list)


@dataclass
class Catalog:
    lines: list[Line] = field(default_factory=list)


def text(d: dict, lang: str) -> str:
    """Testo in `lang`; ricade su italiano se manca o è vuoto."""
    return d.get(lang) or d.get("it", "")


def validate(c: Catalog) -> list[str]:
    errors: list[str] = []
    line_slugs: set[str] = set()
    for line in c.lines:
        if line.slug in line_slugs:
            errors.append(f"linea duplicata: {line.slug}")
        line_slugs.add(line.slug)
        fam_slugs: set[str] = set()
        for fam in line.families:
            if fam.slug in fam_slugs:
                errors.append(f"famiglia duplicata in {line.slug}: {fam.slug}")
            fam_slugs.add(fam.slug)
            prod_slugs: set[str] = set()
            for prod in fam.products:
                where = f"{line.slug}/{fam.slug}/{prod.slug}"
                if prod.slug in prod_slugs:
                    errors.append(f"slug duplicato in famiglia {fam.slug}: {prod.slug}")
                prod_slugs.add(prod.slug)
                for m in prod.materials:
                    if m not in MATERIALS:
                        errors.append(f"{where}: materiale non valido: {m}")
                for a in prod.articles:
                    if a.kind not in KINDS:
                        errors.append(f"{where}: kind non valido per {a.code}: {a.kind}")
    return errors


def save_catalog(c: Catalog, path: Path) -> None:
    Path(path).write_text(
        json.dumps(asdict(c), ensure_ascii=False, indent=2), encoding="utf-8"
    )


def _article(d: dict) -> Article:
    return Article(d["code"], d["description"], d.get("conf"), d.get("imb"),
                   d.get("pallet"), d["kind"])


def _product(d: dict) -> Product:
    return Product(d["slug"], d["name"], list(d.get("materials", [])),
                   list(d.get("features", [])), list(d.get("dimensions", [])),
                   d.get("image"), d.get("video_url"),
                   [_article(a) for a in d.get("articles", [])])


def load_catalog(path: Path) -> Catalog:
    data = json.loads(Path(path).read_text(encoding="utf-8"))
    return Catalog([
        Line(l["slug"], l["name"], l["accent"],
             [Family(f["slug"], f["name"], [_product(p) for p in f.get("products", [])])
              for f in l.get("families", [])])
        for l in data.get("lines", [])
    ])
