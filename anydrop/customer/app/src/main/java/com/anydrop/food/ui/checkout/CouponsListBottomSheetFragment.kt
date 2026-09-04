package com.anydrop.food.ui.checkout

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.FragmentManager
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.google.android.material.bottomsheet.BottomSheetDialogFragment
import com.anydrop.food.databinding.FragmentCouponsListBinding
import com.anydrop.food.network.ApiClient
import com.anydrop.food.network.CouponListItem
import com.anydrop.food.ui.common.InAppNotifier
import kotlinx.coroutines.launch

/**
 * H5 — "View all offers & coupons" sheet, opened from Checkout's new
 * "View all offers & coupons" row. `GET /coupons/list.php` does the same
 * eligibility filtering `price_cart()` already applies (active, in-date,
 * platform-wide or this restaurant's own) — this sheet is a browse/pick
 * UI in front of the coupon code box, not a second apply pathway: tapping
 * an eligible row just fills the code and calls back into
 * CheckoutActivity's existing `applyCoupon()`.
 */
class CouponsListBottomSheetFragment private constructor() : BottomSheetDialogFragment() {

    companion object {
        private const val ARG_RESTAURANT_ID = "restaurant_id"
        private const val ARG_ITEM_TOTAL = "item_total"
        private const val ARG_APPLIED_CODE = "applied_code"

        fun newInstance(
            restaurantId: Int,
            itemTotal: Double?,
            appliedCode: String?
        ): CouponsListBottomSheetFragment {
            val sheet = CouponsListBottomSheetFragment()
            sheet.arguments = Bundle().apply {
                putInt(ARG_RESTAURANT_ID, restaurantId)
                itemTotal?.let { putDouble(ARG_ITEM_TOTAL, it) }
                putString(ARG_APPLIED_CODE, appliedCode)
            }
            return sheet
        }
    }

    /** Set by the host before [show] — CheckoutActivity fills the coupon
     * code box and calls its own applyCoupon() when a row is tapped. */
    var onCouponSelected: ((CouponListItem) -> Unit)? = null

    private var _binding: FragmentCouponsListBinding? = null
    private val binding get() = _binding!!
    private val api by lazy { ApiClient.create(requireContext()) }

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentCouponsListBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        binding.btnCloseCoupons.setOnClickListener { dismiss() }

        val restaurantId = requireArguments().getInt(ARG_RESTAURANT_ID)
        val itemTotal = requireArguments().takeIf { it.containsKey(ARG_ITEM_TOTAL) }
            ?.getDouble(ARG_ITEM_TOTAL)
        val appliedCode = requireArguments().getString(ARG_APPLIED_CODE)

        val adapter = CouponsAdapter { coupon ->
            onCouponSelected?.invoke(coupon)
            dismiss()
        }
        binding.couponsRecyclerView.layoutManager = LinearLayoutManager(requireContext())
        binding.couponsRecyclerView.adapter = adapter

        loadCoupons(restaurantId, itemTotal, appliedCode, adapter)
    }

    private fun loadCoupons(
        restaurantId: Int,
        itemTotal: Double?,
        appliedCode: String?,
        adapter: CouponsAdapter
    ) {
        binding.couponsLoading.visibility = View.VISIBLE
        binding.couponsEmptyText.visibility = View.GONE
        binding.couponsRecyclerView.visibility = View.GONE

        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val response = api.getCoupons(restaurantId, itemTotal)
                val coupons = response.body()?.data?.coupons.orEmpty()
                binding.couponsLoading.visibility = View.GONE
                if (coupons.isEmpty()) {
                    binding.couponsEmptyText.visibility = View.VISIBLE
                } else {
                    binding.couponsRecyclerView.visibility = View.VISIBLE
                    adapter.submit(coupons, appliedCode)
                }
            } catch (e: Exception) {
                binding.couponsLoading.visibility = View.GONE
                binding.couponsEmptyText.visibility = View.VISIBLE
                InAppNotifier.show(activity, "Couldn't load offers right now.", InAppNotifier.Type.ERROR)
            }
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }

    fun show(manager: FragmentManager) = show(manager, "coupons_list")
}
