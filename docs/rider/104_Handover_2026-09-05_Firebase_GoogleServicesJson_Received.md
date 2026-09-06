# Handover — google-services.json for com.anydrop.rider received and placed (Firebase blocker from docs 99-103 now closed)

Session date: 05 Sep 2026 (continues doc 103, same day). Owner
supplied the downloaded Firebase config file after registering
`com.anydrop.rider` in the Firebase console.

## What was verified before placing the file

- Parsed the uploaded JSON and confirmed it contains three
  `client` entries — `com.anydrop.food`, `com.anydrop.restaurant`,
  and `com.anydrop.rider` — each with its own `mobilesdk_app_id`
  under the same `project_info` (`anydrop-2d917`, project number
  `681580896631`) the customer/restaurant apps already use. This is
  the standard shape for a multi-app Firebase project: one shared
  config file, the Gradle plugin picks the matching client entry by
  `applicationId` at build time.
- Diffed the uploaded file byte-for-byte against
  `customer/app/google-services.json` — identical except for the
  added rider client entry, confirming this is the same project
  re-downloaded after registering the rider app, not a different
  project or a stale/mismatched file.
- Confirmed (re-read, not assumed) that `rider/build.gradle` and
  `rider/app/build.gradle` already had the `com.google.gms.google-
  services` plugin applied and `firebase-messaging-ktx` as a
  dependency (doc 101's additions) — so no Gradle wiring was needed,
  only the file itself.

## What was done this session

- Placed the file at `rider/app/google-services.json` — same path
  and same file the customer/restaurant modules already use their own
  copies from.
- Updated the now-stale "this file does not exist yet" comments in
  `rider/build.gradle` and `rider/app/build.gradle` to reflect that
  the blocker is closed.

## Still open — this closes the Firebase blocker, not the whole feature

- **Nothing has been build/device-verified.** This sandbox has no
  Gradle, no Android SDK, no real device — the file being in place
  means a real build *can* succeed now, not that it *has*. First real
  build should specifically check: `rider/app/build.gradle`'s
  `applicationId` matches `com.anydrop.rider` exactly (a mismatch
  here is the most common way this plugin fails even with a seemingly
  correct file in place), then a full FCM round-trip end to end (see
  doc 103's "Still open" — log in, confirm token round-trips to
  `rider/fcm-token-update.php`, trigger a `create_notification('rider',
  ...)` from the admin panel, confirm both the system-tray push and
  the in-app bell/badge).
- Everything else docs 99-103 already carried over unchanged: `php -l`
  never run, migrations 75/76 never run, Order category (deep-plan
  §23) still entirely unbuilt, PENDING.md/recall.md still not
  re-audited (flagged stale since doc 97).

## Next step

A real build/device test of the Rider app is now unblocked and is the
natural next step before adding anything further to this notifications
slice. Otherwise, owner's choice of Rider Documents/Payouts follow-ups
or deep-plan §23's Order category, same options doc 103 left open.
