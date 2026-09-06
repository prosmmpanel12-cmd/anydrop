package com.anydrop.rider.network

import android.content.Context
import com.anydrop.rider.data.TokenManager
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory

/**
 * Single Retrofit instance for the Rider app.
 *
 * BASE_URL points at the same backend as the Customer/Restaurant apps.
 * Only this constant needs to change when the backend moves off
 * InfinityFree to a paid VPS — see recall.md's noted migration path.
 */
object ApiClient {

    private const val BASE_URL = "http://localhost:8080/anydrop/api/v1/"

    // Rider Order Detail (deep-plan §9, this session) — the address
    // photo comes back as a relative path (same "backend never makes
    // uploads absolute" convention every other image field in this
    // codebase follows — see orders-detail.php's own kdoc), so the
    // Android side prepends this the same way the customer app's
    // ApiClient.baseUrlForStaticFiles() already does for its own
    // dish/restaurant images.
    fun baseUrlForStaticFiles(): String = BASE_URL.removeSuffix("api/v1/")

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
            level = HttpLoggingInterceptor.Level.BODY
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
