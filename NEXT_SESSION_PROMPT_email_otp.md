# NEXT SESSION PROMPT — Email OTP Multi-Provider (2026-08-30)

Anydrop project zip attached (or: this `anydrop_email_otp_multiprovider.zip`
overlaid onto the existing repo). This session built the backend half
of `AnyDrop_Email_OTP_MultiProvider_Plan.md` / docs/19 §7.

Status: 🟡 BUILT, NOT DB/device-verified — no PHP CLI or live MySQL in
this sandbox (same standing limitation as every other session in this
repo). Needs a real deploy + click-through before it's trusted.

## What exists now

- `backend/sql/67_migration_email_otp_providers.sql` — creates
  `email_otp_providers` + `email_otp_logs`, seeds all 6 providers
  (Resend → Brevo → MailerSend → Sendix → Maileroo → Mailjet,
  priorities 1–6). All start `is_active = 0` — nothing fires until an
  admin pastes in a real key and flips it on.
- `backend/lib/email_otp/` — full provider-interface architecture:
  `EmailProviderInterface`, `ProviderResult`, `SecretManager`
  (AES-256-GCM encryption of API keys using `APP_SECRET`),
  `ProviderHttpClient` (shared cURL + error-classification), 6 driver
  classes, `ProviderRegistry` (quota-aware active-provider loading),
  `EmailOtpService` (the orchestrator — failover, logging, quota
  counters).
- `backend/api/v1/auth/customer-request-otp.php` and
  `restaurant-request-otp.php` — now call `EmailOtpService::send()`
  instead of always claiming "OTP sent". `debug_otp_enabled` app_setting
  still works exactly as before for dev/staging testing.
- `backend/admin/email-providers.php` — Admin Panel screen: per-provider
  API key fields (masked after save, blank = keep existing), sender
  email/name, priority, daily/monthly quota, Active toggle, Test Send
  button (real send, logged separately as `purpose='admin_test'`,
  doesn't consume quota), usage bars, last-25 failed-delivery log.
- `backend/admin/NAV_PATCH_INSTRUCTIONS.md` — the 2 small edits still
  needed in the existing (not overwritten) `admin/_layout_head.php` to
  add the "Email Providers" nav link under Finance.

## Continue from here — in order

1. **Apply the nav patch.** Open `admin/_layout_head.php`, make the two
   edits in `NAV_PATCH_INSTRUCTIONS.md`. Two lines, low risk.
2. **Run migration 67** on the dev DB. Confirm the 6 seeded rows exist
   in `email_otp_providers`.
3. **Real deploy verification (the actual blocker):**
   - Log into Admin Panel → Email Providers, paste a real Resend (or
     any one provider) API key + sender email, save, hit Test — confirm
     an email actually lands.
   - Flip that provider Active, hit `customer-request-otp` from the
     Customer app (or curl), confirm the OTP email arrives and
     `email_otp_logs` gets a `status='sent'` row.
   - Deliberately break it (wrong key) and confirm: (a) it logs
     `status='failed'`, (b) with only one provider configured and it
     failing, the endpoint returns `email_delivery_unavailable` (503)
     rather than "OTP sent" — this is the one behavior most worth
     re-checking by hand, since it's the whole point of plan §3.
   - Turn on 2 providers, break the first on purpose (bad key), confirm
     failover to the second actually happens and both attempts show up
     in the log with `attempt_number` 1 and 2.
4. **SecretManager note for whoever deploys:** `APP_SECRET` in
   `config/config.php` is still the placeholder
   (`anydrop_local_dev_secret_change_later`) — change it to a real
   random value before going anywhere near production, and note that
   changing it *after* provider keys are saved makes those keys
   undecryptable (re-enter them from the Admin Panel if that happens).
5. **Not done yet, out of scope for this session:** `email_change` and
   `password_reset` purposes aren't wired to any endpoint (no such
   endpoints exist yet in this repo) — `EmailOtpService::send()`
   already accepts any purpose string, so wiring those in later is a
   one-line change wherever those flows get built, no service changes
   needed.

## Also from this session, unrelated to email but flagged for the owner

The repo zip had **no `.gitignore`**, and `backend/config/
firebase-service-account.json` was sitting untracked-ignore inside it —
almost certainly how the Firebase key leaked to GitHub. The owner was
told to `git rm --cached` it, add a `.gitignore` covering
`backend/config/firebase-service-account.json` and `backend/config/
config.php`, and to treat the old key as burned regardless (already
rotated a new one before this session). Worth confirming that actually
got done before the next push.
