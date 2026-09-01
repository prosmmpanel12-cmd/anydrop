<?php
/**
 * QrPay — Mailer (OTP emails)
 *
 * Requires PHPMailer. Install via Composer:
 *   composer require phpmailer/phpmailer
 *
 * All SMTP credentials come from environment variables — see
 * config/env.example. Nothing here is hardcoded.
 */

require_once __DIR__ . '/../vendor/autoload.php'; // Composer autoload for PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Shared low-level sender — every email function below builds a
 * PHPMailer instance the same way, only subject/body differ.
 * Returns true on success, false on failure (never throws to callers).
 */
function qrpay_send_mail(string $toEmail, string $subject, string $html, string $altText): bool {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = qrpay_env('SMTP_HOST');
        $mail->SMTPAuth   = true;
        $mail->Username   = qrpay_env('SMTP_USER');
        $mail->Password   = qrpay_env('SMTP_PASS');
        $mail->Port       = (int) qrpay_env('SMTP_PORT', '587');

        $encryption = qrpay_env('SMTP_ENCRYPTION', 'tls');
        $mail->SMTPSecure = $encryption === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;

        $mail->setFrom(
            qrpay_env('SMTP_FROM_EMAIL'),
            qrpay_env('SMTP_FROM_NAME', 'QrPay')
        );
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $altText;

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('QrPay email failed to ' . $toEmail . ' [' . $subject . ']: ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Sends the 2FA login code. (Signup/login identity itself is now
 * email+password — see auth/signup.php, auth/login.php — this OTP is
 * ONLY the per-user-toggleable second factor at login.)
 */
function send_otp_email(string $toEmail, string $otp, int $expiryMinutes): bool {
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
    $html = <<<HTML
    <div style="font-family: Arial, sans-serif; max-width: 480px; margin: 0 auto; padding: 24px;">
      <h2 style="margin-bottom: 8px;">Your QrPay 2FA code</h2>
      <p style="color:#555;">Enter this code to finish logging in. It expires in {$expiryMinutes} minutes.</p>
      <div style="font-size: 32px; font-weight: bold; letter-spacing: 6px; padding: 16px 0;">
        {$safeOtp}
      </div>
      <p style="color:#888; font-size: 13px;">
        Didn't try to log in? Your password may be compromised — reset it right away.
      </p>
    </div>
    HTML;

    $altText = "Your QrPay 2FA code is {$otp}. It expires in {$expiryMinutes} minutes. "
        . "Didn't try to log in? Reset your password right away.";

    return qrpay_send_mail($toEmail, "Your QrPay 2FA code: {$otp}", $html, $altText);
}

/**
 * Sends the "verify your email" link at signup. Only used when
 * admin_settings.email_verification_enabled = 1 (see config/db.php).
 */
function send_verification_email(string $toEmail, string $verifyLink, int $expiryMinutes): bool {
    $safeLink = htmlspecialchars($verifyLink, ENT_QUOTES, 'UTF-8');
    $html = <<<HTML
    <div style="font-family: Arial, sans-serif; max-width: 480px; margin: 0 auto; padding: 24px;">
      <h2 style="margin-bottom: 8px;">Verify your QrPay account</h2>
      <p style="color:#555;">Click the button below to verify your email and activate your account. This link expires in {$expiryMinutes} minutes.</p>
      <p style="padding: 16px 0;">
        <a href="{$safeLink}" style="background:#111;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;display:inline-block;">Verify Email</a>
      </p>
      <p style="color:#888; font-size: 13px;">
        Or paste this link into your browser: {$safeLink}
      </p>
      <p style="color:#888; font-size: 13px;">
        Didn't create a QrPay account? You can safely ignore this email.
      </p>
    </div>
    HTML;

    $altText = "Verify your QrPay account by visiting: {$verifyLink} (expires in {$expiryMinutes} minutes). "
        . "Didn't create a QrPay account? You can safely ignore this email.";

    return qrpay_send_mail($toEmail, 'Verify your QrPay account', $html, $altText);
}

/**
 * Sends the "reset your password" link. Always safe to call — the
 * caller (auth/forgot_password.php) decides whether to call this at
 * all based on account existence, but the RESPONSE to the client is
 * identical either way, to avoid leaking which emails have accounts.
 */
function send_password_reset_email(string $toEmail, string $resetLink, int $expiryMinutes): bool {
    $safeLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');
    $html = <<<HTML
    <div style="font-family: Arial, sans-serif; max-width: 480px; margin: 0 auto; padding: 24px;">
      <h2 style="margin-bottom: 8px;">Reset your QrPay password</h2>
      <p style="color:#555;">Click the button below to choose a new password. This link expires in {$expiryMinutes} minutes and can only be used once.</p>
      <p style="padding: 16px 0;">
        <a href="{$safeLink}" style="background:#111;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;display:inline-block;">Reset Password</a>
      </p>
      <p style="color:#888; font-size: 13px;">
        Or paste this link into your browser: {$safeLink}
      </p>
      <p style="color:#888; font-size: 13px;">
        Didn't request this? You can safely ignore this email — your password won't change.
      </p>
    </div>
    HTML;

    $altText = "Reset your QrPay password: {$resetLink} (expires in {$expiryMinutes} minutes, single use). "
        . "Didn't request this? You can safely ignore this email.";

    return qrpay_send_mail($toEmail, 'Reset your QrPay password', $html, $altText);
}
