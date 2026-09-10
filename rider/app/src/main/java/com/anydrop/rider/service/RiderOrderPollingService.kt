package com.anydrop.rider.service

import android.Manifest
import android.annotation.SuppressLint
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.location.Location
import android.os.IBinder
import androidx.core.content.ContextCompat
import com.anydrop.rider.data.TokenManager
import com.anydrop.rider.network.ApiClient
import com.anydrop.rider.network.LocationBody
import com.anydrop.rider.notifications.RiderNotificationHelper
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.android.gms.tasks.CancellationTokenSource
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.suspendCancellableCoroutine

/**
 * Root-cause fix for "rider app mein orders receive nahi ho rahe" (v24
 * bug report). Traced against the existing dispatch engine
 * (backend/lib/dispatch.php, rider/orders-available.php,
 * rider/orders-accept.php) end to end — that side is correct and
 * unchanged by this fix.
 *
 * The actual gap was Android-side only: RiderDashboardActivity's own
 * `dashboardPollRunnable`/`locationPollRunnable` are wired to
 * onResume()/onPause() (see that class's kdoc — "Foreground-only
 * polling while online... explicitly out of scope re: background
 * service" was a deliberate Phase 3 simplification that was never
 * revisited). The instant a rider backgrounds the app, locks the
 * screen, or switches to another app to wait — completely normal
 * behavior for a delivery rider between orders — `onPause()` cancels
 * both pollers immediately. No further calls to
 * `/rider/orders-available` happen until the rider manually reopens
 * this app, so a new offer can sit dispatched server-side (with its
 * own 40s `rider_assignment_timeout_seconds` expiry ticking down) with
 * nothing on the Android side ever checking for it. From the rider's
 * point of view this reads exactly as "order aata hi nahi" even though
 * the backend already created the offer correctly.
 *
 * This is the exact same class of bug the Restaurant app hit first
 * (see `restaurant/.../service/OrderPollingService.kt`'s own kdoc,
 * "real fix for alert should work even when the app is closed") and
 * the Customer app also already has its own equivalent
 * (`OrderUpdatePollingService`) — the Rider app was the one app in
 * this project that never got this treatment. This service is the
 * same fix, ported to the rider domain: a foreground `Service`,
 * independent of any Activity's lifecycle, that keeps polling
 * `/rider/orders-available` (new offer) and `/rider/orders-current`
 * (active-delivery status, in case it changed while backgrounded — an
 * admin cancel, for instance) as long as the rider is online, and
 * fires a real status-bar alert via [RiderNotificationHelper] the
 * moment a genuinely new offer shows up — not just an in-app card the
 * rider has to have the screen open to see.
 *
 * 2026-09-07 — Also now sends the periodic LOCATION ping (moved here
 * from HomeFragment, which used to run this itself via a
 * Handler/Runnable tied to onResume()/onPause() — see that class's own
 * kdoc). Exactly the same class of gap the order-offer polling fix
 * above already closed, just for location: a rider who locks their
 * screen or switches apps mid-delivery would stop updating their live
 * position on the admin map / customer tracking screen the instant
 * HomeFragment's onPause() fired, even though nothing about an actual
 * delivery in progress should ever stop needing a fresh location.
 * Same adaptive cadence as before (30s idle / 7s while an active
 * delivery is in progress — see LOCATION_POLL_INTERVAL_MS /
 * LOCATION_POLL_INTERVAL_ACTIVE_MS below, unchanged values, just
 * relocated), same `FusedLocationProviderClient.getCurrentLocation()`
 * call, same `POST /rider/location` request shape. Runs as a SEPARATE
 * coroutine loop/job from `pollForOffer()`'s (different cadence, 15s
 * fixed vs 30s/7s adaptive — merging them would mean either polling
 * orders too slowly or pinging location too often), but both loops
 * share this service's single `hasActiveOrder`/`activeOrderIdForLocation`
 * fields rather than each independently calling `/rider/orders-current`
 * on their own schedule — `pollForOffer()`'s existing call already
 * tells us this every 15s, which is frequent enough for the location
 * loop's own adaptive-interval decision to just read rather than
 * re-fetch.
 *
 * HomeFragment keeps ONLY its one-shot `sendLocationThenGoOnline()`
 * ping (needed synchronously before the "go online" API call), not an
 * ongoing loop of its own anymore — running both this service's loop
 * AND a duplicate foreground-only one in the fragment at the same time
 * would just double the GPS reads/API calls with zero freshness
 * benefit, unlike order-offer polling where a faster in-focus cadence
 * genuinely improves the UX of seeing a new offer appear.
 *
 * `startForeground()` reduces (does not guarantee — see the restaurant
 * service's own kdoc for the same OEM battery-management caveat,
 * unchanged here) the odds Android kills this process while
 * backgrounded. `RiderDashboardActivity` keeps its own foreground-only
 * pollers exactly as they were — this service is additive, not a
 * replacement, since a visible, focused dashboard should still update
 * at its faster in-app cadence; this service only needs to cover the
 * gap when nothing else is watching.
 *
 * Start/stop lifecycle (mirrors OrderPollingService.start()/stop()
 * being called from the restaurant app's MainActivity/AccountFragment):
 * - Started whenever the rider is (or goes) online —
 *   RiderDashboardActivity.setOnlineStatus() on a successful "went
 *   online" response, and refreshFromServer() on load if /rider/me
 *   already reports is_online=true (covers "app reopened while still
 *   online from a previous session").
 * - Stopped when the rider goes offline (setOnlineStatus()'s "went
 *   offline" branch) and on logout — an offline or logged-out rider
 *   has no business still polling for someone else's delivery offers,
 *   or still sending location pings nobody has any use for.
 */
class RiderOrderPollingService : Service() {

    private var job: Job? = null
    private var locationJob: Job? = null
    private val scope = CoroutineScope(Dispatchers.IO + Job())
    private lateinit var prefs: android.content.SharedPreferences
    private val fusedLocationClient by lazy { LocationServices.getFusedLocationProviderClient(applicationContext) }

    // Set by pollForOffer()'s own /rider/orders-current call (already
    // running every POLL_INTERVAL_MS) and read by the location loop to
    // pick its adaptive interval + populate LocationBody.orderId —
    // deliberately NOT a second independent /rider/orders-current call
    // on the location loop's own faster cadence, which would just be
    // redundant load for information the order-poll loop already has.
    @Volatile private var activeOrderIdForLocation: Int? = null

    override fun onCreate() {
        super.onCreate()
        RiderNotificationHelper.ensureChannels(applicationContext)
        prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        startForeground(
            RiderNotificationHelper.MONITORING_NOTIFICATION_ID,
            RiderNotificationHelper.buildMonitoringNotification(applicationContext)
        )

        // onStartCommand can fire more than once (e.g. every
        // RiderDashboardActivity.onCreate()/setOnlineStatus() success
        // while already online calling start() again) — don't stack a
        // second poll loop on top of an already-running one.
        if (job?.isActive != true) {
            job = scope.launch {
                while (true) {
                    val tokenManager = TokenManager(applicationContext)
                    if (!tokenManager.isLoggedIn() || !tokenManager.getIsOnline()) {
                        stopSelf()
                        return@launch
                    }
                    pollForOffer()
                    delay(POLL_INTERVAL_MS)
                }
            }
        }

        // Separate loop, separate cadence (30s idle / 7s active vs the
        // order-poll loop's fixed 15s) — see this class's own kdoc for
        // why these aren't merged into one loop.
        if (locationJob?.isActive != true) {
            locationJob = scope.launch {
                while (true) {
                    val tokenManager = TokenManager(applicationContext)
                    if (!tokenManager.isLoggedIn() || !tokenManager.getIsOnline()) {
                        return@launch // job above already calls stopSelf() in this case
                    }
                    sendLocationPing()
                    val interval = if (activeOrderIdForLocation != null) LOCATION_POLL_INTERVAL_ACTIVE_MS else LOCATION_POLL_INTERVAL_MS
                    delay(interval)
                }
            }
        }

        // START_STICKY — same reasoning as the restaurant app's
        // OrderPollingService: if the OS kills this process under
        // memory pressure, ask it to come back once resources free up
        // rather than staying gone until the rider next opens the app.
        return START_STICKY
    }

    /** Same two-step "current delivery wins, else check for an offer"
     * shape as RiderDashboardActivity.pollDashboardState() — an active
     * delivery already has its own dedicated screen/notifications from
     * accept/pickup/deliver, so this only needs to alert on a genuinely
     * new *offer* the rider hasn't seen yet, tracked by assignment_id
     * (not order_id — a rejected/expired offer on the same order gets a
     * new assignment_id on re-dispatch, and that IS a new offer worth
     * alerting on again). */
    private suspend fun pollForOffer() {
        try {
            val api = ApiClient.create(applicationContext)

            // An active delivery means no offer will ever be returned by
            // orders-available anyway (dispatch.php's own eligibility
            // query excludes a rider with one) — skip the extra call.
            val currentResponse = api.getCurrentOrder()
            val currentOrder = if (currentResponse.isSuccessful) currentResponse.body()?.data?.order else null
            // Shared with the location loop — see this field's own kdoc
            // above for why that loop doesn't make its own separate call.
            activeOrderIdForLocation = currentOrder?.id
            if (currentOrder != null) {
                return
            }

            val offerResponse = api.getAvailableOffer()
            val offer = if (offerResponse.isSuccessful) offerResponse.body()?.data?.offer else null

            val lastAlertedId = prefs.getInt(KEY_LAST_ALERTED_ASSIGNMENT_ID, -1)
            if (offer != null && offer.assignmentId != lastAlertedId) {
                RiderNotificationHelper.showOrderNotification(
                    applicationContext,
                    "New delivery available",
                    "Order ${offer.orderCode} is ready for pickup",
                    "order_offer"
                )
                prefs.edit().putInt(KEY_LAST_ALERTED_ASSIGNMENT_ID, offer.assignmentId).apply()
            } else if (offer == null) {
                // No open offer right now — reset the baseline so the
                // *next* offer (even if it happens to reuse an id, which
                // can't actually happen with an autoincrement column, but
                // keeps this resilient regardless) always alerts.
                prefs.edit().putInt(KEY_LAST_ALERTED_ASSIGNMENT_ID, -1).apply()
            }
        } catch (e: Exception) {
            // Transient network error — next poll cycle tries again,
            // same silent-retry stance every other poller in this
            // codebase takes.
        }
    }

    override fun onBind(intent: Intent?): IBinder? = null

    /** Same shape as HomeFragment's former `sendLocationPing()`/
     *  `sendLocationPingInternal()` (now removed from that class — see
     *  its own kdoc) — permission check, then
     *  `FusedLocationProviderClient.getCurrentLocation()`, then
     *  `POST /rider/location`. The only real difference: this runs in a
     *  suspend function inside this service's own coroutine loop rather
     *  than a Fragment's `lifecycleScope`, so the callback-based
     *  Play Services `Task` is bridged into a suspend call via
     *  `suspendCancellableCoroutine` (this module has no
     *  kotlinx-coroutines-play-services dependency for `.await()`,
     *  and adding one for this alone isn't worth it). */
    @SuppressLint("MissingPermission")
    private suspend fun sendLocationPing() {
        if (ContextCompat.checkSelfPermission(applicationContext, Manifest.permission.ACCESS_FINE_LOCATION) !=
            PackageManager.PERMISSION_GRANTED
        ) {
            return // rider went online before this was granted somehow — next tick tries again
        }

        try {
            val location = getCurrentLocationOrNull() ?: return
            val speedKmh = if (location.hasSpeed()) (location.speed * 3.6).toDouble() else null
            val api = ApiClient.create(applicationContext)
            api.updateLocation(
                LocationBody(location.latitude, location.longitude, activeOrderIdForLocation, speedKmh)
            )
        } catch (e: Exception) {
            // Transient network/location error — next poll cycle tries again.
        }
    }

    /** Bridges FusedLocationProviderClient's callback-based Task into a
     *  suspend call. Cancelling the coroutine (e.g. this service's scope
     *  being cancelled in onDestroy()) cancels the underlying location
     *  request too, rather than leaving it running with nothing waiting
     *  on the result. */
    private suspend fun getCurrentLocationOrNull(): Location? = suspendCancellableCoroutine { cont ->
        val cancellationSource = CancellationTokenSource()
        cont.invokeOnCancellation { cancellationSource.cancel() }
        fusedLocationClient.getCurrentLocation(Priority.PRIORITY_BALANCED_POWER_ACCURACY, cancellationSource.token)
            .addOnSuccessListener { location -> if (cont.isActive) cont.resumeWith(Result.success(location)) }
            .addOnFailureListener { if (cont.isActive) cont.resumeWith(Result.success(null)) }
    }

    override fun onDestroy() {
        super.onDestroy()
        scope.cancel() // cancels both job and locationJob — same shared scope
    }

    companion object {
        private const val POLL_INTERVAL_MS = 15_000L
        private const val LOCATION_POLL_INTERVAL_MS = 30_000L
        private const val LOCATION_POLL_INTERVAL_ACTIVE_MS = 7_000L
        private const val PREFS_NAME = "anydrop_rider_order_polling"
        private const val KEY_LAST_ALERTED_ASSIGNMENT_ID = "last_alerted_assignment_id"

        /** Idempotent — safe to call whenever the rider is known to be
         * online, whether or not the service is already running. */
        fun start(context: Context) {
            val intent = Intent(context, RiderOrderPollingService::class.java)
            ContextCompat.startForegroundService(context, intent)
        }

        fun stop(context: Context) {
            context.stopService(Intent(context, RiderOrderPollingService::class.java))
        }
    }
}
