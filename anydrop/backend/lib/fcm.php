<?php
/**
 * Anydrop — Firebase Cloud Messaging (FCM) sender, HTTP v1 API.
 *
 * Person-requested this session: real push notifications across all 3
 * apps (Customer/Restaurant/Rider — Rider App itself is still Phase
 * 4/unbuilt, but `riders.fcm_token` already existed in the schema
 * ahead of it), plus admin-broadcast support (image/link/area-wise —
 * built in a later file, this one is just the low-level sender both
 * paths call).
 *
 * WHY HAND-ROLLED, NOT THE OFFICIAL kreait/firebase-php OR
 * google/apiclient LIBRARY: this codebase has no Composer/vendor
 * directory anywhere (checked — every other integration in
 * backend/lib/payment/ hand-rolls its HTTP calls with curl too, see
 * PaytmStatusClient.php for the exact same convention this file
 * follows) and no network access exists in the dev sandbox to `composer
 * require` one in anyway. FCM's HTTP v1 API needs nothing beyond a
 * signed JWT + one token-exchange call + one send call — all
 * doable with curl + openssl_sign(), both already relied on elsewhere
 * in this codebase (PaytmStatusClient's curl, auth.php's own crypto).
 *
 * SERVICE ACCOUNT SOURCE: the admin panel's FCM settings screen
 * (admin/fcm-settings.php) lets the app owner paste the full Firebase
 * service-account JSON straight into a form, stored as
 * app_settings.fcm_service_account_json — no physical file needs to
 * exist on the server for this to work, which matters on hosts where
 * the admin can't drop an arbitrary file next to the code (shared
 * hosting, a phone-based PHP server, etc.). fcm_get_service_account()
 * below reads that DB value first; the old file path,
 * backend/config/firebase-service-account.json (gitignored — see repo
 * root .gitignore's own comment for why, and the rotation procedure if
 * it's ever leaked), is still checked as a fallback for anyone who
 * already has it deployed that way, but it is no longer required.
 *
 * WHAT THIS FILE DOES NOT DO: decide who to notify, or write the bell
 * row — that's still lib/notifications.php's create_notification(),
 * which now calls fcm_send_to_token() as its last step (see that
 * file's own updated kdoc). This file's only job is "given one token
 * and a payload, deliver it via FCM" — same single-responsibility
 * split PaymentProviderInterface keeps between "decide what to charge"
 * and "talk to the gateway."
 */

require_once __DIR__ . '/settings.php';

/**
 * Loads the Firebase service-account credentials, DB setting first
 * (pasted via admin/fcm-settings.php), falling back to the on-disk
 * file for backward compatibility. Returns null — and records why, via
 * fcm_record_status() — if neither source has a usable value, so both
 * fcm_get_access_token() and fcm_send_to_token() can share one place
 * that decides "do we even have credentials" instead of duplicating
 * the DB-then-file-then-validate logic in each.
 */
function fcm_get_service_account(): ?array
{
    $json = get_setting('fcm_service_account_json', '');
    if (is_string($json) && trim($json) !== '') {
        $decoded = json_decode($json, true);
        if (is_array($decoded) && !empty($decoded['private_key']) && !empty($decoded['client_email'])) {
            return $decoded;
        }
        fcm_record_status(false, 'Saved FCM service-account JSON (Settings → FCM Settings) is malformed — missing private_key or client_email.');
        return null;
    }

    $serviceAccountPath = __DIR__ . '/../config/firebase-service-account.json';
    if (file_exists($serviceAccountPath)) {
        $decoded = json_decode((string) file_get_contents($serviceAccountPath), true);
        if (is_array($decoded) && !empty($decoded['private_key']) && !empty($decoded['client_email'])) {
            return $decoded;
        }
        fcm_record_status(false, 'firebase-service-account.json on disk is malformed — missing private_key or client_email.');
        return null;
    }

    fcm_record_status(false, 'No FCM service account configured yet — paste it in Settings → FCM Settings.');
    return null;
}

/**
 * Best-effort status recorder so the admin's FCM settings page can
 * show "last send: ok / failed, reason, when" instead of the admin
 * only finding out push is broken from a missing notification days
 * later. Never throws — same push-is-best-effort spirit as everything
 * else in this file.
 */
function fcm_record_status(bool $ok, string $message): void
{
    try {
        set_setting('fcm_last_status', $ok ? 'ok' : 'error');
        set_setting('fcm_last_message', $message);
        set_setting('fcm_last_checked_at', date('Y-m-d H:i:s'));
    } catch (Throwable $e) {
        error_log('fcm_record_status: failed to write status (non-fatal): ' . $e->getMessage());
    }
}

/**
 * Get a valid OAuth2 access token for the FCM v1 API, minting a new one
 * only when the cached one is missing/expired. Google's access tokens
 * last 1 hour; cached in app_settings as fcm_access_token +
 * fcm_access_token_expires_at so most calls in a busy period skip the
 * JWT-mint-and-exchange round trip entirely. Falls back to always
 * minting fresh if the cache write itself fails — a slower path, never
 * a broken one.
 *
 * @return string|null null on any failure (missing service-account
 *         file, malformed key, network error) — callers must treat
 *         null as "could not send," never throw a fatal, per this
 *         whole feature's push-is-best-effort spirit (same as
 *         create_notification()'s own bell-row write).
 */
function fcm_get_access_token(): ?string
{
    $db = Database::get();

    $cached = $db->prepare(
        "SELECT `value` FROM app_settings WHERE `key` = 'fcm_access_token' LIMIT 1"
    );
    $cached->execute();
    $cachedToken = $cached->fetch()['value'] ?? null;

    $expiryRow = $db->prepare(
        "SELECT `value` FROM app_settings WHERE `key` = 'fcm_access_token_expires_at' LIMIT 1"
    );
    $expiryRow->execute();
    $expiresAt = (int) ($expiryRow->fetch()['value'] ?? 0);

    // 60s safety margin so a token that's about to expire mid-request
    // never gets used right up to the wire.
    if ($cachedToken && $expiresAt > (time() + 60)) {
        return $cachedToken;
    }

    $serviceAccount = fcm_get_service_account();
    if ($serviceAccount === null) {
        // fcm_get_service_account() already recorded the specific
        // reason (missing/malformed) — nothing more to log here.
        return null;
    }

    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claims = [
        'iss' => $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ];

    $base64url = static fn(string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    $segments = $base64url(json_encode($header)) . '.' . $base64url(json_encode($claims));

    $signature = '';
    $signed = openssl_sign($segments, $signature, $serviceAccount['private_key'], OPENSSL_ALGO_SHA256);
    if (!$signed) {
        error_log('fcm_get_access_token: openssl_sign failed — check private_key format in service account file');
        fcm_record_status(false, 'Could not sign the FCM auth request — the private_key in the service account JSON looks malformed.');
        return null;
    }

    $jwt = $segments . '.' . $base64url($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false || $response === '') {
        error_log('fcm_get_access_token: token exchange curl failed: ' . $curlErr);
        fcm_record_status(false, 'Could not reach Google to exchange the FCM auth token: ' . $curlErr);
        return null;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || empty($decoded['access_token'])) {
        error_log('fcm_get_access_token: token exchange returned no access_token: ' . $response);
        fcm_record_status(false, 'Google rejected the FCM service-account credentials: ' . $response);
        return null;
    }

    $accessToken = $decoded['access_token'];
    $expiresIn = (int) ($decoded['expires_in'] ?? 3600);

    // Best-effort cache write via upsert — a failure here just means
    // the next call re-mints, never a hard error.
    try {
        $upsert = $db->prepare(
            "INSERT INTO app_settings (`key`, `value`) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE `value` = :v2"
        );
        $upsert->execute(['k' => 'fcm_access_token', 'v' => $accessToken, 'v2' => $accessToken]);
        $expiryUpsert = $db->prepare(
            "INSERT INTO app_settings (`key`, `value`) VALUES ('fcm_access_token_expires_at', :v)
             ON DUPLICATE KEY UPDATE `value` = :v2"
        );
        $expiryVal = (string) ($now + $expiresIn);
        $expiryUpsert->execute(['v' => $expiryVal, 'v2' => $expiryVal]);
    } catch (Throwable $e) {
        error_log('fcm_get_access_token: cache write failed (non-fatal): ' . $e->getMessage());
    }

    return $accessToken;
}

/**
 * Send one push to one FCM registration token.
 *
 * @param string $token     The recipient's fcm_token (customers/
 *                           restaurants/riders table).
 * @param string $title     Notification title.
 * @param string $body      Notification body.
 * @param array  $data      Arbitrary string=>string deep-link payload
 *                           — FCM's "data" payload requires every
 *                           value be a string, unlike
 *                           notifications.data_json which allows any
 *                           JSON type; callers must pre-stringify
 *                           (e.g. (string) $orderId, not $orderId).
 * @param string|null $imageUrl Optional image, passed through as the
 *                           data payload's `image_url` key (not FCM's
 *                           own notification.image field — this
 *                           function sends data-only messages, see the
 *                           body's own comment for why). The client's
 *                           own notification code is responsible for
 *                           actually rendering it (CustomerFirebase
 *                           MessagingService.onMessageReceived() already
 *                           reads `data["image_url"]` into
 *                           NotificationHelper.showOfferNotification()'s
 *                           BigPictureStyle).
 *
 * Never throws outward — same "a notification is a nice-to-have"
 * philosophy create_notification() already documents. Logs failures,
 * returns false, lets the caller's real action (order accept, admin
 * broadcast send, etc) proceed regardless.
 */
function fcm_send_to_token(
    string $token,
    string $title,
    string $body,
    array $data = [],
    ?string $imageUrl = null
): bool {
    $serviceAccount = fcm_get_service_account();
    if ($serviceAccount === null) {
        // Same silent-skip as a missing/null token — fcm_get_service_account()
        // already recorded the specific reason for the settings page.
        return false;
    }

    $projectId = $serviceAccount['project_id'] ?? null;
    if (!$projectId) {
        error_log('fcm_send_to_token: service account missing project_id');
        fcm_record_status(false, 'The saved FCM service-account JSON has no project_id.');
        return false;
    }

    $accessToken = fcm_get_access_token();
    if (!$accessToken) {
        return false;
    }

    // Every FCM "data" value must be a string — stringify defensively
    // here rather than trusting every call site to remember, same
    // "don't let a caller mistake break delivery" spirit as
    // create_notification()'s own json_encode() of an arbitrary array.
    $stringData = array_map(static fn($v): string => is_string($v) ? $v : (string) $v, $data);

    // Deliberately data-only — NO top-level 'notification' block (and
    // no 'android.notification' either). A message that carries a
    // 'notification' key makes the OS's own FCM SDK auto-display a
    // generic system tray notification AND skip
    // FirebaseMessagingService.onMessageReceived() entirely whenever
    // the app is backgrounded or killed — the SDK treats that case as
    // "already handled." That silently broke the one thing this whole
    // feature exists for: RestaurantFirebaseMessagingService's
    // onMessageReceived() routes an order push into
    // OrderNotificationHelper.showNewOrderAlert() (the loud
    // full-screen ringing alarm), and CustomerFirebaseMessagingService
    // routes into its own rich order/offer notifications — neither of
    // which ever ran except while the app happened to be in the
    // foreground. Folding title/body/image into 'data' instead (both
    // client services already read title/body/image_url from `data`
    // as their fallback — see each service's own onMessageReceived())
    // guarantees onMessageReceived() fires in every app state, so the
    // client's own notification logic is always the thing that runs.
    $stringData['title'] = $title;
    $stringData['body'] = $body;
    if ($imageUrl !== null && $imageUrl !== '') {
        $stringData['image_url'] = $imageUrl;
    }

    $message = [
        'message' => [
            'token' => $token,
            'data' => $stringData,
        ],
    ];

    $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($message),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('fcm_send_to_token: curl failed: ' . $curlErr);
        fcm_record_status(false, 'Could not reach FCM to send the push: ' . $curlErr);
        return false;
    }
    if ($httpCode !== 200) {
        // 404/UNREGISTERED here means the token is stale (app
        // uninstalled, token rotated) — logged but NOT auto-cleared
        // from the DB in this pass; a future session could add that
        // cleanup (delete the stale token on this specific error) if
        // it turns out to matter at real volume. Deliberately not
        // guessed at here since which table/id owns this token isn't
        // known at this layer — this function only ever sees a bare
        // token string, by design (single-responsibility, see file
        // header).
        error_log("fcm_send_to_token: FCM returned HTTP $httpCode: $response");
        fcm_record_status(false, "FCM returned HTTP $httpCode: $response");
        return false;
    }

    fcm_record_status(true, 'Last push sent successfully.');
    return true;
}
