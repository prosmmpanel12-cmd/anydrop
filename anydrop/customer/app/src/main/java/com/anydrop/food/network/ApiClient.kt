package com.anydrop.food.network

import android.content.Context
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import com.anydrop.food.data.TokenManager

/**
 * Single Retrofit instance for the Customer app.
 *
 * BASE_URL points at the Phase 1 backend running locally via KS Web on-device
 * (see docs/Status.md). Only this constant needs to change when the backend
 * moves to InfinityFree — nothing else in the app depends on the host.
 */
object ApiClient {

    private const val BASE_URL = "http://localhost:8080/anydrop/api/v1/"

    /** Root for static/uploaded files served directly by the backend host
     * (not through api/v1/), e.g. address-photo.php's returned
     * "uploads/address_photos/..." relative paths — see that endpoint's
     * kdoc and MapPinDropActivity's absoluteUrl(). Derived by stripping
     * "api/v1/" off BASE_URL rather than hardcoding the anydrop/ root a
     * second time, so this and BASE_URL only ever need updating in one
     * place when the backend host changes (KS Web → InfinityFree). */
    fun baseUrlForStaticFiles(context: Context): String = BASE_URL.removeSuffix("api/v1/")

    private val gson = Gson()
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

        // Doc 25 — backend/lib/auth.php's require_auth() now returns
        // 403 `account_suspended` from *any* authenticated endpoint the
        // moment an admin suspends this customer, not just at login.
        // Catches it here once, globally, instead of per-screen: peeks
        // the response body (peekBody, not body()/string() — those
        // would consume the stream Retrofit still needs to read) so
        // this is purely observational and never changes what the
        // calling code sees. On match: drops the now-invalid token and
        // notifies SessionEvents; HomeActivity does the actual
        // navigate-to-Login + show-reason.
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
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(ApiService::class.java)
    }
}
