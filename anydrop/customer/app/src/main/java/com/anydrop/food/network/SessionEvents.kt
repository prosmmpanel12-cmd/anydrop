package com.anydrop.food.network

import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.asSharedFlow

/**
 * Doc 25 — fires when any authenticated API response comes back
 * `403 account_suspended` (backend/lib/auth.php's `require_auth()` now
 * re-checks `customers.is_active` on every request, not just at login,
 * so this can happen from literally any authenticated call once an
 * admin suspends a customer who's already logged in — see the handover
 * for why that mid-session gap existed before).
 *
 * `ApiClient`'s suspension interceptor emits here; `HomeActivity` (the
 * one long-lived Activity every screen in this app funnels through —
 * see its own `loadPromoBanners()`/`onResume()` kdoc for the same
 * "Home outlives everything else" reasoning) observes it once in
 * `onCreate()` and force-navigates to Login. No existing event-bus
 * pattern in this app to reuse, and every `ApiClient.create()` caller
 * already has a `Context`, so a static `SharedFlow` is the smallest
 * addition that reaches every screen without threading a new
 * dependency through the whole Activity graph.
 */
object SessionEvents {
    private val _accountSuspended = MutableSharedFlow<String?>(extraBufferCapacity = 1)
    val accountSuspended: SharedFlow<String?> = _accountSuspended.asSharedFlow()

    /**
     * [reason] is whatever the backend's `data.reason` held, or null if
     * none was set. Plain `tryEmit` (not `emit`) on purpose — this is
     * called from inside an OkHttp interceptor, which runs on an OkHttp
     * dispatcher thread, not a coroutine; `extraBufferCapacity = 1`
     * above means `tryEmit` always succeeds here (worst case: a second
     * suspension notice in rapid succession overwrites the first
     * buffered one, which is fine — the app only cares that it's
     * suspended, not how many suspended responses arrived).
     */
    fun emitAccountSuspended(reason: String?) {
        _accountSuspended.tryEmit(reason)
    }
}
