Anydrop project zip attached. Unzip it, read recall.md fully (start
with section 0/33's Phase D item 29 entry — the combo/bundle log —
then jump to docs/40_Plan_Combo_Bundle_Offer_Type_2026-08-25.md for
the full step-by-step detail, and docs/Status.md's matching "Combo/
Bundle Offer Type" entries for the same history in narrative form).

## Where this left off

**docs/40's Combo/Bundle Offer Type plan is now fully done — Steps 1
through 6, all six.** Status is 🟡 BUILT, NOT device/build-verified —
same standing sandbox limitation as every other feature in this repo
(no PHP CLI, Kotlin compiler, Gradle, or live DB here).

Quick recap of what exists now:
- Migration 50 (`backend/sql/50_migration_combo_offers.sql`) — combo
  offer_type + `offer_combo_items` table.
- `lib/offers.php` — matching/discount calc (`get_offer_combo_items()`,
  `compute_offer_discount()`'s combo case), plus this session's own
  addition: `index_combo_offers()` + a fixed `pick_item_badge_offer()`/
  `offer_badge_label()` pair.
- `offers-create.php`/`offers-list.php`/`offers-update.php` — combo
  create/read support, `format_offer()`'s `combo_items` field.
- Restaurant App (`OfferManagerActivity.kt`, `dialog_add_offer.xml`,
  `Models.kt`) — combo create/edit dialog.
- `admin/offers.php` — per-combo item-list visibility in the Type
  column.
- **This session's Step 6:** `restaurants/menu.php`,
  `home/popular-items.php`, `search/search.php`,
  `home/offers-browse.php` all now badge ONLY a combo's own required
  items (not the whole restaurant's menu — see the bug note below),
  with a label naming the other items + bundle price, e.g. `"Combo w/
  Fries, Coke — ₹199"`. Android needed no changes — `offerTag` is a
  plain `String?` already wired to an unconstrained pill everywhere.

**The one thing worth re-reading closely before touching this area
again:** Step 6 found and fixed a real bug, not just a missing
display — `pick_item_badge_offer()`'s old restaurant-wide fallback
tier would have matched a combo's `scope = 'restaurant'` (migration
50 forces that, since scope is unused for combo matching) and badged
**every menu item in the restaurant**, not just the combo's own
items. Fixed by giving combo its own precedence tier + excluding it
from the restaurant-wide fallback. If you're editing
`pick_item_badge_offer()`/`offer_badge_label()`/any of those four
endpoints again, keep that exclusion — removing it silently
reintroduces the bug.

## Continue from here — two options, pick based on what the owner wants

**(a) Verification pass (recommended, and the thing this whole feature
has been blocked on since Step 1).** docs/40's own closing note has a
5-item checklist:
1. Run migration 50 against a real DB.
2. `php -l` every file this feature touched (listed in docs/40's
   closing note and docs/Status.md's Step 6 entry).
3. Create a combo via the Restaurant App (2+ items, a bundle price) →
   confirm admin/offers.php's item list (Step 5) and the Restaurant
   App's own edit/view mode (Step 4) both show it correctly.
4. Browse Home/Search/the restaurant's menu/the Offers screen →
   confirm ONLY the combo's own items badge, not the whole menu — this
   is the specific regression Step 6 fixed, the one most worth a real
   device check.
5. Add the combo's required items to cart, checkout → confirm the
   offer strip shows correctly, and that removing a required item
   before placing drops the discount to 0.

This is also PENDING.md item 31's existing "full build/device/live DB
regression" ask — not a special case for this feature, just the next
instance of a backlog that's been growing across every session in this
repo (docs/38, docs/39, and now docs/40 have all deferred it the same
way). Worth doing as one pass across everything at once rather than
piecemeal, if/when a real PHP CLI + Android Studio + live MySQL
environment is available.

**(b) Keep building forward, if the owner doesn't have that
environment yet.** recall.md's Phase E (Support/Trust, items 34-38 —
support tickets, order issue reporting, review replies/reports, and an
optional AI support layer) is entirely unstarted and the next
un-flagged block in the file's own build order (section 33). Phase D
(Offers) is now fully closed out (items 28-33 all built).

Standing rules (recall.md section 34) — read the actual current source
before trusting any status line, keep business rules admin-
configurable never hardcoded in the Customer App, and update recall.md
+ this handover pattern again at the end of your session so the next
one doesn't lose the thread.
