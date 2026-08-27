# Restaurant App — Known Issues & Fixes (2026-08-15 session)

## 1. "Anydrop Restaurant poses a threat" — Play Protect warning on install

**What you saw:** Android's Play Protect shows *"'Anydrop Restaurant' poses a threat. Suggest uninstalling."* right after installing the APK from the GitHub Actions artifact.

**Why this happens — not a real threat, it's expected for this build:**
- The APK is a **debug build**, signed with the auto-generated Android debug keystore (not a real release signing key).
- It was **side-loaded** (installed from a downloaded file, not the Play Store), which is exactly the pattern real malware uses — so Play Protect flags *any* unknown, unsigned-by-a-known-developer APK this way by default, regardless of what's actually inside it.
- This is the same warning you'd get side-loading any personal/test APK, including ones built by big companies for internal testing.

**What to do about it right now:** Tap **"View details"** → **"Install anyway"** (or ignore/dismiss). Safe to do since you built this APK yourself from your own source code.

**What actually fixes this long-term (not urgent, but worth knowing):**
1. Move from a **debug** build (`assembleDebug`) to a proper **release** build signed with a real keystore — Play Protect trusts consistently-signed apps more once the same signature has some install history.
2. Eventually distributing via **Play Store internal testing track** removes this warning entirely, since Play Store apps are pre-vetted.
3. Neither is needed for now — this is a testing-phase side-load, the warning is cosmetic at this stage.

---

## 2. Input field text looked faded

**Root cause:** The app's theme (`themes.xml`) never set `colorOnSurface` / `colorOnSurfaceVariant`, which is what Material3's `TextInputLayout` actually uses to color the text you type and the box outline. Without an explicit value, it silently fell back to Material3's own default muted gray-purple instead of this app's `text_primary` (near-black) — so every outlined input field (login, signup, menu add/edit dialogs) rendered typed text lighter than intended.

**Fix:** Set `colorOnSurface` → `@color/text_primary` and `colorOnSurfaceVariant` → `@color/text_secondary` on the app theme itself, in `themes.xml`. This is a **theme-level fix**, so it corrects every current and future Material3 input field app-wide — not just the screens touched this session.

---

## 3. Screen switch animations

**What was missing:** Dashboard → Menu Management had **no transition at all** — it just hard-cut, while the Login ↔ Signup flow already had a smooth slide (`slide_in_right` / `slide_out_left` and reverse). Inconsistent — some navigation felt animated, some felt abrupt.

**Fix:** Menu Management now slides in from the right on open, and slides back out to the right on both the on-screen back arrow *and* the hardware/gesture back button (`onBackPressed` override), matching the existing Login/Signup transition direction convention so the whole app now feels like one consistent flow instead of two different styles stitched together.

**Still not covered** (flagging honestly, not done this session): Order Detail screen's open/close transition, and the future bottom-nav tab switches (once §9/§10 of the UI plan doc are built) — those should get the same treatment when those screens are touched next.
