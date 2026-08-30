<?php
/**
 * Anydrop — Email OTP driver: Sendix (https://docs.sendix.dev)
 */

require_once __DIR__ . '/EmailProviderInterface.php';
require_once __DIR__ . '/ProviderResult.php';
require_once __DIR__ . '/ProviderHttpClient.php';

class SendixProvider implements EmailProviderInterface
{
    public function send(string $to, string $subject, string $html, string $text, array $config): ProviderResult
    {
        $apiKey = $config['api_key'] ?? '';
        if ($apiKey === '') {
            return ProviderResult::fail('auth_failure', 'Sendix API key not configured', true);
        }
        $senderEmail = $config['sender_email'] ?? '';
        $senderName = $config['sender_name'] ?? 'AnyDrop';

        $result = ProviderHttpClient::postJson(
            'https://api.sendix.dev/v1/emails',
            ['Authorization' => 'Bearer ' . $apiKey],
            [
                'from' => "{$senderName} <{$senderEmail}>",
                'to' => [$to],
                'subject' => $subject,
                'html' => $html,
                'text' => $text,
            ]
        );

        if (!$result['ok']) {
            return ProviderResult::fail($result['errorType'], $result['errorMessage'], $result['retryable'], $result['httpStatus']);
        }

        $messageId = $result['data']['id'] ?? null;
        return ProviderResult::ok($messageId, $result['httpStatus']);
    }
}
