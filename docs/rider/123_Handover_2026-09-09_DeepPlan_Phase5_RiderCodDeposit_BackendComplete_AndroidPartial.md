# Handover — Deep Plan Phase 5: Rider "Pay COD Amount" (Backend complete, Android UI not started)

**Date:** 2026-09-09 (session following Phase 4's `122_Handover_...md`)
**Scope:** Deep Plan §5 (`docs/00_Deep_Plan_Statement_CODLimit_QRPay_CashFlow_2026-09-09.md`)
**Status:** 🟡 PARTIAL — backend fully built, Android data layer built, Android UI screen NOT built yet. Nothing in this phase is device/build-verified (standing sandbox limitation — no PHP CLI, no MySQL, no Android SDK here).

## Owner decision this session
Two open questions were listed at the end of the Phase 4 handover. Only one was asked and answered before this session ran out of room:

- **Full deposit vs. partial deposit → Owner chose: partial deposits are allowed.** A rider holding e.g. ₹850 may deposit any amount from ₹0.01 up to ₹850, not only the full balance. This is enforced server-side in `cod-deposit-initiate.php` (`amount > cod_cash_held` → `422 amount_exceeds_cod_held`), not in the schema — migration 82's own comment block explains why.
- **Which endpoint path the deposit-initiate call should live at → NOT asked.** I picked `backend/api/v1/rider/cod-deposit-initiate.php` / `cod-deposit-status.php` / `cod-deposit-submit-utr.php` (direct-filename convention, matching every other rider endpoint — rider endpoints have no `.htaccess` pretty-route layer at all, confirmed by grepping `backend/.htaccess` and cross-checking the rider app's `ApiService.kt`, which calls every rider endpoint by its raw `.php` filename). This wasn't a real open design question, just an unconfirmed guess — flag it to the owner if they'd rather it live somewhere else (e.g. under a new `rider/payments/` subfolder) before wiring the Android side to call it.

## What's built and should work (pending verification)

### Backend
- **`backend/sql/82_migration_rider_cod_deposit.sql`** (new, run this before anything else works) —
  - `payment_transactions.order_id` loosened from `NOT NULL` to `NULL`.
  - `payment_transactions` gets `purpose` (`'customer_order'` default | `'rider_cod_deposit'`) and `rider_id` (FK → riders, nullable), plus indexes and a best-effort CHECK constraint (MySQL 8.0.16+/MariaDB 10.2+ only, same caveat migration 37 already carries).
  - `rider_cod_ledger` gets `payment_transaction_id` (FK → payment_transactions, nullable, UNIQUE) — links a self-service deposit's ledger row back to the exact payment that produced it; stays `NULL` for admin-manual settlements (`rider-settlements.php`) exactly as before.
  - **Read the migration file's own header comment** — it explains the `purpose` split in detail; don't re-derive it from scratch in a future session.

- **`backend/lib/rider_ledger.php`** —
  - `write_rider_cod_ledger_entry()` and `record_rider_settlement()` both gained an optional trailing `?int $paymentTransactionId = null` param — fully backward compatible, every existing caller is untouched.
  - New: `rider_cod_ledger_has_payment_transaction(PDO $db, int $paymentTransactionId): bool` — idempotency pre-check.
  - New: `record_rider_cod_deposit_via_upi(PDO $db, int $riderId, int $paymentTransactionId, float $amount, string $providerTxnRef): int` — the system-authored counterpart to `record_rider_settlement()`'s admin-authored ledger write.

- **`backend/lib/payment/PaymentService.php`** — added without touching any existing customer-order behavior:
  - `initiateRiderCodDeposit(PDO $db, int $riderId, float $amount): array` — same provider/QR mechanics as `initiatePayment()`, keyed on `(rider_id, purpose)` instead of `order_id`. Has the same "reuse an in-flight txn for the same amount, start fresh for a different amount" idempotency as the order flow.
  - `getRiderDepositClientStatus(PDO $db, int $riderId, int $txnId): array` — polled status, re-verifies via the provider every call (never trusts a client "I paid" claim), same status vocabulary as the customer flow (`not_found | initiated | utr_pending_window | utr_available | utr_submitted | success | failed | expired`).
  - `submitRiderDepositUtr(PDO $db, int $riderId, int $txnId, string $utr): array` — manual fallback, mirrors `submitUtr()`.
  - `promoteRiderDepositIfNeeded(PDO $db, array $txn): void` (private) — idempotent (checked via `rider_cod_ledger_has_payment_transaction()` + the migration's UNIQUE index as the hard backstop). On first success: writes the ledger entry (decrements `cod_cash_held`), sends the rider a notification, writes an audit log entry.
  - **Fixed a real bug while extending this:** `adminPendingTransactions()` and `adminDecide()` both used to `JOIN` (inner) or otherwise assume every `payment_transactions` row has a matching `orders` row. A rider-deposit row has `order_id = NULL`, so the old inner join would have made `adminDecide()` 404 on every deposit transaction id, and the old `adminPendingTransactions()` query would have silently never shown deposit rows in the admin queue at all. Both are now `LEFT JOIN`, and `adminDecide()`'s `SELECT` explicitly aliases `t.rider_id`/`t.purpose`/`t.provider_txn_id`/`t.amount_confirmed` — because `orders` has its own unrelated `rider_id` column (the delivery rider assigned to that order), and without the alias PDO's associative fetch would have silently overwritten the deposit's real `rider_id` with `NULL` for every deposit row. **If you touch `adminDecide()` again, keep those aliases** — removing them re-introduces this bug silently (no error, just credits nobody).

- **Three new rider endpoints** (`backend/api/v1/rider/`): `cod-deposit-initiate.php`, `cod-deposit-status.php`, `cod-deposit-submit-utr.php`. Each has a full doc-comment header; read those before extending. `cod-deposit-initiate.php` does its own `amount > 0` and `amount <= cod_cash_held` validation before calling `PaymentService`.

- **`backend/admin/payment-pending.php`** — now shows both customer-order and rider-deposit rows in the same manual-review queue, with a "COD Deposit" badge + rider name for the latter. Approve/Reject buttons are unchanged; they call the same `adminDecide()`, which now branches internally.

### Android (Rider App) — data layer only, no screen yet
- `rider/app/build.gradle` — added `com.google.zxing:core:3.5.3` (same version the customer app already uses for its own UPI QR screen).
- `network/Models.kt` — added `DepositInitBody`, `DepositInitResult`, `DepositStatusResult`, `DepositSubmitUtrBody`, `DepositSubmitUtrResult`. Deliberately field-for-field mirrors of the customer app's own `UpiPaymentInitResult`/`UpiPaymentStatusResult`/`SubmitUtrBody`/`SubmitUtrResult` (`customer/app/.../network/Models.kt`) — same backend, same shape, don't invent a different naming scheme for these.
- `network/ApiService.kt` — added `initiateCodDeposit()`, `getCodDepositStatus()`, `submitCodDepositUtr()` — direct calls to the three new `.php` filenames (see the open question above about the path).

## NOT built yet — pick this up first next session

1. **`PayCodDepositActivity.kt`** (new) — the actual screen. Adapt `customer/app/src/main/java/com/anydrop/food/ui/checkout/UpiPaymentActivity.kt` (already fully read this session, its pattern is: render QR from `upi_link` via ZXing, poll `getCodDepositStatus()` every `pollIntervalSec`, show a UTR entry fallback once `utr_available`, handle `expired`/`failed`, finish with a result on `success`). Needs its own layout, adapted from `customer/app/src/main/res/layout/activity_upi_payment.xml` (already read) — swap "pay for your order" copy for "deposit COD cash to admin" copy, and there's no order-summary section to show (no order behind this transaction).
2. **Entry point** — a "Pay COD Amount" button in `rider/app/src/main/res/layout/fragment_earnings.xml`, next to the existing `btnViewStatement` (both already reviewed this session — the COD Cash Held card with its pill/progress bar is already there from an earlier phase, this button goes right next to it). Wire it in `EarningsFragment.kt` to launch `PayCodDepositActivity`, probably passing the current `cod_cash_held` value so the deposit screen can pre-fill/cap the amount field.
3. **Amount-entry step** — neither `UpiPaymentActivity`'s pattern nor `DepositInitResult` includes an amount-entry UI (the customer flow's amount is fixed by the order total). Since partial deposits are allowed, `PayCodDepositActivity` needs its own small "how much are you depositing?" step (a plain `EditText`, pre-filled with the full `cod_cash_held`, editable, client-side capped at that value) BEFORE calling `initiateCodDeposit()` — this doesn't exist anywhere to copy from, it's new for this phase.
4. New strings (`strings.xml`) and the `AndroidManifest.xml` `<activity>` registration for `PayCodDepositActivity`.
5. Full verification checklist (once a real environment is available): run migration 82 → confirm `payment_transactions`/`rider_cod_ledger` shapes → rider deposits a partial amount → auto-verify or manual UTR + admin approval → confirm `cod_cash_held` decrements by exactly the deposited amount (not the full balance) → confirm the ledger row's `payment_transaction_id` is set and a second status poll after success does NOT write a second ledger row (idempotency) → confirm `admin/payment-pending.php` shows the deposit row correctly and a customer-order row is unaffected.

## Deliberately not touched
- No change to `record_rider_settlement()`'s existing behavior/signature defaults — the admin manual "Record Settlement" button on `rider-settlements.php` works exactly as before.
- No change to the customer order UPI flow (`payment-upi-*.php`, `PaymentService::initiatePayment/getClientStatus/submitUtr/promoteOrderIfNeeded`) beyond what was structurally required (the `LEFT JOIN` fix above) to make the shared table safely support both shapes.
