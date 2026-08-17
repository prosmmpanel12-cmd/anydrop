package com.anydrop.restaurant.ui.common

import android.app.Activity
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.net.Uri
import android.os.Bundle
import androidx.exifinterface.media.ExifInterface
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ActivityCropBinding
import com.google.android.material.chip.Chip
import java.io.File
import java.io.FileOutputStream

/**
 * Crop screen (app-owner feedback item #2, 2026-08-17 — "logo/dish photo
 * upload ke time crop option dikhna chahiye, konsa ratio sahi hai kitna
 * hissa crop hoga"). Sits between an image picker (GetContent()) and the
 * existing upload code in every photo-upload flow — EditProfileActivity's
 * logo picker, and MenuFragment's dish-photo and category-photo pickers.
 *
 * Usage: launch with [start], read the result back with [getResultUri] in
 * onActivityResult (or an ActivityResultContracts.StartActivityForResult
 * launcher, same pattern EditProfileActivity already uses for
 * LocationPickerActivity). On confirm this activity writes a cropped JPEG
 * into the caller's own cache dir and returns a plain `file://` Uri —
 * deliberately not a FileProvider content:// Uri, since this file only
 * ever needs to be read back inside this same app process (via
 * ContentResolver.openInputStream(), same as every existing
 * uploadXxxPhoto() function already does for GetContent() Uris) and never
 * shared to another app, so the extra FileProvider
 * manifest/xml/authority setup isn't needed.
 *
 * Does not itself upload anything — same "crop stages a local file, the
 * caller's existing Save button decides when to actually multipart-upload
 * it" split every picker flow in this app already follows for
 * cancel-safety.
 */
class CropActivity : Activity() {

    private lateinit var binding: ActivityCropBinding
    private var sourceBitmap: Bitmap? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityCropBinding.inflate(layoutInflater)
        setContentView(binding.root)

        val inputUriString = intent.getStringExtra(EXTRA_INPUT_URI)
        val slot = intent.getStringExtra(EXTRA_SLOT) ?: SLOT_SQUARE_ONLY
        val inputUri = inputUriString?.let { Uri.parse(it) }

        if (inputUri == null) {
            finish()
            return
        }

        val bitmap = decodeSampledBitmap(inputUri)
        if (bitmap == null) {
            InAppNotifier.show(this, getString(R.string.crop_processing_error), InAppNotifier.Type.ERROR)
            finish()
            return
        }
        sourceBitmap = bitmap
        binding.cropImageView.setImageBitmap(bitmap)

        setupRatioChips(slot)

        binding.btnCancelCrop.setOnClickListener {
            setResult(RESULT_CANCELED)
            finish()
        }
        binding.btnConfirmCrop.setOnClickListener { confirmCrop() }
    }

    /**
     * Which ratio chips a given upload slot offers. A dish photo genuinely
     * benefits from a choice (a plated dish often reads better in 4:3 than
     * forced-square), whereas a logo and a category icon are always shown
     * as a small circle/square everywhere in both apps — offering other
     * ratios there would just let an owner produce a crop that gets
     * re-squished oddly at every display site, so those two stay
     * square-only (still shown as a chip, so it's clear *which* ratio is
     * being applied, not hidden).
     */
    private fun setupRatioChips(slot: String) {
        val ratios: List<Pair<String, Float>> = when (slot) {
            SLOT_DISH_PHOTO -> listOf(
                getString(R.string.crop_ratio_square) to 1f,
                getString(R.string.crop_ratio_standard) to 4f / 3f,
                getString(R.string.crop_ratio_portrait) to 4f / 5f
            )
            SLOT_BANNER -> listOf(
                getString(R.string.crop_ratio_landscape) to 16f / 9f,
                getString(R.string.crop_ratio_standard) to 4f / 3f
            )
            else -> listOf(getString(R.string.crop_ratio_square) to 1f)
        }

        binding.ratioChipGroup.removeAllViews()
        ratios.forEachIndexed { index, (label, ratio) ->
            val chip = Chip(this).apply {
                text = label
                isCheckable = true
                isChecked = index == 0
                setOnClickListener { binding.cropImageView.aspectRatio = ratio }
            }
            binding.ratioChipGroup.addView(chip)
        }
        binding.cropImageView.aspectRatio = ratios.first().second

        // Single-ratio slots (logo/category icon): still show the one chip
        // pre-selected so the ratio is visible, but there's nothing else
        // to switch to — no extra handling needed beyond what's above.
    }

    private fun confirmCrop() {
        val cropped = binding.cropImageView.getCroppedBitmap()
        if (cropped == null) {
            InAppNotifier.show(this, getString(R.string.crop_processing_error), InAppNotifier.Type.ERROR)
            return
        }
        val outFile = File(cacheDir, "crop_${System.currentTimeMillis()}.jpg")
        try {
            FileOutputStream(outFile).use { out ->
                cropped.compress(Bitmap.CompressFormat.JPEG, 90, out)
            }
        } catch (e: Exception) {
            InAppNotifier.show(this, getString(R.string.crop_processing_error), InAppNotifier.Type.ERROR)
            return
        }

        val result = Intent().apply { putExtra(EXTRA_RESULT_URI, Uri.fromFile(outFile).toString()) }
        setResult(RESULT_OK, result)
        finish()
    }

    /**
     * Downsamples the source image before decoding a full Bitmap — a
     * phone-camera photo can be 12MP+ and this view never needs more than
     * a couple thousand px on a side, so decoding it at full resolution
     * would risk an OOM for no visible benefit. Also corrects EXIF
     * rotation, since GetContent() Uris from the camera/gallery are
     * frequently stored sideways with only the EXIF tag indicating the
     * intended orientation.
     */
    private fun decodeSampledBitmap(uri: Uri): Bitmap? {
        return try {
            val boundsOptions = BitmapFactory.Options().apply { inJustDecodeBounds = true }
            contentResolver.openInputStream(uri)?.use {
                BitmapFactory.decodeStream(it, null, boundsOptions)
            }
            val maxDimen = 2048
            var sampleSize = 1
            while (boundsOptions.outWidth / sampleSize > maxDimen || boundsOptions.outHeight / sampleSize > maxDimen) {
                sampleSize *= 2
            }

            val decodeOptions = BitmapFactory.Options().apply { inSampleSize = sampleSize }
            val rawBitmap = contentResolver.openInputStream(uri)?.use {
                BitmapFactory.decodeStream(it, null, decodeOptions)
            } ?: return null

            applyExifRotation(uri, rawBitmap)
        } catch (e: Exception) {
            null
        }
    }

    private fun applyExifRotation(uri: Uri, bitmap: Bitmap): Bitmap {
        val orientation = try {
            contentResolver.openInputStream(uri)?.use { ExifInterface(it).getAttributeInt(
                ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL
            ) } ?: ExifInterface.ORIENTATION_NORMAL
        } catch (e: Exception) {
            ExifInterface.ORIENTATION_NORMAL
        }

        val degrees = when (orientation) {
            ExifInterface.ORIENTATION_ROTATE_90 -> 90f
            ExifInterface.ORIENTATION_ROTATE_180 -> 180f
            ExifInterface.ORIENTATION_ROTATE_270 -> 270f
            else -> 0f
        }
        if (degrees == 0f) return bitmap

        val matrix = Matrix().apply { postRotate(degrees) }
        return Bitmap.createBitmap(bitmap, 0, 0, bitmap.width, bitmap.height, matrix, true)
    }

    companion object {
        private const val EXTRA_INPUT_URI = "extra_input_uri"
        private const val EXTRA_SLOT = "extra_slot"
        private const val EXTRA_RESULT_URI = "extra_result_uri"

        /** Logo, category icon — always square everywhere it's displayed. */
        const val SLOT_SQUARE_ONLY = "square_only"

        /** Menu item / dish photo — square, 4:3, or 4:5, owner's choice. */
        const val SLOT_DISH_PHOTO = "dish_photo"

        /** Restaurant banner (item #3) — wide 16:9 or 4:3. */
        const val SLOT_BANNER = "banner"

        fun start(context: Context, imageUri: Uri, slot: String, launcher: androidx.activity.result.ActivityResultLauncher<Intent>) {
            val intent = Intent(context, CropActivity::class.java).apply {
                putExtra(EXTRA_INPUT_URI, imageUri.toString())
                putExtra(EXTRA_SLOT, slot)
            }
            launcher.launch(intent)
        }

        /** Reads the cropped file Uri back out of an onActivityResult/launcher callback's [Intent], or null if the crop was cancelled. */
        fun getResultUri(data: Intent?): Uri? {
            val raw = data?.getStringExtra(EXTRA_RESULT_URI) ?: return null
            return Uri.parse(raw)
        }
    }
}
