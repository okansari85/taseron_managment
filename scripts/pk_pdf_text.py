#!/usr/bin/env python3
"""pktakip: PDF metin katmanı, sayfa sayfa, pdfium ile (Camelot'un da kullandığı motor; pypdfium2 Camelot'la kurulu).

smalot/pdfparser'ın okuyamadığı PDF'ler için ikinci okuyucu (PkReportText). Çıktı: {"pages": ["...", ...]}.
"""
import json
import sys

import pypdfium2 as pdfium

# Windows subprocess stdout can use the system code page instead of UTF-8.
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")


def main() -> int:
    if len(sys.argv) != 2:
        print(json.dumps({"error": "usage: pk_pdf_text.py <pdf>"}, ensure_ascii=False))
        return 1
    try:
        pdf = pdfium.PdfDocument(sys.argv[1])
        pages = []
        for index in range(len(pdf)):
            text = pdf[index].get_textpage().get_text_range()
            # Bölünmez boşluk ve Unicode tire (U+2010) düz karaktere: tarih / rapor no ayrıştırması bozulmasın.
            text = text.replace("\r\n", "\n").replace("\r", "\n").replace(" ", " ").replace("‐", "-")
            pages.append(text.strip())
    except Exception as exc:  # noqa: BLE001 - hata PHP tarafına JSON olarak gider
        print(json.dumps({"error": str(exc)}, ensure_ascii=False))
        return 1
    print(json.dumps({"pages": pages}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
