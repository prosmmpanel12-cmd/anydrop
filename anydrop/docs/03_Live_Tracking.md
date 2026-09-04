# Anydrop — Live Location Tracking (Zomato/Swiggy-style, Free Stack)

**Version:** 1.0 · **Maps:** OpenStreetMap (osmdroid) · **Routing:** OSRM (free public server)

> **⚠️ PLAN CHANGED (2026-08-11):** This entire document's stack choice
> (osmdroid + OSRM, "100% free") is superseded — **Google Maps is now the
> planned provider** for live tracking too (Maps SDK for rendering,
> Directions API for the route line, deviation-based refetch to keep cost
> near-zero at current scale). See
> `docs/12_Handover_H6_Map_PinDrop_Photo.md` → "Google Maps SDK migration
> plan" and its "Live tracking screen" section for the current plan. The
> animation/polling mechanics described below (5-7s GPS pings,
> `ValueAnimator` marker interpolation, single map-load per session) are
> still accurate and provider-agnostic — only the map-rendering and
> routing *provider* changes, not this document's core tracking approach.
> Migration is blocked on app name/package finalization and billing
> setup; Phase 4 (which this doc covers) hasn't started yet regardless.

This document explains exactly how the "rider moving live on map with smooth animation" feature works — the thing that makes Zomato/Swiggy feel premium — using a 100% free stack.

---

## 1. Why Not Google Maps + Firebase Realtime DB (the "easy" way)

The typical tutorial approach uses Google Maps SDK + Firebase Realtime Database for instant push-based location sync. We're intentionally not doing that because:
- Google Maps SDK requires a billing-enabled Google Cloud project (free tier exists but needs a credit card on file, and costs scale with usage as you grow — you specifically asked for OpenStreetMap)
- Firebase Realtime DB is a separate service from your PHP+MySQL backend — would mean managing two data stores and two sources of truth for the same order

Our stack achieves the same visual result with **polling + client-side animation**, which is actually how Swiggy/Zomato worked in their early years before they had massive infra teams.

---

## 2. The Core Insight: Smooth Motion Doesn't Require Real-Time Push

The rider's dot doesn't need to update 10 times a second on the server. It needs to **look smooth on the customer's screen**. These are different problems:

- **Server-side:** Rider app sends GPS coordinate every 5-10 seconds (not continuously — see interval table below)
- **Client-side:** Customer app receives a new coordinate every ~5 seconds, but **animates the marker smoothly from old position to new position over ~3-4 seconds** using simple interpolation, instead of "teleporting" the dot

This is exactly the trick Zomato/Swiggy use. The backend doesn't need to be a real-time streaming system — the illusion of live motion is built on the client.

```
Server sends:  Point A (0s) -----------> Point B (5s) -----------> Point C (10s)
Customer sees: A -smoothly slides-> B -smoothly slides-> C
               (interpolated over the 5s gap, not a jump)
```

**Implementation (Android/Kotlin, osmdroid):** on receiving a new lat/lng, run a `ValueAnimator` over ~4000ms that interpolates marker position between old and new coordinates using linear interpolation (or better, along the road-snapped path if OSRM route is available), updating the osmdroid `Marker` position + `invalidate()` on each animation frame (~16ms ticks = 60fps).

---

## 3. GPS Ping Interval Strategy (Rider App Side)

Sending GPS every second would drain the rider's battery and flood the PHP server with writes. Instead, interval adapts to context — all values configurable from Admin Panel (`app_settings` table):

| Rider State | Ping Interval | Reasoning |
|---|---|---|
| Online, no active order | 60s | Just needs "last seen" for restaurant dashboard, no urgency |
| Assigned, heading to restaurant | 10s | Needs reasonable freshness, not yet visible to customer |
| Picked up, heading to customer | **5-7s** | This is what the customer is watching live — matches Zomato's real-world interval |
| Stationary (speed ~0 for >30s, e.g. red light) | 20s | No point pinging fast if not moving — saves battery/data |
| Within ~300m of customer | 3-5s | Tightest tracking right at the end, matches the "rider is arriving" excitement moment |

**Detection of "stationary":** rider app compares last 2-3 GPS readings; if movement < ~10 meters over 30 seconds, switch to the idle interval automatically, then back to active interval once movement resumes. This logic lives entirely in the Android app's location service — server doesn't need to know about it.

---

## 4. Customer/Restaurant Side: Polling, Not Sockets

Since InfinityFree can't run WebSocket servers, the Customer App's live tracking screen simply calls:

```
GET /orders/{id}/track
```

every **4-5 seconds** while the tracking screen is open (and pauses/stops when the app is backgrounded, to save battery and server load). This is a plain HTTP GET — cheap, stateless, works on any free PHP host.

**Server cost control:** this endpoint reads only `riders.last_lat/last_lng` (a denormalized, indexed lookup on the `riders` table) — **not** a query against the full `rider_locations` history table. The history table is for the audit trail and future analytics/heatmaps, never for the live polling path. This keeps the hot-path query trivially fast even at thousands of concurrent orders.

---

## 5. Route Line + ETA (OSRM)

For the visual road-snapped route line (the polyline Zomato draws from rider to customer) and ETA:

- **Android app** calls the free public OSRM demo server directly (`https://router.project-osrm.org/route/v1/driving/{lng1},{lat1};{lng2},{lat2}?overview=full&geometries=polyline`) — this is a GET call from the phone, not through your PHP backend, so it costs your server nothing.
- Response includes a polyline (decoded and drawn on the osmdroid map as the route line) and `duration` (used as the ETA shown to the customer).
- **Recalculation frequency:** the full route doesn't need to be re-fetched every ping — only every ~30-45 seconds, or when the rider deviates significantly from the last known route. The marker animation (Section 2) handles the smooth motion between recalculations.

> **Future note:** the public OSRM demo server has fair-use limits and no uptime guarantee. Once you have real order volume, self-hosting OSRM (it's an open-source Docker container, run on a small VPS) is a documented, drop-in replacement — same API shape, so no app changes needed, just a URL swap in a config value.

---

## 6. Full Data Flow, Order to Delivery

```
Rider App (background service)
   │  every N seconds (adaptive interval)
   ▼
POST /rider/location  { lat, lng, order_id, speed }
   │
   ▼
PHP: INSERT INTO rider_locations (audit trail)
     UPDATE riders SET last_lat=, last_lng= (fast-read cache)
   │
   │◄──────────────── Customer App polls every 4-5s ─────────────┐
   ▼                                                              │
GET /orders/{id}/track  →  reads riders.last_lat/last_lng ────────┘
   │
   ▼
Customer App: receives new point → ValueAnimator interpolates
              marker smoothly from old position to new position
              over ~4s → looks like continuous live motion
```

---

## 7. Battery Optimization (Rider App)

- Location updates run via Android's `FusedLocationProviderClient`-equivalent (or plain `LocationManager` since no Google Play Services billing dependency needed — osmdroid doesn't require Play Services at all, which is a nice side benefit of skipping Google Maps).
- Foreground service with a persistent notification ("Anydrop: Delivering Order #1234") — required by Android for background location access, and reassures the rider the app is tracking correctly.
- GPS accuracy request set to `PRIORITY_BALANCED_POWER_ACCURACY` rather than high-accuracy when idle, switching to high-accuracy only during active delivery.
- Location updates stop entirely (not just slow down) when rider goes offline.

---

## 8. Server Load at Scale — Sanity Check

At 1,000 concurrent active deliveries, with 5-7s ping interval: ~150-200 location writes/second across the whole platform. This is a simple indexed `INSERT`/`UPDATE` on MySQL — well within InfinityFree's capability for moderate scale, and trivial for a real VPS later. The design deliberately avoids anything that wouldn't survive this scale check, per your original "would this work at 10/100/1000/10000 restaurants" principle.
