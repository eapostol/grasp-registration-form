#!/usr/bin/env bash
set -euo pipefail

# Exercise the renderer in a disposable workspace; never touch application assets.
repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
mkdir -p "$work/tools" "$work/config" "$work/parent-manual-form/assets/pages" "$work/bin"
cp "$repo/tools/render-parent-manual-pages.sh" "$work/tools/"
printf 'fixture\n' > "$work/manual.pdf"
printf '{"manual":{"pageCount":1},"keep":"unchanged"}\n' > "$work/config/parent-manual-fields.json"
printf 'old-page\n' > "$work/parent-manual-form/assets/pages/page-01.jpg"
printf 'keep auxiliary files\n' > "$work/parent-manual-form/assets/pages/notes.txt"
cat > "$work/bin/pdfinfo" <<'SH'
#!/usr/bin/env bash
printf 'Pages: 12\n'
SH
cat > "$work/bin/pdftoppm" <<'SH'
#!/usr/bin/env bash
prefix=${!#}
for ((page=1; page<=12; page++)); do
  [[ "${RENDER_TEST_MODE:-}" == missing && "$page" == 9 ]] && continue
  printf -v padded '%02d' "$page"
  printf 'new-page-%s\n' "$padded" > "$prefix-$padded.jpg"
done
[[ "${RENDER_TEST_MODE:-}" != fail ]]
SH
cat > "$work/bin/mv" <<'SH'
#!/usr/bin/env bash
if [[ "${RENDER_TEST_MODE:-}" == publish-fail && "${!#}" == config/parent-manual-fields.json ]]; then
  [[ "$*" != *previous-config.json* ]] && exit 1
fi
exec /bin/mv "$@"
SH
chmod +x "$work/bin/"*
cd "$work"
export PATH="$work/bin:$PATH"
snapshot() {
  find config parent-manual-form/assets/pages -type f -print0 | sort -z | xargs -0 sha256sum
}
expect_failure() {
  local before after
  before=$(snapshot)
  if bash tools/render-parent-manual-pages.sh "$@" > "$work/result.log" 2>&1; then
    echo "FAIL: expected rejection: $*" >&2; exit 1
  fi
  after=$(snapshot)
  [[ "$before" == "$after" ]] || { echo 'FAIL: failure changed existing assets/config' >&2; exit 1; }
  [[ -z "$(find parent-manual-form/assets -maxdepth 1 -name '.pages-render.*' -print)" ]] || {
    echo 'FAIL: staging directory leaked' >&2; exit 1
  }
}
expect_failure missing.pdf 110 82
expect_failure manual.pdf 0 82
expect_failure manual.pdf invalid 82
expect_failure manual.pdf 110 101
RENDER_TEST_MODE=fail expect_failure manual.pdf 110 82
RENDER_TEST_MODE=missing expect_failure manual.pdf 110 82
RENDER_TEST_MODE=publish-fail expect_failure manual.pdf 110 82
cp config/parent-manual-fields.json "$work/valid-config.json"
printf 'invalid JSON\n' > config/parent-manual-fields.json
expect_failure manual.pdf 110 82
cp "$work/valid-config.json" config/parent-manual-fields.json
bash tools/render-parent-manual-pages.sh manual.pdf 110 82 > "$work/result.log"
[[ "$(find parent-manual-form/assets/pages -name 'page-*.jpg' | wc -l)" == 12 ]]
grep -qx new-page-08 parent-manual-form/assets/pages/page-08.jpg
grep -qx new-page-09 parent-manual-form/assets/pages/page-09.jpg
grep -qx 'keep auxiliary files' parent-manual-form/assets/pages/notes.txt
python3 - <<'PY'
import json
with open('config/parent-manual-fields.json') as f:
    cfg = json.load(f)
assert cfg == {'manual': {'pageCount': 12}, 'keep': 'unchanged'}
PY
echo 'PASS: decimal page numbering, complete publication, auxiliary-file preservation, and rollback on failure'
