# Anydrop Restaurant App

Phase 3 — order management for restaurant partners. Login → Dashboard (New / Active / History tabs, polls every 10s) → Order Detail (Accept / Reject / Mark Preparing / Mark Ready).

Same backend as the Customer app (`docs/02_API_Contract.md` §6 "Restaurant" endpoints). Update `BASE_URL` in `app/src/main/java/com/anydrop/restaurant/network/ApiClient.kt` to point at your backend, same as the customer app.

Not yet built (Phase 4+): rider assignment, live map, push notifications for new orders (currently polling only), earnings/payout history beyond today's summary.
