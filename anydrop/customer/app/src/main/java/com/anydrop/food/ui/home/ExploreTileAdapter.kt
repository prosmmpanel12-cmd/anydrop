package com.anydrop.food.ui.home

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.food.databinding.ItemExploreTileBinding

/**
 * "Explore More" tile row (§2.3), below the restaurant list.
 * Offers and Top 10 are real (backed by restaurants/list.php filter/sort
 * params); Food on train / Collections are visually-present "Coming soon"
 * tiles per the spec's explicit scope decision — no real feature behind
 * them, tapping shows a toast instead of navigating anywhere.
 */
data class ExploreTile(
    val id: String, // "offers" | "top10" | "train" | "collections"
    val title: String,
    val subtitle: String,
    val iconRes: Int,
    val isComingSoon: Boolean
)

class ExploreTileAdapter(
    private val tiles: List<ExploreTile>,
    private val onClick: (ExploreTile) -> Unit
) : RecyclerView.Adapter<ExploreTileAdapter.VH>() {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemExploreTileBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(tiles[position])

    override fun getItemCount() = tiles.size

    inner class VH(private val binding: ItemExploreTileBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(tile: ExploreTile) {
            binding.tileTitle.text = tile.title
            binding.tileSubtitle.text = tile.subtitle
            binding.tileIcon.setImageResource(tile.iconRes)
            binding.root.alpha = if (tile.isComingSoon) 0.6f else 1f
            binding.root.setOnClickListener { onClick(tile) }
        }
    }
}
