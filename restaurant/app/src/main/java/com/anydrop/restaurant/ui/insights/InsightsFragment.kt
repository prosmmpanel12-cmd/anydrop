package com.anydrop.restaurant.ui.insights

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import com.anydrop.restaurant.databinding.FragmentInsightsBinding

/**
 * Insights tab — empty placeholder for the bottom-nav shell (§10 item
 * 2). Real content is §10 item 6: date range selector, stat cards,
 * 7-day bar chart, top-5 bestsellers (§6), each with its skeleton
 * loading state built alongside it (§9). Blocked on a new
 * `restaurant/insights.php` backend endpoint that doesn't exist yet
 * (flagged in §6's own note) — not building that here.
 */
class InsightsFragment : Fragment() {

    private var _binding: FragmentInsightsBinding? = null
    private val binding get() = _binding!!

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentInsightsBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
