package com.anydrop.restaurant.network.external

/**
 * Response shape of Iconify's public search endpoint
 * (`https://api.iconify.design/search?query=...`) — free, no API key,
 * no rate-limit auth required for reasonable client-side use (same
 * "no key, no cost, no ongoing dependency" reasoning CategoryIcons.kt's
 * own kdoc already used to justify the bundled-set-first approach; this
 * is the "search more" half doc 22 item 1 deferred at the time).
 *
 * Each entry in [icons] is a fully-qualified icon id in
 * `"{prefix}:{name}"` form (e.g. `"mdi:pizza"`, `"twemoji:pizza"`) — see
 * [IconifyResult.svgUrl] for how that turns into a loadable image.
 */
data class IconifySearchResponse(
    val icons: List<String> = emptyList(),
    val total: Int = 0
)

/** One icon search result, wrapping the raw `"prefix:name"` id string
 * Iconify returns into something a RecyclerView adapter can bind
 * directly without re-parsing it in three different places. */
data class IconifyResult(val id: String) {
    private val prefix: String get() = id.substringBefore(':', missingDelimiterValue = "")
    private val name: String get() = id.substringAfter(':', missingDelimiterValue = id)

    /** Iconify's documented per-icon SVG endpoint —
     * `https://api.iconify.design/{prefix}/{name}.svg`. */
    val svgUrl: String
        get() = "https://api.iconify.design/$prefix/$name.svg"
}

/** Response shape of Openverse's public image-search endpoint
 * (`https://api.openverse.org/v1/images/?q=...`) — free/openly-licensed
 * image search (Creative Commons), no API key required for light,
 * non-commercial-scale client use per Openverse's own public docs. */
data class OpenverseSearchResponse(
    val results: List<OpenverseImage> = emptyList()
)

data class OpenverseImage(
    val id: String,
    val title: String? = null,
    val url: String,
    val thumbnail: String? = null
) {
    /** Grid-preview + eventual download source — prefer the smaller
     * pre-rendered thumbnail when Openverse provides one (faster grid
     * load, and it's still plenty of resolution for a small category
     * icon), fall back to the full [url] otherwise. */
    val previewUrl: String get() = thumbnail ?: url
}
