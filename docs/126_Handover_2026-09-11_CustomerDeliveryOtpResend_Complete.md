# Handover — 2026-09-11 (Part 5): Customer Delivery-OTP Resend COMPLETE

Direct continuation of
`125_Handover_2026-09-11_RestaurantPickupOtp_Complete_CustomerDeliveryOtpResend_Partial.md`.
Finishes item 1 from that doc's "What's NOT done yet" list — the
customer app's delivery-OTP resend feature is now complete and should
build.

## What's DONE this part

### Customer app — Delivery OTP Resend (complete)

- **`res/values/strings.xml`** — added the five missing strings:
  `btn_resend_delivery_otp`, `delivery_otp_resend_countdown`,
  `delivery_otp_resend_sent`, `delivery_otp_resend_cooldown_message`,
  `delivery_otp_resend_failed`. This is the fix for the hard
  resource-compilation failure doc 125 flagged (`activity_order_status.xml`
  already referenced `@string/btn_resend_delivery_otp` before this
  string existed).
- **`network/Models.kt`** (`com.anydrop.food.network`) — new
  `ResendOtpResult(message, emailSent)`, matching the restaurant app's
  own data class and `delivery-otp-resend.php`'s actual response shape
  (`{"message": ..., "email_sent": ...}` — confirmed by reading that
  endpoint's source this session, not assumed).
- **`network/ApiService.kt`** — new `resendDeliveryOtp(orderId)`, POST
  `customer/delivery-otp-resend.php?id=`, no request body — same shape
  as the restaurant app's `resendPickupOtp`.
- **`network/ApiErrorParser.kt`** — **left unchanged**. Doc 125 flagged
  this file's shape might differ from the rider/restaurant apps'
  `ParsedApiError`, and it does: `ApiErrorParser.parse()` already
  returns `data` as a raw `Map<String, Any?>` rather than a typed data
  class with named fields. That means `retry_after_seconds` is already
  reachable as `info.data["retry_after_seconds"]` (a `Double`, per
  Gson's raw-map decoding) with no code change needed — adding a
  dedicated `retryAfterSeconds` field the way the restaurant app's
  `ErrorParsing.kt` did would have been redundant here. Nothing in this
  session's `resendDeliveryOtp()` actually reads the numeric value (only
  `info.code == "resend_cooldown"` is checked, same as the restaurant
  app's message-selection logic), but the value is available at that
  map key if a future screen wants to show the exact wait time.
- **`ui/orderstatus/OrderStatusActivity.kt`**:
  - `deliveryOtpResendTimer: CountDownTimer?` field +
    `deliveryOtpResendCooldownMillis` constant, same shape as the
    restaurant app's `OrderDetailActivity.pickupOtpResendTimer`.
  - `binding.btnResendDeliveryOtp.setOnClickListener { resendDeliveryOtp() }`
    wired in `onCreate()` alongside the existing `btnCancelOrder`
    listener.
  - `onDestroy()` — this Activity already had one (for `polling`/
    `riderMarkerAnimator`/the MapView); added
    `deliveryOtpResendTimer?.cancel()` to it rather than creating a
    duplicate.
  - `render()`'s existing `otpCard` visibility block extended:
    - Diverges from the restaurant app's unconditional
      `renderPickupOtp()` reset in one deliberate way — this screen's
      `render()` runs every 5s poll (not once per explicit reload like
      the restaurant screen), so resetting the button's enabled/text
      state unconditionally on every render would stomp an in-progress
      cooldown's countdown display. The reset is now guarded on
      `deliveryOtpResendTimer == null`.
    - When `track.otp` goes null (order left the OTP-visible status
      window), any running cooldown timer is now explicitly cancelled
      and nulled — the button was already hidden by construction via
      `otpCard`'s own visibility (doc 125's open question, confirmed
      correct), but a stale ticking timer with no visible target was
      still worth stopping cleanly rather than leaving to run out
      silently against a hidden view.
  - `resendDeliveryOtp()` + `startDeliveryOtpResendCooldown()` — direct
    port of the restaurant app's `resendPickupOtp()`/
    `startPickupOtpResendCooldown()`, swapping the API call, string
    names, and error parser call (`ApiErrorParser.parse(response).code`
    instead of `parseApiError(response.errorBody()).code` — see the
    `ApiErrorParser.kt` note above for why no shape adaptation beyond
    that was needed).
  - New import: `android.os.CountDownTimer`.
- **`res/layout/activity_order_status.xml`** — unchanged this session;
  doc 125's `btnResendDeliveryOtp` TextView (inside `otpCard`, below
  `otpText`) already had the right ID for the binding references above.
- Brace/paren balance-checked (`OrderStatusActivity.kt`: 123/123 curly,
  394/394 paren; `Models.kt`: 5/5 curly, 547/547 paren; `ApiService.kt`:
  1/1 curly, 173/173 paren) and `strings.xml` XML-parsed successfully —
  no compiler in this sandbox, same standing limitation as every prior
  doc in this chain.

## What's NOT done yet

Nothing outstanding from this feature. Both the restaurant pickup-OTP
resend (doc 125) and customer delivery-OTP resend (this doc) are now
code-complete.

## NOT verified — same standing sandbox limitation

No Gradle/Android SDK/PHP CLI/live DB in this sandbox (repeated from
every doc in this chain). Specifically for this part:

1. Customer app: full Gradle build to confirm
   `ActivityOrderStatusBinding` picks up `btnResendDeliveryOtp`
   correctly (it was added to the layout in doc 125, this session only
   referenced it from Kotlin/added the string it needed) and
   `ApiService`/`Models` compile with the new declarations.
2. Manually exercise the resend flow + cooldown on a real order in
   `rider_assigned`/`out_for_delivery` state, and confirm the
   cooldown-guarded render() reset (the one behavioral difference from
   the restaurant app's pattern, described above) actually looks right
   on a device — i.e. a poll landing mid-cooldown doesn't visibly
   glitch the countdown text.
3. `backend/api/v1/customer/delivery-otp-resend.php` itself was only
   read, not modified or executed — it already existed going into this
   session (built alongside doc 122/125's backend work) and its
   response shape was read directly from source to build
   `ResendOtpResult`/`resendDeliveryOtp()` against, rather than assumed.
