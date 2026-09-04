<?php
/**
 * Anydrop — Email OTP: Secret Manager
 *
 * Encrypts/decrypts provider API keys before they touch
 * email_otp_providers.config_json, so a DB dump or a stray backup file
 * doesn't hand over live provider credentials in plaintext (docs plan
 * §6 — "Never ... store keys in Git" / "Recommended: Store encrypted
 * provider configuration").
 *
 * Uses AES-256-GCM with a key derived from APP_SECRET (config/config.php).
 * IMPORTANT: if APP_SECRET is ever changed, every already-saved provider
 * key becomes undecryptable — re-enter all provider API keys from the
 * Admin Panel after rotating APP_SECRET.
 *
 * Encrypted values are stored as base64("v1:" . iv . tag . ciphertext)
 * so decrypt_secret() can tell an already-encrypted value apart from a
 * plain empty string / legacy plaintext without a separate DB column.
 */

class SecretManager
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'v1:';

    private static function key(): string
    {
        // 32-byte key regardless of APP_SECRET's own length.
        return hash('sha256', APP_SECRET, true);
    }

    /** Encrypts a plaintext secret. Empty string stays empty (nothing to encrypt). */
    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('secret_encryption_failed');
        }
        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /** Decrypts a value previously produced by encrypt(). Returns '' for empty/undecryptable input. */
    public static function decrypt(string $stored): string
    {
        if ($stored === '' || strpos($stored, self::PREFIX) !== 0) {
            // Not our format — either empty or (shouldn't happen going
            // forward, but be safe) legacy plaintext. Treat as plaintext
            // rather than throwing, so a bad/old row doesn't 500 the
            // whole OTP-send path.
            return $stored;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 12 + 16) {
            return '';
        }
        $ivLen = openssl_cipher_iv_length(self::CIPHER);
        $iv = substr($raw, 0, $ivLen);
        $tag = substr($raw, $ivLen, 16);
        $ciphertext = substr($raw, $ivLen + 16);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plaintext === false ? '' : $plaintext;
    }

    /** For Admin Panel display only — never show a saved key in full. */
    public static function mask(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        $len = strlen($plaintext);
        if ($len <= 4) {
            return str_repeat('•', $len);
        }
        return str_repeat('•', min(20, $len - 4)) . substr($plaintext, -4);
    }
}
