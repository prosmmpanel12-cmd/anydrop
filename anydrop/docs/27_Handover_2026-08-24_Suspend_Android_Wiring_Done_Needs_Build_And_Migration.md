# Handover — continue from here (2026-08-24, session 3)

Continuation of doc 26. Finished the Android side of item 3
("Suspended restaurant/customer keeps full access until token
expires") — steps 2 and 3 of doc 26's suggested order are done. Steps
1 and 4 are **not** done and need a real machine to do them; see below.

Same standing limitation as every prior session: no Android SDK/Gradle
and no DB access in this sandbox, so everything below is manually
verified only (import lists checked, braces/parens counted) — not a
substitute for a real build or a real migration run.

---

## ✅ Done this session — Android (both apps, both pieces)

### Restaurant app — interceptor added, observer wired
- `restaurant/.../network/ApiClient.kt` — added `suspensionInterceptor`,
  copied from the Customer app's `ApiClient.kt` with only the package
  changing (`com.anydrop.restaurant.*`). Same `peekBody(2048)` +
  try/catch shape, same `tokenManager.clear()` +
  `SessionEvents.emitAccountSuspended(reason)` on match. Added to the
  `OkHttpClient.Builder()` chain after `authInterceptor`, same position
  as the Customer app.
- `restaurant/.../ui/main/MainActivity.kt` — added a
  `lifecycleScope.launch { SessionEvents.accountSuspended.collect { ... } }`
  in `onCreate()`, right after the existing not-logged-in check. Calls
  the existing `goToLogin()` helper, which now takes an optional
  `reason: String?` param and puts it on the `Intent` as
  `LoginActivity.EXTRA_SUSPENSION_REASON`. The plain not-logged-in path
  still calls `goToLogin()` with no argument, unchanged.
- `restaurant/.../ui/login/LoginActivity.kt` — added the
  `EXTRA_SUSPENSION_REASON` constant and an `onCreate()` check that
  shows the reason (or a generic fallback if null) via the existing
  `InAppNotifier.Type.ERROR` toast, same pattern already used for
  `friendlyError()` on this screen.

### Customer app — observer wired (interceptor was already done)
- `customer/.../ui/home/HomeActivity.kt` — added the same
  `SessionEvents.accountSuspended.collect` block in `onCreate()`, as
  the very first thing after `setContentView()` (ahead of the cart
  restore) so it's live before anything else in this Activity can run.
  Navigates to `LoginActivity` with `FLAG_ACTIVITY_NEW_TASK or
  FLAG_ACTIVITY_CLEAR_TASK`, exactly as doc 26 sketched, plus
  `EXTRA_SUSPENSION_REASON` as an intent extra. No token clear needed
  here — `ApiClient`'s interceptor already did that before emitting.
- `customer/.../ui/login/LoginActivity.kt` — same
  `EXTRA_SUSPENSION_REASON` constant + `InAppNotifier` toast as the
  Restaurant app, for consistency between the two apps.

Both apps now do the identical thing end to end once wired: any
authenticated call 403ing with `account_suspended` → interceptor drops
the token → the long-lived Activity's observer force-navigates to
Login → Login shows the reason (or a generic message) in a toast.

---

## 🔴 Not done — needs a real machine, not this sandbox

1. **Migration 46 still hasn't been run against the live DB.**
   `sql/46_migration_customer_suspension_reason.sql` from doc 25/26.
   Nothing above depends on it directly (the Android changes only read
   `data.reason` off an existing response shape), but the *backend*
   code from doc 26 (`lib/auth.php`, `customer-verify-otp.php`,
   `admin/customers.php`) will error on the missing column until this
   runs. Run this first, before manually testing any of doc 26's
   backend pieces or this session's Android pieces together.

2. **No Gradle build has confirmed any of this compiles.** Every `.kt`
   file touched across doc 26 and this session was hand-verified only
   (imports present, no obviously-mismatched types, braces/parens
   balanced). That's a floor, not a substitute — get both apps through
   an actual `./gradlew assembleDebug` (or Android Studio build) before
   trusting this is shippable. Particular things worth eyeballing in a
   real IDE first, since they're the most likely spot for a typo this
   method can't catch:
   - `restaurant/.../network/ApiClient.kt`'s new `Gson`/`TypeToken`
     imports and the `envelopeType` field — same pattern as the
     Customer app's `ApiClient.kt`, which was presumably built at some
     point, but this file's version of it hasn't been.
   - Both `LoginActivity.kt` files' new `companion object` blocks —
     straightforward Kotlin, but worth a glance.

Once both of those are done, item 3 from doc 25 is fully shippable:
backend done (doc 26), Android done (this session), pending only the
migration run and a build to confirm.
