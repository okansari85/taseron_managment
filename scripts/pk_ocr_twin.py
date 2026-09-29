#!/usr/bin/env python3
"""pktakip: taranmış rapor PDF'i → "dijital ikiz" PDF (tablo çizgileri vektör, metin gerçek metin).

Mevcut hat (pk_pdf_text.py + yapay zeka + Camelot) ikiz PDF'i dijital bir rapor gibi okur; arşivde orijinal kalır.
Adımlar (sayfa başına):
  1. pdfium ile 300 DPI renkli çizim; renkli mürekkep (kaşe, imza, logo) silinir.
  2. OpenCV: yatay / dikey çizgiler → ızgara → hücreler (kapalı bölgeler).
  3. Dolu hücreler tek Tesseract çalıştırmasında (tur, psm 6) okunur; işaret kutuları [X] / [ ] olarak yazılır;
     kod benzeri kısa hücreler (C1, A12 ...) harf+rakam listesiyle yeniden okunur.
  4. Hücre dışı metin (başlık, paragraf): tam sayfa Tesseract (psm 3), hücre içine düşen kelimeler atılır.
  5. reportlab: ızgara hücre sınırlarından yeniden çizilir (Camelot lattice varsayılan ayarlarla okur) + metin,
     orijinal sayfa ölçüsünde. Sayfalar paralel okunur (pdfium çizimi sırayla).
Kullanım: pk_ocr_twin.py <girdi.pdf> <çıktı.pdf>  → stdout JSON {"pages": n, "cells": n, "duration_s": s}
"""
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import threading
import time
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import cv2
import numpy as np
import pypdfium2 as pdfium
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

DPI = 300
PT = 72 / DPI
# Tesseract: PK_TESSERACT (env) → PATH → Windows varsayılan kurulum. Dil verisi (tur): PK_TESSDATA → ~/tessdata
# (Windows'ta Program Files'a yazılamadığında) → Tesseract'ın kendi klasörü (Linux: apt ile gelen tesseract-ocr-tur).
TESSERACT = os.environ.get("PK_TESSERACT") or shutil.which("tesseract") or r"C:\Program Files\Tesseract-OCR\tesseract.exe"
TESSDATA = os.environ.get("PK_TESSDATA") or next(
    (d for d in [os.path.expanduser("~/tessdata")] if os.path.isfile(os.path.join(d, "tur.traineddata"))), ""
)
FONT_CANDIDATES = [
    r"C:\Windows\Fonts\arial.ttf",
    "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
    "/usr/share/fonts/dejavu/DejaVuSans.ttf",
]
CODE = re.compile(r"[A-ZÇĞİÖŞÜ]{0,3}[0-9Il|!\]]{1,4}")


def tesseract(args: list[str]) -> None:
    command = [TESSERACT] + args[:2] + (["--tessdata-dir", TESSDATA] if TESSDATA and os.path.isdir(TESSDATA) else []) + args[2:]
    subprocess.run(command, check=True, capture_output=True)


# --- 1. çizim ve temizlik -------------------------------------------------------------------------------------
def page_image(pdf: pdfium.PdfDocument, index: int) -> np.ndarray:
    rgb = np.array(pdf[index].render(scale=DPI / 72).to_pil().convert("RGB"))
    spread = rgb.max(axis=2).astype(int) - rgb.min(axis=2).astype(int)
    colored = cv2.dilate((spread > 50).astype(np.uint8), np.ones((5, 5), np.uint8)) > 0
    gray = cv2.cvtColor(rgb, cv2.COLOR_RGB2GRAY)
    gray[colored] = 255
    return gray


# --- 2. ızgara ve hücreler ------------------------------------------------------------------------------------
def line_masks(gray: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    bw = cv2.adaptiveThreshold(255 - gray, 255, cv2.ADAPTIVE_THRESH_MEAN_C, cv2.THRESH_BINARY, 25, -10)
    h, w = bw.shape
    horiz = cv2.morphologyEx(bw, cv2.MORPH_OPEN, cv2.getStructuringElement(cv2.MORPH_RECT, (max(40, w // 35), 1)))
    vert = cv2.morphologyEx(bw, cv2.MORPH_OPEN, cv2.getStructuringElement(cv2.MORPH_RECT, (1, max(30, h // 90))))
    horiz = cv2.dilate(horiz, cv2.getStructuringElement(cv2.MORPH_RECT, (25, 3)))
    vert = cv2.dilate(vert, cv2.getStructuringElement(cv2.MORPH_RECT, (3, 25)))
    return horiz, vert


def cells_from_grid(grid: np.ndarray) -> list[tuple[int, int, int, int]]:
    h, w = grid.shape
    free = (grid == 0).astype(np.uint8)
    count, _, stats, _ = cv2.connectedComponentsWithStats(free, connectivity=4)
    cells = []
    for i in range(1, count):
        x, y, cw, ch, area = (int(v) for v in stats[i])
        if x == 0 or y == 0 or x + cw >= w or y + ch >= h:
            continue
        if cw < 18 or ch < 18 or area < 600 or cw * ch > 0.5 * w * h:
            continue
        cells.append((x, y, cw, ch))
    return sorted(cells, key=lambda c: (c[1], c[0]))


def snapped(values: list[float], tolerance: float = 12) -> dict[float, float]:
    """Yakın koordinatları (aynı çizginin iki yanı, taramadaki kayma) tek değere topla."""
    mapping: dict[float, float] = {}
    group: list[float] = []
    for value in sorted(set(values)) + [float("inf")]:
        if group and value - group[-1] > tolerance:
            center = sum(group) / len(group)
            mapping.update({v: center for v in group})
            group = []
        group.append(value)
    return mapping


def grid_lines(cells: list) -> list[tuple[float, float, float, float]]:
    """Izgara hücre sınırlarından yeniden çizilir: komşu hücreler aynı kenarı paylaşır, çizgiler kesintisiz olur
    (Camelot lattice varsayılan ayarlarla okur). Hücre çevrelemeyen süs çizgileri çizilmez."""
    xs = snapped([c[0] for c in cells] + [c[0] + c[2] for c in cells])
    ys = snapped([c[1] for c in cells] + [c[1] + c[3] for c in cells])
    lines = set()
    for x, y, w, h in cells:
        left, right, top, bottom = xs[x], xs[x + w], ys[y], ys[y + h]
        lines.update({(left, top, right, top), (left, bottom, right, bottom), (left, top, left, bottom), (right, top, right, bottom)})
    return sorted(lines)


# --- 3. hücre OCR ---------------------------------------------------------------------------------------------
def checkboxes(ink: np.ndarray) -> list[tuple[int, int, int, int, bool]]:
    """İşaret kutuları: dolu işaret (■ ya da ▌ çubuk) ya da kenarları ve köşeleri dolu, içi boş kare (□).
    Köşe şartı küçük "o" / "D" gibi harfleri eler."""
    count, _, stats, _ = cv2.connectedComponentsWithStats(ink, connectivity=8)
    boxes = []
    for i in range(1, count):
        x, y, w, h, area = (int(v) for v in stats[i])
        if not (10 <= w <= 70 and 11 <= h <= 70):
            continue
        part = ink[y:y + h, x:x + w]
        fill = area / (w * h)
        if fill > 0.9 and 0.35 <= w / h <= 1.8:
            boxes.append((x, y, w, h, True))
            continue
        if not (0.7 <= w / h <= 1.4 and fill < 0.5):
            continue
        # Dört kenar da (3 px bant) boydan boya dolu, köşeler dolu, içi boş: □ ("n" / "u" / "U" / "o" elenir)
        sides = [part[:3].any(axis=0).mean(), part[-3:].any(axis=0).mean(), part[:, :3].any(axis=1).mean(), part[:, -3:].any(axis=1).mean()]
        corners = [part[:3, :3], part[:3, -3:], part[-3:, :3], part[-3:, -3:]]
        center = part[4:-4, 4:-4]
        # Kelime içindeki harfin yanında bitişik harf olur; kutunun solunda boşluk (ya da hücre başı), sağında ara olur.
        band = ink[y:y + h]
        left_gap = not band[:, max(0, x - 8):x].any()
        right_gap = not band[:, x + w:x + w + 4].any()
        if left_gap and right_gap and min(sides) >= 0.75 and sum(bool(c.any()) for c in corners) == 4 and (center.size == 0 or float(center.mean()) < 0.15):
            boxes.append((x, y, w, h, False))
    return boxes


def cell_crop(gray: np.ndarray, cell: tuple[int, int, int, int]) -> tuple[np.ndarray | None, str]:
    x, y, w, h = cell
    m = 3
    crop = gray[y + m:y + h - m, x + m:x + w - m].copy()
    if crop.size == 0:
        return None, ""
    ink = (crop < 150).astype(np.uint8)
    ink[:, :2] = ink[:, -2:] = 0
    ink[:2, :] = ink[-2:, :] = 0
    ch, cw = ink.shape
    # Hücre kenarından taşan uzun şekiller (kaşe yayı, imza) yazı değildir.
    count, _, stats, _ = cv2.connectedComponentsWithStats(ink, connectivity=8)
    for i in range(1, count):
        bx, by, bw, bh, _ = (int(v) for v in stats[i])
        at_edge = bx <= 3 or by <= 3 or bx + bw >= cw - 3 or by + bh >= ch - 3
        if at_edge and (bw > 90 or bh > 0.85 * ch):
            crop[by:by + bh, bx:bx + bw][ink[by:by + bh, bx:bx + bw] > 0] = 255
            ink[by:by + bh, bx:bx + bw] = 0
    prefix = ""
    for bx, by, bw, bh, filled in sorted(checkboxes(ink), key=lambda b: b[0]):
        prefix += "[X] " if filled else "[ ] "
        crop[by:by + bh, bx:bx + bw] = 255
        ink[by:by + bh, bx:bx + bw] = 0
    if int(ink.sum()) < 25:
        return None, prefix.strip()
    ys, xs = np.nonzero(ink)
    if ys.max() - ys.min() <= 9 and xs.max() - xs.min() <= 80:
        return None, (prefix + "-").strip()  # yalnızca kısa çizgi: "-"
    tight = crop[max(0, ys.min() - 4):ys.max() + 5, max(0, xs.min() - 4):xs.max() + 5]
    if tight.shape[0] < 45:
        scale = 45 / tight.shape[0] * 1.5
        tight = cv2.resize(tight, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC)
    return cv2.copyMakeBorder(tight, 20, 20, 20, 20, cv2.BORDER_CONSTANT, value=255), prefix


def read_tsv(path: Path) -> dict[int, list[tuple]]:
    words: dict[int, list[tuple]] = {}
    for line in path.read_text(encoding="utf-8").splitlines()[1:]:
        p = line.split("\t")
        if len(p) < 12 or p[0] != "5" or not p[11].strip():
            continue
        words.setdefault(int(p[1]), []).append(((int(p[2]), int(p[3]), int(p[4])), int(p[6]), int(p[7]), int(p[8]), int(p[9]), p[11]))
    return words


def join_lines(words: list[tuple]) -> str:
    lines: dict = {}
    for key, left, *_rest, text in sorted(words, key=lambda w: (w[0], w[1])):
        lines.setdefault(key, []).append(text)
    return " ".join(" ".join(ws) for ws in lines.values()).strip()


def ocr_cells(gray: np.ndarray, cells: list, work: Path) -> dict[int, str]:
    order, prefixes = [], {}
    for index, cell in enumerate(cells):
        image, prefix = cell_crop(gray, cell)
        if prefix:
            prefixes[index] = prefix
        if image is not None:
            path = work / f"c{index:04d}.png"
            cv2.imwrite(str(path), image)
            order.append((index, path))
    texts = {i: p for i, p in prefixes.items()}
    if not order:
        return texts
    listing = work / "cells.txt"
    listing.write_text("\n".join(str(p) for _, p in order), encoding="utf-8")
    tesseract([str(listing), str(work / "cells"), "-l", "tur", "--psm", "6", "tsv"])
    words = read_tsv(work / "cells.tsv")
    for n, (index, _) in enumerate(order, start=1):
        texts[index] = (prefixes.get(index, "") + " " + join_lines(words.get(n, []))).strip()

    # Kod benzeri kısa hücreler (CI → C1, | → 1): harf + rakam listesiyle yeniden
    retry = [(i, p) for i, p in order if CODE.fullmatch(texts[i]) and re.search(r"[Il|!\]]", texts[i])]
    if retry:
        listing = work / "codes.txt"
        listing.write_text("\n".join(str(p) for _, p in retry), encoding="utf-8")
        tesseract([str(listing), str(work / "codes"), "-l", "eng", "--psm", "7",
                   "-c", "tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.", "tsv"])
        again = read_tsv(work / "codes.tsv")
        for n, (index, _) in enumerate(retry, start=1):
            value = join_lines(again.get(n, [])).replace(" ", "")
            if re.fullmatch(r"[A-Z]{0,3}\d{1,4}\.?", value):
                texts[index] = value
    return texts


SHORT = re.compile(r"[A-Za-zÇĞİÖŞÜçğıöşü]{0,2}\.?[0-9A-Za-z|!\]]{1,4}\.?")
CLEAN = re.compile(r"([A-Za-z]{0,2}\.?)(\d{1,3})\.?")


def repair_sequences(cells: list, texts: dict[int, str]) -> None:
    """Madde kodu / sıra no sütunları sıralıdır (A1, A2 ... / 1, 2 ...): harf-rakam karışmasını (AS→A5, CIII→C11,
    |→1) komşulardan düzelt. Okunuşu temiz olan değer yalnızca iki yanı kesin ve aralık tam 2 ise değişir."""
    columns: dict[int, list[int]] = {}
    for index, text in texts.items():
        text = text.strip()
        if not text or len(text) > 6 or not SHORT.fullmatch(text):
            continue
        x = cells[index][0]
        key = next((k for k in columns if abs(k - x) <= 25), x)
        columns.setdefault(key, []).append(index)

    def parse(text: str):
        m = CLEAN.fullmatch(text.strip())
        return (m.group(1).upper(), int(m.group(2))) if m else None

    for column in columns.values():
        column.sort(key=lambda i: cells[i][1])
        parsed = [parse(texts[i]) for i in column]
        for k, index in enumerate(column):
            current = texts[index].strip()
            prev = parsed[k - 1] if k > 0 else None
            nxt = parsed[k + 1] if k + 1 < len(column) else None
            expected = None
            if prev and nxt and prev[0] == nxt[0] and nxt[1] - prev[1] == 2:
                expected = (prev[0], prev[1] + 1)
            elif parsed[k] is None:
                before = parsed[k - 2] if k > 1 else None
                after = parsed[k + 2] if k + 2 < len(column) else None
                options = []
                if prev and before and before[0] == prev[0] and prev[1] - before[1] == 1:
                    options.append((prev[0], prev[1] + 1))
                if nxt and after and after[0] == nxt[0] and after[1] - nxt[1] == 1 and nxt[1] > 1:
                    options.append((nxt[0], nxt[1] - 1))
                # Harf öneki tutmalı (BI → B1 olur, A19 olmaz); öneksizse (sıra no) şekil önemli değil
                options = [o for o in options if (o[0] == "" and not re.match(r"[A-Za-z]{2}", current)) or current[:1].upper() == o[0][:1]]
                expected = options[0] if options else None
            if expected and parsed[k] != expected:
                texts[index] = f"{expected[0]}{expected[1]}"
                parsed[k] = expected


def ocr_page_text(gray: np.ndarray, cells: list, work: Path) -> list[tuple[int, int, int, int, str]]:
    """Hücre dışındaki kelimeler (başlık, paragraf) — konumlarıyla."""
    image = work / "page.png"
    cv2.imwrite(str(image), gray)
    tesseract([str(image), str(work / "page"), "-l", "tur", "--psm", "3", "tsv"])
    inside = np.zeros(gray.shape, dtype=bool)
    for x, y, w, h in cells:
        inside[y:y + h, x:x + w] = True
    words = []
    for _key, left, top, width, height, text in read_tsv(work / "page.tsv").get(1, []):
        cx, cy = left + width // 2, top + height // 2
        if 0 <= cy < gray.shape[0] and 0 <= cx < gray.shape[1] and inside[cy, cx]:
            continue
        if len(text.strip()) == 1 and not text.strip().isalnum():
            continue  # çizgi / leke kırıntısı
        words.append((left, top, width, height, text))
    return words


# --- 5. ikiz PDF ----------------------------------------------------------------------------------------------
def font_name() -> str:
    for path in FONT_CANDIDATES:
        if os.path.isfile(path):
            pdfmetrics.registerFont(TTFont("PkText", path))
            return "PkText"
    return "Helvetica"


def wrap(text: str, font: str, size: float, width: float) -> list[str]:
    lines, current = [], ""
    for word in text.split():
        candidate = f"{current} {word}".strip()
        if pdfmetrics.stringWidth(candidate, font, size) <= width or not current:
            current = candidate
        else:
            lines.append(current)
            current = word
    return lines + ([current] if current else [])


def draw_cell_text(pdf: canvas.Canvas, font: str, text: str, cell: tuple, page_h: float) -> None:
    x, y, w, h = (v * PT for v in cell)
    inner_w, inner_h = max(w - 2, 1), max(h - 2, 1)
    size = min(9.0, inner_h * 0.7)
    lines = wrap(text, font, size, inner_w)
    while size > 3 and (len(lines) * size * 1.15 > inner_h or any(pdfmetrics.stringWidth(l, font, size) > inner_w for l in lines)):
        size -= 0.5
        lines = wrap(text, font, size, inner_w)
    pdf.setFont(font, size)
    top = page_h - y - (inner_h - len(lines) * size * 1.15) / 2 - size
    for n, line in enumerate(lines):
        pdf.drawString(x + 1, top - n * size * 1.15, line)


def read_page(source: pdfium.PdfDocument, lock: threading.Lock, index: int, work: Path) -> dict:
    """Bir sayfanın OCR'ı; pdfium iş parçacığı güvenli değil, çizim kilitle sırayla yapılır, OCR paralel."""
    with lock:
        page_w, page_h = source[index].get_size()
        gray = page_image(source, index)
    horiz, vert = line_masks(gray)
    cells = cells_from_grid(cv2.bitwise_or(horiz, vert))
    page_work = work / f"p{index + 1}"
    page_work.mkdir()
    texts = ocr_cells(gray, cells, page_work)
    repair_sequences(cells, texts)
    return {"size": (page_w, page_h), "cells": cells, "texts": texts, "words": ocr_page_text(gray, cells, page_work)}


def draw_page(out: canvas.Canvas, font: str, page: dict) -> None:
    page_w, page_h = page["size"]
    cells, texts = page["cells"], page["texts"]
    out.setPageSize((page_w, page_h))
    out.setLineWidth(0.8)
    for x0, y0, x1, y1 in grid_lines(cells):
        out.line(x0 * PT, page_h - y0 * PT, x1 * PT, page_h - y1 * PT)
    for left, top, width, height, text in page["words"]:
        # Kelime kendi genişliğine sığdırılır, sonuna boşluk: metin çıkarılırken kelimeler bitişmez.
        size = max(4.0, min(16.0, height * PT * 0.95))
        natural = pdfmetrics.stringWidth(text, font, size)
        if natural > width * PT:
            size = max(3.0, size * width * PT / natural)
        out.setFont(font, size)
        out.drawString(left * PT, page_h - (top + height) * PT + size * 0.15, text + " ")
    for cell_index, text in sorted(texts.items(), key=lambda t: (cells[t[0]][1], cells[t[0]][0])):
        if text:
            draw_cell_text(out, font, text, cells[cell_index], page_h)
    out.showPage()


def main() -> int:
    if len(sys.argv) != 3:
        print(json.dumps({"error": "usage: pk_ocr_twin.py <input.pdf> <output.pdf>"}))
        return 1
    started = time.time()
    try:
        source = pdfium.PdfDocument(sys.argv[1])
        work = Path(tempfile.mkdtemp(prefix="pk_ocr_"))
        lock = threading.Lock()
        workers = max(1, min(4, (os.cpu_count() or 2) - 1))
        try:
            with ThreadPoolExecutor(max_workers=workers) as pool:
                pages = list(pool.map(lambda i: read_page(source, lock, i, work), range(len(source))))
            font = font_name()
            out = canvas.Canvas(sys.argv[2])
            for page in pages:
                draw_page(out, font, page)
            out.save()
        finally:
            shutil.rmtree(work, ignore_errors=True)
    except Exception as exc:  # noqa: BLE001 - hata PHP tarafına JSON olarak gider
        print(json.dumps({"error": f"{type(exc).__name__}: {exc}"}, ensure_ascii=False))
        return 1
    print(json.dumps({"pages": len(pages), "cells": sum(len(p["cells"]) for p in pages), "duration_s": round(time.time() - started, 1)}))
    return 0


if __name__ == "__main__":
    sys.exit(main())
