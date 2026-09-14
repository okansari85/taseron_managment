#!/usr/bin/env python3
import json
import sys
from pathlib import Path

import camelot


# Windows subprocess stdout can use the system code page instead of UTF-8.
# The PHP consumer expects UTF-8 JSON.
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")


def clean(value):
    if value is None:
        return ""
    return " ".join(str(value).replace("\n", " ").split())


def main():
    if len(sys.argv) != 2:
        print(json.dumps({"error": "usage: camelot_extract.py <pdf>"}, ensure_ascii=False))
        return 2

    pdf = Path(sys.argv[1])
    if not pdf.is_file():
        print(json.dumps({"error": f"PDF not found: {pdf}"}, ensure_ascii=False))
        return 2

    result = {"version": camelot.__version__, "tables": []}

    # Lattice first: the fire-suppression reports use ruled/tabular layouts.
    # Stream is a fallback for pages/tables without ruling lines.
    for flavor in ("lattice", "stream"):
        try:
            tables = camelot.read_pdf(str(pdf), pages="all", flavor=flavor)
        except Exception as exc:
            result.setdefault("warnings", []).append(f"{flavor}: {exc}")
            continue

        for table_index, table in enumerate(tables):
            cells = []
            for row in table.cells:
                cell_row = []
                for cell in row:
                    text = clean(cell.text)
                    cell_row.append({
                        "text": text,
                        "x1": float(cell.x1),
                        "y1": float(cell.y1),
                        "x2": float(cell.x2),
                        "y2": float(cell.y2),
                    })
                cells.append(cell_row)

            result["tables"].append({
                "flavor": flavor,
                "index": table_index,
                "page": int(table.page),
                "bbox": [float(v) for v in table._bbox],
                "data": [[clean(v) for v in row] for row in table.df.values.tolist()],
                "cells": cells,
            })

    print(json.dumps(result, ensure_ascii=False, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
