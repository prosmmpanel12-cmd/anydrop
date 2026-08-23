<?php
/**
 * ╔═══════════════════════════════════════════════════════════════╗
 * ║              UpiPe SDK — PHP Client v2.2                     ║
 * ║              by YourApis (yourapi.42web.io)                  ║
 * ╚═══════════════════════════════════════════════════════════════╝
 *
 * 5 minute mein UPI payment apni PHP site pe add karo.
 *
 * VERIFICATION FLOW:
 * ──────────────────
 *   Auto verify (Paytm)  → hamesha chalta hai, koi limit nahi
 *   Manual verify (UTR)  → 5 min baad user ka option (parallel)
 *
 * QUICK START:
 * ────────────
 *   require 'UpiPeSDK/UpiPe.php';
 *   $upi    = new UpiPe('YOUR_API_KEY');
 *   $order  = $upi->createOrder(499, 'user_123', 'My Store Order');
 *   $verify = $upi->verifyPayment($order['order_id']);
 *
 * API BASE: https://yourapi.42web.io/api/upi/api/
 *
 * @author  YourApis
 * @version 2.2
 * @license MIT
 */

class UpiPe
{
    const DEFAULT_BASE = 'https://yourapi.42web.io/api/upi/api';
    const VERSION      = '2.2';

    private string $apiKey;
    private string $baseUrl;
    private int    $timeout;
    private bool   $sslVerify;

    /**
     * @param string $apiKey   YourApis dashboard se mila API key
     * @param string $baseUrl  API server URL (default: yourapi.42web.io)
     * @param int    $timeout  cURL timeout in seconds (default: 20)
     */
    public function __construct(
        string $apiKey,
        string $baseUrl    = self::DEFAULT_BASE,
        int    $timeout    = 20,
        bool   $sslVerify  = true
    ) {
        if (empty($apiKey)) {
            throw new \InvalidArgumentException('UpiPe: API key required. YourApis dashboard se lo.');
        }
        $this->apiKey     = $apiKey;
        $this->baseUrl    = rtrim($baseUrl, '/');
        $this->timeout    = $timeout;
        $this->sslVerify  = $sslVerify;
    }

    // ──────────────────────────────────────────────────────────────────
    // 1. ORDER CREATE
    // ──────────────────────────────────────────────────────────────────

    /**
     * Naya UPI payment order create karo.
     *
     * @param float  $amount      Amount in ₹ (minimum ₹1, maximum ₹1,00,000)
     * @param string $customerId  Tumhara user/order ID (any string)
     * @param string $note        Payment note shown to customer
     * @param string $mode        'auto' (Paytm verify) | 'manual' (UTR only)
     *
     * @return array {
     *   status:          'success',
     *   order_id:        'QRP...',         ← save this!
     *   amount:          499,
     *   qr_url:          'https://...',    ← <img src> mein lagao
     *   upi_link:        'upi://pay?...',  ← mobile pay button
     *   deep_links:      { gpay, phonepe, paytm },
     *   mode:            'auto' | 'manual',
     *   expires_in_sec:  1800,
     *   expires_at:      '2024-01-01 12:30:00'
     * }
     */
    public function createOrder(
        float  $amount,
        string $customerId,
        string $note = 'Payment',
        string $mode = 'auto'
    ): array {
        if ($amount < 1) {
            return ['status' => 'error', 'message' => 'Minimum amount is ₹1.'];
        }
        if ($amount > 100000) {
            return ['status' => 'error', 'message' => 'Maximum amount is ₹1,00,000.'];
        }
        if (!in_array($mode, ['auto', 'manual'])) {
            $mode = 'auto';
        }
        return $this->request('GET', '/create_order.php', [
            'apikey'      => $this->apiKey,
            'amount'      => $amount,
            'customer_id' => $customerId,
            'note'        => $note,
            'mode'        => $mode,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // 2. PAYMENT VERIFY
    // ──────────────────────────────────────────────────────────────────

    /**
     * Payment verify karo.
     *
     * AUTO VERIFY (no UTR param):
     *   Paytm se auto check hoga. Koi attempt limit nahi.
     *   Agar 5 min baad bhi payment detect na ho, response mein
     *   manual_utr_available=true aayega — tab UTR le lo customer se.
     *
     * MANUAL VERIFY (UTR param diya):
     *   Sirf 5 min baad accept hoga.
     *   UTR submit hote hi status MANUAL_PENDING ho jaata hai.
     *   Merchant panel se approve/reject karo.
     *
     * STATUS VALUES:
     * ┌─────────────────────┬───────────────────────────────────────────┐
     * │ Status              │ Matlab                                    │
     * ├─────────────────────┼───────────────────────────────────────────┤
     * │ paid                │ ✅ Payment verified — action lo           │
     * │ already_paid        │ ✅ Pehle hi verify ho chuka               │
     * │ not_paid            │ ⏳ Abhi detect nahi hui — retry karo      │
     * │ manual_pending      │ 🕐 UTR submit, merchant approve karega    │
     * │ manual_not_allowed  │ ⏳ 5 min nahi hue — UTR submit ruko      │
     * │ utr_required        │ 📝 Manual-only order — UTR do             │
     * │ pending             │ ⏳ Manual-only order, 5 min wait karo     │
     * │ expired             │ ❌ 30 min mein pay nahi hua               │
     * │ rejected            │ ❌ Merchant ne reject kiya                │
     * └─────────────────────┴───────────────────────────────────────────┘
     *
     * EXTRA FIELDS (not_paid response mein):
     *   manual_utr_available  → true/false  (5 min ho gaye?)
     *   manual_allowed_in     → seconds remaining (agar false hai)
     *   auto_attempts         → kitni baar auto check hua
     *
     * @param string $orderId  createOrder se mila order_id
     * @param string $utr      Customer ka 12-digit bank UTR (sirf 5 min baad)
     */
    public function verifyPayment(string $orderId, string $utr = ''): array
    {
        $params = [
            'apikey'   => $this->apiKey,
            'order_id' => $orderId,
        ];
        if (!empty($utr)) {
            if (!preg_match('/^\d{12}$/', $utr)) {
                return ['status' => 'error', 'message' => 'UTR must be exactly 12 digits.'];
            }
            $params['utr'] = $utr;
        }
        return $this->request('POST', '/verify_payment.php', $params);
    }

    // ──────────────────────────────────────────────────────────────────
    // 3. ORDER STATUS
    // ──────────────────────────────────────────────────────────────────

    /**
     * Order ka current DB status fetch karo (verify nahi karta).
     * Merchant dashboard ya webhook fallback ke liye useful.
     *
     * @return array { order: { order_id, status, amount, utr, ... } }
     */
    public function getOrderStatus(string $orderId): array
    {
        return $this->request('GET', '/order_status.php', [
            'apikey'   => $this->apiKey,
            'order_id' => $orderId,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // 4. MANUAL ORDERS LIST
    // ──────────────────────────────────────────────────────────────────

    /**
     * Manual pending orders list karo — merchant dashboard ke liye.
     *
     * @param string $status  MANUAL_PENDING | PAID | REJECTED | PENDING | EXPIRED | ALL
     *
     * @return array { count: N, orders: [ ... ] }
     */
    public function getManualOrders(string $status = 'MANUAL_PENDING'): array
    {
        return $this->request('GET', '/manual_orders.php', [
            'apikey' => $this->apiKey,
            'status' => strtoupper($status),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // 5. MANUAL ORDER APPROVE / REJECT
    // ──────────────────────────────────────────────────────────────────

    /**
     * MANUAL_PENDING order approve ya reject karo.
     *
     * @param string $orderId       Order ID
     * @param string $action        'approve' | 'reject'
     * @param string $rejectReason  (optional, sirf reject ke liye)
     *
     * @return array { status: 'success', action: 'approved'|'rejected', ... }
     */
    public function manualAction(
        string $orderId,
        string $action,
        string $rejectReason = ''
    ): array {
        if (!in_array($action, ['approve', 'reject'])) {
            return ['status' => 'error', 'message' => "Action must be 'approve' or 'reject'."];
        }
        $params = [
            'apikey'   => $this->apiKey,
            'order_id' => $orderId,
            'action'   => $action,
        ];
        if (!empty($rejectReason)) {
            $params['reject_reason'] = $rejectReason;
        }
        return $this->request('POST', '/manual_action.php', $params);
    }

    // ──────────────────────────────────────────────────────────────────
    // HELPERS
    // ──────────────────────────────────────────────────────────────────

    /**
     * Simple boolean — payment successful hai ya nahi.
     * Polling loop mein use karo.
     *
     * @example
     *   while (!$upi->isPaid($orderId)) { sleep(5); }
     */
    public function isPaid(string $orderId): bool
    {
        $res = $this->verifyPayment($orderId);
        return in_array($res['status'] ?? '', ['paid', 'already_paid'], true);
    }

    /**
     * Blocking wait — background scripts / CLI ke liye.
     * Automatically handles both auto and manual flow:
     *   - Auto verify hamesha retry karta hai
     *   - 5 min baad manual_utr_available = true ho jaata hai
     *     (frontend pe UTR input dikhao, phir verifyPayment($id, $utr) call karo)
     *
     * @param string   $orderId
     * @param int      $intervalSec   Check interval in seconds (default: 5)
     * @param int      $maxWaitSec    Max wait time in seconds (default: 1800 = 30 min)
     * @param callable $utrCallback   Optional: called when manual UTR is available.
     *                                 Should return 12-digit UTR string or null.
     *                                 Signature: function(array $lastResponse): ?string
     *
     * @return array Final verify response
     */
    public function waitForPayment(
        string   $orderId,
        int      $intervalSec  = 5,
        int      $maxWaitSec   = 1800,
        callable $utrCallback  = null
    ): array {
        $deadline          = time() + $maxWaitSec;
        $utrSubmitted      = false;

        while (time() < $deadline) {
            $res    = $this->verifyPayment($orderId);
            $status = $res['status'] ?? 'error';

            // Terminal states — stop
            if (in_array($status, ['paid', 'already_paid', 'expired', 'rejected', 'error'], true)) {
                return $res;
            }

            // UTR submitted — keep polling for merchant approval
            if ($status === 'manual_pending') {
                sleep($intervalSec);
                continue;
            }

            // 5 min ho gaye + UTR nahi diya abhi tak — callback invoke karo
            if (!$utrSubmitted && ($res['manual_utr_available'] ?? false) && $utrCallback !== null) {
                $utr = call_user_func($utrCallback, $res);
                if (!empty($utr)) {
                    $utrRes = $this->verifyPayment($orderId, $utr);
                    if (in_array($utrRes['status'] ?? '', ['manual_pending', 'paid', 'already_paid'], true)) {
                        $utrSubmitted = true;
                        if (in_array($utrRes['status'], ['paid', 'already_paid'], true)) {
                            return $utrRes;
                        }
                    }
                }
            }

            sleep($intervalSec);
        }

        return ['status' => 'expired', 'message' => 'Max wait time exceeded.'];
    }

    /**
     * Check if order is in a terminal (final) state.
     * Terminal = paid / already_paid / expired / rejected
     */
    public function isTerminal(string $orderId): bool
    {
        $res = $this->getOrderStatus($orderId);
        $status = $res['order']['status'] ?? $res['status'] ?? '';
        return in_array($status, ['PAID', 'EXPIRED', 'REJECTED', 'paid', 'already_paid', 'expired', 'rejected'], true);
    }

    // ──────────────────────────────────────────────────────────────────
    // HTTP TRANSPORT (Internal)
    // ──────────────────────────────────────────────────────────────────

    private function request(string $method, string $path, array $params = []): array
    {
        $url = $this->baseUrl . $path;
        $ch  = curl_init();

        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->sslVerify,
            CURLOPT_SSL_VERIFYHOST => $this->sslVerify ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: UpiPe-SDK-PHP/' . self::VERSION,
            ],
        ];

        if ($method === 'POST') {
            $curlOpts[CURLOPT_URL]        = $url;
            $curlOpts[CURLOPT_POST]       = true;
            $curlOpts[CURLOPT_POSTFIELDS] = http_build_query($params);
        } else {
            $curlOpts[CURLOPT_URL] = $url . '?' . http_build_query($params);
        }

        curl_setopt_array($ch, $curlOpts);
        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr) {
            return ['status' => 'error', 'message' => 'Network error: ' . $curlErr, 'code' => 0];
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'status'  => 'error',
                'message' => 'Invalid JSON from server (HTTP ' . $httpCode . ')',
                'raw'     => substr($response, 0, 200),
            ];
        }

        return $data;
    }
}
