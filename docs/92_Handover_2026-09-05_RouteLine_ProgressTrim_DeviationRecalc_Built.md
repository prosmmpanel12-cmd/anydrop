# Handover — Progress-Trim Route Line + Deviation-Based Recalc, BUILT

Session date: 05 Sep 2026. Implements doc 91's plan in full (both
Piece A and Piece B), per the person's own answers at the bottom of
that doc: smooth (per-frame) trim, admin-configurable thresholds via
`app_settings`.

> **Not built/tested against a live backend or device.** Same standing
> caveat as every other handover in this project — no Android SDK,
> Gradle, emulator, or DB in this sandbox. XML/Kotlin/PHP checked by
> hand for consistency and (PHP) balanced syntax only; `php -l` isn't
> available here either. Treat as `🟡 IMPLEMENTED — TEST PENDING` per
> `done.md`'s own rule until it's actually run.

## What changed

### Backend — `backend/api/v1/orders/route.php`
- Every response (success and every "no route yet" degrade path) now
  also returns three numbers, sourced from `app_settings` via
  `get_setting()` (same convention as `rider_earning_share_percent`
  etc.):
  - `deviation_threshold_m` (default 70)
  - `deviation_sustain_seconds` (default 60)
  - `max_recalc_interval_seconds` (default 90)
- New local `with_route_config()` helper wraps every `respond_ok()`
  payload so these three keys don't need repeating at each of the six
  response call sites.
- **No admin-panel UI added yet for these three keys** — same
  "fast follow" gap this file's own kdoc already notes for
  `google_directions_api_key`. Set them via `set_setting()` or a
  direct `app_settings` row for now; defaults apply otherwise.

### Android — new file: `util/RouteGeometry.kt`
- `nearestPointOnPath(points, target)` — projects a point onto a
  polyline (planar-approximation segment projection, linear scan,
  same "short polylines, no spatial index needed" reasoning as the
  plan doc), returns the projected point + straight-line distance in
  metres (haversine).
- `trimToNearest(points, nearest)` — returns the "remaining route"
  sub-path from that projection onward.
- Hand-rolled rather than adding `play-services-maps-utils`, same
  dependency-avoidance reasoning `PolylineDecoder` already documents.

### Android — `network/Models.kt`
- `RouteResult` gained the three new fields (`deviationThresholdM` /
  `deviationSustainSeconds` / `maxRecalcIntervalSeconds`), with Kotlin
  defaults matching route.php's own `get_setting()` fallbacks.

### Android — `ui/orderstatus/OrderStatusActivity.kt`
- **Piece A (progress trim):** new `routePoints: List<LatLng>?` field
  holds the undecoded-into-trimmed source polyline. `trimPolylineTo(pos)`
  re-slices from that source and calls `Polyline.points =` (not
  remove+re-add) on every frame of `animateRiderMarker`'s existing
  `ValueAnimator` — the "smooth" option from doc 91, confirmed by the
  person over the simpler once-per-5s-poll alternative.
- **Piece B (deviation recalc):** new `checkRouteDeviation(riderPos)`,
  called once per 5s poll from `updateMap()` with the rider's real
  (non-animated) position. Starts a "deviated since" timer past
  `deviationThresholdM`, fires an immediate `fetchAndDrawRoute()` once
  that persists past `deviationSustainMs`; resets on any in-threshold
  sample (momentary GPS jitter doesn't count).
- `startRouteRecalcLoop()` reworked from "fetch every fixed 35s" to
  "tick every 5s, only actually fetch once `maxRecalcIntervalMs` has
  elapsed since the last fetch (deviation-triggered or fallback)" —
  this is the fallback ceiling the plan asked to keep alongside
  deviation-triggering, not a replacement for it.
- `fetchAndDrawRoute()` now also: stores the full undecoded point list
  into `routePoints`, refreshes the three config fields from the
  response every time (so an admin change takes effect on the very
  next successful fetch, not just at Activity start), and resets the
  deviation timer (fresh route = fresh baseline, per doc 91's edge
  case notes).

## Open items / things to verify on a real device

1. **The two new numbers' real-world feel** — 70m / 60s / 90s ceiling
   are the defaults seeded here; doc 91 itself flagged these as
   "TBD/configurable, not guessed" — tune via `app_settings` after
   watching a real rider's GPS drift, not by further guessing here.
2. **Per-frame `Polyline.points =` cost** — should be cheap (short
   polylines, no remove/re-add), but this sandbox can't profile frame
   timing on an actual device/emulator to confirm "still cheap" holds
   in practice at 60fps over a 5s animation window.
3. **No admin-panel fields yet** for the three new `app_settings` keys
   — same gap as `google_directions_api_key`; a fast follow if these
   need in-panel tuning rather than direct DB/`set_setting()` edits.
4. **Gradle/device build** — same untested-in-this-sandbox status every
   other recent handover in this project already carries.

Next step: build the customer app, run a real delivery order end to
end, and watch both pieces on a moving rider — that's the only way to
validate the two open numbers above.
