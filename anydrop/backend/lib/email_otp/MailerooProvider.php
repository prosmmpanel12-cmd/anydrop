<?php
/**
 * Anydrop — Email OTP driver: Maileroo (https://maileroo.com/docs/email-api/send-basic-email/)
 */

require_once __DIR__ . '/EmailProviderInterface.php';
require_once __DIR__ . '/ProviderResult.php';
require_once __DIR__ . '/ProviderHttpClient.php';

class MailerooProvider implements EmailProviderInterface
{
    public function send(string $to, string $subject, string $html, string $text, array $config): ProviderResult
    {
        $apiKey = $config['api_key'] ?? '';
        if ($apiKey === '') {
            return ProviderResult::fail('auth_failure', 'Maileroo API key not configured', true);
        }
        $senderEmail = $config['sender_email'] ?? '';
        $senderName = $config['sender_name'] ?? 'AnyDrop';

        $result = ProviderHttpClient::postJson(
            'https://smtp.maileroo.com/api/v2/emails',
            ['Authorization' => 'Bearer ' . $apiKey],
            [
                'from' => ['address' => $senderEmail, 'display_name' => $senderName],
                'to' => [['address' => $to]],
                'subject' => $subject,
                'html' => $html,
                'plain' => $text,
            ]
        );

        if (!$result['ok']) {
            return ProviderResult::fail($result['errorType'], $result['errorMessage'], $result['retryable'], $result['httpStatus']);
        }

        $messageId = $result['data']['reference_id'] ?? ($result['data']['data']['reference_id'] ?? null);
        return ProviderResult::ok($messageId, $result['httpStatus']);
    }
}
