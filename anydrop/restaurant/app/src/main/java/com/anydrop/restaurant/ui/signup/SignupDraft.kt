package com.anydrop.restaurant.ui.signup

import android.os.Parcelable
import kotlinx.parcelize.Parcelize

/**
 * The full Step-1 signup form, carried as one Intent extra into
 * OtpVerifyActivity so the actual /auth/restaurant-signup.php call only
 * happens after OTP verification succeeds — nothing is submitted twice.
 */
@Parcelize
data class SignupDraft(
    val name: String,
    val ownerName: String,
    val ownerMobile: String,
    val email: String,
    val password: String,
    val address: String?,
    // Optional service-area pin (§0, 2026-08-28) — null when the owner
    // skipped the "Set restaurant location" row on SignupActivity. Carried
    // through OTP verify the same way the rest of the form is, only ever
    // sent to the backend in the final /auth/restaurant-signup.php call.
    val latitude: Double? = null,
    val longitude: Double? = null
) : Parcelable
