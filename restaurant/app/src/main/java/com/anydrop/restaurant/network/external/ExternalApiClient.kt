package com.anydrop.restaurant.network.external

import okhttp3.OkHttpClient
import retrofit2.Response
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import retrofit2.http.GET
import retrofit2.http.Query
import java.util.concurrent.TimeUnit

/**
 * Icon-search half of the category-icon live search (Phase 1, 2026-08-19
 * UI/UX overhaul). Deliberately a *separate* Retrofit instance from
 * [com.anydrop.restaurant.network.ApiClient] — these two APIs are public,
 * unauthenticated, third-party services, not the AnyDrop backend, so they
 * must never pick up [com.anydrop.restaurant.network.ApiClient]'s auth
 * bearer-token interceptor.
 */
interface IconifyApi {
    @GET("search")
    suspend fun search(
        @Query("query") query: String,
        @Query("limit") limit: Int = 48
    ): Response<IconifySearchResponse>
}

/** Photo-search half — Openverse (openly-licensed / Creative Commons
 * image search), same "own Retrofit instance, no AnyDrop auth" reasoning
 * as [IconifyApi]. */
interface OpenverseApi {
    @GET("images/")
    suspend fun search(
        @Query("q") query: String,
        @Query("page_size") pageSize: Int = 30
    ): Response<OpenverseSearchResponse>
}

object ExternalApiClient {

    // Short, generous-but-bounded timeouts — these are best-effort search
    // calls triggered on every debounced keystroke, not critical-path
    // requests; a slow/unreachable third party should fail fast rather
    // than hang the search grid indefinitely.
    private val client: OkHttpClient by lazy {
        OkHttpClient.Builder()
            .connectTimeout(8, TimeUnit.SECONDS)
            .readTimeout(8, TimeUnit.SECONDS)
            .build()
    }

    val iconify: IconifyApi by lazy {
        Retrofit.Builder()
            .baseUrl("https://api.iconify.design/")
            .client(client)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(IconifyApi::class.java)
    }

    val openverse: OpenverseApi by lazy {
        Retrofit.Builder()
            .baseUrl("https://api.openverse.org/v1/")
            .client(client)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(OpenverseApi::class.java)
    }
}
