#!/usr/bin/env bash
set -euo pipefail

# Re-render Parent Manual page images from the source PDF.
#
# Usage (from repo root):
#   bash tools/render-parent-manual-pages.sh [path-to-pdf] [dpi]
#
# Examples:
#   bash tools/render-parent-manual-pages.sh parent-manual-form/assets/GRASP-parent-manual-2026.pdf 160
#
# Requirements (WSL/Ubuntu):
#   sudo apt-get update && sudo apt-get install -y poppler-utils
#
# Notes:
# - Output images are written to: parent-manual-form/assets/pages/
# - Filenames are padded: page-01.jpg, page-02.jpg, ...
# - The script also updates config/parent-manual-fields.json manual.pageCount.

PDF_PATH="${1:-parent-manual-form/assets/GRASP-parent-manual-2026.pdf}"
DPI="${2:-160}"

OUTDIR="parent-manual-form/assets/pages"
TMPDIR="$(mktemp -d)"

if ! command -v pdftoppm >/dev/null 2>&1; then
  echo "pdftoppm not found. Install poppler-utils:"
  echo "  sudo apt-get update && sudo apt-get install -y poppler-utils"
  exit 1
fi

mkdir -p "$OUTDIR"
rm -f "$OUTDIR"/page-*.jpg

echo "Rendering pages from: $PDF_PATH (DPI=$DPI)"
pdftoppm -jpeg -r "$DPI" "$PDF_PATH" "$TMPDIR/page" >/dev/null

# Rename to padded page-01.jpg format
count=0
for f in "$TMPDIR"/page-*.jpg; do
  [ -e "$f" ] || continue
  base="$(basename "$f")"
  # page-1.jpg -> 1
  num="${base#page-}"
  num="${num%.jpg}"
  padded="$(printf "%02d" "$num")"
  mv "$f" "$OUTDIR/page-$padded.jpg"
  count=$((count+1))
done

rm -rf "$TMPDIR"

echo "Rendered $count pages into $OUTDIR"

# Update pageCount in config JSON (requires python3)
if command -v python3 >/dev/null 2>&1; then
  python3 - <<'PY'
import glob, json
cfg_path="config/parent-manual-fields.json"
with open(cfg_path,"r",encoding="utf-8") as f:
    data=json.load(f)
pages=sorted(glob.glob("parent-manual-form/assets/pages/page-*.jpg"))
data.setdefault("manual",{})["pageCount"]=len(pages)
with open(cfg_path,"w",encoding="utf-8") as f:
    json.dump(data,f,indent=2)
    f.write("\n")
print(f"Updated {cfg_path}: manual.pageCount={len(pages)}")
PY
else
  echo "python3 not found. Please update config/parent-manual-fields.json manual.pageCount manually."
fi
