<?php
/**
 * Anydrop — EmailOtpService: the central orchestrator (plan §9)
 *
 * customer-request-otp.php / restaurant-request-otp.php call
 * EmailOtpService::send() and get back one normalized result. They
 * never know or care which of the 6 providers actually delivered it.
 *
 * Responsibilities (plan §9):
 *  1. Load active providers, priority-ordered, quota-checked (delegated
 *     to ProviderRegistry).
 *  2. Attempt each in turn.
 *  3. Interpret each provider's ProviderResult.
 *  4. Log every attempt to email_otp_logs.
 *  5. Update daily_used/monthly_used/last_success_at/last_failure_at/
 *     consecutive_failures on success or failure.
 *  6. Fail over automatically on retryable errors (plan §3);
 *     non-retryable errors are also logged and failed over past —
 *     defensively, since a "non-retryable" classification here is
 *     about the *email payload/config*, not about whether the OTP
 *     itself is still worth trying to deliver through another channel.
 *  7. Return one normalized result. Never claims success unless a
 *     provider actually accepted the message (plan §3, "Never return
 *     OTP sent when no provider actually accepted the message").
 */

require_once __DIR__ . '/ProviderRegistry.php';
require_once __DIR__ . '/ProviderResult.php';

class EmailOtpService
{
    private ProviderRegistry $registry;

    public function __construct(private PDO $db)
    {
        $this->registry = new ProviderRegistry($db);
    }

    /**
     * @param string $purpose one of: customer_login, restaurant_signup,
     *                        restaurant_login, email_change, password_reset,
     *                        rider_pickup_otp_resend, customer_delivery_otp_resend
     * @param string|null $subject   Override the default subject line
     *                               (2026-09-11: pickup/delivery OTP resends
     *                               want an order-specific subject/heading
     *                               rather than the generic login-code copy).
     * @param string|null $heading   Override the big heading inside the email.
     * @param string|null $intro     Override the one-line intro paragraph
     *                               shown above the code.
     * @return array{success: bool, provider_driver_key: ?string, error: ?string}
     */
    public function send(
        string $to,
        string $otp,
        string $purpose,
        int $expiryMinutes,
        ?string $subject = null,
        ?string $heading = null,
        ?string $intro = null
    ): array {
        $subject = $subject ?? 'Your AnyDrop confirmation code';
        [$html, $text] = $this->buildEmail($otp, $expiryMinutes, $heading, $intro);

        $providers = $this->registry->activeProvidersInOrder();

        if (empty($providers)) {
            // No active/eligible provider at all — nothing to attempt.
            $this->logAttempt(null, $to, $purpose, 'failed', 'no_active_provider', null, null, 1);
            return ['success' => false, 'provider_driver_key' => null, 'error' => 'email_delivery_unavailable'];
        }

        $attempt = 0;
        foreach ($providers as $entry) {
            $attempt++;
            $row = $entry['row'];
            $providerId = (int) $row['id'];

            /** @var ProviderResult $result */
            $result = $entry['driver']->send($to, $subject, $html, $text, $entry['config']);

            if ($result->success) {
                $this->markSuccess($providerId);
                $this->logAttempt($providerId, $to, $purpose, 'sent', null, $result->httpStatus, $result->providerMessageId, $attempt);
                return ['success' => true, 'provider_driver_key' => $row['driver_key'], 'error' => null];
            }

            $this->markFailure($providerId);
            $this->logAttempt($providerId, $to, $purpose, 'failed', $result->errorType, $result->httpStatus, null, $attempt);
            // Always continue to the next provider on failure — plan §3
            // only carves out an exception for *validation* errors
            // (invalid recipient, missing fields, cooldown), which never
            // reach EmailOtpService in the first place because the
            // calling endpoint validates before calling send().
        }

        return ['success' => false, 'provider_driver_key' => null, 'error' => 'email_delivery_unavailable'];
    }

    /**
     * 2026-09-11 (app-owner ask, "mail OTPs ko attractive banao HTML use
     * kar ke") — replaces the old bare-bones single <div> with a proper
     * branded HTML email: a colored header bar, a white content card
     * with the OTP in a bordered "pill" box (rather than just large
     * text), and a muted footer. Table-based layout (not flex/grid) on
     * purpose — this needs to render correctly inside Gmail/Outlook's
     * own HTML sanitizers, which strip modern CSS layout properties;
     * tables + inline styles are the one thing every mail client still
     * renders consistently. $heading/$intro let call sites (e.g. the
     * pickup/delivery OTP resend endpoints) customize the copy while
     * reusing the exact same visual shell as every login/signup OTP
     * email already sends.
     */
    private function buildEmail(string $otp, int $expiryMinutes, ?string $heading = null, ?string $intro = null): array
    {
        $heading = $heading ?? 'Your confirmation code';
        $intro = $intro ?? 'Use the code below to continue. Don\'t share it with anyone — AnyDrop staff will never ask you for it over phone or chat.';
        $brandColor = '#E64A19';
        $otpEsc = htmlspecialchars($otp);
        $headingEsc = htmlspecialchars($heading);
        $introEsc = htmlspecialchars($intro);
        $expiryLineEsc = $expiryMinutes > 0
            ? 'This code expires in <strong>' . (int) $expiryMinutes . ' minutes</strong>.'
            : 'This code <strong>stays valid for this order only</strong>.';

        $html = <<<HTML
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F4F5;padding:32px 0;font-family:Arial,Helvetica,sans-serif;">
  <tr>
    <td align="center">
      <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="max-width:480px;width:100%;background:#FFFFFF;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
        <tr>
          <td style="background:{$brandColor};padding:22px 28px;">
            <span style="font-size:20px;font-weight:bold;color:#FFFFFF;letter-spacing:0.5px;">AnyDrop</span>
          </td>
        </tr>
        <tr>
          <td style="padding:32px 28px 8px 28px;">
            <h1 style="margin:0 0 12px 0;font-size:19px;color:#1A1A1A;">{$headingEsc}</h1>
            <p style="margin:0 0 24px 0;font-size:14px;line-height:1.6;color:#555555;">{$introEsc}</p>
          </td>
        </tr>
        <tr>
          <td style="padding:0 28px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FFF3EE;border:1px dashed {$brandColor};border-radius:10px;">
              <tr>
                <td align="center" style="padding:18px 0;">
                  <span style="font-size:34px;font-weight:bold;letter-spacing:10px;color:{$brandColor};">{$otpEsc}</span>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:20px 28px 32px 28px;">
            <p style="margin:0;font-size:13px;color:#888888;line-height:1.6;">
              {$expiryLineEsc}
              If you didn't request this, you can safely ignore this email —
              no action is needed on your part.
            </p>
          </td>
        </tr>
        <tr>
          <td style="background:#FAFAFA;padding:16px 28px;border-top:1px solid #EEEEEE;">
            <p style="margin:0;font-size:11px;color:#AAAAAA;">
              &copy; AnyDrop. This is an automated message, please don't reply to this email.
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
HTML;

        $expiryLine = $expiryMinutes > 0
            ? "This code expires in {$expiryMinutes} minutes."
            : 'This code stays valid for this order only.';
        $text = "{$heading}\n\n{$intro}\n\nYour code: {$otp}\n{$expiryLine}\nIf you didn't request this, you can safely ignore this email.";
        return [$html, $text];
    }

    private function markSuccess(int $providerId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE email_otp_providers
             SET daily_used = daily_used + 1, monthly_used = monthly_used + 1,
                 last_success_at = NOW(), consecutive_failures = 0
             WHERE id = :id'
        );
        $stmt->execute(['id' => $providerId]);
    }

    private function markFailure(int $providerId): void
    {
        // Deliberately does NOT increment daily/monthly usage — a
        // provider that rejected/failed the send didn't consume the
        // quota it never actually used (plan §4, "Do not permanently
        // disable a provider because of one temporary error" — this
        // only tracks consecutive_failures for the Admin Panel's health
        // display, it never auto-disables is_active).
        $stmt = $this->db->prepare(
            'UPDATE email_otp_providers
             SET last_failure_at = NOW(), consecutive_failures = consecutive_failures + 1
             WHERE id = :id'
        );
        $stmt->execute(['id' => $providerId]);
    }

    private function logAttempt(
        ?int $providerId,
        string $to,
        string $purpose,
        string $status,
        ?string $errorReason,
        ?int $httpStatus,
        ?string $providerMessageId,
        int $attemptNumber
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO email_otp_logs
                (provider_id, recipient_email, purpose, status, error_reason, provider_http_status, provider_message_id, attempt_number)
             VALUES (:pid, :email, :purpose, :status, :reason, :http, :mid, :attempt)'
        );
        $stmt->execute([
            'pid' => $providerId,
            'email' => $to,
            'purpose' => $purpose,
            'status' => $status,
            'reason' => $errorReason,
            'http' => $httpStatus,
            'mid' => $providerMessageId,
            'attempt' => $attemptNumber,
        ]);
        // Note: never logs the OTP code, API keys, or full Authorization
        // headers (plan §20).
    }
}
