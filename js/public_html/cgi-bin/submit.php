<?php
header("Content-Type: application/json");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");
require_once dirname(__DIR__, 3) . '/api/lib/ContactAdminSecurity.php';

function respond($status, $payload)
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(405, ["message" => "Method not allowed."]);
}

$fullName = is_string($_POST["fullName"] ?? null) ? trim($_POST["fullName"]) : "";
$email = is_string($_POST["email"] ?? null) ? trim($_POST["email"]) : "";
$message = is_string($_POST["message"] ?? null) ? trim($_POST["message"]) : "";
$token = is_string($_POST['g-recaptcha-response'] ?? null)
    ? trim($_POST['g-recaptcha-response']) : '';

$errors = [];

$emailPattern = '/^[A-Za-z0-9]+(?:\.[A-Za-z0-9]+)*@(?:[A-Za-z0-9-]+\.){1,2}[A-Za-z]{2,}$/';

if ($fullName === "") {
    $errors["fullName"] = "Full Name is required.";
}

if ($email === "") {
    $errors["email"] = "Email is required.";
} elseif (!preg_match($emailPattern, $email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors["email"] = "Please enter a valid email address.";
}

if ($message === "") {
    $errors["message"] = "Message cannot be blank.";
}

if (strlen($fullName) > 200 || strlen($email) > 254 || strlen($message) > 10000) {
    respond(422, ['message' => 'Please shorten your name, email, or message.']);
}

if (!empty($errors)) {
    respond(422, [
        "message" => "Please correct the highlighted fields.",
        "errors" => $errors,
    ]);
}

if ($token === '' || strlen($token) > 16384) {
    respond(422, ['message' => 'Please complete the verification checkbox.']);
}

try {
    $contactConfig = contactAdminConfig();
    if (!contactAdminVerify($contactConfig, $token)) {
        respond(422, ['message' => 'Verification failed or expired. Please complete the checkbox again.']);
    }
} catch (Throwable $error) {
    // Never include Google responses, tokens, config paths, or credentials.
    respond(503, ['message' => 'Contact verification is temporarily unavailable. Please try again later.']);
}
$recipient = $contactConfig['recipient'];

$cleanName = htmlspecialchars($fullName, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
$cleanEmail = filter_var($email, FILTER_SANITIZE_EMAIL);
$cleanMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");

$subject = "Site Issue from Greenland Recreational Website";
$body = "A visitor submitted the following from the site issue form:\n\n";
$body .= "Full Name: {$cleanName}\n";
$body .= "Email: {$cleanEmail}\n\n";
$body .= "Message:\n{$cleanMessage}\n";

$headers = "From: Greenland Website <no-reply@greenlandrecreational.com>\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Reply-To: {$cleanEmail}\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

$sent = mail($recipient, $subject, $body, $headers);

if (!$sent) {
    respond(500, ["message" => "There was a problem sending your message. Please try again later."]);
}

respond(200, ["message" => "Your message has been sent. Thank you!"]);
