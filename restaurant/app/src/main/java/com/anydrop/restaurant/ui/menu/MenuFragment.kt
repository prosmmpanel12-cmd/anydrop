package com.anydrop.restaurant.ui.menu

import android.app.AlertDialog
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.DialogAddCategoryBinding
import com.anydrop.restaurant.databinding.DialogAddMenuItemBinding
import com.anydrop.restaurant.databinding.FragmentMenuBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.ApiService
import com.anydrop.restaurant.network.CategoryCreateBody
import com.anydrop.restaurant.network.CategoryUpdateBody
import com.anydrop.restaurant.network.MenuCategory
import com.anydrop.restaurant.network.MenuItem
import com.anydrop.restaurant.network.MenuItemCreateBody
import com.anydrop.restaurant.network.MenuItemUpdateBody
import com.anydrop.restaurant.ui.common.InAppNotifier
import kotlinx.coroutines.launch

/**
 * Menu tab (§5 of the UI plan) — formerly its own pushed screen
 * (`MenuManagementActivity`, reached from a Dashboard button with a
 * slide transition), now one of four bottom-nav tabs hosted by
 * `MainActivity`. Behavior carried over as-is for this shell step; the
 * §5 extensions (photo thumbnail slot, category tabs as a horizontal
 * strip once 5+ categories, drag-to-reorder, search bar) are §10 item
 * 4, not this pass.
 *
 * Tier 1 "Menu Management" (docs/18). Category + food item add/edit/
 * delete, price update, veg/non-veg toggle (on the add/edit form), and
 * the out-of-stock quick-toggle switch on each item row.
 *
 * Not in this pass (same as it wasn't in MenuManagementActivity):
 * photo upload, customization/add-on group UI, item availability
 * time-of-day windows, in-list search box.
 */
class MenuFragment : Fragment() {

    private var _binding: FragmentMenuBinding? = null
    private val binding get() = _binding!!

    private val api: ApiService by lazy { ApiClient.create(requireContext()) }
    private lateinit var adapter: CategoryAdapter

    private var categories: List<MenuCategory> = emptyList()
    private var items: List<MenuItem> = emptyList()

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentMenuBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        adapter = CategoryAdapter(
            context = requireContext(),
            onEditCategory = { showCategoryDialog(it) },
            onDeleteCategory = { confirmDeleteCategory(it) },
            onAddItem = { showItemDialog(category = it) },
            onToggleItemAvailable = { item, available -> toggleItemAvailable(item, available) },
            onEditItem = { showItemDialog(existingItem = it) },
            onDeleteItem = { confirmDeleteItem(it) }
        )
        binding.categoriesRecycler.layoutManager = LinearLayoutManager(requireContext())
        binding.categoriesRecycler.adapter = adapter

        binding.btnAddCategory.setOnClickListener { showCategoryDialog() }
        binding.swipeRefresh.setOnRefreshListener { loadMenu() }

        loadMenu()
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }

    private fun loadMenu() {
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val catResp = api.getCategories().body()?.data?.categories ?: emptyList()
                val itemResp = api.getMenuItems().body()?.data?.items ?: emptyList()
                categories = catResp
                items = itemResp
                adapter.submitData(categories, items)
                if (_binding != null) {
                    binding.emptyText.visibility = if (categories.none { it.isActive }) View.VISIBLE else View.GONE
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, getString(R.string.menu_load_failed), InAppNotifier.Type.ERROR)
            } finally {
                if (_binding != null) binding.swipeRefresh.isRefreshing = false
            }
        }
    }

    // ---- Category add/edit ----

    private fun showCategoryDialog(existing: MenuCategory? = null) {
        val dialogBinding = DialogAddCategoryBinding.inflate(layoutInflater)
        dialogBinding.inputCategoryName.setText(existing?.name ?: "")

        AlertDialog.Builder(requireContext())
            .setTitle(if (existing == null) R.string.dialog_add_category_title else R.string.dialog_edit_category_title)
            .setView(dialogBinding.root)
            .setPositiveButton(R.string.btn_save) { _, _ ->
                val name = dialogBinding.inputCategoryName.text?.toString()?.trim().orEmpty()
                if (name.isEmpty()) {
                    InAppNotifier.show(activity, getString(R.string.menu_save_failed), InAppNotifier.Type.ERROR)
                    return@setPositiveButton
                }
                saveCategory(existing, name)
            }
            .setNegativeButton(R.string.btn_cancel, null)
            .show()
    }

    private fun saveCategory(existing: MenuCategory?, name: String) {
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val ok = if (existing == null) {
                    api.createCategory(CategoryCreateBody(name = name)).isSuccessful
                } else {
                    api.updateCategory(existing.id, CategoryUpdateBody(name = name)).isSuccessful
                }
                if (ok) {
                    InAppNotifier.show(activity, getString(R.string.menu_category_saved), InAppNotifier.Type.SUCCESS)
                    loadMenu()
                } else {
                    InAppNotifier.show(activity, getString(R.string.menu_save_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, getString(R.string.menu_save_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun confirmDeleteCategory(category: MenuCategory) {
        AlertDialog.Builder(requireContext())
            .setMessage(R.string.confirm_delete_category)
            .setPositiveButton(R.string.btn_delete) { _, _ -> deleteCategory(category) }
            .setNegativeButton(R.string.btn_cancel, null)
            .show()
    }

    private fun deleteCategory(category: MenuCategory) {
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                if (api.deleteCategory(category.id).isSuccessful) {
                    InAppNotifier.show(activity, getString(R.string.menu_category_deleted), InAppNotifier.Type.SUCCESS)
                    loadMenu()
                } else {
                    InAppNotifier.show(activity, getString(R.string.menu_save_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, getString(R.string.menu_save_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    // ---- Item add/edit ----

    private fun showItemDialog(category: MenuCategory? = null, existingItem: MenuItem? = null) {
        val targetCategoryId = existingItem?.categoryId ?: category?.id
        if (targetCategoryId == null) return

        val dialogBinding = DialogAddMenuItemBinding.inflate(layoutInflater)
        dialogBinding.inputItemName.setText(existingItem?.name ?: "")
        dialogBinding.inputItemPrice.setText(existingItem?.price?.let { "%.2f".format(it) } ?: "")
        dialogBinding.inputItemDescription.setText(existingItem?.description ?: "")
        dialogBinding.switchIsVeg.isChecked = existingItem?.isVeg ?: true

        AlertDialog.Builder(requireContext())
            .setTitle(if (existingItem == null) R.string.dialog_add_item_title else R.string.dialog_edit_item_title)
            .setView(dialogBinding.root)
            .setPositiveButton(R.string.btn_save) { _, _ ->
                val name = dialogBinding.inputItemName.text?.toString()?.trim().orEmpty()
                val priceText = dialogBinding.inputItemPrice.text?.toString()?.trim().orEmpty()
                val price = priceText.toDoubleOrNull()
                val description = dialogBinding.inputItemDescription.text?.toString()?.trim()
                val isVeg = dialogBinding.switchIsVeg.isChecked

                if (name.isEmpty() || price == null || price <= 0.0) {
                    InAppNotifier.show(activity, getString(R.string.menu_save_failed), InAppNotifier.Type.ERROR)
                    return@setPositiveButton
                }
                saveItem(existingItem, targetCategoryId, name, price, description, isVeg)
            }
            .setNegativeButton(R.string.btn_cancel, null)
            .show()
    }

    private fun saveItem(
        existing: MenuItem?,
        categoryId: Int,
        name: String,
        price: Double,
        description: String?,
        isVeg: Boolean
    ) {
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val ok = if (existing == null) {
                    api.createMenuItem(
                        MenuItemCreateBody(
                            categoryId = categoryId,
                            name = name,
                            price = price,
                            description = description,
                            isVeg = isVeg
                        )
                    ).isSuccessful
                } else {
                    api.updateMenuItem(
                        existing.id,
                        MenuItemUpdateBody(
                            categoryId = categoryId,
                            name = name,
                            price = price,
                            description = description,
                            isVeg = isVeg
                        )
                    ).isSuccessful
                }
                if (ok) {
                    InAppNotifier.show(activity, getString(R.string.menu_item_saved), InAppNotifier.Type.SUCCESS)
                    loadMenu()
                } else {
                    InAppNotifier.show(activity, getString(R.string.menu_save_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, getString(R.string.menu_save_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun toggleItemAvailable(item: MenuItem, available: Boolean) {
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val ok = api.updateMenuItem(item.id, MenuItemUpdateBody(isAvailable = available)).isSuccessful
                if (!ok) {
                    InAppNotifier.show(activity, getString(R.string.menu_save_failed), InAppNotifier.Type.ERROR)
                    loadMenu() // revert the switch to server truth
                } else {
                    // Keep local cache consistent without a full reload/flicker.
                    items = items.map { if (it.id == item.id) it.copy(isAvailable = available) else it }
                    adapter.submitData(categories, items)
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, getString(R.string.menu_save_failed), InAppNotifier.Type.ERROR)
                loadMenu()
            }
        }
    }

    private fun confirmDeleteItem(item: MenuItem) {
        AlertDialog.Builder(requireContext())
            .setMessage(R.string.confirm_delete_item)
            .setPositiveButton(R.string.btn_delete) { _, _ -> deleteItem(item) }
            .setNegativeButton(R.string.btn_cancel, null)
            .show()
    }

    private fun deleteItem(item: MenuItem) {
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                if (api.deleteMenuItem(item.id).isSuccessful) {
                    InAppNotifier.show(activity, getString(R.string.menu_item_deleted), InAppNotifier.Type.SUCCESS)
                    loadMenu()
                } else {
                    InAppNotifier.show(activity, getString(R.string.menu_save_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, getString(R.string.menu_save_failed), InAppNotifier.Type.ERROR)
            }
        }
    }
}
