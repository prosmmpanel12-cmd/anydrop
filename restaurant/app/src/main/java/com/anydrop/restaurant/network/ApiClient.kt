package com.anydrop.restaurant.network

import android.content.Context
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
