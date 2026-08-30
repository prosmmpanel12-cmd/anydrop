Anydrop project zip attached. Unzip it, read `PENDING.md` first (the
source-checked pending list), then
`docs/77_Handover_2026-08-30_Admin_Panel_Refunds_Calendar_Wallet_AppSettings_FCM.md`
for the most recent session (an app-owner-requested admin-panel bug
fix detour), and `docs/76_Handover_2026-08-30_Payment_Refund_Reconciliation_Built.md`
for the last item on the main priority list before that detour.

## Most recent session (doc 77) — admin panel bug fixes, not on the priority list

The app owner hit live bugs in the admin panel mid-session and asked
for them fixed directly, ahead of the Rider App / reconciliation
verification queue below. Doc 77 covers it in full; short version:

1. **Fixed a fatal PDOException** on every wallet-credit / wallet-debit
   call (`lib/wallet.php` built `FOR UPDATE LIMIT 1` — backwards for
   MySQL, must be `LIMIT 1 FOR UPDATE`). This was breaking refund-to-
   wallet, wallet withdrawals, and any admin wallet adjustment.
2. **Replaced `admin/refunds.php`'s JS `prompt()` popups** (including
   a plain-text "type a date" box) with real `<dialog>` modals — a
   native `<input type="date">` calendar, a method dropdown, proper
   required fields for reject reason / transfer reference.
3. **Reordered the Finance sidebar group** so Refunds and Wallet
   Withdrawals sit together right after Pending UPI Payments (all
   three are the customer-money lifecycle); scanned for duplicate
   refund/wallet APIs — found none, everything already funnels
   through the same `lib/wallet.php`/`lib/refunds.php` functions.
4. **Fixed FCM push notifications being silently broken** — they
   depended on a physical `firebase-service-account.json` file that
   was never actually deployable on this project's host. Built
   `admin/fcm-settings.php`: paste the JSON straight into a form, it's
   stored in `app_settings` instead, with a live status panel (last
   send: ok/failed + why) and a test-push button.
5. **Built `admin/app-settings.php`** — three new sidebar entries
   (Customer/Restaurant/Rider App, under Settings), each with an
   Update-Check section (edits the already-existing but
   previously admin-UI-less `latest_app_version_{platform}` etc. keys)
   and a new per-app Maintenance Mode toggle, now also exposed in
   `api/v1/system/app-version.php`'s response for future Android-side
   use.

**Not done as part of doc 77**: `php -l` on the 8 touched files (no
PHP CLI in this sandbox), running the new migration 68, live-testing
any of the above against a real DB/device, or any Android-side change
to actually show a maintenance screen. See doc 77's own "Still
pending" section for the full list.

## Kiya hua hai (what's done before that, as of 2026-08-30 session 20 — doc 76)

Session 19 (doc 75) finished §37 (Wallet Withdrawal) code-complete on
both halves — only live verification left, none of it Claude-
actionable without a PHP CLI/live DB/Android SDK. The app owner picked
the next priority-list item: **Payment/Refund Reconciliation (item 24).
This session (20) built it in full:**

1. **Doc-audit finding first.** Before writing anything new, found
   real, previously undocumented code already in the repo — Paytm
   auto-verify + `provider_bank_ref` dedupe (migrations 41/
   42-paytm/43-dedupe-providers, `UpipeProvider::tryAutoVerify()`,
   `PaytmStatusClient.php`). Neither `recall.md` §28 nor `PENDING.md`
   item 24 mentioned it. It already answers most of "provider
   transaction matching" / "duplicate transaction detection" at the
   DB-constraint level — both docs corrected to say so.
2. **Migration 66 — built.** Adds `restaurant_due_ledger.
   restaurant_payment_id` (a real gap: `platform_ledger` has had this
   exact link since migration 38, `restaurant_due_ledger` never did —
   closes it with a best-effort 120-second-window backfill for
   history, exact going forward), plus the new `reconciliation_flags`
   table and `reconciliation_view`/`reconciliation_manage` permissions.
3. **`lib/reconciliation.php` — built.** 11 read-only checks (see doc
   76's table for the full list — both directions of payment-vs-order
   state, double-successful-transactions, both directions of
   refund-vs-ledger/wallet, wallet balance drift, settlement-ledger
   linkage, platform-wide balance). Deliberately detection-only —
   nothing in this file writes to any financial table, only to
   `reconciliation_flags`.
4. **`admin/reconciliation.php` — built.** Run-scan button, filterable
   flag list, Resolve/Ignore (both note-required, both audit-logged).
   Nav entry added.
5. **`lib/ledger.php` — edited.** `write_due_ledger_entry()` +
   `record_settlement()` now populate the new
   `restaurant_payment_id` link going forward.

Full detail, every file touched, and the precise "what's built vs
what still needs a live DB/device" line is all in doc 76 — read it
before writing any new code here.

## Kiya pending hai (what's still pending)

**PENDING.md item 24 is now code-complete.** What remains is
verification, not new code:

1. Run migration 66 on a live DB — including reviewing how many
   historical settlement rows its backfill query actually linked
   (the 120-second window is a reasoned guess, never tested against
   real timestamps).
2. `php -l` the 3 touched/new backend files (no PHP CLI in this
   sandbox — only a manual brace/paren/bracket balance check was
   possible).
3. Live click-through: run a scan on a clean-ish DB (expect ~0 flags),
   deliberately break one invariant by hand (e.g. delete a
   `platform_ledger` `refund_out` row for a refunded order), confirm
   the matching check fires on the next scan, confirm Resolve/Ignore
   both round-trip, confirm an ignored flag doesn't resurface while a
   still-open one does.
4. No scheduler/cron exists anywhere in this codebase — the scan is
   admin-triggered only for now. Building one is its own, separate
   piece of infrastructure work, out of scope for this session.

Once 1-3 are done, item 24 moves to `done.md` per the project's own
completion rule (#4 needs a scheduler to exist first, which is a
bigger, separate task).

After that, the prior priority order still applies:

5. **Rider App** (PENDING.md item 18) — still fully untouched, largest
   remaining phase.
6. Email OTP Provider Failover (item 25).
7. Security Hardening (item 26).
8. A real machine build/device/live-DB verification pass — every
   "BUILT" item across this project is still unverified in-container;
   check `today.md`/`recall.md` for the latest confirmed-through
   point.

## Next session should start here

**If the app owner can run things on a real machine:** run migration
66, `php -l` the 3 touched files, then live-test the reconciliation
scan end-to-end (steps above). That closes out item 24 completely
(short of the scheduler, which is separate work).

**If no live environment is available yet:** there is no more
Claude-actionable code work left in item 24 — everything buildable
without a PHP CLI/Android SDK/live DB has been built. Either wait for
the app owner to run the verification steps, or, if they want forward
progress instead, move to Rider App (item 18), Email OTP Provider
Failover (item 25), or Security Hardening (item 26).

Same standing constraint as every session: no PHP CLI, Android
SDK/Gradle, or live DB in this sandbox. Run the brace/paren + XML
well-formedness Python checkers over every new/edited file, and update
`PENDING.md` checkboxes / `recall.md` once work is actually done — not
before. Don't forget migration 68 (doc 77) is also unrun, in addition
to migration 66 (doc 76) above.
