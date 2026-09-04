# Handover — continue from here (2026-08-24)

App owner reported 4 issues in one message. Below is what's **done**
(code changed, not yet built/tested on device — no Android SDK/Gradle
in this sandbox, same standing limitation as every prior session) and
what's **still pending**, in priority order.

---

## ✅ Done this session

### 1. COD/UPI disabled options now show a specific reason, not generic text
Root cause: `backend/api/v1/customer/cod-eligibility.php` (the finer
per-customer rule — min prepaid orders, max COD amount, daily cap) was
already fully built server-side but **never called from the app** —
`Models.kt`'s own old comment said so explicitly ("isn't wired into
the app yet"). The app only ever called the coarser
`payment-methods.php` (area-level allow/deny), so a customer who was
in a COD-enabled area but personally ineligible (e.g. new customer,
needs N prepaid orders first) saw COD as enabled and only found out
via a 422 at order submission.

Wired in:
- `customer/.../network/ApiService.kt` — new `getCodEligibility()` call
- `customer/.../network/Models.kt` — new `CodEligibilityResult` / `CodRule`
- `customer/.../ui/checkout/CheckoutActivity.kt`:
  - `loadPaymentMethods()` now also calls cod-eligibility (only when
    the area allows COD at all — no point checking a rail the area
    doesn't offer)
  - new `refreshCodEligibility()` helper, also re-run from
    `renderBill()` once the real grand total is known (the amount-cap
    check needs it; on the very first paint it's still null)
  - `applyPaymentMethodRestrictions()` now grays out COD when *either*
    the area check or the per-customer check fails, and prefers the
    specific reason string (e.g. "Available after 3 prepaid orders")
    over the generic "not available here" one

**Not done / worth a follow-up:** UPI has no equivalent finer
per-customer rule today (only the area-level check) — if the app
owner wants something like "UPI unavailable, minimum ₹X order" that's
a new backend rule to design, not a wiring gap like COD was.

### 2. Admin banners not showing up for an already-open app
Root cause: `HomeActivity.loadPromoBanners()` only ever ran once, from
`onCreate()`. Home is a long-lived Activity — customers background/
foreground it constantly rather than relaunching — so a banner an
admin adds mid-session never appeared until the customer force-closed
and reopened the app.

Fix: `HomeActivity.kt` now also calls `loadPromoBanners()` from
`onResume()`, throttled to once per 60s (`lastBannerLoadAtMs` /
`BANNER_REFRESH_THROTTLE_MS`) so rapid app-switching doesn't spam the
endpoint — roughly matches `promo-banners.php`'s own
`Cache-Control: max-age=120`.

**Scope note:** app owner also mentioned "other popup and etc" not
showing new content. No other admin-pushed popup/announcement feature
exists in this codebase yet (searched for popup/announcement tables
and endpoints — none found). If they mean something specific, ask
what screen/feature before building anything new.

---

## 🔴 Not done — highest priority for next session

### 3. Suspended restaurant/customer keeps full access until token expires (30 days)
This is the app owner's first-listed complaint and the biggest one —
**root cause confirmed, fix not yet written.**

`backend/lib/auth.php`'s `get_authenticated_owner()` only validates
that the Bearer token exists and hasn't expired — it **never re-checks
`restaurants.status` / `customers.is_active`** on each request. Login
endpoints (`restaurant-login.php`, `customer-verify-otp.php`) do check
status, but that's only at login time. So: admin suspends a restaurant
or customer who is already logged in (has a valid token) → they can
keep placing orders / editing menu / everything, completely normally,
until that token's 30-day expiry — the suspension has no practical
effect for up to a month.

**What needs to happen:**

1. **Migration** — add `suspension_reason VARCHAR(255) NULL` to both
   `restaurants` and `customers` tables (new `sql/4X_migration_*.sql`,
   follow the numbering convention — check the highest existing number
   under `backend/sql/` first, don't assume 44/45 are still the latest
   since this zip may have moved on).
2. **`lib/auth.php`** — `get_authenticated_owner()` (or a wrapper
   `require_auth()` calls) needs to look up the current
   `restaurants.status` / `customers.is_active` row for the resolved
   owner_id on *every* authenticated request, and immediately
   `respond_error('account_suspended', 403, ['reason' => ...])` if
   suspended — not just at login. This one change is what actually
   fixes "restaurant could still edit everything" and "customer wasn't
   logged out" — everything downstream (both apps) is UI plumbing for
   this same error code.
   - Careful: this runs on *every* API call, so keep the extra query
     cheap (indexed lookup by primary key, already how `id` is looked
     up elsewhere) — don't turn every request into a heavier join.
   - Decide whether `pending`/`rejected` restaurant statuses should
     also be force-logged-out this same way, or whether those only
     ever matter at login (restaurant-login.php already blocks both at
     login; a *currently logged in* restaurant can't retroactively
     become `pending`, only `suspended`, so this is probably academic
     — confirm before spending time on it).
3. **Login endpoints** — `restaurant-login.php`'s existing
   `account_suspended` response and `customer-verify-otp.php`'s should
   both start echoing back `suspension_reason` in the error `data`
   payload once #1 exists, same as the mid-session check will.
4. **Admin panel** — `admin/restaurants.php` and `admin/customers.php`
   suspend actions currently just flip a status/flag with no reason
   captured anywhere. Add a reason text input to the suspend
   confirm (both already have a `data-confirm-*` JS-driven confirm
   dialog pattern — extend it with a text field, or a simple prompt())
   and save it into the new `suspension_reason` column. Clear the
   reason on reactivate.
5. **Both Android apps** — need a global way to catch
   `account_suspended` from *any* API response (not per-screen), since
   it can now come back from literally any authenticated call once #2
   ships. Cleanest hook: an OkHttp response interceptor in each app's
   `ApiClient.kt` (`restaurant/.../network/ApiClient.kt` and
   `customer/.../network/ApiClient.kt`) that peeks the response body
   (`response.peekBody(...)`, so it doesn't consume the stream
   Retrofit still needs) for `403` + `"code":"account_suspended"`, and
   on match:
   - Clears the stored token (`TokenManager`)
   - Fires a simple event (a static `SharedFlow`/callback list works
     without new DI) that a base/main Activity in each app observes
   - That observer force-navigates to the Login screen and shows the
     reason (`AlertDialog` or a dedicated "Account suspended" screen)
   - Do this once per app — don't scatter suspension-handling
     try/catch across every individual screen's API call site.

This is the one piece of real backend+app logic left from the app
owner's list — everything else this session was either already-solved
(UPI notification timing, from the prior session) or a smaller wiring
fix (#1/#2 above).

---

## Standing reminder (carried from every prior handover in this repo)
Nothing in this project has been compiled/run on a device in this
sandbox — no Android SDK/Gradle available here. Every `.kt` change
across all sessions (this one included) is manually verified only
(import lists checked, braces balanced, referenced `binding.X` IDs
cross-checked against layouts) — that is not a substitute for a real
build. Get a Gradle build confirmed before trusting any of this is
actually shippable.
