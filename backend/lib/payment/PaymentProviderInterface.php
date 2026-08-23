<?php
/**
 * Anydrop — Payment Provider Interface (recall.md Phase C item 24,
 * doc 19 §8).
 *
 * Every gateway (UpipeProvider today; Razorpay/Cashfree/PhonePe later)
 * implements this and nothing else in the codebase ever calls a
 * provider class directly — always through PaymentService.php, so
 * adding or swapping a gateway never touches order-processing code.
 */

interface PaymentProviderInterface
{
    /**
     * Starts a payment for one order. Returns whatever the customer
     * app needs to actually pay (e.g. a UPI deep link / QR payload —
     * shape is provider-specific, hence the free-form array) plus a
     * provider-side reference if the provider issues one up front.
     *
     * @param array $config Decoded payment_providers.config_json for this provider.
     * @return array{provider_txn_id: ?string, status: string, raw_response: array, client_payload: array}
     */
    public function initiate(float $amount, int $orderId, string $orderCode, array $config): array;

    /**
     * Checks a previously-initiated payment's real status with the
     * provider. MUST reflect the provider's own source of truth, never
     * a client-supplied claim — see doc 19 §28: "Do not mark a payment
     * successful solely from a client-side success callback."
     *
     * @return array{status: string, raw_response: array}
     */
    public function verify(string $providerTxnId, array $config): array;

    /**
     * Issues a refund for a previously-successful payment.
     *
     * @return array{status: string, raw_response: array}
     */
    public function refund(string $providerTxnId, float $amount, array $config): array;
}
