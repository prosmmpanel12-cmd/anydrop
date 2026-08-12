package com.anydrop.food.network

import android.content.Context
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

        val logging = HttpLoggingInterceptor().apply {
            level = HttpLoggingInterceptor.Level.BASIC
        }

        val client = OkHttpClient.Builder()
            .addInterceptor(authInterceptor)
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
