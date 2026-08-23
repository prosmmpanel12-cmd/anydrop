Anydrop project zip attached. Unzip it, read recall.md fully (start
with section 0, then jump to section 4b — "AREA-WISE PAYMENT
RESTRICTIONS (GENERAL)" — for the item this session is continuing).

Phase B item 15 (area-wise general payment restrictions) was built
last session: migration 37, backend/lib/payment_restrictions.php,
orders/create.php enforcement, customer/payment-methods.php pre-check
endpoint, admin/payment-restrictions.php admin page + nav entry, and
Customer App wiring in CheckoutActivity.kt (payment radios grey out
per resolved area, auto-hop selection, client + server-side rejection
messaging). Status is 🟡 BUILT, NOT device-verified — no PHP CLI, live
DB, or Android build environment exists in this sandbox, so none of it
has been run yet.

Continue from here:

1. First, if the owner has since device-tested item 15, update
   recall.md section 4b's status to ✅ DONE per its own verification
   checklist (7 steps listed at the end of that section) — don't
   re-open it as pending if it's already confirmed working.
2. If NOT yet verified, do not redo the build — just pick up the next
   genuinely pending Phase B item per recall.md section 33's build
   order: item 16, "Area-wise banner targeting" — but check its own
   status line first (recall.md section 5 already flags this may
   actually be built already, despite its own stale status line — same
   "don't trust an old status blindly" rule as section 34 spells out).
3. If item 16 turns out already done too, move to item 17 ("No-restaurant
   state" — also check section 6 for the same reason) or whatever
   recall.md's own state says is next once you've re-read it fresh.

Standing rules (recall.md section 34) — read the actual current source
before trusting any status line, keep business rules admin-configurable
never hardcoded in the Customer App, and update recall.md + this
handover pattern again at the end of your session so the next one
doesn't lose the thread.
