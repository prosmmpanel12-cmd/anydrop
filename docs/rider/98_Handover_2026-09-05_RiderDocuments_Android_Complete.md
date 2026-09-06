# Handover — Rider Documents (deep-plan §22), ANDROID COMPLETE

Session date: 05 Sep 2026 (same day as docs 94-97). This session picks
up exactly where doc 97 left off — backend + admin were already
complete; this session built the missing Android half end to end.

> **Not built/tested against a live backend or device.** Same standing
> caveat as every other handover in this project — no PHP CLI, Android
> SDK, Gradle, or DB in this sandbox. All Kotlin files below were
> checked with a comment/string-aware brace/paren/bracket balance
> script (strips `//`, `/* */`, `"..."`, `"""..."""`, and `'x'` char
> literals correctly before counting) and came back clean. Every
> touched/new XML file (3 layouts, 1 manifest, strings, colors) was
> checked with `xmllint --noout` and is well-formed. Gradle/a live
> device are still the only real tests — none of this substitutes for
> them.

## Where this session started

Before writing anything new, this session re-verified doc 97's own
claimed backend work, since that was the most recent unverified
handover:
- Read `documents-upload.php`, `documents-get.php`, and
  `documents-view.php` in full — all three match doc 97's kdoc
  descriptions exactly (private-storage convention, two-path auth on
  the view endpoint, `id_doc` required on every submit call even a
  re-submission, `vehicle_doc` optional at the endpoint level despite
  being deep-plan-required at the account-approval level).
- Confirmed `Models.kt`'s `RiderDocumentsResult`/
  `RiderDocumentsUploadResult`/`RiderMeProfile` additions and
  `ApiService.kt`'s state (imports added, no endpoint methods yet) both
  match doc 97's claims exactly.

No corrections were needed — doc 97's own verification claims held up.

## What was built this session — Android (complete)

### `rider/.../network/ApiService.kt` (extended)
Added the two methods doc 97 flagged as "the very next thing to
write":
- `getRiderDocuments()` — `GET rider/documents-get.php`.
- `uploadRiderDocuments()` — `@Multipart POST rider/documents-upload.php`,
  4 `@Part`s (`idDoc: MultipartBody.Part` required, `vehicleDoc`/
  `profilePhoto: MultipartBody.Part?` optional) + 2 named text parts
  (`vehicle_type`/`vehicle_number` as `RequestBody?`, `"text/plain"`
  media type) — this is the first multipart call in this app carrying
  non-file fields alongside files, so there was no prior in-app example
  to copy for that half; built directly from OkHttp's own
  `RequestBody.toRequestBody()`.

### `rider/.../data/TokenManager.kt` (extended)
Added `getDocumentsStatus()`/`updateDocumentsStatus()` — same "cached
for instant cold-start rendering, not treated as source of truth"
convention `getIsOnline()`/`setIsOnline()` already established.
Defaults to `"not_submitted"` (migration 75's own column default) when
never set. Refreshed at three call sites: `ApplicationStatusActivity`'s
`getMe()` refresh, `RiderDashboardActivity`'s `getMe()` bootstrap, and
`SubmitDocumentsActivity`'s own load/submit calls.

### New: `rider/.../ui/documents/SubmitDocumentsActivity.kt`
+ `activity_submit_documents.xml`

The actual submission screen. On create, calls `getRiderDocuments()`
and renders:
- A status pill (4 states — not-submitted/pending/verified/rejected,
  new `doc_*_bg`/`doc_*_fg` color pairs in `colors.xml`, deliberately
  separate from the existing `status_*` pairs even though 3 of the 4
  values coincide today — see that file's own comment for why) +
  reject reason (visible only when rejected).
- "Already on file" indicator text for ID doc / vehicle doc / profile
  photo, shown independently of whatever the rider is about to pick.
- Pre-filled vehicle type/number fields from the server response
  (only if the field is currently blank — doesn't clobber in-progress
  typing).

Three file pickers, all plain `ActivityResultContracts.GetContent()`
(`"*/*"` for the two documents since a PDF is valid per
`documents-upload.php`'s own `$allowedDoc`, `"image/*"` for the
profile photo) — picking shows a resolved display name via
`OpenableColumns.DISPLAY_NAME`, not a thumbnail. **No image preview
anywhere on this screen, by design**: this app has no image-loading
library in its dependencies (`coil` is a Restaurant-app-only
dependency, confirmed by grepping `rider/app/build.gradle` this
session), and `documents-view.php`'s Bearer-token auth isn't something
a plain `<img>`/`ImageView.load()` call can attach a header to anyway
without real custom-fetcher work — that's future scope, not something
worth taking on for this slice. Text state is a legitimate, honest
substitute.

Submit button is validated client-side only for "is an ID doc file
actually picked" (`documents_error_id_doc_required` if not) — every
other field is optional, matching the backend's own validation
exactly, so there's no client-side rule here that isn't also a
server-side rule.

Upload path: content Uris are copied to a cache file via
`contentResolver.openInputStream()` + `FileOutputStream`, wrapped in
`MultipartBody.Part.createFormData()`, temp files deleted in a
`finally` block regardless of outcome — same pattern as the Restaurant
app's `EditProfileActivity.uploadLogo()`, extended here to also accept
`application/pdf` for the two document fields (profile photo stays
image-only, matching `documents-upload.php`'s own `$allowedImage` vs
`$allowedDoc` split).

On a successful submit: caches the returned `documents_status` (always
`"pending"` per the endpoint's own contract) into `TokenManager`,
toasts success, `setResult(RESULT_OK)`, finishes — the two callers
(below) just re-render off the cache on `onResume()`, no extra network
round trip needed.

### `rider/.../ui/pending/ApplicationStatusActivity.kt` (extended)
This was the confirmed pending-rider entry point (doc 90/97's own
research). Added:
- `renderDocumentsButton()` — a new `MaterialButton` (`btnManageDocuments`,
  outlined style, sits between `statusReason` and the existing
  `btnRefreshStatus` in `activity_application_status.xml`) that
  branches on `tokenManager.getDocumentsStatus()`: "Complete Profile"
  (not_submitted), "Documents Under Review" (pending), "Documents
  Rejected — Resubmit" (rejected), hidden entirely once verified —
  exactly the three visible states + one hidden state doc 97's own
  research described.
- Called from `onCreate()` (off the cache, no extra network call on
  cold start — same "don't force a round trip just to render" stance
  this screen already takes for its account-status pill), from
  `onResume()` (so coming back from `SubmitDocumentsActivity` reflects
  the just-cached update), and from `onRefreshClicked()`'s success
  branch (which now also calls `tokenManager.updateDocumentsStatus()`
  alongside its existing `updateStatus()`/`setIsOnline()` calls, since
  `getMe()`'s response already carries `documents_status` — additive,
  doc 97's own `me.php` extension).
- Tapping the button launches `SubmitDocumentsActivity` directly (no
  result handling needed beyond the `onResume()` re-render above).

### `rider/.../ui/dashboard/RiderDashboardActivity.kt` (extended)
This was the confirmed approved-rider re-submission entry point (doc
97's own research: no Account/Profile hub exists in this app, so it
had to land on the header row rather than a nav-menu item). Added:
- A new `btnDocumentsAlert` `TextView` on the existing header row
  (between `dashboardGreeting` and `btnLogout` in
  `activity_rider_dashboard.xml`), styled with the new
  `doc_rejected_fg` color, **hidden unless `documentsStatus ==
  "rejected"`** — deliberately narrower than
  `ApplicationStatusActivity`'s button: an approved rider already
  passed the account-approval bar, so only a post-approval rejection
  actually needs their attention here, not "not yet verified" in
  general.
- `renderDocumentsEntryPoint()` called from `onResume()` (cache-only,
  same reasoning as the status screen) and from `refreshFromServer()`'s
  success branch (which now also calls
  `tokenManager.updateDocumentsStatus(result.rider.documentsStatus)`
  alongside its existing cache updates).
- Tapping it launches `SubmitDocumentsActivity` directly.

### `rider/AndroidManifest.xml`
New `<activity>` entry for `.ui.documents.SubmitDocumentsActivity`
(`exported="false"`, no special `windowSoftInputMode` — this screen has
no OTP-style keyboard-sensitive layout unlike `SignupActivity`/
`OtpVerifyActivity`/`RequestPayoutActivity`).

### `strings.xml` / `colors.xml`
- ~25 new `documents_*` strings (status labels, field hints, error/
  success messages) + reused `documents_button_*` labels shared between
  the status-screen button and (implicitly, via the same enum) the
  dashboard alert's rejected-state text.
- 4 new `doc_*_bg`/`doc_*_fg` color pairs — see the color file's own
  comment for why these are deliberately not aliases of the existing
  `status_*_bg`/`status_*_fg` pairs despite 3 of 4 values coinciding
  today (documents_status and riders.status are independent lifecycles,
  same reasoning migration 75's own header already gives on the
  backend side — the color system just carries that same independence
  through to the UI layer).

## Verification done this session (Android)

1. **Brace/paren/bracket balance** — wrote a fresh comment/string-aware
   Python checker (strips `//` line comments, `/* */` block comments,
   `"..."` strings with escape handling, `"""..."""` raw strings, and
   `'x'` char literals before counting delimiters — Kotlin's `'x'` char
   literals specifically needed their own state, since a naive
   quote-toggle approach would misparse a `'{'`-as-char-literal inside
   a string-handling branch). Run against all 5 touched/new Kotlin
   files this session (`ApiService.kt`, `TokenManager.kt`,
   `ApplicationStatusActivity.kt`, `RiderDashboardActivity.kt`,
   `SubmitDocumentsActivity.kt`). All `OK`.
2. **XML well-formedness** — `xmllint --noout` against all 6
   touched/new XML files (`activity_submit_documents.xml`,
   `activity_application_status.xml`, `activity_rider_dashboard.xml`,
   `strings.xml`, `colors.xml`, `AndroidManifest.xml`). All `OK`.
3. **Manual read-through** of `documents-upload.php`/
   `documents-get.php`/`documents-view.php` against what the new Kotlin
   actually sends/expects, to make sure the multipart field names
   (`id_doc`, `vehicle_doc`, `profile_photo`, `vehicle_type`,
   `vehicle_number`) and the JSON response shapes
   (`RiderDocumentsResult`/`RiderDocumentsUploadResult` field names)
   line up exactly — they do.

None of this substitutes for a real Gradle build, `php -l`, a live DB,
or a device click-through (still not available in this sandbox) — same
standing limitation as every prior handover in this project.

## Still open (carried over + new)

- **Everything doc 96 already listed** for Rider Payout Requests
  (migration 74 live run, `php -l`, live click-through, no rider
  notification surface) — untouched this session.
- **Migration 75 live run + `php -l`** on doc 97's 5 backend files —
  still outstanding, untouched this session (this session was Android-
  only).
- **Live click-through, now that Android exists end to end:** submit
  documents as a rider (with and without the optional fields) → confirm
  `documents_status` flips to `pending` on the admin list → admin's
  "View ID Doc"/"View Vehicle Doc" links actually render the uploaded
  file (not a 403/404) → Verify/Reject from the admin dialog → confirm
  the rider's own `SubmitDocumentsActivity` reload and
  `ApplicationStatusActivity`/`RiderDashboardActivity` buttons reflect
  the new status without a re-login.
- **A real Gradle build** — this session's Kotlin was only balance-
  checked, never compiled. The most likely first compile error, if
  any, would be around the multipart `RequestBody` extension imports
  (`okhttp3.RequestBody.Companion.toRequestBody`) — double-check that
  resolves against this project's actual OkHttp version if the first
  build fails there.
- **Known gap, unchanged:** no rider notification surface (deep-plan
  §23, still unbuilt) — a rider whose documents get rejected still has
  no in-app way to be told beyond opening the app and checking status
  manually (this session's dashboard alert only shows once they *do*
  open the app, it doesn't push anything).
- **Deferred, not attempted this session:** an actual image-preview
  experience for already-submitted documents (would need either a new
  image-loading dependency wired to send an Authorization header, or a
  hand-rolled `OkHttpClient` bitmap fetch) — text-state substitute is
  what's live today, see `SubmitDocumentsActivity`'s own kdoc for the
  reasoning.
- PENDING.md/recall.md haven't been touched this session (already
  noted as stale at the start of doc 97) — still worth a real re-audit
  pass, not done here either.
