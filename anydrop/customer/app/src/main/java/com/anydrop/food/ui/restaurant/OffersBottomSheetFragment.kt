package com.anydrop.food.ui.restaurant

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import com.google.android.material.bottomsheet.BottomSheetDialogFragment
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import com.anydrop.food.databinding.FragmentRestaurantOffersBinding
import com.anydrop.food.databinding.ItemOfferRowBinding
import com.anydrop.food.network.RestaurantOffer

/**
 * features.md §6 — simple viewer sheet for a restaurant's offers, opened
 * from the "N offers ⌄" strip on the restaurant detail header. Same
 * lightweight pattern as MenuFiltersBottomSheet (Gson-serialized list
 * through the args Bundle, title + close icon at top) but with no footer —
 * there's nothing to "apply" here, it's just a list to read.
 */
class OffersBottomSheetFragment private constructor() : BottomSheetDialogFragment() {

    companion object {
        private const val ARG_OFFERS_JSON = "offers_json"

        fun newInstance(offers: List<RestaurantOffer>): OffersBottomSheetFragment {
            val sheet = OffersBottomSheetFragment()
            sheet.arguments = Bundle().apply {
                putString(ARG_OFFERS_JSON, Gson().toJson(offers))
            }
            return sheet
        }
    }

    private var _binding: FragmentRestaurantOffersBinding? = null
    private val binding get() = _binding!!

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentRestaurantOffersBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        val offersJson = requireArguments().getString(ARG_OFFERS_JSON)
        val offers: List<RestaurantOffer> = if (!offersJson.isNullOrBlank()) {
            val type = object : TypeToken<List<RestaurantOffer>>() {}.type
            Gson().fromJson(offersJson, type)
        } else {
            emptyList()
        }

        binding.btnCloseOffers.setOnClickListener { dismiss() }

        binding.offersList.removeAllViews()
        val inflater = LayoutInflater.from(requireContext())
        offers.forEachIndexed { index, offer ->
            val row = ItemOfferRowBinding.inflate(inflater, binding.offersList, false)
            row.offerRowDivider.visibility = if (index == 0) View.GONE else View.VISIBLE
            row.offerTitle.text = offer.title
            if (!offer.description.isNullOrBlank()) {
                row.offerDescription.text = offer.description
                row.offerDescription.visibility = View.VISIBLE
            } else {
                row.offerDescription.visibility = View.GONE
            }
            binding.offersList.addView(row.root)
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
