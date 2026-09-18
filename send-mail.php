<?php
declare(strict_types=1);

/**
 * Starfleet Innotech Contact Form SMTP Handler
 * Compatible with PHP 7.4+ and PHP 8.x (cPanel / Apache)
 */

header('Content-Type: application/json; charset=utf-8');

// Reject any non-POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Only POST requests are accepted.'
    ]);
    exit;
}

// 1. Zero-dependency .env loader
function loadEnv(string $path): array {
    if (!file_exists($path) || !is_readable($path)) {
        return [];
    }

    $env = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $val = trim($parts[1]);

        // Strip enclosing quotes if present
        if (strlen($val) >= 2) {
            $first = $val[0];
            $last = substr($val, -1);
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $val = substr($val, 1, -1);
            }
        }

        $env[$key] = $val;
    }

    return $env;
}

// Load environment variables from .env
$envPath = __DIR__ . '/.env';
$env = loadEnv($envPath);

// Verify PHPMailer autoload file exists
$autoloader = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoloader)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'PHPMailer dependency is missing. Please ensure vendor/autoload.php exists.'
    ]);
    exit;
}

require $autoloader;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 2. Parse request data (supports application/x-www-form-urlencoded, multipart/form-data, and application/json)
$input = $_POST;
if (empty($input)) {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $json = json_decode($rawInput, true);
        if (is_array($json)) {
            $input = $json;
        }
    }
}

// Honeypot spam trap (if filled, silently succeed or reject bot)
if (!empty($input['_gotcha'])) {
    // Return fake success to deceive bots
    echo json_encode(['success' => true, 'message' => 'Message sent successfully.']);
    exit;
}

// 3. Extract and sanitize inputs
$firstName = trim((string)($input['First-Name'] ?? $input['first_name'] ?? ''));
$lastName  = trim((string)($input['Last-Name'] ?? $input['last_name'] ?? ''));
$phone     = trim((string)($input['Contact-Number'] ?? $input['contact_number'] ?? $input['phone'] ?? ''));
$email     = trim((string)($input['Email-Address'] ?? $input['email_address'] ?? $input['email'] ?? ''));
$subject   = trim((string)($input['Subject'] ?? $input['subject'] ?? 'General Inquiry'));
$message   = trim((string)($input['Message'] ?? $input['message'] ?? ''));

// Validate required fields
$errors = [];
if ($firstName === '') {
    $errors[] = 'First Name is required.';
}
if ($lastName === '') {
    $errors[] = 'Last Name is required.';
}
if ($phone === '') {
    $errors[] = 'Contact Number is required.';
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid Email Address is required.';
}
if ($message === '') {
    $errors[] = 'Message is required.';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => implode(' ', $errors),
        'errors'  => $errors
    ]);
    exit;
}

// 4. Optional Google reCAPTCHA verification
$recaptchaSecret = trim((string)($env['RECAPTCHA_SECRET_KEY'] ?? ''));
if ($recaptchaSecret !== '') {
    $recaptchaResponse = (string)($input['g-recaptcha-response'] ?? '');
    if ($recaptchaResponse === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Please complete the reCAPTCHA verification.'
        ]);
        exit;
    }

    $verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
    $postData = http_build_query([
        'secret'   => $recaptchaSecret,
        'response' => $recaptchaResponse,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ]);

    $ch = curl_init($verifyUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to verify reCAPTCHA with server: ' . $curlError
        ]);
        exit;
    }

    $result = json_decode($response, true);
    if (empty($result['success'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'reCAPTCHA verification failed. Please try again.'
        ]);
        exit;
    }
}

// 5. Check required SMTP configuration
$smtpHost = $env['SMTP_HOST'] ?? '';
$smtpUser = $env['SMTP_USER'] ?? '';
$smtpPass = $env['SMTP_PASS'] ?? '';
$mailTo   = $env['MAIL_TO'] ?? '';

if ($smtpHost === '' || $smtpUser === '' || $smtpPass === '' || $mailTo === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'SMTP is not fully configured in .env (SMTP_HOST, SMTP_USER, SMTP_PASS, and MAIL_TO are required).'
    ]);
    exit;
}

// 6. Build and send email via PHPMailer
$mail = new PHPMailer(true);

try {
    // Server settings
    $mail->isSMTP();
    $mail->Host       = $smtpHost;
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtpUser;
    $mail->Password   = $smtpPass;

    $secure = strtolower($env['SMTP_SECURE'] ?? 'tls');
    if ($secure === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mail->Port = (int)($env['SMTP_PORT'] ?? ($secure === 'ssl' ? 465 : 587));
    $mail->CharSet = 'UTF-8';

    // Allow SSL connections to IP addresses and cPanel servers
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];

    // Sender & Recipients
    $mailFrom     = $env['MAIL_FROM'] ?? $smtpUser;
    $mailFromName = $env['MAIL_FROM_NAME'] ?? 'Starfleet Innotech Inc.';
    $mail->setFrom($mailFrom, $mailFromName);

    // Support multiple recipients separated by comma
    $recipients = array_map('trim', explode(',', $mailTo));
    foreach ($recipients as $recipient) {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $mail->addAddress($recipient);
        }
    }

    // Reply-to sender's contact details
    $fullName = "$firstName $lastName";
    $mail->addReplyTo($email, $fullName);

    // Content
    // Content
    $mail->isHTML(true);
    $mail->Subject = "New Form Submitted on SFIO Website";

    $safeFirstName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
    $safeLastName  = htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8');
    $safeEmail     = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $safePhone     = htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');
    $safeSubject   = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
    $safeMessage   = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

    $htmlBody = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #111; background-color: #f7f9fa; padding: 20px; }
            .card { max-width: 580px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 24px; }
            .intro { margin-bottom: 20px; font-size: 15px; color: #222; }
            .field-row { margin-bottom: 12px; font-size: 14px; }
            .label { font-weight: bold; color: #333; }
            .message-content { margin-top: 6px; padding: 12px; background: #f8fafc; border: 1px solid #edf2f7; border-radius: 4px; white-space: pre-wrap; }
        </style>
    </head>
    <body>
        <div class='card'>
            <div class='intro'>You just received a new form submission on your website <strong>Starfleet Innotech, Inc.</strong></div>
            <div class='field-row'><span class='label'>First Name:</span> {$safeFirstName}</div>
            <div class='field-row'><span class='label'>Last Name:</span> {$safeLastName}</div>
            <div class='field-row'><span class='label'>Contact Number:</span> {$safePhone}</div>
            <div class='field-row'><span class='label'>Email Address:</span> <a href='mailto:{$safeEmail}'>{$safeEmail}</a></div>
            <div class='field-row'><span class='label'>Subject:</span> {$safeSubject}</div>
            <div class='field-row'>
                <span class='label'>field:</span>
                <div class='message-content'>{$safeMessage}</div>
            </div>
        </div>
    </body>
    </html>
    ";

    $plainText = "You just received a new form submission on your website Starfleet Innotech, Inc.\n\n"
               . "First Name: $firstName\n"
               . "Last Name: $lastName\n"
               . "Contact Number: $phone\n"
               . "Email Address: $email\n"
               . "Subject: $subject\n"
               . "field: $message\n";

    $mail->Body    = $htmlBody;
    $mail->AltBody = $plainText;

    $mail->send();

    echo json_encode([
        'success' => true,
        'message' => 'Thank you! Your message has been sent successfully.'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to send message. Mailer error: ' . $mail->ErrorInfo
    ]);
}
