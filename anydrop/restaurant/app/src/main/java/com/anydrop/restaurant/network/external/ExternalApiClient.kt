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

/** Step 1 of Flaticon's two-step call (see [FlaticonAuthRequest]'s own
 * kdoc) — exchanges the long-lived personal API key for a short-lived
 * bearer token. */
interface FlaticonAuthApi {
    @retrofit2.http.POST("v3/app/authentication")
    suspend fun authenticate(@retrofit2.http.Body body: FlaticonAuthRequest): Response<FlaticonLoginResponse>
}

/** Step 2 of Flaticon's two-step call — the actual icon search, using
 * the bearer token [FlaticonAuthApi.authenticate] returned. */
interface FlaticonSearchApi {
    @GET("v3/search/icons")
    suspend fun search(
        @retrofit2.http.Header("Authorization") bearer: String,
        @Query("q") query: String,
        @Query("limit") limit: Int = 30
    ): Response<FlaticonSearchResponse>
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

    private val flaticonAuth: FlaticonAuthApi by lazy {
        retrofitFor("https://api.flaticon.com/").create(FlaticonAuthApi::class.java)
    }

    private val flaticonSearch: FlaticonSearchApi by lazy {
        retrofitFor("https://api.flaticon.com/").create(FlaticonSearchApi::class.java)
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

    private const val GOOGLE_ICONS_ASSET_FAMILY = "materialicons"
    private const val GOOGLE_ICONS_ASSET_FILE = "24px.svg"

    /** In-memory cache of the full Google icons catalog — fetched once
     * (it's the same ~1MB response regardless of search query, there's no
     * server-side filtering) and reused for every subsequent call in this
     * process; see [GoogleIconsMetadataResponse]'s own kdoc. Not
     * thread-safe against a genuine race on the very first concurrent
     * calls, but worst case that's one duplicate network fetch, not a
     * correctness bug — not worth a Mutex for a debounced search field. */
    private var cachedGoogleIcons: List<GoogleIconMetadata>? = null

    private suspend fun fetchGoogleIconsMetadata(): List<GoogleIconMetadata> {
        cachedGoogleIcons?.let { return it }
        return try {
            val request = Request.Builder().url("https://fonts.google.com/metadata/icons").build()
            val raw = client.newCall(request).execute().use { it.body?.string() } ?: return emptyList()
            // Response body starts with an XSSI-protection line (")]}'")
            // before the real JSON — skip to the first '{' rather than
            // parsing that line.
            val jsonStart = raw.indexOf('{')
            if (jsonStart < 0) return emptyList()
            val parsed = gson.fromJson(raw.substring(jsonStart), GoogleIconsMetadataResponse::class.java)
            val icons = parsed?.icons.orEmpty()
            cachedGoogleIcons = icons
            icons
        } catch (e: Exception) {
            emptyList()
        }
    }

    /** Free, no API key — see [GoogleIconsMetadataResponse]'s kdoc for why
     * this filters a locally-cached catalog instead of calling a
     * per-query search endpoint (Google's metadata endpoint doesn't have
     * one). */
    suspend fun searchIconsGoogleMaterial(query: String): List<SearchResultImage> = try {
        val q = query.trim().lowercase()
        fetchGoogleIconsMetadata()
            .filter { icon ->
                val name = icon.name?.lowercase().orEmpty()
                name.contains(q) || icon.tags.orEmpty().any { it.lowercase().contains(q) }
            }
            .take(48)
            .mapNotNull { icon ->
                val name = icon.name ?: return@mapNotNull null
                val version = icon.version ?: return@mapNotNull null
                val svgUrl = "https://fonts.gstatic.com/s/i/$GOOGLE_ICONS_ASSET_FAMILY/$name/v$version/$GOOGLE_ICONS_ASSET_FILE"
                SearchResultImage(previewUrl = svgUrl, downloadUrl = svgUrl, isSvg = true, source = "google-material-icons")
            }
    } catch (e: Exception) {
        emptyList()
    }

    /** In-memory bearer-token cache for [searchIconsFlaticon] — Flaticon
     * tokens expire after about an hour per Flaticon's own docs; refetched
     * a little before that to stay safe. Keyed by nothing (this app only
     * ever uses one Flaticon API key at a time) — just a token + the
     * timestamp it was issued. */
    private var flaticonToken: String? = null
    private var flaticonTokenFetchedAt: Long = 0L
    private val FLATICON_TOKEN_TTL_MS = 50 * 60 * 1000L // refresh a bit before the ~1hr expiry

    private suspend fun getFlaticonToken(apiKey: String): String? {
        val cached = flaticonToken
        if (cached != null && System.currentTimeMillis() - flaticonTokenFetchedAt < FLATICON_TOKEN_TTL_MS) {
            return cached
        }
        val token = flaticonAuth.authenticate(FlaticonAuthRequest(apiKey)).body()?.data?.token ?: return null
        flaticonToken = token
        flaticonTokenFetchedAt = System.currentTimeMillis()
        return token
    }

    /** Only attempted when [apiKey] (res/values/api_keys.xml,
     * `flaticon_api_key`) is non-blank — same key-gating as
     * [searchPhotosPixabay]. See [FlaticonAuthRequest]'s kdoc for the
     * two-step auth-then-search flow this wraps. */
    suspend fun searchIconsFlaticon(query: String, apiKey: String): List<SearchResultImage> {
        if (apiKey.isBlank()) return emptyList()
        return try {
            val token = getFlaticonToken(apiKey) ?: return emptyList()
            flaticonSearch.search(bearer = "Bearer $token", query = query).body()?.data.orEmpty()
                .mapNotNull { icon ->
                    val images = icon.images ?: return@mapNotNull null
                    // Prefer a small size for the grid preview and the
                    // largest available for the actual download.
                    val preview = images["64"] ?: images.values.minByOrNull { it.length }
                    val download = images["512"] ?: images["256"] ?: images["128"]
                        ?: images.values.maxByOrNull { it.length } ?: preview
                    val url = download ?: preview ?: return@mapNotNull null
                    SearchResultImage(previewUrl = preview ?: url, downloadUrl = url, source = "flaticon")
                }
        } catch (e: Exception) {
            emptyList()
        }
    }

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

    /** Read the four optional key resources once per call site — small
     * enough (four string lookups) that there's no need to cache these;
     * kept as a single helper so MenuFragment doesn't need to know the
     * four individual resource ids. */
    data class OptionalApiKeys(
        val pixabay: String,
        val pexels: String,
        val unsplash: String,
        val flaticon: String
    )

    fun readOptionalApiKeys(context: Context): OptionalApiKeys = OptionalApiKeys(
        pixabay = context.getString(R.string.pixabay_api_key),
        pexels = context.getString(R.string.pexels_api_key),
        unsplash = context.getString(R.string.unsplash_access_key),
        flaticon = context.getString(R.string.flaticon_api_key)
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
