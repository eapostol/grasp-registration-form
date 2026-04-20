#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

php "${ROOT}/scripts/test_enrollment_renderer_structure.php"
php "${ROOT}/scripts/test_enrollment_pdf_page1_layout.php"
