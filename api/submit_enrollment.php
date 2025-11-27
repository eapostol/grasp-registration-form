<?php
// api/submit_enrollment.php
// Final submission endpoint: stores to DB (if configured) and sends an HTML email.
// In dev (DDEV), PHP mail() is captured by Mailpit. In production, consider SMTP with PHPMailer for deliverability.

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) || empty($data['formId']) || empty($data['data'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid payload']);
    exit;
}

$formId     = $data['formId'];
$sessionId  = isset($data['sessionId']) ? $data['sessionId'] : null;
$submitted  = isset($data['submittedAt']) ? $data['submittedAt'] : date('c');
$fields     = $data['data'];

$config = require __DIR__ . '/config.php';

// 1) Save to DB (optional)
$dbSaved = false;
if (!empty($config['db']['dsn'])) {
    try {
        $pdo = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Upsert-like: update most recent row for this session, else insert
        if (!empty($sessionId)) {
            $select = $pdo->prepare("SELECT id FROM enrollments WHERE form_id = :f AND session_id = :s ORDER BY id DESC LIMIT 1");
            $select->execute([':f' => $formId, ':s' => $sessionId]);
            $row = $select->fetch();
        } else {
            $row = false;
        }

        if ($row && isset($row['id'])) {
            $upd = $pdo->prepare("UPDATE enrollments SET data_json = :j, submitted_at = :t WHERE id = :id");
            $upd->execute([
                ':j'   => json_encode($fields, JSON_UNESCAPED_UNICODE),
                ':t'   => date('Y-m-d H:i:s', strtotime($submitted)),
                ':id'  => $row['id']
            ]);
        } else {
            $sql = "INSERT INTO enrollments (form_id, session_id, submitted_at, data_json, status)
                    VALUES (:f, :s, :t, :j, 'submitted')
                    ON DUPLICATE KEY UPDATE
                    data_json = VALUES(data_json),
                    status = 'submitted',
                    submitted_at = VALUES(submitted_at),
                    updated_at = NOW()";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
            ':f' => $formId,
            ':s' => $sessionId ?: '',
            ':t' => date('Y-m-d H:i:s', strtotime($submitted)),
            ':j' => json_encode($fields, JSON_UNESCAPED_UNICODE),
            ]);

        }

        $dbSaved = true;
    } catch (Throwable $e) {
        // DB is optional; continue to email
        $dbSaved = false;
    }
}

// 2) Build email HTML (table by sections if front-end sent preview; here we flat-render key-value pairs)
function escape_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$rows = '';
foreach ($fields as $k => $v) {
    $rows .= '<tr><td style="border:1px solid #e5e7eb;padding:6px 8px;font-weight:600;width:38%;">' . escape_html($k) .
             '</td><td style="border:1px solid #e5e7eb;padding:6px 8px;">' . escape_html($v) . '</td></tr>';
}

$emailHtml = '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;">
  <h2 style="margin:0 0 10px;">GRASP Enrollment Submission</h2>
  <p style="margin:0 0 12px;">Submitted at: ' . escape_html($submitted) . '</p>
  <table style="border-collapse:collapse;width:100%;max-width:900px;">' . $rows . '</table>
</div>';

// Subject and headers
$subject = isset($config['email_subject']) ? $config['email_subject'] : 'New GRASP Enrollment Submission';
$to      = isset($config['email_to']) ? $config['email_to'] : '';
$from    = isset($config['email_from']) ? $config['email_from'] : 'no-reply@example.com';

if (empty($to)) {
    echo json_encode(['success' => false, 'error' => 'Email recipient not configured', 'dbSaved' => $dbSaved]);
    exit;
}

// Use parent email for Reply-To if available
$parentEmail = '';
foreach (['parent1_email','parent_email','guardian_email','email'] as $key) {
    if (!empty($fields[$key])) { $parentEmail = $fields[$key]; break; }
}

$headers = [];
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-type: text/html; charset=UTF-8';
$headers[] = 'From: ' . $from;
if (!empty($parentEmail)) {
    $headers[] = 'Reply-To: ' . $parentEmail;
}
$headers[] = 'X-Mailer: PHP/' . phpversion();

$success = @mail($to, $subject, $emailHtml, implode("\r\n", $headers));

echo json_encode([ 'success' => (bool)$success, 'dbSaved' => $dbSaved ]);
