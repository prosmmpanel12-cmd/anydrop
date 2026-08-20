package com.anydrop.restaurant.network.external

import android.content.Context
import com.anydrop.restaurant.R
import com.google.gson.Gson
import okhttp3.OkHttpClient
import okhttp3.Request
import retrofit2.Response
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import retrofit2.http.GET
import retrofit2.http.Query
import java.util.concurrent.TimeUnit

/**
 * Icon-search + photo-search external providers for the category-icon
 * live search (Phase 1, 2026-08-19 UI/UX overhaul; multi-source fallback
 * pass 2026-08-20 — see ExternalModels.kt's own header comment for why).
 * Deliberately a *separate* Retrofit instance/OkHttpClient from
 * [com.anydrop.restaurant.network.ApiClient] — every provider here is a
 * public, unauthenticated (or separately-keyed), third-party service,
 * not the AnyDrop backend, so none of them should ever pick up
 * [com.anydrop.restaurant.network.ApiClient]'s auth bearer-token
 * interceptor.
 *
 * Callers (MenuFragment's runIconSearch()/runPhotoSearch()) should use
 * the `search*()` wrapper functions at the bottom of this file, not the
 * raw `*Api` interfaces above them directly — the wrappers are what
 * normalize every provider's own response shape into
 * [SearchResultImage] and swallow that provider's own exceptions (so one
 * slow/unreachable provider can't take the whole fallback chain down
 * with it; see each wrapper's own try/catch).
 */
interface IconifyApi {
    @GET("search")
    suspend fun search(
        @Query("query") query: String,
        @Query("limit") limit: Int = 48
    ): Response<IconifySearchResponse>
}

interface OpenverseApi {
    @GET("images/")
    suspend fun search(
        @Query("q") query: String,
        @Query("page_size") pageSize: Int = 30
    ): Response<OpenverseSearchResponse>
}

interface OpenclipartApi {
    @GET("search/json/")
    suspend fun search(
        @Query("query") query: String,
        @Query("amount") amount: Int = 30
    ): Response<OpenclipartSearchResponse>
}

interface WikimediaCommonsApi {
    // gsrnamespace=6 restricts the search to the File: namespace (actual
    // media, not article pages) — Wikimedia Commons' own documented value
    // for that namespace. iiurlwidth asks for a pre-scaled thumbnail so
    // the grid isn't loading full-resolution originals.
    @GET("w/api.php?action=query&format=json&generator=search&gsrnamespace=6&prop=imageinfo&iiprop=url&iiurlwidth=320")
    suspend fun search(
        @Query("gsrsearch") query: String,
        @Query("gsrlimit") limit: Int = 30
    ): Response<WikimediaSearchResponse>
}

interface PixabayApi {
    @GET("api/")
    suspend fun search(
        @Query("key") apiKey: String,
        @Query("q") query: String,
        @Query("per_page") perPage: Int = 30,
        @Query("safesearch") safeSearch: Boolean = true
    ): Response<PixabaySearchResponse>
}

interface PexelsApi {
    @GET("v1/search")
    suspend fun search(
        @retrofit2.http.Header("Authorization") apiKey: String,
        @Query("query") query: String,
        @Query("per_page") perPage: Int = 30
    ): Response<PexelsSearchResponse>
}

interface UnsplashApi {
    @GET("search/photos")
    suspend fun search(
        @retrofit2.http.Header("Authorization") clientIdHeader: String,
        @Query("query") query: String,
        @Query("per_page") perPage: Int = 30
    ): Response<UnsplashSearchResponse>
}

object ExternalApiClient {

    // Short, generous-but-bounded timeouts — these are best-effort search
    // calls triggered on every debounced keystroke, not critical-path
    // requests; a slow/unreachable third party should fail fast rather
    // than hang the search grid indefinitely, and fail fast is what lets
    // the fallback chains below try the next provider quickly instead of
    // sitting on a stalled connection.
    private val client: OkHttpClient by lazy {
        OkHttpClient.Builder()
            .connectTimeout(8, TimeUnit.SECONDS)
            .readTimeout(8, TimeUnit.SECONDS)
            .build()
    }

    private val gson: Gson by lazy { Gson() }

    private fun retrofitFor(baseUrl: String) = Retrofit.Builder()
        .baseUrl(baseUrl)
        .client(client)
        .addConverterFactory(GsonConverterFactory.create())
        .build()

    val iconify: IconifyApi by lazy {
        retrofitFor("https://api.iconify.design/").create(IconifyApi::class.java)
    }

    val openverse: OpenverseApi by lazy {
        retrofitFor("https://api.openverse.org/v1/").create(OpenverseApi::class.java)
    }

    val openclipart: OpenclipartApi by lazy {
        retrofitFor("https://openclipart.org/").create(OpenclipartApi::class.java)
    }

    val wikimediaCommons: WikimediaCommonsApi by lazy {
        retrofitFor("https://commons.wikimedia.org/").create(WikimediaCommonsApi::class.java)
    }

    val pixabay: PixabayApi by lazy {
        retrofitFor("https://pixabay.com/").create(PixabayApi::class.java)
    }

    val pexels: PexelsApi by lazy {
        retrofitFor("https://api.pexels.com/").create(PexelsApi::class.java)
    }

    val unsplash: UnsplashApi by lazy {
        retrofitFor("https://api.unsplash.com/").create(UnsplashApi::class.java)
    }

    // -------------------------------------------------------------
    // search*() wrappers — one per provider, each normalizing that
    // provider's own response shape into List<SearchResultImage> and
    // swallowing that provider's own exceptions (a slow/unreachable
    // provider returns an empty list here, not a crash, so
    // MenuFragment's chain can just move on to the next provider).
    // -------------------------------------------------------------

    suspend fun searchIconsIconify(query: String): List<SearchResultImage> = try {
        val ids = iconify.search(query).body()?.icons.orEmpty()
        ids.map { id ->
            val prefix = id.substringBefore(':', missingDelimiterValue = "")
            val name = id.substringAfter(':', missingDelimiterValue = id)
            val svgUrl = "https://api.iconify.design/$prefix/$name.svg"
            SearchResultImage(previewUrl = svgUrl, downloadUrl = svgUrl, isSvg = true, source = "iconify")
        }
    } catch (e: Exception) {
        emptyList()
    }

    suspend fun searchIconsOpenclipart(query: String): List<SearchResultImage> =
        searchOpenclipartRaw(query, source = "openclipart-icon")

    suspend fun searchIconsWikimedia(query: String): List<SearchResultImage> =
        searchWikimediaRaw(query, source = "wikimedia-icon")

    suspend fun searchPhotosOpenverse(query: String): List<SearchResultImage> = try {
        openverse.search(query).body()?.results.orEmpty().map {
            SearchResultImage(
                previewUrl = it.thumbnail ?: it.url,
                downloadUrl = it.url,
                source = "openverse"
            )
        }
    } catch (e: Exception) {
        emptyList()
    }

    suspend fun searchPhotosWikimedia(query: String): List<SearchResultImage> =
        searchWikimediaRaw(query, source = "wikimedia-photo")

    suspend fun searchPhotosOpenclipart(query: String): List<SearchResultImage> =
        searchOpenclipartRaw(query, source = "openclipart-photo")

    /** Only attempted when [apiKeyPixabay] (res/values/api_keys.xml) is
     * non-blank — see this file's/ExternalModels.kt's header comments.
     * Returns an empty list immediately, without a network call, when no
     * key is configured. */
    suspend fun searchPhotosPixabay(query: String, apiKey: String): List<SearchResultImage> {
        if (apiKey.isBlank()) return emptyList()
        return try {
            pixabay.search(apiKey = apiKey, query = query).body()?.hits.orEmpty().mapNotNull { hit ->
                val url = hit.webformatURL ?: hit.previewURL ?: return@mapNotNull null
                SearchResultImage(previewUrl = hit.previewURL ?: url, downloadUrl = url, source = "pixabay")
            }
        } catch (e: Exception) {
            emptyList()
        }
    }

    /** Same key-gating as [searchPhotosPixabay]; see res/values/api_keys.xml. */
    suspend fun searchPhotosPexels(query: String, apiKey: String): List<SearchResultImage> {
        if (apiKey.isBlank()) return emptyList()
        return try {
            pexels.search(apiKey = apiKey, query = query).body()?.photos.orEmpty().mapNotNull { photo ->
                val url = photo.src?.medium ?: photo.src?.small ?: return@mapNotNull null
                SearchResultImage(previewUrl = photo.src?.small ?: url, downloadUrl = url, source = "pexels")
            }
        } catch (e: Exception) {
            emptyList()
        }
    }

    /** Same key-gating as [searchPhotosPixabay]; see res/values/api_keys.xml.
     * Unsplash's Authorization header format is `Client-ID {key}`, not a
     * bearer token — [apiKey] should be the raw Access Key, this
     * function adds the `Client-ID ` prefix itself. */
    suspend fun searchPhotosUnsplash(query: String, apiKey: String): List<SearchResultImage> {
        if (apiKey.isBlank()) return emptyList()
        return try {
            unsplash.search(clientIdHeader = "Client-ID $apiKey", query = query)
                .body()?.results.orEmpty().mapNotNull { photo ->
                    val url = photo.urls?.small ?: photo.urls?.thumb ?: return@mapNotNull null
                    SearchResultImage(previewUrl = photo.urls?.thumb ?: url, downloadUrl = url, source = "unsplash")
                }
        } catch (e: Exception) {
            emptyList()
        }
    }

    /** Last-resort fallback for the Photos tab — DuckDuckGo's image
     * search has no official public API; this reverse-engineers its
     * undocumented internal endpoint the same way several open-source
     * "ddg image scraper" libraries do: fetch the HTML search page once
     * to pull out a `vqd` token DuckDuckGo embeds for that query, then
     * call the internal `i.js` JSON endpoint with it.
     *
     * **This is inherently fragile** — it depends on DuckDuckGo's
     * unversioned internal page structure, isn't a documented/stable
     * contract, and can silently start returning nothing (or fail
     * entirely) if DuckDuckGo changes that page. It's deliberately last
     * in [ExternalApiClient]'s photo chain, after every official
     * provider, and every failure mode here is already handled the same
     * "return empty list, let the chain move on" way as the official
     * providers' own try/catch — nothing about this being scraped
     * instead of an official API changes how callers use it. */
    suspend fun searchPhotosDuckDuckGoScrape(query: String): List<SearchResultImage> {
        return try {
            val vqd = fetchDuckDuckGoVqd(query) ?: return emptyList()
            val request = Request.Builder()
                .url(
                    "https://duckduckgo.com/i.js?l=us-en&o=json&q=" +
                        java.net.URLEncoder.encode(query, "UTF-8") +
                        "&vqd=$vqd&f=,,,&p=1"
                )
                .header("User-Agent", DUCKDUCKGO_USER_AGENT)
                .header("Referer", "https://duckduckgo.com/")
                .build()
            val body = client.newCall(request).execute().use { it.body?.string() } ?: return emptyList()
            val parsed = gson.fromJson(body, DuckDuckGoImageResponse::class.java) ?: return emptyList()
            parsed.results.orEmpty().mapNotNull { result ->
                val url = result.image ?: return@mapNotNull null
                SearchResultImage(
                    previewUrl = result.thumbnail ?: url,
                    downloadUrl = url,
                    source = "duckduckgo-scrape"
                )
            }
        } catch (e: Exception) {
            emptyList()
        }
    }

    /** Step 1 of [searchPhotosDuckDuckGoScrape] — the `vqd` token is
     * embedded in the HTML of a normal DuckDuckGo search results page as
     * `vqd='...'` (or `vqd=\"...\"` depending on which page variant is
     * served); this just fetches that page and regexes it out rather
     * than parsing full HTML, since that's all this needs from it. */
    private fun fetchDuckDuckGoVqd(query: String): String? {
        val request = Request.Builder()
            .url("https://duckduckgo.com/?q=" + java.net.URLEncoder.encode(query, "UTF-8") + "&iax=images&ia=images")
            .header("User-Agent", DUCKDUCKGO_USER_AGENT)
            .build()
        val html = client.newCall(request).execute().use { it.body?.string() } ?: return null
        val match = Regex("vqd=['\"]([\\d-]+)['\"]").find(html)
            ?: Regex("vqd=([\\d-]+)&").find(html)
        return match?.groupValues?.getOrNull(1)
    }

    private suspend fun searchOpenclipartRaw(query: String, source: String): List<SearchResultImage> = try {
        openclipart.search(query).body()?.payload.orEmpty().values.mapNotNull { item ->
            val url = item.svg?.png_thumb ?: item.svg?.url ?: return@mapNotNull null
            SearchResultImage(previewUrl = url, downloadUrl = item.svg?.url ?: url, source = source)
        }
    } catch (e: Exception) {
        emptyList()
    }

    private suspend fun searchWikimediaRaw(query: String, source: String): List<SearchResultImage> = try {
        wikimediaCommons.search(query).body()?.query?.pages.orEmpty().values
            .sortedBy { it.index ?: Int.MAX_VALUE }
            .mapNotNull { page ->
                val info = page.imageinfo?.firstOrNull() ?: return@mapNotNull null
                val url = info.thumburl ?: info.url ?: return@mapNotNull null
                SearchResultImage(previewUrl = url, downloadUrl = info.url ?: url, source = source)
            }
    } catch (e: Exception) {
        emptyList()
    }

    private const val DUCKDUCKGO_USER_AGENT =
        "Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36"

    /** Read the three optional key resources once per call site — small
     * enough (three string lookups) that there's no need to cache these;
     * kept as a single helper so MenuFragment doesn't need to know the
     * three individual resource ids. */
    data class OptionalApiKeys(val pixabay: String, val pexels: String, val unsplash: String)

    fun readOptionalApiKeys(context: Context): OptionalApiKeys = OptionalApiKeys(
        pixabay = context.getString(R.string.pixabay_api_key),
        pexels = context.getString(R.string.pexels_api_key),
        unsplash = context.getString(R.string.unsplash_access_key)
    )
}

/** Raw shape of DuckDuckGo's undocumented `i.js` image-search endpoint —
 * see [ExternalApiClient.searchPhotosDuckDuckGoScrape]'s own kdoc for
 * why this exists at all. Deliberately not in ExternalModels.kt with the
 * seven official providers' response shapes — this one isn't an official
 * contract and shouldn't read like it belongs next to ones that are. */
private data class DuckDuckGoImageResponse(
    val results: List<DuckDuckGoImageResult>? = null
)

private data class DuckDuckGoImageResult(
    val image: String? = null,
    val thumbnail: String? = null
)
