# AnyDrop V1 → V2 Migration Options

## Purpose

This document defines two possible strategies for migrating AnyDrop from the current PHP V1 API to a future Node.js V2 API.

The current AnyDrop application should continue using the existing V1 API until V2 is production-ready.

---

# Current Architecture

At present:

```text
AnyDrop Apps
 ├── Customer App
 ├── Rider App
 └── Restaurant App
        │
        ▼
 Existing API Base URL
        │
        ▼
      /api/v1/
        │
        ▼
     PHP V1 API
        │
        ▼
       MySQL
```

The Admin Panel and existing web endpoints should remain unchanged during the initial V2 development phase.

---

# OPTION 1 — Force Update + API Gateway

## Recommended option for critical/business APIs

In this strategy, V2 is introduced through a new application release.

### Migration flow

```text
Existing App
     │
     ▼
 PHP V1
```

Build and test Node.js V2 separately.

When V2 is production-ready:

```text
Force Update
     │
     ▼
 New App Version
     │
     ▼
 API Gateway
     │
     ├── V1 PHP
     │
     └── V2 Node.js
```

The new application version can use the gateway/domain architecture.

## Gradual rollout

Do not move every user to V2 immediately.

Example:

```text
Stage 1:  V1 = 95%   V2 = 5%
Stage 2:  V1 = 80%   V2 = 20%
Stage 3:  V1 = 50%   V2 = 50%
Stage 4:  V1 = 20%   V2 = 80%
Stage 5:  V1 = 0%    V2 = 100%
```

The gateway can assign users to a stable V1/V2 bucket so a user does not randomly switch versions on every request.

## Important

The force update should move users to a compatible app version. It should NOT mean that V1 is immediately shut down.

Keep V1 alive during the migration period for:

- old app versions
- rollback
- failed updates
- emergency fallback
- users who have not completed the update

## Multiple V2 API servers

Once V2 has significant traffic:

```text
                    api.anydrop.in
                           │
                      API Gateway
                           │
                         V2
                           │
                    Load Balancer
                           │
             ┌─────────────┼─────────────┐
             ▼             ▼             ▼
           V2-1          V2-2          V2-3
```

The application should never know individual server IPs.

## Advantages

- Safer migration for orders, wallet and payment flows.
- V2 can be designed with a clean API contract.
- Easier rollback.
- Easier compatibility testing.
- V1 and V2 can coexist.
- Multiple V2 servers can be added later.
- Clear control over the application version.

## Disadvantages

- Requires a new app release.
- Users can delay updates unless the update is enforced.
- Old V1 support must remain temporarily.

---

# OPTION 2 — Direct Subdomain + Gateway, No App Update

## Concept

The existing app continues making requests that contain `/api/v1/`, but traffic is intercepted/routed by a gateway.

Example:

```text
Existing App
     │
     ▼
https://api.anydrop.in/api/v1/...
     │
     ▼
 API Gateway
     │
     ├── V1 → PHP
     │
     └── V2 → Node.js
```

The gateway can decide which backend handles a request.

For example:

```text
User A → V1
User B → V1
User C → V2
User D → V1
User E → V2
```

A stable user-based rollout can be used:

```text
0–79   → V1
80–99  → V2
```

This gives approximately:

```text
80% V1
20% V2
```

without requiring an app update.

## Critical technical requirement

The gateway must provide compatibility between the old `/api/v1/` client contract and V2.

If the old app expects:

```text
/api/v1/orders
```

and V2 internally exposes:

```text
/api/v2/orders
```

the gateway cannot simply redirect the request blindly.

It must route/translate the request so the old application receives the response format it expects.

Therefore V2 must initially be sufficiently compatible with the V1 client contract, or an adapter/compatibility layer must exist.

## Advantages

- Existing users do not need an app update.
- Gradual backend migration is possible.
- Gateway can control V1/V2 traffic centrally.
- Fast rollback from V2 to V1.

## Disadvantages

- More complicated gateway/compatibility logic.
- V2 must remain compatible with the existing app contract.
- Harder for major API contract changes.
- More testing is required.
- Critical payment/wallet/order changes become more sensitive.

---

# OPTION 1 vs OPTION 2

| Feature | Option 1: Force Update | Option 2: No Update |
|---|---|---|
| Existing users need update | Yes | No |
| V1/V2 gradual rollout | Yes | Yes |
| Gateway required | Yes, for rollout architecture | Yes |
| Major API contract changes | Easier | Harder |
| Old app compatibility | Easier to control | Must be maintained |
| Rollback | Easy | Easy |
| Implementation complexity | Medium | High |
| Orders/wallet/payment migration | Safer | More complex |
| Recommended for AnyDrop | **Yes** | Advanced option |

---

# Recommended AnyDrop Strategy

Use **Option 1 as the primary migration strategy**.

### Phase 1 — Now

Keep the current V1 architecture:

```text
App
 ↓
/api/v1/
 ↓
PHP V1
```

Do not change working production endpoints just because V2 is planned.

### Phase 2 — Build V2

Build Node.js V2 independently while keeping the existing V1 API operational.

Both versions should follow a defined API contract.

### Phase 3 — Introduce production API domain

Use:

```text
https://api.anydrop.in/
```

as the long-term API domain.

Versioned endpoints can be:

```text
/api/v1/...
/api/v2/...
```

### Phase 4 — Release compatible new app

Force-update users to a new app version that is designed for the new API architecture.

### Phase 5 — Gradual rollout

Example:

```text
5% V2
   ↓
20% V2
   ↓
50% V2
   ↓
80% V2
   ↓
100% V2
```

Monitor:

- API error rate
- latency
- HTTP 4xx/5xx
- order creation failures
- payment failures
- wallet/ledger consistency
- rider dispatch failures
- notification failures
- database errors

If a serious problem appears:

```text
V2 → 0%
```

and send traffic back to V1.

### Phase 6 — Scale V2

After V2 reaches stable production:

```text
api.anydrop.in
       │
       ▼
Load Balancer
       │
 ┌─────┼─────┐
 ▼     ▼     ▼
V2-1  V2-2  V2-3
       │
       ▼
 Shared/authoritative data layer
```

Add more V2 API servers horizontally as traffic grows.

### Phase 7 — Retire V1

Only after:

1. Old app versions are sufficiently retired.
2. V2 has been stable for a reasonable period.
3. Critical business flows have been validated.
4. No important clients depend on V1.
5. Rollback requirements are satisfied.

Then:

```text
V1 → decommission
V2 → 100%
```

---

# Admin Panel and Existing Web APIs

Do NOT automatically rewrite every Admin Panel endpoint when V2 is created.

Initially:

```text
Admin Panel
     ↓
Existing Admin/PHP endpoints
     ↓
PHP V1
```

Migrate Admin APIs separately when their Node.js equivalents are ready and tested.

The same principle applies to existing web endpoints: keep working endpoints stable unless there is a specific migration requirement.

---

# Multiple API Servers vs V1/V2

These are two different concepts.

## V1/V2

This is an API version:

```text
V1 = PHP implementation
V2 = Node.js implementation
```

## Multiple API servers

This is horizontal scaling:

```text
V2
 ├── Server 1
 ├── Server 2
 └── Server 3
```

They can coexist:

```text
                 API Gateway
                      │
            ┌─────────┴─────────┐
            ▼                   ▼
       PHP V1 cluster       Node V2 cluster
       ├── V1-1             ├── V2-1
       ├── V1-2             ├── V2-2
       └── V1-3             └── V2-3
```

---

# Final Recommendation

For AnyDrop:

**Now:** keep `/api/v1/`.

**When V2 is production-ready:** introduce `api.anydrop.in` as the long-term API entry point.

**Preferred migration:** Force Update + Gateway + gradual V1/V2 rollout.

**Alternative:** Direct subdomain + compatibility gateway without requiring an app update.

Do not expose individual API server IPs to mobile apps.

Do not create separate independent databases for V1 and V2 just because two API versions exist. Keep one authoritative data source unless a later scaling architecture specifically requires replication/sharding.

The goal is:

```text
Mobile Apps
     │
     ▼
api.anydrop.in
     │
     ▼
Gateway / Load Balancer
     │
     ├── V1 PHP cluster
     │
     └── V2 Node cluster
              │
              ▼
        Authoritative DB
```
