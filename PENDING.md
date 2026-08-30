# AnyDrop — PENDING.md

**Purpose:** Current, source-checked list of features/work that are genuinely pending.  
**Rule:** Do not add a feature here merely because an old document says `PENDING`. It must still be missing, incomplete, or require a clearly defined remaining implementation/verification step in the current source.

---

# 🔴 P0 — Core Platform Pending

## 1. Admin Order Control

**Status:** ✅ BUILT & VERIFIED — app owner confirmed build + device test pass on 2026-08-28.
Corrected 2026-08-27 (session 12 doc-audit) — stale PENDING marking,
`backend/admin/orders.php` (548 lines) confirmed built directly. Full
detail in `docs/42_Handover_2026-08-26_Admin_Order_Control_Built.md`.

Admin needs a dedicated order-control module.

### Required
- [x] Admin order listing
- [x] Search by order ID
- [x] Filter by area
- [x] Filter by restaurant
- [x] Filter by customer
- [x] Filter by order status
- [x] Filter by payment method/status
- [x] Complete order detail view
- [x] Full order timeline/status history
- [x] Controlled manual status actions where allowed — Force-Cancel,
      gated `orders_manage`, only from non-terminal statuses
- [x] Admin actor/audit record for manual actions — `write_audit_log()`
- [x] Verify financial impact of manual actions — verification step,
      see doc 42's own checklist item 3

### Main docs
- `recall.md` §14
- `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md`
- `docs/41_Plan_Admin_Order_Control_2026-08-26.md`
- `docs/42_Handover_2026-08-26_Admin_Order_Control_Built.md`

---

## 2. Admin Analytics & Reports

**Status:** ✅ BUILT & VERIFIED — app owner confirmed build + device test pass on 2026-08-28.
State/District/Restaurant/Category filters, Rider analytics, Payment
analytics, Coupon analytics, and CSV Export all added this session on
top of doc 44's original build. Full detail in
`docs/50_Handover_2026-08-27_Admin_Analytics_Filters_Riders_Payments_Coupons_Export_Built.md`.

### Required filters
- [x] Date range
- [x] State
- [x] District
- [x] City/Village — same dropdown as Area (see doc 50 for why these
      two levels share one filter, not two)
- [x] Area
- [x] Restaurant
- [x] Restaurant category
- [x] Order status — via Orders section's own Total/Completed/
      Cancelled/Rejected/Failed breakdown (doc 44)
- [x] Payment method — via new Payments section

### Reports
- [x] Order analytics
- [x] Revenue analytics
- [x] Commission analytics — inside Revenue's stat cards (doc 44), not
      a separate section (see doc 50, "what stays out of scope")
- [x] Area performance
- [x] Restaurant performance
- [x] Top restaurants
- [x] Top food/items
- [x] Customer analytics
- [x] Rider analytics
- [x] Payment analytics
- [x] Coupon analytics
- [x] Export/report generation — CSV, gated on `reports_export`

### Still open
- [x] Build + device verification (no PHP CLI/live DB in the sandbox
      this was built in — see doc 50's checklist, items 1-10).

### Main docs
- `recall.md` §31
- `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md`
- `docs/44_Handover_2026-08-26_Admin_Analytics_Built_Ledger_Comments_Fixed.md`
- `docs/50_Handover_2026-08-27_Admin_Analytics_Filters_Riders_Payments_Coupons_Export_Built.md`

---

## 3. Restaurant Insights

**Status:** 🟡 BUILT 2026-08-27 — NOT build/device-verified

Built end-to-end this session per docs/restorent/19 §6's actual spec:
`restaurant/insights.php` (new), date-range toggle (Today/Week/Month),
7-day orders bar chart, 4 stat cards (orders/earnings/AOV/cancellation
rate), top-5 best-selling items, repeat customers. Full detail in
`docs/49_Handover_2026-08-27_Restaurant_Insights_Tab_Built.md`.

### Still open (deliberately out of scope this session — see doc 49)
- [ ] Peak hours — in this item's original wishlist above but NOT in
      §6's actual UI spec; needs its own design decision before
      building (heatmap? single busiest-hour stat?).
- [x] Export CSV — ✅ built 2026-08-29 (doc 65), app owner confirmed
      CSV (not real PDF/xlsx) + in-app download/share-sheet scope.
      Not build/device-verified — see doc 65.
- [x] Build + device verification (no PHP CLI/Android SDK/live DB in
      the sandbox this was built in — see doc 49's checklist).

### Item availability timing — ✅ built 2026-08-29 (doc 68)
today.md §1's real gap. `menu_items.available_from`/`available_until`
(TIME, nullable, migration 62) — optional daily recurring window (e.g.
a breakfast item, 7:00-11:00). `is_menu_item_available_now()` combines
this with the existing is_available toggle; enforced server-
authoritatively in `price_cart()` (covers both cart/validate.php and
orders/create.php), applied to cart-sync.php's restore, and the
customer-facing menu.php's is_available now reflects effective
right-now availability. Restaurant app's add/edit item dialog has the
two time-picker fields, mirroring OfferManagerActivity's happy-hour
pattern. Known gap: search.php/home/*.php still show only the raw
toggle (display-only inconsistency, not a checkout-bypass — see doc
68). Not build/device-verified.

### Generic link-tap routing — ✅ built 2026-08-29 (doc 67)
Closes doc 66's first "still open" item. Decision: external browser via
`Intent.ACTION_VIEW` (http/https only), not an in-app WebView. Both
apps' notification helpers (`NotificationHelper.showOfferNotification`,
`OrderNotificationHelper.showBellNotification`) gained an optional
`linkUrl` param wired from FCM's `data.link`; falls back to prior
behavior (Home / bell list) when absent or invalid. Not build/device-
verified — see doc 67.

### FCM push notifications + admin broadcast — ✅ built 2026-08-29 (doc 66)
Project-wide, not analytics-specific — filed here since it was the
next item tackled in the same session block. Migration 60
(fcm_token), migration 61 (notification_broadcasts), lib/fcm.php
(hand-rolled FCM v1 sender), create_notification() now auto-pushes
for every existing call site, both apps got a FirebaseMessagingService
reusing each app's existing notification UI, admin/broadcast.php
(image/link/area-wise targeting). Per-category notification toggle
(a separate ask, same session) was investigated and deliberately
dropped — only 2 of 5 proposed categories have a real writer. Not
build/device-verified — see doc 66's "still open" list for the exact
device-testing checklist and the known Android-side gaps (generic
link-tap routing, stale-token cleanup).

### Main docs
- `recall.md` §22, 2026-08-27 entry
- `docs/restorent/19_Restaurant_App_UI_Plan.md` §6
- `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md`
- `docs/49_Handover_2026-08-27_Restaurant_Insights_Tab_Built.md`

---

# 🟠 P1 — Restaurant Operations Pending

## 4. Full Restaurant Offers Engine

**Status:** ✅ BUILT & VERIFIED — app owner confirmed build + device test pass on 2026-08-28.
Corrected 2026-08-27 (session 12 doc-audit) — this item was still
marked PENDING with every box unchecked, which no longer matched
either the actual code or this project's own `docs/Status.md` log
(see its "Combo/Bundle Offer Type — Step 6 done ... closes docs/40's
plan, Steps 1-6 all done" entry). Re-verified directly against
`backend/lib/offers.php`, `OfferManagerActivity.kt`/
`dialog_add_offer.xml`, `CheckoutActivity.kt`, `OfferScreenActivity.kt`
+ `home/offers-browse.php` before making this correction.

Existing Coupons Manager is not the full Offers Engine — this is the
separate engine built across docs/29, 30, 31, 32, 33, 34, 35, 36, 37,
38, 39, and 40 (combo/bundle).

### Required offer types
- [x] Percentage discount
- [x] Flat discount
- [x] Item-specific offer
- [x] Category-specific offer
- [x] Combo/bundle — migration 50, docs/40 Steps 1-6, all done
- [x] Buy 2 Get 1 — `buy_x_get_y`
- [x] Fixed-price quantity offer — `quantity_deal`/`buy_x_for_y`
- [x] Free delivery above threshold
- [x] Happy-hour/time-based offer — `start_time`/`end_time` window
- [x] Other structured promotional rules

### Rule engine
- [x] Minimum order
- [x] Maximum discount
- [x] Total usage limit
- [x] Per-customer usage limit
- [x] Daily usage limit
- [x] Start/end date-time
- [x] Item/category eligibility
- [x] Stacking rules — coupon↔offer `allow_coupon_stacking` toggle,
      migration 48
- [x] Exclusive offer handling — apply-mode, migration 49
- [x] Server-authoritative discount calculation — `price_cart()`
- [x] Checkout validation — `cart/validate.php`, `CheckoutActivity`
      offer strip + B1G1 free-item row

### Still open
- [x] Build + device verification (no PHP CLI/Android SDK/live DB in
      the sandbox this was built in — see docs/40's own Step 6
      checklist, items 1-5).

### Main docs
- `recall.md` §9, and the 2026-08-27 session 11/12 entries at the end
- `docs/20_Offers_Pricing_UI_Polish_Notes.md`
- `docs/09_Auto_Bestseller_Discount_And_Git_Push.md`
- `docs/29_Handover_2026-08-24_Offers_Engine_Backend_Built.md` through
  `docs/40_Plan_Combo_Bundle_Offer_Type_2026-08-25.md` (full build
  trail)

---

## 5. Restaurant Delivery Responsibility / Self Delivery — REMOVED

**Status:** DROPPED (2026-08-30) — decided not to build this. All
delivery will remain Anydrop-only (platform-assigned riders); no
restaurant self-delivery mode. Do not re-add unless explicitly
requested again.

---

## 6. Temporary Closure / Holiday Scheduling

**Status:** 🟡 BUILT 2026-08-28 (doc 60/61/62), NOT build/device-verified

Basic temporary close/open functionality exists; full scheduling is
now built (backend + Android) but hasn't had a real Gradle
build/device pass or a live end-to-end backend test yet — same
"Implemented ≠ Tested" caveat this file's completion rule (§34) calls
out. Do not mark DONE until that verification happens.

### Required
- [x] Closed today (existing on-demand `operational_status` switch)
- [x] Closed until specific date/time (`resume_at` → `temp_closed_until`,
      `status-update.php`, doc 60)
- [x] Holiday date / Multi-day closure (`restaurant_closures`
      `date_range` type, migration 58)
- [x] Recurring weekly closure (`restaurant_closures`
      `weekly_recurring` type)
- [x] Server-authoritative operational state (`compute_restaurant_status()`
      extended, auto-expiry on `temp_closed_until`)
- [x] Customer-side visibility (`restaurants/list.php`, `search/search.php`
      both blocks, `restaurants/menu.php` — all three wired, doc 60/61)
- [x] Order-placement blocking — follows from the status computation
      above being consistent across surfaces (not separately
      re-verified this pass — flag for the end-to-end test)
- [x] Restaurant-side schedule management (`ClosureScheduleActivity`,
      Android, doc 62)

### Still open
- [ ] `php -l` on all 8 touched backend files + live end-to-end test
- [ ] Real Android Gradle build/device test (first real compile of
      all 7 Android pieces together)

### Main docs
- `recall.md` §12
- `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md`
- `docs/60_...md` / `docs/61_...md` / `docs/62_...md`

---

## 7. Restaurant Staff / RBAC

**Status:** ✅ BUILT, not yet build/device verified — backend + full
Android UI (login, owner-side Staff Management, and now the Staff
Activity Log audit trail) complete as of 2026-08-30. Standing sandbox
constraint (no PHP CLI, Gradle, or live DB here) means none of it has
run for real yet. Full detail in
`docs/71_Handover_2026-08-29_Restaurant_Staff_RBAC_Backend_Done_Android_InProgress.md`,
`docs/72_Handover_2026-08-30_Restaurant_Staff_RBAC_StaffManagement_UI_Complete.md`,
and
`docs/73_Handover_2026-08-30_Restaurant_Staff_Audit_Trail_Built.md`.

Do not confuse this with Admin RBAC. Admin RBAC already exists.

### Required
- [x] Restaurant staff table — migration 63
- [x] Staff accounts
- [x] Owner role — deliberately not a `restaurant_staff.role` ENUM
      value; the restaurant's own existing login is untouched
- [x] Manager role
- [x] Kitchen role
- [x] Cashier role
- [x] Permission matrix — `backend/lib/permissions.php`
- [x] Staff login/session handling — separate
      `restaurant-staff-login.php`, `auth_tokens.staff_id`
- [x] Endpoint-level authorization — all 47 `restaurant/*.php`
      endpoints re-audited, 33 gated, 14 deliberately left open to any
      authenticated staff (read-only)
- [x] Staff audit trail — migration 64, reuses the existing
      `audit_logs` table (no new schema), `write_staff_audit_log()`
      helper wired into staff-create/update/delete.php, new
      `staff-audit-list.php` endpoint (owner-only), Android
      `StaffAuditLogActivity` reachable from a new Account tab row.
      Not build/device-verified — same standing sandbox constraint.
- [x] Add/remove/deactivate staff — full CRUD backend + owner-facing
      Staff Management screen in the Android app

### Main docs
- `recall.md` §23
- `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md`
- `docs/71_Handover_2026-08-29_Restaurant_Staff_RBAC_Backend_Done_Android_InProgress.md`
- `docs/72_Handover_2026-08-30_Restaurant_Staff_RBAC_StaffManagement_UI_Complete.md`
- `docs/73_Handover_2026-08-30_Restaurant_Staff_Audit_Trail_Built.md`

---

## 8. Review Reporting & Admin Moderation

**Status:** ✅ BUILT & VERIFIED — app owner confirmed build + device test pass on 2026-08-28.

Restaurant review listing and restaurant reply were already implemented.
This session added the rest: migration 54 (`review_reports` table,
`reviews.moderation_status`/`hidden_reason`/`moderated_by`/
`moderated_at`, new `reviews_moderate` permission), customer report
endpoint, and the admin queue.

- [x] Customer report-review action — `api/v1/customer/report-review.php`
- [x] Report reason — required field on that endpoint, stored in `review_reports.reason`
- [x] Admin reported-review queue — `admin/review-moderation.php` (Reported tab)
- [x] Review moderation — Hide / Dismiss / Restore actions, `lib/reviews.php`
- [x] Hide/remove workflow — hide excludes the review from `recalc_restaurant_rating()`; Hidden tab + Restore (undo)
- [x] Audit log — `write_audit_log()` on hide/dismiss/restore
- [x] Abuse protection — `uq_review_report_once` (one report per customer per review; repeat = idempotent no-op, not a queue entry)
- [x] Build + device verification (no PHP CLI/live DB in the sandbox this was built in)

### Main docs
- `recall.md` §11
- `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md`
- `docs/restorent/00_Status.md`

---

# 🟠 P1 — Customer Support / Trust Pending

## 9. Customer Support / Ticket System

**Status:** ✅ ADMIN SIDE BUILT & VERIFIED — app owner confirmed build + device test pass on 2026-08-28.
See `docs/48_Handover_2026-08-27_Support_Ticket_System_Admin_Side_Built.md`.
Migration 52 + `lib/support.php` + `admin/support.php`.

### Required
- [x] Create ticket — admin-side "Log a Ticket" form (`create_ticket()`)
- [x] Ticket ID — `ticket_code`, e.g. `TKT-260827-A1B2C3`
- [x] Order-linked ticket — optional `order_id`
- [x] Issue categories
- [x] Missing item
- [x] Wrong item
- [x] Food quality
- [x] Delivery issue
- [x] Payment issue
- [x] Refund issue
- [x] Account issue
- [x] Coupon issue
- [x] General issue
- [x] Customer/admin conversation — `support_ticket_messages` thread
- [x] Attachments — one per message, JPG/PNG/WEBP, 5 MB cap
- [x] Ticket status — open/in_progress/resolved/closed
- [x] Admin assignment — assign-to-active-admin dropdown
- [x] Resolution — required note to mark Resolved
- [x] Close/reopen — resolved→closed, resolved→in_progress (reopen)
- [x] Audit history — every status change/assignment goes through `write_audit_log()`
- [ ] Customer/Restaurant/Rider App self-service creation — NOT built
      in any app; `create_ticket()` is raiser-type-generic so whichever
      app builds a "Help & Support" screen first can call it directly

### Main docs
- `recall.md` §20
- `docs/21_Production_Feature_Gap_Plan.md`
- `docs/48_Handover_2026-08-27_Support_Ticket_System_Admin_Side_Built.md`

---

## 10. Customer Self-Service Refund Flow

**Status:** PENDING

Refund foundation already exists, but complete customer self-service flow is not finished.

### Required
- [ ] Customer selects eligible order
- [ ] Select refund reason
- [ ] Attach evidence where applicable
- [ ] Create support/refund request
- [ ] Prevent duplicate refund requests
- [ ] Admin review
- [ ] Refund status tracking
- [ ] Wallet refund option where applicable
- [ ] Final refund reconciliation

### Dependency
Should be integrated with the Support/Ticket system rather than creating an uncontrolled standalone refund endpoint.

### Main docs
- `recall.md` §19
- `docs/21_Production_Feature_Gap_Plan.md`

---

## 11. Support AI

**Status:** PENDING

### Required architecture

```text
Customer
   ↓
Support AI
   ↓
Anydrop Backend Proxy
   ↓
Gemini / Claude / GPT
```

### Required
- [ ] Backend AI proxy
- [ ] No direct DB credentials to model
- [ ] Context filtering
- [ ] Order lookup through controlled APIs
- [ ] Refund/support escalation
- [ ] Human handoff
- [ ] Conversation history
- [ ] Abuse/rate limiting
- [ ] Cost controls
- [ ] Logging

### Main docs
- `recall.md` §21
- `docs/21_Production_Feature_Gap_Plan.md`

---

## 11a. Admin Panel — Customer Feedback View

**Status:** ✅ BUILT & VERIFIED — app owner confirmed build + device test pass on 2026-08-28.
See `docs/52_Handover_2026-08-27_Admin_Feedback_View_And_Customer_Complete_Profile_Built.md`.

`api/v1/customer/feedback.php` (Phase 3.6 §2.7) has always been
capture-and-store only — this closes that endpoint's own kdoc TODO
("Reviewable directly in the `feedback` table, or a future Admin Panel
screen, Phase 5").

### Required
- [x] Migration 55 — new `feedback_view` admin permission
- [x] `admin/customer-feedback.php` — read-only list (customer name/
      email/mobile, star rating, message, created_at)
- [x] Star-rating filter chips + message text search
- [x] Sidebar nav entry (Operations group)
- [x] Run migration 55 on a live DB
- [x] Device/browser-verify the page renders and filters correctly
- [ ] Decide if a future session should add a status/workflow column
      (mark-as-reviewed, reply-to-customer) — deliberately NOT built
      this session, this is read-only same as the endpoint always was

### Main docs
- `docs/52_Handover_2026-08-27_Admin_Feedback_View_And_Customer_Complete_Profile_Built.md`

---

## 11b. Customer App — Complete Profile After OTP Login (name + mobile)

**Status:** ✅ BUILT & VERIFIED — app owner confirmed build + device test pass on 2026-08-28.
See `docs/52_Handover_2026-08-27_Admin_Feedback_View_And_Customer_Complete_Profile_Built.md`
and `docs/53_Handover_2026-08-27_Complete_Profile_Android_Side_Built.md`.

`customers.name` / `customers.mobile` (`01_Database_Schema.md`) have
always been nullable — email-OTP signup never collected either. App
owner asked for a "tell us your name + number" step right after OTP
verification succeeds, before Home.

### Required
- [x] `api/v1/customer/complete-profile.php` — auth'd endpoint, takes
      `{name, mobile}`, validates 10-digit mobile, rejects duplicate
      mobile, updates the row
- [x] `.htaccess` clean route added
- [x] Add `mobile` to the `Customer` model in `Models.kt`
- [x] `CompleteProfileBody`/`CompleteProfileResult` models
- [x] `ApiService.kt` — `completeProfile()` call
- [x] New `CompleteProfileActivity.kt` + `activity_complete_profile.xml`
      (name + mobile fields, same visual style as `activity_login.xml`)
- [x] `ic_phone.xml` drawable (doesn't exist yet — `ic_mail`/`ic_person`/
      `ic_lock` do)
- [x] Register the new activity in `AndroidManifest.xml`
- [x] Wire `LoginActivity.onVerifyOtp()` — if the returned
      `customer.name` or `customer.mobile` is null, navigate to
      `CompleteProfileActivity` instead of straight to `HomeActivity`
- [x] Device/build-verify the full OTP → complete-profile → Home flow
      (no Android SDK/emulator in this sandbox — needs Android Studio)

### Main docs
- `docs/52_Handover_2026-08-27_Admin_Feedback_View_And_Customer_Complete_Profile_Built.md`
- `docs/53_Handover_2026-08-27_Complete_Profile_Android_Side_Built.md`

---

# 🟠 P1 — Wallet / Finance Pending

## 12. Customer Wallet Checkout Integration

**Status:** ✅ BUILT & VERIFIED — corrected 2026-08-28 (this item was
stale-marked PENDING; source-checked against `orders/create.php`,
`wallet.php`, and `CheckoutActivity.kt` — confirmed built 2026-08-23
per `recall.md` §item 26 §D.12-D.15. App owner confirmed build +
device test pass on 2026-08-28.

Wallet balance/history screen already exists.

- [x] Wallet payment option in checkout — `radioWallet` in `CheckoutActivity.kt`
- [x] Wallet balance shown during checkout — live balance label, `pay_wallet_with_balance_format`
- [x] Wallet debit inside order transaction — `orders/create.php`, inside the same DB transaction
- [x] Insufficient-balance handling — pre-check + `wallet_insufficient_balance` error, amount-aware
- [x] Atomic wallet/order transaction — row-locked `debit_wallet_for_order()`
- [x] Idempotency — same `idempotency_key` mechanism as every other order
- [x] Payment failure rollback

### Main docs
- `recall.md` §18, §item 26 §D
- `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md`

---

## 13. Wallet Refund Integration

**Status:** ✅ BUILT & VERIFIED — corrected 2026-08-28 (stale-marked
PENDING; confirmed built 2026-08-23 per `recall.md` §item 15/26 §D
follow-up session #9 — `lib/refunds.php`'s `complete_refund_to_wallet()`
+ `admin/refunds.php`'s "Credit to Wallet" button). App owner confirmed
build + device test pass on 2026-08-28.

### Required
- [x] Refund-to-wallet method — `complete_refund_to_wallet()`
- [x] Atomic refund ledger entry — via `credit_wallet()`'s own row lock
- [x] Wallet transaction reference — synthetic `WALLET-CREDIT-{txn_id}` marker
- [x] Customer notification — `credit_wallet()`'s own notification
- [x] Duplicate refund protection
- [x] Reconciliation

---

## 14. Cashback / Wallet Reward Engine

**Status:** PENDING

### Required
- [ ] Cashback rules
- [ ] Eligibility
- [ ] Maximum cashback
- [ ] Wallet credit
- [ ] Transaction reference
- [ ] Expiry
- [ ] Reversal on cancellation/refund
- [ ] Admin configuration
- [ ] Abuse prevention

---

## 15. Restaurant Bank Details Submission

**Status:** 🟡 BUILT (backend + Android both complete, 2026-08-29 —
migration 59, `docs/63_Handover_2026-08-29_BankDetails_Built.md` +
this session's Android build), NOT build/device-verified.

Admin-side settlement/bank infrastructure exists.

Remaining:

- [x] Restaurant-side bank details form — backend endpoints
      (`bank-details-get.php`/`bank-details-save.php`) + Android
      screen (`activity_bank_details.xml` + `BankDetailsActivity.kt`,
      wired from `AccountFragment`'s new Bank Details row) both done.
- [x] Account-holder name — validated server-side + client-side mirror
- [x] Account number — validated server-side (9–18 digits), masked on
      every read (last 4 digits only); client-side mirrors the same
      regex, Android leaves the field blank on load and requires a
      full re-type to change any field (see `BankDetailsActivity.kt`'s
      own kdoc for why — the masked value can't be resubmitted as-is)
- [x] IFSC — validated server-side (standard RBI shape) + client-side
      mirror
- [x] Validation — server-side + Android client-side mirror both done
- [x] Verification status — migration 59, pending/verified/rejected;
      Android shows a colored status badge + admin remarks when present
- [ ] Secure display/storage — masking done; full-value-at-rest
      encryption not evaluated (currently plain VARCHAR, same as
      migration 38 always had)
- [x] Admin verification — `admin/settlements.php` verify/reject
      actions, migration 59
- [x] Audit trail — `bank_details_submitted` (restaurant) /
      `restaurant_bank_details_verified`/`_rejected` (admin) via
      `write_audit_log()`

**Not build/device-verified** — same standing sandbox limitation (no
PHP CLI/live DB/Android SDK in this container). Needs, in order: (1)
migration 59 run on the live DB, (2) `php -l` on the 4 backend files,
(3) Android rebuild, (4) live click-through — submit fresh bank
details, confirm status shows Pending Review, verify/reject from
`admin/settlements.php`, confirm the badge + remarks update on
re-open, edit an already-verified record and confirm status resets to
pending.

---

## 16. Settlement Screenshot Upload

**Status:** ✅ BUILT & VERIFIED — app owner confirmed build + device test pass on 2026-08-28. See
`docs/46_Handover_2026-08-26_Settlement_Screenshot_Upload_Built.md`.

- [x] Actual screenshot/file upload — `admin/settlements.php`'s Pay Now form
- [x] Secure storage — `backend/uploads/settlement_screenshots/`, finfo MIME-sniffed, 5MB cap
- [x] File validation
- [x] Settlement record attachment — `restaurant_payments.screenshot_url` (column already existed)
- [x] Admin view — Settlement History thumbnail/link
- [ ] Restaurant view — not built (admin-recorded flow only, per doc 19 §6's model)
- [x] Audit trail — `write_audit_log('settlement_recorded', ...)` now includes the path

Needs: PHP lint + live click-through per the handover doc's checklist.

---

## 17. Restaurant Finance / Payout Analytics

**Status:** ✅ BUILT & VERIFIED — app owner confirmed build + device test pass on 2026-08-28. See
`docs/47_Handover_2026-08-26_Restaurant_Payout_Analytics_Built.md`.
`admin/settlements.php`'s per-restaurant Payout Analytics card, doc 19
§6's exact column list.

- [x] Total orders
- [x] Cash collected (COD)
- [x] Online collected (UPI)
- [x] Commission
- [x] GST (on commission, `app_settings.gst_percent`)
- [x] Restaurant payable/due — Net Payable + live `current_due` (Pending)
- [x] Today
- [x] Weekly (rolling 7 days, matches `analytics.php`'s convention)
- [x] Monthly (rolling 30 days, same convention)
- [x] Settlement history — already existed, unchanged by this item
- [x] Download/export — CSV, gated on `reports_export`, built
      2026-08-27 (session 12): Payout Analytics + Ledger Statement +
      Settlement History, per-restaurant. Same `Content-Disposition`/
      `fputcsv` pattern doc 50 established for `analytics.php` — this
      is the second page to use it, not a new convention.

Needs: PHP lint + a live page load across all three ranges, including a
restaurant with zero orders in range (confirm no divide-by-zero /
blank-state rendering issue). Export needs: click Export CSV with
`reports_export`, confirm the file downloads with the right filename
and figures matching the page; hit `?export=csv` directly as a
`payouts_view`-only admin without `reports_export`, confirm no crash
and the link itself stays hidden; confirm `settlement_exported` audit
log entry appears.

---

# 🔴 P0/P1 — Delivery Network Pending

## 18. Rider App

**Status:** NOT STARTED / PENDING

### Required
- [ ] Rider signup
- [ ] Login
- [ ] Profile
- [ ] Documents
- [ ] Admin approval
- [ ] Online/offline
- [ ] Availability
- [ ] Order assignment
- [ ] Accept/reject
- [ ] Pickup
- [ ] Navigation
- [ ] Customer contact
- [ ] Restaurant contact
- [ ] Delivery OTP
- [ ] Order completion
- [ ] Earnings
- [ ] History
- [ ] Support

### Main docs
- `recall.md` §24
- `docs/03_Live_Tracking.md`
- `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md`

---

## 19. Rider Assignment System

**Status:** PENDING

### Required
- [ ] Find eligible riders
- [ ] Distance/area matching
- [ ] Online status
- [ ] Assignment
- [ ] Accept/reject
- [ ] Reassignment
- [ ] Timeout
- [ ] Restaurant/customer notification
- [ ] Admin override
- [ ] Audit trail

---

## 20. Live Rider Tracking

**Status:** PENDING

### Required
- [ ] Rider GPS pings
- [ ] Customer map
- [ ] Restaurant map where required
- [ ] Admin map
- [ ] Smooth marker movement
- [ ] ETA
- [ ] Route
- [ ] Foreground location service
- [ ] Battery-aware interval
- [ ] Offline handling
- [ ] Location history
- [ ] Permission handling

### Main docs
- `recall.md` §25
- `docs/03_Live_Tracking.md`
- `docs/12_Handover_H6_Map_PinDrop_Photo.md`

---

## 21. Delivery OTP / Completion Flow

**Status:** PENDING

### Required
- [ ] Generate delivery OTP
- [ ] Show to customer
- [ ] Rider enters OTP
- [ ] Server validation
- [ ] Attempt limit
- [ ] Completion only after valid OTP where required
- [ ] Failed attempt handling
- [ ] Audit trail

---

## 22. Rider Earnings / Settlement

**Status:** PENDING

### Required
- [ ] Per-order earning
- [ ] Delivery fee split
- [ ] Incentives
- [ ] Penalties where applicable
- [ ] Daily/weekly/monthly earnings
- [ ] Rider ledger
- [ ] Settlement
- [ ] Payout history

---

# 🔴 P1 — Authentication / Production Pending

## 23. Google Login Backend Verification

**Status:** PENDING

Customer-side Google login flow still needs complete backend verification.

### Required
```text
Google Sign-In
    ↓
ID Token
    ↓
Backend Verification
    ↓
Customer Create/Login
    ↓
Session
```

### Required
- [ ] Backend token verification
- [ ] Account linking
- [ ] New-user creation
- [ ] Existing-user login
- [ ] Secure session generation
- [ ] Invalid token handling
- [ ] Replay protection where applicable

### Main docs
- `recall.md` §26
- `docs/02_API_Contract.md`
- `docs/Status.md`

---

## 24. Payment / Refund Reconciliation

**Status:** 🟡 BUILT 2026-08-30 (session 20, doc 76) — NOT build/device-verified.

Migration 66 + `backend/lib/reconciliation.php` + `backend/admin/reconciliation.php`.
Doc-audit note (same session): found real, previously-undocumented
work already in the codebase — Paytm auto-verify + `provider_bank_ref`
dedupe (migrations 41/42-paytm/43-dedupe-providers,
`UpipeProvider::tryAutoVerify()`, `PaytmStatusClient.php`) — this
closes most of "Provider transaction matching" and "Duplicate
transaction detection" below at the DB-constraint level already; this
session's build is the detection/queue layer on top of that.

### Required chain

```text
Order
 ↓
Payment Transaction
 ↓
Platform Ledger
 ↓
Restaurant Due Ledger
 ↓
Settlement
 ↓
Refund
```

### Required
- [x] Authoritative payment state — verified via
      `recon_check_payment_confirmed_order_not_paid()` /
      `recon_check_paid_upi_order_missing_transaction()` (cross-checks
      `payment_transactions.status` against `orders.payment_status`
      both directions)
- [x] Provider transaction matching — already DB-enforced
      (`uq_ptxn_utr`, `uq_ptxn_provider_bank_ref`, migrations 40/42);
      `recon_check_order_multiple_successful_transactions()` adds the
      one gap those constraints don't cover (two distinct successful
      transactions landing on the same order)
- [x] Reconciliation job/check — `run_reconciliation_scan()`, 11
      checks, run on demand from `admin/reconciliation.php` (no cron
      exists in this codebase to run it automatically yet — flagged as
      a real gap below, not silently assumed away)
- [x] Mismatch detection — every check above + `wallet_balance_drift`
      (stored `customer_wallets.balance` vs its own transaction-history
      sum) + `platform_balance_mismatch` (pulls in `platform-ledger.php`'s
      existing inline check as a persisted, resolvable flag)
- [x] Duplicate transaction detection — DB-enforced (see above) +
      `order_multiple_successful_transactions`
- [x] Refund reconciliation — `refund_missing_ledger_entry` (manual
      transfer), `wallet_refund_missing_credit` /
      `wallet_refund_unexpected_ledger_entry` (wallet method, both
      directions), `order_refunded_no_refund_record`
- [x] Settlement reconciliation — new `restaurant_due_ledger.
      restaurant_payment_id` column (migration 66 — this link never
      existed before; `platform_ledger` had it since migration 38 but
      `restaurant_due_ledger` never did), backfilled best-effort for
      history, populated going forward by `record_settlement()`;
      `settlement_missing_ledger_entry` check uses it
- [x] Admin mismatch queue — `reconciliation_flags` table (migration
      66), `admin/reconciliation.php` (Resolve/Ignore, both
      note-required + audit-logged, both gated on
      `reconciliation_manage`)
- [x] Audit trail — scan runs + every resolve/ignore go through
      `write_audit_log()`

### Still open
- [ ] Migration 66 run on a live DB (including its backfill query —
      review the backfilled `restaurant_payment_id` links on real
      historical data once run, the 120-second-window heuristic was
      never tested against real timestamps)
- [ ] `php -l` on the 3 touched/new backend files (no PHP CLI in this
      sandbox — only a manual brace/paren/bracket balance check was
      possible)
- [ ] Live click-through: run a scan against a clean DB (expect ~0
      flags), then deliberately break one invariant by hand (e.g.
      delete a `platform_ledger` `refund_out` row for a refunded
      order) and confirm the matching check fires on the next scan;
      confirm Resolve/Ignore round-trip and that an ignored flag
      doesn't resurface while a still-broken one does
- [ ] No scheduler/cron exists anywhere in this codebase (same gap
      `wallet.php`'s cashback-expiry note already flagged) — this scan
      is admin-triggered only for now; running it automatically (daily)
      needs that infrastructure to exist first, out of scope for this
      session
- [ ] Webhook-based payment confirmation (doc 21 §5.6's original
      diagram) doesn't apply yet — there is no live gateway, only the
      manual/auto-verify UPIPE stub — revisit this doc's diagram once
      Phase E's real-gateway work happens (doc 23 §9)

### Main docs
- `recall.md` §28
- `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md`
- `docs/21_Production_Feature_Gap_Plan.md`
- `docs/23_Native_UPI_Payment_Gateway_Architecture_2026-08-23.md`
- `docs/76_Handover_2026-08-30_Payment_Refund_Reconciliation_Built.md`

---

## 25. Email OTP Provider Failover System

**Status:** PENDING / NEEDS FINAL VERIFICATION

### Required
- [ ] Provider abstraction
- [ ] Provider 1
- [ ] Provider 2
- [ ] Provider 3
- [ ] Priority
- [ ] Enable/disable
- [ ] Daily quota
- [ ] Monthly quota
- [ ] Automatic fallback
- [ ] Failure logging
- [ ] Test send
- [ ] Usage dashboard
- [ ] Admin configuration

### Main docs
- `recall.md` §30
- `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md`

---

# 🔴 P1 — Production Security Pending

## 26. Security Hardening

**Status:** PENDING / FINAL AUDIT REQUIRED

### Required
- [ ] OTP rate limiting
- [ ] OTP abuse protection
- [ ] Order idempotency
- [ ] Coupon race-condition protection
- [ ] Server-side price validation
- [ ] Server-side operational-state validation
- [ ] Payment authorization
- [ ] Refund authorization
- [ ] Admin RBAC audit
- [ ] Financial-data access control
- [ ] Audit logging
- [ ] Production secret management
- [ ] Error logging
- [ ] Backup policy
- [ ] Restore testing

### Main docs
- `docs/security.md`
- `docs/bugs.md`
- `docs/21_Production_Feature_Gap_Plan.md`

---

# 🟡 P2 — Remaining Verification / Smaller Gaps

## 27. Restaurant Search Delivery-Radius Consistency

**Status:** PARTIAL

`restaurants/list.php` has delivery-radius logic, but `search.php` still needs the same server-side consistency.

- [ ] Apply/verify `delivery_radius_km` in search
- [ ] Test area + distance combination
- [ ] Test unresolved-location behavior

### Main doc
- `recall.md` §3

---

## 28. Area / Payment / Banner Live Verification

**Status:** IMPLEMENTED — VERIFICATION PENDING

- [ ] Test service-area resolution
- [ ] Test restaurant area matching
- [ ] Test delivery radius
- [ ] Test COD rules
- [ ] Test minimum order
- [ ] Test delivery fee
- [ ] Test payment restrictions
- [ ] Test area-targeted banners
- [ ] Test unresolved-location fallback
- [ ] Test real device build

---

## 29. Final Restaurant Finance Live Verification

**Status:** IMPLEMENTED FOUNDATION — VERIFICATION PENDING

- [ ] Commission calculation
- [ ] Restaurant due
- [ ] Paid order ledger
- [ ] Settlement
- [ ] Platform ledger
- [ ] UTR/reference
- [ ] Audit record
- [ ] Refund interaction
- [ ] Real DB/device test

---

## 30. Final Suspend / Session / Migration Verification

**Status:** IMPLEMENTATION PRESENT — FINAL VERIFICATION PENDING

Based on the latest handovers:

- [ ] Run required migration(s)
- [ ] Build Restaurant app
- [ ] Test suspended restaurant login
- [ ] Test already logged-in suspended restaurant
- [ ] Test API rejection after suspension
- [ ] Test customer visibility
- [ ] Test reactivation
- [ ] Test session/token behavior
- [ ] Verify Admin audit

### Main docs
- `docs/25_Handover_2026-08-24_Suspend_Banners_CodReason.md`
- `docs/26_Handover_2026-08-24_Suspend_MidSession_Backend_Done_Android_Partial.md`
- `docs/27_Handover_2026-08-24_Suspend_Android_Wiring_Done_Needs_Build_And_Migration.md`

---

# 31. Full Build / Device / Live DB Regression

**Status:** PENDING

Before marking the current development milestone as production-ready:

- [ ] Backend syntax check
- [ ] DB migration check
- [ ] Customer APK build
- [ ] Restaurant APK build
- [ ] Admin login test
- [ ] Customer login test
- [ ] Restaurant login test
- [ ] Order creation
- [ ] Restaurant accept/reject
- [ ] Status updates
- [ ] Payment
- [ ] Refund
- [ ] Wallet
- [ ] Coupon
- [ ] Banner
- [ ] Area rules
- [ ] Commission
- [ ] Settlement
- [ ] Suspend/reactivate
- [ ] Notification
- [ ] Review/reply
- [ ] Error handling
- [ ] Security regression

---

# 32. PENDING MASTER ORDER

Recommended implementation order:

### Phase A — Admin Control
- [ ] Admin Order Control
- [ ] Admin Analytics/Reports

### Phase B — Restaurant Operations
- [ ] Restaurant Insights
- [ ] Full Offers Engine
- [ ] Temporary Closure/Holiday Scheduling
- [ ] Review Reporting/Moderation
- [ ] Restaurant Staff/RBAC

### Phase C — Finance/Customer
- [ ] Wallet Checkout
- [ ] Wallet Refund
- [ ] Cashback
- [~] Restaurant Bank Submission (backend done, Android pending — §15)
- [ ] Settlement Screenshot
- [ ] Finance Analytics
- [ ] Support/Tickets
- [ ] Customer Refund Requests
- [ ] Support AI

### Phase D — Delivery
- [ ] Rider App
- [ ] Rider Assignment
- [ ] Delivery OTP
- [ ] Live Tracking
- [ ] Rider Earnings

### Phase E — Production
- [ ] Google Login backend
- [~] Payment/refund reconciliation — built 2026-08-30, not device-verified (§24)
- [ ] Email OTP provider failover
- [ ] Security hardening
- [ ] Full regression/build/device/live DB testing

---

# 33. Do NOT move these back into PENDING

The following already have current source implementation/foundation and should only be reopened for verification or specific remaining sub-features:

- Admin Dashboard
- Admin Restaurant Management foundation
- Admin Customer Management foundation
- Admin Areas
- Admin Categories
- Admin Banners
- Admin COD Rules
- Admin Payment Restrictions
- Admin Pricing Rules
- Admin Commission Rules
- Admin Settlements foundation
- Platform Ledger
- Admin Payment Gateway foundation
- Admin Pending UPI queue
- Refund foundation
- Customer Wallet screen/history
- Restaurant Review Reply
- Restaurant Account tab
- Restaurant Coupons Manager
- Restaurant Banners Manager
- Restaurant Item Tags
- Restaurant Notifications
- Restaurant Suspend flow foundation
- Restaurant OPEN/CLOSED foundation

---

# 37. Wallet Withdrawal + Prepaid-Cancel Auto-Refund-to-Wallet

**Status:** 🟡 ALL CODE BUILT (backend + Android), 2026-08-30 (session
19, doc 75) — only migration-run + live/device testing + the PHP
syntax error remain. Do not treat either half as production-verified
yet.

App owner request: (1) a prepaid order cancelled by the customer
within the cancel window should auto-refund straight to the in-app
Wallet, no manual admin step; (2) a Wallet withdrawal feature —
customer requests payout to bank/UPI (saved or entered fresh), admin
reviews and marks it paid. Also flagged: a PHP syntax error somewhere
on the admin Refunds page — investigated session 18 (full-project
brace-balance check, no PHP CLI in sandbox), **not found** — still
needs the app owner's actual error text from their own machine.

### Required — auto-refund-to-wallet on cancel
- [x] `lib/refunds.php` — `auto_wallet_refund_on_cancel()`, wired into
      `orders/cancel.php`'s customer-self-cancel-within-window path
      only (restaurant-reject / admin-force-cancel unchanged, still
      manual-review refunds)
- [ ] Migration 65 run on a live DB
- [ ] Live test: UPI order → cancel within window → wallet balance up,
      one `refunds` row already `refunded`/`wallet`

### Required — wallet withdrawal
- [x] Migration 65 — `customer_bank_details`, `wallet_withdrawals`
      tables, `wallet_transactions`/`platform_ledger` ENUMs widened,
      `wallet_withdrawals_view`/`wallet_withdrawals_manage` permissions
- [x] `lib/customer_wallet_withdrawal.php` — full library:
      validation, save/get/serialize bank details, `request_wallet_
      withdrawal()` (debits wallet up front — see its own kdoc for why
      this is the safe design), admin approve/processing/complete/
      reject functions
- [x] Customer API endpoints — `api/v1/customer/wallet-bank-details-get.php`,
      `wallet-bank-details-save.php`, `wallet-withdrawal.php` (GET
      history / POST request), all thin wrappers around
      `lib/customer_wallet_withdrawal.php`
- [x] `.htaccess` clean routes — `/api/v1/customer/wallet/bank-details`,
      `/bank-details/save`, `/withdrawal`
- [x] Admin review page (`admin/wallet-withdrawals.php`, mirrors
      `admin/refunds.php`'s shape — list + Approve/Mark Processing/
      Complete/Reject, CSRF, unmasked payout details) + nav entry in
      `admin/_layout_head.php`
- [x] Android — `WithdrawActivity` (form + history), `WalletWithdrawalAdapter`,
      new models/API methods, Withdraw button on `WalletActivity`.
      Bank details pre-fill on load (account number deliberately not
      pre-filled, only masked). Not yet Gradle-compiled/device-run.
- [ ] Migration 65 run on a live DB
- [ ] Live test: request withdrawal → balance drops immediately →
      Approve → Mark Processing (reference) → Mark Completed →
      `platform_ledger` gets `wallet_withdrawal_out` row; separately
      test Reject from both `requested` and `approved` → balance
      credited back exactly
- [ ] Real Android Studio/Gradle build + device click-through

### Main docs
- `docs/74_Handover_2026-08-30_Wallet_Withdrawal_And_AutoRefund_Backend_Partial.md`
- `docs/75_Handover_2026-08-30_Wallet_Withdrawal_Endpoints_AdminPage_And_Android_Built.md`

---

# 34. Completion rule

A feature is only removed from this file after:

```text
SOURCE IMPLEMENTED
       ↓
DB/MIGRATION READY
       ↓
API VERIFIED
       ↓
ANDROID/ADMIN UI VERIFIED
       ↓
REAL DEVICE TEST
       ↓
LIVE DB / END-TO-END TEST
       ↓
SECURITY + EDGE CASE TEST
       ↓
DONE
```

**Implemented ≠ Tested**

**Tested ≠ Production-ready**

Only after the complete flow is verified should the item be moved to `done.md`.
