# Rider App — Phase 1 wrap-up: username/password_hash Cleanup + Email Template Check
Session date: 01 Sep 2026 (follow-on to docs 79/80/81 in the same day's work)

> **⚠️ Not built/tested this pass.** Same standing sandbox limitation
> as docs 79/80/81: no PHP interpreter, no MySQL, no network access
> here. Per `done.md`'s rule, treat migration 71 and the
> `rider-signup.php` change as `🟡 IMPLEMENTED — TEST PENDING` until
> run/hit against a live DB.

This closes out the two remaining items from doc 79's "Known gaps /
next-session TODO" list (items 2 and 5) — items 1, 3, 4 were already
closed by docs 80/81 earlier in this same day's work.

## ✅ Item 2 — `riders.username`/`password_hash` made nullable

**Doc 79's note:** *"`username`/`password_hash` (legacy, NOT NULL
columns from the old restaurant-created-rider path) are filled with
random placeholders — a platform rider never actually uses them, but
leaving them NULL would risk breaking any existing restaurant-side
rider-management screen that still reads `username`. Flagging this as
a follow-up cleanup candidate."*

**Audit performed this session** (grepped the entire `backend/` tree):
- No admin page (`admin/*.php`) reads `riders.username` or
  `riders.password_hash`.
- No restaurant-facing API endpoint reads, writes, or creates a riders
  row via username/password — the restaurant-scoped rider model
  described in `01_schema.sql`'s own column comments has **no actual
  API surface built for it anywhere in this codebase**. It's
  schema-only, consistent with doc 79's own "found during audit, not
  built this session" note about the `riders` table.
- The only `INSERT INTO riders` anywhere in the codebase is
  `rider-signup.php` itself.

Confirmed safe to relax the constraint. Built:

### `backend/sql/71_migration_riders_username_password_nullable.sql`
`ALTER TABLE riders MODIFY COLUMN username ... NULL` /
`password_hash ... NULL`. Deliberately **not dropping** the columns —
a live DB may already have real restaurant-created rider rows with
real values; this only relaxes the constraint for future inserts,
leaving any existing data completely untouched. The existing UNIQUE
index on `username` is unaffected (MySQL allows multiple NULLs under
UNIQUE, same reasoning migration 69 already used for `riders.email`).

### `backend/api/v1/auth/rider-signup.php` (modified again)
Removed the `$placeholderUsername`/`$placeholderPasswordHash`
generation entirely — the INSERT now passes `NULL` for both columns
directly. Simpler code, one less place doing `random_bytes()` for
values nothing ever reads. Doc comment updated to note this file now
**requires migration 71** to be run first (deploying this version
without it will fail every signup with a NOT NULL violation, since
the old placeholder workaround is gone).

## ✅ Item 5 — EmailOtpService `'rider_auth'` purpose string check

**Doc 79's note:** *"check whether the email template/provider config
needs anything purpose-specific added on the Admin → Email Providers
screen, or whether it falls through to a generic template fine
as-is."*

**Checked this session** (read `lib/email_otp/EmailOtpService.php` and
`admin/email-providers.php` in full):
- `EmailOtpService::send()`'s `$purpose` parameter is used **only**
  for the `email_otp_logs.purpose` column — a plain audit/filter
  label. It has zero effect on the subject line or the HTML/text body
  (`buildEmail()` doesn't even receive `$purpose` as a parameter) and
  zero effect on which provider is selected.
- `admin/email-providers.php`'s Recent Attempts log table just
  displays whatever string is in the `purpose` column
  (`<?= admin_escape($log['purpose']) ?>`) — no per-purpose branching,
  filtering config, or template mapping exists anywhere on that page.

**Conclusion: no gap, nothing to build.** `'rider_auth'` will send the
exact same generic "AnyDrop confirmation code" email every other
purpose already sends, and will show up in the admin log table
correctly labelled with zero extra configuration. This item is closed
by inspection — verified, not just assumed.

## Doc 79's next-session TODO — final status

| # | Item | Status |
|---|------|--------|
| 1 | Run migration 69 + smoke-test 4 endpoints | 🔴 Still needs a live server — not Claude-actionable in this sandbox |
| 2 | username/password_hash nullable cleanup | ✅ Built this session (migration 71) |
| 3 | Rate-limit/abuse guard on rider-signup.php | ✅ Built earlier this session (doc 81, migration 70) |
| 4 | Admin Riders approval queue screen | ✅ Built earlier this session (doc 80) |
| 5 | EmailOtpService `rider_auth` purpose check | ✅ Verified this session — no gap existed |

**Every Claude-actionable item from doc 79 is now closed.** The only
remaining Phase 1 work is item 1 — running migrations 69/70/71 against
a real database and smoke-testing the 4 auth endpoints + the new
Riders admin page — which needs the app owner's live server, not more
code. Once that's confirmed working, Phase 1 is genuinely done per
`done.md`'s rule and Phase 2 (the actual Android Rider App) can start.

## Files in this delivery

```
backend/sql/71_migration_riders_username_password_nullable.sql   (new)
backend/api/v1/auth/rider-signup.php                              (modified — placeholder generation removed, doc comment updated)
```

No file changes for item 5 — verification only, no gap found.

## Full Phase 1 deployment order (all sessions combined, docs 79–82)

Run migrations in this exact order, then deploy the PHP files:

```
backend/sql/69_migration_rider_self_signup.sql
backend/sql/70_migration_signup_rate_limit.sql
backend/sql/71_migration_riders_username_password_nullable.sql

backend/api/v1/auth/rider-request-otp.php
backend/api/v1/auth/rider-verify-otp.php
backend/api/v1/auth/rider-signup.php          (latest version — from this doc, supersedes doc 79/81's versions)
backend/api/v1/system/service-areas.php
backend/lib/auth.php                           (modified — require_auth() rider branch)
backend/lib/rate_limit.php                     (new)
backend/admin/riders.php                       (new)
backend/admin/_layout_head.php                 (modified — nav item)
```
