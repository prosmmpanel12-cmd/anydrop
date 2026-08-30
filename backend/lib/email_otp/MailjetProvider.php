<?php
/**
 * Anydrop — Email OTP driver: Mailjet (https://dev.mailjet.com/email/reference/send-emails/)
 *
 * Per the plan, Mailjet is OPTIONAL / NOT CONFIRMED and stays disabled
 * (is_active = 0, priority 6/last) until real end-to-end delivery has
 * been verified from the Admin Panel's Test button.
 *
 * Mailjet uses HTTP Basic auth (API key as username, API secret as
 * password) rather than a Bearer token — the other five drivers all
 * use Bearer, this one is the exception.
 */

require_once __DIR__ . '/EmailProviderInterface.php';
require_once __DIR__ . '/ProviderResult.php';
require_once __DIR__ . '/ProviderHttpClient.php';

class MailjetProvider implements EmailProviderInterface
{
    public function send(string $to, string $subject, string $html, string $text, array $config): ProviderResult
    {
        $apiKey = $config['api_key'] ?? '';
        $apiSecret = $config['api_secret'] ?? '';
        if ($apiKey === '' || $apiSecret === '') {
            return ProviderResult::fail('auth_failure', 'Mailjet API key/secret not configured', true);
        }
        $senderEmail = $config['sender_email'] ?? '';
        $senderName = $config['sender_name'] ?? 'AnyDrop';

        $result = ProviderHttpClient::postJson(
            'https://api.mailjet.com/v3.1/send',
            ['Authorization' => 'Basic ' . base64_encode($apiKey . ':' . $apiSecret)],
            [
                'Messages' => [[
                    'From' => ['Email' => $senderEmail, 'Name' => $senderName],
                    'To' => [['Email' => $to]],
                    'Subject' => $subject,
                    'HTMLPart' => $html,
                    'TextPart' => $text,
                ]],
            ]
        );

        if (!$result['ok']) {
            return ProviderResult::fail($result['errorType'], $result['errorMessage'], $result['retryable'], $result['httpStatus']);
        }

        $messageId = $result['data']['Messages'][0]['To'][0]['MessageID'] ?? null;
        return ProviderResult::ok($messageId !== null ? (string) $messageId : null, $result['httpStatus']);
    }
}
