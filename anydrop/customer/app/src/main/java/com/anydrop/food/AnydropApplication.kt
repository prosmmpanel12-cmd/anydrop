package com.anydrop.food

import android.app.Application
import androidx.lifecycle.DefaultLifecycleObserver
import androidx.lifecycle.LifecycleOwner
import androidx.lifecycle.ProcessLifecycleOwner
import com.anydrop.food.notifications.CartAbandonmentScheduler

/**
 * Phase J cart-abandonment — the app previously had no `Application`
 * subclass at all (every lifecycle-sensitive thing lived on individual
 * Activities). `onStop` here is `ProcessLifecycleOwner`'s "the whole app,
 * every Activity, just went to the background" signal — deliberately not
 * any single Activity's `onPause`/`onStop`, which also fires on ordinary
 * screen-to-screen navigation within the app and would false-trigger
 * constantly. See `CartAbandonmentScheduler`'s kdoc for what happens next.
 */
class AnydropApplication : Application() {

    override fun onCreate() {
        super.onCreate()
        ProcessLifecycleOwner.get().lifecycle.addObserver(object : DefaultLifecycleObserver {
            override fun onStop(owner: LifecycleOwner) {
                CartAbandonmentScheduler.onAppBackgrounded(this@AnydropApplication)
            }

            override fun onStart(owner: LifecycleOwner) {
                // Phase J — coming back to the foreground with the cart
                // still non-empty isn't "abandonment" anymore (the whole
                // point was catching *background* neglect), so cancel any
                // pending 15-minute timer the moment the app is reopened.
                // If the cart's still sitting there unopened next time the
                // app backgrounds, onStop above schedules a fresh one.
                CartAbandonmentScheduler.cancel(this@AnydropApplication)
            }
        })
    }
}
