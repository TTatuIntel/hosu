<?php
/**
 * mailer.php — SMTP email helper using PHPMailer.
 *
 * Drop-in replacement for PHP mail(). Reads SMTP config from .env via env.php.
 *
 * Usage:
 *   require_once 'mailer.php';
 *   hosuMail($to, $subject, $htmlBody);             // simple
 *   hosuMail($to, $subject, $htmlBody, $fromName);  // custom sender name
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/env.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Send an HTML email via SMTP.
 *
 * @param string $to        Recipient email
 * @param string $subject   Email subject
 * @param string $htmlBody  Full HTML body
 * @param string $fromName  Sender display name (default: HOSU)
 * @return bool             True on success
 */
function hosuMail(string $to, string $subject, string $htmlBody, string $fromName = 'HOSU'): bool
{
    $mail = new PHPMailer(true);

    try {
        // SMTP configuration from environment
        $smtpHost = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $smtpPort = (int)(getenv('SMTP_PORT') ?: 587);
        $smtpUser = getenv('SMTP_USER') ?: 'infor@hosu.or.ug';
        $smtpPass = getenv('SMTP_PASS') ?: '';
        $smtpFrom = getenv('SMTP_FROM') ?: ($smtpUser ?: 'infor@hosu.or.ug');
        $smtpFromName = getenv('SMTP_FROM_NAME') ?: $fromName;
        $smtpEncryption = getenv('SMTP_ENCRYPTION') ?: 'tls';

        // If no SMTP credentials configured, fall back to PHP mail()
        if (empty($smtpUser) || empty($smtpPass)) {
            error_log('HOSU Mailer: No SMTP credentials configured, falling back to mail()');
            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: " . $smtpFromName . " <" . $smtpFrom . ">\r\n";
            return @mail($to, $subject, $htmlBody, $headers);
        }

        // Server settings
        $mail->isSMTP();
        $mail->Host       = $smtpHost;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->Port       = $smtpPort;

        if ($smtpEncryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($smtpEncryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }

        // Timeout
        $mail->Timeout = 30;

        // Recipients
        $mail->setFrom($smtpFrom, $smtpFromName);
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        // Plain text fallback
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('HOSU Mailer Error: ' . $e->getMessage());
        return false;
    }
}
