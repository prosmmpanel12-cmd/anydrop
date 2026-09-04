# Native UPI Payment Gateway — Architecture Spec (2026-08-23)

> **STATUS: 🟡 BUILT 2026-08-23, NOT device-verified.** The design
> below was implemented the same session (migration 40, `lib/payment/`
> rewrite, 3 customer APIs, 2 admin pages) — see the **Addendum**
> at the very bottom for the file list and the couple of places the
> real build deviated from this doc's first draft (most notably: no
> server-generated QR image, see the addendum's §A1). Same standing
> "not build/device-verified" caveat every session in this repo
> carries — no PHP CLI or live DB in this sandbox to actually run it
> against.

---

## 0. The one decision this whole doc hangs off

Anydrop is **not** going to call UPIPE (`yourapi.42web.io`) as a
hosted third-party API the way `sdk/UpiPeSDK/UpiPe.php` is designed
to be used (API key → `https://yourapi.42web.io/api/upi/api/...`).

Instead, Anydrop's own backend becomes its own UPI collection system:

- Anydrop's own backend generates the order.
- Anydrop's own backend generates the QR (an `upi://pay?...` intent
  string encoded as a QR image, using the **admin's own UPI ID** —
  not UPIPE's, not a third party's).
- Anydrop's own backend verifies the payment.
- No money, no API call, no data ever goes to `yourapi.42web.io` or
  any other outside UPI gateway company.

So in the `payment_providers` table (migration 39, already in the
repo) the row named **`UPIPE`** does **not** mean "call the UPIPE
company's API." It means "the in-house, UPIPE-*pattern* UPI-QR
collection method" — same interface slot the real UPIPE stub already
occupies (`lib/payment/UpipeProvider.php`), same `driver_key =
'upipe'`, but the class body talks to Anydrop's own DB and Anydrop's
own QR generator, never an external host. This preserves everything
migration 39 and doc 19 §8 already set up (registry pattern, admin
enable/disable/priority/test-mode, `PaymentService` as the only
caller) — it just changes what the `UpipeProvider` class *does*
internally.

**Why UPIPE's source is in this repo at all, then:** it's a
well-tested reference implementation of exactly this problem — QR
generation from a UPI ID, an order/status state machine, a manual
UTR-approval fallback, an admin panel to approve/reject. Anydrop's
own implementation should follow the *shape* of that logic (state
names, timing rules, the auto→manual fallback pattern) without ever
making an outbound call to it. Think "textbook," not "dependency."

---

## 1. What UPIPE's own SDK does that Anydrop explicitly does NOT want

Reading `sdk/readme.md` / `sdk/UpiPeSDK/UpiPe.php` against the app
owner's instructions for this session, three things are called out as
**out of scope**:

| UPIPE SDK behavior | Anydrop decision |
|---|---|
| `deep_links: { gpay, phonepe, paytm }` — buttons that jump straight into a specific UPI app | ❌ **Not used.** No deep links anywhere in the customer app UI. |
| `upi_link` shown as a tappable button | ❌ Not shown as a button. The same `upi://pay?...` string is still generated (it's *what gets encoded into the QR*), it's just never surfaced to the customer as clickable text/link. |
| Auto-verify via a Paytm merchant-ID integration | 🔶 Not available at launch (Anydrop has no Paytm MID integration). Verification is manual-admin, see §5. Auto-verify is a documented **future** upgrade path, same "swap the class body, not the caller" guarantee as any other provider swap. |

What Anydrop **does** keep from the UPIPE pattern:

- QR-first UX (`qr_url` equivalent).
- Order state machine shape (`initiated → paid`, with an
  `expired` branch).
- A UTR-submission fallback + admin approve/reject panel — this is
  the *only* realistic way to confirm a payment when there's no live
  gateway webhook (see §5).

---

## 2. Customer-facing flow

```
Checkout screen
      │  customer selects "UPI" as payment method
      ▼
App calls: POST /customer/payment/upi/create-order
      │  (order already exists in `orders` table at this point —
      │   this call is scoped to payment, not order creation)
      ▼
Backend:
  1. Loads the active UPIPE-pattern provider row from payment_providers
     (is_active=1, highest priority) — same PaymentService lookup
     migration 39 already assumes.
  2. Reads that provider's config_json → admin's own UPI ID (`pa`),
     payee display name (`pn`).
  3. Builds the UPI intent string:
       upi://pay?pa={admin_upi_id}&pn={payee_name}&am={amount}
                &tr={internal_txn_ref}&tn={order_note}&cu=INR
  4. Generates a QR image from that string (phpqrcode-style, same
     library UPIPE's own source uses — see §10).
  5. Inserts a payment_transactions row, status = 'initiated'.
  6. Returns: QR image (base64 or a short-lived signed URL),
     internal txn ref, amount, expiry countdown, and the instructions
     text (§3).
      ▼
Payment screen (customer app)
  - Shows the QR.
  - Shows instructions (§3) — screenshot-or-second-device framing,
    because this QR is NOT expected to be scanned by the same phone
    the app is running on.
  - Starts a 10-second poll loop against the verify endpoint (§4).
      ▼
Customer pays with whatever UPI app they like (GPay / PhonePe / Paytm
/ BHIM / any bank app) — Anydrop never knows or cares which one, since
there's no deep link tying the flow to a specific app.
      ▼
Verification happens (§5) → payment_transactions.status → 'success'
      ▼
Next poll response says "success" → app shows order-confirmed screen,
      same as the existing COD confirmation flow.
```

### Expiry
Same principle as UPIPE's 30-minute window, but Anydrop's version
should be a `config_json` value per provider row (admin-editable, not
hardcoded) so it can be tuned without a code change. Suggested
default: **15 minutes** — long enough to switch devices, screenshot,
open a UPI app and pay; short enough that a stale QR isn't sitting
around confusing an admin reviewing pending payments an hour later.
On expiry: `payment_transactions.status = 'failed'`, `raw_response_json`
notes `"reason": "expired"`, customer app shows "QR expired — start
over," same UX shape as UPIPE's `expired` status.

---

## 3. On-screen instructions (payment screen copy)

Because there is no deep link, the instructions carry the whole UX —
this is the one place this design leans hardest on doc/UI copy instead
of a button doing the work. Draft copy for the customer app:

1. **Screenshot this QR**, or open your bank/UPI app on **another
   device** and scan it directly from this screen.
2. Open **any** UPI app — Paytm, PhonePe, Google Pay, BHIM, or your
   bank's app.
3. Scan the QR (from the screenshot or straight off this screen) and
   pay the exact amount shown: **₹{amount}**.
4. Come back here — this screen checks automatically every few
   seconds. Don't close the app.
5. Once payment is confirmed you'll see the order-confirmed screen
   automatically. No need to submit anything unless asked.

(§5 covers the case where step 5 doesn't self-resolve and a UTR input
has to appear — the copy above should be step-5-conditional in the
actual UI, not shown up front, to keep the common-case flow simple.)

---

## 4. Verification polling contract

`GET /customer/payment/upi/verify?order_id=...` — **polled every 10
seconds** by the customer app, per the app owner's instruction (UPIPE
itself recommends 5s; 10s is Anydrop's deliberate choice — halves
request volume against a manual-review-backed status, where sub-5s
resolution latency was never realistic anyway since a human is doing
the confirming).

Response shape (mirrors UPIPE's status vocabulary where it fits
Anydrop's manual-only model):

| `status` | Meaning | App action |
|---|---|---|
| `initiated` | QR shown, no action yet | keep polling |
| `utr_pending_window` | Not enough time has passed to accept a UTR yet (mirrors UPIPE's `manual_not_allowed`) | keep polling, optionally show "you can also enter your UTR in {n}s" |
| `utr_available` | Enough time has passed — offer the optional UTR input (see §5) | keep polling; if customer submits a UTR, that's a separate `POST .../submit-utr` call |
| `utr_submitted` | Customer supplied a UTR, sitting in the admin's review queue | keep polling, show "verifying your payment" |
| `success` | Order confirmed | stop polling, go to order-confirmed screen |
| `failed` | Admin rejected, or genuinely never paid | stop polling, show retry option |
| `expired` | Past the expiry window with no resolution | stop polling, show "start over" |

The endpoint **must** read straight from `payment_transactions`
(source of truth, DB row an admin action or future auto-verifier
writes to) — never accept or trust anything the client claims about
its own payment status, per the same rule `PaymentProviderInterface.php`
already documents ("never mark a payment successful solely from a
client-side callback").

---

## 5. How "verification" actually happens (no live gateway = no webhook)

This is the one part of the flow that's structurally different from a
real gateway integration, and it needs to be said plainly: **Anydrop
has no bank/NPCI connection today.** There is no automatic signal that
fires the instant money lands in the admin's UPI account. So
"verification" at launch is **manual, by a human admin**, same as
UPIPE falls back to when there's no Paytm MID configured. Two
sub-flows, pick one as the v1 build (recommend the first — simpler,
fewer moving parts):

**A. Admin-checks-their-own-bank-app model (simplest)**
1. Admin panel gets a **"Pending UPI Payments"** queue: every
   `payment_transactions` row with status `initiated`/`utr_submitted`,
   showing amount, order code, internal txn ref, time created,
   customer-submitted UTR if any.
2. Admin glances at their own phone's UPI app / bank SMS, matches the
   amount + rough time, clicks **Approve** or **Reject** (reject needs
   a reason — mirrors `manual_action.php`'s `reject_reason`).
3. Approve → `payment_transactions.status = 'success'` →
   `orders.payment_status` updates → next customer poll returns
   `success`.

**B. Customer-submits-UTR model (UPIPE's actual pattern)**
Same as A, but the customer types in their 12-digit UTR (bank
reference number) after a short delay (UPIPE uses 5 minutes; Anydrop
should reuse that as the default, admin-configurable) via
`POST /customer/payment/upi/submit-utr`. This gives the admin
something concrete to match against instead of "amount + rough time,"
which matters once order volume is more than a handful a day. The
admin queue and approve/reject action are identical to model A either
way.

**Recommendation:** ship **B**, because it's what UPIPE's own source
already proves out end-to-end, and "amount + rough time only" (model
A) breaks down the moment two customers pay the same amount within a
few minutes of each other — a UTR removes that ambiguity for free.

**Explicitly future, not v1:** any form of automatic detection —
bank SMS parsing, a UPI-collect API, NPCI/PSP webhook integration.
All of these need either a real payment aggregator account or a
bank-specific integration Anydrop doesn't have today; flagged the same
way doc 19 §8 already flags Razorpay/Cashfree/webhooks as future work.

---

## 6. Data model — extends migration 39, doesn't replace it

`payment_providers` and `payment_transactions` (already in
`backend/sql/39_migration_payment_providers.sql`) stay as the base.
This design needs a few additions when it's actually built (listed
here for the *next* session that writes the migration — not created
now, per §"STATUS" at the top):

**`payment_providers.config_json` (UPIPE-pattern row) should hold:**
```json
{
  "upi_id": "merchant@upi",
  "payee_name": "Anydrop Foods",
  "qr_size": 300,
  "expiry_sec": 900,
  "utr_window_sec": 300,
  "utr_required": true
}
```

**`payment_transactions` needs 3 more columns:**
- `utr VARCHAR(12) NULL` — customer-submitted UTR, unique-constrained
  the same way UPIPE's own schema prevents UTR reuse across orders
  (`config/schema.sql` — worth mirroring that constraint exactly).
- `verified_by_admin_id BIGINT UNSIGNED NULL` — which admin
  approved/rejected, for the audit trail (`lib/audit.php` already
  logs admin actions elsewhere in this codebase — this should go
  through the same logger).
- `reject_reason VARCHAR(255) NULL`.

**`orders.payment_status`** keeps using whatever enum values it
already has (`pending`/`paid`/etc. — see `lib/orders.php`) — this
design writes to it the same way any other provider result already
would; no schema change needed there.

---

## 7. Admin Panel — new "Payment Gateways" module

Doc 19 §8 already calls for a **Payment Providers** admin screen
("mirrors the Email Providers screen: enable/disable, priority, test
mode"). This spec makes that concrete for the UPIPE-pattern row
specifically:

**`admin/payment-gateways.php`** — list + add/edit form
- Table: Name, Driver key, Active, Test mode, Priority, Updated at.
- Edit form for the UPIPE-pattern row exposes exactly the
  `config_json` fields from §6 as real inputs (UPI ID, payee name, QR
  size, expiry, UTR window, UTR required toggle) — not a raw JSON
  textarea, same UX bar as the rest of this admin panel.
- "Test mode" toggle (already in migration 39) should, when on, watermark
  the generated QR / instructions screen so nobody accidentally treats
  a test QR as a real payment request — same intent as `is_test_mode`
  starting `1` by default in the seed row.

**`admin/payment-pending.php`** — the manual verification queue from
§5. Reuses this codebase's existing Payout-approval UI pattern
(§6 of doc 19: "Pay Now → screenshot/UTR/amount/date/remarks →
notification") rather than inventing a new pattern — this is
structurally the same "human reviews evidence, approves or rejects"
shape already proven out for restaurant settlements.

Both screens gated on a permission key the same way `payment-restrictions.php`
piggybacks on `areas_view`/`areas_edit` today — migration 29's RBAC
seed will need a `payments_view`/`payments_edit` pair (or reuse an
existing key if one already fits) when this actually gets built.

---

## 8. API surface (contract only — not implemented this session)

**Customer-facing (`api/v1/customer/payment/upi/`):**
| Endpoint | Method | Purpose |
|---|---|---|
| `create-order.php` | POST | Generates QR + txn ref for an existing order |
| `verify.php` | GET | Polled every 10s — returns current status (§4) |
| `submit-utr.php` | POST | Optional, only if `utr_required`/window has opened |

**Admin-facing (`admin/api/payment/`):**
| Endpoint | Method | Purpose |
|---|---|---|
| `gateways.php` | GET/POST | List/update `payment_providers` rows |
| `pending.php` | GET | Pending queue for §5's manual review |
| `verify-action.php` | POST | Approve/reject a pending transaction |

All of these sit behind `PaymentService.php` on the backend side per
doc 19 §8's existing rule — **order processing code never talks to
`UpipeProvider` directly**, only through the service, so a future real
gateway swap (§9) touches zero caller code.

---

## 9. Future gateways (explicitly out of scope now, architecture already supports them)

Per migration 39's own comment and doc 19 §8: adding any of these
later is "a new class implementing `PaymentProviderInterface` + a new
`payment_providers` row" — no change to checkout, order processing, or
the customer-facing polling contract in §4 (a real gateway would just
make `verify.php` resolve to `success` faster, via a real webhook
instead of an admin click). Reserved for future integration, not
built now:

- **Razorpay** — full-service aggregator, supports UPI + cards +
  netbanking, real webhooks, auto-settlement.
- **Cashfree** — similar breadth, competitive UPI-specific pricing,
  commonly used by Indian food-delivery-style apps.
- **PhonePe Payment Gateway** (distinct from the PhonePe *deep link*
  UPIPE's SDK offers — this would be PhonePe's actual merchant
  gateway product, with real server-to-server verification).
- **Instamojo or PayU** — smaller/simpler alternative aggregators,
  worth evaluating if Razorpay/Cashfree's onboarding requirements
  (business docs, GST, etc.) turn out to be a blocker for Anydrop's
  current business setup.

None of these get a `payment_providers` row until the app owner
supplies real merchant credentials for one of them — same "stub until
real credentials exist" rule this codebase already applies to UPIPE
itself.

---

## 10. Where the UPIPE reference source lives in this repo

The UPIPE source the app owner supplied has been placed at:

```
docs/payment_reference/upipe_source/
```

(full folder as supplied — `upi/api/`, `upi/core/`, `upi/lib/phpqrcode/`,
`upi/panel/`, `upi/sdk/`, `upi/config/schema.sql`, plus the standalone
`if0_42149143_upi.sql` dump)

**This folder is reference material only:**
- It is **not** wired into the build (no `require`, no route, no
  `composer`/autoload entry points to it from anywhere in
  `backend/`).
- It is **not** meant to be deployed, run, or exposed on any public
  URL as part of Anydrop.
- Its value is: (a) `lib/phpqrcode/qrlib.php` is a decent
  battle-tested QR generator Anydrop's own `UpipeProvider::initiate()`
  can borrow (same library, used locally, not called over the network);
  (b) `api/verify_payment.php` and `api/manual_action.php` show a
  working version of exactly the state machine §5 describes, worth
  reading before writing Anydrop's own version; (c) `panel/dashboard.php`
  is a useful reference for what the §7 admin screens need to show.

When the real build session happens, pull logic/structure from here,
never a `require_once` pointing into this folder from live
`backend/` code.

---

## 11. Build order (once this spec is approved)

1. Migration: extend `payment_providers.config_json` shape (§6) +
   add the 3 `payment_transactions` columns (§6) — additive, no
   breaking change to the existing migration 39 tables.
2. Rewrite `UpipeProvider::initiate()` to actually generate a QR (own
   UPI ID from config, own QR lib — see §10) instead of the current
   "online payment unavailable" stub response.
3. Rewrite `UpipeProvider::verify()` to read the real
   `payment_transactions.status` instead of always returning `failed`.
4. Build the 3 customer APIs (§8).
5. Build `admin/payment-gateways.php` (§7).
6. Build `admin/payment-pending.php` + the approve/reject API (§7, §8).
7. Wire the customer-app checkout/payment screen: QR display, copy
   from §3, 10-second poll loop from §4.
8. Only after all of the above is live and working: revisit §9 as a
   separate, later project.

---

## Addendum — what actually got built, 2026-08-23 (same session)

Steps 1–6 of §11 above are done. Step 7 (customer-app UI wiring) is
sketched but not fully built — see §A4. File list:

```
backend/sql/40_migration_native_upi_payment_gateway.sql
backend/lib/payment/PaymentProviderInterface.php        (unchanged)
backend/lib/payment/ManualVerificationProviderInterface.php  (new)
backend/lib/payment/UpipeProvider.php                    (rewritten)
backend/lib/payment/PaymentService.php                   (new)
backend/api/v1/orders/payment-upi-create.php             (new)
backend/api/v1/orders/payment-upi-verify.php              (new)
backend/api/v1/orders/payment-upi-submit-utr.php          (new)
backend/admin/payment-gateways.php                        (new)
backend/admin/payment-pending.php                         (new)
backend/admin/_layout_head.php                            (2 nav entries added)
backend/.htaccess                                          (3 clean-URL rules added)
```

### A1 — the QR image plan changed: no server-side QR image at all

While pulling the UPIPE reference source in as promised (§10), its
own QR "generator"
(`docs/payment_reference/upipe_source/upi/lib/phpqrcode/qrlib.php`)
turned out to be **fake** — its `textToMatrix()` builds an image from
an MD5 hash of the input, not a real QR encoding (no Reed-Solomon
error correction, no actual data payload). Its own comment even
half-admits it ("not spec-perfect, but scannable for UPI") — it is
not scannable at all; it's noise that happens to look QR-shaped.

Rather than build Anydrop's real flow on top of a QR generator that
can never be scanned by an actual phone, and given this sandbox has
no network access to pull in a real PHP QR library, the design
changed to: **the backend never generates a QR image.** `initiate()`
returns the raw `upi_link` string (`upi://pay?pa=...`), and the
**customer app renders the actual scannable QR client-side** using a
real, offline, battle-tested library — on Android, `com.google.zxing:core`
(ZXing), which needs zero network access and is what most production
UPI-collection apps already use for exactly this. This is arguably
better than the original plan anyway: one less server round-trip, one
less thing that can be a stale/wrong image.

**Action needed when the customer app gets wired up (§A4):** add
`implementation 'com.google.zxing:core:3.5.3'` (or current stable) to
`customer/app/build.gradle`, encode `upi_link` with
`new QRCodeWriter().encode(upiLink, BarcodeFormat.QR_CODE, size, size)`,
render the resulting `BitMatrix` to a `Bitmap`.

### A2 — admin surface is server-rendered pages, not a JSON API

§8's original draft sketched `admin/api/payment/*.php` JSON endpoints.
The actual admin panel in this codebase is 100% session-authenticated,
server-rendered PHP (see `admin/_bootstrap.php`'s own doc-comment on
why — no JS build step, works by pointing a browser at it). The real
build follows that existing convention instead:
`admin/payment-gateways.php` and `admin/payment-pending.php` are full
pages with POST forms (CSRF-protected, same as every other admin
page), not a separate JSON API layer. Both are gated on the
`payment_providers_manage` permission key — which, usefully, migration
29 had **already seeded** back when doc 19 was written, so no new RBAC
migration was needed.

### A3 — `payment_transactions.status` gained one more ENUM value

Migration 39's ENUM (`initiated`/`success`/`failed`/`refunded`) didn't
have a slot for "customer submitted a UTR, waiting on admin review."
Migration 40 widens it to add `utr_submitted`. An admin **rejection**
deliberately does *not* get its own ENUM value — it's stored as
`failed` + `reject_reason` populated, so nothing that already only
checks for `success`/`failed` needs to change.

### A4 — customer-app UI: built in a later session (see recall.md item 24)

`UpiPaymentActivity.kt` (+ layout/strings + `CheckoutActivity.kt`
wiring) was written in a subsequent session — QR render (ZXing, §A1),
instructions copy from §3, the 10-second poll loop against
`GET .../payment/upi/status`, and UTR submission are all in place.
Still 🟡 not device/build-verified, same standing sandbox limitation.

### A4b — "Cancel and pay by COD" button — real endpoint added (2026-08-23, follow-up)

The button originally just showed its own label back as a toast and
left the payment screen — the order stayed `payment_method='upi'`
the whole time, so the customer's order was actually still awaiting a
UPI payment with no on-screen indication of that. Fixed with a real
backend endpoint: `POST /orders/{id}/payment/switch-to-cod`
(`backend/api/v1/orders/payment-switch-cod.php`) — reuses the exact
same `get_effective_payment_restrictions()` / `is_payment_method_
allowed_in_area()` (migration 37) and `get_effective_cod_rule()` /
`evaluate_cod_eligibility()` (migration 35) pair `orders/create.php`
calls, so a customer can never switch into a COD order that a fresh
COD checkout in the same area/circumstance couldn't have created.
Voids any outstanding `payment_transactions` row
(`status='failed', reject_reason='switched_to_cod'`) so it drops out
of `admin/payment-pending.php`'s queue. `UpiPaymentActivity.kt` now
shows a real confirm dialog and calls this endpoint instead of faking
success; surfaces `cod_not_eligible` / `payment_method_not_allowed`
rejections with their `reason` inline rather than pretending the
switch always works. New `ApiService.switchOrderToCod()` + `.htaccess`
clean-URL rule added to match. **Not device/build-verified** — same
standing sandbox limitation as everything else in this doc.

### A5 — anti-spoof hardening (app owner request, 2026-08-23)

Explicit ask: *"koi payment ko spoof na kar sake, koi loophole na
nikaal le"* — closed two gaps found on a second pass, both in
`sql/41_migration_upi_antifraud_hardening.sql`:

1. **UTR-guessing / endpoint hammering.** `submitUtr()` previously had
   no cap on retries — a customer could hammer the endpoint with
   random 12-digit numbers hoping to collide with a real UTR already
   in the table (which the UNIQUE index would then reject, but only
   after a DB round-trip per guess). `payment_transactions.utr_attempts`
   now caps each transaction at 8 attempts (`UpipeProvider::MAX_UTR_ATTEMPTS`),
   counted whether the attempt was valid or not. Exhausting it doesn't
   auto-fail the transaction — a genuine customer who mistyped a few
   times shouldn't lose a real payment — it just stops accepting more
   guesses; an admin can still resolve it manually.

2. **Amount tampering via an editable UPI-app amount field.** Some UPI
   apps let the payer edit the amount before confirming, even when the
   QR pre-fills one. The original admin-approve flow just trusted
   whatever the admin eyeballed. Approval now requires the admin to
   type in the exact amount their own bank/UPI app shows
   (`amount_confirmed`), and `UpipeProvider::adminDecision()` **refuses**
   the approval outright if it doesn't match the order's own
   `payment_transactions.amount` (server-side, never client input) —
   see `admin/payment-pending.php`'s updated Approve flow. A genuine
   short payment has to be rejected here and resolved outside the
   system; this design doesn't model partial payments.

**Residual risk, documented rather than "solved"** — this manual
model has one gap that can't be closed without a real bank/NPCI
integration: a customer could theoretically submit a UTR that belongs
to someone else's real (unrelated) payment before that real payer
submits it themselves, effectively squatting on it (the UNIQUE
constraint then blocks the real payer's own submission). The mitigation
is procedural, not technical — `admin/payment-pending.php` now says
explicitly to check the UTR against the *time and amount* on the
actual bank statement, not just accept any 12-digit string that looks
plausible. This is the same limitation every manual-UTR system has
(including the UPIPE reference source itself) until a real gateway
webhook (§9) replaces it.
