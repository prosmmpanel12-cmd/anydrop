package com.anydrop.restaurant.ui.account

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.GridLayoutManager
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ActivityBannerManagerBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.Banner
import com.anydrop.restaurant.network.BannerDeleteBody
import com.anydrop.restaurant.ui.common.CropActivity
import com.anydrop.restaurant.ui.common.InAppNotifier
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import java.io.File

/**
 * Restaurant Banners manager (app-owner feedback item #3, 2026-08-17 —
 * "restaurant open ke baad restaurant banners dikhenge yaha pe multiple
 * with a cool transition, agar 1 baner hi upload ho to usko fix rakho").
 * Launched from AccountFragment's new "Restaurant Banners" row.
 *
 * Unlike the logo/dish-photo pickers, each banner add/delete here is its
 * own immediate network call (list → add → delete), not staged locally
 * behind a form Save button — see banner-upload.php/banner-delete.php's
 * kdoc for why: banners are a standalone add/remove list, not a form
 * field. The on-screen carousel that customers actually see
 * (RestaurantBannerCarouselView, Customer app) auto-transitions between
 * 2+ banners and falls back to a single static image for exactly 1 — that
 * behaviour lives entirely on the Customer-app side; this screen is just
 * the owner's upload/manage UI and doesn't need to know or preview that
 * distinction itself.
 */
class BannerManagerActivity : AppCompatActivity() {

    private lateinit var binding: ActivityBannerManagerBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var adapter: BannerAdapter

    private val pickBannerLauncher =
        registerForActivityResult(ActivityResultContracts.GetContent()) { uri ->
            if (uri != null) {
                CropActivity.start(this, uri, CropActivity.SLOT_BANNER, cropBannerLauncher)
            }
        }

    private val cropBannerLauncher =
        registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
            if (result.resultCode == RESULT_OK) {
                val croppedUri = CropActivity.getResultUri(result.data) ?: return@registerForActivityResult
                uploadBanner(croppedUri)
            }
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityBannerManagerBinding.inflate(layoutInflater)
        setContentView(binding.root)

        adapter = BannerAdapter(onDelete = { banner -> confirmDelete(banner) })
        binding.bannerGrid.layoutManager = GridLayoutManager(this, 2)
        binding.bannerGrid.adapter = adapter

        binding.btnBack.setOnClickListener { finish() }
        binding.btnAddBanner.setOnClickListener { pickBannerLauncher.launch("image/*") }

        loadBanners()
    }

    private fun loadBanners() {
        lifecycleScope.launch {
            try {
                val response = api.getBanners()
                val banners = response.body()?.data?.banners
                if (response.isSuccessful && banners != null) {
                    adapter.submitList(banners)
                } else {
                    InAppNotifier.show(this@BannerManagerActivity, getString(R.string.banner_load_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@BannerManagerActivity, getString(R.string.banner_load_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun uploadBanner(uri: Uri) {
        lifecycleScope.launch {
            try {
                val mimeType = contentResolver.getType(uri) ?: "image/jpeg"
                val ext = when (mimeType) {
                    "image/png" -> "png"
                    "image/webp" -> "webp"
                    else -> "jpg"
                }
                val tempFile = File(cacheDir, "banner_upload.$ext")
                contentResolver.openInputStream(uri)?.use { input ->
                    tempFile.outputStream().use { output -> input.copyTo(output) }
                } ?: run {
                    InAppNotifier.show(this@BannerManagerActivity, getString(R.string.banner_upload_failed), InAppNotifier.Type.ERROR)
                    return@launch
                }

                val requestBody = tempFile.asRequestBody(mimeType.toMediaTypeOrNull())
                val part = MultipartBody.Part.createFormData("banner", tempFile.name, requestBody)
                val response = api.uploadBanner(part)
                tempFile.delete()

                val result = response.body()?.data
                if (response.isSuccessful && result != null) {
                    adapter.addBanner(Banner(id = result.id, imageUrl = result.imageUrl))
                } else {
                    InAppNotifier.show(this@BannerManagerActivity, getString(R.string.banner_upload_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@BannerManagerActivity, getString(R.string.banner_upload_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun confirmDelete(banner: Banner) {
        android.app.AlertDialog.Builder(this)
            .setMessage(R.string.banner_confirm_delete)
            .setPositiveButton(R.string.btn_delete) { _, _ -> deleteBanner(banner) }
            .setNegativeButton(R.string.btn_cancel, null)
            .show()
    }

    private fun deleteBanner(banner: Banner) {
        lifecycleScope.launch {
            try {
                val response = api.deleteBanner(BannerDeleteBody(id = banner.id))
                if (response.isSuccessful && response.body()?.success == true) {
                    adapter.removeBanner(banner.id)
                } else {
                    InAppNotifier.show(this@BannerManagerActivity, getString(R.string.banner_delete_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@BannerManagerActivity, getString(R.string.banner_delete_failed), InAppNotifier.Type.ERROR)
            }
        }
    }
}
