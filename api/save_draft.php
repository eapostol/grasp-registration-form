<?php
// api/save-draft.php
// Saves in-progress data to the database (best-effort).
// Works even if the DB is disabled in config.php; in that case it returns ok=false with a reason.

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload) || empty($payload['formId']) || empty($payload['sessionId']) || !is_array($payload['data'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

$formId = $payload['formId'];
$sessionId = $payload['sessionId'];
$dataJson = json_encode($payload['data'], JSON_UNESCAPED_UNICODE);

$config = require __DIR__ . '/config.php';
if (empty($config['db']['dsn'])) {
    echo json_encode(['ok' => false, 'reason' => 'db-disabled']);
    exit;
}

try {
    $pdo = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Try to UPDATE the most recent row for this session; if none, INSERT a new one.
    $select = $pdo->prepare("SELECT id FROM enrollments WHERE form_id = :f AND session_id = :s ORDER BY id DESC LIMIT 1");
    $select->execute([':f' => $formId, ':s' => $sessionId]);
    $row = $select->fetch();

    if ($row && isset($row['id'])) {
        $upd = $pdo->prepare("UPDATE enrollments SET data_json = :j, submitted_at = NOW() WHERE id = :id");
        $upd->execute([':j' => $dataJson, ':id' => $row['id']]);
    } else {
        $sql = "INSERT INTO enrollments (form_id, session_id, submitted_at, data_json, status)
        VALUES (:f, :s, NOW(), :j, 'draft')
        ON DUPLICATE KEY UPDATE
          data_json = VALUES(data_json),
          status = 'draft',
          submitted_at = NOW(),
          updated_at = NOW()";
        $ins = $pdo->prepare($sql);
        $ins->execute([':f' => $formId, ':s' => $sessionId, ':j' => $dataJson]);
    }

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
