# Handover — H6 part 2: Map pin-drop screen (OSM) + photo upload

**Status: 🟡 In progress, 2026-08-10 (session 2).** Location Picker screen
(H6 part 1) is built — still untested, no Gradle build run yet. This
session did the **backend half of part 2 and all the Android scaffolding**
(models, API method, layout, drawables, strings, dependency, manifest
entry) for the map pin-drop screen — **the `MapPinDropActivity.kt` class
itself (the actual osmdroid/geocoding/save logic) is the one big piece
still not written.**

Say **"continue H6"** to pick this up.

---

## Where things stand

### ✅ Built in session 1 (H6 part 1 — Location Picker, screenshot 12)
Unchanged from before — see the file list below, all still true:
- `ui/address/LocationPickerActivity.kt` + `res/layout/activity_location_picker.xml`
- `res/layout/item_saved_address_picker.xml`
- `util/DistanceUtil.kt`
- `res/drawable/ic_target.xml`, `ic_work.xml`, `ic_more_horiz.xml`
- `strings.xml` — `location_picker_*` strings
- `AndroidManifest.xml` — `LocationPickerActivity` registered
- `HomeActivity.kt` — location bar tap opens `LocationPickerActivity`
- `AddressBookActivity.kt` + `AddressAdapter.kt` — tap-card-to-activate

**Still not built/tested:** no Gradle build, no on-device test of part 1.

### ✅ Built this session (H6 part 2, so far)

**Backend — done and should be deployable as-is:**
- `backend/sql/16_migration_address_photo.sql` — adds `customer_addresses.photo_url
  VARCHAR(255) NULL`, same idempotent conditional-ALTER pattern as
  `06_migration_phase36.sql`. **Not yet run against the DB.**
- `backend/api/v1/customer/address-photo.php` — **new** endpoint, `POST`
  multipart (`photo` field), customer auth required. Validates real mime
  type via `finfo` (jpeg/png/webp only), 5 MB cap, saves to
  `backend/uploads/address_photos/`, returns
  `{ photo_url: "uploads/address_photos/<file>" }` — a path **relative to
  the backend root**, not a full URL (Android needs to build the full URL
  itself — see Android section below, this is unfinished on that side).
  Grepped the whole backend first for any existing upload pattern
  (`move_uploaded_file`, `$_FILES`, `base64_decode`) — found none
  (`logo_url`/`image_url` on restaurants/menu_items are plain seeded
  columns, never uploaded through the app) — so this establishes the
  pattern rather than reusing one.
- `backend/uploads/.htaccess` — blocks PHP execution inside the uploads
  dir (basic hardening for a folder that accepts user file uploads).
  `backend/uploads/address_photos/` created (empty, `.gitkeep` only).
- `backend/api/v1/customer/addresses.php` — `format_address()` now
  returns `photo_url`; `POST` inserts it; `PUT` **only overwrites it when
  the client actually sends the field** (so editing an address through
  the old plain-form `AddressEditorBottomSheet`, which has no photo
  field, doesn't null out a photo saved via the map screen).
- **No `.htaccess` rewrite rule added** for `address-photo.php` — calls it
  by its direct `.php` path, same "works either way" convention the doc's
  session-1 notes already established for other Phase 3 endpoints.

**Android — scaffolding only, no Activity logic yet:**
- `network/Models.kt` — `Address.photoUrl` and `AddAddressBody.photoUrl`
  added (both nullable, defaulted, so nothing existing breaks); new
  `AddressPhotoUploadResult(photoUrl: String)`.
- `network/ApiService.kt` — new `uploadAddressPhoto(photo: MultipartBody.Part)`,
  `@Multipart @POST("customer/address-photo.php")`. First multipart call
  in the app.
- `customer/app/build.gradle` — added
  `org.osmdroid:osmdroid-android:6.1.20` (checked Maven Central directly
  this session, still current stable).
- `AndroidManifest.xml` — `MapPinDropActivity` registered
  (`.ui.address.MapPinDropActivity`, `exported=false`). **The class this
  points at doesn't exist yet** — app won't compile referencing it until
  next session writes it, but nothing currently references the manifest
  entry either, so this alone doesn't break part 1's build.
- `res/values/strings.xml` — full `map_pin_drop_*` string set added
  (title, search hint, distance-warning template with `%1$s`, address
  details hint + example line, receiver details header, photo row labels,
  save button, error strings, upload-failure/geocode-failure messages).
- New drawables: `res/drawable/ic_camera.xml` (photo row icon),
  `res/drawable/ic_map_center_pin.xml` (larger green pin, 40dp, for the
  fixed map-center overlay — separate from the existing small `ic_location.xml`
  used elsewhere).
- New layout: `res/layout/activity_map_pin_drop.xml` — full screen built
  and should need no further layout changes:
  - Header (back + title) + stubbed search bar, same convention as
    `activity_location_picker.xml`.
  - `FrameLayout` (weight=1) containing `org.osmdroid.views.MapView`
    (`@id/mapView`), a centered `ImageView` (`@id/centerPin`) as the fixed
    pin overlay, and a floating "Use current location" pill
    (`@id/btnUseCurrentLocationMap`) bottom-center over the map.
  - Bottom panel: `NestedScrollView` (`maxHeight="420dp"`,
    `bg_dialog_rounded_top`) containing "Delivery details" header,
    reverse-geocoded address row (`@id/textResolvedAddress`), distance
    warning banner (`@id/textDistanceWarning`, starts `gone`),
    `@id/inputAddressDetails` (the single "Address details*" field per
    spec — maps to `houseFlatNo` only, floor left null, matching the
    screenshot's single field), receiver name/phone fields
    (`@id/inputReceiverNameMap` / `@id/inputReceiverPhoneMap`), photo row
    (`@id/rowAddPhoto` / `@id/imgAddressPhotoThumb` / `@id/textAddPhotoLabel`),
    and `@id/btnSaveAddressMap`.

### 🔲 Not started — what's actually left

**The big one: `ui/address/MapPinDropActivity.kt` doesn't exist yet.**
Everything above was scaffolding for this class. It needs to:

1. **osmdroid setup** (in `onCreate`, before `setContentView` per
   osmdroid's own setup docs — check them when writing this, requirements
   shift between versions):
   - `Configuration.getInstance().load(...)` with
     `PreferenceManager.getDefaultSharedPreferences(applicationContext)`.
   - Set `osmdroidBasePath`/tile cache to somewhere in `context.cacheDir`
     (not external storage) specifically **to avoid needing
     `WRITE_EXTERNAL_STORAGE`** — this project has no such permission
     today and shouldn't need to add one.
   - Set a distinct `userAgentValue` (e.g. `"com.anydrop.food"`) — tile
     servers' ToS expect this, and Nominatim's reverse-geocode policy
     explicitly asks for a distinct User-Agent too (see point 3).
   - `mapView.onResume()`/`onPause()` wired to the Activity's own
     lifecycle methods (osmdroid requirement).
   - Default center/zoom: last resolved GPS fix if available, else just
     pick a reasonable default zoom (~16) — decide what "no GPS fix yet"
     should show; probably worth calling the same current-location fetch
     immediately on open, same as `LocationPickerActivity` does silently
     on its own open.

2. **Fixed center pin behavior**: the `centerPin` ImageView is laid out
   centered via `layout_gravity="center"` in the `FrameLayout` — that
   centers the pin's *bounding box*, but the pin graphic's actual "point"
   (the tip in `ic_map_center_pin.xml`) is at the bottom of that 40dp box,
   not its center. The pin needs to be nudged up by roughly half its
   height so the tip — not the icon's visual center — lands exactly on
   the map's true center pixel (do this via `translationY` in code once
   the view is laid out, not by fighting it in XML, since the pin height
   is fixed but "half height" as a margin trick is fragile against
   different densities). Get the actual pixel math right by testing
   on-device — this is exactly the kind of thing that looks fine in one
   layout preview and is subtly off on a real screen.

3. **Debounced Nominatim reverse geocoding** as the map is dragged:
   - `mapView.addMapListener(...)` (or `DelayedMapListener` wrapping a
     `MapListener`) to detect scroll/zoom stop.
   - On stop, debounce ~800ms–1s (a `Handler`/`postDelayed`, cancelling
     any pending one on each new scroll event) before firing the
     Nominatim call — **do not call on every scroll frame**, Nominatim's
     1 req/sec policy will throttle/block the app's requests otherwise.
   - Call `https://nominatim.openstreetmap.org/reverse?format=json&lat={lat}&lon={lng}`
     — plain `OkHttpClient` request (not the existing `ApiService`/
     Retrofit instance, which is hardcoded to the Anydrop backend host) with
     a custom `User-Agent` header, run on `Dispatchers.IO` from
     `lifecycleScope`. Parse the JSON `display_name` field (or build
     something reasonable from the `address` object if `display_name` is
     ever missing) into `textResolvedAddress`.
   - On any failure (network error, malformed response, rate-limited),
     show `map_pin_drop_geocode_failed` via `InAppNotifier` rather than
     leaving stale text silently — but don't block map interaction on it.
   - Whatever the last successful reverse-geocode result was is what gets
     sent as `fullAddress` on save — **not** hand-typed by the user
     anywhere on this screen (per spec).

4. **"Use current location" pill** (`btnUseCurrentLocationMap`): same
   GPS-fix pattern already duplicated in `CheckoutActivity`,
   `AddressBookActivity`, and `LocationPickerActivity` — copy the
   `fetchCurrentLocation()` approach from any of them (permission check →
   `LocationManager.getLastKnownLocation` → `requestSingleUpdate` fallback).
   On a fix, `mapView.controller.animateTo(GeoPoint(lat, lng))` (or
   `setCenter` if animation feels unnecessary) — this itself will trigger
   the map-stopped listener from point 3 and reverse-geocode the new
   center, so no separate geocode call is needed here.

5. **Distance warning**: compare the pin's current center lat/lng against
   the last resolved GPS fix using `DistanceUtil.km()` (already built,
   just import `com.anydrop.food.util.DistanceUtil`). Show
   `textDistanceWarning` (template string `map_pin_drop_distance_warning`,
   `%1$s` = `DistanceUtil.formatDistance()` output) when the two are
   meaningfully far apart — the screenshot's threshold isn't specified
   anywhere in the spec, so pick something sensible (e.g. show whenever
   distance > ~1–2 km) rather than agonizing over an exact cutoff; note
   the choice in code so it's easy to tune later. Hide the banner entirely
   if no GPS fix has been resolved yet (nothing to compare against).

6. **Photo picker + upload** (`rowAddPhoto` tap):
   - `ActivityResultContracts.GetContent()` (or
     `PickVisualMedia` if targeting a more modern picker — check what's
     simplest given `minSdk 24` in this project) with `"image/*"`.
   - On a picked URI, update `textAddPhotoLabel` to
     `map_pin_drop_photo_added` and swap `imgAddressPhotoThumb` to show
     the picked image (Coil, already a dependency — `imgAddressPhotoThumb.load(uri)`)
     instead of the camera icon, so the user gets visual confirmation
     before saving.
   - **Upload timing decision needed**: either (a) upload immediately on
     pick, storing the resulting `photo_url` in a field for Save to use,
     or (b) defer the upload until Save is tapped, uploading first and
     then calling `addAddress()` with the result. (b) is simpler state
     management (nothing to hold in memory except the picked `Uri` until
     Save) and avoids uploading a photo the user picks then abandons by
     leaving the screen — **lean toward (b)** unless there's a reason to
     prefer immediate upload once you're actually writing this.
   - Building the multipart body: read the URI via
     `contentResolver.openInputStream(uri)`, into a `ByteArray`, wrap as
     `RequestBody.create(mediaType, bytes)`, then
     `MultipartBody.Part.createFormData("photo", "photo.jpg", requestBody)`
     — mind that gallery images can be large; consider whether any
     client-side downscaling is worth adding here or whether the backend's
     5 MB cap plus phone camera photos being what they are is fine as-is
     for a first pass (probably fine — don't over-build this).
   - On upload failure, per the spec's own fallback: show
     `map_pin_drop_photo_upload_failed` and **still let the address save
     succeed without the photo** — don't block the whole flow on a
     photo-upload hiccup.
   - **Building the photo's full display URL elsewhere** (e.g. if this
     photo ever needs to be shown back to the user, on the saved-address
     card or an edit screen): the backend returns a path relative to its
     root (`uploads/address_photos/...`), not a full URL. `ApiClient`'s
     `BASE_URL` is `http://localhost:8080/anydrop/api/v1/` — strip the
     trailing `api/v1/` and append the relative path to get something
     browsable. This screen itself doesn't need to *display* an existing
     photo (Add Address is always a fresh add, never pre-filled), but
     note this for whenever `AddressEditorBottomSheet` or an address card
     eventually wants to show a saved photo thumbnail.

7. **Save button** (`btnSaveAddressMap`):
   - Validate `inputAddressDetails` non-empty (→
     `error_address_details_required` via `InAppNotifier`, same pattern as
     `AddressEditorBottomSheet.save()`). Receiver name/phone: decide
     whether to enforce the same required-field rules the plain editor
     has (`error_receiver_name_required` / `error_receiver_phone_required`
     already exist as strings) — the H6 spec doesn't explicitly say these
     are optional here, and `AddAddressBody` doesn't strictly require them
     server-side, but for consistency with the existing editor's UX,
     validating them the same way is probably right; use judgement here
     rather than skipping validation just because it's easy to skip.
   - If a photo was picked and not yet uploaded (per the (b) decision
     above), upload it first, capture `photo_url` (or null + notify on
     failure, per point 6).
   - Call `api.addAddress(AddAddressBody(fullAddress = <last reverse-geocode
     result>, houseFlatNo = inputAddressDetails text, floor = null,
     receiverName = ..., receiverPhone = ..., latitude = <pin lat>,
     longitude = <pin lng>, photoUrl = <uploaded url or null>, isDefault = true))`
     — same call `AddressEditorBottomSheet.save()` already makes, no new
     address-fields endpoint needed (confirmed again this session — only
     the photo needed new backend work, and that's done).
   - On success: `setResult(RESULT_OK)`, `finish()` — so the caller
     (`LocationPickerActivity`, once wired per item 8 below) knows to
     reload its address list.
   - Disable the button while the save (and any photo upload) is
     in-flight, same as `AddressEditorBottomSheet` does, to prevent
     double-submits.

### Also still needed once `MapPinDropActivity` exists

8. **Wire `LocationPickerActivity`'s "Add Address" row to the new
   screen.** Currently `openEditor(null)` opens `AddressEditorBottomSheet`
   directly — this still needs to change to launch `MapPinDropActivity`
   via a `registerForActivityResult` launcher (same pattern
   `HomeActivity` already uses for `LocationPickerActivity` itself —
   `locationPickerLauncher`), and reload addresses (`loadAddresses()`) on
   `RESULT_OK`. **Leave `openEditor(address)` (the `...`/pencil icon on
   existing saved-address rows) pointed at the old
   `AddressEditorBottomSheet`** — editing an existing address via the map
   screen isn't in scope for this feature, only adding a new one is.

9. **Build + test everything** — neither part 1 nor any of part 2 has
   been compiled yet. Given the size of what's changed (new dependency,
   new manifest entry, new Activity, new backend endpoint), budget real
   time for this and expect at least one round of fixing something that
   silently didn't make it into a push (same standing lesson from H5,
   repeated in session 1's version of this doc too — bears repeating a
   third time because it keeps happening).

---

## Notes for next session

- **Start by re-reading this doc's "Where things stand" section** — it
  now fully supersedes the original H6 spec in `features.md` §7 *and*
  session 1's version of this doc. No need to re-read either first.
- **`MapPinDropActivity.kt` is the entire remaining scope.** Every other
  file this feature needs already exists. Don't rebuild the layout, the
  models, the endpoint, or the strings — just write the class that wires
  them together, following the numbered plan above.
- Before writing the geocoding code, actually check osmdroid's current
  setup docs (`Configuration`/lifecycle requirements can shift between
  versions) rather than relying on general familiarity with the library —
  same caution the session-1 doc already flagged.
## Map Provider & Live Tracking Decision (added 2026-08-11)

This section records decisions made after this handover doc was written,
covering the map provider choice and how rider navigation + live tracking
will work. Read this before starting on the map provider or on Phase 4
(Live Tracking).

### Map provider: Google Maps (confirmed)

Evaluated OSM/osmdroid, MapTiler, and Mapbox against Google Maps for data
quality in the target area (Osian, Jodhpur). Side-by-side comparison
(screenshots of the same location in Mapbox vs Google Maps) showed Google
Maps has meaningfully better local data — labels, landmarks, and minor
roads that Mapbox/OSM don't have. **Google Maps is the confirmed provider**,
pending a billing-account card being set up (not yet done as of this
writing — this is the one blocker before any Google Maps SDK work starts).

Until the card is set up, H6 continues on osmdroid as already scaffolded.
Because osmdroid is unmaintained (repo archived Nov 2024) and doesn't
support 3D, the plan is to migrate to Google Maps SDK once billing is
ready, rather than invest further in osmdroid-specific polish. Keep the
map-rendering code isolated behind a small wrapper/interface where
practical (map init, marker add/update, camera move) so the SDK swap
later touches one file instead of every screen that shows a map.

### Which Google APIs are actually used, and why each is cheap at this scale

| API | Used for | Free tier | Est. monthly cost at 200 users / 20-50 orders/day |
|---|---|---|---|
| Maps SDK for Android | Rendering the map on any screen (Location Picker, Live Tracking) | Unlimited, always free | ₹0 |
| Geocoding API | Pin-drop → address text (reverse geocode) | 10,000 requests/mo | ₹0 (~30 calls/mo used) |
| Directions API | Street-by-street route line, rider→customer, on the customer/restaurant/admin tracking screens | 10,000 requests/mo | ₹0 (~4,200 calls/mo with deviation-based refetch) |

Cost-control choices baked into the design:
1. **No search bar, no Places Autocomplete, no forward-geocoding search —
   removed entirely for this update** (see "Search bar: fully removed"
   below). Address selection is current-location or manual pin-drop only.
   This also means the Geocoding API is only ever used in its reverse
   direction (coordinates → address text), never forward search.
2. **Directions calls are deviation-triggered, not on a fixed timer.**
   Recalculate the route line only when the rider's position strays
   meaningfully from the last-fetched route, not every 30-45 seconds.
   This is roughly a 6x reduction in call volume for the same feature.

GPS pings (rider → backend, every 5-7s) and marker movement on an
already-loaded map are **not** API calls of any kind — they're plain
backend polling and client-side animation, and cost nothing regardless of
provider.

If billable usage is ever exceeded (business grows past current
estimates), set a hard daily/monthly quota cap in Google Cloud Console so
spend can never exceed a chosen ceiling; requests fail gracefully past the
cap rather than the bill growing unbounded.

### Rider navigation: deep-link to the Google Maps app (confirmed)

Riders do **not** get in-app turn-by-turn navigation. Tapping "Start
Navigation" fires an Android intent (`google.navigation:q=<lat>,<lng>`)
that opens the Google Maps app already installed on the rider's phone,
with turn-by-turn navigation to the drop point. This is a plain
app-launch intent — **not** a Directions API call, and costs nothing.

**Note:** the exact trigger point for when "Start Navigation" appears was
refined after this section was first written — see "Rider navigation,
background tracking, and drop-off OTP flow" near the end of this doc for
the current, more precise sequence (button appears after the rider marks
the order Picked Up, not on accept) and for what happens after the rider
leaves for Google Maps (background GPS tracking, manual return, OTP).

Rationale: building in-app turn-by-turn (voice guidance, re-routing,
lane-level instructions) is a large undertaking that duplicates something
Google has already spent years polishing, and it would also multiply
Directions API usage (turn-by-turn re-routes continuously). Even Zomato/
Swiggy hand riders off to a native maps app rather than reimplementing
navigation. Customer/restaurant/admin-facing tracking screens stay in-app
(that's where the polished UX matters); the rider-facing tool just needs
to get the rider there efficiently.

### Live tracking screen (customer/restaurant/admin) — confirmed behavior

- Map loads once when the tracking screen opens (single map load).
- Rider's marker updates every 5-7s from GPS pings already flowing through
  the existing polling design — marker slides between points via
  `ValueAnimator`, no map reload.
- A street-by-street route line (rider → customer) renders via Directions
  API, recalculated only on deviation (see cost table above).
- Tracking only becomes visible once the rider has picked up the order
  (not from order-placed) — this was a deliberate scope cut to keep both
  GPS polling volume and Directions usage down, since prep time doesn't
  need a moving map.

### Billing visibility and hard spend protection (critical, added 2026-08-11)

This is the honest picture after checking Google's current documentation
— an earlier version of this guidance oversimplified it, so record the
correct mechanics here.

**Budget alerts alone do NOT stop spending.** Setting a "Budget & Alert" in
Google Cloud Console (Billing → Budgets & alerts) only sends an email when
spend crosses a threshold — it does not pause or block anything.
Confirmed directly from Google's own docs: alerts-only budgets don't
automatically cap usage or spending. Worse, once an alert threshold is
exceeded, Google generally stops sending further alerts past that point —
so a budget alert is a notification system, not a safety switch. Don't
rely on it as the thing standing between this project and an unexpected
bill.

**What actually enforces a hard limit is per-API Quotas, not budgets.**
In Google Cloud Console → APIs & Services → the specific API (Geocoding,
Directions) → Quotas, set a **daily request cap** per API (e.g. "Requests
per day: 5,000"). This is hard-enforced server-side: once the cap is hit,
further requests to that API fail with a quota-exceeded error instead of
succeeding and adding to the bill. This is the real backstop — set one on
both the Geocoding key and the Directions key, sized comfortably above
expected usage (see the cost table above) but with real headroom removed,
so a bug or an abuse pattern can't run up an unbounded bill.

**Newer "Spend Cap" feature exists but is limited.** Google has a preview
feature (Billing → Budgets → spend cap budget) that does pause the
*eligible* services automatically once a spend target is hit — this is
closer to true bill protection, but it's in Preview and only covers
"eligible services," not necessarily Maps Platform APIs. Worth checking
if it covers Geocoding/Directions by the time billing is actually set up
for this project, but don't depend on it being available — the per-API
Quota cap above is the reliable mechanism today.

**Practical setup once the card is added, in order:**
1. Set a per-API daily Quota cap on Geocoding and Directions (hard stop).
2. Set a Budget Alert as an early-warning email on top of that (soft
   notice, catches problems before the quota is even hit).
3. Check whether Spend Cap (preview) covers Maps Platform APIs at setup
   time — enable it if so, as a third layer.

None of this is automatic by default — all three have to be manually
configured in Cloud Console after the billing account exists. Do this
configuration in the same session the card is added, before any API key
is used in the app, so there's never a window where billing exists but no
cap does.

### API key security — how the Google Maps key must be stored (critical, added 2026-08-11)

**Never put the Geocoding/Directions API key inside the Android app.** Any
key shipped in `AndroidManifest.xml`, a `.kt`/`.java` file, `local.properties`
bundled into the APK, or a string resource is recoverable by decompiling
the APK — a trivial, well-documented attack, not a theoretical one. A
leaked key gets used by someone else and billed to this project's Google
Cloud account. This applies even if the repo/APK is not public — assume
any APK that leaves this machine can be decompiled.

Two different categories of API usage need two different treatments:

1. **Maps SDK for Android (rendering the map on-device).** This one
   genuinely requires a key on the device — Google's SDK has no
   server-proxy option for map tile rendering. Mitigate with **key
   restriction** in Google Cloud Console: restrict the key to Android apps
   only, and lock it to this app's exact package name + SHA-1 signing
   certificate fingerprint (Console → Credentials → the key → Application
   restrictions → Android apps). A key restricted this way is useless to
   anyone who extracts it from the APK, because Google checks the
   requesting app's signature server-side before honoring the request.
   Use a **separate key** for this, scoped to only the Maps SDK API.

2. **Geocoding API and Directions API (backend-callable APIs).** These
   must **never** be called directly from the Android app. The Android app
   calls this project's own PHP backend (same pattern as every other
   endpoint in `02_API_Contract.md`); the PHP backend — running on the
   server, never shipped to a device — holds this key and makes the actual
   call to Google. The key never leaves the server. Use a **second,
   separate key** for this, scoped to only Geocoding + Directions, with an
   **IP restriction** in Google Cloud Console limiting it to the hosting
   provider's outbound IP (if the host has a stable one) or, at minimum,
   API restriction to just those two APIs so a leak has a small blast
   radius.

Practical rule: **two keys, not one** — an Android-restricted key for the
SDK (safe to ship in the app), and a server-only key for Geocoding/
Directions (never appears in the app, only in backend PHP config, which
itself should not be committed to a public repo — keep it in a
gitignored config file or environment variable on the host, matching
whatever pattern the rest of the backend already uses for secrets).

Also set a daily quota cap on both keys in Google Cloud Console (see cost
table above) as a second line of defense — even a leaked, correctly
restricted key can't run up an unbounded bill if a hard cap exists.

### Google Maps SDK migration plan (added 2026-08-11, code migrated 2026-08-12)

> **Status:** `MapPinDropActivity.kt`, `activity_map_pin_drop.xml`,
> `customer/app/build.gradle`, and `AndroidManifest.xml` have all been
> migrated to Google Maps SDK — steps 3, 4, 5, 7 of the migration steps
> below are done. **Not done:** the app name/`applicationId` decision this
> was originally blocked on is resolved (`com.anydrop.food`), but the
> Google Cloud billing card and both API keys are still not set up — see
> step 2 below, still fully outstanding. The code compiles and is
> structurally complete, but the map will show blank/grey at runtime until
> a real Android-restricted key replaces the placeholder in
> `strings.xml`'s `google_maps_key`. Step 6 (backend-proxied Geocoding,
> replacing the on-device Geocoder currently still in use for reverse-
> geocoding) is also not done — see the kdoc on `reverseGeocode()` in the
> Activity for why that's flagged as an interim state, not the final one.
> Step 8 (Live Tracking screen) hasn't started — Phase 4 scope, not H6.
> Osmdroid has been fully removed from the app (dependency, imports, XML
> tag) — no fallback exists anymore if the Maps key turns out to be an
> issue, unlike the "keep both during the transition" caution in step 3
> below, which wasn't followed since the app owner explicitly wanted the
> code migrated now rather than staged.

This is the plan for swapping osmdroid → Google Maps SDK, recorded ahead of
implementation. **Do not start this migration until:**
1. The app's final name and `applicationId` (package name) are decided —
   changing either after Maps SDK setup means redoing the SHA-1/package
   key-restriction in Google Cloud Console, so settle the name first.
2. The Google Cloud billing card is set up (see the billing-visibility
   section above — set the per-API Quota caps in the same session the
   card is added, before shipping any key in the app).

#### Which features move to Google Maps, and which don't

| Feature | Provider after migration | Notes |
|---|---|---|
| Map rendering (Location Picker, Live Tracking) | Google Maps SDK for Android | Replaces osmdroid's `MapView`; free/unlimited regardless of usage |
| Pin-drop (fixed center pin, pan-to-position) | Google Maps SDK | Same fixed-center-pin pattern already built for osmdroid — only the underlying `MapView` swaps, the UX doesn't change |
| Current-location auto-fetch | Unchanged | Already pure GPS (`LocationManager`), no map-provider dependency — nothing to migrate here |
| Reverse geocoding (pin → address text) | Google Geocoding API, called from the **PHP backend only** (never directly from the app) | App calls this project's own backend endpoint; backend holds the server-only Geocoding key and proxies to Google — see API key security section above |
| Address search | **Removed for this update** | See "Search bar: fully removed" below — no search UI, no forward-geocoding, current-location + pin-drop only |
| Live tracking route line (rider → customer) | Google Directions API, backend-proxied, deviation-based refetch (not fixed-timer) | Customer/Restaurant/Admin tracking screens — see cost table above |
| Rider navigation | Google Maps **app** deep-link (`google.navigation:q=<lat>,<lng>`), triggered by "Start Navigation" after the rider marks the order Picked Up | Not an API call, not the SDK. See "Rider navigation, background tracking, and drop-off OTP flow" below for the full sequence — this row only covers the map hand-off part of it. |

#### Search bar: fully removed for this update (2026-08-11 revision)

**Revised from an earlier version of this plan.** Originally the idea was
to keep a Geocoding-search box behind an "Order for other" button. That's
now cut too — **no search bar anywhere in this update**, full stop. Address
selection has exactly two paths for now:
1. "Use current location" (GPS, free, no API call)
2. Manual pin-drop (drag the map, center-fixed pin, reverse-geocoded via
   Geocoding API as described above)

Revisit search (Autocomplete or plain Geocoding forward-search) in a
*future* update — not part of this migration. If it comes back, it's
still specifically scoped to a non-default "Order for other" entry point,
not the default checkout/picker screen — that part of the earlier
reasoning still holds, just deferred rather than built now.

#### Migration steps, in order, once unblocked

1. Finalize app name + `applicationId`; do a full rebuild/reinstall so the
   signing cert used for local testing is stable before generating any
   Google Maps key against it.
2. Set up Google Cloud billing (card), then immediately configure:
   - Two separate API keys — one Android-restricted (package name +
     SHA-1) for Maps SDK only; one server-only, IP/API-restricted, for
     Geocoding + Directions (used only by the PHP backend, never shipped
     in the app).
   - Per-API daily Quota caps on both Geocoding and Directions (hard
     enforcement — see billing section above; budget alerts alone don't
     block spend).
3. Add `com.google.android.gms:play-services-maps` to
   `customer/app/build.gradle`, remove the osmdroid dependency once the
   swap is verified working (don't remove it first — keep both during the
   transition so there's a fallback if the Maps SDK key isn't approved/
   working yet).
4. Add the Android-restricted key to `AndroidManifest.xml` as the
   standard `com.google.android.geo.API_KEY` meta-data tag (Maps SDK
   reads it from there at launch — this is why it must be a *restricted*
   key, since it necessarily ships inside the APK; see the API key
   security section above for why DB-fetching this particular key at
   runtime doesn't add real protection over a properly restricted static
   key).
5. In `MapPinDropActivity.kt`, swap the `org.osmdroid.views.MapView` for
   `com.google.android.gms.maps.MapView` (or `SupportMapFragment`) —
   marker/camera APIs differ (`GoogleMap.addMarker`/`moveCamera` vs
   osmdroid's `Marker`/`controller.setCenter`), but the fixed-center-pin
   UX pattern, the debounced-reverse-geocode-on-idle logic, and the
   photo-upload/save flow are unchanged — only the map-rendering layer
   swaps.
6. Move reverse-geocoding and forward-search calls from the on-device
   `Geocoder` (current osmdroid-era approach) to the backend-proxied
   Google Geocoding endpoint, once that backend endpoint exists (not yet
   built — new backend work, not just an app-side change).
7. Update `activity_map_pin_drop.xml`'s `org.osmdroid.views.MapView` tag
   to the Google Maps equivalent.
8. Repeat steps 5-7's map-widget swap for the Live Tracking screen once
   Phase 4 is underway (that screen doesn't exist yet as of this doc).

### Address flow (Location Picker) — confirmed behavior

- Default screen: saved addresses list + "Add New Address" button.
- Add New Address: if GPS is on, current location auto-fetches and centers
  the map with no extra tap; if off, prompt to enable or fall back to a
  default city-center view.
- Pin-drop: center-fixed pin, user scrolls the map to position it (already
  the H6 Part 1/2 design — no change here).
- No search bar anywhere in this update — "Order for other" as a
  search-reveal button is cut, current-location + pin-drop are the only
  two ways to set a location. `inputMapSearch` in
  `activity_map_pin_drop.xml` is now dead/unused — remove that EditText
  (and its layout constraints) from the layout when doing the Google Maps
  migration pass, rather than leaving an inert search box in the XML.
- Address details form (Landmark, House No., Area/Street, Receiver Name,
  Phone Number) must be complete before the order can be placed — this is
  plain form validation, no API cost.

- **Deployment checklist once the Activity is written and building:**
  1. Run `backend/sql/16_migration_address_photo.sql` in phpMyAdmin.
  2. Copy the updated `backend/` folder (new `address-photo.php`, updated
     `addresses.php`, new `uploads/` dir with its `.htaccess`) into the KS
     Web `anydrop` folder — **confirm the web server user can write to
     `uploads/address_photos/`** (create-if-missing `mkdir` in the PHP
     code should handle this on first upload, but worth checking
     permissions don't block it on whatever host this ends up on).
  3. Push `customer/` changes from Termux, confirm the build passes with
     the new osmdroid dependency pulled in (first time this project
     builds a dependency beyond what's already in `build.gradle` — worth
     watching that CI step specifically in case of a resolution issue).
  4. Install the APK and manually walk through: open Location Picker →
     Add Address → confirm the map loads and tiles render, drag the map
     and confirm the address line updates (not on every frame — count
     roughly how often it fires to sanity-check the debounce is actually
     working), tap "Use current location" and confirm it recenters, fill
     in address details + receiver info, optionally add a photo, Save,
     confirm it lands back on Location Picker with the new address in the
     saved list and (if a photo was added) that `photo_url` is actually
     populated on that row in the DB.

---

## Rider navigation, background tracking, and drop-off OTP flow (added 2026-08-12) — plan only, not built

This is Rider-app scope, not Customer-app/H6 scope — recorded here because
it was defined in the same planning conversation as the Google Maps
migration above and depends on it (the navigation hand-off). The Rider app
itself doesn't exist yet (empty folder, Phase 4 not started) — this section
is the plan to build against once Phase 4 starts, not a description of
anything currently working.

### Full sequence, in order

1. **Rider accepts the order.** Normal accept flow (not detailed here —
   part of core Phase 4 order-assignment work, not map-specific).
2. **Rider marks the order "Picked Up."** This status update is what
   reveals the "Start Navigation" button — the button does not appear
   before pickup, since there's nothing to navigate to yet at the
   accept-only stage (rider still needs to get to the restaurant first,
   which either has its own separate navigate-to-restaurant step or is
   out of scope for this note — clarify which when Phase 4 planning
   starts in earnest).
3. **Rider taps "Start Navigation."** Fires the `google.navigation:` deep
   link intent described in the migration plan above, opening the
   separately-installed Google Maps app pointed at the customer's
   pin-dropped coordinates. This is the same free, non-API deep-link
   mechanism already documented — no new cost here.
4. **While the rider is in Google Maps (not the Anydrop app), the Anydrop
   Rider app keeps sending GPS pings to the backend from the background.**
   This requires a background location service (foreground service with
   a persistent notification, per Android's background-location rules for
   API 26+) — the app doesn't need to be in the foreground or visible for
   tracking to keep working; it just needs the service alive. This is the
   same 5-7s GPS-ping mechanism already planned for live tracking
   (`03_Live_Tracking.md`), just running from a background service
   instead of a foreground screen. Customer/Restaurant/Admin tracking
   screens keep working exactly as already designed during this whole
   window, since they only depend on the backend receiving pings, not on
   which app is in the foreground on the rider's phone.
5. **Rider reaches the destination and manually reopens the Anydrop app**
   (switches back from Google Maps). No auto-detection of arrival is
   planned — this is a manual switch-back by the rider.
6. **App prompts for the drop-off OTP.** Entering it (matching what the
   customer has) completes the delivery.
7. **Customer receives the OTP through three channels simultaneously:**
   a push notification, the in-app bell/notification icon, and directly
   on the order-detail screen. All three should show the same OTP value
   — this isn't three different OTPs, one value surfaced three places.
8. **If the rider doesn't receive/see the OTP from the customer** (e.g.
   customer didn't check their phone), the rider has a **"Resend OTP"**
   action that re-triggers the same notification to the customer, rather
   than generating a new/different OTP — the original OTP is resent, not
   rotated, so a customer who already has the right one from step 7 isn't
   invalidated by a resend.

### What still needs deciding when Phase 4 planning starts (not answered yet)

- Exact trigger for OTP generation — at order-placement, at pickup, or
  only once the rider reaches the destination radius? Affects how early
  the customer can see it on the order-detail screen.
- Whether there's a separate "Navigate to restaurant" deep-link step
  before pickup (mentioned as an open question in step 2 above), or if
  restaurant-bound navigation is out of scope for v1.
- Foreground-service notification content/permissions — Android 13+
  requires a runtime notification permission in addition to location
  permission for a persistent foreground-service notification to show,
  which will need its own permission-request flow in the Rider app (not
  yet designed).
- What happens if the rider force-closes the app or the phone kills the
  background service before drop-off — whether tracking should
  auto-resume on next app-open, and whether the customer/restaurant/admin
  tracking screens need a "rider location stale" indicator for that gap.

