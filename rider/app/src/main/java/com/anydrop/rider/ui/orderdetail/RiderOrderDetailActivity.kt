package com.anydrop.rider.ui.orderdetail

import android.content.ActivityNotFoundException
import android.content.ColorStateList
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.View
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import coil.load
import com.anydrop.rider.R
import com.anydrop.rider.databinding.ActivityOrderDetailBinding
import com.anydrop.rider.network.ApiClient
import com.anydrop.rider.network.OrderDetail
import kotlinx.coroutines.launch

/**
 * Rider Order Detail (deep-plan §9) — dedicated read-only screen for a
 * rider's active delivery, reached by tapping "View Details" on
 * RiderDashboardActivity's current-order card. Built this session; no
 * prior version of this screen existed anywhere in the codebase (see
 * handover doc — checked before starting).
 *
 * Deliberately separate from the dashboard's own compact card rather
 * than expanding that card in place: the dashboard's job is "what do I
 * do right now" (one status-appropriate action button), this screen's
 * job is "everything about this delivery" (both addresses, both
 * contacts, the money breakdown) — different enough purposes that
 * combining them would make the dashboard card cluttered on every
 * status, not just when the rider actually wants the extra detail.
 *
 * Data comes from a new GET /rider/orders-detail endpoint, not
 * orders-current.php — that endpoint intentionally stays minimal for
 * the dashboard card (see its own kdoc); duplicating its query with
 * the extra joins this screen needs would either bloat that endpoint's
 * response for every dashboard poll or require a second call anyway,
 * so a purpose-built endpoint was the more honest choice.
 *
 * Navigate/Call are the first phone-dialer/maps intents anywhere in
 * this rider app (checked: no ACTION_DIAL/geo: precedent exists in any
 * of the three apps' rider-relevant code) — genuinely new plumbing,
 * not copy-adapted from an existing working screen. Call uses
 * ACTION_DIAL (opens the dialer pre-filled, rider still taps to place
 * the call) rather than ACTION_CALL specifically so no CALL_PHONE
 * runtime permission is needed — this app has never requested that
 * permission and adding it for one button was judged not worth a new
 * permission prompt on approval/first-launch flows elsewhere in the
 * app. Navigate opens Google Maps turn-by-turn via a google.navigation
 * intent when Maps is installed, falling back to a plain geo: query
 * (opens the device's app chooser) otherwise — same fallback shape
 * dispatch.php's own "never assume a rider environment detail" caution
 * suggested for anything rider-device-dependent.
 *
 * UPDATE (this session, address detail fields added) — house/floor/
 * landmark/receiver name+phone/door-photo, all sourced from
 * customer_addresses columns that already existed (migrations 06/16)
 * but neither this screen's first version nor orders-current.php ever
 * selected. Call now prefers the address's own receiver_phone over the
 * account holder's customer_phone when one is on file (the delivery
 * address may belong to someone other than the person who placed the
 * order). The door photo is this app's first-ever remote-image load —
 * see build.gradle's own comment on adding Coil for exactly this.
 */
class RiderOrderDetailActivity : AppCompatActivity() {

    private lateinit var binding: ActivityOrderDetailBinding
    private val api by lazy { ApiClient.create(this) }
    private var order: OrderDetail? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityOrderDetailBinding.inflate(layoutInflater)
        setContentView(binding.root)

        val orderId = intent.getIntExtra(EXTRA_ORDER_ID, -1)
        if (orderId <= 0) {
            // Launched incorrectly — nothing to show, don't leave a
            // broken screen open.
            finish()
            return
        }

        binding.btnBack.setOnClickListener { finish() }

        binding.btnNavigateRestaurant.setOnClickListener {
            order?.let { navigateTo(it.restaurantLat, it.restaurantLng, it.restaurantAddress) }
        }
        binding.btnCallRestaurant.setOnClickListener {
            order?.let { callNumber(it.restaurantPhone) }
        }
        binding.btnNavigateCustomer.setOnClickListener {
            order?.let { navigateTo(it.deliveryLat, it.deliveryLng, it.deliveryAddress) }
        }
        binding.btnCallCustomer.setOnClickListener {
            // Prefer the address's own receiver_phone when one is on file
            // (this address may belong to someone other than the account
            // holder — a gift order, an office address, etc. — receiver_
            // name/phone exist on customer_addresses specifically for
            // that case) — falls back to the account holder's own phone
            // otherwise, same as this screen already shows dropReceiver
            // only when a receiver name is actually set.
            order?.let { callNumber(it.receiverPhone ?: it.customerPhone) }
        }

        loadDetail(orderId)
    }

    // Same "screen might be stale after a status-changing action
    // elsewhere (pickup/deliver from the dashboard)" reasoning
    // EarningsActivity.onResume() already applies to its own balance
    // figure — a rider could background this screen, mark pickup from
    // a notification tap, then return here with a stale status pill.
    override fun onResume() {
        super.onResume()
        order?.let { loadDetail(it.id) }
    }

    private fun loadDetail(orderId: Int) {
        binding.orderDetailProgress.visibility = View.VISIBLE
        binding.orderDetailErrorText.visibility = View.GONE
        lifecycleScope.launch {
            try {
                val response = api.getOrderDetail(orderId)
                val result = if (response.isSuccessful) response.body()?.data?.order else null
                if (result != null) {
                    render(result)
                } else {
                    showError()
                }
            } catch (e: Exception) {
                showError()
            } finally {
                binding.orderDetailProgress.visibility = View.GONE
            }
        }
    }

    private fun showError() {
        // Order likely moved past the active-delivery statuses this
        // endpoint scopes to (e.g. delivered/cancelled from elsewhere
        // while this screen was open) or was never this rider's order
        // to begin with — either way, nothing here to keep showing.
        binding.orderDetailScroll.visibility = View.GONE
        binding.orderDetailErrorText.visibility = View.VISIBLE
    }

    private fun render(order: OrderDetail) {
        this.order = order
        binding.orderDetailErrorText.visibility = View.GONE
        binding.orderDetailScroll.visibility = View.VISIBLE

        val (pillBg, pillFg, pillLabel) = when (order.status) {
            "picked_up" -> Triple(R.color.status_approved_bg, R.color.status_approved_fg, getString(R.string.status_picked_up))
            "out_for_delivery" -> Triple(R.color.status_approved_bg, R.color.status_approved_fg, getString(R.string.status_out_for_delivery))
            else -> Triple(R.color.status_pending_bg, R.color.status_pending_fg, getString(R.string.status_rider_assigned))
        }
        binding.orderDetailStatusPill.text = pillLabel
        binding.orderDetailStatusPill.backgroundTintList = ColorStateList.valueOf(getColor(pillBg))
        binding.orderDetailStatusPill.setTextColor(getColor(pillFg))

        binding.orderDetailCodeMeta.text = getString(
            R.string.earnings_order_code_format, order.orderCode
        ).let { "$it • " + getString(R.string.order_detail_items_format, order.itemCount) }

        binding.pickupName.text = order.restaurantName
        binding.pickupAddress.text = order.restaurantAddress ?: ""

        binding.dropName.text = order.customerName ?: ""
        binding.dropAddress.text = order.deliveryAddress ?: ""

        // Structured address fields (migration 06) — each its own line,
        // independently visibility-gone so a partially-filled address
        // doesn't leave a blank line where a field is missing.
        renderOptionalLine(binding.dropHouse, order.houseFlatNo, R.string.order_detail_house_format)
        renderOptionalLine(binding.dropFloor, order.floor, R.string.order_detail_floor_format)
        renderOptionalLine(binding.dropLandmark, order.landmark, R.string.order_detail_landmark_format)
        renderOptionalLine(binding.dropReceiver, order.receiverName, R.string.order_detail_receiver_format)

        // Door/building photo (migration 16, H6) — not every address has
        // one. First remote-image load anywhere in this app (see
        // build.gradle's own comment on adding Coil for this).
        if (!order.addressPhotoUrl.isNullOrBlank()) {
            binding.dropPhoto.visibility = View.VISIBLE
            binding.dropPhoto.load(ApiClient.baseUrlForStaticFiles() + order.addressPhotoUrl)
        } else {
            binding.dropPhoto.visibility = View.GONE
        }

        if (order.distanceKm != null) {
            binding.pickupDistance.text = getString(R.string.order_detail_distance_format, order.distanceKm)
            binding.pickupDistance.visibility = View.VISIBLE
        } else {
            binding.pickupDistance.visibility = View.GONE
        }

        if (!order.deliveryInstructions.isNullOrBlank()) {
            binding.instructionsCard.visibility = View.VISIBLE
            binding.instructionsText.text = order.deliveryInstructions
        } else {
            binding.instructionsCard.visibility = View.GONE
        }

        binding.billItemTotal.text = "₹${order.itemTotal.toInt()}"
        binding.billDeliveryCharge.text = "₹${order.deliveryCharge.toInt()}"
        binding.billGrandTotal.text = "₹${order.grandTotal.toInt()}"

        if (order.paymentMethod == "cod" && order.codAmount != null) {
            binding.billCodRow.visibility = View.VISIBLE
            binding.billCodAmount.text = "₹${order.codAmount.toInt()}"
            binding.billPaidOnline.visibility = View.GONE
        } else {
            binding.billCodRow.visibility = View.GONE
            binding.billPaidOnline.visibility = View.VISIBLE
        }
    }

    private fun renderOptionalLine(view: android.widget.TextView, value: String?, formatRes: Int) {
        if (!value.isNullOrBlank()) {
            view.text = getString(formatRes, value)
            view.visibility = View.VISIBLE
        } else {
            view.visibility = View.GONE
        }
    }

    private fun callNumber(phone: String?) {
        if (phone.isNullOrBlank()) {
            Toast.makeText(this, R.string.order_detail_no_phone, Toast.LENGTH_SHORT).show()
            return
        }
        try {
            startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$phone")))
        } catch (e: ActivityNotFoundException) {
            Toast.makeText(this, R.string.order_detail_no_phone, Toast.LENGTH_SHORT).show()
        }
    }

    private fun navigateTo(lat: Double?, lng: Double?, label: String?) {
        if (lat == null || lng == null) {
            Toast.makeText(this, R.string.order_detail_no_location, Toast.LENGTH_SHORT).show()
            return
        }
        val encodedLabel = Uri.encode(label ?: "")
        // Prefer Google Maps turn-by-turn navigation directly...
        val navIntent = Intent(
            Intent.ACTION_VIEW,
            Uri.parse("google.navigation:q=$lat,$lng")
        ).apply { setPackage("com.google.android.apps.maps") }

        try {
            startActivity(navIntent)
        } catch (e: ActivityNotFoundException) {
            // ...falling back to a plain geo: query so the device's own
            // app chooser handles it if Maps specifically isn't
            // installed (a rider phone missing Maps is unusual but not
            // impossible — no reason to leave the button dead).
            val geoIntent = Intent(
                Intent.ACTION_VIEW,
                Uri.parse("geo:$lat,$lng?q=$lat,$lng($encodedLabel)")
            )
            try {
                startActivity(geoIntent)
            } catch (e2: ActivityNotFoundException) {
                Toast.makeText(this, R.string.order_detail_no_location, Toast.LENGTH_SHORT).show()
            }
        }
    }

    companion object {
        const val EXTRA_ORDER_ID = "extra_order_id"
    }
}
