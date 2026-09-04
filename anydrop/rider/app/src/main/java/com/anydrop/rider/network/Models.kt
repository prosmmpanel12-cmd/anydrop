package com.anydrop.rider.network

import com.google.gson.annotations.SerializedName

/** Standard envelope every Anydrop API endpoint responds with. */
data class ApiResponse<T>(
    val success: Boolean,
    val data: T?,
    val error: String?
)

// ---- Auth (backend/api/v1/auth/rider-*.php, docs 79-82) ----

data class RequestOtpBody(val email: String)

data class RequestOtpResult(
    val message: String,
    @SerializedName("debug_otp") val debugOtp: String? = null
)

data class VerifyOtpBody(val email: String, val otp: String)

/**
 * Mirrors rider-verify-otp.php exactly. This single call does double duty:
 * - New email -> accountExists=false, app routes to SignupActivity.
 * - Existing rider -> accountExists=true, token+rider+status are already
 *   here — this IS login, no separate call needed.
 */
data class VerifyOtpResult(
    val verified: Boolean,
    val email: String,
    @SerializedName("account_exists") val accountExists: Boolean,
    val rider: RiderProfile? = null,
    val token: String? = null,
    val status: String? = null
)

data class SignupBody(
    val name: String,
    val email: String,
    val mobile: String,
    @SerializedName("service_area_id") val serviceAreaId: Int? = null,
    val latitude: Double? = null,
    val longitude: Double? = null,
    // Deliberately NOT sent from the signup form (app owner decision,
    // 2026-09-01) — vehicle_type/vehicle_number are collected later in
    // the post-approval "Complete Profile" step, not here. The backend
    // field exists and accepts these, they're just never populated from
    // this screen.
    @SerializedName("vehicle_type") val vehicleType: String? = null,
    @SerializedName("vehicle_number") val vehicleNumber: String? = null
)

data class SignupResult(
    val rider: RiderProfile,
    val status: String,
    @SerializedName("area_resolved") val areaResolved: Boolean = false,
    val area: ResolvedArea? = null,
    val token: String? = null
)

data class ResolvedArea(
    val id: Int,
    val name: String,
    val level: String
)

data class RiderProfile(
    val id: Int,
    val name: String,
    val email: String?,
    val mobile: String?,
    val status: String,
    @SerializedName("rejection_reason") val rejectionReason: String? = null
)

// ---- rider/me (backend/api/v1/rider/me.php) ----

/**
 * Response from GET /api/v1/rider/me — used by ApplicationStatusActivity's
 * Refresh button to re-check current status without forcing a full logout
 * + OTP re-login. Includes the service area name so the status screen
 * can show it as a confirmation of where the rider signed up.
 */
data class RiderMeResult(
    val rider: RiderMeProfile,
    val status: String
)

data class RiderMeProfile(
    val id: Int,
    val name: String,
    val email: String,
    val mobile: String?,
    val status: String,
    @SerializedName("rejection_reason") val rejectionReason: String? = null,
    @SerializedName("service_area_id") val serviceAreaId: Int? = null,
    @SerializedName("service_area_name") val serviceAreaName: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
    // Phase 3 (doc 83) additions — additive only, everything above is unchanged.
    @SerializedName("is_online") val isOnline: Boolean = false,
    @SerializedName("vehicle_type") val vehicleType: String? = null,
    @SerializedName("vehicle_number") val vehicleNumber: String? = null
)

// ---- Phase 3: dashboard online toggle + location ping (doc 83) ----

data class OnlineStatusBody(val online: Boolean)

data class OnlineStatusResult(@SerializedName("is_online") val isOnline: Boolean)

data class LocationBody(val lat: Double, val lng: Double)

/** Generic {"ok": true} shape — used by endpoints with nothing else to return. */
data class OkResult(val ok: Boolean)

// ---- Service areas (backend/api/v1/system/service-areas.php) ----

data class ServiceArea(
    val id: Int,
    @SerializedName("parent_id") val parentId: Int?,
    val level: String,
    val name: String
)

data class ServiceAreasResult(
    val areas: List<ServiceArea>
)
