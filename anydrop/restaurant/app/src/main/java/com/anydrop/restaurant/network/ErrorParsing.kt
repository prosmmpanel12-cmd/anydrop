package com.anydrop.restaurant.network

import com.google.gson.Gson
import okhttp3.ResponseBody

/**
 * Shared parser for the `{success, data, error}` envelope every Anydrop
 * endpoint responds with (backend/lib/response.php).
 *
 * Retrofit only populates `Response.body()` for 2xx HTTP responses —
 * for anything else (401/403/422/...) `body()` is always null and the
 * JSON is only reachable via `Response.errorBody()`. Several call
 * sites in this app used to read `response.body()?.error` even on the
 * failure branch, which meant it was always null and every specific
 * server error (`invalid_credentials`, `staff_disabled`,
 * `username_taken`, a validation_error's field list, ...) silently
 * collapsed into the same generic fallback message — see
 * `OfferManagerActivity.serverErrorDetail()`'s own kdoc, which found
 * and fixed this exact bug for the offers screen first. This is the
 * same fix, extracted so login and staff-management don't each need
 * their own copy.
 *
 * `errorBody()` can only be read once per response, so callers should
 * call this at most once per failed response.
 */
data class ParsedApiError(
    val code: String?,
    val fields: List<String>?,
    val reason: String?
)

fun parseApiError(errorBody: ResponseBody?): ParsedApiError {
    if (errorBody == null) return ParsedApiError(null, null, null)
    return try {
        val bodyStr = errorBody.string()
        val map = Gson().fromJson(bodyStr, Map::class.java)
        val code = map?.get("error") as? String
        val data = map?.get("data") as? Map<*, *>
        @Suppress("UNCHECKED_CAST")
        val fields = (data?.get("fields") as? List<*>)?.map { it.toString() }
        val reason = data?.get("reason") as? String
        ParsedApiError(code, fields, reason)
    } catch (e: Exception) {
        ParsedApiError(null, null, null)
    }
}
