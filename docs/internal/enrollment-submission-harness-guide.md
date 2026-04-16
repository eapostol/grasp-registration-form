# Enrollment Submission Harness Guide

## Purpose
This guide explains the two regression harness scripts used to verify Parent/Guardian 2 submission-state behavior for:
- payload data sent to preview/submit endpoints
- preview/email rendering content
- fallback handling for optional unit/suite field
- same-as-parent synchronization behavior

## Harness Files
1. `scripts/enrollment_preview_capture.py`
Captures one realistic browser run and outputs structured JSON containing:
- selected outbound payload fields (`payload_subset`)
- preview/email presence checks (`preview_contains`)
- label-to-value extraction from preview HTML (`preview_label_rows`)
- validation banner check for incorrect required-state on the unit field

2. `scripts/test_enrollment_submission_regression.py`
Runs two automated browser assertions and exits non-zero on failure:
- `test_parent2_work_unit_fallback`
- `test_parent2_same_as_parent1_sync`

## Prerequisites
1. Local site available at:
`https://reg-form-project.ddev.site/enrollment-form/index.html?debug=true`
2. Python + Playwright installed:
```bash
python3 -m pip install --user playwright
```
3. Chrome available at `/usr/bin/google-chrome` (used by both scripts).

## How To Run
1. Run the capture script:
```bash
python3 scripts/enrollment_preview_capture.py --output /tmp/enrollment-preview-capture.json
cat /tmp/enrollment-preview-capture.json
```
2. Run the regression harness:
```bash
python3 scripts/test_enrollment_submission_regression.py
```

## Expected Results (Current Fixed Behavior)
From a passing local run:

```json
{
  "payload_subset": {
    "parent2_phones": "416-777-1001",
    "parent2_work_street": "55 Example Work Street",
    "parent2_work_unit": "n/a (not applicable)",
    "parent2_work_city": "Toronto",
    "parent2_work_province": "ON",
    "parent2_work_postal1": "M4B",
    "parent2_work_postal2": "1B3",
    "parent2_work_phone": "416-777-2002"
  },
  "preview_label_rows": {
    "Parent / Guardian 2 Cell and home #": "416-777-1001",
    "Parent / Guardian 2 Work / School Street Address": "55 Example Work Street",
    "Parent / Guardian 2 Work / School unit / suite / extra (optional)": "n/a (not applicable)",
    "Parent / Guardian 2 Work / School city": "Toronto",
    "Parent / Guardian 2 Work / School province / territory": "Ontario",
    "Parent / Guardian 2 Work / School phone #": "416-777-2002"
  },
  "preview_banner_has_unit_required_error": false
}
```

```text
[PASS] test_parent2_work_unit_fallback: parent2_work_unit='n/a (not applicable)'
[PASS] test_parent2_same_as_parent1_sync: parent2_work_street='SYNC FROM P1 WORK STREET 123'
```

## Failure Signals To Watch For
1. `parent2_work_unit` is empty instead of `n/a (not applicable)`.
2. `preview_label_rows` values are blank for PG2 work fields.
3. `preview_banner_has_unit_required_error` is `true`.
4. Regression script prints any `[FAIL] ...` line or exits with code `1`.

## Before/After Comparison Workflow
Use this when validating a bugfix branch against a baseline branch:

1. On baseline branch:
```bash
python3 scripts/enrollment_preview_capture.py --output /tmp/capture-before.json
```
2. On fix branch:
```bash
python3 scripts/enrollment_preview_capture.py --output /tmp/capture-after.json
```
3. Compare:
```bash
diff -u /tmp/capture-before.json /tmp/capture-after.json
```

## Can This Be Automated In Git Lifecycle / CI-CD?
Yes.

## Local Automation (Future Work, No Changes Applied Yet)
1. Add a wrapper command (for example `make test-regression-enrollment`) that runs both scripts.
2. Add a `pre-push` hook to invoke that wrapper.
3. Block push on non-zero exit.

## CI/CD Automation (Future Work, No Changes Applied Yet)
1. Add a CI job that:
- starts the app in a reproducible environment
- installs Python + Playwright dependencies
- runs `scripts/test_enrollment_submission_regression.py`
- optionally runs `scripts/enrollment_preview_capture.py` and uploads JSON artifact
2. Mark this CI job as a required status check for merge.
3. Configure deployment workflow to run only after required checks succeed.

## Suggested Hardening Before CI Rollout
1. Add `--url` CLI support to `scripts/test_enrollment_submission_regression.py` so CI can target its own test URL.
2. Add a pinned dev dependency file for Python tooling used by harness scripts.
3. Add a single entrypoint command to standardize local and CI execution.
4. Expand coverage with additional submission-state tests as new fields/features are introduced.
