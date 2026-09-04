package com.anydrop.restaurant.ui.account

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ItemOfferCardBinding
import com.anydrop.restaurant.network.PromoOffer
import java.text.SimpleDateFormat
import java.util.Locale

/**
 * Offer list for OfferManagerActivity (doc 20 §14; docs/29 "Not built"
 * item 1, finished this session). Same plain submitList/updateOne shape
 * as CouponAdapter — see that file's header comment for why no DiffUtil
 * (this screen's list is small and rarely churns mid-session, same
 * reasoning applies here).
 *
 * Unlike CouponAdapter, this adapter doesn't own the Active/Scheduled/
 * Expired/Paused bucketing itself — OfferManagerActivity filters the
 * full offers list by tab (via OfferManagerActivity.bucketFor()) and
 * calls submitList() with just the current bucket's items each time the
 * tab changes, so this class only ever renders "whatever list it was
 * handed," same division of responsibility BannerAdapter already uses
 * elsewhere in this app.
 */
class OfferAdapter(
    private val onEditClick: (PromoOffer) -> Unit,
    private val onPauseResumeClick: (PromoOffer) -> Unit,
    private val onViewClick: (PromoOffer) -> Unit
) : RecyclerView.Adapter<OfferAdapter.OfferViewHolder>() {

    private val offers = mutableListOf<PromoOffer>()

    fun submitList(newOffers: List<PromoOffer>) {
        offers.clear()
        offers.addAll(newOffers)
        notifyDataSetChanged()
    }

    fun updateOne(updated: PromoOffer) {
        val index = offers.indexOfFirst { it.id == updated.id }
        if (index != -1) {
            offers[index] = updated
            notifyItemChanged(index)
        }
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): OfferViewHolder {
        val binding = ItemOfferCardBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return OfferViewHolder(binding)
    }

    override fun onBindViewHolder(holder: OfferViewHolder, position: Int) {
        holder.bind(offers[position])
    }

    override fun getItemCount() = offers.size

    inner class OfferViewHolder(private val binding: ItemOfferCardBinding) :
        RecyclerView.ViewHolder(binding.root) {

        fun bind(offer: PromoOffer) {
            val ctx = binding.root.context

            binding.offerTitleText.text = offer.title

            // ic_fire/ic_delivery (this session's new drawables) stand in
            // for doc 20 §1.1/§2's 🔥/🚚 emoji — free_delivery is the only
            // type without a "mechanic" leader, everything else shares
            // the fire badge same as the mock's item/percent/flat cards.
            binding.offerTypeIcon.setImageResource(
                if (offer.offerType == "free_delivery") R.drawable.ic_delivery else R.drawable.ic_fire
            )

            // "Used: X / Y" — Y omitted when total_limit is null
            // (unlimited), same null-is-unlimited convention the backend
            // itself uses for this field.
            binding.offerUsageText.text = if (offer.totalLimit != null) {
                ctx.getString(R.string.offer_used_of_fmt, offer.timesUsed, offer.totalLimit)
            } else {
                ctx.getString(R.string.offer_used_unlimited_fmt, offer.timesUsed)
            }

            binding.offerValidityText.text = formatValidity(ctx, offer)

            val (bgColor, fgColor, label) = statusStyle(ctx, offer)
            binding.offerStatusBadge.text = label
            binding.offerStatusBadge.setTextColor(fgColor)
            binding.offerStatusBadge.background.setTint(bgColor)

            // Pause/Resume — label and icon flip with the offer's current
            // status. Disabled (admin-paused) offers can't be resumed by
            // the restaurant at all (offers-update.php returns 403
            // offer_disabled_by_admin) — hidden here rather than shown-
            // then-erroring, so the restaurant isn't invited into a dead
            // end the card itself can already rule out.
            if (offer.status == "disabled") {
                binding.btnOfferPauseResume.visibility = View.GONE
            } else {
                binding.btnOfferPauseResume.visibility = View.VISIBLE
                if (offer.status == "paused") {
                    binding.btnOfferPauseResume.text = ctx.getString(R.string.offer_action_resume)
                    binding.btnOfferPauseResume.icon = ContextCompat.getDrawable(ctx, R.drawable.ic_play)
                } else {
                    binding.btnOfferPauseResume.text = ctx.getString(R.string.offer_action_pause)
                    binding.btnOfferPauseResume.icon = ContextCompat.getDrawable(ctx, R.drawable.ic_pause)
                }
                binding.btnOfferPauseResume.setOnClickListener { onPauseResumeClick(offer) }
            }

            binding.btnOfferEdit.setOnClickListener { onEditClick(offer) }
            binding.btnOfferView.setOnClickListener { onViewClick(offer) }
        }

        private fun statusStyle(ctx: android.content.Context, offer: PromoOffer): Triple<Int, Int, String> {
            return when (offer.status) {
                "disabled" -> Triple(
                    ContextCompat.getColor(ctx, R.color.error_bg),
                    ContextCompat.getColor(ctx, R.color.error_fg),
                    ctx.getString(R.string.offer_status_disabled)
                )
                "paused" -> Triple(
                    ContextCompat.getColor(ctx, R.color.status_pending_bg),
                    ContextCompat.getColor(ctx, R.color.status_pending_fg),
                    ctx.getString(R.string.offer_status_paused)
                )
                else -> Triple(
                    ContextCompat.getColor(ctx, R.color.success_bg),
                    ContextCompat.getColor(ctx, R.color.success_fg),
                    ctx.getString(R.string.offer_status_active)
                )
            }
        }

        /** "Valid: 18 Aug – 25 Aug" per doc 20 §14's mock — both dates
         * shown when both present; a single open-ended bound shown alone
         * ("From 18 Aug" / "Until 25 Aug"); nothing shown at all (blank
         * string, offerValidityText left empty) when the offer has
         * neither, since an always-on offer has no validity line to show
         * in the mock. */
        private fun formatValidity(ctx: android.content.Context, offer: PromoOffer): String {
            val start = offer.startDate?.let { formatDateShort(it) }
            val end = offer.endDate?.let { formatDateShort(it) }
            return when {
                start != null && end != null -> ctx.getString(R.string.offer_valid_range_fmt, start, end)
                start != null -> ctx.getString(R.string.offer_valid_from_fmt, start)
                end != null -> ctx.getString(R.string.offer_valid_until_fmt, end)
                else -> ""
            }
        }

        private fun formatDateShort(yyyyMmDd: String): String {
            return try {
                val parsed = wireDateFormat.parse(yyyyMmDd)
                displayDateFormat.format(parsed!!)
            } catch (e: Exception) {
                yyyyMmDd // fall back to the raw value rather than crashing on an unexpected format
            }
        }
    }

    companion object {
        private val wireDateFormat = SimpleDateFormat("yyyy-MM-dd", Locale.US)
        private val displayDateFormat = SimpleDateFormat("d MMM", Locale.getDefault())
    }
}
