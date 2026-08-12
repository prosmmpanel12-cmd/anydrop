# Anydrop — Security Hardening Roadmap

**Status:** Not started. This is a planning doc only — none of this is implemented
yet. Build the app functionally first (all phases), then come back here and
implement these in the priority order below.

**Why this exists:** Google Maps API key (and later, other backend secrets)
live inside the Android APK, which anyone can decompile. This doc covers how
to make key leaks harmless and how to stop people from capturing/replaying
API traffic against our backend.

---

## 1. Google Maps API key restrictions (Google Cloud Console)

Do this the moment the real Maps key is generated (currently a placeholder
in `customer/app/src/main/res/values/strings.xml` → `google_maps_key`).

- **Application restriction:** Android apps only.
  - Package name: `com.anydrop.food`
  - SHA-1 fingerprint: add both debug keystore SHA-1 (for testing) and the
    release keystore SHA-1 (once we have a release signing key, Phase 7).
- **API restriction:** limit the key to only **Maps SDK for Android** —
  nothing else, even if other APIs are enabled on the project.
- **Never** put a Geocoding/Directions/Places key in the app. Those go
  server-side only (see §5).
- **Billing budget alert:** set a small monthly budget (e.g. ₹100–200) in
  Cloud Console → Billing → Budgets & Alerts, so any abuse triggers a
  notification instead of a silent bill.

This doesn't make the key unbreakable (a determined attacker can spoof the
`X-Android-Package`/`X-Android-Cert` headers Maps SDK sends), but it stops
casual key-copying, which is the overwhelmingly common case.

---

## 2. SSL Certificate Pinning (customer + restaurant apps)

**Why:** Without this, anyone can install a proxy tool (Charles Proxy,
mitmproxy) with a fake root CA on their own phone and read/modify every
request our app sends/receives in plaintext — including request signatures,
tokens, and payloads. Pinning makes the app reject any connection that
doesn't present our real server's certificate, killing this attack outright.

**Where:** `ApiClient.kt` (customer) and its restaurant-app equivalent —
add a `CertificatePinner` to the shared `OkHttpClient`.

```kotlin
val certificatePinner = CertificatePinner.Builder()
    .add("yourdomain.com", "sha256/AAAA...=")   // primary pin
    .add("yourdomain.com", "sha256/BBBB...=")   // backup pin (different cert/CA)
    .build()

OkHttpClient.Builder()
    .certificatePinner(certificatePinner)
    .build()
```

**Notes for implementation day:**
- Always add a **backup pin** (e.g. the CA's intermediate cert), or an
  SSL cert renewal will hard-break the app for every installed user until
  an app update ships.
- Needs the production domain finalized first (currently `localhost:8080`
  for local dev — pinning is meaningless until we're on a real domain with
  a real cert, so this step naturally comes after hosting is finalized).

---

## 3. HMAC request signing (our own backend endpoints)

**Why:** Even with HTTPS, someone who captures one valid request (their own
traffic, via a rooted device + Frida, etc.) can replay it forever, or you
just want basic guarantee that a request actually came from the real app
and wasn't hand-crafted with Postman. Signing + a short time window + a
one-time nonce stops both.

**App side:** every request to our backend adds three headers —
`X-Timestamp`, `X-Nonce` (random per-request), `X-Signature` = HMAC-SHA256
of `timestamp + nonce + request body` using a secret key embedded in the
app (see §4 for how to store that secret safely).

**Backend side (PHP), reject if:**
- `X-Signature` doesn't match the recomputed HMAC
- `X-Timestamp` is more than ~3 minutes off from server time (replay window)
- `X-Nonce` has been seen before (store recently-used nonces in a table or
  cache with a TTL matching the timestamp window)

```php
$timestamp = $_SERVER['HTTP_X_TIMESTAMP'];
$nonce     = $_SERVER['HTTP_X_NONCE'];
$signature = $_SERVER['HTTP_X_SIGNATURE'];

if (abs(time() - $timestamp) > 180) { /* reject: stale */ }
if (nonce_already_used($nonce))     { /* reject: replay */ }

$expected = hash_hmac('sha256', $timestamp . $nonce . $rawBody, APP_SIGNING_SECRET);
if (!hash_equals($expected, $signature)) { /* reject: bad signature */ }
```

Apply this to every `api/v1/**` endpoint, not just auth — a shared
middleware/guard function at the top of the router, not copy-pasted into
each file.

---

## 4. Code obfuscation + secret hiding

- **R8/ProGuard:** turn on `minifyEnabled true` and `shrinkResources true`
  in the **release** build type of both apps' `build.gradle`. Free, built
  into the Android Gradle Plugin, zero extra dependencies. This alone
  makes casual decompilation output far harder to read (renamed
  classes/methods, dead code stripped).
- **Don't store the HMAC secret (§3) as a plain Kotlin string constant** —
  that's the first thing a decompiler shows. Options, roughly in order of
  effort vs. payoff:
  1. Split the secret across multiple string fragments and reassemble at
     runtime (raises the bar slightly, cheap to do).
  2. Store it in a native (NDK/C++) `.so` library instead of Kotlin/Java —
     meaningfully harder to extract than decompiling bytecode.
  3. (Later/optional) Full string-encryption tooling — not required for
     this app's threat level, mentioned for completeness only.

---

## 5. Never ship sensitive/quota-heavy API keys in the app

Any Google API that's expensive per-call or has a generous abuse surface —
**Geocoding, Directions, Places** — must be called from our **PHP backend**,
never directly from the app. Flow: app → our backend endpoint → backend
calls Google using a **server-only** key (IP-restricted to our hosting
server's IP in Cloud Console) → backend returns just the result the app
needs.

This key never touches the APK, so it can't be extracted from it at all —
this is the single highest-value fix for the "expensive API abused via
extracted key" risk, more effective than any client-side restriction.

Status per `docs/12_Handover_H6_Map_PinDrop_Photo.md`: currently using
on-device Android `Geocoder` as an interim measure. Migrating this to a
backend-proxied endpoint is still pending — do it together with this
section, not before, since the backend endpoint should be built with §3's
signing already in place.

---

## 6. Play Integrity API

**Why:** Confirms a request came from our genuine, unmodified app, freshly
installed from Play Store, on a non-rooted device — cryptographically
verified by Google, not just headers we can't check. Defeats
repackaged/modified-APK attacks that bypass §1–§4 entirely.

**Prerequisite:** Google Play Console account (**$25 one-time** — already
planned anyway since Anydrop is going on the Play Store). No recurring
cost; Play Integrity itself is free with a generous quota for an app this
size.

**Flow:** app requests an integrity token from Google Play services →
sends it to our backend with the request → backend calls Google's
verification API → Google confirms package name, cert, licensing, and
device integrity → backend trusts (or rejects) the request accordingly.

Do this **after** the Play Console account exists and the app has at least
an internal testing release — it can't be wired up before that.

---

## 7. Root / debugger / emulator detection (app-side, defense in depth)

Cheap, free, no library required. On app start, check for:
- `Debug.isDebuggerConnected()`
- Common root indicators (`su` binary present, Magisk artifacts, etc.)
- Emulator fingerprint checks (optional — lower priority, mainly a
  fraud/testing-abuse signal, not a hard security boundary)

A determined attacker can bypass this (it's client-side, so it can be
patched out), but it raises effort for casual tampering and can be paired
with server-side signals (e.g. flag accounts that consistently trip these
checks) rather than being a hard block on its own.

---

## 8. Server-side rate limiting

**Why:** The final safety net — even if every other layer is somehow
bypassed, capping requests per device/IP/account stops bulk abuse (OTP
spam, scraping the menu/restaurant list at scale, brute-forcing anything)
and makes abuse visible instead of silent.

Implementation: simple counter per IP (and per authenticated user once
logged in) with a sliding window, stored in a DB table or lightweight
cache. Exact limits (requests/minute per endpoint) to be tuned once real
usage patterns are visible — start conservative, loosen if it's blocking
legitimate use.

---

## Priority order for implementation day

1. **SSL Certificate Pinning** — biggest single win, kills traffic capture
   outright. (Needs production domain finalized first.)
2. **HMAC + timestamp + nonce signing** on our backend endpoints.
3. **R8/ProGuard** on release builds — trivial to turn on, no reason to skip.
4. **Backend-proxied Geocoding** (moves that key out of the app entirely).
5. **Server-side rate limiting.**
6. **Play Integrity API** — once Play Console account + internal testing
   release exist.
7. **Root/debugger detection** — nice-to-have, do last.

**Reminder:** none of this is 100% unbeatable against a sufficiently
determined, skilled attacker (rooted device + Frida + patience beats
almost any client-side protection eventually). The goal is raising the
cost/skill bar high enough that casual and semi-skilled attackers give up,
while keeping the actually expensive/sensitive operations (Geocoding,
Directions, anything billed per-call) server-side where they can't be
touched at all regardless of what's extracted from the APK.
