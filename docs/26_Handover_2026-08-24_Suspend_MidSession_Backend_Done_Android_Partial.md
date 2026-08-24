# Handover — continue from here (2026-08-24, session 2)

Continuation of doc 25's item 3 ("Suspended restaurant/customer keeps
full access until token expires"). Backend is fully done. Android is
half-done — customer app's interceptor is wired, restaurant app's is
not, and **neither app's Activity observer exists yet**, so nothing
user-visible ships from this session until that's finished.

Same standing limitation as every prior session: no Android SDK/Gradle
in this sandbox, so every `.kt` change below is manually verified only
(import lists checked, braces balanced) — not a substitute for a real
build.

---

## ✅ Done this session — Backend (fully working end to end)

### Migration
`sql/46_migration_customer_suspension_reason.sql` — adds
`customers.suspension_reason VARCHAR(255) NULL`. Same idempotent
CONTINUE-HANDLER-for-1060 pattern as migration 25. **Not yet run
against the live DB** — run this before anything else or the new
`lib/auth.php` code below will error on the missing column.

Deliberately did **not** add an equivalent column to `restaurants` —
it already has `rejection_reason` (migration 25), already reused by
`admin/restaurants.php`'s suspend action and already read by
`restaurant-login.php`. A second column there would just be a second
source of truth for the same thing.

### `lib/auth.php`
`require_auth()` now re-checks `restaurants.status` /
`customers.is_active` (+ `deleted_at IS NULL` for both — catches
soft-deleted accounts too, a case doc 25 didn't call out but falls out
of the same fix for free) on **every** authenticated request, not just
at login. Returns `403 account_suspended` with `{"reason": ...}` in
`data` the moment it fails. One indexed PK lookup per request, kept
deliberately minimal.

`pending`/`rejected` restaurant statuses are *not* separately checked
here — confirmed the doc 25 reasoning holds: login already blocks
both, and a restaurant that has a live token can only have reached its
current state via `approved -> suspended`, never retroactively back to
`pending`/`rejected`.

### Login endpoints
`restaurant-login.php` and `customer-verify-otp.php` both now echo
`reason` in the `account_suspended` error payload (pulled from
`rejection_reason` / `suspension_reason` respectively), matching what
the mid-session check above sends.

### Admin panel
`admin/customers.php`'s suspend action now requires a reason (same
`data-confirm-*` + required `<textarea>` pattern `admin/restaurants.php`
already uses for restaurant suspend/reject), stores it in the new
`suspension_reason` column, clears it on reactivate, and shows it in
the customer detail modal. `admin/restaurants.php` needed no changes —
it already captured a reason via `rejection_reason`.

---

## 🟡 Partially done — Android

### Customer app — interceptor done, observer NOT done
- `customer/.../network/SessionEvents.kt` (new) — a static
  `SharedFlow<String?>` event bus. `emitAccountSuspended(reason)` uses
  `tryEmit`, not suspend `emit`, because it's called from an OkHttp
  interceptor thread, not a coroutine.
- `customer/.../network/ApiClient.kt` — new `suspensionInterceptor`,
  added to the `OkHttpClient.Builder()` chain after `authInterceptor`.
  Peeks (`response.peekBody(2048)`, never `.body()`/`.string()` on the
  real body — that would consume the stream Retrofit still needs) any
  `403` for `{"error":"account_suspended"}`, and on match clears
  `TokenManager` and calls `SessionEvents.emitAccountSuspended(reason)`.
  Wrapped in try/catch so a parse failure never blocks the response
  from reaching the normal call site.

**Still missing:** nothing observes `SessionEvents.accountSuspended`
yet. Need to add, in `HomeActivity.onCreate()` (same "Home is the one
long-lived Activity everything funnels through" reasoning doc 25 used
for the banner-refresh fix):
```kotlin
lifecycleScope.launch {
    SessionEvents.accountSuspended.collect { reason ->
        // clear any in-memory UI state if needed, then:
        startActivity(Intent(this@HomeActivity, LoginActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK))
        // show `reason` via AlertDialog on the Login screen (pass as
        // an intent extra) or a Toast right before navigating — either
        // is fine, just don't lose it across the navigate.
    }
}
```
Until this exists, the customer app silently logs a suspended user out
of their *token* (API calls will keep 403ing) but never navigates them
anywhere or tells them why — worse than doing nothing, since they'll
just see broken screens. **Don't ship the interceptor without the
observer.**

### Restaurant app — neither piece done
- `restaurant/.../network/SessionEvents.kt` (new) — same shape as the
  customer app's, created but **nothing calls `emitAccountSuspended`
  yet**.
- `restaurant/.../network/ApiClient.kt` — **not yet touched.** Needs
  the identical `suspensionInterceptor` the customer app's `ApiClient.kt`
  now has (copy the pattern, swap package/imports — `TokenManager` and
  `SessionEvents` are both already in scope under
  `com.anydrop.restaurant.*`).
- `MainActivity` (restaurant app's equivalent of `HomeActivity`) needs
  the same observer as above, navigating to its `LoginActivity`.

---

## Suggested order for next session
1. Run migration 46 against the DB.
2. Copy the customer app's `suspensionInterceptor` into the restaurant
   app's `ApiClient.kt`.
3. Wire both observers (`HomeActivity` for customer,
   `MainActivity` for restaurant) — this is the part that actually
   makes any of this visible to a user.
4. Only then is item 3 from doc 25 actually shippable. Get a Gradle
   build confirmed before trusting any of it.
