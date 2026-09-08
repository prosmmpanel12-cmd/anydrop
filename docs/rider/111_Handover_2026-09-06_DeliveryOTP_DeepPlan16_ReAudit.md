# Handover — Delivery OTP re-audit, deep-plan §16 (audit + gaps 1-2 built)

Session date: 06 Sep 2026, continuing from doc 110's fork (owner picked
the OTP re-audit rather than a build/DB pass or Live Location
re-verification). First part was a read-only trace of the existing OTP
flow against deep-plan §16's own wording and PENDING.md §21's
checklist. Owner then picked gap 1 (admin unlock/override), then gap 2
(admin UI for `otp_max_attempts`) to actually build, one at a time —
see their own sections below. Gaps 3-4 remain unbuilt findings only.

## What was traced

- `api/v1/orders/create.php` — OTP generation. Uses `random_int()`
  (CSPRNG, not `rand()`/`mt_rand()`), correctly bounded to
  `otp_length` digits via `str_pad` on both ends of the range, only
  generated when `otpRequired` (UPI, or COD if
  `otp_required_for_cod` is on).
- `api/v1/orders/track.php` — customer-facing exposure. Only returns
  the real code when `status IN ('rider_assigned', 'out_for_delivery')
  AND delivery_otp IS NOT NULL`. Confirmed this isn't a gap around
  `picked_up`: `orders-pickup.php`'s own kdoc confirms `picked_up` is
  never a resting `orders.status` value — pickup confirmation jumps
  straight to `out_for_delivery` in one DB write (deep-plan §11's own
  "avoid an unnecessary extra tap" call), so there's no order-lifecycle
  window where the customer's copy of the OTP would incorrectly
  disappear.
- `api/v1/rider/orders-current.php` / `orders-detail.php` — rider-facing
  exposure. Both deliberately return only `delivery_otp_required`
  (boolean), never the code itself — correct, since the rider is
  supposed to be told the code by the customer, not read it off their
  own screen.
- `api/v1/rider/orders-deliver.php` — verification endpoint. Ownership
  check (`rider_id = :rider_id` in the same query), state check
  (`status = 'out_for_delivery'`), max-attempts check before the
  comparison (so a locked order rejects even a now-correct guess, per
  §16's own "lock after max attempts" wording), atomic
  `otp_attempts = otp_attempts + 1` column increment (safe under
  concurrent requests even though the in-PHP `attempts_remaining`
  number read earlier is a stale snapshot), and the actual
  delivered-transition guarded by
  `WHERE ... AND status = 'out_for_delivery'` with a `rowCount() !== 1`
  rollback — so a second concurrent call (or a retry after a client
  timeout) can't double-fire the status flip, the COD ledger entry, or
  the rider earning entry.
- `otp_max_attempts` setting: confirmed seeded in `01_schema.sql`
  and read consistently via `get_setting('otp_max_attempts', 3)` by
  every OTP-checking endpoint in the codebase (auth OTP flows too, not
  just delivery) — one shared knob, not a duplicated one.
- `admin/orders.php` — confirmed the OTP + attempt count are visible
  to admins (click-to-reveal), but traced every `form_action` in that
  file and found no action that touches `otp_attempts` or forces a
  delivery.
- Checked `bugs.md`/`recall.md`/`PENDING.md` for any already-tracked
  finding covering the gaps below before treating them as new —
  PENDING.md §21 still shows the whole section as an un-checked
  `- [ ]` list (stale, same as every previous handover's own callout of
  that file), so it doesn't already capture these.

## Gap 1 — now built

`admin/orders.php` gains two new gated (`orders_manage`) override
actions, offered only when an order is actually OTP-locked
(`delivery_otp` set, `status = 'out_for_delivery'`,
`otp_attempts >= otp_max_attempts`) — never shown otherwise, so this
can't be reached for an order that just hasn't been attempted yet:

- **Reset attempts** (`otp_reset_attempts`) — sets `otp_attempts = 0`
  and nothing else. No status change, no ledger/earning writes, no
  reason required (it's non-destructive — it just gives the rider more
  real tries at the real code). Covers "customer re-reads/re-sends the
  correct code, rider just mistyped it three times."
- **Force-deliver** (`force_deliver`) — the actual bypass: flips status
  to `delivered`, sets `delivered_at`, flips `payment_status` to
  `'paid'` for COD (identical to `orders-deliver.php`'s own success
  path), fires the SAME `record_cod_order_ledger_entry()` /
  `record_rider_cod_collected()` / `record_rider_delivery_earning()`
  calls in the same transaction, and sends the same customer
  "delivered" + rider "earning posted" notifications after commit —
  so taking the admin path instead of the rider-app path never
  silently drops money or notifications. Deliberately does NOT set
  `otp_verified_at` (it wasn't OTP-verified), and records
  `'Force-delivered (OTP bypassed): <reason>'` on the
  `order_status_history` row specifically so this is visually
  distinguishable from a normal rider-confirmed delivery in the
  order's own timeline — covers gap 3's forensics concern for this one
  action specifically, even though gap 3 itself (see below) is still
  open for ordinary wrong-attempt events. Requires a reason (mirrors
  Force-Cancel's own required-reason pattern), and the WHERE-guarded
  UPDATE + `rowCount() !== 1` rollback check is copied from
  `orders-deliver.php` so a lost race can't double-fire the ledger/
  earning writes here either.

Both actions write `write_audit_log()` entries that include a
`rider_id` key (`order_otp_attempts_reset` / `order_force_delivered`)
— unlike `order_force_cancelled`, which doesn't carry one. This means
these two new actions will automatically surface on that rider's own
Audit Trail (`rider-detail.php`, doc 109) with no changes needed on
that page — its `JSON_UNQUOTE(JSON_EXTRACT(...))` filter picks up any
matching key. Worth noting doc 109's own claim that only three files
"ever write an audit entry with a rider in scope" is now one file
wider, going forward.

Reused `record_rider_delivery_earning()` and the COD ledger writers
exactly as-is — no changes to either file, no new function needed. Not
build/DB-verified (same standing sandbox limitation as every session
before this one); manual PHP-segment-aware brace/paren check on the
touched file came back balanced.

## Gap 2 — now built

New dedicated page `admin/otp-settings.php`, gated on `settings_manage`
(same permission every other single-topic "Settings" nav page already
uses — `route-recalc-settings.php`, `directions-settings.php`,
`fcm-settings.php` — no new RBAC migration needed), wired into the nav
under the existing "Settings" group right after Route Recalc Settings.
One field: max wrong attempts before lockout, bounded 1-20 (1 disallowed
below that — 0 would lock on the very first check before any real
attempt), default 3, with a Save and a Reset-to-default action, both
audit-logged (`otp_settings_updated` / `otp_settings_reset`).

Deliberately scoped to just `otp_max_attempts` — not bundled with
`otp_length` or `otp_required_for_cod`, which docs/111 never flagged as
missing UI (only `otp_max_attempts` was confirmed to have zero
admin-panel exposure anywhere). Page copy explicitly says the setting
is shared across delivery OTP AND all three apps' login OTP, since
that's true and worth an admin knowing before they change it expecting
it to be delivery-only.

Not build/DB-verified (same standing sandbox limitation). Manual
PHP-segment-aware brace/paren check on both the new page and the
edited `_layout_head.php` nav array came back balanced.

## Gaps NOT fixed this session (still open findings for a future call)

3. **No per-attempt timestamped audit trail.** PENDING.md
   §21 lists "Audit trail" as a required item. `order_status_history`
   only gets a row on the actual `delivered` transition
   (`insert_status_history` isn't called on a wrong guess) — a wrong
   attempt only increments `orders.otp_attempts`, with no timestamp or
   record of when each failed attempt happened. For a single rider
   account this is low-risk (only the assigned rider can call the
   endpoint at all, so "who" is never in question), but there's
   currently no way to see, after the fact, whether three wrong
   attempts happened in three seconds (mis-tap) or across two days
   (something else going on).
4. **Minor/cosmetic**: the max-attempts check and the increment aren't
   done under the same row lock, so back-to-back concurrent wrong
   submissions (e.g. a rider double-tapping submit) could each read
   the same pre-increment `otp_attempts` value before either write
   lands, letting the true attempt count land one past the configured
   max before the lock actually engages. The increment itself can't be
   lost (it's a DB-level `col = col + 1`), and lockout still reliably
   fires on the very next request either way — this only means the
   effective ceiling is "max_attempts, +0 or +1" rather than an exact
   value. Not worth a `SELECT ... FOR UPDATE` unless the owner cares
   about the exact number being hit precisely.

## What's confirmed correct, no action needed

- OTP generation entropy/bounds.
- Customer-only plaintext exposure, rider-only boolean exposure.
- Ownership + state guards on the verify endpoint.
- Transactional atomicity of delivered-status + COD ledger + rider
  earning (all three or none).
- Shared `otp_max_attempts` setting across every OTP flow in the
  codebase (no drift between delivery OTP and auth OTP).
- The `rider_assigned`/`out_for_delivery`-only exposure window on
  `track.php` (verified this isn't a `picked_up` gap — see above).

## Not done this session

- Gaps 3-4 (per-attempt timestamped audit trail, the max-attempts-check
  race) remain unbuilt — each is still a product/ops call rather than
  something to silently decide and build without the owner weighing
  in.
- Nothing in this session was run against a live DB/PHP CLI/real
  device — same standing sandbox limitation as every prior handover.
- Recommended first live check for gap 1's build: get a real order to
  `out_for_delivery` with an OTP, exhaust `otp_max_attempts` wrong
  guesses via the rider app, confirm the two new buttons appear on
  `admin/orders.php`'s detail modal (and only then), and exercise both
  — Reset attempts then a real correct rider-side delivery, and
  separately Force-deliver on a second locked order — checking the
  COD ledger/rider earnings rows and both notifications land exactly
  once each.

## Next step, owner's choice

Gaps 1-2 closed. Gaps 3-4 still open if the owner wants either built
next; otherwise the same fork doc 110 left stands: a real build/DB/
browser verification pass, or Live Location System customer-side
re-verification.
