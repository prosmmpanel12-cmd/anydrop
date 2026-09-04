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
     *                        restaurant_login, email_change, password_reset
     * @return array{success: bool, provider_driver_key: ?string, error: ?string}
     */
    public function send(string $to, string $otp, string $purpose, int $expiryMinutes): array
    {
        $subject = 'Your AnyDrop confirmation code';
        [$html, $text] = $this->buildEmail($otp, $expiryMinutes);

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

    private function buildEmail(string $otp, int $expiryMinutes): array
    {
        $html = '<div style="font-family:sans-serif;max-width:420px;margin:0 auto;padding:24px">'
            . '<h2 style="margin:0 0 16px">AnyDrop confirmation code</h2>'
            . '<p style="font-size:32px;letter-spacing:6px;font-weight:700;margin:0 0 16px">' . htmlspecialchars($otp) . '</p>'
            . '<p style="color:#555">This code expires in ' . (int) $expiryMinutes . ' minutes. '
            . 'If you didn\'t request this, you can safely ignore this email.</p>'
            . '</div>';
        $text = "Your AnyDrop confirmation code is: {$otp}\nThis code expires in {$expiryMinutes} minutes.\nIf you didn't request this, you can safely ignore this email.";
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
