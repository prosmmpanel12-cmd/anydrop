package com.anydrop.restaurant.network.external

/**
 * One unified result shape every provider's response gets mapped into
 * (see the `search*()` wrapper functions in [ExternalApiClient]) so
 * [com.anydrop.restaurant.ui.menu.CategoryIconSearchAdapter] /
 * [com.anydrop.restaurant.ui.menu.CategoryPhotoSearchAdapter] and
 * MenuFragment's two search chains only ever deal with one type,
 * regardless of which of the seven providers below a given result
 * actually came from.
 *
 * [previewUrl] is what the search-results grid loads (small/fast);
 * [downloadUrl] is what gets fetched for real once the restaurant taps a
 * result (full quality) — for most providers these differ (a thumbnail
 * vs. the original), for a couple (Iconify's SVGs) they're the same URL.
 * [isSvg] tells the downloader whether to run it through Coil's
 * [coil.decode.SvgDecoder] (Iconify only — every other provider here
 * returns a raster PNG/JPG preview even when its *source* asset is an
 * SVG, e.g. Openclipart's `png_thumb`).
 */
data class SearchResultImage(
    val previewUrl: String,
    val downloadUrl: String,
    val isSvg: Boolean = false,
    /** Which provider this came from — surfaced only for
     * debugging/telemetry (e.g. a future "why did this look different"
     * report); never shown in the UI itself. */
    val source: String
)

// ---------------------------------------------------------------------
// Raw per-provider response shapes below. Nothing outside
// [ExternalApiClient]'s search*() wrapper functions should ever touch
// these directly — MenuFragment and the two grid adapters only see
// [SearchResultImage].
// ---------------------------------------------------------------------

/** Iconify's public search endpoint
 * (`https://api.iconify.design/search?query=...`) — free, no API key,
 * no rate-limit auth required for reasonable client-side use. The
 * biggest/most reliable source in either chain (200,000+ icons across
 * 150+ icon sets), kept first in the Icons-tab chain for that reason.
 *
 * Each entry in [icons] is a fully-qualified icon id in
 * `"{prefix}:{name}"` form (e.g. `"mdi:pizza"`) — turned into a loadable
 * SVG URL via Iconify's own per-icon endpoint,
 * `https://api.iconify.design/{prefix}/{name}.svg`, inside
 * [ExternalApiClient.searchIconsIconify]. */
data class IconifySearchResponse(
    val icons: List<String> = emptyList(),
    val total: Int = 0
)

/** Openverse's public image-search endpoint
 * (`https://api.openverse.org/v1/images/?q=...`) — free/openly-licensed
 * image search (Creative Commons), no API key required for light,
 * non-commercial-scale client use per Openverse's own public docs. First
 * in the Photos-tab chain (real photos, not clip-art/icons, so it's the
 * best match for "search photos" when it's up) — but per the app-owner's
 * 2026-08-20 report that this alone was failing outright, it's no longer
 * the *only* source; see the rest of this file. */
data class OpenverseSearchResponse(
    val results: List<OpenverseImage> = emptyList()
)

data class OpenverseImage(
    val id: String,
    val title: String? = null,
    val url: String,
    val thumbnail: String? = null
)

// ---------------------------------------------------------------------
// 2026-08-20 multi-source fallback pass — app-owner report: the Icons tab
// only ever had one source (Iconify above) and the Photos tab only ever
// had one (Openverse above), and Openverse was failing outright for
// them. Rather than swap Openverse for a different single point of
// failure, both tabs now try several providers in order (see
// MenuFragment's runIconSearch()/runPhotoSearch()) and keep going until
// one returns results. Everything below is the raw response shape for
// one of those additional providers. Each provider's own kdoc says
// whether it needs an API key — none of the icon-chain additions do;
// three of the photo-chain additions (Pixabay/Pexels/Unsplash) do, and
// are genuinely inert (skipped in the chain, not attempted) until a free
// key is filled in — see res/values/api_keys.xml.
// ---------------------------------------------------------------------

/** Openclipart's public search endpoint
 * (`https://openclipart.org/search/json/?query=...`) — free, no API key,
 * open-licensed (public-domain) clip-art. Used as a fallback in BOTH
 * chains: its flat, single-subject clip-art style reads reasonably as an
 * "icon" once downsized (Icons tab), and it's still a real downloadable
 * image for restaurants who'd rather have that than nothing (Photos tab,
 * listed after the actual-photo sources).
 *
 * Real shape is `{"msg":"200 OK","info":{...},"payload":{"0":{...},
 * "1":{...},...}}` — [payload] is a JSON *object* keyed by result index,
 * not a JSON array, hence `Map<String, OpenclipartItem>` rather than a
 * `List`. */
data class OpenclipartSearchResponse(
    val msg: String? = null,
    val payload: Map<String, OpenclipartItem>? = null
)

data class OpenclipartItem(
    val id: String? = null,
    val title: String? = null,
    val svg: OpenclipartSvg? = null
)

data class OpenclipartSvg(
    /** Openclipart's own pre-rendered PNG thumbnail of the SVG — used
     * directly (plain raster load, no SVG decoder involved) rather than
     * [url] (the raw .svg) for both preview and download. */
    val png_thumb: String? = null,
    val url: String? = null
)

/** Wikimedia Commons' public MediaWiki API, `generator=search` +
 * `prop=imageinfo` combined into one call — free, no API key, and by far
 * the largest openly-licensed media library of any provider in this
 * file (Wikimedia Commons itself, not just Wikipedia). Used as a
 * fallback in both chains, same reasoning as [OpenclipartSearchResponse].
 *
 * `pages` is a JSON *object* keyed by numeric MediaWiki page id (not a
 * list, and not reliably ordered by relevance in the raw JSON) — see
 * [ExternalApiClient.searchWikimedia] for the sort-by-[WikimediaPage.index]
 * step that fixes result ordering before this becomes [SearchResultImage]s. */
data class WikimediaSearchResponse(
    val query: WikimediaQuery? = null
)

data class WikimediaQuery(
    val pages: Map<String, WikimediaPage>? = null
)

data class WikimediaPage(
    val title: String? = null,
    /** MediaWiki's own relevance rank for `generator=search` results —
     * lower is more relevant. Absent on a few responses; treated as
     * "last" rather than crashing a sort when missing. */
    val index: Int? = null,
    val imageinfo: List<WikimediaImageInfo>? = null
)

data class WikimediaImageInfo(
    val url: String? = null,
    /** Only present because the search call requests `iiurlwidth` — a
     * multi-megapixel original [url] would be wasteful to load into a
     * small grid thumbnail. */
    val thumburl: String? = null
)

/** Pixabay's search endpoint — free tier, but *does* require a personal
 * API key (free signup at pixabay.com/api/docs). [ExternalApiClient]
 * only attempts this provider when the `pixabay_api_key` string
 * resource is non-blank; see res/values/api_keys.xml. Genuinely inert
 * (never called) until a key is filled in there — no placeholder/demo
 * key is embedded in this project. */
data class PixabaySearchResponse(
    val hits: List<PixabayHit> = emptyList()
)

data class PixabayHit(
    val id: Long,
    val webformatURL: String? = null,
    val previewURL: String? = null
)

/** Pexels' search endpoint — free tier, requires a personal API key
 * (free signup at pexels.com/api). Key-gated the same way as
 * [PixabaySearchResponse]; see res/values/api_keys.xml. */
data class PexelsSearchResponse(
    val photos: List<PexelsPhoto> = emptyList()
)

data class PexelsPhoto(
    val id: Long,
    val src: PexelsPhotoSrc? = null
)

data class PexelsPhotoSrc(
    val medium: String? = null,
    val small: String? = null
)

/** Unsplash's search endpoint — free "Demo" tier (50 requests/hour),
 * requires a personal Access Key (free signup at unsplash.com/developers).
 * Key-gated the same way as [PixabaySearchResponse]/[PexelsSearchResponse];
 * see res/values/api_keys.xml. */
data class UnsplashSearchResponse(
    val results: List<UnsplashPhoto> = emptyList()
)

data class UnsplashPhoto(
    val id: String,
    val urls: UnsplashPhotoUrls? = null
)

data class UnsplashPhotoUrls(
    val small: String? = null,
    val thumb: String? = null
)
