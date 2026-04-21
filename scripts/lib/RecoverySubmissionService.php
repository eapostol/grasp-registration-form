<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/lib/EmailPrintTemplate.php';
require_once __DIR__ . '/../../api/lib/FormPdfGenerator.php';
require_once __DIR__ . '/../../api/lib/ParentManualPdfGenerator.php';
require_once __DIR__ . '/../../api/lib/NormalizedSubmissionStore.php';

final class RecoverySubmissionService
{
    private string $projectRoot;
    /** @var array<string,mixed> */
    private array $config;
    private RecoveryRepository $repository;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(string $projectRoot, array $config, RecoveryRepository $repository)
    {
        $this->projectRoot = $projectRoot;
        $this->config = $config;
        $this->repository = $repository;
    }

    /**
     * @param array<string,mixed> $parsedMessage
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function recover(array $parsedMessage, array $options): array
    {
        $formType = (string)($parsedMessage['formType'] ?? 'unknown');
        $sessionId = trim((string)($parsedMessage['sessionId'] ?? ''));
        $originalSubject = trim((string)($parsedMessage['subject'] ?? ''));
        $submittedAt = $this->normalizeSubmittedAt((string)($parsedMessage['submittedAt'] ?? ''));
        $messageId = trim((string)($parsedMessage['messageId'] ?? ''));

        $canonical = $this->repository->fetchCanonicalSubmission($formType, $sessionId);
        $fields = [];
        $fieldSource = 'parser';
        if (!empty($canonical['ok']) && is_array($canonical['fields'] ?? null)) {
            $fields = $canonical['fields'];
            $fieldSource = (string)($canonical['source'] ?? 'db');
            if (!empty($canonical['submittedAt'])) {
                $submittedAt = $this->normalizeSubmittedAt((string)$canonical['submittedAt']);
            }
        } elseif (is_array($parsedMessage['fields'] ?? null)) {
            $fields = $parsedMessage['fields'];
        }

        if ($sessionId === '') {
            $sessionId = $this->makeSyntheticSessionId($formType, $originalSubject, $submittedAt, $fields);
        }

        $rendered = $this->renderRecoveredSubmission($formType, $fields, $sessionId, $submittedAt);
        $normalized = ['ok' => false, 'skipped' => true];
        if (!empty($options['writeDb'])) {
            $normalized = NormalizedSubmissionStore::persistSubmission([
                'dsn' => (string)($this->config['db']['dsn'] ?? ''),
                'user' => (string)($this->config['db']['user'] ?? ''),
                'password' => (string)($this->config['db']['password'] ?? ''),
                'formType' => $formType,
                'sessionId' => $sessionId,
                'submittedAt' => $submittedAt,
                'status' => 'submitted',
                'fields' => $fields,
                'payloadJson' => json_encode($fields, JSON_UNESCAPED_UNICODE),
                'emailHtml' => (string)($rendered['emailHtml'] ?? ''),
                'pdfHtml' => (string)($rendered['pdfHtml'] ?? ''),
                'source' => 'recover_missing_attachments',
            ]);

            if ($formType === 'enrollment') {
                $this->repository->upsertLegacyEnrollment([
                    'formType' => $formType,
                    'formId' => 'grasp_enrollment_2025',
                    'sessionId' => $sessionId,
                    'submittedAtSql' => $submittedAt,
                    'fields' => $fields,
                ]);
            }
        }

        $mailResult = ['ok' => false, 'skipped' => true];
        if (!empty($options['send'])) {
            $mailResult = $this->sendRecoveredEmail([
                'formType' => $formType,
                'sessionId' => $sessionId,
                'subject' => $this->buildResendSubject($originalSubject, $formType, $fields),
                'bodyHtml' => (string)($rendered['htmlDoc'] ?? ''),
                'pdfPath' => (string)($rendered['pdfPath'] ?? ''),
                'pdfFilename' => (string)($rendered['pdfFilename'] ?? ''),
            ]);
            if (!empty($mailResult['ok']) && !empty($normalized['submissionId'])) {
                NormalizedSubmissionStore::markEmailSent([
                    'dsn' => (string)($this->config['db']['dsn'] ?? ''),
                    'user' => (string)($this->config['db']['user'] ?? ''),
                    'password' => (string)($this->config['db']['password'] ?? ''),
                    'submissionId' => (int)$normalized['submissionId'],
                    'source' => 'recover_missing_attachments',
                    'meta' => [
                        'mailSent' => true,
                        'messageId' => $messageId,
                        'fieldSource' => $fieldSource,
                    ],
                ]);
            }
        }

        return [
            'ok' => !empty($rendered['ok']),
            'formType' => $formType,
            'sessionId' => $sessionId,
            'submittedAt' => $submittedAt,
            'fieldSource' => $fieldSource,
            'normalizedSaved' => !empty($normalized['ok']),
            'mailSent' => !empty($mailResult['ok']),
            'resendSubject' => $this->buildResendSubject($originalSubject, $formType, $fields),
            'pdfPath' => (string)($rendered['pdfPath'] ?? ''),
            'pdfFilename' => (string)($rendered['pdfFilename'] ?? ''),
            'normalizedResult' => $normalized,
            'mailResult' => $mailResult,
            'renderResult' => $rendered,
            'messageId' => $messageId,
        ];
    }

    /**
     * @param array<string,string> $fields
     * @return array<string,mixed>
     */
    private function renderRecoveredSubmission(string $formType, array $fields, string $sessionId, string $submittedAtSql): array
    {
        $submittedDisplay = $this->formatSubmittedDisplay($submittedAtSql, $formType);

        if ($formType === 'enrollment') {
            $configPath = $this->projectRoot . '/config/enrollment-fields.json';
            $emailHtml = EmailPrintTemplate::renderFromConfig($configPath, $fields, [
                'formTitle' => 'GRASP Enrollment Form',
                'submittedAt' => $submittedAtSql,
                'sessionId' => $sessionId,
                'templateProfile' => 'enrollment',
            ]);
            $pdfHtml = EmailPrintTemplate::renderPdfFromConfig($configPath, $fields, [
                'formTitle' => 'GRASP Enrollment Form',
                'submittedAt' => $submittedAtSql,
                'sessionId' => $sessionId,
                'templateProfile' => 'enrollment',
            ]);
            $htmlDoc = $this->wrapHtmlDocument($emailHtml);
            $pdfDoc = $this->wrapHtmlDocument($pdfHtml);
            $pdfInfo = FormPdfGenerator::generateFromHtml(
                'GRASP Enrollment Form',
                $pdfDoc,
                'GRASP-Enrollment-Recovered-' . $sessionId,
                ['profile' => 'enrollment']
            );
            return [
                'ok' => true,
                'emailHtml' => $emailHtml,
                'pdfHtml' => $pdfHtml,
                'htmlDoc' => $htmlDoc,
                'pdfPath' => (string)($pdfInfo['path'] ?? ''),
                'pdfFilename' => (string)($pdfInfo['filename'] ?? 'GRASP-Enrollment.pdf'),
            ];
        }

        if ($formType === 'waitlist') {
            $configPath = $this->projectRoot . '/config/waitlist-fields.json';
            $emailHtml = EmailPrintTemplate::renderFromConfig($configPath, $fields, [
                'formTitle' => 'GRASP Wait List Application',
                'submittedAt' => $submittedDisplay,
                'sessionId' => $sessionId,
                'templateProfile' => 'waitlist',
            ]);
            $pdfHtml = EmailPrintTemplate::renderPdfFromConfig($configPath, $fields, [
                'formTitle' => 'GRASP Wait List Application',
                'submittedAt' => $submittedDisplay,
                'sessionId' => $sessionId,
                'templateProfile' => 'waitlist',
            ]);
            $htmlDoc = $this->wrapHtmlDocument($emailHtml);
            $pdfInfo = FormPdfGenerator::generateFromHtml(
                'GRASP Wait List Application',
                $this->wrapHtmlDocument($pdfHtml),
                'GRASP-Waitlist-Recovered-' . $sessionId,
                ['profile' => 'waitlist']
            );
            return [
                'ok' => true,
                'emailHtml' => $emailHtml,
                'pdfHtml' => $pdfHtml,
                'htmlDoc' => $htmlDoc,
                'pdfPath' => (string)($pdfInfo['path'] ?? ''),
                'pdfFilename' => (string)($pdfInfo['filename'] ?? 'GRASP-Waitlist.pdf'),
            ];
        }

        if ($formType === 'parent_manual') {
            $configPath = $this->projectRoot . '/config/parent-manual-fields.json';
            $emailHtml = EmailPrintTemplate::renderParentManualWithAttachmentNotice($configPath, $fields, [
                'formTitle' => 'GRASP Parent Manual Agreement',
                'submittedAt' => $submittedDisplay,
            ]);
            $bodyWithMeta = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#333;margin:0 0 10px;">'
                . '<p style="margin:0;">Session ID: ' . htmlspecialchars($sessionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                . '<p style="margin:0;">Submitted: ' . htmlspecialchars($submittedDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                . '</div>'
                . $emailHtml;
            $pdfInfo = ParentManualPdfGenerator::generate(['fields' => $fields], $sessionId);
            return [
                'ok' => true,
                'emailHtml' => $emailHtml,
                'pdfHtml' => '',
                'htmlDoc' => $this->wrapHtmlDocument($bodyWithMeta),
                'pdfPath' => (string)($pdfInfo['path'] ?? ''),
                'pdfFilename' => (string)($pdfInfo['filename'] ?? 'GRASP-Parent-Manual.pdf'),
            ];
        }

        throw new RuntimeException('Unsupported form type for recovery: ' . $formType);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function sendRecoveredEmail(array $payload): array
    {
        $to = (string)($this->config['email_to'] ?? '');
        $from = (string)($this->config['email_from'] ?? '');
        if ($to === '' || $from === '') {
            return ['ok' => false, 'error' => 'Email configuration is missing.'];
        }

        $boundary = '=_GRASP_RECOVERY_' . bin2hex(random_bytes(12));
        $headers = [
            'MIME-Version: 1.0',
            'From: ' . $from,
            'Reply-To: ' . $from,
            'X-Mailer: PHP/' . phpversion(),
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        ];
        if (!empty($this->config['email_bcc'])) {
            $headers[] = 'Bcc: ' . (string)$this->config['email_bcc'];
        }

        $message = '';
        $message .= '--' . $boundary . "\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= wordwrap((string)$payload['bodyHtml'], 900, "\r\n", true) . "\r\n\r\n";

        $pdfPath = (string)($payload['pdfPath'] ?? '');
        if ($pdfPath !== '' && is_file($pdfPath)) {
            $encoded = chunk_split(base64_encode((string)file_get_contents($pdfPath)));
            $filename = (string)($payload['pdfFilename'] ?? basename($pdfPath));
            $message .= '--' . $boundary . "\r\n";
            $message .= 'Content-Type: application/pdf; name="' . addcslashes($filename, '"') . '"' . "\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n";
            $message .= 'Content-Disposition: attachment; filename="' . addcslashes($filename, '"') . '"' . "\r\n\r\n";
            $message .= $encoded . "\r\n";
        }

        $message .= '--' . $boundary . "--\r\n";
        $headersStr = implode("\r\n", $headers);
        $subject = (string)($payload['subject'] ?? '');
        $envFrom = $from;
        if (preg_match('/<([^>]+)>/', $from, $m) === 1) {
            $envFrom = trim($m[1]);
        }
        $envFrom = preg_replace('/[\r\n]+/', '', $envFrom);
        if (!filter_var($envFrom, FILTER_VALIDATE_EMAIL)) {
            $envFrom = '';
        }

        $sent = false;
        $mailError = '';
        if ($envFrom !== '') {
            $sent = @mail($to, $subject, $message, $headersStr, '-f ' . $envFrom);
            if (!$sent) {
                $last = error_get_last();
                if (is_array($last) && isset($last['message'])) {
                    $mailError = (string)$last['message'];
                }
                $sent = @mail($to, $subject, $message, $headersStr);
            }
        } else {
            $sent = @mail($to, $subject, $message, $headersStr);
        }

        if (!$sent && $mailError === '') {
            $last = error_get_last();
            if (is_array($last) && isset($last['message'])) {
                $mailError = (string)$last['message'];
            }
        }

        return [
            'ok' => $sent,
            'error' => $mailError,
            'to' => $to,
            'bcc' => (string)($this->config['email_bcc'] ?? ''),
        ];
    }

    /**
     * @param array<string,string> $fields
     */
    private function buildResendSubject(string $originalSubject, string $formType, array $fields): string
    {
        $base = trim($originalSubject);
        if ($base === '') {
            if ($formType === 'waitlist') {
                $base = 'New GRASP Wait List Application';
                if (!empty($fields['child_name'])) {
                    $base .= ' - ' . $fields['child_name'];
                }
            } elseif ($formType === 'parent_manual') {
                $base = 'GRASP Parent Manual Agreement';
                if (!empty($fields['pm_ack_printed_name'])) {
                    $base .= ' - ' . $fields['pm_ack_printed_name'];
                }
            } else {
                $base = 'New GRASP Enrollment Submission';
            }
        }
        return '[resend] ' . $base;
    }

    private function makeSyntheticSessionId(string $formType, string $subject, string $submittedAtSql, array $fields): string
    {
        $seed = $formType . '|' . $subject . '|' . $submittedAtSql . '|' . json_encode($fields, JSON_UNESCAPED_UNICODE);
        return 'recovered-' . substr(sha1((string)$seed), 0, 16);
    }

    private function normalizeSubmittedAt(string $value): string
    {
        $ts = strtotime($value);
        if ($ts === false) {
            return date('Y-m-d H:i:s');
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private function formatSubmittedDisplay(string $submittedAtSql, string $formType): string
    {
        $ts = strtotime($submittedAtSql);
        if ($ts === false) {
            return $submittedAtSql;
        }
        if ($formType === 'waitlist') {
            return date('F j, Y, g:i a', $ts);
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private function wrapHtmlDocument(string $bodyHtml): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $bodyHtml . '</body></html>';
    }
}
