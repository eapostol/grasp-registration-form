<?php
// api/lib/NormalizedSubmissionStore.php
// Shared best-effort dual-write helper for normalized submission tables.

final class NormalizedSubmissionStore
{
    /**
     * Persist a submitted form into normalized tables.
     *
     * @param array{
     *   dsn?:string,
     *   user?:string,
     *   password?:string,
     *   formType:string,
     *   sessionId:string,
     *   submittedAt:string,
     *   status?:string,
     *   fields?:array<string,mixed>,
     *   payloadJson?:string,
     *   emailHtml?:string,
     *   pdfHtml?:string,
     *   source?:string
     * } $input
     * @return array{ok:bool, skipped?:bool, packageId?:int, submissionId?:int, error?:string}
     */
    public static function persistSubmission(array $input): array
    {
        $dsn = (string)($input['dsn'] ?? '');
        $user = (string)($input['user'] ?? '');
        $password = (string)($input['password'] ?? '');

        if ($dsn === '') {
            return ['ok' => false, 'skipped' => true, 'error' => 'db-disabled'];
        }

        try {
            $pdo = new PDO(
                $dsn,
                $user,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'db-connect-failed: ' . $e->getMessage()];
        }

        if (!self::normalizedSchemaExists($pdo)) {
            return ['ok' => false, 'skipped' => true, 'error' => 'normalized-schema-missing'];
        }

        $formType = self::normalizeFormType((string)($input['formType'] ?? 'enrollment'));
        $sessionId = trim((string)($input['sessionId'] ?? ''));
        if ($sessionId === '') {
            $sessionId = 'legacy-' . bin2hex(random_bytes(8));
        }

        $submittedAt = self::normalizeDatetime((string)($input['submittedAt'] ?? ''));
        $status = self::normalizeSubmissionStatus((string)($input['status'] ?? 'submitted'));
        $fields = isset($input['fields']) && is_array($input['fields']) ? $input['fields'] : [];
        $payloadJson = (string)($input['payloadJson'] ?? json_encode($fields, JSON_UNESCAPED_UNICODE));
        $emailHtml = isset($input['emailHtml']) ? (string)$input['emailHtml'] : null;
        $pdfHtml = isset($input['pdfHtml']) ? (string)$input['pdfHtml'] : null;
        $source = (string)($input['source'] ?? 'unknown');

        $packageStatus = self::mapPackageStatus($formType, $status);

        try {
            $pdo->beginTransaction();

            $upsertPackageSql = "
                INSERT INTO submission_package (session_id, status, created_at, updated_at)
                VALUES (:session_id, :incoming_status, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                  status = CASE
                    WHEN submission_package.status = 'completed' OR VALUES(status) = 'completed' THEN 'completed'
                    WHEN submission_package.status = 'manual_submitted' OR VALUES(status) = 'manual_submitted' THEN 'manual_submitted'
                    WHEN submission_package.status = 'enrollment_submitted' OR VALUES(status) = 'enrollment_submitted' THEN 'enrollment_submitted'
                    WHEN submission_package.status = 'waitlist_submitted' OR VALUES(status) = 'waitlist_submitted' THEN 'waitlist_submitted'
                    ELSE 'draft'
                  END,
                  updated_at = NOW()
            ";
            $upsertPackage = $pdo->prepare($upsertPackageSql);
            $upsertPackage->execute([
                ':session_id' => $sessionId,
                ':incoming_status' => $packageStatus,
            ]);

            $packageIdStmt = $pdo->prepare("SELECT id FROM submission_package WHERE session_id = :session_id LIMIT 1");
            $packageIdStmt->execute([':session_id' => $sessionId]);
            $packageIdRow = $packageIdStmt->fetch();
            if (!$packageIdRow || !isset($packageIdRow['id'])) {
                throw new RuntimeException('Unable to resolve package_id');
            }
            $packageId = (int)$packageIdRow['id'];

            $insertSubmissionSql = "
                INSERT INTO form_submission (
                    package_id,
                    form_type,
                    form_version,
                    submitted_at,
                    status,
                    payload_json,
                    email_html,
                    pdf_html,
                    created_at,
                    updated_at
                ) VALUES (
                    :package_id,
                    :form_type,
                    NULL,
                    :submitted_at,
                    :status,
                    :payload_json,
                    :email_html,
                    :pdf_html,
                    NOW(),
                    NOW()
                )
            ";
            $insertSubmission = $pdo->prepare($insertSubmissionSql);
            $insertSubmission->execute([
                ':package_id' => $packageId,
                ':form_type' => $formType,
                ':submitted_at' => $submittedAt,
                ':status' => $status,
                ':payload_json' => $payloadJson,
                ':email_html' => $emailHtml,
                ':pdf_html' => $pdfHtml,
            ]);
            $submissionId = (int)$pdo->lastInsertId();

            self::upsertSubmissionFields($pdo, $submissionId, $fields);
            self::upsertConsentRecords($pdo, $submissionId, $fields, $source);
            self::upsertManualInitials($pdo, $submissionId, $fields);

            self::insertEvent($pdo, $submissionId, 'submitted', [
                'source' => $source,
                'sessionId' => $sessionId,
            ]);
            if ($pdfHtml !== null && trim($pdfHtml) !== '') {
                self::insertEvent($pdo, $submissionId, 'pdf_generated', [
                    'source' => $source,
                ]);
            }

            self::updatePackageCompletionStatus($pdo, $packageId, $submissionId, $source);

            $pdo->commit();
            return ['ok' => true, 'packageId' => $packageId, 'submissionId' => $submissionId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => 'normalized-write-failed: ' . $e->getMessage()];
        }
    }

    public static function markEmailSent(array $input): array
    {
        $dsn = (string)($input['dsn'] ?? '');
        $user = (string)($input['user'] ?? '');
        $password = (string)($input['password'] ?? '');
        $submissionId = (int)($input['submissionId'] ?? 0);
        $source = (string)($input['source'] ?? 'unknown');
        $meta = isset($input['meta']) && is_array($input['meta']) ? $input['meta'] : [];

        if ($dsn === '' || $submissionId <= 0) {
            return ['ok' => false, 'skipped' => true];
        }

        try {
            $pdo = new PDO(
                $dsn,
                $user,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            if (!self::normalizedSchemaExists($pdo)) {
                return ['ok' => false, 'skipped' => true];
            }

            self::insertEvent($pdo, $submissionId, 'email_sent', array_merge(
                ['source' => $source],
                $meta
            ));
            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'normalized-email-event-failed: ' . $e->getMessage()];
        }
    }

    private static function normalizedSchemaExists(PDO $pdo): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $stmt = $pdo->query("
            SELECT COUNT(*) AS cnt
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN ('submission_package', 'form_submission', 'submission_field_value', 'submission_event')
        ");
        $row = $stmt ? $stmt->fetch() : null;
        $cache = ((int)($row['cnt'] ?? 0) >= 4);
        return $cache;
    }

    private static function normalizeFormType(string $formType): string
    {
        $v = strtolower(trim($formType));
        if ($v === 'waitlist' || $v === 'parent_manual' || $v === 'enrollment') {
            return $v;
        }
        if (strpos($v, 'wait') !== false) {
            return 'waitlist';
        }
        if (strpos($v, 'manual') !== false || strpos($v, 'parent') !== false) {
            return 'parent_manual';
        }
        return 'enrollment';
    }

    private static function normalizeSubmissionStatus(string $status): string
    {
        $v = strtolower(trim($status));
        if (in_array($v, ['draft', 'submitted', 'superseded', 'void'], true)) {
            return $v;
        }
        return 'submitted';
    }

    private static function mapPackageStatus(string $formType, string $submissionStatus): string
    {
        if ($submissionStatus !== 'submitted') {
            return 'draft';
        }
        if ($formType === 'waitlist') {
            return 'waitlist_submitted';
        }
        if ($formType === 'parent_manual') {
            return 'manual_submitted';
        }
        return 'enrollment_submitted';
    }

    private static function normalizeDatetime(string $input): string
    {
        $ts = strtotime($input);
        if ($ts === false || $ts <= 0) {
            return date('Y-m-d H:i:s');
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private static function upsertSubmissionFields(PDO $pdo, int $submissionId, array $fields): void
    {
        $sql = "
            INSERT INTO submission_field_value (
                submission_id, field_name, field_type, value_text, value_number, value_date, value_bool, created_at, updated_at
            ) VALUES (
                :submission_id, :field_name, :field_type, :value_text, :value_number, :value_date, :value_bool, NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                field_type = VALUES(field_type),
                value_text = VALUES(value_text),
                value_number = VALUES(value_number),
                value_date = VALUES(value_date),
                value_bool = VALUES(value_bool),
                updated_at = NOW()
        ";
        $stmt = $pdo->prepare($sql);

        foreach ($fields as $name => $value) {
            $fieldName = substr((string)$name, 0, 120);
            [$fieldType, $valueText, $valueNumber, $valueDate, $valueBool] = self::coerceValue($value);
            $stmt->execute([
                ':submission_id' => $submissionId,
                ':field_name' => $fieldName,
                ':field_type' => $fieldType,
                ':value_text' => $valueText,
                ':value_number' => $valueNumber,
                ':value_date' => $valueDate,
                ':value_bool' => $valueBool,
            ]);
        }
    }

    private static function upsertConsentRecords(PDO $pdo, int $submissionId, array $fields, string $source): void
    {
        $sql = "
            INSERT INTO consent_record (
                submission_id, consent_code, consent_value, is_agreed, signed_by_person_id, signed_at, meta_json, created_at, updated_at
            ) VALUES (
                :submission_id, :consent_code, :consent_value, :is_agreed, NULL, NULL, :meta_json, NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                consent_value = VALUES(consent_value),
                is_agreed = VALUES(is_agreed),
                meta_json = VALUES(meta_json),
                updated_at = NOW()
        ";
        $stmt = $pdo->prepare($sql);

        foreach ($fields as $name => $value) {
            $key = (string)$name;
            $k = strtolower($key);
            if (substr($k, -5) === '_text') {
                continue;
            }
            if (!preg_match('/consent|agree|acknowledge|release|policy/', $k)) {
                continue;
            }

            $raw = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value;
            $normalized = strtolower(trim((string)$raw));
            $isAgreed = null;
            if (preg_match('/(^|[^a-z])(agree|agreed|consent|consented|acknowledge|acknowledged|yes)([^a-z]|$)/', $normalized)) {
                $isAgreed = 1;
            } elseif (preg_match('/(^|[^a-z])(no|decline|disagree|refuse)([^a-z]|$)/', $normalized)) {
                $isAgreed = 0;
            }

            $stmt->execute([
                ':submission_id' => $submissionId,
                ':consent_code' => substr($key, 0, 120),
                ':consent_value' => $raw,
                ':is_agreed' => $isAgreed,
                ':meta_json' => json_encode(['source' => $source], JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    private static function upsertManualInitials(PDO $pdo, int $submissionId, array $fields): void
    {
        $sql = "
            INSERT INTO manual_initial_ack (
                submission_id, ack_code, initials_value, required_flag, created_at, updated_at
            ) VALUES (
                :submission_id, :ack_code, :initials_value, :required_flag, NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                initials_value = VALUES(initials_value),
                required_flag = VALUES(required_flag),
                updated_at = NOW()
        ";
        $stmt = $pdo->prepare($sql);

        foreach ($fields as $name => $value) {
            $key = (string)$name;
            $k = strtolower($key);
            if (!preg_match('/initial|initials/', $k)) {
                continue;
            }
            $initials = trim((string)(is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value));
            if ($initials === '') {
                continue;
            }
            $stmt->execute([
                ':submission_id' => $submissionId,
                ':ack_code' => substr($key, 0, 80),
                ':initials_value' => substr($initials, 0, 20),
                ':required_flag' => 1,
            ]);
        }
    }

    private static function updatePackageCompletionStatus(PDO $pdo, int $packageId, int $submissionId, string $source): void
    {
        $countSql = "
            SELECT COUNT(DISTINCT form_type) AS form_count
            FROM form_submission
            WHERE package_id = :package_id
              AND status = 'submitted'
              AND form_type IN ('waitlist', 'enrollment', 'parent_manual')
        ";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute([':package_id' => $packageId]);
        $countRow = $countStmt->fetch();
        $formCount = (int)($countRow['form_count'] ?? 0);

        if ($formCount >= 3) {
            $upd = $pdo->prepare("
                UPDATE submission_package
                SET status = 'completed', updated_at = NOW()
                WHERE id = :package_id
                  AND status <> 'completed'
            ");
            $upd->execute([':package_id' => $packageId]);

            if ($upd->rowCount() > 0) {
                self::insertEvent($pdo, $submissionId, 'status_changed', [
                    'source' => $source,
                    'packageStatus' => 'completed',
                ]);
            }
        }
    }

    private static function insertEvent(PDO $pdo, int $submissionId, string $eventType, array $payload): void
    {
        $sql = "
            INSERT INTO submission_event (submission_id, event_type, event_payload_json, created_at)
            VALUES (:submission_id, :event_type, :event_payload_json, NOW())
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':submission_id' => $submissionId,
            ':event_type' => $eventType,
            ':event_payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param mixed $value
     * @return array{0:string,1:?string,2:?string,3:?string,4:?int}
     */
    private static function coerceValue($value): array
    {
        if (is_bool($value)) {
            return ['boolean', $value ? 'true' : 'false', null, null, $value ? 1 : 0];
        }

        if (is_int($value) || is_float($value)) {
            return ['number', (string)$value, (string)$value, null, null];
        }

        if (is_array($value)) {
            $txt = json_encode($value, JSON_UNESCAPED_UNICODE);
            return ['json', $txt, null, null, null];
        }

        $txt = trim((string)$value);
        $valueNumber = null;
        $valueDate = null;
        $valueBool = null;

        if ($txt !== '' && preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $txt)) {
            $valueNumber = $txt;
        }
        if ($txt !== '' && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $txt)) {
            $valueDate = $txt;
        }

        $lower = strtolower($txt);
        if (in_array($lower, ['true', '1', 'yes', 'y', 'on', 'checked'], true)) {
            $valueBool = 1;
        } elseif (in_array($lower, ['false', '0', 'no', 'n', 'off', 'unchecked'], true)) {
            $valueBool = 0;
        }

        return ['text', $txt, $valueNumber, $valueDate, $valueBool];
    }
}
