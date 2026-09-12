package com.anydrop.restaurant.ui.statement

import android.content.res.ColorStateList
import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ItemStatementOrderBinding
import com.anydrop.restaurant.network.StatementOrder
import java.text.SimpleDateFormat
import java.util.Locale

/**
 * StatementActivity's RecyclerView row (Deep Plan Phase 2). No
 * pagination — same "a single day's orders is always a small list"
 * reasoning ClosureAdapter/AddonGroupAdapter already use elsewhere in
 * this app; the whole list is re-fetched on every date change.
 */
class StatementOrderAdapter : RecyclerView.Adapter<StatementOrderAdapter.ViewHolder>() {

    private val items = mutableListOf<StatementOrder>()

    fun submit(newItems: List<StatementOrder>) {
        items.clear()
        items.addAll(newItems)
        notifyDataSetChanged()
    }

    inner class ViewHolder(val binding: ItemStatementOrderBinding) : RecyclerView.ViewHolder(binding.root)

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
        val binding = ItemStatementOrderBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return ViewHolder(binding)
    }

    override fun onBindViewHolder(holder: ViewHolder, position: Int) {
        val order = items[position]
        val context = holder.binding.root.context

        holder.binding.orderCode.text = "#${order.orderCode}"

        val timeLabel = formatTime(order.createdAt)
        val methodLabel = if (order.paymentMethod == "cod") "COD" else "Prepaid"
        holder.binding.orderTime.text = "$timeLabel · $methodLabel"

        holder.binding.orderAmount.text = "₹${"%.0f".format(order.grandTotal)}"

        // App-owner ask, 2026-09-11 — commission + net payable, right on
        // the row. Backend always sends net_payable now; the 0.0 default
        // on the model is only a defensive fallback for an old cached
        // response shape.
        holder.binding.orderCommissionNet.text = context.getString(
            R.string.statement_row_commission_net_format,
            "%.0f".format(order.commissionAmount),
            "%.0f".format(order.netPayable)
        )

        // Not-delivered orders (cancelled/rejected/still in progress)
        // never carry a real settlement status ('not_applicable' on the
        // backend) — hide the badge rather than show a misleading one.
        when (order.settlementStatus) {
            "settled" -> renderBadge(holder, R.string.statement_badge_settled, R.color.success_bg, R.color.success_fg)
            "eligible" -> renderBadge(holder, R.string.statement_badge_eligible, R.color.success_bg, R.color.success_fg)
            "pending" -> renderBadge(holder, R.string.statement_badge_pending, R.color.warning_amber_bg, R.color.warning_amber)
            else -> holder.binding.settlementBadge.visibility = android.view.View.GONE
        }
    }

    private fun renderBadge(holder: ViewHolder, textRes: Int, bgColorRes: Int, fgColorRes: Int) {
        val context = holder.binding.root.context
        holder.binding.settlementBadge.visibility = android.view.View.VISIBLE
        holder.binding.settlementBadge.text = context.getString(textRes)
        holder.binding.settlementBadge.setTextColor(context.getColor(fgColorRes))
        holder.binding.settlementBadge.backgroundTintList =
            ColorStateList.valueOf(context.getColor(bgColorRes))
    }

    override fun getItemCount(): Int = items.size

    private fun formatTime(wireTimestamp: String): String {
        return try {
            // Backend sends "YYYY-MM-DD HH:MM:SS" (server timezone, same
            // convention as every other created_at/delivered_at value
            // this app already displays elsewhere, e.g. OrdersFragment).
            val wire = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)
            val display = SimpleDateFormat("h:mm a", Locale.getDefault())
            display.format(wire.parse(wireTimestamp)!!)
        } catch (e: Exception) {
            wireTimestamp
        }
    }
}
