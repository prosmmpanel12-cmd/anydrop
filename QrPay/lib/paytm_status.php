<?php
/**
 * QrPay — Paytm status-check helpers (subset of the old encdec_paytm.php)
 *
 * Only the functions verify_payment.php actually needs are here:
 * checksum/crypto primitives + getTxnStatusNew()/callNewAPI().
 *
 * Two changes from the old file:
 *   1. SSL verification is forced ON (old file had VERIFYPEER/VERIFYHOST
 *      set to 0 in every call* — that's the exact thing the blueprint's
 *      ground rules explicitly rule out).
 *   2. PAYTM_MERCHANT_KEY is no longer a global constant — it's passed
 *      in per-call, since it now lives in `user_settings` per developer
 *      instead of one hardcoded value for the whole gateway.
 *
 * Refund functions (initiateTxnRefund, callRefundAPI, etc.) are
 * DELIBERATELY NOT ported here — the blueprint flags Paytm's refund API
 * as needing its own phase against the current JSON head/body/signature
 * format; the old functions target the legacy format and would be
 * actively wrong to carry forward as-is.
 */

function paytm_encrypt(string $input, string $key): string {
    $decodedKey = html_entity_decode($key);
    $iv = "@@@@&&&&####$$$$";
    return openssl_encrypt($input, "AES-128-CBC", $decodedKey, 0, $iv);
}

function paytm_decrypt(string $crypt, string $key): string {
    $decodedKey = html_entity_decode($key);
    $iv = "@@@@&&&&####$$$$";
    return openssl_decrypt($crypt, "AES-128-CBC", $decodedKey, 0, $iv);
}

function paytm_generate_salt(int $length): string {
    // random_bytes-backed, unlike the old srand()-based generator.
    $data = "AbcDE123IJKLMN67QRSTUVWXYZaBCdefghijklmn123opq45rs67tuv89wxyz0FGH45OP89";
    $random = "";
    for ($i = 0; $i < $length; $i++) {
        $random .= $data[random_int(0, strlen($data) - 1)];
    }
    return $random;
}

function paytm_check_string(string $value): string {
    return $value === 'null' ? '' : $value;
}

function paytm_array_to_str(array $arrayList): string {
    $paramStr = "";
    $flag = true;
    foreach ($arrayList as $value) {
        if (strpos((string) $value, 'REFUND') !== false || strpos((string) $value, '|') !== false) {
            continue;
        }
        $paramStr .= $flag ? paytm_check_string($value) : "|" . paytm_check_string($value);
        $flag = false;
    }
    return $paramStr;
}

/**
 * Calls Paytm's getTxnStatus endpoint for a given order.
 *
 * @param array  $requestParamList Must include MID, ORDERID (and any
 *                                 other fields Paytm's API expects).
 * @param string $merchantKey      This developer's Paytm merchant key,
 *                                 fetched from user_settings — never a
 *                                 hardcoded/global constant.
 */
function getTxnStatusNew(array $requestParamList, string $merchantKey): ?array {
    return paytm_call_status_api(
        "https://securegw.paytm.in/merchant-status/getTxnStatus",
        $requestParamList
    );
}

function paytm_call_status_api(string $apiURL, array $requestParamList, int $timeoutSec = 15): ?array {
    $jsonData = json_encode($requestParamList);
    $postData = 'JsonData=' . urlencode($jsonData);

    $ch = curl_init($apiURL);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => "POST",
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeoutSec,
        CURLOPT_SSL_VERIFYHOST => 2,     // was 0 in the old file — fixed
        CURLOPT_SSL_VERIFYPEER => true,  // was 0 in the old file — fixed
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($postData),
        ],
    ]);

    $jsonResponse = curl_exec($ch);
    if ($jsonResponse === false) {
        error_log('Paytm status API cURL error: ' . curl_error($ch));
        curl_close($ch);
        return null;
    }
    curl_close($ch);

    return json_decode($jsonResponse, true);
}
