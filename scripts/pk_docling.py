#!/usr/bin/env python3
"""pktakip: PDF'i yapısı korunarak (başlık, tablo, liste) sayfa sayfa sıkışık Markdown'a çevirir (Docling).

Tesisat raporlarında yapay zekaya düz metin yerine verilir (PkReportText): düz metinde tablolar düzleşiyor, iki sütunlu
kontrol listesinde madde kodu ile metni kayıyor. Tablolar dolgu boşluksuz yazılır (Docling'in Markdown'ı hizalama için
boşlukla şişer). Metin katmanı olan PDF içindir; OCR kapalı (taranmış PDF mevcut OCR akışından geçer).
Çıktı: {"pages": ["...", ...], "tables": [...]} (pages pk_pdf_text.py ile aynı biçim). tables: her tablonun numarası (metinde
"### Tablo N"), sayfası, Camelot alanı (table_areas, PDF noktası, sol-alt köken: "x1,y1,x2,y2" sol-üst → sağ-alt), altında durduğu
başlık ve ilk satırı; tabloyu Camelot yalnız bu alanda okur. "--serve <port>" ile sürekli açık servis (PKTAKIP_DOCLING_URL).
"""
import json
import sys

# Windows subprocess stdout can use the system code page instead of UTF-8.
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")


def cell(value) -> str:
    return " ".join(str(value).split()).replace("|", "/")


def converter():
    from docling.datamodel.base_models import InputFormat
    from docling.datamodel.pipeline_options import PdfPipelineOptions
    from docling.document_converter import DocumentConverter, PdfFormatOption

    return DocumentConverter(format_options={InputFormat.PDF: PdfFormatOption(pipeline_options=PdfPipelineOptions(do_ocr=False))})


def convert(conv, path: str) -> dict:
    from docling_core.types.doc import ListItem, SectionHeaderItem, TableItem, TextItem

    result = conv.convert(path)
    doc = result.document
    pages = {number: [] for number in range(1, len(result.pages) + 1)}
    tables = []
    heading = None
    for item, _level in doc.iterate_items():
        if not getattr(item, "prov", None):
            continue
        page = item.prov[0].page_no
        if isinstance(item, TableItem):
            frame = item.export_to_dataframe(doc)
            rows = [[cell(column) for column in frame.columns]] + [[cell(value) for value in row] for row in frame.values.tolist()]
            box = item.prov[0].bbox.to_bottom_left_origin(doc.pages[page].size.height)
            tables.append({"no": len(tables) + 1, "page": page, "area": "%d,%d,%d,%d" % (box.l, box.t, box.r, box.b), "heading": heading, "first_row": rows[0] if rows else []})
            lines = ["### Tablo %d" % len(tables)] + ["| " + " | ".join(row) + " |" for row in rows if any(row)]
        elif isinstance(item, SectionHeaderItem):
            heading = cell(item.text)
            lines = ["## " + heading]
        elif isinstance(item, ListItem):
            lines = ["- " + cell(item.text)]
        elif isinstance(item, TextItem):
            lines = [cell(item.text)]
        else:
            continue
        pages.setdefault(page, []).extend(line for line in lines if line.strip("|-# "))

    return {"pages": ["\n".join(pages[number]).strip() for number in sorted(pages)], "tables": tables}


def serve(port: int, host: str = "127.0.0.1") -> int:
    """Sürekli açık servis: modeller bir kez yüklenir. GET /?path=<pdf yolu> (aynı makine) ya da POST / (gövdede PDF)."""
    from http.server import BaseHTTPRequestHandler, HTTPServer
    from urllib.parse import parse_qs, urlparse

    conv = converter()

    class Handler(BaseHTTPRequestHandler):
        def reply(self, run):
            try:
                body, status = run(), 200
            except Exception as exc:  # noqa: BLE001
                body, status = {"error": str(exc)}, 500
            data = json.dumps(body, ensure_ascii=False).encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)

        # Aynı makinedeki dosya: GET /?path=<pdf yolu>
        def do_GET(self):  # noqa: N802 - http.server adı
            self.reply(lambda: convert(conv, (parse_qs(urlparse(self.path).query).get("path") or [""])[0]))

        # Başka makineden (gövdede PDF): POST / ; geçici dosya işlenip silinir.
        def do_POST(self):  # noqa: N802 - http.server adı
            def run():
                import os, tempfile
                handle, temp = tempfile.mkstemp(suffix=".pdf")
                try:
                    with os.fdopen(handle, "wb") as out:
                        out.write(self.rfile.read(int(self.headers.get("Content-Length", 0))))
                    return convert(conv, temp)
                finally:
                    os.unlink(temp)
            self.reply(run)

    print(f"pk_docling servis: http://{host}:{port}", flush=True)
    # Tek iş parçacığı: Docling modelleri eşzamanlı istekte çöküyor; istekler sırayla işlenir.
    HTTPServer((host, port), Handler).serve_forever()
    return 0


def main() -> int:
    if len(sys.argv) in (3, 4) and sys.argv[1] == "--serve":
        return serve(int(sys.argv[2]), sys.argv[3] if len(sys.argv) == 4 else "127.0.0.1")
    if len(sys.argv) != 2:
        print(json.dumps({"error": "usage: pk_docling.py <pdf> | --serve <port>"}, ensure_ascii=False))
        return 1
    try:
        out = convert(converter(), sys.argv[1])
    except Exception as exc:  # noqa: BLE001 - hata PHP tarafına JSON olarak gider
        print(json.dumps({"error": str(exc)}, ensure_ascii=False))
        return 1

    print(json.dumps(out, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
