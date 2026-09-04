package com.anydrop.food.ui.profile

import android.app.AlertDialog
import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.anydrop.food.R
import com.anydrop.food.databinding.ActivitySimpleListBinding
import com.anydrop.food.network.Address
import com.anydrop.food.network.ApiClient
import com.anydrop.food.ui.address.AddressEditorBottomSheet
import com.anydrop.food.ui.common.InAppNotifier
import kotlinx.coroutines.launch

/**
 * Profile → Address Book (§2.7). Reuses AddressEditorBottomSheet from
 * step 11 as-is for both add (tap the + in the header) and edit (tap Edit
 * on a card) — no new form code needed, same sheet Checkout already uses.
 */
class AddressBookActivity : AppCompatActivity(), AddressEditorBottomSheet.LocationRequester {

    private lateinit var binding: ActivitySimpleListBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var adapter: AddressAdapter
    private var pendingSheetForLocation: AddressEditorBottomSheet? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySimpleListBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.screenTitle.text = getString(R.string.address_book_title)
        binding.btnBack.setOnClickListener { finish() }

        binding.btnAction.setImageResource(R.drawable.ic_add)
        binding.btnAction.visibility = android.view.View.VISIBLE
        binding.btnAction.setOnClickListener { openEditor(null) }

        adapter = AddressAdapter(
            onEdit = { address -> openEditor(address) },
            onDelete = { address -> confirmDelete(address) },
            onActivate = { address -> activateAddress(address) },
            onSetDefault = { address -> setDefaultAddress(address) }
        )
        binding.contentList.layoutManager = LinearLayoutManager(this)
        binding.contentList.adapter = adapter

        binding.emptyStateText.text = getString(R.string.empty_addresses)
        binding.swipeRefresh.setOnRefreshListener { loadAddresses() }

        loadAddresses()
    }

    private fun openEditor(address: Address?) {
        val sheet = if (address != null) {
            AddressEditorBottomSheet.newInstance(address)
        } else {
            // isFirstAddress drives whether this new address auto-becomes
            // the account default (see AddressEditorBottomSheet kdoc) — the
            // adapter's current item count is the live truth for that.
            AddressEditorBottomSheet.newInstance(isFirstAddress = adapter.itemCount == 0)
        }
        sheet.onSaved = { loadAddresses() }
        sheet.show(supportFragmentManager, "address_editor")
    }

    // H6 — "tap card to activate" (features.md §7/§H6 spec) reuses the same
    // ActiveAddressManager.set() the Location Picker screen uses; Address
    // Book didn't have this concept before H6 (only edit/delete).
    private fun activateAddress(address: Address) {
        com.anydrop.food.data.ActiveAddressManager.set(this, address)
        InAppNotifier.show(this, "Delivering to this address now", InAppNotifier.Type.SUCCESS)
    }

    // Bug 6.2 — addresses.php's PUT handler calls
    // require_fields($body, ['full_address']) before touching anything
    // else, so a bare {"is_default": true} body gets rejected as
    // validation_error. Must send the address's full existing payload
    // (same fields AddressEditorBottomSheet's save already sends) with
    // is_default flipped to true, not just that one field alone. This is
    // deliberately separate from activateAddress() above — setting the
    // account-wide default must NOT also silently switch what's active on
    // Home right now; that stays a distinct, deliberate action via the
    // Location Picker.
    private fun setDefaultAddress(address: Address) {
        lifecycleScope.launch {
            try {
                val body = com.anydrop.food.network.AddAddressBody(
                    label = address.label,
                    addressType = address.addressType,
                    fullAddress = address.fullAddress,
                    houseFlatNo = address.houseFlatNo,
                    floor = address.floor,
                    landmark = address.landmark,
                    receiverName = address.receiverName,
                    receiverPhone = address.receiverPhone,
                    latitude = address.latitude,
                    longitude = address.longitude,
                    isDefault = true,
                    photoUrl = address.photoUrl
                )
                val response = api.updateAddress(address.id, body)
                if (response.isSuccessful && response.body()?.success == true) {
                    InAppNotifier.show(this@AddressBookActivity, "Default address updated", InAppNotifier.Type.SUCCESS)
                    // Re-fetch so the badge moves immediately — no stale
                    // state until the next manual refresh (spec requirement).
                    loadAddresses()
                } else {
                    InAppNotifier.show(this@AddressBookActivity, "Couldn't set default address", InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@AddressBookActivity, "Network error", InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun confirmDelete(address: Address) {
        AlertDialog.Builder(this)
            .setMessage(R.string.delete_address_confirm)
            .setPositiveButton(R.string.btn_delete) { _, _ -> deleteAddress(address) }
            .setNegativeButton(android.R.string.cancel, null)
            .show()
    }

    private fun deleteAddress(address: Address) {
        lifecycleScope.launch {
            try {
                val response = api.deleteAddress(address.id)
                if (response.isSuccessful && response.body()?.success == true) {
                    // Bug fix — deleting an address never used to touch
                    // ActiveAddressManager. If the address just deleted was
                    // also the on-device "active" delivery address, Home
                    // (and anywhere else reading ActiveAddressManager) kept
                    // right on using its cached lat/lng/label for a row that
                    // no longer exists server-side — screen looked
                    // completely unchanged, so it *looked* like delete had
                    // silently failed even though the server-side delete
                    // succeeded. Clearing it here forces every screen to
                    // re-resolve (or fall back to "no address") next time.
                    val active = com.anydrop.food.data.ActiveAddressManager.get(this@AddressBookActivity)
                    if (active != null && active.id == address.id) {
                        com.anydrop.food.data.ActiveAddressManager.clear(this@AddressBookActivity)
                    }
                    InAppNotifier.show(this@AddressBookActivity, "Address deleted", InAppNotifier.Type.SUCCESS)
                    loadAddresses(afterDelete = true)
                } else {
                    InAppNotifier.show(this@AddressBookActivity, "Couldn't delete address", InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@AddressBookActivity, "Network error", InAppNotifier.Type.ERROR)
            }
        }
    }

    /** [afterDelete] is only true right after a successful delete — that's
     * the one case where landing on an empty list should immediately pull up
     * the add-address flow (which already has "Use current location" wired
     * in via this Activity's LocationRequester implementation below) rather
     * than stranding the user on a bare empty state they have to notice and
     * tap into themselves. A plain refresh/initial load that happens to be
     * empty (e.g. brand-new account) still just shows the empty state. */
    private fun loadAddresses(afterDelete: Boolean = false) {
        binding.swipeRefresh.isRefreshing = true
        lifecycleScope.launch {
            try {
                val addresses = api.getAddresses().body()?.data?.addresses ?: emptyList()
                adapter.submit(addresses)
                val isEmpty = addresses.isEmpty()
                binding.emptyState.visibility = if (isEmpty) android.view.View.VISIBLE else android.view.View.GONE
                binding.contentList.visibility = if (isEmpty) android.view.View.GONE else android.view.View.VISIBLE
                if (afterDelete && isEmpty) {
                    InAppNotifier.show(
                        this@AddressBookActivity,
                        "Add a delivery address to keep ordering",
                        InAppNotifier.Type.INFO
                    )
                    openEditor(null)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@AddressBookActivity, "Couldn't load addresses", InAppNotifier.Type.ERROR)
            } finally {
                binding.swipeRefresh.isRefreshing = false
            }
        }
    }

    // ---- AddressEditorBottomSheet.LocationRequester (same pattern as CheckoutActivity) ----

    override fun requestLocationForAddressEditor(sheet: AddressEditorBottomSheet) {
        pendingSheetForLocation = sheet
        val fineGranted = androidx.core.content.ContextCompat.checkSelfPermission(
            this, android.Manifest.permission.ACCESS_FINE_LOCATION
        ) == android.content.pm.PackageManager.PERMISSION_GRANTED
        if (fineGranted) {
            fetchCurrentLocation()
        } else {
            locationPermissionLauncher.launch(android.Manifest.permission.ACCESS_FINE_LOCATION)
        }
    }

    private val locationPermissionLauncher =
        registerForActivityResult(androidx.activity.result.contract.ActivityResultContracts.RequestPermission()) { granted ->
            if (granted) fetchCurrentLocation() else {
                InAppNotifier.show(this, "Location permission denied", InAppNotifier.Type.INFO)
                pendingSheetForLocation = null
            }
        }

    private fun fetchCurrentLocation() {
        val locationManager = getSystemService(LOCATION_SERVICE) as android.location.LocationManager
        val hasGps = locationManager.isProviderEnabled(android.location.LocationManager.GPS_PROVIDER)
        val hasNetwork = locationManager.isProviderEnabled(android.location.LocationManager.NETWORK_PROVIDER)
        if (!hasGps && !hasNetwork) {
            InAppNotifier.show(this, "Turn on location services to use this", InAppNotifier.Type.INFO)
            pendingSheetForLocation = null
            return
        }
        val provider = if (hasGps) android.location.LocationManager.GPS_PROVIDER else android.location.LocationManager.NETWORK_PROVIDER
        try {
            val lastKnown = locationManager.getLastKnownLocation(provider)
            if (lastKnown != null) {
                onLocationResolved(lastKnown)
            } else {
                locationManager.requestSingleUpdate(provider, { location -> onLocationResolved(location) }, android.os.Looper.getMainLooper())
            }
        } catch (e: SecurityException) {
            InAppNotifier.show(this, "Location permission needed", InAppNotifier.Type.INFO)
            pendingSheetForLocation = null
        }
    }

    private fun onLocationResolved(location: android.location.Location) {
        val sheet = pendingSheetForLocation
        pendingSheetForLocation = null
        var addressLine: String? = null
        try {
            val geocoder = android.location.Geocoder(this, java.util.Locale.getDefault())
            @Suppress("DEPRECATION")
            val results = geocoder.getFromLocation(location.latitude, location.longitude, 1)
            addressLine = results?.firstOrNull()?.getAddressLine(0)
        } catch (e: Exception) {
            // Non-fatal — sheet still gets lat/lng without a readable line.
        }
        if (sheet != null && sheet.isAdded) {
            sheet.applyResolvedLocation(location.latitude, location.longitude, addressLine)
            InAppNotifier.show(this, "Current location filled in — edit if needed", InAppNotifier.Type.SUCCESS)
        }
    }
}
