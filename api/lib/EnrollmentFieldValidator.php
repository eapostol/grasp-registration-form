<?php
// api/lib/EnrollmentFieldValidator.php

class EnrollmentFieldValidator
{
    /**
     * Validate payload data against field-level maxLength constraints in config.
     *
     * @param string $configPath
     * @param array $data
     * @return array<int, array{name:string,label:string,maxLength:int,length:int,message:string}>
     */
    public static function validateAgainstConfig(string $configPath, array $data): array
    {
        if (!is_file($configPath)) {
            return [];
        }

        $cfgRaw = @file_get_contents($configPath);
        if ($cfgRaw === false || $cfgRaw === '') {
            return [];
        }

        $cfg = json_decode($cfgRaw, true);
        if (!is_array($cfg)) {
            return [];
        }

        $issues = [];
        $maxLenMessageTemplate = 'Please use {max} characters or fewer.';
        if (isset($cfg['validationMessages']['maxLength']) && is_string($cfg['validationMessages']['maxLength'])) {
            $maxLenMessageTemplate = (string)$cfg['validationMessages']['maxLength'];
        }

        foreach (($cfg['steps'] ?? []) as $step) {
            if (!is_array($step)) {
                continue;
            }
            foreach (($step['groups'] ?? []) as $group) {
                if (!is_array($group)) {
                    continue;
                }
                foreach (($group['fields'] ?? []) as $field) {
                    if (!is_array($field)) {
                        continue;
                    }

                    $name = isset($field['name']) ? trim((string)$field['name']) : '';
                    if ($name === '') {
                        continue;
                    }

                    $type = strtolower(trim((string)($field['type'] ?? '')));
                    if ($type === 'hidden' || $type === 'static') {
                        continue;
                    }

                    if (!isset($field['maxLength']) || !is_numeric($field['maxLength'])) {
                        continue;
                    }

                    $maxLen = (int)$field['maxLength'];
                    if ($maxLen <= 0) {
                        continue;
                    }

                    if (!array_key_exists($name, $data)) {
                        continue;
                    }

                    $rawVal = $data[$name];
                    if (is_array($rawVal)) {
                        $rawVal = implode(', ', array_map('strval', $rawVal));
                    }
                    if (is_bool($rawVal)) {
                        $rawVal = $rawVal ? '1' : '0';
                    }

                    $value = trim((string)$rawVal);
                    if ($value === '') {
                        continue;
                    }

                    $length = self::strlen($value);
                    if ($length <= $maxLen) {
                        continue;
                    }

                    $label = isset($field['label']) && is_string($field['label']) && trim($field['label']) !== ''
                        ? trim((string)$field['label'])
                        : $name;

                    $msg = str_replace('{max}', (string)$maxLen, $maxLenMessageTemplate);

                    $issues[] = [
                        'name' => $name,
                        'label' => $label,
                        'maxLength' => $maxLen,
                        'length' => $length,
                        'message' => $msg,
                    ];
                }
            }
        }

        return $issues;
    }

    private static function strlen(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return (int)mb_strlen($value, 'UTF-8');
        }
        return strlen($value);
    }
}
