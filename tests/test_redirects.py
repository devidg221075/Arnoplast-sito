from pathlib import Path

from arnoplast.catalog import load_catalog
from arnoplast.site.build import build_site
from arnoplast.site.redirects import load_redirects

ROOT = Path(__file__).resolve().parent.parent
CSV = ROOT / "data" / "redirects.csv"


def test_every_redirect_target_exists_in_dist(tmp_path):
    build_site(load_catalog(ROOT / "data" / "catalog.json"), tmp_path)
    rows = load_redirects(CSV)
    assert rows
    missing = [new for _, new in rows if not (tmp_path / new.lstrip("/") / "index.html").is_file()]
    assert not missing, missing


def test_no_redirect_loops():
    rows = load_redirects(CSV)
    olds = {o for o, _ in rows}
    for old, new in rows:
        assert old != new
        assert new not in olds, (old, new)


def test_old_paths_unique():
    olds = [o for o, _ in load_redirects(CSV)]
    assert len(olds) == len(set(olds))


def test_all_old_paths_start_with_slash_and_have_no_domain():
    for old, new in load_redirects(CSV):
        for p in (old, new):
            assert p.startswith("/") and "://" not in p and not p.startswith("//"), p
