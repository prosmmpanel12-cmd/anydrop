<?php
/**
 * Anydrop — Email OTP driver: Brevo (https://developers.brevo.com/reference/sendtransacemail)
 */

require_once __DIR__ . '/EmailProviderInterface.php';
require_once __DIR__ . '/ProviderResult.php';
require_once __DIR__ . '/ProviderHttpClient.php';

class BrevoProvider implements EmailProviderInterface
{
    public function send(string $to, string $subject, string $html, string $text, array $config): ProviderResult
    {
        $apiKey = $config['api_key'] ?? '';
        if ($apiKey === '') {
            return ProviderResult::fail('auth_failure', 'Brevo API key not configured', true);
        }
        $senderEmail = $config['sender_email'] ?? '';
        $senderName = $config['sender_name'] ?? 'AnyDrop';

        $result = ProviderHttpClient::postJson(
            'https://api.brevo.com/v3/smtp/email',
            ['api-key' => $apiKey],
            [
                'sender' => ['name' => $senderName, 'email' => $senderEmail],
                'to' => [['email' => $to]],
                'subject' => $subject,
                'htmlContent' => $html,
                'textContent' => $text,
            ]
        );

        if (!$result['ok']) {
            return ProviderResult::fail($result['errorType'], $result['errorMessage'], $result['retryable'], $result['httpStatus']);
        }

        $messageId = $result['data']['messageId'] ?? null;
        return ProviderResult::ok($messageId, $result['httpStatus']);
    }
}
