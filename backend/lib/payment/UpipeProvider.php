<?php
/**
 * Anydrop — UPIPE Stub Provider (recall.md Phase C item 24, doc 19
 * §8).
 *
 * Deliberately a stub — no real UPIPE SDK/API credentials exist yet
 * (the app owner needs to provide those, same standing gap flagged
 * throughout recall.md wherever UPIPE comes up). Implements the full
 * interface so `price_cart()`/checkout code can be written and tested
 * against it right now; swapping in the real integration later means
 * rewriting only this one file's method bodies, never any caller.
 *
 * Honesty rule this file follows strictly: it never fabricates a
 * 'success' status. `initiate()` always comes back 'initiated' (a
 * real payment attempt genuinely was started, in the sense that an
 * order+transaction row now exists) and `verify()`/`refund()` always
 * come back 'failed' with a clear reason — NOT 'pending' — because
 * there's no real payment happening at all yet, and returning
 * 'pending' would let a caller sit there polling something that will
 * never resolve. Once real UPIPE credentials exist, replace these
 * three method bodies with real SDK/API calls; the return shapes are
 * already what PaymentService.php expects.
 */

require_once __DIR__ . '/PaymentProviderInterface.php';

class UpipeProvider implements PaymentProviderInterface
{
    public function initiate(float $amount, int $orderId, string $orderCode, array $config): array
    {
        return [
            'provider_txn_id' => null, // UPIPE hasn't actually been called — nothing to reference yet
            'status' => 'initiated',
            'raw_response' => [
                'stub' => true,
                'note' => 'UPIPE real integration pending — no SDK/API credentials configured yet.',
            ],
            'client_payload' => [
                'method' => 'unavailable',
                'message' => 'Online payment is not yet available — please choose Cash on Delivery.',
            ],
        ];
    }

    public function verify(string $providerTxnId, array $config): array
    {
        return [
            'status' => 'failed',
            'raw_response' => [
                'stub' => true,
                'reason' => 'no_real_provider_configured',
                'note' => 'UPIPE real integration pending — cannot verify a payment that was never actually sent to a gateway.',
            ],
        ];
    }

    public function refund(string $providerTxnId, float $amount, array $config): array
    {
        return [
            'status' => 'failed',
            'raw_response' => [
                'stub' => true,
                'reason' => 'no_real_provider_configured',
                'note' => 'UPIPE real integration pending — refunds must be handled manually (bank transfer) until then.',
            ],
        ];
    }
}
