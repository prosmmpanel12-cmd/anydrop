package com.anydrop.food.ui.common

import androidx.fragment.app.FragmentActivity
import com.anydrop.food.BuildConfig
import com.anydrop.food.network.ApiClient
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.MainScope
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * Hits GET /system/app-version.php at startup and shows an in-app popup
 * if the installed build is outdated. Forced (non-dismissible) when below
 * min_version_code, optional ("Later") when merely below latest_version_code.
 *
 * Splash is kept on screen for a minimum duration regardless of how fast
 * the network call returns, so the branded splash always gets a proper
 * moment on screen instead of flashing by instantly.
 */
object UpdateChecker {

    private const val MIN_SPLASH_MILLIS = 7500L

    fun check(activity: FragmentActivity, onDone: () -> Unit) {
        val scope = MainScope()
        scope.launch {
            val minDelay = async { delay(MIN_SPLASH_MILLIS) }

            val info = try {
                val api = ApiClient.create(activity)
                withContext(Dispatchers.IO) { api.getAppVersion("customer") }.body()?.data
            } catch (e: Exception) {
                // Backend unreachable (e.g. local KS Web server not running) —
                // don't block the app on a version check failure.
                null
            }

            val current = BuildConfig.VERSION_CODE
            when {
                info == null -> {
                    minDelay.await()
                    onDone()
                }
                current < info.minVersionCode -> {
                    minDelay.await()
                    UpdateDialogFragment.newInstance(info, forced = true)
                        .show(activity.supportFragmentManager, "update_forced")
                    // Forced update: do not call onDone() — app should not proceed.
                }
                current < info.latestVersionCode -> {
                    minDelay.await()
                    val dialog = UpdateDialogFragment.newInstance(info, forced = false)
                    dialog.onLater = { onDone() }
                    dialog.show(activity.supportFragmentManager, "update_optional")
                }
                else -> {
                    minDelay.await()
                    onDone()
                }
            }
        }
    }
}
