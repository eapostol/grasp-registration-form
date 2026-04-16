#!/usr/bin/env python3
"""
Capture enrollment preview request payload + preview output snippets for PG2 work fields.

Usage:
  python3 scripts/enrollment_preview_capture.py --output /tmp/capture.json

Requires:
  pip install --user playwright
"""

from __future__ import annotations

import argparse
import json
import re
from dataclasses import dataclass
from html import unescape
from typing import Any

from playwright.sync_api import sync_playwright


DEFAULT_URL = "https://reg-form-project.ddev.site/enrollment-form/index.html?debug=true"

AFFECTED_KEYS = [
    "parent2_phones",
    "parent2_work_street",
    "parent2_work_unit",
    "parent2_work_city",
    "parent2_work_province",
    "parent2_work_postal1",
    "parent2_work_postal2",
    "parent2_work_phone",
    "parent2_work_postal_code",
    "parent2_work_address",
]

AFFECTED_VALUES = {
    "parent2_phones": "416-777-1001",
    "parent2_work_street": "55 Example Work Street",
    "parent2_work_unit": "",
    "parent2_work_city": "Toronto",
    "parent2_work_province": "ON",
    "parent2_work_postal1": "M4B",
    "parent2_work_postal2": "1B3",
    "parent2_work_phone": "416-777-2002",
}


@dataclass
class CaptureResult:
    payload_subset: dict[str, Any]
    preview_contains: dict[str, bool]
    preview_label_rows: dict[str, str]
    preview_banner_has_unit_required_error: bool


def _strip_html(value: str) -> str:
    text = re.sub(r"<[^>]+>", "", value)
    text = re.sub(r"\s+", " ", text)
    return unescape(text).strip()


def _extract_row_value(email_html: str, label: str) -> str:
    # Common row pattern in this project templates:
    # <td ...>Label</td> <td ...>Value</td>
    pattern = re.compile(
        rf">{re.escape(label)}<\s*/td>\s*<td[^>]*>(.*?)</td>",
        flags=re.IGNORECASE | re.DOTALL,
    )
    m = pattern.search(email_html)
    if not m:
        return ""
    return _strip_html(m.group(1))


def run_capture(url: str) -> CaptureResult:
    request_payload: dict[str, Any] = {}
    preview_json: dict[str, Any] = {}

    with sync_playwright() as p:
        browser = p.chromium.launch(
            executable_path="/usr/bin/google-chrome",
            headless=True,
            args=["--no-sandbox"],
        )
        page = browser.new_page(ignore_https_errors=True)

        def on_request(req):
            nonlocal request_payload
            if not req.url.endswith("/api/preview_enrollment.php"):
                return
            if req.method.lower() != "post":
                return
            try:
                request_payload = json.loads(req.post_data or "{}")
            except Exception:
                request_payload = {}

        def on_response(res):
            nonlocal preview_json
            if not res.url.endswith("/api/preview_enrollment.php"):
                return
            if res.request.method.lower() != "post":
                return
            try:
                preview_json = res.json()
            except Exception:
                preview_json = {}

        page.on("request", on_request)
        page.on("response", on_response)

        page.goto(url, wait_until="domcontentloaded", timeout=120_000)
        page.wait_for_selector("#field_parent2_work_street", timeout=120_000)

        # Keep in two-parent mode for this repro.
        toggle = page.locator("#grasp-single-parent-toggle")
        if toggle.count() > 0 and toggle.is_checked():
            toggle.uncheck()

        # Fill only affected fields (unit intentionally blank).
        page.fill("#field_parent2_phones", AFFECTED_VALUES["parent2_phones"])
        page.fill("#field_parent2_work_street", AFFECTED_VALUES["parent2_work_street"])
        page.fill("#field_parent2_work_unit", AFFECTED_VALUES["parent2_work_unit"])
        page.fill("#field_parent2_work_city", AFFECTED_VALUES["parent2_work_city"])
        page.select_option(
            "#field_parent2_work_province", AFFECTED_VALUES["parent2_work_province"]
        )
        page.fill("#field_parent2_work_postal1", AFFECTED_VALUES["parent2_work_postal1"])
        page.fill("#field_parent2_work_postal2", AFFECTED_VALUES["parent2_work_postal2"])
        page.fill("#field_parent2_work_phone", AFFECTED_VALUES["parent2_work_phone"])

        page.click("#grasp-btn-preview")
        page.wait_for_selector("#grasp-preview-modal:not(.hidden)", timeout=120_000)
        page.wait_for_timeout(1200)

        preview_content_text = page.locator("#grasp-preview-content").inner_text()
        browser.close()

    email_html = str(preview_json.get("emailHtml", ""))
    payload_data = request_payload.get("data", {}) if isinstance(request_payload, dict) else {}
    if not isinstance(payload_data, dict):
        payload_data = {}

    payload_subset = {k: payload_data.get(k, None) for k in AFFECTED_KEYS}

    label_rows = {
        "Parent / Guardian 2 Cell and home #": _extract_row_value(
            email_html, "Parent / Guardian 2 Cell and home #"
        ),
        "Parent / Guardian 2 Work / School Street Address": _extract_row_value(
            email_html, "Parent / Guardian 2 Work / School Street Address"
        ),
        "Parent / Guardian 2 Work / School unit / suite / extra (optional)": _extract_row_value(
            email_html, "Parent / Guardian 2 Work / School unit / suite / extra (optional)"
        ),
        "Parent / Guardian 2 Work / School city": _extract_row_value(
            email_html, "Parent / Guardian 2 Work / School city"
        ),
        "Parent / Guardian 2 Work / School province / territory": _extract_row_value(
            email_html, "Parent / Guardian 2 Work / School province / territory"
        ),
        "Parent / Guardian 2 Work / School phone #": _extract_row_value(
            email_html, "Parent / Guardian 2 Work / School phone #"
        ),
    }

    preview_contains = {
        "contains_work_street_value": AFFECTED_VALUES["parent2_work_street"] in email_html,
        "contains_work_unit_fallback": "n/a (not applicable)" in email_html.lower(),
        "contains_work_city_value": AFFECTED_VALUES["parent2_work_city"] in email_html,
        "contains_work_phone_value": AFFECTED_VALUES["parent2_work_phone"] in email_html,
        "contains_work_postal_compound": "M4B 1B3" in email_html,
    }

    return CaptureResult(
        payload_subset=payload_subset,
        preview_contains=preview_contains,
        preview_label_rows=label_rows,
        preview_banner_has_unit_required_error=(
            "Parent / Guardian 2 Work / School unit / suite / extra (optional)" in preview_content_text
            and "This field is required." in preview_content_text
        ),
    )


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--url", default=DEFAULT_URL)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()

    result = run_capture(args.url)
    out = {
        "url": args.url,
        "payload_subset": result.payload_subset,
        "preview_contains": result.preview_contains,
        "preview_label_rows": result.preview_label_rows,
        "preview_banner_has_unit_required_error": result.preview_banner_has_unit_required_error,
    }
    with open(args.output, "w", encoding="utf-8") as f:
        json.dump(out, f, indent=2, ensure_ascii=False)
    print(json.dumps(out, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()

