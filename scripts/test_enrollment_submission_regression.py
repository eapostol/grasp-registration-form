#!/usr/bin/env python3
"""
Small browser-based regression harness for enrollment submission-state logic.

Covers:
1) Blank PG2 work unit gets fallback value in preview payload.
2) PG2 "home same as PG1" mirroring survives preview-time DOM sync.

Usage:
  python3 scripts/test_enrollment_submission_regression.py
"""

from __future__ import annotations

import json
import sys
from dataclasses import dataclass
from typing import Any

from playwright.sync_api import sync_playwright


URL = "https://reg-form-project.ddev.site/enrollment-form/index.html?debug=true"


@dataclass
class TestOutcome:
    name: str
    passed: bool
    detail: str


def _capture_preview_payload(page) -> dict[str, Any]:
    payload: dict[str, Any] = {}

    def on_request(req):
        nonlocal payload
        if req.url.endswith("/api/preview_enrollment.php") and req.method.lower() == "post":
            try:
                payload = json.loads(req.post_data or "{}")
            except Exception:
                payload = {}

    page.on("request", on_request)
    page.click("#grasp-btn-preview")
    page.wait_for_selector("#grasp-preview-modal:not(.hidden)", timeout=120_000)
    page.wait_for_timeout(800)
    return payload


def test_parent2_work_unit_fallback(page) -> TestOutcome:
    page.goto(URL, wait_until="domcontentloaded", timeout=120_000)
    page.wait_for_selector("#field_parent2_work_unit", timeout=120_000)

    toggle = page.locator("#grasp-single-parent-toggle")
    if toggle.count() > 0 and toggle.is_checked():
        toggle.uncheck()

    page.fill("#field_parent2_work_street", "99 Regression Street")
    page.fill("#field_parent2_work_unit", "")
    page.fill("#field_parent2_work_city", "Toronto")
    page.select_option("#field_parent2_work_province", "ON")
    page.fill("#field_parent2_work_postal1", "M1A")
    page.fill("#field_parent2_work_postal2", "1A1")
    page.fill("#field_parent2_work_phone", "416-555-5555")

    payload = _capture_preview_payload(page)
    data = payload.get("data", {}) if isinstance(payload, dict) else {}
    unit_value = data.get("parent2_work_unit", None) if isinstance(data, dict) else None

    if unit_value == "n/a (not applicable)":
        return TestOutcome(
            "test_parent2_work_unit_fallback",
            True,
            f"parent2_work_unit={unit_value!r}",
        )
    return TestOutcome(
        "test_parent2_work_unit_fallback",
        False,
        f"expected 'n/a (not applicable)', got {unit_value!r}",
    )


def test_parent2_same_as_parent1_sync(page) -> TestOutcome:
    page.goto(URL, wait_until="domcontentloaded", timeout=120_000)
    page.wait_for_selector("#field_parent1_work_street", timeout=120_000)
    page.wait_for_selector("#field_parent2_home_same_as_parent1", timeout=120_000)

    # Enable same-as mode.
    page.check("#field_parent2_home_same_as_parent1")
    page.wait_for_timeout(150)

    # Change a mapped Parent 1 source field.
    expected = "SYNC FROM P1 WORK STREET 123"
    page.fill("#field_parent1_work_street", expected)

    payload = _capture_preview_payload(page)
    data = payload.get("data", {}) if isinstance(payload, dict) else {}
    if not isinstance(data, dict):
        data = {}
    actual = data.get("parent2_work_street", None)

    if actual == expected:
        return TestOutcome(
            "test_parent2_same_as_parent1_sync",
            True,
            f"parent2_work_street={actual!r}",
        )
    return TestOutcome(
        "test_parent2_same_as_parent1_sync",
        False,
        f"expected {expected!r}, got {actual!r}",
    )


def main() -> int:
    outcomes: list[TestOutcome] = []
    with sync_playwright() as p:
        browser = p.chromium.launch(
            executable_path="/usr/bin/google-chrome",
            headless=True,
            args=["--no-sandbox"],
        )
        page = browser.new_page(ignore_https_errors=True)
        try:
            outcomes.append(test_parent2_work_unit_fallback(page))
            outcomes.append(test_parent2_same_as_parent1_sync(page))
        finally:
            browser.close()

    has_failures = any(not o.passed for o in outcomes)
    for o in outcomes:
        status = "PASS" if o.passed else "FAIL"
        print(f"[{status}] {o.name}: {o.detail}")

    return 1 if has_failures else 0


if __name__ == "__main__":
    sys.exit(main())

