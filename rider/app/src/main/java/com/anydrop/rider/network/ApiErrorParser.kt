package com.anydrop.rider.network

import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import retrofit2.Response

/**
 * Ported from the customer app's network/ApiErrorParser.kt (same bug it
 * documents applies here too): Retrofit only populates `Response.body()`
 * on a 2xx status. For `rider/payout.php`'s 422 error codes
 * (`insufficient_balance`, `below_minimum_amount`, `validation_error`)
 * `response.body()` is always null — the real envelope is only reachable
 * via `response.errorBody()`. This reads and parses that, same shape as
 * a successful `ApiResponse` envelope.
 *
 * Usage:
 *   val info = ApiErrorParser.parse(response)
 *   val message = when (info.code) {
 *       "insufficient_balance" -> ...
 *       "below_minimum_amount" -> ...use info.data["minimum"]...
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
