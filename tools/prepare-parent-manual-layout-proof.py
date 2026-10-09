#!/usr/bin/env python3
"""Overlay review samples on the authoritative PDF without rebuilding its content.

Requires pypdf and reportlab. Only configured fields are included.
Office-only fields are outlined, never filled as parent signatures.
"""
import argparse
import io
import json
from pathlib import Path
from pypdf import PdfReader, PdfWriter
from reportlab.pdfgen import canvas


def prepare(source, config, output):
    if source.resolve() == output.resolve():
        raise ValueError("Output must not overwrite source")
    cfg = json.loads(config.read_text(encoding="utf-8"))
    fields = list(cfg.get("fields", []))
    for step in cfg.get("steps", []):
        fields.extend(step.get("fields", []))
        for group in step.get("groups", []):
            fields.extend(group.get("fields", []))
    reader = PdfReader(source)
    if len(reader.pages) != cfg["manual"]["pageCount"]:
        raise ValueError("Source/config page counts differ")
    writer = PdfWriter()
    for number, page in enumerate(reader.pages, 1):
        width, height = float(page.mediabox.width), float(page.mediabox.height)
        buffer = io.BytesIO()
        drawing = canvas.Canvas(buffer, pagesize=(width, height))
        for field in fields:
            for placement in field.get("placements", [field.get("placement", {})]):
                if placement.get("page") != number:
                    continue
                rect = placement["rect"]
                x, y, w, h = rect["x"]*width, (1-rect["y"]-rect["h"])*height, rect["w"]*width, rect["h"]*height
                drawing.setStrokeColorRGB(.15, .45, .8)
                drawing.setLineWidth(.4)
                drawing.rect(x, y, w, h)
                if field.get("officeOnly"):
                    continue
                kind = field.get("kind", field.get("type", "text"))
                value = "QA" if kind == "initials" else "2026-10-08" if kind == "date" else "Review Sample"
                drawing.setFillColorRGB(.1, .2, .6)
                drawing.setFont("Helvetica-Bold" if kind == "initials" else "Helvetica", 10)
                if kind == "initials":
                    drawing.drawCentredString(x+w/2, y+(h-10)/2+2, value)
                else:
                    drawing.drawString(x+1, y+(h-10)/2+2, value)
        drawing.showPage()
        drawing.save()
        buffer.seek(0)
        page.merge_page(PdfReader(buffer).pages[0])
        writer.add_page(page)
    writer.add_metadata({"/Title": "GRASP cleaned Parent Manual - local overlay review proof", "/Subject": "Sample entries and blue field boundaries; 16 required initials; executive fields blank"})
    output.parent.mkdir(parents=True, exist_ok=True)
    with output.open("wb") as stream:
        writer.write(stream)
    assert len(PdfReader(output).pages) == len(reader.pages)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source", type=Path)
    parser.add_argument("config", type=Path)
    parser.add_argument("output", type=Path)
    args = parser.parse_args()
    prepare(args.source, args.config, args.output)
    print(args.output)
