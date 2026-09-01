package com.anydrop.restaurant.network

import com.google.gson.TypeAdapter
import com.google.gson.stream.JsonReader
import com.google.gson.stream.JsonToken
import com.google.gson.stream.JsonWriter

/**
 * Gson's built-in Boolean adapter only accepts a literal JSON
 * `true`/`false` (or, for the STRING token case, calls
 * `Boolean.parseBoolean(...)`). It does NOT accept a JSON *number* —
 * `in.nextBoolean()` throws `IllegalStateException: Expected a boolean
 * but was NUMBER` for anything else.
 *
 * That's exactly what broke staff login here: `restaurant_staff.is_active`
 * is a MySQL `TINYINT(1)`, and PDO/json_encode serialize that as a bare
 * JSON number (`"is_active":1`), not a JSON boolean (`true`) — confirmed
 * directly via `curl` against restaurant-staff-login.php, which
 * returned a perfectly valid 200 response the server side. The crash
 * was purely client-side: `StaffProfile.isActive: Boolean` failed to
 * deserialize that `1`, the whole `staffLogin()` call's Gson parse
 * threw, and that exception is what StaffLoginActivity's catch block
 * was showing as "network"/"unexpected response" — the backend was
 * never actually broken.
 *
 * Any TINYINT(1)/boolean-ish column can hit this same failure mode
 * wherever it's added to a response in the future (not just
 * is_active), so this is registered globally on the shared Gson
 * instance (ApiClient.kt) rather than patched field-by-field — it
 * transparently accepts `true`/`false`, `1`/`0`, and `"1"`/`"0"`,
 * covering every shape PHP might reasonably serialize a MySQL boolean
 * column as.
 */
object LenientBooleanTypeAdapter : TypeAdapter<Boolean>() {
    override fun write(out: JsonWriter, value: Boolean?) {
        if (value == null) out.nullValue() else out.value(value)
    }

    override fun read(reader: JsonReader): Boolean? {
        return when (reader.peek()) {
            JsonToken.NULL -> {
                reader.nextNull()
                null
            }
            JsonToken.BOOLEAN -> reader.nextBoolean()
            JsonToken.NUMBER -> reader.nextInt() != 0
            JsonToken.STRING -> {
                val s = reader.nextString()
                s == "1" || s.equals("true", ignoreCase = true)
            }
            else -> {
                reader.skipValue()
                null
            }
        }
    }
}
