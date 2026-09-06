# Handover — Rider Documents (deep-plan §22), BACKEND + ADMIN COMPLETE, ANDROID IN PROGRESS

Session date: 05 Sep 2026 (same day as docs 94-96). Doc 96 closed out
Rider Payout Requests (deep-plan §21) end to end. Person then picked
§22 (Rider Documents) over §23 (FCM/Notifications) as the next-step —
doc 90's other flagged item — for this session.

> **Not built/tested against a live backend or device.** Same standing
> caveat as every other handover in this project — no PHP CLI, Android
> SDK, Gradle, or DB in this sandbox. All PHP files below were checked
> with a script-based brace/paren/bracket balance pass (comment-aware,
> not just eyeballing) and came back clean. The 2 touched Kotlin files
> were checked the same way. See "Verification done this session"
> below for the exact scope. `php -l`/Gradle/a live device are still
> the only real tests — none of this substitutes for them.

## Where this session started

Before touching anything new, this session re-verified doc 96's own
claimed work, since that was the most recent unverified handover:
- All 5 Rider Payout Requests backend files (`payout.php`,
  `payout-bank-details-get.php`, `payout-bank-details-save.php`,
  `lib/rider_payout.php`, `admin/rider-payouts.php`) — balance-checked
  clean with a corrected comment-aware script (the first naive pass
  threw a false positive on `payout.php` from an apostrophe inside a
  `/** */` docblock — re-checked and confirmed it was a script bug, not
  a real file bug).
- Migration 74 SQL — read in full, confirmed internally consistent
  with the codebase's established conventions (mirrors migration 65's
  wallet-withdrawal shape, as its own header states).
- Confirmed all of doc 96's new/changed Android files
  (`RequestPayoutActivity.kt`, `RiderPayoutAdapter.kt`,
  `ApiErrorParser.kt`, the 2 new layouts, manifest entry, strings)
  actually exist on disk as claimed.

No corrections were needed — doc 96's own verification claims held up.
Rider Payout Requests' only remaining gaps are the same ones doc 96
already listed (live migration run, `php -l`, live click-through, no
notification surface yet).

## Deep-plan §22 scope (this session)

> ID document. Vehicle document. Vehicle type. Vehicle number.
> Optional profile photo. Admin should see document status and be able
> to reject/suspend for compliance reasons. Do not upload arbitrary
> files directly into a public web directory without access controls.

`vehicle_type`/`vehicle_number`/`vehicle_doc_url`/`id_doc_url` columns
already existed on `riders` (migration 69) but nothing had ever written
to the two doc-url columns — no endpoint, no admin UI, no Android
screen touched them. This session builds that missing piece.

**Security-critical decision, called out up front because it shapes
every file below:** this is the codebase's **first private,
access-controlled upload**. Every existing upload endpoint (restaurant
logos/banners/dish photos, address photos) saves into the public
`backend/uploads/` tree, whose own `.htaccess` only blocks *executing*
scripts there — plain file GETs are still world-readable by design,
since those are meant to be publicly displayable images. A government
ID photo is not that. Per the deep-plan's own instruction above, ID/
vehicle documents are stored in a brand-new `backend/rider_documents/`
directory with a `Require all denied` `.htaccess` (same total-denial
convention `backend/logs/.htaccess` already uses) — nothing can read
these files over HTTP directly, ever; the only access path is a new
gated endpoint that streams the bytes after checking who's asking. See
`documents-view.php` below for the full auth logic. `profile_photo_url`
is NOT treated this way — a rider's profile photo is meant to be shown
to a restaurant/customer, so it stays in the ordinary public
`uploads/rider_profile_photos/` tree, same convention as every other
`*_photos` folder there.

## What was built — Backend (complete)

### `backend/sql/75_migration_rider_documents.sql`
New columns on `riders`: `profile_photo_url`, `documents_status`
(ENUM `not_submitted`/`pending`/`verified`/`rejected`, default
`not_submitted`), `documents_reject_reason`, `documents_submitted_at`,
`documents_verified_by_admin_id`, `documents_verified_at`. Uses the
same idempotent `CONTINUE HANDLER FOR 1060` (duplicate column)
procedure pattern migration 69 established — the first draft of this
migration used an `information_schema` existence check instead, caught
during this session's own cross-check against migration 69 and
corrected to match the codebase's real convention before finalizing.
FK on `documents_verified_by_admin_id` added via a second idempotent
procedure (`CONTINUE HANDLER FOR 1826`/`1005`).

`documents_status` is deliberately a **4-state enum** and a **separate
column from `riders.status`** — full reasoning is in the migration's
own header, but in short: a rider's account approval and their document
verification are two independent lifecycles (an already-approved rider
can have a document flagged and be asked to re-submit without an admin
also having to suspend the whole account to say so), so they get
independent status + reason columns, mirroring how `restaurants.status`
and `restaurant_bank_details.verification_status` already coexist
independently in this schema. The 4th state (`not_submitted`, with no
equivalent in migration 59's 3-state bank-verification enum) exists
because a PENDING rider's approval decision may hinge on "have they
even submitted documents yet" — that needs to read differently in the
UI from "submitted, awaiting review".

Backfill: any existing rider row with both doc URLs already non-NULL
(none should exist today, per this file's own header, but kept correct
in case of a prior manual admin edit) is backfilled to `pending`, not
left at `not_submitted`.

RBAC: new `rider_documents_view`/`rider_documents_manage` permission
pair, deliberately separate from `riders_view`/`riders_edit`/
`riders_approve` — same "this specific action touches more sensitive
data than the base list view" reasoning migration 74 used to keep
`rider_payouts_*` separate from `payouts_manage`. Granted to every role
already holding `riders_approve` (today, just Super Admin).

### New: `backend/rider_documents/.htaccess`
`Require all denied` — the entire directory is unreachable over HTTP.
Files land at `backend/rider_documents/<rider_id>/<random-hex>.<ext>`.

### New: `backend/api/v1/rider/documents-upload.php`
POST, multipart/form-data, Auth: Rider token. Required file field
`id_doc`; optional `vehicle_doc`, `profile_photo`; optional text fields
`vehicle_type`/`vehicle_number` (deep-plan §22 groups these with
document submission, not the original signup form — consistent with
the 2026-09-01 decision already on record to defer them off the
signup screen). `id_doc`/`vehicle_doc` accept image or PDF; profile
photo accepts image only. 5 MB cap, same as every other upload endpoint
in this codebase. Every submission resets `documents_status` to
`pending` and clears any prior reject reason/verification stamp — a
fresh submission always needs a fresh look. Old files are left on disk
when replaced (not deleted) — same "orphaned upload is a cheap,
harmless cost" call `logo-upload.php` already documents, and here it
additionally avoids yanking a file out from under an admin who has it
open in another tab mid-review. Response returns no URL for the two
private docs (there is none to return) — only `documents_status` and
`profile_photo_url` (which IS a real public path, since that file went
to the public tree).

### New: `backend/api/v1/rider/documents-get.php`
GET, Auth: Rider token. Returns `documents_status`,
`documents_reject_reason`, `has_id_doc`/`has_vehicle_doc` (booleans,
not URLs), `profile_photo_url`, `vehicle_type`, `vehicle_number`.
Deliberately never returns the raw private filenames — a client has no
business constructing a URL to a private file itself.

### New: `backend/api/v1/rider/documents-view.php`
GET `?rider_id=<id>&doc=id|vehicle` — streams the raw file bytes
(`readfile()`, correct `Content-Type` via extension map, `Cache-
Control: private, no-store`). This is the **only** way these private
files are ever served. Unlike every other `api/v1/` endpoint, it
accepts two different caller types because it's linked to from both
the native Rider app AND the server-rendered Admin panel:

1. A rider Bearer token whose `owner_id` matches the requested
   `rider_id` — viewing their own submitted document back.
2. An active admin PHP session holding `rider_documents_view` —
   `admin/riders.php`'s review links point straight here.

Uses `get_authenticated_owner()` directly rather than `require_auth()`,
since `require_auth()` hard-fails immediately on any problem and this
endpoint needs to fall through to the admin-session check first (an
admin request never sends an Authorization header at all). Falls back
to `session_start()` guarded by `session_status() !== PHP_SESSION_ACTIVE`
to avoid a double-start warning if this file is ever reached in a
context where a session is already open. Filename is always this
codebase's own `bin2hex()`-generated value (never user input) but the
endpoint still runs a `realpath()`-prefix containment check before
`readfile()`-ing, as a defensive floor against any future write path
that isn't as careful. No credentials at all, a mismatched rider id, or
an admin session lacking the permission all return the same generic
403/404 — no distinction that would leak which case applied.

### `backend/api/v1/rider/me.php` (extended, additive only)
Response gained `documents_status`, `documents_reject_reason`,
`profile_photo_url` — same "purely additive, existing fields
unchanged" convention every prior extension of this endpoint has used
(Phase 3's `is_online`/`vehicle_type`/`vehicle_number` addition being
the most recent precedent, referenced directly in this file's own
updated kdoc).

### `backend/admin/riders.php` (extended)
- Two new permission flags read at the top: `$canViewDocuments`
  (`rider_documents_view`), `$canManageDocuments`
  (`rider_documents_manage`).
- List query now also selects `documents_status`,
  `documents_reject_reason`, `id_doc_url`, `vehicle_doc_url`,
  `profile_photo_url`.
- New **Documents** column in the table (only rendered when
  `$canViewDocuments`, `<th>`/`<td>` pair kept symmetric — verified,
  no colspan anywhere in this file needed adjusting).
- New **`verify_documents`**/**`reject_documents`** POST actions,
  directly mirroring `settlements.php`'s existing
  `verify_bank_details`/`reject_bank_details` action pair (same
  form-action-value-picks-the-status trick, same "reason required only
  to reject" rule, same guard that only a currently-`pending`
  documents-status can be actioned). Every transition writes to
  `audit_logs` via the same `write_audit_log()` helper every other
  action on this page already uses.
- Manage dialog gained a **Documents** section (between the existing
  account-status block and the existing area-assignment block):
  current documents status + last reject reason, "View ID Doc"/"View
  Vehicle Doc" links pointing at `documents-view.php` (only shown when
  a doc has actually been submitted), and — gated separately on
  `$canManageDocuments` — Verify/Reject forms when status is `pending`.
- Top-of-file kdoc updated to document the new gating and the
  `documents-view.php` two-path auth it's linking out to.

## Verification done this session (backend)

1. **Brace/paren/bracket balance** — script-based, comment-aware (the
   improved script from this session, which strips `/* */` and `//`
   correctly rather than naively toggling on every quote character —
   the earlier false-positive on `payout.php` from doc 96's own
   verification is what prompted writing this better version). Run
   against all 5 touched/new backend PHP files this session
   (`documents-upload.php`, `documents-get.php`, `documents-view.php`,
   `me.php`, `admin/riders.php`). All `OK` — 0 curly, 0 paren, 0
   bracket imbalance, valid `<?php` open tag on every file.
2. **Manual read-through** of migration 75 in full against migration
   59's (restaurant bank verification) and migration 69's (rider
   self-signup) actual on-disk shape, specifically to catch the
   idempotent-procedure-pattern mismatch mentioned above before
   finalizing — corrected in-place, not left as a divergence.
3. **Table/dialog symmetry check** — confirmed the new conditional
   `<th>Documents</th>` / `<td>...</td>` pair in the list table appear
   in matching positions and that no `colspan` attribute exists
   anywhere in this file that the new column would have broken.

None of this substitutes for `php -l`/a live DB/a browser click-through
(still not available in this sandbox) — same standing limitation as
every prior handover.

## What was started but NOT finished — Android (deep-plan §22)

**This is the actual state of the Android side — nothing below this
line compiles into a working screen yet:**

- `rider/.../network/Models.kt` — **done**. `RiderMeProfile` gained
  `documentsStatus`/`documentsRejectReason`/`profilePhotoUrl` (additive,
  matches the backend's `me.php` extension). Two new data classes added:
  `RiderDocumentsResult` (mirrors `documents-get.php`'s response) and
  `RiderDocumentsUploadResult` (mirrors `documents-upload.php`'s
  response). Balance-checked clean.
- `rider/.../network/ApiService.kt` — **only imports added**
  (`okhttp3.MultipartBody`, `retrofit2.http.Multipart`,
  `retrofit2.http.Part`), copied from what the restaurant app's own
  multipart uploads (`uploadLogo()` etc.) need. **The actual endpoint
  method declarations were never added** — no `getRiderDocuments()`,
  no `uploadRiderDocuments()` exist in this file yet. This is the very
  next thing to write.
- **Nothing else Android-side exists for this feature**: no
  `SubmitDocumentsActivity`, no layout, no manifest entry, no strings,
  no wiring into `ApplicationStatusActivity` (pending-rider entry
  point) or `RiderDashboardActivity` (approved-rider re-submission
  entry point) — both of which the person already confirmed as the
  two places this screen should be reachable from, before this
  session's Android work began.

### Research already done this session, ready to use next time
So the next session doesn't have to re-derive any of this:
- The exact multipart-upload pattern to copy is
  `restaurant/.../ui/account/EditProfileActivity.kt`'s `uploadLogo()`
  (copy content Uri to a cache file via `contentResolver.openInputStream`
  + `FileOutputStream`, wrap in `MultipartBody.Part.createFormData()`,
  delete the temp file after the call) — same "content Uris from
  `GetContent()` aren't guaranteed to expose a real filesystem path"
  reasoning that file's own kdoc already gives, directly reusable here
  since the Rider app has no upload code of its own yet to diverge
  from.
- `ApplicationStatusActivity.kt` (existing, unmodified this session) is
  confirmed as the right place for the pending-rider entry point — its
  `renderStatus()` already branches on account status; the natural
  addition is a further branch/button on `documentsStatus` (from a
  `getMe()` call it already makes) to show "Complete Profile" when
  `not_submitted`/`rejected`, or "Documents under review" when
  `pending`.
- `RiderDashboardActivity.kt` (existing, unmodified this session) has
  no Account/Profile hub screen the way the Restaurant app has
  `AccountFragment` — its header row only has `btnLogout`/
  `dashboardGreeting`. The re-submission entry point for an approved-
  but-rejected-documents rider will need to be a small new element on
  that same header row (or a dashboard card, like the existing
  `dashboardEarningsCard` → `EarningsActivity` pattern) rather than a
  nav-menu item, since no such menu exists in this app yet.

### Next session should do, in order
1. Add `getRiderDocuments()` (GET, `rider/documents-get.php`) and
   `uploadRiderDocuments()` (multipart POST, `rider/documents-upload.php`,
   4 `@Part` fields: `id_doc` required, `vehicle_doc`/`profile_photo`
   optional `@Part` `MultipartBody.Part?`, `vehicle_type`/
   `vehicle_number` as `@Part("vehicle_type") RequestBody?` or similar
   text parts) to `ApiService.kt`.
2. Build `SubmitDocumentsActivity.kt` + `activity_submit_documents.xml`
   — 2 required-ish file pickers (ID doc required, vehicle doc
   optional) + 1 optional image picker (profile photo) + vehicle
   type/number text fields + submit button + a read-only "current
   status" section reusing `ApplicationStatusActivity`'s status-pill
   color convention (`status_pending_bg/fg` etc. — this app already has
   these semantic pairs, same ones doc 96's `RiderPayoutAdapter`
   reused rather than introducing new ones).
3. Wire the pending-rider entry point into `ApplicationStatusActivity`.
4. Wire the re-submission entry point into `RiderDashboardActivity`.
5. Add manifest entry + all new strings.
6. Run this session's same verification suite (brace balance, XML
   well-formedness, binding/string/color cross-checks) before calling
   it done.

## Still open (carried over, unchanged)

- Everything doc 96 already listed for Rider Payout Requests (migration
  74 live run, `php -l`, live click-through, no rider notification
  surface).
- Migration 75 live run + `php -l` on this session's 5 backend files.
- Live click-through once Android exists: submit as a rider → confirm
  `documents_status` flips to `pending` on the admin list → View ID/
  Vehicle Doc links actually render the uploaded file (not a 403/404)
  → Verify/Reject from the admin dialog → confirm the rider's own
  `documents-get.php`/`me.php` reflects the new status.
- Known gap, unchanged: no rider notification surface (deep-plan §23,
  still unbuilt) — a rider whose documents get rejected has no in-app
  way to be told beyond opening the app and checking status manually.
- PENDING.md/recall.md haven't been touched this session (both were
  already noted as stale relative to actual progress at the start of
  this session) — worth a real re-audit pass once §22's Android half
  is also done, not before.
