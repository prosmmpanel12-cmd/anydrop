package com.anydrop.food.ui.profile

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.food.databinding.ItemFaqCardBinding
import com.anydrop.food.network.FaqEntry

class FaqAdapter : RecyclerView.Adapter<FaqAdapter.VH>() {

    private val items = mutableListOf<FaqEntry>()
    private var expandedId: Int? = null

    fun submit(list: List<FaqEntry>) {
        items.clear()
        items.addAll(list)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemFaqCardBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(items[position])

    override fun getItemCount() = items.size

    inner class VH(private val binding: ItemFaqCardBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(faq: FaqEntry) {
            binding.faqQuestion.text = faq.question
            binding.faqAnswer.text = faq.answer

            val isExpanded = expandedId == faq.id
            binding.faqAnswer.visibility = if (isExpanded) View.VISIBLE else View.GONE
            binding.faqChevron.rotation = if (isExpanded) 90f else 270f

            binding.faqQuestionRow.setOnClickListener {
                expandedId = if (isExpanded) null else faq.id
                notifyDataSetChanged()
            }
        }
    }
}
