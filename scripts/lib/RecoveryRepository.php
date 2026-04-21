<?php

declare(strict_types=1);

final class RecoveryRepository
{
    /** @var array<string,mixed> */
    private array $config;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @return array<string,mixed>
     */
    public function fetchCanonicalSubmission(string $formType, string $sessionId): array
    {
        if ($sessionId === '') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'missing-session-id'];
        }

        try {
            $pdo = $this->connect();
        } catch (Throwable $e) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'db-unavailable', 'error' => $e->getMessage()];
        }

        $normalized = $this->fetchNormalizedSubmission($pdo, $formType, $sessionId);
        if (!empty($normalized['ok'])) {
            return $normalized;
        }

        if ($formType === 'enrollment') {
            $legacy = $this->fetchLegacyEnrollment($pdo, $sessionId);
            if (!empty($legacy['ok'])) {
                return $legacy;
            }
        }

        return ['ok' => false, 'skipped' => true, 'reason' => 'not-found'];
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public function upsertLegacyEnrollment(array $record): array
    {
        if (($record['formType'] ?? '') !== 'enrollment') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'not-enrollment'];
        }
        try {
            $pdo = $this->connect();
        } catch (Throwable $e) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'db-unavailable', 'error' => $e->getMessage()];
        }

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO enrollments (form_id, session_id, submitted_at, data_json, status)
                 VALUES (:form_id, :session_id, :submitted_at, :data_json, 'submitted')
                 ON DUPLICATE KEY UPDATE
                    data_json = VALUES(data_json),
                    submitted_at = VALUES(submitted_at),
                    status = 'submitted',
                    updated_at = NOW()"
            );
            $stmt->execute([
                ':form_id' => (string)($record['formId'] ?? 'grasp_enrollment_2025'),
                ':session_id' => (string)($record['sessionId'] ?? ''),
                ':submitted_at' => (string)($record['submittedAtSql'] ?? date('Y-m-d H:i:s')),
                ':data_json' => json_encode($record['fields'] ?? [], JSON_UNESCAPED_UNICODE),
            ]);
            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function connect(): PDO
    {
        $dsn = (string)($this->config['db']['dsn'] ?? '');
        $user = (string)($this->config['db']['user'] ?? '');
        $password = (string)($this->config['db']['password'] ?? '');
        if ($dsn === '') {
            throw new RuntimeException('Database DSN is not configured.');
        }
        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchNormalizedSubmission(PDO $pdo, string $formType, string $sessionId): array
    {
        $sql = "SELECT fs.id, fs.form_type, fs.submitted_at, fs.payload_json, fs.email_html, fs.pdf_html, sp.session_id
                FROM form_submission fs
                INNER JOIN submission_package sp ON sp.id = fs.package_id
                WHERE sp.session_id = :session_id
                  AND fs.form_type = :form_type
                ORDER BY fs.submitted_at DESC, fs.id DESC
                LIMIT 1";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':session_id' => $sessionId,
                ':form_type' => $formType,
            ]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                return ['ok' => false, 'skipped' => true, 'reason' => 'normalized-miss'];
            }
            $fields = json_decode((string)($row['payload_json'] ?? '{}'), true);
            if (!is_array($fields)) {
                return ['ok' => false, 'skipped' => true, 'reason' => 'normalized-invalid-json'];
            }
            return [
                'ok' => true,
                'source' => 'normalized_db',
                'submissionId' => (int)($row['id'] ?? 0),
                'formType' => (string)($row['form_type'] ?? $formType),
                'sessionId' => (string)($row['session_id'] ?? $sessionId),
                'submittedAt' => (string)($row['submitted_at'] ?? ''),
                'fields' => $fields,
                'emailHtml' => (string)($row['email_html'] ?? ''),
                'pdfHtml' => (string)($row['pdf_html'] ?? ''),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'normalized-query-failed', 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchLegacyEnrollment(PDO $pdo, string $sessionId): array
    {
        $sql = "SELECT form_id, session_id, submitted_at, data_json, status
                FROM enrollments
                WHERE session_id = :session_id
                ORDER BY submitted_at DESC
                LIMIT 1";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':session_id' => $sessionId]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                return ['ok' => false, 'skipped' => true, 'reason' => 'legacy-miss'];
            }
            $fields = json_decode((string)($row['data_json'] ?? '{}'), true);
            if (!is_array($fields)) {
                return ['ok' => false, 'skipped' => true, 'reason' => 'legacy-invalid-json'];
            }
            return [
                'ok' => true,
                'source' => 'legacy_enrollments',
                'formType' => 'enrollment',
                'formId' => (string)($row['form_id'] ?? 'grasp_enrollment_2025'),
                'sessionId' => (string)($row['session_id'] ?? $sessionId),
                'submittedAt' => (string)($row['submitted_at'] ?? ''),
                'fields' => $fields,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'legacy-query-failed', 'error' => $e->getMessage()];
        }
    }
}
