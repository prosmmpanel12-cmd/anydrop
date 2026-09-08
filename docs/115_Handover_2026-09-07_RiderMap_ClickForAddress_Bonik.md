# Handover — 2026-09-07 — Rider Map: Click-Anywhere-for-Address (bonik.in)

**App owner request (Hinglish, verbatim intent):** "Rider view map mein
jab kisi jagah pe click karu to uski details show karo — same wali jo
Service Area mein hai" → areas.php's "Choose on map" picker already
reverse-geocodes a clicked point via bonik.in and shows the formatted
address. This session ports that same click-to-address behaviour onto
`backend/admin/rider-map.php` (the Live Rider Map, doc 93).

## What changed

**File:** `backend/admin/rider-map.php` only.

- Clicking anywhere on the Leaflet map now drops (or moves, on a second
  click) a plain `L.marker` pin and opens a popup showing the
  reverse-geocoded address for that point — same
  `https://bonik.in/api/google/address?lat=..&lng=..` call and same
  formatted-address/city/state/country/pincode line layout
  `areas.php`'s `fetchBonikAddress()` already uses, adapted to write
  into a Leaflet popup (`setPopupContent`) instead of a dialog's `<div>`.
- Rider `circleMarker`s got `bubblingMouseEvents: false` added to their
  options. Without this, clicking a rider's own marker to open *its*
  popup would also bubble up to the map's new click handler and drop an
  address pin right on top of it — Leaflet's vector layers (circleMarker
  included) bubble mouse events to the map by default; plain
  `L.marker` (used only for the new address-pick pin) never did.
- Stale-response guard (`addressFetchToken`, same pattern as
  areas.php's own) so a second click while the first lookup is still
  in flight can't have the first response's address land in the
  second pin's popup.
- One-line legend hint added above the map: "Click anywhere on the map
  to look up that point's address."
- This is **read-only** — unlike areas.php's picker there's nothing to
  save here, so no dialog, no form, no "Use this point" button, just
  the popup. Kept deliberately smaller than areas.php's version for
  that reason.

## Why rider-map.php and not somewhere else

The app owner said "rider view map" — the only map surface in this
project that shows riders is the admin's Live Rider Map
(`rider-map.php`, doc 93). The Rider Android app itself has no
embedded map screen of its own (`RiderOrderDetailActivity` only
launches Google Maps via an external intent for navigation) — so
there was nothing to change there.

## Not done / caveats

- **No browser available in this sandbox** — same standing caveat as
  every other admin-panel page in this project (rider-map.php's own
  header already flags itself as the single least-visually-confirmed
  Leaflet page in the codebase). This click handler has not been seen
  rendering in an actual browser. Manual review: brace/paren balance
  checked, and the new block mirrors areas.php's already-working
  `fetchBonikAddress()` closely enough that the same network call
  shape is trusted, but this still needs an eyeball test on a live
  page.
- bonik.in's actual response shape/uptime is an external dependency
  already relied on elsewhere (areas.php) — no new risk introduced,
  same trust level as before.
