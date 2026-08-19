package com.anydrop.restaurant.ui.menu

import androidx.annotation.DrawableRes
import androidx.annotation.StringRes
import com.anydrop.restaurant.R

/**
 * Bundled category icon set (doc 22 item 1 — "category icons shouldn't
 * require an upload every time"). App owner's decision: option 1, a fixed
 * set shipped inside the app, restaurant picks from a grid — zero network
 * dependency, no rate limits, no ongoing cost. Option 2 ("search more" via
 * a stock-icon/photo API) is explicitly deferred, not built here; see
 * 00_Status.md / NEXT_SESSION_PROMPT.md for that follow-up.
 *
 * [key] is what actually gets sent to the server (`icon_key` on
 * [com.anydrop.restaurant.network.MenuCategory] /
 * [com.anydrop.restaurant.network.CategoryCreateBody] /
 * [com.anydrop.restaurant.network.CategoryUpdateBody]) and stored as-is —
 * the server doesn't validate it against this list
 * (28_migration_category_icon_key.sql's kdoc), so this object is the
 * single source of truth for which keys are actually valid. An
 * unrecognized key (e.g. one from a future app version's larger icon set,
 * seen by an older client) just falls back to the placeholder icon — see
 * [drawableFor].
 *
 * v1 set — 14 common Indian-restaurant categories, matching doc 22's own
 * examples (biryani, chinese, desserts, beverages, south indian) plus
 * reasonable additions. The vector art itself is placeholder-quality (see
 * each ic_cat_*.xml's header) pending a real design pass once a toolchain
 * exists to actually preview it.
 */
object CategoryIcons {

    data class Option(val key: String, @DrawableRes val iconRes: Int, @StringRes val labelRes: Int)

    val ALL: List<Option> = listOf(
        Option("biryani", R.drawable.ic_cat_biryani, R.string.category_icon_biryani),
        Option("north_indian", R.drawable.ic_cat_north_indian, R.string.category_icon_north_indian),
        Option("south_indian", R.drawable.ic_cat_south_indian, R.string.category_icon_south_indian),
        Option("chinese", R.drawable.ic_cat_chinese, R.string.category_icon_chinese),
        Option("pizza", R.drawable.ic_cat_pizza, R.string.category_icon_pizza),
        Option("burger", R.drawable.ic_cat_burger, R.string.category_icon_burger),
        Option("tandoori", R.drawable.ic_cat_tandoori, R.string.category_icon_tandoori),
        Option("momos", R.drawable.ic_cat_momos, R.string.category_icon_momos),
        Option("breakfast", R.drawable.ic_cat_breakfast, R.string.category_icon_breakfast),
        Option("bakery", R.drawable.ic_cat_bakery, R.string.category_icon_bakery),
        Option("desserts", R.drawable.ic_cat_desserts, R.string.category_icon_desserts),
        Option("ice_cream", R.drawable.ic_cat_ice_cream, R.string.category_icon_ice_cream),
        Option("beverages", R.drawable.ic_cat_beverages, R.string.category_icon_beverages),
        Option("salads", R.drawable.ic_cat_salads, R.string.category_icon_salads)
    )

    private val byKey = ALL.associateBy { it.key }

    fun find(key: String?): Option? = key?.let { byKey[it] }

    /** [drawableFor] falls back to [R.drawable.ic_food_placeholder] for a
     * null or unrecognized key, same fallback CategoryAdapter/MenuFragment
     * already use for a missing/broken image_url. */
    @DrawableRes
    fun drawableFor(key: String?): Int = find(key)?.iconRes ?: R.drawable.ic_food_placeholder
}
