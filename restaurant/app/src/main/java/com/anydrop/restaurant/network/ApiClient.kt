package com.anydrop.restaurant.network

import android.content.Context
import com.google.gson.GsonBuilder
import com.google.gson.reflect.TypeToken
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import com.anydrop.restaurant.data.TokenManager

/**
 * Single Retrofit instance for the Restaurant app.
 *
 * BASE_URL points at the same backend as the Customer app (see docs/Status.md).
 * Only this constant needs to change when the backend moves to InfinityFree.
 */
object ApiClient {

    private const val BASE_URL = "http://localhost:8080/anydrop/api/v1/"

    /** Static-file root (".../anydrop/") for turning logo-upload.php's
     * relative logo_url into a loadable image URL — same helper/reasoning
     * as the Customer app's ApiClient.baseUrlForStaticFiles() (H6 pin-drop
     * photo). Kept here rather than hardcoding the swap at each call site
     * so BASE_URL only needs to change in one place. */
    fun baseUrlForStaticFiles(context: Context): String = BASE_URL.removeSuffix("api/v1/")

    private val gson = GsonBuilder()
        .registerTypeAdapter(Boolean::class.javaObjectType, LenientBooleanTypeAdapter)
        .registerTypeAdapter(Boolean::class.javaPrimitiveType, LenientBooleanTypeAdapter)
        .create()
    private val envelopeType = object : TypeToken<Map<String, Any?>>() {}.type

    fun create(context: Context): ApiService {
        val tokenManager = TokenManager(context)

        val authInterceptor = Interceptor { chain ->
            val original = chain.request()
            val token = tokenManager.getToken()
            val request = if (!token.isNullOrEmpty()) {
                original.newBuilder()
                    .addHeader("Authorization", "Bearer $token")
                    .build()
            } else {
                original
            }
            chain.proceed(request)
        }

        // Doc 26 — backend/lib/auth.php's require_auth() now returns
        // 403 `account_suspended` from *any* authenticated endpoint the
        // moment an admin suspends this restaurant, not just at login.
        // Identical to the Customer app's ApiClient interceptor of the
        // same name — see that file's kdoc for the peekBody()/try-catch
        // reasoning. On match: drops the now-invalid token and notifies
        // SessionEvents; MainActivity does the actual navigate-to-Login
        // + show-reason.
        val suspensionInterceptor = Interceptor { chain ->
            val response = chain.proceed(chain.request())
            if (response.code == 403) {
                try {
                    val bodyString = response.peekBody(2048).string()
                    @Suppress("UNCHECKED_CAST")
                    val envelope = gson.fromJson<Map<String, Any?>>(bodyString, envelopeType)
                    if (envelope?.get("error") == "account_suspended") {
                        @Suppress("UNCHECKED_CAST")
                        val data = envelope["data"] as? Map<String, Any?>
                        val reason = data?.get("reason") as? String
                        tokenManager.clear()
                        SessionEvents.emitAccountSuspended(reason)
                    }
                } catch (e: Exception) {
                    // Malformed/unreadable body — fall through and let
                    // the caller handle the plain 403 as before; this
                    // interceptor only ever adds behavior, never blocks
                    // the response on a parse failure.
                }
            }
            response
        }

        val logging = HttpLoggingInterceptor().apply {
            level = HttpLoggingInterceptor.Level.BASIC
        }

        val client = OkHttpClient.Builder()
            .addInterceptor(authInterceptor)
            .addInterceptor(suspensionInterceptor)
            .addInterceptor(logging)
            .build()

        return Retrofit.Builder()
            .baseUrl(BASE_URL)
            .client(client)
            .addConverterFactory(GsonConverterFactory.create(gson))
            .build()
            .create(ApiService::class.java)
    }
}
