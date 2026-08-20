# Handover Note — Item Tags Feature (2026-08-20)

## Ask (user ke original words)
1. Restaurant app: har menu item ke "niche" tags ka option do (Pizza, Onion,
   Capsicum, etc.) — jisse customer app ke Home screen category chips
   automatically wahi items filter kar sakein.
2. Restaurant app: location pe click karne par location permission maange,
   location ON karwaye.
3. Location "overwrite" honi chahiye (nayi location purani ko replace kare).
4. Customer app ke home screen filter ko bhi isi tarah design karo.

## ✅ Poora ho chuka hai

### Backend (PHP)
- `backend/lib/menu_item_tags.php` — naya shared helper:
  `resolve_food_category_ids()`, `set_menu_item_tags()`,
  `get_menu_item_tags()`, `get_menu_item_tags_bulk()`.
  Koi nayi table nahi banani padi — `food_categories` aur
  `menu_item_categories` (migration 05) already maujood the; customer app
  ka Home filter unhi se chalta hai. Bas restaurant side se kabhi likha
  nahi ja raha tha.
- `backend/api/v1/restaurant/food-tags-list.php` — **NAYA** endpoint.
  `GET`, restaurant-auth, sab active food_categories (id/name/slug/icon)
  laut ata hai — restaurant app ka tag-picker isi se populate hoga.
- `backend/api/v1/restaurant/menu-items-create.php` — ab body me `tags`
  (slug array, e.g. `["pizza","onion"]`) accept karta hai, save karta hai,
  aur response me `tags` field return karta hai.
- `backend/api/v1/restaurant/menu-items-update.php` — same, PLUS: `tags`
  key sirf tabhi replace hoti hai jab request body me explicitly bheji ho
  (taaki out-of-stock toggle jaisi partial update kabhi tags wipe na kare).
- `backend/api/v1/restaurant/menu-items-list.php` — response me har item
  ke saath uske `tags` (slug array) bhi ab aate hain, bulk query se (N+1
  nahi).

### Restaurant app (Kotlin)
- `network/ApiService.kt` — `getFoodTags()` add.
- `network/Models.kt` — `FoodTag`, `FoodTagsListResult` add; `MenuItem`,
  `MenuItemCreateBody`, `MenuItemUpdateBody` sab me `tags` field add.
- `res/layout/dialog_add_menu_item.xml` — Veg switch ke neeche ek naya
  "Tags" section: heading + hint text + `ChipGroup` (`itemTagsGroup`,
  multi-select) + fallback empty/error label (`itemTagsEmptyLabel`).
- `res/values/strings.xml` — `label_item_tags`, `label_item_tags_hint`,
  `label_item_tags_loading`, `label_item_tags_unavailable` add.
- `ui/menu/MenuFragment.kt` —
  - `cachedFoodTags` — tag list ek baar fetch hoti hai, fragment ke life
    me reuse hoti hai (dialog baar-baar khulne pe dobara call nahi hota).
  - `loadFoodTagsIntoDialog()` / `renderTagChips()` — dialog khulte hi
    (add ya edit dono me) chips render karte hain, edit me existing tags
    pre-checked aate hain.
  - `showItemDialog()` / `btnItemDialogSave` click / `saveItem()` — sab
    update kiye taaki selected chip slugs collect ho kar create/update
    body me jayein.

### Customer app
- Kuch nahi banana pada — `FoodCategoryAdapter` / `HomeActivity` /
  `home/category-items.php` already `food_categories` +
  `menu_item_categories` se chalte hain. Ab jaise hi restaurant koi item
  ko "Pizza" tag karega, wo item apne aap customer app ke Home screen ke
  "Pizza" chip ke neeche dikhna shuru ho jayega — koi extra customer-side
  kaam nahi chahiye tha.

## 🐛 Bug fixes (screenshots, 2026-08-20)

Two more app-owner reports, screenshots taken right after the item-tags +
location-settings work above:

1. **"Restaurant address" field stays blank after picking a location** —
   real bug, not a migration issue. `EditProfileActivity`'s
   `pickLocationLauncher` (map-picker result) was only reading
   `EXTRA_RESULT_LAT` / `EXTRA_RESULT_LNG` off the result `Intent` and
   silently dropping `EXTRA_RESULT_ADDRESS_LINE`, even though
   `LocationPickerActivity` already reverse-geocodes the dropped pin and
   sends that extra back. Fixed: now reads it and fills `inputAddress`
   (map pick explicitly overwrites, same convention as the Customer app's
   `MapPinDropActivity` caller). Separately, the "Use current location"
   GPS row (`fetchCurrentLocationForRow()` / `onCurrentLocationResolved()`)
   never reverse-geocoded at all — it only ever had lat/lng, no address
   string to forward. Added a `reverseGeocodeAndFillAddress()` helper
   (same on-device `Geocoder` call `LocationPickerActivity` uses) that now
   runs after a GPS fix resolves and fills the same field; on any geocode
   failure it just leaves the field alone for manual entry.
   The "Current location used" / "Location set" row labels going green in
   the screenshot were correct, working as designed — that's what made it
   look like *only* the address text was broken, since lat/lng really was
   being saved.

2. **"Couldn't load tags — item will save without them" in Add Menu Item**
   — checked `food-tags-list.php` and the client call end-to-end, code
   looks correct on both sides (matches every other working
   `restaurant/*.php` endpoint's shape/auth). This is almost certainly
   exactly the risk item 4 (below) already flagged: `food-tags-list.php`
   queries the `food_categories` table, which only exists once
   **migration 05** (`backend/sql/05_migration_categories_and_tags.sql`)
   has actually been run on the *live* DB — if it hasn't, that query
   500s, the app's catch-block swallows it, and this fallback text is
   exactly what shows. **Action needed on your end**: run
   `05_migration_categories_and_tags.sql` against the live database (it's
   idempotent/safe to re-run). If tags still fail to load after that,
   send me the actual HTTP response/error from `food-tags-list.php` (or
   server error log) and I'll dig further — but nothing in the endpoint
   or client code itself looks broken.

## 📌 Decision — Home category ownership (app owner, 2026-08-20)

Confirmed scope boundary for the item-tags feature above: restaurants
only ever **select from** the existing `food_categories` list to tag
their own items (the Tags picker built above) — they never create a
*new* Home-screen category. Adding/editing/deactivating a `food_categories`
row (e.g. adding a "Momos" chip) is **admin-panel-only**, via a CRUD
screen that doesn't exist yet (still just seeded via
`05_migration_categories_and_tags.sql`). Full note + the
`food_categories` vs `restaurant_categories` naming clarification is in
`docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md`
under "Category Management" — that's where this CRUD screen should get
built whenever the admin panel work picks up.



1. ~~**Location ON prompt**~~ — ✅ **is session me ho gaya.**
   `EditProfileActivity.fetchCurrentLocationForRow()` aur
   `LocationPickerActivity.fetchCurrentLocation()` dono me ab, agar GPS
   aur Network provider dono OFF hain, toast ke saath-saath device ki
   Location Settings screen (`Settings.ACTION_LOCATION_SOURCE_SETTINGS`)
   bhi seedha open ho jaati hai.
   - `EditProfileActivity`: `fetchCurrentLocationForRow()` sirf ek row
     (`rowUseCurrentLocation`) ke explicit tap se hi chalta hai, isliye
     GPS-off case me hamesha settings open hoti hai — koi extra guard
     nahi chahiye tha.
   - `LocationPickerActivity`: yahan `fetchCurrentLocation()` do jagah se
     chalta hai — (a) `btnUseCurrentLocation` ke explicit tap se, aur (b)
     `onCreate()` se silently (agar profile me pehle se koi location
     saved nahi hai). Settings screen sirf case (a) me khulni chahiye —
     screen khulte hi silently settings pe jump kar dena bura UX hota.
     Isliye `requestCurrentLocation()` / `fetchCurrentLocation()` dono me
     ek naya `userInitiated: Boolean` param add kiya (button click =
     `true`, auto-call = `false` default), aur permission-prompt round
     trip ke through ye flag carry karne ke liye ek naya field
     `pendingRequestWasUserInitiated` add kiya (kyunki permission granted
     callback ke time pe pata nahi chalta ye request kahan se aayi thi).
   - Koi naya string resource nahi chahiye tha — dono jagah existing
     `R.string.location_gps_off` hi reuse hua hai.
   - **Verify nahi kiya** (Android SDK/gradle is environment me nahi
     hai) — pehli baar build lete waqt in dono files pe zaroor dekh lena.
2. **Overwrite** — backend (`profile-update.php`) me already sahi kaam
   kar raha hai (naya lat/lng bheja jaye to purana clean overwrite hota
   hai) — is par koi aur kaam nahi chahiye, bas confirm kiya gaya hai.
3. Restaurant app ka gradle build khud chala kar verify nahi kiya gaya
   (is environment me Android SDK/gradle available nahi hai) — pehli
   baar build lete waqt dhyan se dekh lena, khaas kar
   `com.google.android.material.chip.ChipDrawable` aur
   `com.google.android.material.R.style` import wale portion pe.
4. Zaroorat pade to `backend/sql/` me koi migration file add nahi ki
   gayi hai kyunki DB me tables pehle se maujood the — lekin agar aapka
   live DB kisi purani migration se peeche hai (05/14 migrations abhi
   tak nahi chali), to unhe zaroor chala lena warna `food-tags-list.php`
   / naya tags flow 500 error dega.

## Files touched (quick list)
```
backend/lib/menu_item_tags.php                         [NEW]
backend/api/v1/restaurant/food-tags-list.php            [NEW]
backend/api/v1/restaurant/menu-items-create.php
backend/api/v1/restaurant/menu-items-update.php
backend/api/v1/restaurant/menu-items-list.php
restaurant/app/src/main/java/.../network/ApiService.kt
restaurant/app/src/main/java/.../network/Models.kt
restaurant/app/src/main/java/.../ui/menu/MenuFragment.kt
restaurant/app/src/main/res/layout/dialog_add_menu_item.xml
restaurant/app/src/main/res/values/strings.xml
restaurant/app/src/main/java/.../ui/account/EditProfileActivity.kt      [location settings deep-link]
restaurant/app/src/main/java/.../ui/account/LocationPickerActivity.kt   [location settings deep-link]
```
