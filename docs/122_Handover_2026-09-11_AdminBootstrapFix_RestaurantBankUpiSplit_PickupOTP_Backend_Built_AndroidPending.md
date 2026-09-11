# Handover — 2026-09-11: Admin Bootstrap Fix, Restaurant Bank/UPI Split, Pickup OTP (Backend Built, Android Pending)

## Session trigger

App owner reported, in order:

1. Restaurant APK Gradle build failing — `mergeDebugResources` error,
   duplicate `hint_confirm_password` string resource.
2. Admin panel login broken — `Parse error: syntax error, unexpected
   token "use" in admin/_bootstrap.php on line 63`.
3. Restaurant app's Bank Details screen needs Bank vs UPI fields
   clearly separated/labeled, like the Rider app already does.
4. Rider app's dialogs (OTP entry, etc.) need a UI polish pass,
   matching the Restaurant app's visual style.
5. A brand-new **Pickup OTP** system — mirroring the existing Delivery
   OTP, but for the restaurant-to-rider handoff instead of the
   rider-to-customer handoff.
6. Both Pickup OTP and Delivery OTP need a "Resend" option that
   delivers the code through **both** an in-app notification and an
   email.
7. OTP emails should use an attractive HTML template, not plain text.

## What's DONE this session

### 1. Restaurant build fix — duplicate string resource
`restaurant/app/src/main/res/values/strings.xml` had `hint_confirm_password`
defined twice (line 82: "Confirm password" for signup; line 508: "Password"
for the 2026-09-09 bank/UPI password-reconfirm dialog, added under the same
key by mistake). Renamed the second one to `hint_reconfirm_password` and
repointed `BankDetailsActivity.kt`'s one usage. Verified no other duplicate
string keys exist in any of the three apps' `strings.xml`.

### 2. Admin login parse error — root cause found and fixed
`backend/admin/_bootstrap.php` line 62's own docblock comment contained the
literal path fragment `route_recalc_*/fcm_service_account_json` — the `*/`
inside that fragment **closed the PHP comment early** (PHP comments end at
the first `*/`, regardless of context). Everything from line 63 onward
(more English prose, including the word "use") was then parsed as real PHP
code, which is what actually produced the `unexpected token "use"` error —
"use" outside a namespace-import position is a hard parse error. Fixed by
inserting a space (`route_recalc_* /fcm_service_account_json`). Verified no
other file in `backend/` has the same `\w*/\w` pattern inside a comment.

### 3. Restaurant Bank Details screen — Bank vs UPI visually separated
`restaurant/app/src/main/res/layout/activity_bank_details.xml` restructured
into two clearly labeled `bg_card_rounded` cards: **"Bank Account Details"**
(Holder Name, Bank Name, Account Number + masked-account label, IFSC — each
with its own small caption label above the field, using the
`label_account_holder_name` / `label_bank_name` / `label_account_number` /
`label_ifsc_code` strings that already existed in `strings.xml` but were
never actually wired into the layout) and **"UPI Details (Optional)"**
(UPI ID, using `label_upi_id_optional`). Two new strings added:
`section_bank_account_details`, `section_upi_details`. All existing view
IDs (`inputAccountHolderName`, `inputBankName`, `inputAccountNumber`,
`inputIfscCode`, `inputUpiId`, `maskedAccountLabel`) are unchanged, so
`BankDetailsActivity.kt` needed no code changes for this part.

### 4. Pickup OTP — backend fully built (NEW feature)

**Why:** `orders-pickup.php` previously flipped `rider_assigned ->
out_for_delivery` on a single unauthenticated tap — no code, no proof the
rider standing at the restaurant counter was actually the assigned rider.
This mirrors the existing Delivery OTP protection (which guards the
rider-to-customer handoff) onto the restaurant-to-rider handoff too.

**Migration** — `backend/sql/83_migration_pickup_otp.sql` (NOT yet run on
any live DB):
```sql
ALTER TABLE orders
    ADD COLUMN pickup_otp VARCHAR(6) NULL AFTER delivery_otp,
    ADD COLUMN pickup_otp_verified_at TIMESTAMP NULL AFTER otp_verified_at,
    ADD COLUMN pickup_otp_attempts TINYINT NOT NULL DEFAULT 0 AFTER otp_attempts,
    ADD COLUMN pickup_otp_last_sent_at TIMESTAMP NULL AFTER pickup_otp_attempts,
    ADD COLUMN delivery_otp_last_sent_at TIMESTAMP NULL AFTER pickup_otp_last_sent_at;
```

**Key design decision:** unlike `delivery_otp` (only generated when
`$otpRequired` — UPI orders, or COD when `otp_required_for_cod` is on),
`pickup_otp` is generated for **every** order, no gate. The delivery OTP
guards against payment fraud; the pickup OTP guards against handoff fraud —
a concern independent of payment method.

**Files touched:**
- `backend/api/v1/orders/create.php` — generates `pickup_otp` unconditionally
  alongside the existing conditional `delivery_otp`, same
  `otp_length`-setting-driven `random_int` pattern, inserted into the new
  column.
- `backend/api/v1/rider/orders-pickup.php` — rewritten to require `{"otp":
  "..."}` in the POST body and validate it against `pickup_otp` before
  allowing the `rider_assigned -> picked_up -> out_for_delivery` transition.
  Exact same shape as `orders-deliver.php`'s OTP check (shared
  `otp_max_attempts` setting, same `invalid_otp` / `attempts_remaining` /
  `otp_max_attempts_exceeded` response contract) but against its own
  `pickup_otp_attempts` counter — a wrong pickup attempt never touches the
  delivery OTP's counter later in the same order's life.
- `backend/api/v1/restaurant/orders-detail.php` and `orders-list.php` — both
  now add `pickup_otp` (only non-null while `status === 'rider_assigned'`)
  and `pickup_otp_verified` (bool) onto the response, added as a manual
  post-`format_order()` step rather than inside `format_order()` itself
  (shared by customer/admin/rider responses too — none of those should ever
  see this field).

**Resend endpoints (both NEW):**
- `backend/api/v1/restaurant/pickup-otp-resend.php` — restaurant-authed,
  order must belong to them and be `rider_assigned`. Does **not** generate a
  new code — re-delivers the existing `pickup_otp` via (1)
  `create_notification('restaurant', ...)` and (2) `EmailOtpService::send()`
  to `restaurants.owner_email`. 30-second cooldown via the new
  `pickup_otp_last_sent_at` column (mirrors
  `payout-bank-details-request-otp.php`'s cooldown shape, just kept on the
  order row since there's no separate OTP-request table for an
  already-existing code).
- `backend/api/v1/customer/delivery-otp-resend.php` — customer-authed,
  same shape, gated on `delivery_otp !== null` and status
  `rider_assigned`/`out_for_delivery` (matches `orders/track.php`'s own
  reveal condition), cooldown via `delivery_otp_last_sent_at`.

Neither endpoint fails the whole request if only the email leg fails (the
in-app notification already delivered the code) — response includes
`"email_sent": true/false` so the app can show a smaller "email failed,
but here's your in-app notification" hint if it wants to.

### 5. Attractive HTML OTP emails
`backend/lib/email_otp/EmailOtpService.php`'s `buildEmail()` rewritten from
a bare single `<div>` to a proper branded HTML email: orange (`#E64A19`,
matching the Customer app's `anydrop_primary`) header bar, white content
card, OTP shown in a bordered "pill" box, muted footer. Table-based layout
(not flex/grid) specifically because Gmail/Outlook strip modern CSS layout
properties from HTML email — tables + inline styles are what actually
renders consistently across mail clients.

`send()`'s signature gained three **optional, backward-compatible**
trailing params: `?string $subject`, `?string $heading`, `?string $intro`.
All 4 existing call sites (`customer-request-otp.php`,
`rider-request-otp.php`, `restaurant-request-otp.php`,
`payout-bank-details-request-otp.php`) still work unmodified and now
automatically get the nicer template. The two new resend endpoints pass
custom heading/intro text (order-specific) through these new params.
`expiryMinutes = 0` is a valid input now too (pickup/delivery OTPs don't
expire on a timer, only on order lifecycle) — both the HTML and plain-text
branches render "This code stays valid for this order only" instead of
"expires in 0 minutes" when that's passed.

## What's NOT done yet — pick up here next session

All backend, zero Android work done for items 4-7 below. In priority
order:

1. **Rider app — Pickup OTP dialog + wiring.** Needs a new
   `dialog_pickup_otp.xml` (model on `dialog_delivery_otp.xml`, but see
   item 3 below — restyle both while doing this, don't just copy the old
   look) and wiring into whatever currently calls
   `orders-pickup.php`/handles the "Picked Up" button (found in
   `RiderOrderDetailActivity.kt` and `HomeFragment.kt` — check both, the
   pickup button may exist in more than one screen). The dialog needs to
   collect the OTP and pass `{"otp": "..."}` in the POST body — that field
   didn't exist in the request before this session.

2. **Restaurant app — show Pickup OTP + Resend button.** The API
   (`orders-detail.php`/`orders-list.php`) already returns `pickup_otp` /
   `pickup_otp_verified` — nothing on the Android side reads or displays
   them yet. Find wherever the restaurant app shows an active/`rider_assigned`
   order (likely `OrderDetailActivity` or similar — was not located this
   session) and add an OTP display + a "Resend" button calling the new
   `pickup-otp-resend.php`.

3. **Customer app — Delivery OTP Resend button.** Find wherever the
   customer app currently shows the delivery OTP (likely an
   `OrderStatusActivity` reading `orders/track.php`'s existing OTP field —
   was not located this session) and add a "Resend" button calling the new
   `delivery-otp-resend.php`.

4. **Rider app dialogs — UI polish pass.** App owner's explicit ask: "poora
   dialog ki overall UI improve karo just like restaurant app". Looked at
   `restaurant/app/src/main/res/layout/dialog_logout_confirm.xml` this
   session as a reference point (illustration/icon header, centered title +
   message, side-by-side buttons) — rider's current dialogs
   (`dialog_bank_details_otp.xml`, `dialog_delivery_otp.xml`, and the new
   pickup one from item 1) are plain subtitle-text + bare TextInputLayout,
   no icon/illustration, no card framing. Restyle all of them consistently.
   Check if the restaurant app has an `illus_*` drawable that fits an
   OTP-entry context (a lock/shield icon, e.g. reuse `ic_lock` at a bigger
   size if no illustration exists) rather than inventing new art assets
   from scratch.

## NOT verified — do this first in a real environment

Same standing sandbox limitation as almost every other doc in this repo:
no PHP CLI, Gradle, or live DB here.

1. Run migration 83 on a live DB.
2. `php -l` on all 6 touched/new backend files:
   `orders/create.php`, `rider/orders-pickup.php`,
   `restaurant/orders-detail.php`, `restaurant/orders-list.php`,
   `restaurant/pickup-otp-resend.php` (new),
   `customer/delivery-otp-resend.php` (new),
   `lib/email_otp/EmailOtpService.php`.
3. Place a real order, confirm both `delivery_otp` (if applicable) and
   `pickup_otp` land in the DB row.
4. Rider accepts the order, restaurant's order-detail response shows the
   pickup OTP once status is `rider_assigned` — confirm the admin/restaurant
   test account's owner_email actually receives the styled HTML email when
   `pickup-otp-resend.php` is called directly (e.g. via curl/Postman, since
   no Android UI calls it yet).
5. Call `rider/orders-pickup.php` with a wrong OTP — confirm
   `attempts_remaining` decrements correctly and locks at
   `otp_max_attempts`; then with the correct OTP — confirm the
   `rider_assigned -> picked_up -> out_for_delivery` transition still fires
   exactly as before (this endpoint's core transition logic is otherwise
   unchanged from before this session).
6. Restaurant app Gradle rebuild — confirm the `hint_confirm_password`
   duplicate-resource fix actually resolves the `mergeDebugResources`
   failure (this was diagnosed from CI logs, never re-run).
7. Admin panel — confirm login actually loads now (the parse error fix was
   read-verified, not run through a real PHP interpreter).
8. Send a real login OTP email (any of the 4 existing call sites) and open
   it in Gmail + Outlook — confirm the new HTML template renders correctly
   in both (table-based layout was chosen specifically for this, but never
   tested against a real mail client rendering engine).

## Main docs

- `docs/00_Deep_Plan_Statement_CODLimit_QRPay_CashFlow_2026-09-09.md` — unrelated Deep Plan, referenced for the rider-side cash-flow work from the previous session
- `docs/63_Handover_2026-08-29_BankDetails_Built.md` / `64_...Android_Complete.md` — original restaurant bank details build
- `docs/00_HANDOVER_2026-09-09_Rider_Bank_Details_OTP_Save.md` — rider's own bank/UPI OTP-confirm flow (the model this session's pickup OTP borrows its "existing code, resend doesn't regenerate" shape from)
