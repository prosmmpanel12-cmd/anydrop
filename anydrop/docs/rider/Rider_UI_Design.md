# AnyDrop Rider App — UI / UX Design System & Screen Plan

**Version:** 1.0  
**Date:** 02 Sep 2026  
**Product:** AnyDrop Rider / Delivery Partner App  
**Status:** Design specification — implementation starts after Phase 1 signup/login/status foundation

---

## 1. Design Direction

The Rider App must feel like a **professional delivery-work tool**, not a clone of the Customer App.

Primary goals:

- Fast decisions while riding.
- Large touch targets.
- Very clear order state.
- Minimum typing during delivery.
- Map/navigation is the visual center during an active delivery.
- Earnings and workload are visible without becoming distracting.
- Strong online/offline state feedback.
- Use vector icons, not emoji icons.
- Motion should communicate state changes, not decorate every screen.
- Preserve the AnyDrop identity from the supplied rider logo: **deep green + neon green glow + white 3D mark/wordmark**.

The supplied rider logo is the visual reference for the app icon and brand treatment. The current Phase-2 placeholder launcher icon must eventually be replaced with the supplied logo-derived mipmap assets.

---

## 2. Visual Language

### 2.1 Brand palette

Use the logo as the source of truth rather than inventing unrelated colors.

| Token | Suggested role |
|---|---|
| `brandGreen` | Primary actions, active states, navigation highlights |
| `brandGreenDark` | Headers, dark surfaces, map overlays |
| `neonGreen` | Online/live indicator, success accent, important highlights |
| `surface` | Main light UI surface |
| `surfaceAlt` | Secondary cards/sections |
| `textPrimary` | Main content |
| `textSecondary` | Supporting content |
| `danger` | Reject, failed payment, suspension, critical error |
| `warning` | Pending, attention, cash limit |

The exact RGB/HEX values should be extracted from the final approved logo asset before release. Until then, keep the existing Rider theme's green family and avoid introducing a second unrelated brand palette.

### 2.2 Typography

- Large dashboard numbers: bold.
- Screen titles: semibold/bold.
- Order amount and earnings: strong emphasis.
- Supporting metadata: regular/medium.
- Avoid all-caps body text.
- Use Android system typography consistently with Customer/Restaurant apps.

### 2.3 Shape system

- Cards: 16–20dp radius.
- Buttons: 12–16dp radius.
- Status pills: fully rounded.
- Bottom sheets: 24dp top radius.
- Do not overuse floating cards over maps.

### 2.4 Icons

**Vector drawables only.**

Required icon families:

- Home
- Orders
- Earnings
- Profile
- Online/Offline
- Location
- Navigation
- Restaurant
- Customer
- Phone
- Chat/support
- Clock
- Cash
- Wallet
- Check
- Close
- Warning
- Info
- Refresh
- History
- Help
- Logout

Never use emoji as a UI icon.

---

## 3. App Navigation

Recommended authenticated navigation:

```text
                    Rider App
                       │
             ┌─────────┴─────────┐
             │                   │
          Offline              Online
             │                   │
        Dashboard          Delivery Dashboard
             │                   │
     ┌───────┼────────┬──────────┐
     │       │        │          │
   Home    Orders  Earnings   Profile
             │
        Order History
```

During an active delivery, navigation becomes **task-first**:

```text
Active Delivery
      │
      ├── Map / Route
      ├── Restaurant details
      ├── Customer details
      ├── Call
      ├── Navigate
      ├── Pickup confirmation
      ├── Delivery OTP
      └── Delivered → Earnings
```

The active order should remain reachable even if the rider opens another tab.

---

# 4. Screen Specifications

## 4.1 Splash

### Purpose
Brand introduction and session routing.

### Design

- Dark/deep-green background.
- Supplied AnyDrop Rider logo centered.
- Very subtle glow around logo.
- Logo scale-in animation.
- `AnyDrop Rider` subtitle.
- Short duration; no unnecessary loading screen.

### Existing state
Already implemented in the current Rider project. Replace only the placeholder icon/logo treatment with the approved supplied rider logo assets.

---

## 4.2 Login

### Layout

```text
        [AnyDrop Rider Logo]

        Welcome back
   Deliver. Earn. Grow.

   Email
   ┌──────────────────────┐
   │ name@example.com     │
   └──────────────────────┘

        [ Send OTP ]

      New Rider?
       Apply now
```

### Rules

- Email-first passwordless authentication.
- Large primary CTA.
- OTP cooldown must be visible.
- Backend error messages should map to actionable UI.
- Do not expose internal error codes.

Existing login/OTP flow is already implemented.

---

## 4.3 OTP Verification

### Layout

```text
← Back

Verify your email
OTP sent to a***@gmail.com

[ _ ][ _ ][ _ ][ _ ][ _ ][ _ ]

Resend OTP in 00:xx

[ Verify ]
```

### UX

- Auto-focus.
- Paste OTP support if available.
- Shake animation on invalid OTP.
- Success pop animation.
- Never clear all useful context after an error.

Existing flow is already implemented.

---

## 4.4 Signup

The existing Phase-1 signup is retained:

- Name
- Mobile
- State
- District
- City/Village
- Area
- GPS auto-detect
- Vehicle information when collected

### Improvements for final version

- Show a small map/location preview after GPS detection.
- Show `Detected area` separately from manually selected area.
- If GPS and dropdown differ, explicitly show which value will be submitted.
- Keep the form scrollable and keyboard-safe.

---

## 4.5 Application Status

Four visual states:

### Pending

```text
[ Clock icon ]
Application under review

We're reviewing your rider application.

Submitted
Service area
Vehicle

[ Refresh status ]
[ Logout ]
```

### Approved

```text
[ Check icon ]
You're approved

You're ready to start delivering.

[ Go Online ]
```

### Rejected

Show reason prominently, but professionally.

```text
Application not approved
Reason: <reason>

[ Contact Support ]
[ Logout ]
```

### Suspended

```text
Account suspended
Reason: <reason>

[ Contact Support ]
[ Logout ]
```

The approved state should route to the real Rider Dashboard once the delivery system is implemented.

---

# 5. Rider Dashboard

This is the main screen after approval.

## 5.1 Header

```text
Good morning, Altaf

[ ONLINE ● ]
```

The online toggle is one of the most important controls in the app.

### Offline

- Neutral/secondary treatment.
- Text: `You're offline`.
- CTA: `Go Online`.
- Explain that no new delivery offers will arrive while offline.

### Online

- Strong green/neon live indicator.
- Text: `You're online`.
- Show online duration.
- Allow `Go Offline` only when there is no active delivery, unless a safety/admin rule explicitly permits otherwise.

---

## 5.2 Dashboard cards

Recommended order:

1. **Today's earnings**
2. **Completed deliveries**
3. **Current cash held** for COD riders
4. **Current delivery**, if any
5. **Quick stats** — acceptance/completion metrics where useful

Example:

```text
┌────────────────────────────┐
│ Today's earnings           │
│ ₹ 486.00                   │
│ 8 deliveries              │
└────────────────────────────┘

┌────────────────────────────┐
│ COD cash held              │
│ ₹ 1,240 / ₹ 2,000          │
│ Settle before limit        │
└────────────────────────────┘
```

Do not turn the dashboard into a financial analytics screen. Detailed analytics belong in Earnings.

---

# 6. Incoming Order Offer UI

This screen must be extremely fast to understand.

```text
┌────────────────────────────┐
│ NEW DELIVERY               │
│                            │
│ Restaurant Name            │
│ 1.8 km away                │
│                            │
│ Pickup → Customer           │
│ ~4.2 km total              │
│                            │
│ COD / PREPAID              │
│                            │
│ Estimated earning          │
│ ₹ 42                       │
│                            │
│ [ REJECT ] [ ACCEPT ]      │
└────────────────────────────┘
```

### Rules

- Show countdown if assignment timeout is active.
- Accept must be the dominant action.
- Reject must require only one tap; reason can be collected after rejection if product policy requires it.
- Do not show excessive restaurant/menu information.
- New offer should arrive as FCM notification + in-app high-priority sheet.

---

# 7. Active Delivery Screen

This is the most important Rider screen.

## 7.1 Map-first layout

```text
┌────────────────────────────┐
│ ← Delivery #AD1234         │
│                            │
│         MAP                │
│       ● Rider              │
│        ─ Route             │
│                 ● Target   │
│                            │
│ ┌────────────────────────┐ │
│ │ PICKUP / DELIVERY      │ │
│ │ Restaurant Name        │ │
│ │ Address                │ │
│ │ 1.4 km • ~5 min       │ │
│ └────────────────────────┘ │
│                            │
│ [ Navigate ] [ Call ]     │
│                            │
│ [ Mark Picked Up ]        │
└────────────────────────────┘
```

### Map behavior

- Rider marker moves smoothly.
- Route line is shown.
- Current destination is obvious.
- Recenter button available.
- Do not continuously force camera movement; let rider control the camera.

The project's existing live-tracking plan uses adaptive GPS pings and client-side marker interpolation. The final map provider is **Google Maps**, per the later project decision; the older OSMDroid/OSRM document is only a reference for polling/animation mechanics.

---

# 8. Pickup Flow

## Restaurant arrival

Show:

- Restaurant name
- Address
- Order code
- Item count
- Customer/order summary only as needed
- Call restaurant
- Navigate

### Pickup confirmation

```text
At the restaurant?

Order #AD1234

[ Confirm Pickup ]
```

Once confirmed:

```text
picked_up
      ↓
out_for_delivery
```

The backend, not the Android client, is the authority for valid state transitions.

---

# 9. Customer Delivery Screen

After pickup, UI switches context from restaurant to customer.

Show:

- Customer name
- Delivery address
- Delivery instructions
- Distance/ETA
- Call customer
- Navigate
- COD amount, if COD
- Delivery OTP entry

Example:

```text
DELIVER TO

Rahul
12 Main Road, Area

"Call when outside"

₹ 320 COD

[ Navigate ]
[ Call Customer ]

[ Enter Delivery OTP ]
```

Never expose unnecessary personal information.

---

# 10. Delivery OTP

### Screen

```text
Confirm Delivery

Ask the customer for their
4/6 digit delivery OTP.

[ _ ][ _ ][ _ ][ _ ]

[ Confirm Delivery ]
```

### Security UX

- Limited attempts.
- Clear incorrect-OTP message.
- Server verifies the OTP.
- Successful verification atomically completes the order.
- Rider must not be able to mark an OTP-protected order delivered by simply changing the UI state.

Existing order schema already contains `delivery_otp`, `otp_verified_at`, and `otp_attempts`.

---

# 11. Delivery Complete

Use a strong but brief success state:

```text
✓ Delivered

Order #AD1234

You earned ₹42

[ Done ]
```

Then return to Dashboard and update earnings.

Do not force a long celebration animation while the rider is working.

---

# 12. Orders / History

Tabs:

- Active
- Completed
- Cancelled/Failed

Each card:

```text
#AD1234
Restaurant → Customer
Today, 7:42 PM
Delivered
₹42 earning
```

Filters can be added later by date/status.

---

# 13. Earnings

## Dashboard

```text
Today       ₹486
This week   ₹2,840
This month  ₹11,320
```

Breakdown:

- Delivery earnings
- Incentives
- Adjustments
- COD settlement adjustments
- Payouts

### Important distinction

`COD cash held` is **not rider earnings**.

The existing project decision is that COD cash collected by the rider belongs to the platform and is later settled to admin. Rider payout/earning is a separate financial concept.

---

# 14. COD Cash Screen

Because the project already has `rider_cod_ledger` and a configurable settlement limit, Rider UI should expose:

```text
COD Cash Held

₹1,240

Limit ₹2,000

████████████░░ 62%

[ View Cash Ledger ]
[ Settlement History ]
```

At/above limit:

```text
Cash settlement required

Settle with AnyDrop admin before
accepting more COD deliveries.
```

The UI must not imply that this amount is the rider's salary.

---

# 15. Notifications

Notification categories:

- New delivery offer
- Assignment expired
- Order cancelled
- Restaurant update
- Customer update
- Delivery reminder
- Payment/earnings update
- COD settlement warning
- Account status
- Admin announcement

Use FCM for high-priority events and an in-app notification center for history.

---

# 16. Profile

Sections:

### Personal
- Name
- Mobile
- Email
- Profile photo, if introduced

### Vehicle
- Vehicle type
- Vehicle number
- Documents/status

### Work
- Service area
- Online preference/settings

### Finance
- Earnings
- COD ledger
- Settlement history

### Support
- Help
- Tickets
- Contact support

### Account
- App settings
- Logout

---

# 17. Motion / Animation Rules

Animations should be short and functional:

- Screen enter: 180–250ms.
- Bottom sheet: 250–300ms.
- Button feedback: 100–150ms.
- Success: 250–400ms.
- Order offer: subtle pulse, not a distracting infinite animation.
- Online indicator: subtle breathing/glow is acceptable.
- Map marker: smooth position interpolation.

Avoid:

- Excessive bouncing.
- Full-screen animated transitions during active delivery.
- Infinite decorative effects that consume battery.

---

# 18. Accessibility / Rider Safety

- Minimum touch target: 48dp.
- High contrast text.
- Never rely on color alone for status.
- Large primary actions.
- Voice-friendly labels for icons.
- Avoid tiny map controls.
- Do not require typing while moving.
- Any critical action should remain understandable at a glance.

---

# 19. Responsive Layout

Primary target: Android phones.

Support:

- 360dp width minimum.
- 412dp+ comfortably.
- Different status-bar sizes.
- Keyboard resize on auth/forms.
- Dark mode only if it can be made reliable across maps; otherwise prioritize light map readability and brand dark surfaces.

---

# 20. Implementation Rules

1. Reuse the Customer/Restaurant project's existing spacing, networking, error envelope and notification patterns where technically appropriate.
2. Do not copy their navigation structure blindly.
3. Rider is a task/work app: active delivery must dominate.
4. All money calculations come from the backend.
5. All order state transitions are server-authoritative.
6. Location collection must run through a proper Android foreground service during active delivery.
7. Never store sensitive payment secrets in the APK.
8. Use vector icons and drawable resources.
9. Keep API error parsing consistent with the current Rider `ErrorParsing.kt` implementation.
10. Replace the current placeholder launcher icon with the supplied AnyDrop Rider logo before release.

---

# 21. Definition of Done — UI

A Rider UI screen is considered complete only when:

- Layout exists.
- Kotlin behavior exists.
- Loading/empty/error states exist.
- API error states are handled.
- Offline/network failure is handled.
- Back navigation is correct.
- Notification/deep-link entry is correct where relevant.
- Accessibility touch targets are acceptable.
- Build passes.
- Real device/emulator smoke test passes.
- It is tested against the actual backend state machine.

