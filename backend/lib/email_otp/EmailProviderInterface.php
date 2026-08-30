<?php
/**
 * Anydrop — Email OTP: provider driver contract
 *
 * Adding a 6th/7th provider later = one new class implementing this +
 * one email_otp_providers row (plan §22/§24 Phase 3) — no change to
 * EmailOtpService, ProviderRegistry, or the OTP endpoints.
 */

interface EmailProviderInterface
{
    /**
     * $config is the provider's own decrypted config array (already
     * decrypted by ProviderRegistry — drivers never touch SecretManager
     * directly). Shape varies per driver but always includes at least
     * 'api_key', 'sender_email', 'sender_name'.
     */
    public function send(string $to, string $subject, string $html, string $text, array $config): ProviderResult;
}
