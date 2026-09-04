# AnyDrop Rider App — Deep Feature & Engineering Plan

**Version:** 1.0  
**Date:** 02 Sep 2026  
**Scope:** Complete Rider/Delivery Partner system — backend, Android, dispatch, tracking, delivery, earnings, COD, admin integration and customer/restaurant integration.

> This document is the working implementation plan for the Rider system. It supersedes the old generic roadmap statements that simply listed Rider as "not implemented". The project already has Rider Phase 1 signup/auth/status infrastructure; this plan starts from that actual state.

---

# 1. Current Baseline

## Already implemented

### Backend Phase 1

- Rider self-signup.
- Email OTP request.
- Email OTP verification.
- Existing rider OTP login.
- Platform rider model using `restaurant_id = NULL`.
- Service-area hierarchy API.
- GPS-based service-area resolution.
- Explicit dropdown service-area selection.
- Dropdown selection takes precedence over GPS resolution.
- Rider status lifecycle: `pending`, `approved`, `rejected`, `suspended`.
- Signup rate limiting.
- Rider auth tokens.
- Admin Rider approval/rejection/suspension/reactivation screen.
- Rider audit events.
- `username` / `password_hash` made nullable for platform riders.
- Shared email OTP infrastructure with rider-specific purpose.

### Existing Rider Android foundation

- Splash.
- Email login.
- OTP verification.
- New-rider signup.
- GPS location permission and service-area detection.
- Cascading service-area dropdown.
- Application status screen.
- Pending/rejected/suspended handling.
- Session persistence.
- Logout.
- Error-body parsing.

### Existing platform backend foundations relevant to Rider

- `orders.rider_id`.
- Order statuses including `ready`, `rider_assigned`, `picked_up`, `out_for_delivery`, `delivered`.
- `delivery_otp`, `otp_verified_at`, `otp_attempts`.
- `rider_locations` audit table.
- `riders.last_lat`, `last_lng`, `last_location_at`.
- `riders.is_online`.
- FCM token storage.
- Rider COD ledger.
- COD cash-held balance and settlement limit.
- Admin rider settlement screen.
- Customer order tracking endpoint.
- Admin order detail with latest rider location support.
- Notification helper infrastructure supporting rider recipients.

---

# 2. Product Goal

The Rider App must support the full lifecycle:

```text
Application
   ↓
Approval
   ↓
Complete Profile / Documents
   ↓
Go Online
   ↓
Become Eligible for Delivery
   ↓
Receive Offer
   ↓
Accept
   ↓
Navigate to Restaurant
   ↓
Pickup
   ↓
Navigate to Customer
   ↓
Delivery OTP / COD Collection
   ↓
Delivered
   ↓
Earning Generated
   ↓
Settlement / Payout
```

The backend remains the source of truth.

---

# 3. Phase Structure

## Phase R1 — Foundation

**Status: mostly complete**

1. Rider schema.
2. Signup.
3. OTP.
4. Login.
5. Approval queue.
6. Status handling.
7. Rate limiting.
8. Session/token.

Remaining: live migration + endpoint/device verification.

---

## Phase R2 — Rider Work Dashboard

### R2.1 `rider-me.php`

Add a lightweight authenticated endpoint:

```http
GET /api/v1/rider/me.php
Authorization: Bearer <token>
```

Returns:

- rider id
- name
- email
- mobile
- status
- service area
- vehicle
- is_online
- current order
- cod_cash_held
- settlement limit

Purpose: status refresh and dashboard bootstrap without forcing another OTP login.

### R2.2 Dashboard

Build:

- Approved rider home.
- Online/offline switch.
- Current delivery card.
- Today's earnings.
- Completed count.
- COD cash held.
- Notification entry.

### R2.3 Online state API

```http
POST /rider/status
```

Actions:

- online
- offline

Server checks:

- rider exists.
- rider is active.
- rider is approved.
- rider is not suspended.
- service area is valid.
- COD cash limit rules where applicable.
- no invalid active-delivery state.

When going offline:

- stop new assignment eligibility.
- stop background location if no active delivery.
- preserve current active delivery if one exists.

---

# 4. Phase R3 — Delivery Assignment Engine

This is the most important backend feature.

## 4.1 Assignment principles

Never assign an order purely by nearest distance.

Eligibility should consider:

1. Approved status.
2. Active account.
3. Online state.
4. Valid service area.
5. No conflicting active order, unless multi-order batching is explicitly enabled later.
6. Recent location freshness.
7. Distance to restaurant.
8. COD cash-held limit for COD orders.
9. Rider cooldown / recent rejection rules where configured.
10. Optional workload/fairness score.

---

## 4.2 Candidate selection

For a ready order:

```text
Restaurant coordinates
        ↓
Find online approved riders
        ↓
Fresh last location required
        ↓
Service-area eligibility
        ↓
Distance ranking
        ↓
Filter COD eligibility
        ↓
Choose candidate(s)
```

Initial ranking:

```text
score =
    distance_weight
  + freshness_penalty
  + active_work_penalty
  + fairness_adjustment
```

Start simple. Do not build a machine-learning dispatch system in V1.

---

# 5. Assignment Database Design

Add a dedicated assignment/offer table rather than storing all dispatch state directly on `orders`.

Recommended table:

```sql
rider_order_assignments
-----------------------
id
order_id
rider_id
status              -- offered/accepted/rejected/expired/cancelled
attempt_no
offered_at
expires_at
responded_at
reject_reason
created_at
updated_at
```

Indexes:

```text
(order_id, status)
(rider_id, status)
(expires_at, status)
```

### Why this table is necessary

Without it, you cannot reliably answer:

- Who was offered the order?
- Who rejected it?
- How many attempts happened?
- Did the offer expire?
- Why was the order reassigned?
- Which rider was the previous assignee?

---

# 6. Assignment State Machine

```text
READY
  │
  ▼
OFFER CREATED
  │
  ├── Accept ──────→ RIDER ASSIGNED
  │
  ├── Reject ──────→ NEXT RIDER
  │
  └── Timeout ─────→ NEXT RIDER
                         │
                         └── no candidate → UNASSIGNED / ADMIN ATTENTION
```

The existing order ENUM should remain the business state machine:

```text
ready
  ↓
rider_assigned
  ↓
picked_up
  ↓
out_for_delivery
  ↓
delivered
```

Assignment attempts belong in the assignment table.

---

# 7. Accept / Reject APIs

Recommended endpoints:

```http
GET  /rider/orders/available.php
GET  /rider/orders/current.php
POST /rider/orders/{id}/accept.php
POST /rider/orders/{id}/reject.php
```

### Accept transaction

Must lock the order and verify:

- status is still `ready`.
- order is not already assigned.
- rider is still online/approved.
- offer is still valid.
- rider is eligible.

Then atomically:

```text
assignment.status = accepted
orders.rider_id = rider_id
orders.status = rider_assigned
```

Create order status history:

```text
changed_by_type = rider
changed_by_id = rider.id
```

If another rider wins the order first, return a clear conflict instead of creating duplicate assignment.

---

# 8. Assignment Timeout

Admin-configurable setting:

```text
rider_assignment_timeout_seconds
```

Initial suggestion: **30–45 seconds**.

Process:

```text
Offer created
   ↓
No response
   ↓
Expired
   ↓
Select next candidate
```

For free/shared hosting, do not depend on a permanent worker process.

Possible V1 implementation:

- Opportunistic cleanup when assignment endpoints are called.
- Admin/cron cleanup when cron is available.
- Later move to a proper queue/worker on VPS.

---

# 9. Rider Order Detail

API should return only what Rider needs:

- order code
- restaurant name/address/location
- customer name/address/location
- item count
- order total where relevant
- payment method
- COD amount
- delivery instructions
- estimated distance
- current status
- delivery OTP state, never the OTP itself until policy allows display/verification workflow

Do not return unnecessary customer profile data.

---

# 10. Pickup Flow

## Restaurant side

When restaurant marks an order `ready`:

```text
ready
 ↓
Dispatch engine
 ↓
Rider offer
```

After rider accepts:

```text
rider_assigned
```

Rider sees:

- restaurant address
- navigate button
- call restaurant
- order code
- pickup confirmation

### Pickup API

```http
POST /rider/orders/{id}/pickup.php
```

Server checks:

- rider owns the order.
- status is `rider_assigned`.
- rider is active.
- optional geofence rule if enabled.

Then:

```text
status = picked_up
picked_up_at = NOW()
```

---

# 11. Out-for-Delivery Flow

After pickup:

```text
picked_up
    ↓
out_for_delivery
```

Whether this is an explicit rider action or automatically follows pickup should be decided once and kept consistent. Recommended V1: **pickup confirmation immediately transitions the order to `out_for_delivery`** after the backend validates pickup.

This avoids an unnecessary extra tap.

---

# 12. Live Location System

Use the project's existing live-tracking architecture as the baseline.

### Rider GPS intervals

| State | Target interval |
|---|---:|
| Online, no active order | ~60s |
| Heading to restaurant | ~10s |
| Heading to customer | ~5–7s |
| Stationary | ~20s |
| Very close to customer | ~3–5s |

These should become Admin-configurable settings with safe minimums.

### Data flow

```text
Android Foreground Location Service
        ↓
POST /rider/location
        ↓
Validate rider + active order
        ↓
INSERT rider_locations
        ↓
UPDATE riders.last_lat/last_lng/last_location_at
```

`riders.last_lat/last_lng` is the hot-read cache.

`rider_locations` is history/audit.

---

# 13. Location API

Recommended:

```http
POST /rider/location
```

Body:

```json
{
  "latitude": 26.9124,
  "longitude": 75.7873,
  "speed_kmh": 32.4,
  "order_id": 123
}
```

Validation:

- Authenticated rider.
- Approved/active.
- Valid latitude/longitude.
- If `order_id` supplied, it must belong to rider.
- Reject absurd coordinate jumps.
- Record server time, not only client time.

Never trust the client to write arbitrary rider/order combinations.

---

# 14. Live Tracking Provider Decision

The older `docs/03_Live_Tracking.md` contains an OSMDroid + OSRM plan, but that provider decision is superseded by the later project decision to use **Google Maps**.

Use:

- Google Maps SDK for Android.
- Directions API for route calculation.
- PHP/MySQL backend for rider location source of truth.
- HTTP polling for live customer tracking rather than WebSockets in the current hosting architecture.

### Marker animation

Server does not need to stream every frame.

```text
Point A
  ↓
Point B after ~5s
  ↓
Android interpolates marker between A and B
```

This creates smooth motion without high-frequency server writes.

---

# 15. Customer Tracking Integration

Existing customer tracking endpoint already reads rider location information.

Customer active-order tracking should show:

```text
Restaurant
   ↓
Route
   ↓
Rider marker
   ↓
Customer
```

Polling target:

**~4–5 seconds while the tracking screen is active**, with background polling stopped/paused.

Route recalculation should be much less frequent than location polling, roughly **30–45 seconds or on significant route deviation**.

---

# 16. Delivery OTP

Existing order schema contains:

- `delivery_otp`
- `otp_verified_at`
- `otp_attempts`

Recommended flow:

```text
Customer receives/has OTP
       ↓
Rider asks customer
       ↓
Rider enters OTP
       ↓
POST /rider/orders/{id}/deliver.php
       ↓
Server verifies OTP
       ↓
Transaction
       ├── otp_verified_at
       ├── status = delivered
       ├── delivered_at
       ├── rider earning entry
       └── COD ledger entry if COD
```

If OTP is wrong:

- increment attempts.
- never change order status.
- lock after configured maximum attempts.

---

# 17. COD Collection

Existing project decision:

**COD cash collected by the Rider belongs to the platform and is later settled to Admin. It does not belong to the restaurant.**

On successful COD delivery:

```text
Order delivered
     ↓
COD amount
     ↓
rider_cod_ledger
     ↓
riders.cod_cash_held += amount
```

The rider app should display the running cash-held amount separately from earnings.

---

# 18. COD Settlement Limit

Existing setting:

```text
rider_cod_settlement_limit
```

V1 behavior:

```text
cash held < limit
     ↓
COD eligible

cash held >= limit
     ↓
flag/block additional COD assignment
     ↓
settle with admin
```

The exact blocking policy should be server-side and configurable.

---

# 19. Rider Earnings

This is still a business-rule decision and should **not** be hardcoded prematurely.

Recommended model:

```text
base_delivery_fee
+ distance_component
+ peak/incentive
+ approved bonus
- adjustments
= rider_earning
```

Possible pricing models:

### V1 recommended

Flat base + per-km component with an admin-configurable minimum.

Example only:

```text
Base = ₹25
Per km = ₹6
Minimum = ₹30
```

These are placeholders, not final commercial values.

### Future

- Peak hours.
- Rain/surge.
- Area incentives.
- Streak bonuses.
- Acceptance-rate campaigns.
- Guaranteed minimums.

All financial rules must be server-side.

---

# 20. Rider Ledger

Create a dedicated rider earning ledger, separate from `rider_cod_ledger`.

Recommended table:

```text
rider_earnings_ledger
---------------------
id
rider_id
order_id
entry_type
amount
running_balance
note
created_at
```

Possible entry types:

- delivery_earning
- incentive
- bonus
- adjustment_credit
- adjustment_debit
- payout

Do not mix COD cash-holding entries with earnings entries.

---

# 21. Payouts

Recommended flow:

```text
Rider earnings balance
        ↓
Payout request / scheduled payout
        ↓
Admin review / automatic payout
        ↓
Processing
        ↓
Completed / Failed
```

Rider should see:

- available earnings.
- pending earnings.
- payout history.
- payout status.

Bank/UPI details should be masked in the UI.

---

# 22. Rider Documents

Migration 69 already added:

- `vehicle_doc_url`
- `id_doc_url`

Next rider profile stage should support:

- ID document.
- Vehicle document.
- Vehicle type.
- Vehicle number.
- Optional profile photo.

Admin should see document status and be able to reject/suspend for compliance reasons.

Do not upload arbitrary files directly into a public web directory without access controls.

---

# 23. FCM / Notifications

Rider notification events:

### Assignment

- New delivery offer.
- Assignment accepted.
- Assignment expired.
- Assignment cancelled.

### Order

- Restaurant ready.
- Customer cancellation.
- Admin cancellation.
- Delivery issue.

### Finance

- Earning posted.
- Payout initiated.
- Payout completed.
- COD settlement warning.

### Account

- Approved.
- Rejected.
- Suspended.
- Reactivated.

Use FCM for time-sensitive events. Store notifications in the existing notification system for in-app history.

---

# 24. Restaurant Integration

Restaurant flow becomes:

```text
Restaurant accepts
       ↓
Preparing
       ↓
Ready
       ↓
Dispatch
       ↓
Rider assigned
       ↓
Rider pickup
       ↓
Out for delivery
       ↓
Delivered
```

Restaurant should see:

- assigned rider name.
- rider contact action where policy allows.
- rider status.
- pickup progress.

Do not let the restaurant directly change rider assignment unless Admin permission/business rules explicitly allow it.

---

# 25. Admin Rider Command Center

The existing `admin/riders.php` is approval/status management. Extend the Admin system later with:

### Rider list

- Online/offline.
- Approved/pending/rejected/suspended.
- Current order.
- Last seen.
- Service area.
- COD cash held.
- Earnings.

### Rider detail

- Profile.
- Documents.
- Orders.
- Location history.
- Earnings.
- COD ledger.
- Settlement.
- Audit log.

### Live map

Show:

- online riders.
- active deliveries.
- last location.
- stale-location warning.

---

# 26. Fraud / Abuse Controls

Minimum V1 controls:

### Location

- Impossible-speed detection.
- Large coordinate-jump detection.
- Stale GPS detection.
- Server timestamps.

### Order

- One active order per rider in V1.
- Server-side ownership checks.
- Assignment conflict locking.
- Idempotency for state-changing endpoints.

### Delivery

- OTP attempt limits.
- Delivery completion only through server validation.
- Optional restaurant/customer geofence later.

### Finance

- Rider earning ledger immutable except controlled adjustments.
- COD ledger immutable except admin adjustments.
- Audit every manual adjustment.

---

# 27. Offline / Network Failure

Rider app must be usable when mobile connectivity temporarily drops.

### Offline behavior

- Show network banner.
- Cache current delivery details.
- Keep navigation available using the external maps/navigation app if possible.
- Queue safe location pings for short periods only.
- Do not queue arbitrary order-state transitions without server confirmation.

Critical rule:

> A rider may tap `Confirm Pickup` or `Delivered` offline, but the UI must clearly show **Pending Sync** and the backend must remain the final authority. For delivery completion, OTP verification should normally require an online server response.

---

# 28. Background Location Architecture

Use an Android foreground service during active delivery.

Responsibilities:

- Receive fused GPS updates.
- Select adaptive interval.
- Send authenticated pings.
- Maintain persistent notification.
- Stop location updates when offline/no active delivery.
- Recover after temporary network loss.

Required Android permissions must be handled explicitly and only when necessary.

Do not request background location permission during initial signup unless product requirements genuinely require it.

---

# 29. API Contract Checklist

Required Rider API surface:

```text
Auth
├── request OTP
├── verify OTP
└── signup

Profile
├── me
├── update profile
└── documents

Availability
├── online
└── offline

Orders
├── current
├── available
├── accept
├── reject
├── pickup
├── deliver
└── history

Location
└── location ping

Earnings
├── summary
├── ledger
└── payouts

COD
├── cash summary
└── ledger

Notifications
├── list
├── mark read
└── mark all read

Support
├── create ticket
├── list tickets
└── ticket detail/reply
```

Every authenticated endpoint must validate the rider owner type.

---

# 30. Database Additions — Recommended Order

Do not create all tables at once. Use small migrations.

### Migration R2

- rider operational settings if needed.
- indexes for online/location lookup.

### Migration R3

- `rider_order_assignments`.

### Migration R4

- rider earnings ledger.
- payout tables if not already present.

### Migration R5

- rider document metadata/status if existing URL columns become insufficient.

### Migration R6

- optional incentive/peak rules.

Every migration must be idempotent according to the project's existing migration style.

---

# 31. Security Rules

1. Rider token must identify `owner_type = rider`.
2. Never trust `rider_id` supplied by the client.
3. Derive rider identity from auth token.
4. Verify order ownership on every rider order action.
5. Use database transactions for assignment/state changes.
6. Lock rows during accept/deliver operations where race conditions are possible.
7. Use idempotency keys for critical state-changing requests.
8. Do not expose delivery OTP through tracking APIs.
9. Do not expose customer sensitive data unnecessarily.
10. Do not log auth tokens or OTPs in production.
11. Remove HTTP cleartext configuration before production.
12. Replace localhost BASE_URL before device/live deployment.
13. Production FCM and map keys must be restricted appropriately.

---

# 32. Performance Targets

### Assignment

Target: sub-second to a few seconds under normal load.

### Location

Do not query the full location history table for live tracking.

Use:

```text
riders.last_lat
riders.last_lng
riders.last_location_at
```

### Tracking

Customer polling approximately every 4–5 seconds while actively viewing tracking.

### History

Paginate rider locations and earnings; never load unlimited history.

---

# 33. Failure Scenarios to Design Before Coding

### Scenario A

Two riders accept simultaneously.

**Expected:** exactly one wins; second receives conflict.

### Scenario B

Rider accepts after offer expiry.

**Expected:** rejected by server.

### Scenario C

Restaurant cancels after rider assignment.

**Expected:** rider immediately notified; assignment/order resolved according to cancellation policy.

### Scenario D

Rider goes offline with active order.

**Expected:** order is not silently abandoned; rider remains responsible for current order while new offers stop.

### Scenario E

GPS stops updating.

**Expected:** rider/customer/admin see stale location state.

### Scenario F

Customer gives wrong OTP.

**Expected:** attempt count increases; order remains undelivered.

### Scenario G

COD cash reaches limit.

**Expected:** rider is blocked from further COD assignments according to configured policy, not silently from all work unless that is explicitly configured.

### Scenario H

Network dies after rider taps pickup.

**Expected:** no false local completion; app retries or clearly shows pending state; server decides final state.

---

# 34. Development Sequence

## Milestone 1 — Approved Rider Dashboard

- `rider-me.php`.
- Dashboard.
- Online/offline.
- Current order placeholder.
- Profile header.
- FCM registration.

## Milestone 2 — Assignment Backend

- Assignment table.
- Candidate selection.
- Offer creation.
- Accept/reject.
- Timeout.
- Reassignment.
- Order locking.

## Milestone 3 — Rider Order UI

- Incoming offer.
- Current order.
- Restaurant details.
- Pickup.
- Navigation.

## Milestone 4 — Delivery

- Customer details.
- Out-for-delivery.
- Delivery OTP.
- COD collection.
- Delivered confirmation.

## Milestone 5 — Live Tracking

- Foreground location service.
- Adaptive pings.
- Rider marker.
- Customer polling.
- Route/ETA.
- Admin map.

## Milestone 6 — Money

- Earnings ledger.
- Earnings summary.
- COD ledger UI.
- Payouts.
- Admin settlement integration.

## Milestone 7 — Operations

- Notifications.
- Support.
- Documents.
- Safety/fraud controls.
- Analytics.

## Milestone 8 — Production Hardening

- Full build.
- Migration verification.
- Concurrency tests.
- Payment/order regression.
- Location battery testing.
- Network failure tests.
- Security audit.
- Production configuration.

---

# 35. What We Should NOT Build Yet

Avoid these until the core delivery loop is stable:

- Multi-order batching.
- AI dispatch.
- Complex incentive marketplace.
- Rider social features.
- Gamification.
- Excessive analytics.
- In-app chat with complex media.
- Custom navigation engine.
- Real-time WebSocket infrastructure.

First make this rock solid:

```text
Ready → Offer → Accept → Pickup → Out for Delivery → OTP → Delivered → Earning
```

---

# 36. Definition of Done — Rider System

Rider is not "done" when screens compile.

It is done when this complete flow works on a real device against the real backend:

```text
Signup
 ↓
Admin approval
 ↓
Login
 ↓
Go Online
 ↓
Restaurant marks order Ready
 ↓
Rider receives offer
 ↓
Accept
 ↓
Navigate to restaurant
 ↓
Pickup
 ↓
Customer sees rider tracking
 ↓
Rider navigates to customer
 ↓
Customer provides OTP
 ↓
Delivered
 ↓
COD ledger / payment handling
 ↓
Rider earning posted
 ↓
Customer order becomes delivered
 ↓
Restaurant order becomes delivered
 ↓
Admin sees final order + rider + ledger
```

Then test failure paths:

- reject.
- timeout.
- reassignment.
- cancellation.
- wrong OTP.
- offline/online.
- stale GPS.
- duplicate taps.
- concurrent accept.
- COD limit.
- payout failure.

---

# 37. Immediate Next Task

The next coding session should **not** start with decorative UI.

Start with:

### Backend

1. `rider-me.php`.
2. Online/offline endpoint.
3. Assignment schema/migration.
4. Candidate selection helper.
5. Offer/accept/reject APIs.
6. Assignment timeout/reassignment logic.

### Android

7. Approved Rider Dashboard.
8. Online/offline control.
9. Current order model/state handling.
10. Incoming order offer UI.

Then integrate them before moving to pickup/delivery.

This keeps the Rider system backend-first and prevents the Android UI from becoming disconnected from the actual order state machine.

