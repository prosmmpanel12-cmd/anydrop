# Handover — 2026-09-07 — Admin Panel Base URL: New Setting + Every File-Link Rewired To Use It

**App owner request:** "pure admin panel ke liye ek base url banao
settings table mein, aur sari files is base url se chale" — one
configurable base URL, stored in `app_settings`, that every admin-panel
page's links to uploaded files / private-document endpoints are built
from, instead of each page separately guessing a relative path.

## Why this was raised

Earlier the same day, `admin/riders.php`'s "View ID Doc"/"View Vehicle
Doc" links 404'd — they used an **absolute root path**
(`/api/v1/rider/documents-view.php`) but this backend is deployed under
a subdirectory (`/anydrop/`, per the Customer/Restaurant/Rider apps'
own `ApiClient.kt` `BASE_URL` constants), not the domain root. That got
a same-session hotfix to a relative `../` path — but a relative path is
itself fragile the exact same way: it silently depends on how many
folders deep the *calling* page happens to be. There was already
precedent for this going both right and wrong in this codebase —
`banners.php`/`settlements.php`/`support.php` had each separately
guessed `../` correctly, `riders.php` guessed wrong (absolute). One
admin-configured value removes the guessing everywhere, permanently.

## What was built

**New `app_settings` key: `admin_base_url`.** No seed migration — falls
back to `http://localhost:8080/anydrop` (this project's own local/
sandbox default, matching the three apps' `ApiClient.kt`) via
`get_setting()`'s own default parameter until an admin saves a real
value. Same "in-code default until someone saves" convention
`google_directions_api_key` / `route_recalc_*` already established —
deliberately not reinventing a different pattern for this one setting.

**New helper: `admin_base_url()` in `backend/admin/_bootstrap.php`.**
Every `admin/*.php` page already requires `_bootstrap.php` first, so
every page gets this for free — no extra `require_once` needed anywhere
that wasn't already there. Always returns the value **without** a
trailing slash, so every call site can safely write
`admin_base_url() . '/uploads/...'` without ever risking a doubled `//`.

**New settings page: `backend/admin/base-url-settings.php`.** Same
shape as `directions-settings.php` (this project's most recent
single-value settings page) — one text field, validates the pasted
value starts with `http://`/`https://`, strips any trailing slash
before saving, audit-logs the change. Added to the sidebar's Settings
group (`_layout_head.php`) as the first entry, gated on
`settings_manage` (already-seeded permission, no new RBAC migration
needed — same as every other page in that nav group).

**Every existing relative/absolute file-link rewired to use it:**

| File | What changed |
|---|---|
| `admin/riders.php` | Document-view links: `../api/v1/...` (today's earlier hotfix) → `<?= admin_base_url() ?>/api/v1/...` |
| `admin/banners.php` | Both banner-image `<img>` tags: `../<?= image_url ?>` → `<?= admin_base_url() ?>/<?= image_url ?>` |
| `admin/settlements.php` | Screenshot link + thumbnail: same `../` → `admin_base_url()` swap |
| `admin/support.php` | Attachment link + thumbnail: same swap |

`admin/categories.php`'s `icon_url` was **deliberately left alone** —
that field is free-text admin input meant to hold a full external URL
(placeholder text literally says `https://...`), not a locally-uploaded
relative path, so it was never part of this problem.

## Not done / caveats

- **No live browser/DB test** — same standing sandbox limitation as
  every session in this project. Verified via direct code reading:
  brace/paren balance on every touched/new file, confirmed no duplicate
  `admin_base_url()` definition, confirmed `admin_csrf_token()`/
  `admin_verify_csrf()` (used by the new settings page) already exist
  in `_bootstrap.php`.
- **The default value still needs to be corrected once, per real
  deployment** — an admin visiting `base-url-settings.php` and pasting
  the actual live domain (e.g. `https://yourdomain.com/anydrop` or
  wherever this backend is actually hosted) is what makes every link
  this session touches actually resolve correctly in production; until
  then everything still points at the same local-dev default it always
  implicitly assumed.
- Did not audit `backend/admin/*.php` beyond file-link `<img>`/`<a>`
  tags for other places an absolute vs. relative deployment-path
  assumption might be hiding (e.g. any JS `fetch()` calls, redirect
  URLs) — this session's scope was specifically "links to uploaded
  files / private-document endpoints," matching the bug that triggered
  the request.
