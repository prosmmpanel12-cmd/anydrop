# Anydrop Restaurant Partner App — UI Fix Handover
Session date: 31 Aug 2026 (continued, same day)

> **⚠️ Not built/tested this pass.** This sandbox has no Android SDK, no
> `gradlew` wrapper, and no network access, so none of the changes below
> could be compiled or run on a device/emulator. XML was checked for
> well-formedness only. Per `done.md`'s own rule ("No successful test =
> No DONE mark"), none of this is added to `done.md` — treat everything
> below as `🟡 IMPLEMENTED — TEST PENDING` until you've built and run it.

This zip contains the **restaurant app source** with the fixes below already
applied, plus a `reference_images/` folder with everything you shared this
session so the next session doesn't need you to re-upload anything.

---

## ✅ Done this session

### 1. App icon rendering "round" / clipped on the home screen
**Root cause:** `ic_launcher_foreground.png` had the entire black
squircle-with-white-border baked into it as a fully opaque image (no
transparency at all). Android launchers that apply their own icon mask
(circle, squircle, rounded-square, teardrop, etc.) were clipping straight
through that baked-in border/shape, which is what showed up as the icon
looking oddly round/cropped in the app drawer.

**Fix:**
- Extracted just the logo glyph (smiley + "ANY / Dr🍴p") onto a transparent
  canvas — `app/src/main/res/drawable/ic_launcher_foreground.png`
- Added a proper solid-black background layer —
  `app/src/main/res/values/ic_launcher_background.xml`
- Added real **Adaptive Icon** definitions for Android 8+ —
  `app/src/main/res/mipmap-anydpi-v26/ic_launcher.xml` and
  `ic_launcher_round.xml`
- Regenerated every legacy fallback PNG (`mipmap-mdpi` → `mipmap-xxxhdpi`,
  both `ic_launcher.png` and `ic_launcher_round.png`) so pre-Android-8
  devices also get a clean rounded-square / true-circle icon instead of the
  old clipped artwork.

Any launcher shape now masks a full-bleed black background with a centered
glyph — nothing to clip unevenly.

### 2. Insights screen — "Today / This Week / This Month" toggle
**Root cause:** it used a `MaterialButtonToggleGroup` with a custom pill
style. The toggle group's automatic corner-squaring on the middle/end
buttons fought with the style's own fixed corner radius, so only "Today"
ever rendered as a real pill — "This Week" and "This Month" rendered as
plain bordered rectangles (visible in your screenshot).

**Fix:** replaced it with a purpose-built segmented control:
- `app/src/main/res/drawable/bg_segment_track.xml` (rounded track)
- `app/src/main/res/drawable/bg_segment_item.xml` (selected/unselected pill)
- `app/src/main/res/color/segment_text_color.xml` (white on selected, grey
  otherwise)
- `app/src/main/res/layout/fragment_insights.xml` updated to the new markup
- `app/src/main/java/.../ui/insights/InsightsFragment.kt` updated to plain
  click listeners (`View.isSelected`) instead of the old
  `addOnButtonCheckedListener` API

All three segments now render identically — one clean rounded track, black
pill on the active segment.

> Note: this was scoped to the Insights range toggle only. The same pill
> style (`ToggleButton.Pill`) is also reused by the coupon active/inactive
> toggle and the category-icon-picker's 3 tabs — those were intentionally
> left untouched since they weren't part of what you flagged.

### 3. On/off switch size
`app/src/main/res/drawable/switch_track_pill.xml` (and its paired thumb)
had been sized up to 46×26dp in an earlier pass. Brought it back down to
**40×22dp** — smaller than that, still comfortably bigger than the original
too-thin 38×20dp version.

---

## ✅ Also done this continued pass

### 4. Switch thumb drawable (was item 1 in the old list)
`switch_thumb_pill.xml` was still sized for the old 46×26dp track (20dp
thumb) after the track came back down to 40×22dp. Resized the thumb to
**18dp**, which reproduces the same ~2dp top/bottom margin the original
38×20dp-track/16dp-thumb pairing had.

### 5. Login screen badge (was item 2 in the old list) — investigated, no bug found
Checked the math: `activity_login.xml`'s logo `ImageView` already points
at `@mipmap/ic_launcher_round`, and that asset (confirmed by inspecting
the actual PNG alpha channel, all densities) is a true circle inscribed
in its own canvas — not a square with a baked-in shape. A 56dp circle
inside a 66dp circular backdrop leaves a clean 5dp margin all the way
round; there's no diagonal to poke outside. This item in the original
list appears to have been written before this session's app-icon fix
(item 1) was applied — that fix regenerated the exact same
`ic_launcher_round.png` this screen uses, which incidentally resolved
this one too. **No code change made here** — flagging as resolved
pending your visual confirmation on device, rather than silently
dropping it.

### 6. Splash screen badge overflow (was item 3 in the old list, badge part only)
This one *was* a real, distinct bug: `activity_splash.xml` used
`@mipmap/ic_launcher` (the **square-ish**, near-full-bleed legacy icon,
not the round one) at 96dp inside a 112dp circular backdrop. A 96dp
square's corner-to-corner diagonal is ≈136dp — well past the 112dp
backdrop, so corners genuinely poked outside. Switched the splash
screen's `ImageView` to `@mipmap/ic_launcher_round` (the same true-circle
asset the login screen already used), which at 96dp sits with a clean
8dp margin inside the 112dp backdrop.

---

## ⏳ Not done yet — pick up next session

1. **Build & device-test everything above.** This pass edited XML only,
   in a sandbox with no Android SDK/emulator — nothing was compiled or
   visually confirmed. Please build, install, and eyeball: the switch
   toggles app-wide, the login hero badge, and the splash badge.
2. **Splash screen "loading animations" polish** — the badge-overflow
   *bug* is fixed (see #6 above), but the broader ask — restyling the
   splash to match one of the loading-animation treatments in your UI
   kit reference (spin/pulse/wave/bounce/circle-progress/food-loading —
   panel **60** in `07_ui_kit_reference_part4.png`, not 58) — is a
   genuine visual redesign, not a bug fix. It also runs into the same
   theme conflict as item 3 below: the UI kit reference is a dark/black
   theme, but `activity_splash.xml` still carries an explicit
   "2026-08-16 revert back to orange+white" comment. Doing the loading-
   animation polish properly means deciding that theme question first —
   deferred into item 3 rather than done half-in-one-theme,
   half-in-the-other.
3. **Full app re-theme to match your 5 uploaded reference UI kit images**
   (`04_ui_kit_reference_part1.png` → `08_ui_kit_reference_part5_final.png`,
   85 reference screens total). This is a large scope — recommend doing it
   screen-by-screen rather than all at once so quality stays controlled.
   Suggested order: Login → Dashboard → Orders → Order Details → Menu
   Management, then the rest.

---

## Folder guide in this zip

```
restaurant/                  ← the Android Studio project, fixes applied
reference_images/
  01_app_drawer_icon_issue.jpg          ← your annotated screenshot, icon bug
  02_insights_toggle_switch_issue.jpg   ← your annotated screenshot, toggle bug
  03_restaurant_login_current.jpg       ← current login screen (before polish)
  04_ui_kit_reference_part1.png         ← target UI kit, screens 1–12
  05_ui_kit_reference_part3.png         ← target UI kit, screens 29–44
  06_ui_kit_reference_part2.png         ← target UI kit, screens 13–28
  07_ui_kit_reference_part4.png         ← target UI kit, screens 45–70
  08_ui_kit_reference_part5_final.png   ← target UI kit, screens 71–85
HANDOVER.md                  ← this file
```

Just re-upload this zip in the next session and point to this file — no
need to re-share the screenshots or explain the context again.
