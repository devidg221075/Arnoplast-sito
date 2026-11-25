import io
import zipfile

from arnoplast.extract import idml


def _story(rows, head=("Cod. EAN", "Art.", "Descrizione", "Conf.", "Box")):
    cells = []
    for c, h in enumerate(head):
        cells.append(f'<Cell Name="{c}:0"><Content>{h}</Content></Cell>')
    for r, row in enumerate(rows, 1):
        for c, v in enumerate(row):
            cells.append(f'<Cell Name="{c}:{r}"><Content>{v}</Content></Cell>')
    return (f'<Story><Table ColumnCount="{len(head)}" BodyRowCount="{len(rows)}">'
            + "".join(cells) + "</Table></Story>")


def _idml(tmp_path, name, rows, **kw):
    p = tmp_path / name
    with zipfile.ZipFile(p, "w") as z:
        z.writestr("Stories/Story_u1.xml", _story(rows, **kw))
    return str(p)


def test_legge_righe_con_ean_conf_box(tmp_path):
    p = _idml(tmp_path, "a.idml", [("8025721033412", "1104-0501", "Testina", "", "12"),
                                    ("", "1704-0115C", "Clip", "250", "3000")])
    a, b = idml.read_rows(p)
    assert (a.code, a.ean, a.box, a.conf) == ("1104-0501", "8025721033412", 12, None)
    assert (b.code, b.ean, b.conf, b.box) == ("1704-0115C", "", 250, 3000)


def test_ignora_righe_senza_codice_valido(tmp_path):
    p = _idml(tmp_path, "a.idml", [("", "ABC", "x", "", "1")])
    assert idml.read_rows(p) == []


def test_abbina_per_posizione_anche_con_codici_ripetuti(tmp_path):
    it = idml.read_rows(_idml(tmp_path, "it.idml", [("", "2411-0318", "Rondella", "", "10"),
                                                       ("", "2411-0318", "Rondella", "", "10")]))
    en = idml.read_rows(_idml(tmp_path, "en.idml", [("", "2411-0318", "Washer A", "", "10"),
                                                       ("", "2411-0318", "Washer B", "", "10")]))
    pairs, problems = idml.pair(it, en)
    assert problems == []
    assert [e.description for _, e in pairs] == ["Washer A", "Washer B"]


def test_segnala_ordine_diverso_senza_indovinare(tmp_path):
    it = idml.read_rows(_idml(tmp_path, "it.idml", [("", "1104-0501", "A", "", "12")]))
    en = idml.read_rows(_idml(tmp_path, "en.idml", [("", "1104-0502", "B", "", "12")]))
    pairs, problems = idml.pair(it, en)
    assert pairs[0][1] is None and "1104-0502" in problems[0]
