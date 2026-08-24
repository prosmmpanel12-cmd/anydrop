package com.anydrop.restaurant.network

import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.asSharedFlow

/**
 * Doc 25 — fires when any authenticated API response comes back
 * `403 account_suspended` (backend/lib/auth.php's `require_auth()` now
 * re-checks `restaurants.status` on every request, not just at login,
 * so this can happen from literally any authenticated call once an
 * admin suspends a restaurant that's already logged in).
 *
 * `ApiClient`'s suspension interceptor emits here; `MainActivity` (the
 * one long-lived Activity every screen in this app funnels through)
 * observes it once in `onCreate()` and force-navigates to Login. Same
 * static-`SharedFlow` approach as the Customer app's `SessionEvents` —
 * see that file's kdoc for why (no existing event-bus pattern in
 * either app, every `ApiClient.create()` caller already has a
 * `Context`).
 */
object SessionEvents {
    private val _accountSuspended = MutableSharedFlow<String?>(extraBufferCapacity = 1)
    val accountSuspended: SharedFlow<String?> = _accountSuspended.asSharedFlow()

    /**
     * [reason] is whatever the backend's `data.reason` held, or null if
     * none was set. Plain `tryEmit` (not `emit`) on purpose — see the
     * Customer app's `SessionEvents.emitAccountSuspended` kdoc; same
     * reasoning applies here (called from an OkHttp interceptor thread,
     * not a coroutine).
     */
    fun emitAccountSuspended(reason: String?) {
        _accountSuspended.tryEmit(reason)
    }
}
