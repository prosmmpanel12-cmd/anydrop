<?php
/**
 * Anydrop — Email OTP: normalized provider result
 *
 * Every driver returns one of these instead of leaking its own raw API
 * response up to EmailOtpService — keeps the orchestrator provider-
 * agnostic (plan §8).
 */

class ProviderResult
{
    /**
     * @param bool        $success
     * @param bool        $retryable      Whether EmailOtpService should fail over to the
     *                                    next provider (true) or treat this as a final
     *                                    application-level failure (false) — plan §3's
     *                                    "Do NOT fail over for normal validation errors".
     * @param string|null $errorType      e.g. 'rate_limit','quota_exceeded','timeout',
     *                                    'connection_failure','http_5xx','auth_failure',
     *                                    'invalid_response','rejected','validation_error'
     * @param string|null $errorMessage   Human-readable detail, never containing the API key.
     * @param int|null    $httpStatus
     * @param string|null $providerMessageId
     */
    public function __construct(
        public bool $success,
        public bool $retryable = false,
        public ?string $errorType = null,
        public ?string $errorMessage = null,
        public ?int $httpStatus = null,
        public ?string $providerMessageId = null
    ) {
    }

    public static function ok(?string $messageId = null, ?int $httpStatus = 200): self
    {
        return new self(true, false, null, null, $httpStatus, $messageId);
    }

    public static function fail(string $errorType, string $errorMessage, bool $retryable, ?int $httpStatus = null): self
    {
        return new self(false, $retryable, $errorType, $errorMessage, $httpStatus, null);
    }
}
