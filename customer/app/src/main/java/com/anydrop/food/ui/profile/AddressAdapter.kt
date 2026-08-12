package com.anydrop.food.ui.profile

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.food.databinding.ItemAddressCardBinding
import com.anydrop.food.network.Address

class AddressAdapter(
    private val onEdit: (Address) -> Unit,
    private val onDelete: (Address) -> Unit,
    private val onActivate: (Address) -> Unit
) : RecyclerView.Adapter<AddressAdapter.VH>() {

    private val items = mutableListOf<Address>()

    fun submit(list: List<Address>) {
        items.clear()
        items.addAll(list)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemAddressCardBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(items[position])

    override fun getItemCount() = items.size

    inner class VH(private val binding: ItemAddressCardBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(address: Address) {
            binding.addressLabel.text = address.label ?: address.addressType.replaceFirstChar { it.uppercase() }
            binding.addressDefaultBadge.visibility = if (address.isDefault) View.VISIBLE else View.GONE

            val houseFlat = address.houseFlatNo?.let { "$it, " } ?: ""
            binding.addressFull.text = "$houseFlat${address.fullAddress}"

            if (!address.receiverName.isNullOrBlank()) {
                binding.addressReceiver.text = binding.root.context.getString(
                    com.anydrop.food.R.string.receiver_line_format,
                    address.receiverName,
                    address.receiverPhone.orEmpty()
                )
                binding.addressReceiver.visibility = View.VISIBLE
            } else {
                binding.addressReceiver.visibility = View.GONE
            }

            binding.btnEditAddress.setOnClickListener { onEdit(address) }
            binding.btnDeleteAddress.setOnClickListener { onDelete(address) }
            // H6 — tap the card itself (outside the two action buttons) to
            // activate it as the current delivery address, same behavior
            // as the Location Picker screen's saved-address rows.
            binding.root.setOnClickListener { onActivate(address) }
        }
    }
}
