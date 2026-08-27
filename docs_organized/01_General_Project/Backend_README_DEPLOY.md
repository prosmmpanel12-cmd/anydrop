# Anydrop Backend — Local Setup (KS Web on Android)

Running on the same phone via **KS Web** at `http://localhost:8080/anydrop`. This works because the backend and the app (once installed) share the same device — no InfinityFree needed for now.

## What this delivers
- Full database schema (all tables from `01_Database_Schema.md`)
- 5 working APIs:
  - `POST /api/v1/auth/restaurant/login`
  - `POST /api/v1/auth/customer/email/request-otp`
  - `POST /api/v1/auth/customer/email/verify-otp`
  - `GET /api/v1/restaurants`
  - `GET /api/v1/restaurants/{id}/menu`
- Admin + test-data seed scripts

`config/config.php` is already set for local MySQL (`localhost` / `root` / no password) — no editing needed for local testing.

## Setup Steps

### 1. Create the database
1. Open KS Web's phpMyAdmin (usually a button/link inside the KS Web app, or visit `http://localhost:8080/phpmyadmin` in the phone browser)
2. Click **New** (or **Databases** tab) -> create a database named exactly `anydrop`
3. Select the `anydrop` database -> **SQL** tab
4. Paste the entire contents of `sql/01_schema.sql` -> click **Go**
5. You should see ~20 tables created, plus seeded rows in `app_settings`

### 2. Place backend files in the KS Web folder
Copy everything inside `backend/` into your existing `anydrop` web folder (the one already serving `http://localhost:8080/anydrop`).

From Termux, if your KS Web root folder is (commonly) something like `/sdcard/ksweb/www/anydrop` or similar -- check inside the KS Web app settings for the exact path -- then:

```bash
cd ~/anydrop-phase1-download   # wherever you extracted the new zip
cp -r backend/. /path/to/ksweb/www/anydrop/
```

(Ask if you're not sure of the exact KS Web www path -- check the app's "Document Root" or "htdocs" setting.)

### 3. Verify the API is alive
Open in phone browser: `http://localhost:8080/anydrop/`
You should see: `{"success":true,"data":{"message":"Anydrop API is running","version":"phase1"},"error":null}`

**Note:** KS Web may or may not support `.htaccess` rewrite rules depending on which web server it uses internally (Apache vs nginx vs lighttpd). If clean URLs like `/api/v1/restaurants` don't work, use the direct PHP file paths instead -- see "Direct File Paths" below.

### 4. Seed an admin account
Visit: `http://localhost:8080/anydrop/scripts/seed-admin.php?key=SEED_ME&username=admin&password=YourStrongPassword`
Then delete `scripts/seed-admin.php`.

### 5. Seed test data
Visit: `http://localhost:8080/anydrop/scripts/seed-test-data.php?key=SEED_ME`
Creates a demo restaurant (`demo@anydrop.test` / `Demo@1234`) with a sample menu.
Then delete `scripts/seed-test-data.php`.

### 6. Test the APIs (via Termux curl)

```bash
curl -X POST http://localhost:8080/anydrop/api/v1/auth/restaurant-login.php \
  -H "Content-Type: application/json" \
  -d '{"email":"demo@anydrop.test","password":"Demo@1234"}'
```

```bash
curl -X POST http://localhost:8080/anydrop/api/v1/auth/customer-request-otp.php \
  -H "Content-Type: application/json" \
  -d '{"email":"you@example.com"}'
```

```bash
curl -X POST http://localhost:8080/anydrop/api/v1/auth/customer-verify-otp.php \
  -H "Content-Type: application/json" \
  -d '{"email":"you@example.com","otp":"123456"}'
```

```bash
curl "http://localhost:8080/anydrop/api/v1/restaurants/list.php?lat=28.6139&lng=77.2090" \
  -H "Authorization: Bearer YOUR_CUSTOMER_TOKEN"
```

```bash
curl "http://localhost:8080/anydrop/api/v1/restaurants/menu.php?id=1" \
  -H "Authorization: Bearer YOUR_CUSTOMER_TOKEN"
```

### Direct File Paths (if .htaccess rewriting doesn't work on KS Web)
Use these instead of the "clean" URLs from the API contract:

| Clean URL (contract) | Direct file path (use this on KS Web) |
|---|---|
| `POST /api/v1/auth/restaurant/login` | `POST /api/v1/auth/restaurant-login.php` |
| `POST /api/v1/auth/customer/email/request-otp` | `POST /api/v1/auth/customer-request-otp.php` |
| `POST /api/v1/auth/customer/email/verify-otp` | `POST /api/v1/auth/customer-verify-otp.php` |
| `GET /api/v1/restaurants` | `GET /api/v1/restaurants/list.php` |
| `GET /api/v1/restaurants/{id}/menu` | `GET /api/v1/restaurants/menu.php?id={id}` |

The Android app's networking code will be written to call the direct file paths, so this table is just for your own testing/reference.

## Important limitation to remember
This local setup **only works when the app and server are on the same device**. It's great for fast iteration right now. When you're ready to let real customers/restaurants use the app from their own phones, you'll need to move the backend to real hosting (InfinityFree, as originally planned) -- nothing in the code needs to change, just the config values and where files are uploaded.

## Known Phase 1 Simplifications (documented, to revisit later)
- OTP email delivery is stubbed (`debug_otp` returned in response) -- real SMTP comes in a follow-up pass
- Auth tokens are opaque (not JWT) -- simple and sufficient for this project's scale
- `POST /auth/customer/google` not yet implemented -- email OTP is the first working login path
