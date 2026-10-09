#!/usr/bin/env bash
set -euo pipefail

# Re-render Parent Manual page images from the source PDF.
#
# Usage (from repo root):
#   bash tools/render-parent-manual-pages.sh [path-to-pdf] [dpi] [jpeg-quality]
#
# Examples:
#   bash tools/render-parent-manual-pages.sh parent-manual-form/assets/GRASP-parent-manual-2026.pdf 110
#   bash tools/render-parent-manual-pages.sh parent-manual-form/assets/GRASP-parent-manual-2026.pdf 110 82
#
# Requirements (WSL/Ubuntu):
#   sudo apt-get update && sudo apt-get install -y poppler-utils
#
# Notes:
# - Output images are written to: parent-manual-form/assets/pages/
# - Filenames are padded: page-01.jpg, page-02.jpg, ...
# - The script also updates config/parent-manual-fields.json manual.pageCount.

PDF_PATH="${1:-parent-manual-form/assets/GRASP-parent-manual-2026.pdf}"
DPI="${2:-110}"
JPEG_QUALITY="${3:-82}"

OUTDIR="parent-manual-form/assets/pages"
CONFIG="config/parent-manual-fields.json"

# Validate everything before touching the existing images or configuration.
[[ -r "$PDF_PATH" ]] || { echo "PDF not readable: $PDF_PATH" >&2; exit 1; }
[[ "$DPI" =~ ^[0-9]+([.][0-9]+)?$ ]] && awk "BEGIN {exit !($DPI > 0)}" || {
  echo "Invalid DPI: $DPI (expected a positive number)" >&2
  exit 1
}
if [[ ! "$JPEG_QUALITY" =~ ^[0-9]+$ ]] || [ "$JPEG_QUALITY" -lt 1 ] || [ "$JPEG_QUALITY" -gt 100 ]; then
  echo "Invalid jpeg-quality: $JPEG_QUALITY (expected integer 1-100)"
  exit 1
fi

for tool in pdftoppm pdfinfo python3; do
  command -v "$tool" >/dev/null 2>&1 || { echo "Required tool not found: $tool" >&2; exit 1; }
done
expected_count=$(LC_ALL=C pdfinfo "$PDF_PATH" | awk '/^Pages:/ {print $2}')
[[ "$expected_count" =~ ^[1-9][0-9]*$ ]] || { echo "Cannot determine PDF page count" >&2; exit 1; }
python3 -c 'import json,sys; json.load(open(sys.argv[1], encoding="utf-8"))' "$CONFIG"

# Keep staging and the old images on the same filesystem for directory renames.
mkdir -p "$(dirname "$OUTDIR")"
TMPDIR="$(mktemp -d "$(dirname "$OUTDIR")/.pages-render.XXXXXX")"
pages_installed=false
config_installed=false
cleanup() {
  local status=$?
  trap - EXIT HUP INT TERM
  if [[ "$status" -ne 0 ]]; then
    if [[ "$pages_installed" == true ]]; then rm -rf -- "$OUTDIR"; fi
    if [[ -d "$TMPDIR/previous-pages" ]]; then mv -- "$TMPDIR/previous-pages" "$OUTDIR"; fi
    if [[ "$config_installed" == true ]]; then mv -- "$TMPDIR/previous-config.json" "$CONFIG"; fi
  fi
  rm -rf -- "$TMPDIR"
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' HUP TERM
mkdir "$TMPDIR/pages"
if [[ -d "$OUTDIR" ]]; then
  cp -a -- "$OUTDIR/." "$TMPDIR/pages/"
  rm -f -- "$TMPDIR/pages"/page-*.jpg
fi
cp -- "$CONFIG" "$TMPDIR/previous-config.json"

echo "Rendering pages from: $PDF_PATH (DPI=$DPI, JPEG_QUALITY=$JPEG_QUALITY)"
pdftoppm -jpeg -jpegopt "quality=$JPEG_QUALITY" -r "$DPI" "$PDF_PATH" "$TMPDIR/page" >/dev/null

# Rename to padded page-01.jpg format
count=0
for f in "$TMPDIR"/page-*.jpg; do
  [ -e "$f" ] || continue
  base="$(basename "$f")"
  # page-1.jpg -> 1
  num="${base#page-}"
  num="${num%.jpg}"
  [[ "$num" =~ ^[0-9]+$ ]] || { echo "Unexpected rendered filename: $base" >&2; exit 1; }
  # Poppler pads page numbers; 08 and 09 must be interpreted as decimal.
  num=$((10#$num))
  [[ "$num" -ge 1 && "$num" -le "$expected_count" ]] || { echo "Unexpected page number: $num" >&2; exit 1; }
  padded="$(printf "%02d" "$num")"
  [[ -s "$f" && ! -e "$TMPDIR/pages/page-$padded.jpg" ]] || { echo "Empty or duplicate rendered page: $num" >&2; exit 1; }
  mv -- "$f" "$TMPDIR/pages/page-$padded.jpg"
  count=$((count+1))
done

[[ "$count" -eq "$expected_count" ]] || { echo "Rendered $count pages; expected $expected_count" >&2; exit 1; }
for ((page=1; page<=expected_count; page++)); do
  printf -v padded '%02d' "$page"
  [[ -s "$TMPDIR/pages/page-$padded.jpg" ]] || { echo "Missing rendered page: $page" >&2; exit 1; }
done

python3 - "$CONFIG" "$TMPDIR/config.json" "$count" <<'PY'
import json, sys
with open(sys.argv[1],"r",encoding="utf-8") as f:
    data=json.load(f)
data.setdefault("manual",{})["pageCount"]=int(sys.argv[3])
with open(sys.argv[2],"w",encoding="utf-8") as f:
    json.dump(data,f,indent=2)
    f.write("\n")
PY

# Publish only after rendering and configuration preparation succeed.
if [[ -d "$OUTDIR" ]]; then mv -- "$OUTDIR" "$TMPDIR/previous-pages"; fi
mv -- "$TMPDIR/pages" "$OUTDIR"
pages_installed=true
mv -- "$TMPDIR/config.json" "$CONFIG"
config_installed=true
echo "Rendered $count pages into $OUTDIR"
echo "Updated $CONFIG: manual.pageCount=$count"
