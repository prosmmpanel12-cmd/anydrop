# Handover — Cash Flow Pages Merged (owner request, follow-up to Phase 7's `125_Handover_...md`)

**Date:** 2026-09-10 (same day, immediate follow-up)
**Trigger:** Owner: "cash flow ek alredy hai dono mai se ek rakho ya
marge kr do" — i.e. there were now two "cash flow"-ish admin pages
(`platform-ledger.php`, pre-existing, and `cash-flow.php`, built this
same session per doc 125) and the owner asked to either keep only one
or merge them, rather than have both.
**Decision:** Merged (kept one URL, `cash-flow.php`, folded the other
one's content in) rather than deleted, since `platform-ledger.php`'s
UPIPE-merchant-account view and `cash-flow.php`'s rider/restaurant view
are genuinely different money flows the owner may still want both —
merging into one page with clear sections preserves all of it instead
of throwing a section away.

## What changed

**`backend/admin/cash-flow.php`** — now three sections, one page:
1. Rider COD Cash (unchanged from doc 125's build)
2. Restaurant Settlement Summary (unchanged from doc 125's build)
3. **Platform Cash Flow (UPIPE Merchant Account)** — new section,
   moved in verbatim from the old standalone `platform-ledger.php`:
   Total Money In/Out, Net Balance Held, Total Platform Revenue, the
   reconciliation check against restaurants' negative `current_due`
   total, and the filterable `platform_ledger` entries list
   (restaurant dropdown filter, own "Clear" link). Query logic is
   byte-for-byte the same as the old page had — nothing about how
   those numbers are computed changed, only where they're rendered.
   Reuses the page's existing `$fromDate`/`$toDate` (Section 2's
   filter) for this section's date range too, instead of a second,
   redundant date-filter form — one From/To on the page now drives
   both Section 2's "Paid this cycle" and Section 3's entries list.
   Section 3 keeps its own separate restaurant-dropdown filter
   (`pl_restaurant_id` param) since that's specific to platform_ledger
   entries, not something Section 1/2 have an equivalent of.

**`backend/admin/platform-ledger.php`** — gutted to a thin redirect:
still calls `admin_require_login()` / `admin_require_permission()`
first (so a direct hit still respects auth), then 302s to
`cash-flow.php`. Kept as a file (not deleted) purely so any existing
bookmark or hardcoded link doesn't 404 — query params are **not**
forwarded, since the param shapes differ (`restaurant_id` on the old
page vs. `pl_restaurant_id` + shared `from`/`to` on the new one) and
mapping them wasn't worth the complexity for what's expected to be a
rare direct-hit case.

**`backend/admin/_layout_head.php`** — removed the separate
`platform_ledger` nav entry (was right above `cash_flow` in the
`finance` group); only `cash_flow` remains, labelled "Cash Flow".
Also dropped `platform_ledger` from the `$activeNav` doc-comment enum
list since the key is no longer set anywhere.

## What did NOT change
- No schema/migration change.
- `lib/ledger.php`, `lib/rider_ledger.php` — untouched, same as doc
  125.
- The actual totals/reconciliation/entries **logic** for the merged-in
  Section 3 — copied as-is from the old page, not rewritten.

## Static checks this session
- Brace `{}` / paren `()` balance on the updated `cash-flow.php` —
  balanced (9/9 braces, 207/207 parens).
- Read-through confirmed every `<div>`/`<form>` opened in the new
  Section 3 markup is closed, and the page's final closing
  `</div>` (wrapping the whole `.section`) still lines up correctly
  after the added content.
- Grepped for other references to `platform-ledger.php` before
  gutting it — only found comments (in `settlements.php`,
  `reconciliation.php`) and the nav array (now removed); no other
  `.php` file links to it directly, so the redirect is the only
  compatibility surface that matters.

## NOT verified — do this first in a real environment
1. Everything doc 125 already flagged as unverified for Sections 1-2
   (rider deposit mix, restaurant Pay Now totals) — still open.
2. Confirm the merged Section 3 renders identically to what the old
   `platform-ledger.php` used to show for the same data (totals,
   reconciliation badge, entries table) — this session only moved the
   code, never executed it.
3. Confirm the redirect in `platform-ledger.php` actually fires (302,
   not a blank page or error) once a real PHP runtime exists.
4. Click through the page with a restaurant selected in Section 3's
   filter AND a From/To range set — confirm both filters compose
   correctly (Section 3's WHERE clause ANDs `pl.restaurant_id` with
   the shared date range) rather than one silently overriding the
   other.

## NEXT SESSION
Real-environment verification (all of docs 124/125/126's checklists,
plus everything from Phases 1-6 already flagged) is the only real
remaining work — the Deep Plan itself has nothing left to build.
