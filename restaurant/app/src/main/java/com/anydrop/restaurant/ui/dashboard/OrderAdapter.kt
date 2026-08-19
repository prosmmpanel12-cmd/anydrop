package com.anydrop.restaurant.ui.dashboard

import android.app.Activity
import android.content.Context
import android.os.Handler
import android.os.Looper
import android.text.InputType
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.EditText
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.restaurant.R
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.anydrop.restaurant.databinding.ItemOrderCardBinding
import com.anydrop.restaurant.network.Order
import com.anydrop.restaurant.ui.common.InAppNotifier
import com.anydrop.restaurant.ui.common.PrepTimeDialog
import com.anydrop.restaurant.util.ScheduledTimeFormatter
import java.text.SimpleDateFormat
import java.util.Locale
import java.util.concurrent.TimeUnit

/**
 * Orders tab redesign (docs/restorent/19 §4, §10 item 3). One shared
 * adapter class; the fragment creates **three instances** — one per
 * section (New / In-progress / Completed today) — each constructed
 * with a fixed [CardMode] so item_order_card.xml's optional rows
 * show/hide consistently per section rather than per item. See
 * docs/restorent/NEXT_SESSION_PROMPT.md's suggested approach.
 *
 * [onAccept]/[onReject]/[onMarkNextStep] are left to the caller (the
 * fragment) to actually hit the API and refresh — this class only
 * collects the reject reason (needs its own dialog) and reports the
 * user's intent upward, same separation OrderDetailActivity already
 * has between its button handlers and its own network calls.
 */
class OrderAdapter(
    private val context: Context,
    private val mode: CardMode,
    private val onClick: (Order) -> Unit,
    // Order Management small addition — Accept now carries the
    // restaurant-chosen prep time (see PrepTimeDialog) instead of always
    // silently taking orders-accept.php's own 20-min fallback.
    private val onAccept: (Order, Int) -> Unit = { _, _ -> },
    private val onReject: (Order, String) -> Unit = { _, _ -> },
    private val onMarkNextStep: (Order) -> Unit = {}
) : RecyclerView.Adapter<OrderAdapter.OrderViewHolder>() {

    enum class CardMode { NEW, IN_PROGRESS, COMPLETED }

    private val orders = mutableListOf<Order>()

    fun submitList(newOrders: List<Order>) {
        orders.clear()
        orders.addAll(newOrders)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): OrderViewHolder {
        val binding = ItemOrderCardBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return OrderViewHolder(binding)
    }

    override fun onBindViewHolder(holder: OrderViewHolder, position: Int) {
        holder.bind(orders[position])
    }

    override fun onViewRecycled(holder: OrderViewHolder) {
        super.onViewRecycled(holder)
        // Recycled rows keep ticking otherwise — a countdown started for
        // one New order would silently keep updating a since-rebound
        // (possibly non-New) row underneath a recycled ViewHolder.
        holder.stopCountdown()
    }

    override fun getItemCount() = orders.size

    inner class OrderViewHolder(private val binding: ItemOrderCardBinding) : RecyclerView.ViewHolder(binding.root) {

        private val countdownHandler = Handler(Looper.getMainLooper())
        private var countdownRunnable: Runnable? = null

        fun bind(order: Order) {
            binding.orderCodeText.text = order.orderCode
            binding.itemsSummaryText.text = order.items.joinToString(", ") { "${it.quantity}x ${it.name}" }
            binding.grandTotalText.text = "₹${"%.2f".format(order.grandTotal)}"
            binding.paymentMethodText.text = order.paymentMethod.uppercase()

            val scheduledTime = ScheduledTimeFormatter.formatTime(order.scheduledFor)
            if (scheduledTime != null) {
                binding.scheduledBadge.visibility = View.VISIBLE
                binding.scheduledBadge.text = context.getString(R.string.order_scheduled_for_format, scheduledTime)
            } else {
                binding.scheduledBadge.visibility = View.GONE
            }

            val (bgColor, fgColor, label) = statusStyle(order.status)
            binding.statusChip.text = label
            binding.statusChip.setTextColor(fgColor)
            binding.statusChip.background.setTint(bgColor)

            bindCountdown(order)
            bindStepper(order)
            bindActionRow(order)

            binding.root.setOnClickListener { onClick(order) }
        }

        // ---- countdown chip (New section only) ----
        // Cosmetic local-window countdown only — the backend has no
        // accept-deadline/auto-reject concept at all (confirmed, see
        // docs/restorent/00_Status.md 2026-08-15 groundwork entry). This
        // never implies a real deadline the server would enforce.

        private fun bindCountdown(order: Order) {
            stopCountdown()
            if (mode != CardMode.NEW) {
                binding.countdownChip.visibility = View.GONE
                return
            }
            binding.countdownChip.visibility = View.VISIBLE
            val createdAtMs = parseCreatedAt(order.createdAt)
            if (createdAtMs == null) {
                binding.countdownChip.text = context.getString(R.string.countdown_expired)
                return
            }
            val deadlineMs = createdAtMs + COUNTDOWN_WINDOW_MS
            val runnable = object : Runnable {
                override fun run() {
                    val remaining = deadlineMs - System.currentTimeMillis()
                    if (remaining <= 0) {
                        binding.countdownChip.text = context.getString(R.string.countdown_expired)
                        return // window's up — stop ticking, nothing left to count down
                    }
                    val minutes = TimeUnit.MILLISECONDS.toMinutes(remaining)
                    val seconds = TimeUnit.MILLISECONDS.toSeconds(remaining) % 60
                    binding.countdownChip.text = context.getString(R.string.countdown_format, minutes, seconds)
                    countdownHandler.postDelayed(this, 1000L)
                }
            }
            countdownRunnable = runnable
            runnable.run()
        }

        fun stopCountdown() {
            countdownRunnable?.let { countdownHandler.removeCallbacks(it) }
            countdownRunnable = null
        }

        // ---- status stepper (In-progress section only) ----

        private fun bindStepper(order: Order) {
            if (mode != CardMode.IN_PROGRESS) {
                binding.stepperRow.visibility = View.GONE
                return
            }
            binding.stepperRow.visibility = View.VISIBLE
            // Step 1 (Preparing) is always filled by definition of being in
            // this section (status is accepted/preparing/ready/beyond).
            binding.stepDot1.setBackgroundResource(R.drawable.bg_stepper_dot_filled)
            val step2Filled = order.status != "accepted" && order.status != "preparing"
            binding.stepDot2.setBackgroundResource(
                if (step2Filled) R.drawable.bg_stepper_dot_filled else R.drawable.bg_stepper_dot_empty
            )
            // Step 3 ("Handed to rider") is never reachable from this app —
            // rider assignment is Phase 4, not built. Always empty for now.
            binding.stepDot3.setBackgroundResource(R.drawable.bg_stepper_dot_empty)
        }

        // ---- action row ----
        // New: Accept / Reject. In-progress: single "Mark next step"
        // button (reuses btnAccept's slot, btnReject hidden), or the
        // whole row hidden once there's no restaurant-side action left
        // (status already "ready" or beyond — matches
        // OrderDetailActivity.configureActions()'s else branch).
        // Completed: no actions, ever.

        private fun bindActionRow(order: Order) {
            when (mode) {
                CardMode.NEW -> {
                    binding.actionRow.visibility = View.VISIBLE
                    binding.btnReject.visibility = View.VISIBLE
                    binding.btnAccept.visibility = View.VISIBLE
                    binding.btnAccept.text = context.getString(R.string.btn_accept)
                    binding.btnAccept.setOnClickListener { promptPrepTime(order) }
                    binding.btnReject.setOnClickListener { promptRejectReason(order) }
                }
                CardMode.IN_PROGRESS -> {
                    val nextStatus = nextStatusFor(order.status)
                    if (nextStatus == null) {
                        binding.actionRow.visibility = View.GONE
                    } else {
                        binding.actionRow.visibility = View.VISIBLE
                        binding.btnReject.visibility = View.GONE
                        binding.btnAccept.visibility = View.VISIBLE
                        binding.btnAccept.text = context.getString(R.string.btn_mark_next_step)
                        binding.btnAccept.setOnClickListener { onMarkNextStep(order) }
                    }
                }
                CardMode.COMPLETED -> {
                    binding.actionRow.visibility = View.GONE
                }
            }
        }

        /** Mirrors orders-status.php's allowed transitions (accepted ->
         * preparing -> ready only; rider assignment is Phase 4). Null
         * means no restaurant-side action is left for this order. */
        private fun nextStatusFor(status: String): String? = when (status) {
            "accepted" -> "preparing"
            "preparing" -> "ready"
            else -> null
        }

        private fun promptPrepTime(order: Order) {
            PrepTimeDialog.show(context) { prepMinutes ->
                onAccept(order, prepMinutes)
            }
        }

        private fun promptRejectReason(order: Order) {
            val input = EditText(context).apply {
                hint = context.getString(R.string.hint_reject_reason)
                inputType = InputType.TYPE_CLASS_TEXT
                val padding = (16 * context.resources.displayMetrics.density).toInt()
                setPadding(padding, padding / 2, padding, padding / 2)
            }
            MaterialAlertDialogBuilder(context)
                .setTitle(R.string.btn_reject)
                .setView(input)
                .setPositiveButton(R.string.btn_confirm_reject) { _, _ ->
                    val reason = input.text?.toString()?.trim().orEmpty()
                    if (reason.isEmpty()) {
                        InAppNotifier.show(context as? Activity, context.getString(R.string.hint_reject_reason), InAppNotifier.Type.INFO)
                    } else {
                        onReject(order, reason)
                    }
                }
                .setNegativeButton(R.string.btn_cancel, null)
                .show()
        }

        private fun statusStyle(status: String): Triple<Int, Int, String> {
            val bg: Int
            val fg: Int
            val label: String
            when (status) {
                "pending" -> {
                    bg = ContextCompat.getColor(context, R.color.status_pending_bg)
                    fg = ContextCompat.getColor(context, R.color.status_pending_fg)
                    label = "NEW"
                }
                "delivered" -> {
                    bg = ContextCompat.getColor(context, R.color.status_done_bg)
                    fg = ContextCompat.getColor(context, R.color.status_done_fg)
                    label = "DELIVERED"
                }
                "cancelled", "rejected" -> {
                    bg = ContextCompat.getColor(context, R.color.error_bg)
                    fg = ContextCompat.getColor(context, R.color.error_fg)
                    label = status.uppercase()
                }
                else -> {
                    bg = ContextCompat.getColor(context, R.color.status_active_bg)
                    fg = ContextCompat.getColor(context, R.color.status_active_fg)
                    label = status.uppercase().replace("_", " ")
                }
            }
            return Triple(bg, fg, label)
        }
    }

    companion object {
        // Cosmetic-only local window — see the docstring above and
        // docs/restorent/00_Status.md: no accept_deadline/expires_at
        // column or cron job exists server-side.
        private const val COUNTDOWN_WINDOW_MS = 5 * 60 * 1000L

        private fun parseCreatedAt(raw: String): Long? = try {
            SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.getDefault()).parse(raw)?.time
        } catch (e: Exception) {
            null
        }
    }
}
