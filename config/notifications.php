<?php
/**
 * Notification helpers — SMS (Africa's Talking) + Email (SMTP)
 * Auto-detects PHPMailer if installed, falls back to raw socket otherwise.
 */

// ============================================
// SMS — Africa's Talking
// ============================================
if (!defined('AT_USERNAME'))   define('AT_USERNAME',   getenv('AT_USERNAME')   ?: 'sandbox');
if (!defined('AT_API_KEY'))    define('AT_API_KEY',    getenv('AT_API_KEY')    ?: '');
if (!defined('AT_SENDER_ID'))  define('AT_SENDER_ID',  getenv('AT_SENDER_ID')  ?: '');

// ============================================
// EMAIL — SMTP
// ============================================
if (!defined('SMTP_HOST'))      define('SMTP_HOST',      getenv('SMTP_HOST')      ?: 'smtp.gmail.com');
if (!defined('SMTP_PORT'))      define('SMTP_PORT',      (int)(getenv('SMTP_PORT') ?: 587));
if (!defined('SMTP_USER'))      define('SMTP_USER',      getenv('SMTP_USER')      ?: '');
if (!defined('SMTP_PASS'))      define('SMTP_PASS',      getenv('SMTP_PASS')      ?: '');
if (!defined('SMTP_FROM'))      define('SMTP_FROM',      getenv('SMTP_FROM')      ?: 'noreply@example.com');
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: (defined('APP_NAME') ? APP_NAME : 'POS'));

// ============================================
// HELPER: Normalize phone to E.164 (+256…)
// ============================================
function normalize_phone($phone) {
    $phone = preg_replace('/[^0-9]/', '', (string)$phone);
    if (empty($phone)) return '';
    if (strlen($phone) === 10 && $phone[0] === '0') return '+256' . substr($phone, 1);
    if (strlen($phone) === 9)                       return '+256' . $phone;
    if (strlen($phone) === 12 && substr($phone, 0, 3) === '256') return '+' . $phone;
    return '+' . $phone;
}

// ============================================
// SMS: send via Africa's Talking
// ============================================
function send_sms($phone, $message) {
    $phone = normalize_phone($phone);
    if (empty($phone)) return ['success' => false, 'error' => 'Invalid phone number'];
    
    // Sandbox / no credentials → log only
    if (AT_USERNAME === 'sandbox' || empty(AT_API_KEY)) {
        error_log("[SMS-SANDBOX] To: $phone | Msg: $message");
        return ['success' => true, 'message_id' => 'sandbox_' . uniqid()];
    }
    
    $data = [
        'username' => AT_USERNAME,
        'to'       => $phone,
        'message'  => $message,
    ];
    if (!empty(AT_SENDER_ID)) $data['from'] = AT_SENDER_ID;
    
    $ch = curl_init('https://api.africastalking.com/version1/messaging');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['apiKey: ' . AT_API_KEY, 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);
    
    if ($curl_err) {
        error_log("[SMS] cURL error: $curl_err");
        return ['success' => false, 'error' => 'Network error'];
    }
    
    $json = json_decode($response, true);
    $recipient = $json['SMSMessageData']['Recipients'][0] ?? null;
    
    if ($http_code >= 200 && $http_code < 300 && $recipient && strcasecmp($recipient['status'] ?? '', 'Success') === 0) {
        return ['success' => true, 'message_id' => $recipient['messageId'] ?? null];
    }
    
    error_log("[SMS] Failed: HTTP $http_code | $response");
    return ['success' => false, 'error' => $recipient['status'] ?? 'SMS delivery failed'];
}

// ============================================
// EMAIL: send via SMTP
// ============================================
function send_email($to, $subject, $body_html) {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Invalid email address'];
    }
    
    // No credentials → log only
    if (empty(SMTP_USER) || empty(SMTP_PASS)) {
        error_log("[EMAIL-SANDBOX] To: $to | Subject: $subject");
        error_log("[EMAIL-SANDBOX] Body: " . strip_tags($body_html));
        return ['success' => true, 'message_id' => 'sandbox_' . uniqid()];
    }
    
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return send_email_php_mailer($to, $subject, $body_html);
    }
    return send_email_socket($to, $subject, $body_html);
}

function send_email_php_mailer($to, $subject, $body_html) {
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_PORT == 465
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port    = SMTP_PORT;
        $mail->CharSet = 'UTF-8';
        
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body_html;
        $mail->AltBody = strip_tags($body_html);
        $mail->send();
        
        return ['success' => true, 'message_id' => $mail->getLastMessageID()];
    } catch (Exception $e) {
        error_log('[EMAIL] PHPMailer error: ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function send_email_socket($to, $subject, $body_html) {
    $prefix = (SMTP_PORT == 465) ? 'ssl://' : '';
    $sock = @fsockopen($prefix . SMTP_HOST, SMTP_PORT, $errno, $errstr, 15);
    if (!$sock) return ['success' => false, 'error' => "Connection failed: $errstr"];
    stream_set_timeout($sock, 15);
    
    try {
        $read = function() use ($sock) { return fgets($sock, 515); };
        $cmd  = function($c, $expected) use ($sock, $read) {
            fwrite($sock, $c . "\r\n");
            $resp = '';
            while ($line = $read()) {
                $resp .= $line;
                if (isset($line[3]) && $line[3] === ' ') break;
            }
            if ((int)substr($resp, 0, 3) !== $expected) throw new Exception("SMTP $c failed: $resp");
            return $resp;
        };
        
        $read();
        $cmd("EHLO " . gethostname(), 250);
        
        if (SMTP_PORT == 587) {
            $cmd("STARTTLS", 220);
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new Exception("TLS handshake failed");
            }
            $cmd("EHLO " . gethostname(), 250);
        }
        
        $cmd("AUTH LOGIN", 334);
        $cmd(base64_encode(SMTP_USER), 334);
        $cmd(base64_encode(SMTP_PASS), 235);
        $cmd("MAIL FROM:<" . SMTP_FROM . ">", 250);
        $cmd("RCPT TO:<" . $to . ">", 250);
        $cmd("DATA", 354);
        
        $headers = [
            'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM . '>',
            'To: <' . $to . '>',
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Date: ' . date('r'),
        ];
        $cmd(implode("\r\n", $headers) . "\r\n\r\n" . $body_html . "\r\n.", 250);
        $cmd("QUIT", 221);
        fclose($sock);
        
        return ['success' => true, 'message_id' => 'socket_' . uniqid()];
    } catch (Exception $e) {
        @fclose($sock);
        error_log('[EMAIL-SOCKET] ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ============================================
// HTML EMAIL TEMPLATE
// ============================================
function build_otp_email($full_name, $code, $org_name = '') {
    $org_line = $org_name
        ? '<p style="color:#718096;font-size:13px;">Organization: <strong>' . htmlspecialchars($org_name) . '</strong></p>'
        : '';
    
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#F8FAFC;font-family:Arial,sans-serif;">'
        . '<div style="max-width:500px;margin:30px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);">'
        .   '<div style="background:linear-gradient(135deg,#6B46C1,#553C9A);padding:30px;text-align:center;color:#fff;">'
        .     '<h1 style="margin:0;font-size:22px;">Password Reset Code</h1>'
        .   '</div>'
        .   '<div style="padding:32px 28px;">'
        .     '<p style="font-size:15px;color:#1A202C;">Hi <strong>' . htmlspecialchars($full_name) . '</strong>,</p>'
        .     '<p style="font-size:14px;color:#4A5568;line-height:1.6;">We received a request to reset your password. Use the code below to proceed:</p>'
        .     '<div style="text-align:center;margin:28px 0;">'
        .       '<div style="display:inline-block;background:#F3E8FF;color:#6B46C1;font-size:32px;font-weight:800;letter-spacing:10px;padding:18px 28px;border-radius:12px;font-family:\'Courier New\',monospace;">' . htmlspecialchars($code) . '</div>'
        .     '</div>'
        .     '<p style="font-size:13px;color:#718096;line-height:1.6;">This code expires in <strong>15 minutes</strong>. If you did not request this, you can safely ignore this email.</p>'
        .     $org_line
        .     '<hr style="border:none;border-top:1px solid #E2E8F0;margin:24px 0;">'
        .     '<p style="font-size:12px;color:#A0AEC0;text-align:center;margin:0;">For security, never share this code with anyone.</p>'
        .   '</div>'
        . '</div></body></html>';
}