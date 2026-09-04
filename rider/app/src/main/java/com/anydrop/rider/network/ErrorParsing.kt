package com.anydrop.rider.network

import com.google.gson.Gson
import okhttp3.ResponseBody

/**
 * Shared parser for the `{success, data, error}` envelope every Anydrop
 * endpoint responds with (backend/lib/response.php).
 *
 * Retrofit only populates `Response.body()` for 2xx HTTP responses — for
 * anything else (401/403/409/422/...) `body()` is null and the JSON is
 * only reachable via `Response.errorBody()`. Ported from the restaurant
 * app's `network/ErrorParsing.kt` (see that file's own kdoc for the bug
 * this fixes) so the rider app doesn't repeat the same mistake from day
 * one — this matters here in particular because rider-verify-otp.php's
 * `account_suspended` error carries `reason`/`status` in `data` that
 * OtpVerifyActivity needs to actually show the rider.
 *
 * `errorBody()` can only be read once per response, so callers should
 * call this at most once per failed response.
 */
data class ParsedApiError(
    val code: String?,
    val fields: List<String>?,
    val reason: String?,
    val status: String?
)

fun parseApiError(errorBody: ResponseBody?): ParsedApiError {
    if (errorBody == null) return ParsedApiError(null, null, null, null)
    return try {
        val bodyStr = errorBody.string()
        val map = Gson().fromJson(bodyStr, Map::class.java)
        val code = map?.get("error") as? String
        val data = map?.get("data") as? Map<*, *>
        @Suppress("UNCHECKED_CAST")
        val fields = (data?.get("fields") as? List<*>)?.map { it.toString() }
        val reason = data?.get("reason") as? String
        val status = data?.get("status") as? String
        ParsedApiError(code, fields, reason, status)
    } catch (e: Exception) {
        ParsedApiError(null, null, null, null)
    }
}
