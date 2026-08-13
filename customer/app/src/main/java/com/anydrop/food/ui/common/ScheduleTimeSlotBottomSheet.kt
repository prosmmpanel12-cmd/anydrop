package com.anydrop.food.ui.common

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import com.google.android.material.bottomsheet.BottomSheetDialogFragment
import com.anydrop.food.R
import com.anydrop.food.databinding.FragmentScheduleTimeBinding
import com.anydrop.food.databinding.ItemScheduleSlotRowBinding
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Locale

/**
 * features.md §I4 — "Schedule for later" slot picker, same-day only per the
 * app owner's explicit scope call: no date picker, just "Deliver Now" plus
 * a list of today's remaining half-hour delivery slots, bounded to the
 * restaurant's `opening_time`/`closing_time` (raw "HH:MM:SS" strings off
 * `RestaurantDetail`/menu.php). Shared between RestaurantDetailActivity
 * (first pick) and CheckoutActivity (change the pick) — both just call
 * [newInstance] with whatever the restaurant's hours and the cart's current
 * selection are.
 *
 * The list this sheet offers is only ever a *convenience* — the server is
 * the actual source of truth on whether a slot is valid (same-day, 20-min
 * minimum lead, within open hours; see `validate_scheduled_for()` in
 * `backend/lib/orders.php`). Slot generation here mirrors that logic so the
 * two rarely disagree, but if they ever do, Place Order's 422 wins and the
 * customer just gets sent back to this sheet.
 *
 * [onSelected] is a plain lambda set by the caller right after
 * [newInstance] and before `.show(...)` — same "doesn't survive a
 * config-change teardown of the caller" tradeoff MenuFiltersBottomSheet's
 * `onApply` already accepts elsewhere in this app; a lost callback here
 * just means the customer re-taps the (still-open) sheet.
 */
class ScheduleTimeSlotBottomSheet private constructor() : BottomSheetDialogFragment() {

    companion object {
        private const val ARG_OPENING_TIME = "opening_time"
        private const val ARG_CLOSING_TIME = "closing_time"
        private const val ARG_CURRENT_SELECTION = "current_selection"

        /** Minimum lead time before a slot is offered — must match the
         * server's floor in validate_scheduled_for() or this sheet would
         * routinely offer slots the server then rejects. */
        private const val MIN_LEAD_MINUTES = 20
        private const val SLOT_INTERVAL_MINUTES = 30

        /**
         * @param openingTime restaurant's `opening_time`, raw "HH:MM:SS" (or
         *   "HH:MM"), null if the restaurant has no configured hours (in
         *   which case every slot for the rest of the day is offered).
         * @param closingTime same shape as [openingTime].
         * @param currentSelection the cart's current `scheduledFor`
         *   ("yyyy-MM-dd HH:mm:ss"), or null if it's currently "Deliver
         *   Now" — used only to pre-check the matching row.
         */
        fun newInstance(
            openingTime: String?,
            closingTime: String?,
            currentSelection: String?
        ): ScheduleTimeSlotBottomSheet {
            val sheet = ScheduleTimeSlotBottomSheet()
            sheet.arguments = Bundle().apply {
                putString(ARG_OPENING_TIME, openingTime)
                putString(ARG_CLOSING_TIME, closingTime)
                putString(ARG_CURRENT_SELECTION, currentSelection)
            }
            return sheet
        }
    }

    /** Invoked with the picked slot ("yyyy-MM-dd HH:mm:ss"), or null if the
     * customer picked "Deliver Now". Sheet dismisses itself right after. */
    var onSelected: ((String?) -> Unit)? = null

    private var _binding: FragmentScheduleTimeBinding? = null
    private val binding get() = _binding!!

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentScheduleTimeBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        binding.btnCloseSchedule.setOnClickListener { dismiss() }

        val openingTime = requireArguments().getString(ARG_OPENING_TIME)
        val closingTime = requireArguments().getString(ARG_CLOSING_TIME)
        val currentSelection = requireArguments().getString(ARG_CURRENT_SELECTION)

        val slots = buildSlotsForToday(openingTime, closingTime)

        val inflater = LayoutInflater.from(requireContext())
        binding.scheduleSlotList.removeAllViews()

        // "Deliver Now" is always offered, even if the restaurant is about
        // to close — it's today's normal ASAP order, not a scheduled one,
        // so it isn't bound by the same open-hours slot math below it.
        val nowRow = ItemScheduleSlotRowBinding.inflate(inflater, binding.scheduleSlotList, false)
        nowRow.scheduleRowLabel.text = getString(R.string.schedule_deliver_now)
        nowRow.scheduleRowCheck.visibility = if (currentSelection == null) View.VISIBLE else View.GONE
        nowRow.scheduleRowRoot.setOnClickListener {
            onSelected?.invoke(null)
            dismiss()
        }
        binding.scheduleSlotList.addView(nowRow.root)

        if (slots.isEmpty()) {
            binding.scheduleEmptyState.visibility = View.VISIBLE
        } else {
            val displayFormat = SimpleDateFormat("h:mm a", Locale.getDefault())
            val storageFormat = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.getDefault())
            slots.forEach { slot ->
                val row = ItemScheduleSlotRowBinding.inflate(inflater, binding.scheduleSlotList, false)
                val stored = storageFormat.format(slot.time)
                row.scheduleRowLabel.text = displayFormat.format(slot.time)
                row.scheduleRowCheck.visibility =
                    if (currentSelection != null && currentSelection == stored) View.VISIBLE else View.GONE
                row.scheduleRowRoot.setOnClickListener {
                    onSelected?.invoke(stored)
                    dismiss()
                }
                binding.scheduleSlotList.addView(row.root)
            }
        }
    }

    private data class Slot(val time: java.util.Date)

    /**
     * Builds today's remaining slots at [SLOT_INTERVAL_MINUTES] steps,
     * starting at the next slot boundary at least [MIN_LEAD_MINUTES] from
     * now, bounded to [openingTime]/[closingTime] if both are present.
     * Mirrors `validate_scheduled_for()`'s same-day-window assumption —
     * doesn't handle a restaurant open past midnight, same limitation
     * called out there.
     */
    private fun buildSlotsForToday(openingTime: String?, closingTime: String?): List<Slot> {
        val now = Calendar.getInstance()

        val earliest = (now.clone() as Calendar).apply {
            add(Calendar.MINUTE, MIN_LEAD_MINUTES)
            // Round up to the next SLOT_INTERVAL_MINUTES boundary so slots
            // read as clean times ("8:30 PM"), not "8:23 PM".
            val minute = get(Calendar.MINUTE)
            val remainder = minute % SLOT_INTERVAL_MINUTES
            if (remainder != 0) add(Calendar.MINUTE, SLOT_INTERVAL_MINUTES - remainder)
            set(Calendar.SECOND, 0)
            set(Calendar.MILLISECOND, 0)
        }

        val dayStart = (now.clone() as Calendar).apply {
            set(Calendar.SECOND, 0)
            set(Calendar.MILLISECOND, 0)
        }

        fun parseTimeToday(raw: String?): Calendar? {
            if (raw.isNullOrBlank()) return null
            val parts = raw.split(":")
            if (parts.size < 2) return null
            val hour = parts[0].toIntOrNull() ?: return null
            val minute = parts[1].toIntOrNull() ?: return null
            return (dayStart.clone() as Calendar).apply {
                set(Calendar.HOUR_OF_DAY, hour)
                set(Calendar.MINUTE, minute)
            }
        }

        val openCal = parseTimeToday(openingTime)
        val closeCal = parseTimeToday(closingTime)

        var cursor = earliest
        if (openCal != null && cursor.before(openCal)) {
            cursor = openCal
        }
        // Restaurant with hours configured but already closed for the rest
        // of today (closingTime <= earliest lead time) — no slots to offer,
        // fragment shows scheduleEmptyState instead.
        if (closeCal != null && !closeCal.after(earliest)) {
            return emptyList()
        }

        val slots = mutableListOf<Slot>()
        while (closeCal == null || !cursor.after(closeCal)) {
            slots.add(Slot(cursor.time))
            cursor = (cursor.clone() as Calendar).apply { add(Calendar.MINUTE, SLOT_INTERVAL_MINUTES) }
            // Hard safety cap so a missing/garbled closingTime (no closeCal)
            // can't spin this into an unbounded list — 24h of half-hour
            // slots is already generous for "rest of today".
            if (slots.size >= 48) break
        }
        return slots
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
