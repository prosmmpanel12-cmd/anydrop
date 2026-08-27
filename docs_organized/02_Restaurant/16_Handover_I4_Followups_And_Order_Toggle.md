# Handover — I4 follow-ups + new: Restaurant "pause taking orders" toggle

**Status: ✅ Built this session (2026-08-13), NOT tested/Gradle-built yet.**
Both Part A and Part B below are done, plus the Part B "Temporarily
unavailable" badge that point 3's build-order section left as an open
question — see `docs/Status.md`'s 2026-08-13 entry for the full summary
of what was built and what's still deliberately deferred (the
reason/ETA message for a paused restaurant). I4 itself was already done
— see `15_Handover_I4_Scheduled_Orders.md`.

---

## Part A — I4 leftover items (small, low priority)

### A1. Customer App — `OrderStatusActivity` doesn't show `scheduled_for`
`Order.scheduledFor` (customer app `network/Models.kt`, line ~458) is
already populated by the API for every order — nothing on the backend
needed. Just needs a line added to whatever layout `OrderStatusActivity`
renders order details on: something like "Scheduled for 8:30 PM" when
`scheduledFor != null`, nothing extra when it's a normal ASAP order. Reuse
the same `SimpleDateFormat("yyyy-MM-dd HH:mm:ss")` → `SimpleDateFormat("h:mm a")`
pattern already duplicated in `RestaurantDetailActivity` and
`CheckoutActivity` — a third copy is a good trigger to finally factor it
into one shared formatter, per both docs' standing note.

### A2. Restaurant App — order list/detail don't surface `scheduled_for` at all
Confirmed by reading `restaurant/app/.../network/Models.kt`: the
Restaurant App's `Order` data class **doesn't have a `scheduledFor` field
yet** (unlike the Customer App's, which does) — even though
`format_order()` in `backend/lib/orders.php` already returns `scheduled_for`
on every order response, restaurant-side included. So this is two steps:
1. Add `@SerializedName("scheduled_for") val scheduledFor: String? = null`
   to `Order` in the Restaurant App's `Models.kt`.
2. Surface it in `OrderAdapter.kt` (list row — a small badge/line so a
   scheduled order doesn't look identical to an ASAP one sitting in the
   queue) and `OrderDetailActivity.kt` (detail screen). No design decision
   made yet on exact placement/styling — just needs to exist somewhere
   restaurant staff will actually see it.

---

## Part B — New: Restaurant "pause taking orders" toggle

**What the app owner asked for this session:** a restaurant should be
able to mark itself as not accepting orders right now, and switch back
whenever ready — independent of its fixed `opening_time`/`closing_time`
schedule (e.g. restaurant is open per its hours, but the kitchen is
slammed and wants new orders paused for a while).

**Good news, confirmed by reading the schema and code — most of the
plumbing already exists, just unused:**
- `restaurants.operational_status` — already an
  `ENUM('open','closed','busy','vacation','temp_closed','admin_disabled')`
  column (`01_Database_Schema.md`), currently only ever set by direct
  DB/seed access, never by the app.
- `restaurants/list.php`'s `is_open_now` computation **already gates on
  `operational_status === 'open'`** (alongside the opening/closing-hours
  and working-day check) — confirmed at the exact line:
  ```
  if ($r['operational_status'] === 'open' && $r['opening_time'] && $r['closing_time']) { ... }
  ```
  So flipping this column already hides/shows a restaurant as open on the
  Customer App's Home/list screen with **zero backend changes needed
  there** — the read side is done.

**What's actually missing (confirmed, not assumed):**
1. **No restaurant-facing endpoint to change it.** Restaurant App's
   `ApiService.kt` only has order accept/reject/status-update and
   dashboard calls — nothing touches `operational_status`. Needs a new
   endpoint, e.g. `POST /restaurant/status-update.php` (restaurant auth),
   body `{ "operational_status": "open" | "busy" | "temp_closed" | ... }`,
   updates the row, returns the new value.
2. **No restaurant-facing UI to flip it.** `DashboardActivity.kt` is the
   natural home — a simple "Accepting orders" ON/OFF switch near the top,
   calling the new endpoint on toggle.
3. **`orders/create.php` doesn't check `operational_status` at all right
   now** — confirmed by grep, no reference to it anywhere in
   `backend/lib/orders.php` or `orders/create.php`. This matters: even
   once the toggle exists, a customer could still get an order through
   while the restaurant is paused (stale cached restaurant list, a deep
   link, a scheduled order placed earlier that's now due, etc.) unless
   `create.php` is updated to reject with a 422
   (`restaurant_not_accepting_orders` or similar) whenever
   `operational_status !== 'open'`. **This should ship together with the
   toggle, not as a follow-up** — a pause that only hides the restaurant
   from a list but doesn't actually block the order isn't a real pause.
4. **Open scope question — not decided yet:** does "paused" need a
   reason/message customers see (e.g. "Kitchen too busy, back around
   8 PM"), or is a plain ON/OFF with no explanation enough? Recommend
   starting with plain ON/OFF (maps directly to the existing `busy` enum
   value, ships faster) and adding a reason/ETA message later only if
   actually wanted — flagging so it's a conscious call, not a default.

**Suggested build order, if you confirm this scope:**
1. Backend: `restaurant/status-update.php` endpoint + the `orders/create.php`
   422 guard (must ship together, per point 3 above).
2. Restaurant App: toggle switch on `DashboardActivity` + wiring to the
   new endpoint.
3. Worth deciding at the same time: what the Customer App shows for a
   paused restaurant. Today it would either vanish entirely (if the
   customer has the `open_now` filter on) or just show as "closed" with
   no explanation (if not filtered) — neither is wrong, but a dedicated
   "Temporarily unavailable" badge might read better than plain "closed"
   since the restaurant could resume any minute. Flagging, not deciding —
   simplest is to ship with plain "closed" behavior first and revisit if
   it reads confusingly in practice.
