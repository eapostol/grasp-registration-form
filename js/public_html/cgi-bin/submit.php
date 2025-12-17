<?php
header("Content-Type: application/json");

function respond($status, $payload)
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(405, ["message" => "Method not allowed."]);
}

$fullName = isset($_POST["fullName"]) ? trim($_POST["fullName"]) : "";
$email = isset($_POST["email"]) ? trim($_POST["email"]) : "";
$message = isset($_POST["message"]) ? trim($_POST["message"]) : "";

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

if (!empty($errors)) {
    respond(422, [
        "message" => "Please correct the highlighted fields.",
        "errors" => $errors,
    ]);
}

// Obfuscate recipient to avoid exposing email address plainly.
$recipient = "";
foreach ([101, 100, 64, 101, 100, 97, 112, 111, 115, 116, 111, 108, 46, 99, 111, 109] as $code) {
    $recipient .= chr($code);
}

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
