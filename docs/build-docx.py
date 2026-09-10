"""Render a Markdown document in docs/ as a client-facing .docx in client/.

Deliberately not a general Markdown converter — it handles exactly the subset used
by the client-facing documents (headings, paragraphs, bullet and numbered lists,
pipe tables, ballot-box checklists, inline bold and code) and ignores the rest.
Keeping the Markdown as the single source means the .docx never drifts from it.

    python docs/build-docx.py docs/09-credentials-and-access-request.md \
        client/BulkScrubs-Direct-Credentials-Request.docx
"""

import re
import sys
from pathlib import Path

from docx import Document
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Pt, RGBColor, Inches

INK = RGBColor(0x1A, 0x1A, 0x1A)
ACCENT = RGBColor(0x1F, 0x39, 0x5C)      # deep navy for headings
MUTED = RGBColor(0x5A, 0x5A, 0x5A)
RULE = "D8D8D8"
HEADER_FILL = "F2F0EC"                    # warm off-white, matching the site palette


# --- styling ---------------------------------------------------------------

def style_document(doc):
    normal = doc.styles["Normal"]
    normal.font.name = "Calibri"
    normal.font.size = Pt(10.5)
    normal.font.color.rgb = INK
    normal.paragraph_format.space_after = Pt(8)
    normal.paragraph_format.line_spacing = 1.15

    for name, size, colour, before, after in (
        ("Title", 26, ACCENT, 0, 4),
        ("Heading 1", 16, ACCENT, 20, 6),
        ("Heading 2", 12.5, ACCENT, 14, 4),
        ("Heading 3", 11, INK, 12, 3),
    ):
        st = doc.styles[name]
        st.font.name = "Calibri"
        st.font.size = Pt(size)
        st.font.color.rgb = colour
        st.font.bold = True
        st.paragraph_format.space_before = Pt(before)
        st.paragraph_format.space_after = Pt(after)
        st.paragraph_format.keep_with_next = True

    # python-docx's stock Title carries a bottom border and Subtitle-ish spacing.
    title_ppr = doc.styles["Title"]._element.get_or_add_pPr()
    for border in title_ppr.findall(qn("w:pBdr")):
        title_ppr.remove(border)

    for section in doc.sections:
        section.top_margin = Inches(0.9)
        section.bottom_margin = Inches(0.9)
        section.left_margin = Inches(0.95)
        section.right_margin = Inches(0.95)


def shade(cell, hex_fill):
    el = OxmlElement("w:shd")
    el.set(qn("w:val"), "clear")
    el.set(qn("w:fill"), hex_fill)
    cell._tc.get_or_add_tcPr().append(el)


def horizontal_rule(doc):
    p = doc.add_paragraph()
    p.paragraph_format.space_before = Pt(6)
    p.paragraph_format.space_after = Pt(10)
    pbdr = OxmlElement("w:pBdr")
    bottom = OxmlElement("w:bottom")
    bottom.set(qn("w:val"), "single")
    bottom.set(qn("w:sz"), "6")
    bottom.set(qn("w:space"), "1")
    bottom.set(qn("w:color"), RULE)
    pbdr.append(bottom)
    p._p.get_or_add_pPr().append(pbdr)


# --- inline runs -----------------------------------------------------------

INLINE = re.compile(r"(\*\*.+?\*\*|\*[^*]+\*|`[^`]+`|\[[^\]]+\]\([^)]+\))")


def add_runs(paragraph, text):
    """Bold, italic, inline code and link-text. Links keep their label; only real
    URLs get link styling — internal doc links are noise on paper."""
    for part in INLINE.split(text):
        if not part:
            continue
        if part.startswith("**") and part.endswith("**"):
            paragraph.add_run(part[2:-2]).bold = True
        elif part.startswith("*") and part.endswith("*"):
            paragraph.add_run(part[1:-1]).italic = True
        elif part.startswith("`") and part.endswith("`"):
            run = paragraph.add_run(part[1:-1])
            run.font.name = "Consolas"
            run.font.size = Pt(9.5)
        elif part.startswith("["):
            label, target = re.match(r"\[([^\]]+)\]\(([^)]+)\)", part).groups()
            run = paragraph.add_run(label)
            if target.startswith("http"):
                run.underline = True
                run.font.color.rgb = ACCENT
        else:
            paragraph.add_run(part)


# --- block parsing ---------------------------------------------------------

def split_row(line):
    return [c.strip() for c in line.strip().strip("|").split("|")]


def add_table(doc, rows):
    header, body = rows[0], rows[1:]
    table = doc.add_table(rows=0, cols=len(header))
    table.style = "Table Grid"
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    table.autofit = True

    cells = table.add_row().cells
    for cell, text in zip(cells, header):
        cell.paragraphs[0].paragraph_format.space_after = Pt(2)
        add_runs(cell.paragraphs[0], text)
        for run in cell.paragraphs[0].runs:
            run.bold = True
        shade(cell, HEADER_FILL)

    for row in body:
        cells = table.add_row().cells
        for cell, text in zip(cells, row):
            cell.paragraphs[0].paragraph_format.space_after = Pt(2)
            add_runs(cell.paragraphs[0], text)

    doc.add_paragraph().paragraph_format.space_after = Pt(2)
    return table


def render(md_path, out_path):
    lines = Path(md_path).read_text(encoding="utf-8").splitlines()
    doc = Document()
    style_document(doc)

    i = 0
    while i < len(lines):
        line = lines[i].rstrip()

        if not line.strip():
            i += 1
            continue

        if line.startswith("|"):
            rows = []
            while i < len(lines) and lines[i].lstrip().startswith("|"):
                if not re.match(r"^\|[\s:| -]+\|$", lines[i].strip()):
                    rows.append(split_row(lines[i]))
                i += 1
            add_table(doc, rows)
            continue

        if re.match(r"^-{3,}$", line.strip()):
            horizontal_rule(doc)
            i += 1
            continue

        if line.startswith("#"):
            level = len(line) - len(line.lstrip("#"))
            text = line.lstrip("#").strip()
            style = "Title" if level == 1 else f"Heading {min(level - 1, 3)}"
            if style == "Title":
                # The "09 — " prefix orders the file in docs/; it means nothing
                # to the client reading the document on its own.
                text = re.sub(r"^\d{2}\s+—\s+", "", text)
            p = doc.add_paragraph(style=style)
            add_runs(p, text)
            i += 1
            continue

        if line.lstrip().startswith(("- ", "* ")):
            p = doc.add_paragraph(style="List Bullet")
            p.paragraph_format.space_after = Pt(3)
            add_runs(p, line.lstrip()[2:])
            i += 1
            continue

        if re.match(r"^\d+\.\s", line.strip()):
            p = doc.add_paragraph(style="List Number")
            p.paragraph_format.space_after = Pt(3)
            add_runs(p, re.sub(r"^\d+\.\s+", "", line.strip()))
            i += 1
            continue

        # Checklist lines: one paragraph, tighter spacing, ballot boxes kept inline.
        if line.strip().startswith("☐"):
            p = doc.add_paragraph()
            p.paragraph_format.space_after = Pt(3)
            p.paragraph_format.left_indent = Inches(0.15)
            add_runs(p, line.strip())
            i += 1
            continue

        # Paragraph — join continuation lines until a blank or a new block.
        buf = [line.strip()]
        i += 1
        while i < len(lines) and lines[i].strip() and not lines[i].lstrip().startswith(
            ("|", "#", "- ", "* ", "☐")
        ) and not re.match(r"^(-{3,}|\d+\.\s)", lines[i].strip()):
            buf.append(lines[i].strip())
            i += 1
        p = doc.add_paragraph()
        add_runs(p, " ".join(buf))

    footer = doc.sections[0].footer.paragraphs[0]
    footer.text = "BulkScrubs Direct — Accounts, Credentials & Access Request · 7 September 2026"
    footer.alignment = WD_ALIGN_PARAGRAPH.CENTER
    for run in footer.runs:
        run.font.size = Pt(8)
        run.font.color.rgb = MUTED

    Path(out_path).parent.mkdir(parents=True, exist_ok=True)
    doc.save(out_path)
    print(f"wrote {out_path}")


if __name__ == "__main__":
    render(sys.argv[1], sys.argv[2])
