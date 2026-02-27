<?php
// tools/audit_enrollment_field_options.php
// Simple audit script to detect option/value mismatches that can cause radio/select "flips".
// Usage (from repo root):
//   php tools/audit_enrollment_field_options.php

$cfgPath = __DIR__ . '/../config/enrollment-fields.json';
if (!file_exists($cfgPath)) {
  fwrite(STDERR, "Config not found: {$cfgPath}\n");
  exit(1);
}

$raw = file_get_contents($cfgPath);
$cfg = json_decode($raw, true);
if (!is_array($cfg)) {
  fwrite(STDERR, "Failed to parse JSON: {$cfgPath}\n");
  exit(1);
}

$fields = [];
foreach (($cfg['steps'] ?? []) as $step) {
  foreach (($step['groups'] ?? []) as $group) {
    foreach (($group['fields'] ?? []) as $field) {
      if (!is_array($field)) continue;
      $name = (string)($field['name'] ?? '');
      if ($name === '') continue;
      $fields[] = $field;
    }
  }
}

$issues = 0;

foreach ($fields as $f) {
  $type = (string)($f['type'] ?? 'text');
  if ($type !== 'radio' && $type !== 'select') continue;

  $name = (string)($f['name'] ?? '');
  $label = (string)($f['label'] ?? '');
  $opts = $f['options'] ?? [];
  if (!is_array($opts) || count($opts) === 0) {
    echo "[WARN] {$name}: {$label} has no options\n";
    $issues++;
    continue;
  }

  $seenValues = [];
  $seenLabels = [];
  foreach ($opts as $opt) {
    $v = null;
    $l = null;
    if (is_string($opt)) {
      $v = $opt;
      $l = $opt;
    } elseif (is_array($opt)) {
      $v = isset($opt['value']) ? (string)$opt['value'] : '';
      $l = isset($opt['label']) ? (string)$opt['label'] : $v;
    } else {
      continue;
    }

    if ($v === '') {
      echo "[WARN] {$name}: empty option value detected\n";
      $issues++;
    }

    if (isset($seenValues[$v])) {
      echo "[WARN] {$name}: duplicate option value '{$v}'\n";
      $issues++;
    }
    $seenValues[$v] = true;

    if ($l !== '' && isset($seenLabels[$l])) {
      echo "[WARN] {$name}: duplicate option label '{$l}'\n";
      $issues++;
    }
    $seenLabels[$l] = true;
  }
}

if ($issues === 0) {
  echo "OK: No obvious radio/select option issues found.\n";
  exit(0);
}

echo "Completed with {$issues} warning(s).\n";
exit(2);
