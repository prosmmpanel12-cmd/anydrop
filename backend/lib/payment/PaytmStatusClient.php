<?php
/**
 * Anydrop — Paytm merchant-status API client (doc 23 addendum §A6).
 *
 * Wraps Paytm's real `getTxnStatus` endpoint — same call the UPIPE
 * reference source's `lib/encdec_paytm.php::getTxnStatusNew()` makes
 * (docs/payment_reference/upipe_source/upi/lib/encdec_paytm.php).
 *
 * WHAT THIS CAN AND CANNOT VERIFY:
 * Paytm only has a record of transactions it actually processed under
 * the given MID — a QR built from a plain `upi://pay?pa=...` intent
 * string (Anydrop's default, no-MID flow) is a peer-to-peer UPI
 * transfer Paytm never sees, so a status check against it will come
 * back not-found no matter how correct the MID/key are. This client
 * makes the real call and returns whatever Paytm actually says — it
 * never invents a success.
 */

class PaytmStatusClient
{
    private const LIVE_URL = 'https://securegw.paytm.in/merchant-status/getTxnStatus';
    private const STAGE_URL = 'https://securegw-stage.paytm.in/merchant-status/getTxnStatus';

    /**
     * @return array{status:string, raw:array} status is Paytm's own
     *         STATUS value verbatim (e.g. TXN_SUCCESS, TXN_FAILURE,
     *         PENDING) or 'ERROR' if the call itself failed
     *         (network/timeout/malformed response) — callers must
     *         treat 'ERROR' as "unknown, do not mark paid", not as a
     *         failure of the payment itself.
     */
    public static function checkStatus(string $mid, string $orderId, string $merchantKey, bool $testMode): array
    {
        $url = $testMode ? self::STAGE_URL : self::LIVE_URL;
        $payload = [
            'MID' => $mid,
            'ORDERID' => $orderId,
        ];
        // Sent verbatim if present, exactly like the reference source's
        // getTxnStatusNew() — but not required (see UpipeProvider::verify()'s
        // note: this call never computes a checksum with it, unlike the
        // refund endpoint).
        if ($merchantKey !== '') {
            $payload['KEY'] = $merchantKey;
        }

        $jsonData = json_encode($payload);
        $postData = 'JsonData=' . urlencode($jsonData);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($postData),
            ],
        ]);
        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false || $response === '') {
            return ['status' => 'ERROR', 'raw' => ['error' => $curlErr ?: 'empty_response']];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return ['status' => 'ERROR', 'raw' => ['error' => 'invalid_json', 'body' => $response]];
        }

        return ['status' => (string) ($decoded['STATUS'] ?? 'ERROR'), 'raw' => $decoded];
    }
}
