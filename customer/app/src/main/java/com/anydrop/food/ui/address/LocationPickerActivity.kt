package com.anydrop.food.ui.address

import android.app.AlertDialog
import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import android.os.Bundle
import android.os.Looper
import android.view.View
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.anydrop.food.R
import com.anydrop.food.data.ActiveAddressManager
import com.anydrop.food.databinding.ActivityLocationPickerBinding
import com.anydrop.food.network.Address
import com.anydrop.food.network.ApiClient
import com.anydrop.food.ui.common.InAppNotifier
import kotlinx.coroutines.launch

/**
 * H6 — Location Picker (screenshot 12). Entry point for delivery-address
 * selection: opens on app-open when no address is resolved yet (future
 * wiring — see features.md §H6), and now also from Home's location bar tap
 * (replacing the old direct-to-AddressEditorBottomSheet behaviour).
 *
 * This session builds the picker screen itself — "Add Address" here still
 * opens the existing AddressEditorBottomSheet form. The Zomato-style
 * full-screen map pin-drop screen (screenshot 13) is deliberately deferred
 * to a following session (needs the OSM/osmdroid dependency, which isn't
 * in the project yet) — see docs/features.md §H6 and the handover doc for
 * that piece specifically.
 *
 * Search bar and "NEARBY LOCATIONS" are stubbed per the H6 spec's explicit
 * allowance ("can be stubbed/non-functional first pass — don't block on
 * it") — only "Use current location", "Add Address", and the saved
 * addresses list (with tap-to-activate) are wired live this session.
 */
class LocationPickerActivity : AppCompatActivity(), AddressEditorBottomSheet.LocationRequester {

    private lateinit var binding: ActivityLocationPickerBinding
    private val api by lazy { ApiClient.create(this) }

    private var addresses: List<Address> = emptyList()

    // Set while a location fix is being resolved on behalf of an open
    // AddressEditorBottomSheet (same pattern as Checkout/AddressBook) — but
    // also doubles as "waiting for the plain current-location resolve" via
    // resolvingPlainLocation below, since both share fetchCurrentLocation().
    private var pendingSheetForLocation: AddressEditorBottomSheet? = null
    private var resolvingPlainLocation = false

    /** True only while resolving a fix on behalf of an actual "Use current
     * location" row tap — set right before requestCurrentLocationRow(false)
     * kicks off resolution, cleared once that resolution completes (success
     * or failure). Keeps the silent on-open auto-resolve (distance lines
     * only) from also activating live location as the delivery address. */
    private var explicitRowTap = false

    private val addAddressLauncher =
        registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
            if (result.resultCode == RESULT_OK) loadAddresses()
        }

    private val locationPermissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
            if (granted) fetchCurrentLocation() else {
                resolvingPlainLocation = false
                pendingSheetForLocation = null
                explicitRowTap = false
                binding.currentLocationSubtitle.text = getString(R.string.location_picker_use_current_location)
                InAppNotifier.show(this, "Location permission denied", InAppNotifier.Type.INFO)
            }
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityLocationPickerBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }

        binding.rowUseCurrentLocation.setOnClickListener {
            explicitRowTap = true
            requestCurrentLocationRow()
        }
        // Map-first flow (H6 part 2) now that MapPinDropActivity exists —
        // replaces the direct-to-AddressEditorBottomSheet path this row
        // used during part 1. The editor sheet itself is untouched and
        // still reachable from "..." on an existing saved address (see
        // openEditor(address) below) for straight text-field edits.
        binding.rowAddAddress.setOnClickListener {
            addAddressLauncher.launch(Intent(this, MapPinDropActivity::class.java))
        }

        loadAddresses()
        // Resolve a fix up front too, so distance lines are already filled
        // in by the time the user looks at the saved-addresses list instead
        // of only after they explicitly tap "Use current location".
        requestCurrentLocationRow(silent = true)
    }

    private fun loadAddresses() {
        binding.locationPickerLoading.visibility = View.VISIBLE
        lifecycleScope.launch {
            try {
                addresses = api.getAddresses().body()?.data?.addresses.orEmpty()
                renderSavedAddresses()
            } catch (e: Exception) {
                InAppNotifier.show(this@LocationPickerActivity, "Couldn't load addresses", InAppNotifier.Type.ERROR)
            } finally {
                binding.locationPickerLoading.visibility = View.GONE
            }
        }
    }

    /** Plain `LinearLayout` + manually-inflated rows, not a RecyclerView —
     * this section sits inside the picker's single scrolling page alongside
     * the search bar / current-location row / nearby section, so a nested
     * RecyclerView isn't worth the scroll-conflict handling for what's
     * normally a handful of saved addresses. */
    private fun renderSavedAddresses() {
        binding.savedAddressesContainer.removeAllViews()
        binding.emptySavedAddressesText.visibility = if (addresses.isEmpty()) View.VISIBLE else View.GONE

        val inflater = layoutInflater
        addresses.forEach { address ->
            val row = inflater.inflate(
                R.layout.item_saved_address_picker,
                binding.savedAddressesContainer,
                false
            )
            bindSavedAddressRow(row, address)
            binding.savedAddressesContainer.addView(row)
        }
    }

    private fun bindSavedAddressRow(row: View, address: Address) {
        val label = row.findViewById<android.widget.TextView>(R.id.savedAddressLabel)
        val distance = row.findViewById<android.widget.TextView>(R.id.savedAddressDistance)
        val full = row.findViewById<android.widget.TextView>(R.id.savedAddressFull)
        val phone = row.findViewById<android.widget.TextView>(R.id.savedAddressPhone)
        val typeIcon = row.findViewById<android.widget.ImageView>(R.id.savedAddressTypeIcon)
        val btnShare = row.findViewById<android.widget.ImageView>(R.id.btnShareAddress)
        val btnMore = row.findViewById<android.widget.ImageView>(R.id.btnMoreAddress)

        label.text = address.label ?: address.addressType.replaceFirstChar { it.uppercase() }
        typeIcon.setImageResource(
            when (address.addressType) {
                "work" -> R.drawable.ic_work
                else -> R.drawable.ic_home
            }
        )
        val houseFlat = address.houseFlatNo?.let { "$it, " } ?: ""
        full.text = "$houseFlat${address.fullAddress}"

        if (!address.receiverPhone.isNullOrBlank()) {
            phone.text = address.receiverPhone
            phone.visibility = View.VISIBLE
        } else {
            phone.visibility = View.GONE
        }

        val fixLat = lastResolvedLat
        val fixLng = lastResolvedLng
        if (fixLat != null && fixLng != null && address.latitude != null && address.longitude != null) {
            val km = com.anydrop.food.util.DistanceUtil.km(fixLat, fixLng, address.latitude, address.longitude)
            distance.text = com.anydrop.food.util.DistanceUtil.formatDistance(km)
            distance.visibility = View.VISIBLE
        } else {
            distance.visibility = View.GONE
        }

        row.setOnClickListener { activateAddress(address) }
        btnShare.setOnClickListener { shareAddress(address) }
        btnMore.setOnClickListener { openEditor(address) }
    }

    private fun activateAddress(address: Address) {
        ActiveAddressManager.set(this, address)
        InAppNotifier.show(this, getString(R.string.location_picker_address_activated), InAppNotifier.Type.SUCCESS)
        setResult(RESULT_OK)
        finish()
    }

    private fun shareAddress(address: Address) {
        val houseFlat = address.houseFlatNo?.let { "$it, " } ?: ""
        val shareText = "$houseFlat${address.fullAddress}"
        val intent = Intent(Intent.ACTION_SEND).apply {
            type = "text/plain"
            putExtra(Intent.EXTRA_TEXT, shareText)
        }
        startActivity(Intent.createChooser(intent, getString(R.string.location_picker_saved_addresses)))
    }

    private fun openEditor(address: Address?) {
        val sheet = if (address != null) {
            AddressEditorBottomSheet.newInstance(address)
        } else {
            AddressEditorBottomSheet.newInstance()
        }
        sheet.onSaved = { loadAddresses() }
        sheet.show(supportFragmentManager, "address_editor_from_picker")
    }

    // ---- Current-location resolution ----
    // Same GPS-fix pattern as CheckoutActivity/AddressBookActivity — kept
    // local here rather than shared, matching how those two already each
    // have their own copy (see AddressBookActivity's kdoc on that).

    private var lastResolvedLat: Double? = null
    private var lastResolvedLng: Double? = null

    /** [silent] = true for the automatic on-open resolve (fills distance
     * lines without showing "resolving..."/toasts if it's still pending
     * when the user starts scrolling); false for an explicit row tap. */
    private fun requestCurrentLocationRow(silent: Boolean = false) {
        if (!silent) {
            binding.currentLocationSubtitle.text = getString(R.string.location_picker_resolving)
        }
        resolvingPlainLocation = true
        val fineGranted = ContextCompat.checkSelfPermission(
            this, Manifest.permission.ACCESS_FINE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED
        if (fineGranted) {
            fetchCurrentLocation()
        } else if (!silent) {
            locationPermissionLauncher.launch(Manifest.permission.ACCESS_FINE_LOCATION)
        } else {
            // Silent auto-resolve on open shouldn't itself trigger a
            // permission prompt — only the explicit row tap does.
            resolvingPlainLocation = false
        }
    }

    // ---- AddressEditorBottomSheet.LocationRequester (for "Add Address" → editor sheet) ----

    override fun requestLocationForAddressEditor(sheet: AddressEditorBottomSheet) {
        pendingSheetForLocation = sheet
        val fineGranted = ContextCompat.checkSelfPermission(
            this, Manifest.permission.ACCESS_FINE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED
        if (fineGranted) {
            fetchCurrentLocation()
        } else {
            locationPermissionLauncher.launch(Manifest.permission.ACCESS_FINE_LOCATION)
        }
    }

    private fun fetchCurrentLocation() {
        val locationManager = getSystemService(LOCATION_SERVICE) as LocationManager
        val hasGps = locationManager.isProviderEnabled(LocationManager.GPS_PROVIDER)
        val hasNetwork = locationManager.isProviderEnabled(LocationManager.NETWORK_PROVIDER)
        if (!hasGps && !hasNetwork) {
            resolvingPlainLocation = false
            pendingSheetForLocation = null
            binding.currentLocationSubtitle.text = getString(R.string.location_picker_use_current_location)
            // Phase I — GPS-off flow. The old behaviour was a toast that
            // dead-ended (no live fix possible, nothing else offered). Only
            // show the explicit choice for a real row tap; the silent
            // on-open auto-resolve should just quietly give up, same as
            // before, since the user hasn't asked for anything yet.
            if (explicitRowTap) {
                explicitRowTap = false
                showLocationServicesOffChoice()
            }
            return
        }
        val provider = if (hasGps) LocationManager.GPS_PROVIDER else LocationManager.NETWORK_PROVIDER
        try {
            val lastKnown = locationManager.getLastKnownLocation(provider)
            if (lastKnown != null) {
                onLocationResolved(lastKnown)
            } else {
                locationManager.requestSingleUpdate(provider, { location -> onLocationResolved(location) }, Looper.getMainLooper())
            }
        } catch (e: SecurityException) {
            resolvingPlainLocation = false
            pendingSheetForLocation = null
            explicitRowTap = false
            binding.currentLocationSubtitle.text = getString(R.string.location_picker_use_current_location)
            InAppNotifier.show(this, "Location permission needed", InAppNotifier.Type.INFO)
        }
    }

    /** Phase I — explicit choice when neither GPS nor network location is
     * on: let the user either jump to system Location settings and retry,
     * or fall back to picking a saved address instead of silently stalling
     * on a row that can never resolve. Only saved addresses are offered as
     * the fallback (not "browse without an address") since that's already
     * one tap away via back + the saved-addresses list below. */
    private fun showLocationServicesOffChoice() {
        if (isFinishing || isDestroyed) return
        val hasSavedAddresses = addresses.isNotEmpty()
        val builder = AlertDialog.Builder(this)
            .setTitle(getString(R.string.location_services_off_title))
            .setMessage(
                if (hasSavedAddresses) {
                    getString(R.string.location_services_off_message_with_saved)
                } else {
                    getString(R.string.location_services_off_message_no_saved)
                }
            )
            .setPositiveButton(getString(R.string.location_services_off_turn_on)) { _, _ ->
                startActivity(Intent(android.provider.Settings.ACTION_LOCATION_SOURCE_SETTINGS))
            }
        if (hasSavedAddresses) {
            builder.setNegativeButton(getString(R.string.location_services_off_choose_saved)) { dialog, _ ->
                dialog.dismiss()
                // Saved-addresses list is already the rest of this screen —
                // nothing further to navigate to, just let the user tap a row.
            }
        } else {
            builder.setNegativeButton(android.R.string.cancel, null)
        }
        builder.show()
    }

    private fun onLocationResolved(location: Location) {
        lastResolvedLat = location.latitude
        lastResolvedLng = location.longitude
        // Re-render so distance lines pick up the fresh fix.
        renderSavedAddresses()

        val sheet = pendingSheetForLocation
        pendingSheetForLocation = null
        var addressLine: String? = null
        try {
            val geocoder = android.location.Geocoder(this, java.util.Locale.getDefault())
            @Suppress("DEPRECATION")
            val results = geocoder.getFromLocation(location.latitude, location.longitude, 1)
            addressLine = results?.firstOrNull()?.getAddressLine(0)
        } catch (e: Exception) {
            // Non-fatal — sheet still gets lat/lng without a readable line;
            // the plain row below falls back to raw lat/lng text.
        }

        if (sheet != null && sheet.isAdded) {
            sheet.applyResolvedLocation(location.latitude, location.longitude, addressLine)
        }

        if (resolvingPlainLocation) {
            resolvingPlainLocation = false
            binding.currentLocationSubtitle.text = addressLine
                ?: "%.4f, %.4f".format(location.latitude, location.longitude)
        }

        // Phase I fix — the row tap used to only fill the subtitle/distance
        // lines and never actually made "current location" a deliverable
        // address; ActiveAddressManager.set() required a saved-address row
        // with a real id, which a raw GPS fix doesn't have. explicitRowTap
        // is only true for a genuine tap (not the silent on-open resolve,
        // which would otherwise activate live location before the user
        // chose anything).
        if (explicitRowTap) {
            explicitRowTap = false
            ActiveAddressManager.setLiveLocation(this, location.latitude, location.longitude, addressLine)
            InAppNotifier.show(this, getString(R.string.location_picker_address_activated), InAppNotifier.Type.SUCCESS)
            setResult(RESULT_OK)
            finish()
        }
    }
}
