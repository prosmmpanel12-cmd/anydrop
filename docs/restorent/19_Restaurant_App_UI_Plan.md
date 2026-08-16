# Anydrop Restaurant App — UI Plan
Reference study: Zomato Restaurant Partner app, Swiggy Owner app + a few merchant-app concepts (Behance/Dribbble). Goal: bring Anydrop's restaurant app up to the same "feels professional" bar, not copy their branding.

---

## 1. What Zomato/Swiggy partner apps get right (patterns to borrow)

| Pattern | Why it works |
|---|---|
| **Bottom tab nav** (Orders / Menu / Analytics / Account) | Owner jumps between these constantly during service — a single top-bar link (like our current "Menu" text button) is too slow to reach mid-rush. |
| **Live order = countdown timer chip** on the card | Restaurant staff need to know "how long left to accept" at a glance, not by opening the order. |
| **Order status = horizontal stepper**, not a text label | Received → Preparing → Ready → Picked up. One glance tells kitchen + counter staff where things stand. |
| **Menu editor = category tabs at top + item rows with a thumbnail photo** | Photo-first browsing because owners scan by dish look, not just name. |
| **One-tap "Turn OFF menu item" switch right on the list row** | Out-of-stock happens mid-service; owner shouldn't need to open an edit form for it (we already do this right). |
| **Home screen = today's snapshot card** (orders today, earnings today, rating) above the order list | Gives the owner a reason to open the app even when there's no new order. |
| **Big, unmissable restaurant OPEN/CLOSED toggle**, always visible, never buried in settings | Single biggest lever an owner has; Zomato/Swiggy both pin it to the top of every screen. |

---

## 2. Anydrop's current state vs. target

We already have: Splash → Signup/Login → Dashboard (order list) → Order Detail → Menu Management (built last session).

Gaps vs. Zomato/Swiggy pattern:
- No bottom navigation — everything is squeezed into Dashboard's top bar (Menu button, Logout, status toggle all crammed together).
- Order cards show status as plain text, no visual stepper/timer.
- Menu items have no photo slot in the list row (upload isn't built yet either).
- No "today's snapshot" — owner opens app straight into a raw order list.
- No dedicated Analytics/Earnings screen at all yet.
- No Notifications/Account screen — profile & restaurant details aren't editable from the app yet.

---

## 3. Proposed navigation structure

```
Bottom Nav (4 tabs, always visible after login)
├── 🧾 Orders      (current Dashboard, redesigned — see §4)
├── 📋 Menu        (existing Menu Management screen — see §5)
├── 📊 Insights     (NEW — today's stats, past earnings — see §6)
└── 👤 Account      (NEW — restaurant profile, timings, logout — see §7)
```

The restaurant OPEN/CLOSED toggle stays pinned in a top bar **above** the bottom nav on every tab — same reasoning Zomato/Swiggy use it for.

---

## 4. Orders tab (redesigned Dashboard)

**Top, always visible:**
- Restaurant name + big OPEN/CLOSED pill switch (green/red), tap to confirm before closing.
- "Today" snapshot strip: Orders count · Earnings · Avg prep time — 3 small stat chips in a row.

**Order list, grouped into sections (not one flat list):**
1. **New (needs action)** — cards with a countdown ring/timer ("Accept within 1:45"), Accept / Reject buttons directly on the card.
2. **In progress** — horizontal status stepper (Preparing → Ready → Handed to rider) with a "Mark next step" button.
3. **Completed today** — collapsed, tap to expand.

Each order card: order #, item count + total, customer area (not full address for privacy), and the stepper/timer as described above.

---

## 5. Menu tab (extend what we built)

Keep the category-card structure already built, add:
- **Photo thumbnail** (60×60, rounded) on the left of every item row — placeholder icon until photo upload ships.
- **Category tabs as a horizontal scroll strip** at the top (Starters | Mains | Desserts...) instead of stacked cards, once a restaurant has 5+ categories — stacked cards get long to scroll through.
- Drag-handle icon on category rows for reordering (`sort_order` field already exists in the backend, UI just needs to send it on reorder).
- Small "🔍 Search menu" bar at the top — backend already supports `?search=` on `menu-items-list.php`, just needs wiring.

---

## 6. Insights tab (new)

- Date range selector (Today / This week / This month).
- Cards: Total orders, Total earnings, Average order value, Cancellation rate.
- Simple bar chart: orders per day (last 7 days).
- Top 5 best-selling items this week (uses `is_bestseller` flag we already track).

*(Backend note: none of this data is exposed yet — needs a new `restaurant/insights.php` endpoint aggregating the `orders` table. Flagging as the next backend piece, not building it in this plan doc.)*

---

## 7. Account tab (new)

- Restaurant profile: name, cuisine tags, address, opening/closing time — editable form (fields already exist in `restaurants` table).
- Bank/payout details section (view-only for now if payouts aren't built yet).
- Notification preferences toggle.
- Logout (moved here from the Dashboard top bar, decluttering it).

---

## 8. Visual language

### 8.1 Palette refresh (2026-08-16)

The original palette (kept unchanged through 2026-08-15, see the old
table below) was judged too flat to read as a considered design — plain
white top bar, plain white bottom nav, single orange accent, nothing else
doing any visual work. Picked a refreshed palette from a set of options
the app owner shared (Instagram-style color-pair cards), cross-checked
against current restaurant-dashboard / delivery-partner app UI trends
(dark structural chrome + one bright accent color is the common pattern
now, not flat white-everywhere):

| Token | Value | Use |
|---|---|---|
| Primary | `#F54F1B` ("Exotic Orange") | buttons, active tab, OPEN toggle, bottom nav selected icon |
| Primary dark | `#C93E12` | pressed states |
| Primary container | `#FFDED2` | chips, switch tracks |
| **Ink** (new) | `#1E223D` ("Midnight Blue") | top bar background, bottom nav background, status bar |
| Ink light (new) | `#2A2F52` | elevated dark surfaces / pressed states on ink |
| Text on ink (new) | `#FFFFFF` | text/icons on the ink top bar and bottom nav |
| Text on ink, muted (new) | `#9AA0C3` | unselected bottom nav icons |
| Veg green | `#2E7D32` | veg dot, veg switch |
| Non-veg red | `#C62828` | non-veg dot |

**Why this pair and not one of the other options offered:** food-app
color psychology is a real, well-documented constraint, not just
preference — warm hues (red/orange/yellow) read as appetizing and are
what nearly every major food brand uses (Swiggy, Zomato, DoorDash,
McDonald's); cool blues are established as appetite-*suppressing* and are
deliberately rare in F&B branding. That ruled out picking any of the
blue-led pairs (Champion Blue/Lavender Tonic, Atlantic Blue/Soft Sky
Blue, Imperial Blue/White Convolvulus) as the *primary* color, however
good they looked on their own. Exotic Orange kept the app in the same
warm family as the color it's replacing (`#E64A19` → `#F54F1B`) — brand
continuity, not a reskin from scratch — while reading more saturated and
energetic. Midnight Blue then filled a real gap the old palette didn't
have: a dark structural token for chrome, not content. It's used only on
the top bar, bottom nav, and status bar — not on white content surfaces
(`@color/surface`), so this is additive (a new second surface family) not
a full switch away from the light-content-on-white approach that's
already correct for order/menu cards.

**Implemented this pass (colors.xml, themes.xml, activity_main.xml,
bottom_nav_item_color.xml):** primary color values, status bar, top bar
background + text, bottom nav background + item tint states. Because
nearly every screen's buttons/switches/active states pull from
`colorPrimary`/`@color/anydrop_primary` rather than a hardcoded hex, this
one token-level change cascades automatically across the whole app
(confirmed no screen hardcodes the old `#E64A19`/`#B23C14`/`#FFE0D3` hex
values directly — grepped, only `colors.xml` itself had a comment
mentioning them).

**Not implemented this pass — flagged as follow-up, not silently
skipped:** individual screens that hardcode `@color/surface` (white) as
a full-screen background rather than inheriting from the shared chrome —
`activity_login.xml`, `activity_signup.xml`, `activity_otp_verify.xml`,
`activity_signup_success.xml`, `activity_splash.xml`,
`activity_order_detail.xml` — weren't touched. Those are pre-login /
detail screens outside the bottom-nav shell this pass focused on; giving
them a matching ink accent (e.g. a dark hero panel on login/splash) is a
reasonable next design pass but needs its own layout pass per screen,
not a token swap, so it wasn't safe to do blind in the time available
here.

### 8.2 Original tokens (superseded 2026-08-16, kept for history)

Prior palette — read close to Swiggy's warm-orange identity, changed
above for the reasons in §8.1, not because it was wrong:

| Token | Value | Use |
|---|---|---|
| Primary | `#E64A19` | buttons, active tab, OPEN toggle |
| Primary container | `#FFE0D3` | chips, switch tracks |
| Veg green | `#2E7D32` | veg dot, veg switch |
| Non-veg red | `#C62828` | non-veg dot |

New additions needed:
- A "warning/pending" amber (`#F9A825`-ish) for the order countdown timer as it runs low.
- A neutral gray-100 background for stat chips/cards so they sit apart from the white order cards.

Typography/spacing: keep current Material defaults — the issue isn't the type scale, it's the information hierarchy (flat lists vs. grouped/prioritized), which §4–§7 above address directly.

---

## 9. Loading states — skeleton screens & progress, designed alongside every screen (not bolted on after)

Zomato/Swiggy never show a blank white screen or a lone spinner in the middle — every screen that fetches data shows a **skeleton** (gray placeholder shapes matching the real layout) while loading, so the UI doesn't jump/reflow once data arrives. This has to be planned **per screen**, at the same time as the screen itself, not retrofitted later — the skeleton's shapes only make sense if they mirror that screen's actual final layout.

**Rule going forward: every new screen in this plan ships with its skeleton state from day one, not as a follow-up.**

### 9.1 When to use which loading pattern

| Situation | Pattern |
|---|---|
| First load of a list/card screen (Orders, Menu, Insights) | **Skeleton** — gray rounded rectangles shaped like the real cards/rows |
| Pull-to-refresh on a screen that already has data | Keep existing content visible, just the small spinner at the top (`SwipeRefreshLayout` — already used in Menu Management) |
| Button action (Login, Save item, Accept order) | **Inline progress** — button itself shows a small spinner and disables, text hidden, rather than a separate full-screen loader |
| Full-screen transition (Splash, OTP verify submitting) | Centered `ProgressBar`, tinted `anydrop_primary` — screen is intentionally blocking here |
| Image not yet loaded (menu item photo, once upload exists) | Gray placeholder box with a subtle shimmer, swapped for the real image on load |

### 9.2 Skeleton design per screen (mirrors §4–§7 layouts)

- **Orders tab**: 3 skeleton order cards stacked, each with a gray bar (order #), a shorter gray bar (item count/total), and a rounded gray blob (status stepper) — matches the real card's exact proportions from §4.
- **Menu tab**: 2 skeleton category cards, each with a gray title bar + 3 skeleton item rows (small square for the future thumbnail, two stacked bars for name/price) — matches §5.
- **Insights tab**: 4 skeleton stat chip boxes in a row + one gray rectangle where the bar chart will render — matches §6.
- **Account tab**: skeleton form — a few gray bars where the profile fields will be, since it's a single fetch-then-fill form.

### 9.3 Shimmer effect (the subtle "sheen" that sweeps across skeletons)

Not just static gray boxes — add a soft shimmer animation so it reads as "loading," not "broken." Implementation: a `ValueAnimator`-driven gradient (light gray → white → light gray) translated across each skeleton view, OR a single lightweight custom `ShimmerFrameLayout` wrapping the skeleton row layouts so it's written once and reused across Orders/Menu/Insights/Account skeletons — the second approach avoids duplicating animation code per screen.

### 9.4 Visual tokens for loading states

| Token | Value | Use |
|---|---|---|
| Skeleton base | `#E8E8E8` | resting shape color |
| Skeleton shimmer highlight | `#F5F5F5` | the sweeping sheen |
| Progress tint | `@color/anydrop_primary` | all `ProgressBar`/button-inline spinners, for brand consistency |

### 9.5 Build note

Skeleton layout XML for a screen is written **in the same PR** as that screen's real layout (see §9's rule above) — so each item in §10's build order below now includes its skeleton as part of that same step, not a separate later pass.

---

## 10. Build order (suggested, one PR-sized chunk at a time)

1. **Shared skeleton/shimmer building block** (`ShimmerFrameLayout` + a couple of reusable skeleton row shapes) — built first since everything below depends on it, per §9.
2. Bottom nav shell (4 tabs, empty Insights/Account screens as placeholders) — unblocks everything else.
3. Orders tab redesign: sections + timer + stepper + its skeleton state together (highest daily-use impact).
4. Menu tab: photo thumbnail slot + search bar wiring + its skeleton state together (backend already ready).
5. Account tab: profile edit form + its skeleton state together.
6. Insights tab: needs new backend endpoint first, then the UI + its skeleton state together.
7. ✅ **Pre-login/detail screens ink pass** (2026-08-16, done same day as flagged) — `bg_hero_curved.xml` (shared by login/signup) switched from the orange gradient to an `anydrop_ink`→`anydrop_ink_light` one; `activity_splash.xml`'s background moved from flat `anydrop_primary` to `anydrop_ink` (tagline recolored to `text_on_ink_muted`); `activity_otp_verify.xml` and `activity_order_detail.xml` got a shared ink header-bar treatment (back icon + title on `text_on_ink`); `activity_signup_success.xml` got a soft translucent-ink circle behind its success icon rather than a full ink background, since that screen's green success color/copy reads best on the light surface. Also applied the same header treatment to `activity_edit_profile.xml` (didn't exist when this item was first written, but its own header comment explicitly deferred to this pass — see `00_Status.md`'s 2026-08-16 entry).

Next up: Admin-side "Approve/Reject pending restaurants" screen (see NEXT_SESSION_PROMPT.md / doc 18's recommended build order) — increasingly overdue since self-signup produces pending rows with no way to approve them except a manual DB update. Still no build/compile verification anywhere in the project — see NEXT_SESSION_PROMPT.md's running list, now with this pass added to the unverified pile.
