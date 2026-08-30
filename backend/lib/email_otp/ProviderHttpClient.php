<?php
/**
 * Anydrop — Email OTP: shared HTTP helper for provider drivers
 *
 * Every provider driver (Resend/Brevo/MailerSend/Sendix/Maileroo/
 * Mailjet) speaks JSON-over-HTTPS with only its auth header and payload
 * shape differing, so the request/error-classification plumbing lives
 * here once instead of being copy-pasted six times.
 *
 * Classifies failures into the retryable/non-retryable buckets from
 * plan §3: timeouts, connection failures, 429, and 5xx are retryable
 * (fail over to the next provider); 4xx other than 429/401/403 is
 * treated as non-retryable (bad request shape — another provider won't
 * fix that either, though EmailOtpService still logs it and moves on
 * defensively).
 */

class ProviderHttpClient
{
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>  $jsonBody
     */
    public static function postJson(string $url, array $headers, array $jsonBody, int $timeoutSeconds = 10): array
    {
        $ch = curl_init($url);
        $headerLines = ['Content-Type: application/json'];
        foreach ($headers as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($jsonBody),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
        ]);

        $body = curl_exec($ch);
        $errNo = curl_errno($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo === CURLE_OPERATION_TIMEDOUT) {
            return ['ok' => false, 'errorType' => 'timeout', 'errorMessage' => 'Request to provider timed out', 'httpStatus' => null, 'retryable' => true];
        }
        if ($errNo !== 0) {
            return ['ok' => false, 'errorType' => 'connection_failure', 'errorMessage' => 'Could not reach provider (curl errno ' . $errNo . ')', 'httpStatus' => null, 'retryable' => true];
        }

        $decoded = json_decode((string) $body, true);

        if ($httpStatus === 429) {
            return ['ok' => false, 'errorType' => 'rate_limit', 'errorMessage' => self::extractMessage($decoded, 'Rate limit reached'), 'httpStatus' => $httpStatus, 'retryable' => true];
        }
        if ($httpStatus === 401 || $httpStatus === 403) {
            return ['ok' => false, 'errorType' => 'auth_failure', 'errorMessage' => self::extractMessage($decoded, 'Provider rejected the API key'), 'httpStatus' => $httpStatus, 'retryable' => true];
        }
        if ($httpStatus >= 500) {
            return ['ok' => false, 'errorType' => 'provider_unavailable', 'errorMessage' => self::extractMessage($decoded, 'Provider server error'), 'httpStatus' => $httpStatus, 'retryable' => true];
        }
        if ($httpStatus >= 400) {
            return ['ok' => false, 'errorType' => 'rejected', 'errorMessage' => self::extractMessage($decoded, 'Provider rejected the request'), 'httpStatus' => $httpStatus, 'retryable' => true];
        }
        if ($httpStatus < 200 || $httpStatus >= 300) {
            return ['ok' => false, 'errorType' => 'invalid_response', 'errorMessage' => 'Unexpected HTTP status ' . $httpStatus, 'httpStatus' => $httpStatus, 'retryable' => true];
        }
        if ($decoded === null && $body !== '' && $body !== null) {
            return ['ok' => false, 'errorType' => 'invalid_response', 'errorMessage' => 'Provider returned non-JSON response', 'httpStatus' => $httpStatus, 'retryable' => true];
        }

        return ['ok' => true, 'httpStatus' => $httpStatus, 'data' => $decoded];
    }

    private static function extractMessage($decoded, string $fallback): string
    {
        if (is_array($decoded)) {
            foreach (['message', 'error', 'error_description', 'title'] as $field) {
                if (!empty($decoded[$field]) && is_string($decoded[$field])) {
                    return $decoded[$field];
                }
                if (!empty($decoded[$field]) && is_array($decoded[$field]) && !empty($decoded[$field]['message'])) {
                    return (string) $decoded[$field]['message'];
                }
            }
        }
        return $fallback;
    }
}
