package com.anydrop.rider.ui.documents

import android.content.res.ColorStateList
import android.net.Uri
import android.os.Bundle
import android.provider.OpenableColumns
import android.view.View
import android.widget.TextView
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.anydrop.rider.R
import com.anydrop.rider.data.TokenManager
import com.anydrop.rider.databinding.ActivitySubmitDocumentsBinding
import com.anydrop.rider.network.ApiClient
import com.anydrop.rider.network.RiderDocumentsResult
import com.anydrop.rider.ui.common.InAppNotifier
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.File
import java.io.FileOutputStream

/**
 * Deep-plan §22, migration 75 — submit/re-submit compliance documents.
 * Reachable from ApplicationStatusActivity (pending/rejected rider —
 * "Complete Profile") and RiderDashboardActivity (approved rider whose
 * documents were rejected and need re-submission), per the two entry
 * points the app owner confirmed before this feature's Android work
 * began (see the Rider Documents handover's "Research already done"
 * section).
 *
 * No image thumbnails — see this screen's own layout comment for why
 * (no image-loading library in this app yet, and documents-view.php's
 * Bearer-token auth isn't something a plain <img>/Coil load can send
 * anyway). Existing submissions are represented as text state
 * ("Already on file") rather than a preview.
 *
 * id_doc is REQUIRED on every submit call, even a re-submission —
 * documents-upload.php enforces this server-side (see its own kdoc:
 * an admin needs at least one document to review at all), so this
 * screen always requires a fresh pick before Submit is enabled,
 * regardless of hasIdDoc from the initial load.
 *
 * Multipart upload follows the same copy-content-Uri-to-a-cache-file
 * pattern as the Restaurant app's EditProfileActivity.uploadLogo() —
 * content Uris from GetContent() aren't guaranteed to expose a real
 * filesystem path — extended here to also accept application/pdf for
 * the two document fields (a profile photo stays image-only).
 */
class SubmitDocumentsActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySubmitDocumentsBinding
    private lateinit var tokenManager: TokenManager
    private val api by lazy { ApiClient.create(this) }

    private var pickedIdDocUri: Uri? = null
    private var pickedVehicleDocUri: Uri? = null
    private var pickedProfilePhotoUri: Uri? = null
    private var submitInFlight = false

    // Documents may reasonably be a scanned PDF (matches
    // documents-upload.php's $allowedDoc); the profile photo picker
    // below restricts to image/* only.
    private val pickIdDocLauncher =
        registerForActivityResult(ActivityResultContracts.GetContent()) { uri ->
            if (uri != null) {
                pickedIdDocUri = uri
                showSelectedFile(binding.idDocSelectedText, uri)
            }
        }

    private val pickVehicleDocLauncher =
        registerForActivityResult(ActivityResultContracts.GetContent()) { uri ->
            if (uri != null) {
                pickedVehicleDocUri = uri
                showSelectedFile(binding.vehicleDocSelectedText, uri)
            }
        }

    private val pickProfilePhotoLauncher =
        registerForActivityResult(ActivityResultContracts.GetContent()) { uri ->
            if (uri != null) {
                pickedProfilePhotoUri = uri
                showSelectedFile(binding.profilePhotoSelectedText, uri)
            }
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySubmitDocumentsBinding.inflate(layoutInflater)
        setContentView(binding.root)

        tokenManager = TokenManager(this)

        binding.btnBack.setOnClickListener { finish() }
        binding.btnPickIdDoc.setOnClickListener { pickIdDocLauncher.launch("*/*") }
        binding.btnPickVehicleDoc.setOnClickListener { pickVehicleDocLauncher.launch("*/*") }
        binding.btnPickProfilePhoto.setOnClickListener { pickProfilePhotoLauncher.launch("image/*") }
        binding.btnSubmitDocuments.setOnClickListener { submit() }

        loadCurrentState()
    }

    private fun loadCurrentState() {
        lifecycleScope.launch {
            try {
                val response = api.getRiderDocuments()
                if (response.isSuccessful && response.body()?.success == true) {
                    val result = response.body()?.data ?: return@launch
                    tokenManager.updateDocumentsStatus(result.documentsStatus)
                    renderCurrentState(result)
                } else {
                    InAppNotifier.show(this@SubmitDocumentsActivity, getString(R.string.documents_load_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@SubmitDocumentsActivity, getString(R.string.documents_load_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun renderCurrentState(result: RiderDocumentsResult) {
        val (pillBg, pillFg, pillText) = when (result.documentsStatus) {
            "verified" -> Triple(R.color.doc_verified_bg, R.color.doc_verified_fg, getString(R.string.documents_status_verified))
            "rejected" -> Triple(R.color.doc_rejected_bg, R.color.doc_rejected_fg, getString(R.string.documents_status_rejected))
            "pending" -> Triple(R.color.doc_pending_bg, R.color.doc_pending_fg, getString(R.string.documents_status_pending))
            else -> Triple(R.color.doc_not_submitted_bg, R.color.doc_not_submitted_fg, getString(R.string.documents_status_not_submitted))
        }
        binding.statusPill.text = pillText
        binding.statusPill.setTextColor(getColor(pillFg))
        binding.statusPill.backgroundTintList = ColorStateList.valueOf(getColor(pillBg))

        if (result.documentsStatus == "rejected" && !result.documentsRejectReason.isNullOrBlank()) {
            binding.statusReasonText.text = getString(R.string.documents_reason_format, result.documentsRejectReason)
            binding.statusReasonText.visibility = View.VISIBLE
        } else {
            binding.statusReasonText.visibility = View.GONE
        }

        binding.idDocCurrentText.visibility = if (result.hasIdDoc) View.VISIBLE else View.GONE
        binding.vehicleDocCurrentText.visibility = if (result.hasVehicleDoc) View.VISIBLE else View.GONE
        binding.profilePhotoCurrentText.visibility = if (!result.profilePhotoUrl.isNullOrBlank()) View.VISIBLE else View.GONE

        // Pre-fill only if the field is currently empty — avoids clobbering
        // anything the rider has already started typing if this ever gets
        // called again (it doesn't today, but same defensive stance as
        // EditProfileActivity's populate() taking values from the server
        // as a baseline, not an overwrite-on-every-call assumption).
        if (binding.vehicleTypeInput.text.isNullOrBlank()) {
            binding.vehicleTypeInput.setText(result.vehicleType.orEmpty())
        }
        if (binding.vehicleNumberInput.text.isNullOrBlank()) {
            binding.vehicleNumberInput.setText(result.vehicleNumber.orEmpty())
        }
    }

    private fun submit() {
        if (submitInFlight) return

        val idDocUri = pickedIdDocUri
        if (idDocUri == null) {
            InAppNotifier.show(this, getString(R.string.documents_error_id_doc_required), InAppNotifier.Type.ERROR)
            return
        }

        submitInFlight = true
        setSubmitLoading(true)

        lifecycleScope.launch {
            var idDocTemp: File? = null
            var vehicleDocTemp: File? = null
            var profilePhotoTemp: File? = null
            try {
                idDocTemp = copyToCacheFile(idDocUri, "id_doc")
                val idDocPart = filePartFor("id_doc", idDocTemp, idDocUri, allowPdf = true)
                if (idDocPart == null) {
                    InAppNotifier.show(this@SubmitDocumentsActivity, getString(R.string.documents_error_submit_failed), InAppNotifier.Type.ERROR)
                    return@launch
                }

                var vehicleDocPart: MultipartBody.Part? = null
                val vehicleDocUri = pickedVehicleDocUri
                if (vehicleDocUri != null) {
                    vehicleDocTemp = copyToCacheFile(vehicleDocUri, "vehicle_doc")
                    vehicleDocPart = filePartFor("vehicle_doc", vehicleDocTemp, vehicleDocUri, allowPdf = true)
                }

                var profilePhotoPart: MultipartBody.Part? = null
                val profilePhotoUri = pickedProfilePhotoUri
                if (profilePhotoUri != null) {
                    profilePhotoTemp = copyToCacheFile(profilePhotoUri, "profile_photo")
                    profilePhotoPart = filePartFor("profile_photo", profilePhotoTemp, profilePhotoUri, allowPdf = false)
                }

                val vehicleType = binding.vehicleTypeInput.text?.toString()?.trim().orEmpty()
                val vehicleNumber = binding.vehicleNumberInput.text?.toString()?.trim().orEmpty()

                val response = api.uploadRiderDocuments(
                    idDoc = idDocPart,
                    vehicleDoc = vehicleDocPart,
                    profilePhoto = profilePhotoPart,
                    vehicleType = vehicleType.takeIf { it.isNotEmpty() }?.toRequestBody("text/plain".toMediaTypeOrNull()),
                    vehicleNumber = vehicleNumber.takeIf { it.isNotEmpty() }?.toRequestBody("text/plain".toMediaTypeOrNull())
                )

                if (response.isSuccessful && response.body()?.success == true) {
                    val result = response.body()?.data
                    tokenManager.updateDocumentsStatus(result?.documentsStatus ?: "pending")
                    InAppNotifier.show(this@SubmitDocumentsActivity, getString(R.string.documents_submitted_success), InAppNotifier.Type.SUCCESS)
                    setResult(RESULT_OK)
                    finish()
                } else {
                    InAppNotifier.show(this@SubmitDocumentsActivity, getString(R.string.documents_error_submit_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@SubmitDocumentsActivity, getString(R.string.documents_error_submit_failed), InAppNotifier.Type.ERROR)
            } finally {
                idDocTemp?.delete()
                vehicleDocTemp?.delete()
                profilePhotoTemp?.delete()
                submitInFlight = false
                setSubmitLoading(false)
            }
        }
    }

    /** Copies a picked content Uri into a cache file so it can be wrapped
     *  in a real RequestBody — same reasoning as EditProfileActivity's
     *  uploadLogo(). Prefix distinguishes the three temp files from each
     *  other since they can coexist mid-submit. */
    private fun copyToCacheFile(uri: Uri, prefix: String): File? {
        val tempFile = File(cacheDir, "${prefix}_${System.currentTimeMillis()}")
        contentResolver.openInputStream(uri)?.use { input ->
            FileOutputStream(tempFile).use { output -> input.copyTo(output) }
        } ?: return null
        return tempFile
    }

    /** Builds the MultipartBody.Part for one of the three file fields.
     *  Extension/mime is derived from contentResolver.getType() the same
     *  way EditProfileActivity.uploadLogo() derives it for the logo field
     *  — the actual mime enforcement happens server-side via finfo (see
     *  documents-upload.php), this is just naming the part sensibly. */
    private fun filePartFor(fieldName: String, tempFile: File?, uri: Uri, allowPdf: Boolean): MultipartBody.Part? {
        if (tempFile == null) return null
        val mimeType = contentResolver.getType(uri) ?: "image/jpeg"
        val ext = when (mimeType) {
            "image/png" -> "png"
            "image/webp" -> "webp"
            "application/pdf" -> if (allowPdf) "pdf" else "jpg"
            else -> "jpg"
        }
        val requestBody = tempFile.asRequestBody(mimeType.toMediaTypeOrNull())
        return MultipartBody.Part.createFormData(fieldName, "$fieldName.$ext", requestBody)
    }

    private fun showSelectedFile(target: TextView, uri: Uri) {
        val name = queryFileName(uri) ?: uri.lastPathSegment ?: "file"
        target.text = getString(R.string.documents_file_selected_format, name)
        target.visibility = View.VISIBLE
    }

    /** Resolves a content Uri's display name via OpenableColumns, same
     *  approach any file-picker consumer on Android uses when it wants
     *  to show the user what they picked without holding onto the Uri's
     *  raw path (which content Uris don't reliably expose anyway). */
    private fun queryFileName(uri: Uri): String? {
        return try {
            contentResolver.query(uri, null, null, null, null)?.use { cursor ->
                val nameIndex = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME)
                if (nameIndex >= 0 && cursor.moveToFirst()) cursor.getString(nameIndex) else null
            }
        } catch (e: Exception) {
            null
        }
    }

    private fun setSubmitLoading(loading: Boolean) {
        binding.btnSubmitDocuments.isEnabled = !loading
        binding.documentsSubmitProgress.visibility = if (loading) View.VISIBLE else View.GONE
    }
}
