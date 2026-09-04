<?php
/**
 * Anydrop — Optional capability interface for providers that need a
 * human-in-the-loop fallback (doc 23 §5: "no live gateway = no
 * webhook, so verification is manual-admin at launch").
 *
 * NOT part of PaymentProviderInterface on purpose — a future real
 * gateway (Razorpay, Cashfree, ...) verifies itself via a live API
 * call/webhook and has no use for "customer types in a UTR, admin
 * approves it," so this stays a separate, optional interface that
 * only manual-style providers implement. PaymentService checks
 * `instanceof` before calling any of this — see PaymentService.php.
 */

interface ManualVerificationProviderInterface
{
    /**
     * Records a customer-submitted UTR against an in-progress
     * transaction. Does NOT mark the payment successful by itself —
     * only an admin decision (adminDecision()) can do that. Returns
     * an error status (e.g. 'utr_window_not_open', 'invalid_utr',
     * 'utr_already_used') if the submission is rejected outright.
     *
     * @return array{status: string, raw_response: array}
     */
    public function submitUtr(array $transaction, string $utr, array $config): array;

    /**
     * Applies a human admin's approve/reject decision to a
     * transaction that's pending manual review. This is the ONLY
     * place a transaction may transition to 'success' in the
     * manual-verification model — never a client-facing endpoint,
     * per PaymentProviderInterface's own "never trust a client-side
     * claim" rule.
     *
     * $amountConfirmed / $expectedAmount (2026-08-23 anti-fraud
     * hardening) — an 'approve' decision MUST be refused unless these
     * match (within a 1-paisa rounding tolerance); see
     * UpipeProvider::adminDecision()'s doc-comment for why. Both are
     * null-able only so a 'reject' decision (which never needs them)
     * doesn't force a caller to pass meaningless values.
     *
     * @return array{status: string, raw_response: array}
     */
    public function adminDecision(array $transaction, string $decision, ?string $reason, int $adminId, array $config, ?float $amountConfirmed = null, ?float $expectedAmount = null): array;
}
