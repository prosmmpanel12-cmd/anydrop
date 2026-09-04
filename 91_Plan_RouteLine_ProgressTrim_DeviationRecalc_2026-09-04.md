# Plan — Progress-Trim Route Line + Deviation-Based Recalc

Session date: 04 Sep 2026 (planning only — no code written this
session). Follow-up to deep-plan §14-15 / doc 88's "route deviation
detection — not built" flag, and doc 90's Rider Earnings work (separate
feature, unaffected).

## What changes vs. today

**Today (current code, `OrderStatusActivity.kt`):**
- Route line = full polyline from origin to destination, drawn once
  every `ROUTE_RECALC_INTERVAL_MS` (35s), unconditionally — deleted +
  redrawn from scratch every cycle regardless of whether the rider
  actually moved off it.
- Rider marker animates smoothly every 5s (`POLL_INTERVAL_MS`) via
  `animateRiderMarker()` — this part is unaffected by this plan.
- Between two 35s recalcs, the line never changes — only the marker
  moves along/near it.

**Target behavior (person's ask, matches Uber/Swiggy-style tracking):**
1. **Progress trim** — as the rider moves, the ALREADY-TRAVELLED
   portion of the line disappears. The line always represents
   "remaining route from rider's current position to destination,"
   not "the whole original route."
2. **Deviation-triggered recalc** — a brand new route (fresh Directions
   API call) is fetched only when the rider has actually drifted off
   the currently-drawn line by more than a threshold distance (~50-80m,
   exact number TBD/configurable) for a sustained period (~1 minute,
   per person's own phrasing) — not on a fixed timer.

## Two independent pieces — can be built/shipped separately

### Piece A — Progress trim (client-side only, no new backend call)

**Where:** `OrderStatusActivity.kt`, inside `updateMap()`'s rider-marker
block (runs every 5s, same cadence marker animation already uses —
this is a pure geometry operation, cheap enough to run every poll).

**Approach:**
- Keep the full decoded polyline (`List<LatLng>`) in memory alongside
  `routePolyline` (currently only the `Polyline` overlay object is
  kept — the source points list would need to be stored too, e.g. a
  new `private var routePoints: List<LatLng>?` field).
- On every rider position update: find the nearest point on the
  polyline to the rider's new lat/lng (simple nearest-vertex or
  nearest-segment-projection scan over `routePoints` — polylines here
  are short, at most a few dozen points per Directions response, so a
  linear scan is cheap, no spatial index needed).
- Redraw the polyline using only the sub-list from that nearest point
  onward (`routePoints.subList(nearestIndex, routePoints.size)`),
  replacing the existing `routePolyline` overlay with the trimmed one.
- **During the marker's 5s lerp animation** (`animateRiderMarker()`),
  the trim point should ideally also interpolate smoothly rather than
  jumping in 5s steps — otherwise the line would visibly "chunk" back
  by a marker-hop's distance every 5s instead of continuously
  shrinking. Two options, decide before building:
  - **Simple version:** trim once per 5s poll (same granularity as the
    marker's start/end position) — line shortens in small steps,
    matches the marker's known positions exactly, no extra animation
    code.
  - **Smooth version:** trim on every animation frame tick (reuse
    `ValueAnimator`'s existing `addUpdateListener`, which already fires
    at high frequency for the marker lerp) — line shrinks continuously
    in sync with the marker's visual motion, more polished, slightly
    more CPU per frame (still cheap — trimming a short point list is
    not expensive).
  - Recommendation: smooth version, since the marker is already
    animating every frame in that listener — piggybacking the trim
    calculation onto the same callback is a small addition, not a new
    animation system.

**No backend change needed for this piece** — `route.php` already
returns the full polyline; trimming is purely how the Android side
chooses to render it.

**Edge cases to handle:**
- Nearest-point calculation should have a "reasonable distance" cutoff
  — if the rider is nowhere near the line at all (e.g. deviation
  already happened, recalc pending), don't trim to some arbitrary far
  point; this is exactly why Piece B needs to exist alongside this one
  — a badly-off rider should trigger a fresh route, not a weird trim.
- On every 35s→(new interval, see Piece B) recalc, the full new
  polyline replaces `routePoints` entirely and trimming starts fresh
  from index 0 again.

### Piece B — Deviation-based recalc (replaces the fixed timer)

**Where:** `OrderStatusActivity.kt`'s `startRouteRecalcLoop()` +
`fetchAndDrawRoute()`, called from the same 5s `updateMap()` cycle
instead of (or in addition to) the current independent 35s loop.

**Approach:**
- On every 5s rider position update, after computing the nearest point
  on the currently-drawn route (Piece A's calculation — the two pieces
  share this same "distance from rider to route line" number, so
  building them together is more efficient than building them
  separately and duplicating the geometry math):
  - If that distance exceeds a threshold (e.g. 60-80m — real number
    should come from testing, not guessed here), start/continue a
    "deviated since" timer.
  - If the rider comes back within threshold before the sustain
    duration elapses, cancel the timer (a brief GPS jitter or momentary
    off-route blip — like waiting at a signal slightly off the drawn
    line — shouldn't trigger a real recalc).
  - If the deviation persists for the full sustain duration (person's
    own "1 minute" framing — i.e., ~12 consecutive 5s polls all showing
    the rider off-route), fire `fetchAndDrawRoute()` immediately,
    resetting both the deviation timer and (per Piece A) the trim
    baseline to the new polyline.
- **Keep a fallback maximum-interval recalc too** (e.g. still cap at
  60-90s even with no detected deviation) — a route can go stale for
  reasons deviation-detection won't catch (traffic-aware re-routing
  Google's own API would return differently on a fresh call even along
  the "same" road), and this matches deep-plan §15's original wording:
  "roughly 30-45 seconds **or** on significant route deviation," not
  "only on deviation."

**Backend:** `route.php` itself needs no changes — it already computes
a route from the rider's current position given order status; this
piece only changes *when* the Android side decides to call it, not
what it asks for.

**Cost implication (ties back to the earlier discussion in this
chat):** deviation-triggered recalc should, in the common case (rider
mostly following the suggested route), call the Directions API *less*
often than today's unconditional 35s timer, not more — a real
efficiency win, not just a UX one. Worth mentioning if billing cost was
part of the original concern.

## Suggested build order (if both pieces get built)

1. **Piece A first, alone** — lower risk, no timing/threshold tuning
   needed, purely visual, easy to verify by eye (line visibly shortens
   as the rider marker moves). Ships value even before Piece B exists
   (today's line would still redraw fully every 35s, just also trim
   visually in between).
2. **Piece B second, on top of A** — since it reuses A's
   nearest-point-on-line calculation, building A first means B's
   distance-to-route check is close to free to add.
3. Tune the two open numbers (deviation distance threshold, sustain
   duration) after a real device test — deep-plan/doc 88's standing
   caveat applies here too: this sandbox can't run the app to feel out
   what "1 minute of drift" actually looks like on a moving rider, so
   these should be easy-to-change constants (or even
   `app_settings`-driven, matching this codebase's general "server-
   configurable, not hardcoded" convention used elsewhere — e.g.
   `rider_cod_settlement_limit`, `rider_earning_share_percent`) rather
   than baked-in magic numbers from a first guess.

## Open decisions before coding starts

1. **Trim granularity** — every-frame (smooth) vs. every-5s-poll
   (simple)? Recommendation above is every-frame; confirm before
   building since it changes where the code hooks into
   `animateRiderMarker()`.
2. **Deviation threshold + sustain duration** — starting placeholders
   for a first build vs. person has specific numbers in mind already?
3. **Should the deviation distance/duration be hardcoded constants (Kotlin
   `companion object`, like `POLL_INTERVAL_MS` today) or
   server-configurable via `app_settings`** (more consistent with the
   rest of this codebase's money/business-rule settings, but is
   slightly more backend work — a small GET-settings endpoint or
   folding it into an existing config response)?
4. Fallback max-interval recalc — keep the current 35s as the
   ceiling, or pick a new number now that it's a ceiling instead of the
   only trigger?

Nothing built yet — next step is answering the above, then Piece A,
then Piece B, per the build order.



Q: Line trim smooth honi chahiye (har frame) ya thodi steps mein (har 5-sec)?
A: Smooth (better dikhega, thoda zyada CPU)

Q: Deviation threshold/duration numbers hardcode karein ya admin panel se change karne layak banayein?
A: Admin-configurable banao (jaisa baaki money-settings hain)

Q: Konsa piece pehle banaun?
A: only plan bnao
