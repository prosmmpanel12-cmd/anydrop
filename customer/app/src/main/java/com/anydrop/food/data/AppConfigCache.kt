package com.anydrop.food.data

import com.anydrop.food.network.SplashConfig

/**
 * Holds the splash-config response (banner image + legal page URLs) for the
 * lifetime of the process, so the Login screen can reuse it instantly
 * instead of re-fetching. Populated once by SplashActivity at startup.
 */
object AppConfigCache {
    var splashConfig: SplashConfig? = null
}
