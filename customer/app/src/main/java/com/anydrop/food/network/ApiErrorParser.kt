package com.anydrop.food.network

import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import retrofit2.Response

/**
 * Bug found alongside H4 (2026-08-10): Retrofit only populates
 * `Response.body()` when the HTTP status is 2xx. For every non-2xx response
 * (e.g. `orders/create.php`'s 422 `below_min_order_amount`,
 * `invalid_coupon`, `restaurant_unavailable`, ...) `response.body()` is
 * always null, so every call site doing `response.body()?.error ?:
 * "<generic fallback>"` silently discarded the real backend error code and
 * always showed the generic fallback — no matter which specific error the
 * server actually sent. The real body is only reachable via
 * `response.errorBody()` on non-2xx responses; this reads and parses that
 * instead, exactly like a successful response's `ApiResponse` envelope.
 *
 * Usage:
 *   val info = ApiErrorParser.parse(response)
 *   val message = when (info.code) {
 *       "below_min_order_amount" -> ...use info.data["min_order_amount"] / ["item_total"]...
 *       else -> info.code ?: "Something went wrong"
 *   }
 */
object ApiErrorParser {
    private val gson = Gson()
    private val envelopeType = object : TypeToken<Map<String, Any?>>() {}.type

    data class Info(val code: String?, val data: Map<String, Any?>)

    /** Parses `{ "success": false, "data": {...}, "error": "..." }` off
     * [response]'s error body. Safe to call even on a successful response
     * (returns an empty [Info] since there's no error body to read) and
     * safe against malformed/missing bodies (never throws). */
    fun parse(response: Response<*>): Info {
        val bodyString = try {
            response.errorBody()?.string()
        } catch (e: Exception) {
            null
        } ?: return Info(null, emptyMap())

        return try {
            @Suppress("UNCHECKED_CAST")
            val envelope = gson.fromJson<Map<String, Any?>>(bodyString, envelopeType)
            val code = envelope["error"] as? String
            @Suppress("UNCHECKED_CAST")
            val data = (envelope["data"] as? Map<String, Any?>) ?: emptyMap()
            Info(code, data)
        } catch (e: Exception) {
            Info(null, emptyMap())
        }
    }
}
