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
    val address: String?
) : Parcelable
