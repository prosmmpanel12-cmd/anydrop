<?php
/**
 * Anydrop — Email OTP driver: MailerSend (https://developers.mailersend.com/api/v1/email.html)
 */

require_once __DIR__ . '/EmailProviderInterface.php';
require_once __DIR__ . '/ProviderResult.php';
require_once __DIR__ . '/ProviderHttpClient.php';

class MailerSendProvider implements EmailProviderInterface
{
    public function send(string $to, string $subject, string $html, string $text, array $config): ProviderResult
    {
        $apiKey = $config['api_key'] ?? '';
        if ($apiKey === '') {
            return ProviderResult::fail('auth_failure', 'MailerSend API key not configured', true);
        }
        $senderEmail = $config['sender_email'] ?? '';
        $senderName = $config['sender_name'] ?? 'AnyDrop';

        $result = ProviderHttpClient::postJson(
            'https://api.mailersend.com/v1/email',
            ['Authorization' => 'Bearer ' . $apiKey],
            [
                'from' => ['email' => $senderEmail, 'name' => $senderName],
                'to' => [['email' => $to]],
                'subject' => $subject,
                'html' => $html,
                'text' => $text,
            ]
        );

        if (!$result['ok']) {
            return ProviderResult::fail($result['errorType'], $result['errorMessage'], $result['retryable'], $result['httpStatus']);
        }

        // MailerSend returns 202 with no body; the message id comes back
        // in the X-Message-Id header, which ProviderHttpClient doesn't
        // surface — logging null message_id here is acceptable, the
        // http_status(202) + status='sent' log row is what matters.
        return ProviderResult::ok(null, $result['httpStatus']);
    }
}
