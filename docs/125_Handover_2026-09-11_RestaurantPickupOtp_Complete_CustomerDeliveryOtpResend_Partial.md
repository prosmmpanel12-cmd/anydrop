# Handover — 2026-09-11 (Part 4): Restaurant Pickup-OTP Display DONE, Customer Delivery-OTP Resend PARTIAL

Direct continuation of
`124_Handover_2026-09-11_RiderPickupOtpDialog_HomeFragment_Complete.md`
(rider side is fully done as of that doc). This doc covers item 2
(now complete) and item 3 (started, not finished) from doc 123/124's
remaining list.

**Zipped mid-session at the person's request** ("jaha tak kaam hua
waha tak complete zip") — item 3 below is genuinely unfinished, not
just unverified. Read "What's NOT done yet" carefully before assuming
the customer app builds/works.

## What's DONE this part

### Restaurant app — Pickup OTP display + Resend (complete)

- **`network/Models.kt`** — `Order` gained `pickupOtp: String?` /
  `pickupOtpVerified: Boolean` (`pickup_otp`/`pickup_otp_verified`),
  matching `orders-detail.php`'s reveal condition (null unless
  `status == rider_assigned`, per doc 122). New `ResendOtpResult(message,
  emailSent)` data class for the resend endpoint's response shape.
- **`network/ApiService.kt`** — new `resendPickupOtp(orderId)`, POST
  `restaurant/pickup-otp-resend.php?id=`, no request body.
- **`network/ErrorParsing.kt`** — `ParsedApiError` gained
  `retryAfterSeconds: Int?`, parsed from `data.retry_after_seconds`
  (mirrors the rider app's `attemptsRemaining` field/pattern exactly)
  for the endpoint's `resend_cooldown` (429) error.
- **`res/layout/activity_order_detail.xml`** — new `pickupOtpCard`
  block (label + hint + large OTP text + `btnResendPickupOtp` link),
  inserted between the bill-total row and the reject-reason group.
  `visibility="gone"` by default.
- **`res/values/strings.xml`** — `pickup_otp_label`,
  `pickup_otp_hint`, `btn_resend_pickup_otp`,
  `pickup_otp_resend_countdown`, `pickup_otp_resend_sent`,
  `pickup_otp_resend_cooldown_message`, `pickup_otp_resend_failed`.
- **`ui/orderdetail/OrderDetailActivity.kt`**:
  - `renderPickupOtp(order)` — shows/hides `pickupOtpCard` based on
    `status == "rider_assigned" && !pickupOtpVerified && pickupOtp !=
    null`; called from `render()` right before `configureActions()`.
    Cancels any running cooldown timer on every re-render (a fresh
    render might be a different order/status than when the timer
    started).
  - `resendPickupOtp()` — calls `api.resendPickupOtp(orderId)`,
    `InAppNotifier`s success/cooldown/failure, starts the cooldown
    timer on every outcome except a bare network exception (matches
    the endpoint's own "cooldown persists regardless of why the
    previous attempt didn't go through" shape).
  - `startPickupOtpResendCooldown()` — 30s `CountDownTimer`, same
    shape as the rider app's `RequestPayoutActivity.
    startBankOtpResendCooldown()` (per-second countdown text, button
    disabled/re-enabled).
  - `onDestroy()` added to cancel `pickupOtpResendTimer` — this
    Activity didn't have one before.
  - New imports: `android.os.CountDownTimer`,
    `com.anydrop.restaurant.network.parseApiError`. Note this file's
    existing error-message strings are hardcoded (`"Network error"`
    etc., not `R.string.error_network` — that string doesn't exist in
    this app) — the new code matches that existing local convention
    rather than introducing a new string resource for it.
- Brace/paren balance-checked (78/78 curly, 137/137 paren) — no
  compiler in this sandbox, same standing limitation as every prior
  doc in this chain.

## What's NOT done yet — pick up here next session

### 1. Customer app — Delivery OTP Resend (STARTED, INCOMPLETE)

Found the existing OTP display: `OrderStatusActivity.kt`'s `render()`
already shows `track.otp` (from `GET orders/{id}/track`, field `otp`)
in `otpCard`/`otpText` — this predates this session, nothing broken
here.

**Only one change landed so far:**
- **`res/layout/activity_order_status.xml`** — added a
  `btnResendDeliveryOtp` `TextView` inside the existing `otpCard`
  block, below `otpText`. Styled to match the restaurant app's new
  `btnResendPickupOtp` (link-style, `selectableItemBackgroundBorderless`,
  `anydrop_primary` color instead of restaurant's `info_fg` — the
  customer app's OTP card already uses a green success-style
  background/text, so orange was chosen for contrast against that
  green rather than reusing the restaurant app's blue).

**Not yet done — needed before this compiles/works:**
- `res/values/strings.xml` — no `btn_resend_delivery_otp` string
  exists yet. The layout XML currently references
  `@string/btn_resend_delivery_otp`, **which does not exist** — this
  will fail resource compilation as-is. Needs (mirroring the
  restaurant-app names 1:1, `pickup` → `delivery`):
  `btn_resend_delivery_otp`, `delivery_otp_resend_countdown`,
  `delivery_otp_resend_sent`, `delivery_otp_resend_cooldown_message`,
  `delivery_otp_resend_failed`.
- `network/Models.kt` (customer app, `com.anydrop.food.network`) —
  needs its own `ResendOtpResult(message, emailSent)` (or reuse if an
  equivalent already exists under a different name — not checked).
- `network/ApiService.kt` (customer app) — needs
  `resendDeliveryOtp(orderId)`, POST
  `customer/delivery-otp-resend.php?id=`, no body — same shape as the
  restaurant app's `resendPickupOtp`.
- `network/ApiErrorParser.kt` (**note**: customer app's error parser
  is a different file/class than rider/restaurant's — it's
  `ApiErrorParser.parse(response)`, not a standalone `parseApiError
  (errorBody)` function; `OrderStatusActivity.kt`'s existing
  `cancelOrder()` already calls
  `com.anydrop.food.network.ApiErrorParser.parse(response).code` —
  **read that file before assuming the rider/restaurant
  `retryAfterSeconds`-on-`ParsedApiError` pattern ports over
  directly** — the shape may differ) — needs a
  `retry_after_seconds` → `retryAfterSeconds` field added for the
  `resend_cooldown` error, same as the restaurant app's
  `ErrorParsing.kt` change above.
- `ui/orderstatus/OrderStatusActivity.kt` — needs:
  - A `deliveryOtpResendTimer: CountDownTimer?` field + cancel it in
    an `onDestroy()` (check whether this Activity already has one —
    not checked this session — if so, add the cancel line there
    instead of creating a duplicate).
  - `binding.btnResendDeliveryOtp.setOnClickListener { ... }` wired
    in `onCreate()` (find where `binding.btnCancelOrder`'s listener is
    set, add alongside it).
  - `resendDeliveryOtp()` + `startDeliveryOtpResendCooldown()`,
    same two-method shape as the restaurant app's
    `resendPickupOtp()`/`startPickupOtpResendCooldown()` above —
    should be close to a direct port, just swap the API call, error
    code check, and string names.
  - Confirm whether `track.otp` being non-null but the order status
    having just left `rider_assigned`/`out_for_delivery` needs the
    resend button hidden along with the OTP card — it should, since
    `otpCard`'s own visibility already gates on `track.otp`, and the
    new button lives inside that same card, so this should already be
    correct by construction, but wasn't visually confirmed (no device
    here).

**Bottom line: do not build the customer app until the strings.xml
entry is added** — that's the one change that will hard-fail resource
compilation, everything else above is Kotlin that simply won't
reference the new button yet (harmless, just incomplete) until it's
wired up.

### 2. Everything else from prior docs

Doc 123/124's rider-app work (Parts 2-3) is complete and unaffected by
this session. No other outstanding items beyond the customer-app
piece above.

## NOT verified — same standing sandbox limitation

No Gradle/Android SDK/PHP CLI/live DB in this sandbox (repeated from
every doc in this chain). Specifically for this part:

1. Restaurant app: full Gradle build to confirm
   `ActivityOrderDetailBinding` picks up the three new view IDs
   (`pickupOtpCard`/`pickupOtpValueText`/`btnResendPickupOtp`)
   correctly, and manually exercise the resend flow + cooldown on a
   real order in `rider_assigned` state.
2. Customer app: **will not currently build** — see the missing
   `btn_resend_delivery_otp` string above. Fix that first before
   attempting anything else here.
