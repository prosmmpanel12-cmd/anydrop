package com.anydrop.restaurant.ui.menu

import android.net.Uri
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.text.Editable
import android.text.TextWatcher
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.activity.result.contract.ActivityResultContracts
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.ItemTouchHelper
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import coil.load
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.DialogAddCategoryBinding
import com.anydrop.restaurant.databinding.DialogAddMenuItemBinding
import com.anydrop.restaurant.ui.common.CropActivity
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
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import java.io.File
import java.io.FileOutputStream

/**
 * Menu tab (§5 of the UI plan) — formerly its own pushed screen
 * (`MenuManagementActivity`, reached from a Dashboard button with a
 * slide transition), now one of four bottom-nav tabs hosted by
 * `MainActivity`.
 *
 * §10 item 4: photo thumbnail slot (`MenuItemAdapter`/`CategoryAdapter`,
 * `imageUrl` via Coil, placeholder icon when unset), photo picking + the
 * matching upload-then-save flow for both dish and category photos (this
 * fragment stages picked Uris the same way `EditProfileActivity` stages
 * its logo pick — see `pickedItemPhotoUri`/`pickedCategoryPhotoUri`), the
 * `?search=` bar wired to `menu-items-list.php` (debounced), the §9.2
 * skeleton state shown on first load, the §5 category-tabs-strip (shown
 * once there are 5+ active categories), and drag-to-reorder on category
 * rows (persisted via `categories-update.php`'s `sort_order` field).
 *
 * Tier 1 "Menu Management" (docs/18). Category + food item add/edit/
 * delete, price update, veg/non-veg toggle (on the add/edit form), and
 * the out-of-stock quick-toggle switch on each item row.
 *
 * Three filter states — search, tab-strip category filter, and reorder
 * mode — are treated as mutually exclusive rather than combined: starting
 * a search or entering reorder mode both clear/hide the tab strip
 * selection, and reorder mode also clears any active search. Trying to
 * support all three at once (e.g. "reorder within a search result") would
 * add real complexity for a combination that doesn't make sense anyway
 * (you can't meaningfully reorder categories you can't all see).
 *
 * Not in this pass: customization/add-on group UI, item availability
 * time-of-day windows.
 */
class MenuFragment : Fragment() {

    private var _binding: FragmentMenuBinding? = null
    private val binding get() = _binding!!

    private val api: ApiService by lazy { ApiClient.create(requireContext()) }
    private lateinit var adapter: CategoryAdapter
    private lateinit var tabAdapter: CategoryTabAdapter
    private lateinit var itemTouchHelper: ItemTouchHelper

    private var categories: List<MenuCategory> = emptyList()
    private var visibleCategories: List<MenuCategory> = emptyList()
    private var items: List<MenuItem> = emptyList()

    // Debounced search — mirrors the Customer app's search-as-you-type
    // pattern rather than firing a request per keystroke.
    private val searchHandler = Handler(Looper.getMainLooper())
    private var pendingSearchRunnable: Runnable? = null
    private var currentSearchQuery: String? = null
    private var hasLoadedOnce = false

    // §5 category tabs strip — null = "All" (no filter). Only consulted
    // when there are 5+ active categories AND no search is active; see
    // updateTabStripVisibility().
    private var selectedTabCategoryId: Int? = null

    // §10 item 4 follow-up — drag-to-reorder.
    private var reorderMode = false
    private var isSavingReorder = false

    // Dish/category photo upload (NEXT_SESSION_PROMPT.md item 4). Same
    // "stage a local Uri, upload only fires on dialog Save" pattern as
    // EditProfileActivity.pickedLogoUri — cancelling the add/edit dialog
    // never orphans a DB write (the uploaded file itself being an orphan
    // is an acceptable cheap cost, same reasoning as the logo). The
    // *Dialog vars point at whichever add/edit dialog is currently open,
    // if any, so the activity-result callback (which fires independently
    // of any dialog) can update the right preview and clears back to null
    // in each dialog's onDismiss so a stale reference can't leak into the
    // next dialog opened.
    private var pickedItemPhotoUri: Uri? = null
    private var currentItemDialogBinding: DialogAddMenuItemBinding? = null
    private var pickedCategoryPhotoUri: Uri? = null
    private var currentCategoryDialogBinding: DialogAddCategoryBinding? = null
    // doc 22 item 1 — bundled category icon, mutually exclusive with
    // pickedCategoryPhotoUri (picking one clears the other, both in this
    // fragment and server-side, see categories-update.php's kdoc).
    private var pickedCategoryIconKey: String? = null

    // Crop screen (app-owner feedback item #2, 2026-08-17) — both pickers
    // below now route the freshly-picked Uri through CropActivity before
    // staging it, same "pick → crop → stage locally, upload on Save" flow
    // as EditProfileActivity's logo picker. Dish photos offer a ratio
    // choice (SLOT_DISH_PHOTO); category icons stay square-only, same
    // reasoning as the logo (always shown as a small square/circle).
    private val pickItemPhotoLauncher =
        registerForActivityResult(ActivityResultContracts.GetContent()) { uri ->
            if (uri != null) {
                CropActivity.start(requireContext(), uri, CropActivity.SLOT_DISH_PHOTO, cropItemPhotoLauncher)
            }
        }

    private val cropItemPhotoLauncher =
        registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
            if (result.resultCode == android.app.Activity.RESULT_OK) {
                val croppedUri = CropActivity.getResultUri(result.data) ?: return@registerForActivityResult
                pickedItemPhotoUri = croppedUri
                currentItemDialogBinding?.let { b ->
                    b.itemPhotoPreview.imageTintList = null
                    b.itemPhotoPreview.setPadding(0, 0, 0, 0)
                    b.itemPhotoPreview.scaleType = android.widget.ImageView.ScaleType.CENTER_CROP
                    b.itemPhotoPreview.load(croppedUri) {
                        placeholder(R.drawable.ic_food_placeholder)
                        error(R.drawable.ic_food_placeholder)
                        crossfade(true)
                    }
                    b.itemPhotoLabel.text = getString(R.string.btn_change_photo)
                }
            }
        }

    private val pickCategoryPhotoLauncher =
        registerForActivityResult(ActivityResultContracts.GetContent()) { uri ->
            if (uri != null) {
                CropActivity.start(requireContext(), uri, CropActivity.SLOT_SQUARE_ONLY, cropCategoryPhotoLauncher)
            }
        }

    private val cropCategoryPhotoLauncher =
        registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
            if (result.resultCode == android.app.Activity.RESULT_OK) {
                val croppedUri = CropActivity.getResultUri(result.data) ?: return@registerForActivityResult
                pickedCategoryPhotoUri = croppedUri
                pickedCategoryIconKey = null // mutually exclusive — a real photo replaces any bundled icon pick
                currentCategoryDialogBinding?.let { b ->
                    b.categoryPhotoPreview.imageTintList = null
                    b.categoryPhotoPreview.setPadding(0, 0, 0, 0)
                    b.categoryPhotoPreview.scaleType = android.widget.ImageView.ScaleType.CENTER_CROP
                    b.categoryPhotoPreview.load(croppedUri) {
                        placeholder(R.drawable.ic_food_placeholder)
                        error(R.drawable.ic_food_placeholder)
                        crossfade(true)
                    }
                    b.categoryPhotoLabel.text = getString(R.string.btn_change_photo)
                }
            }
        }

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
            onDeleteItem = { confirmDeleteItem(it) },
            onStartDrag = { holder -> itemTouchHelper.startDrag(holder) }
        )
        binding.categoriesRecycler.layoutManager = LinearLayoutManager(requireContext())
        binding.categoriesRecycler.adapter = adapter

        // §10 item 4 follow-up — drag-to-reorder. Vertical up/down drag
        // only (no swipe-to-dismiss); onMove just live-reorders the
        // adapter's in-memory list, onSelectedChanged/clearView handle
        // start/stop background tinting, and persistence happens once the
        // user taps "Done" (see toggleReorderMode()), not per-swap.
        itemTouchHelper = ItemTouchHelper(object : ItemTouchHelper.SimpleCallback(
            ItemTouchHelper.UP or ItemTouchHelper.DOWN, 0
        ) {
            override fun onMove(
                recyclerView: RecyclerView,
                viewHolder: RecyclerView.ViewHolder,
                target: RecyclerView.ViewHolder
            ): Boolean {
                adapter.moveItem(viewHolder.bindingAdapterPosition, target.bindingAdapterPosition)
                return true
            }

            override fun isLongPressDragEnabled() = false
            override fun onSwiped(viewHolder: RecyclerView.ViewHolder, direction: Int) {}
        })
        itemTouchHelper.attachToRecyclerView(binding.categoriesRecycler)

        tabAdapter = CategoryTabAdapter(requireContext()) { categoryId ->
            selectedTabCategoryId = categoryId
            applyDisplayFilter()
        }
        binding.categoryTabsRecycler.layoutManager =
            LinearLayoutManager(requireContext(), LinearLayoutManager.HORIZONTAL, false)
        binding.categoryTabsRecycler.adapter = tabAdapter

        binding.btnAddCategory.setOnClickListener { showCategoryDialog() }
        binding.btnReorderCategories.setOnClickListener { toggleReorderMode() }
        binding.swipeRefresh.setOnRefreshListener { loadMenu(showSkeleton = false) }

        binding.searchInput.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            override fun afterTextChanged(s: Editable?) {
                pendingSearchRunnable?.let { searchHandler.removeCallbacks(it) }
                val query = s?.toString()?.trim().orEmpty()
                val runnable = Runnable {
                    currentSearchQuery = query.ifEmpty { null }
                    // A search is a fresh fetch, not a refresh of data
                    // already on screen — show the skeleton the same way
                    // the very first load does (§9.1), since the result
                    // set is effectively new.
                    loadMenu(showSkeleton = true)
                }
                pendingSearchRunnable = runnable
                searchHandler.postDelayed(runnable, SEARCH_DEBOUNCE_MS)
            }
        })

        loadMenu(showSkeleton = true)
    }

    override fun onDestroyView() {
        pendingSearchRunnable?.let { searchHandler.removeCallbacks(it) }
        // AlertDialogs aren't tied to the fragment view lifecycle and
        // would otherwise leak a reference to a detached dialog binding.
        currentItemDialogBinding = null
        currentCategoryDialogBinding = null
        super.onDestroyView()
        _binding = null
    }

    private fun loadMenu(showSkeleton: Boolean) {
        // Only show the skeleton on a genuinely empty screen (first load,
        // or a search that hasn't returned anything yet) — never on top
        // of a pull-to-refresh where content is already visible (§9.1).
        val shouldShowSkeleton = showSkeleton && (!hasLoadedOnce || categories.isEmpty())
        if (shouldShowSkeleton) {
            binding.menuSkeletonScroll.visibility = View.VISIBLE
            binding.categoriesRecycler.visibility = View.GONE
            binding.emptyText.visibility = View.GONE
        }

        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val catResp = api.getCategories().body()?.data?.categories ?: emptyList()
                val itemResp = api.getMenuItems(search = currentSearchQuery).body()?.data?.items ?: emptyList()
                categories = catResp
                items = itemResp
                hasLoadedOnce = true
                applyDisplayFilter()
            } catch (e: Exception) {
                InAppNotifier.show(activity, getString(R.string.menu_load_failed), InAppNotifier.Type.ERROR)
            } finally {
                if (_binding != null) {
                    binding.swipeRefresh.isRefreshing = false
                    binding.menuSkeletonScroll.visibility = View.GONE
                    binding.categoriesRecycler.visibility = View.VISIBLE
                }
            }
        }
    }

    /**
     * Recomputes `visibleCategories` from the current `categories`/`items`
     * plus whichever one of search / tab-selection / reorder-mode is
     * currently active (see the class doc for why those three are treated
     * as mutually exclusive), then pushes the result into both adapters
     * and updates the tab strip's visibility + empty-state text. Called
     * after every fresh load and every local filter-state change (tab tap,
     * entering/exiting reorder mode) — never needs a network round trip on
     * its own.
     */
    private fun applyDisplayFilter() {
        if (_binding == null) return
        val activeCategories = categories.filter { it.isActive }
        // A selected tab whose category got deleted/deactivated since the
        // last load falls back to "All" rather than filtering to nothing.
        if (selectedTabCategoryId != null && activeCategories.none { it.id == selectedTabCategoryId }) {
            selectedTabCategoryId = null
        }

        if (reorderMode) {
            // Reorder mode always shows every active category, ignoring
            // search/tab filters — see class doc.
            visibleCategories = activeCategories
            adapter.submitData(categories, items)
            binding.emptyText.visibility = View.GONE
            return
        }

        visibleCategories = when {
            currentSearchQuery != null -> {
                // Only show categories that still have a matching item —
                // an empty category card under a search query would just
                // be noise.
                val matchingItemCategoryIds = items.map { it.categoryId }.toSet()
                activeCategories.filter { it.id in matchingItemCategoryIds }
            }
            selectedTabCategoryId != null -> activeCategories.filter { it.id == selectedTabCategoryId }
            else -> activeCategories
        }
        adapter.submitData(visibleCategories, items)

        updateTabStrip(activeCategories)

        binding.emptyText.text = if (currentSearchQuery != null) {
            getString(R.string.menu_search_no_results, currentSearchQuery)
        } else {
            getString(R.string.empty_categories)
        }
        binding.emptyText.visibility = if (visibleCategories.isEmpty()) View.VISIBLE else View.GONE
    }

    /**
     * §5 — tab strip only appears once there are 5+ active categories, and
     * only while neither searching nor reordering (both of those already
     * show their own full-list view, see applyDisplayFilter()).
     */
    private fun updateTabStrip(activeCategories: List<MenuCategory>) {
        val shouldShow = activeCategories.size >= TAB_STRIP_MIN_CATEGORIES && currentSearchQuery == null
        binding.categoryTabsRecycler.visibility = if (shouldShow) View.VISIBLE else View.GONE
        if (shouldShow) {
            tabAdapter.submitCategories(activeCategories)
            tabAdapter.setSelected(selectedTabCategoryId)
        }
    }

    // ---- Drag-to-reorder (§10 item 4 follow-up) ----

    private fun toggleReorderMode() {
        if (reorderMode) exitReorderMode(save = true) else enterReorderMode()
    }

    private fun enterReorderMode() {
        if (isSavingReorder) return
        reorderMode = true
        // Reordering needs every active category visible at once, so any
        // active search or tab filter is cleared for the duration — see
        // the class doc's "mutually exclusive" note.
        currentSearchQuery = null
        binding.searchInput.setText("")
        selectedTabCategoryId = null
        binding.searchBarContainer.visibility = View.GONE
        binding.categoryTabsRecycler.visibility = View.GONE
        binding.reorderHintText.visibility = View.VISIBLE
        binding.btnReorderCategories.text = getString(R.string.btn_reorder_done)
        binding.btnAddCategory.visibility = View.GONE
        binding.swipeRefresh.isEnabled = false // avoid pull-to-refresh fighting a vertical drag
        adapter.setReorderMode(true)
        applyDisplayFilter()
    }

    private fun exitReorderMode(save: Boolean) {
        if (isSavingReorder) return
        val newOrder = adapter.currentOrder()
        reorderMode = false
        binding.searchBarContainer.visibility = View.VISIBLE
        binding.reorderHintText.visibility = View.GONE
        binding.btnReorderCategories.text = getString(R.string.btn_reorder_categories)
        binding.btnAddCategory.visibility = View.VISIBLE
        binding.swipeRefresh.isEnabled = true
        adapter.setReorderMode(false)

        if (save) {
            persistReorder(newOrder)
        } else {
            applyDisplayFilter()
        }
    }

    /**
     * Writes sequential sort_order values (0, 1, 2...) for the on-screen
     * drag order via categories-update.php, one call per category whose
     * position actually changed. Sequential (not gapped) intentionally —
     * matches what categories-create.php's own "append to end" default
     * already assumes (current MAX(sort_order) + 1).
     */
    private fun persistReorder(newOrder: List<MenuCategory>) {
        val changed = newOrder.withIndex().filter { (index, category) -> category.sortOrder != index }
        if (changed.isEmpty()) {
            applyDisplayFilter()
            return
        }
        isSavingReorder = true
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                var allOk = true
                for ((index, category) in changed) {
                    val ok = api.updateCategory(category.id, CategoryUpdateBody(sortOrder = index)).isSuccessful
                    if (!ok) allOk = false
                }
                if (allOk) {
                    InAppNotifier.show(activity, getString(R.string.menu_reorder_saved), InAppNotifier.Type.SUCCESS)
                } else {
                    InAppNotifier.show(activity, getString(R.string.menu_reorder_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, getString(R.string.menu_reorder_failed), InAppNotifier.Type.ERROR)
            } finally {
                isSavingReorder = false
                if (_binding != null) {
                    loadMenu(showSkeleton = false) // pull fresh sort_order/state from the server either way
                }
            }
        }
    }

    // ---- Category add/edit ----

    private fun showCategoryDialog(existing: MenuCategory? = null) {
        val dialogBinding = DialogAddCategoryBinding.inflate(layoutInflater)
        dialogBinding.inputCategoryName.setText(existing?.name ?: "")

        pickedCategoryPhotoUri = null
        // Pre-fill from the existing category's bundled icon (if any, and
        // if it doesn't already have a photo — the two are mutually
        // exclusive so a category never has both). Left as-is (not
        // re-sent) if the user never touches this section, same "only
        // send what changed" convention as pickedCategoryPhotoUri/
        // imageUrlToSave below.
        pickedCategoryIconKey = existing?.iconKey
        currentCategoryDialogBinding = dialogBinding
        val existingCategoryImageUrl = existing?.imageUrl
        if (!existingCategoryImageUrl.isNullOrBlank()) {
            dialogBinding.categoryPhotoPreview.imageTintList = null
            dialogBinding.categoryPhotoPreview.setPadding(0, 0, 0, 0)
            dialogBinding.categoryPhotoPreview.scaleType = android.widget.ImageView.ScaleType.CENTER_CROP
            dialogBinding.categoryPhotoPreview.load(ApiClient.baseUrlForStaticFiles(requireContext()) + existingCategoryImageUrl) {
                placeholder(R.drawable.ic_food_placeholder)
                error(R.drawable.ic_food_placeholder)
                crossfade(true)
            }
            dialogBinding.categoryPhotoLabel.text = getString(R.string.btn_change_photo)
        } else if (existing?.iconKey != null) {
            applyCategoryIconPreview(dialogBinding, existing.iconKey)
        }
        dialogBinding.categoryPhotoPickerRow.setOnClickListener { pickCategoryPhotoLauncher.launch("image/*") }
        dialogBinding.btnChooseCategoryIcon.setOnClickListener { showCategoryIconPickerDialog(dialogBinding) }

        MaterialAlertDialogBuilder(requireContext())
            .setTitle(if (existing == null) R.string.dialog_add_category_title else R.string.dialog_edit_category_title)
            .setView(dialogBinding.root)
            .setPositiveButton(R.string.btn_save) { _, _ ->
                val name = dialogBinding.inputCategoryName.text?.toString()?.trim().orEmpty()
                if (name.isEmpty()) {
                    InAppNotifier.show(activity, getString(R.string.menu_save_failed), InAppNotifier.Type.ERROR)
                    return@setPositiveButton
                }
                saveCategory(existing, name, pickedCategoryPhotoUri, pickedCategoryIconKey)
            }
            .setNegativeButton(R.string.btn_cancel, null)
            .setOnDismissListener { currentCategoryDialogBinding = null }
            .show()
    }

    /** doc 22 item 1 — opens the bundled-icon grid; picking one clears any
     * staged photo and updates the shared preview slot immediately. */
    private fun showCategoryIconPickerDialog(dialogBinding: DialogAddCategoryBinding) {
        val pickerBinding = DialogCategoryIconPickerBinding.inflate(layoutInflater)
        pickerBinding.categoryIconGrid.layoutManager = GridLayoutManager(requireContext(), 4)

        lateinit var pickerDialog: androidx.appcompat.app.AlertDialog
        pickerBinding.categoryIconGrid.adapter = CategoryIconPickerAdapter(
            requireContext(),
            pickedCategoryIconKey
        ) { option ->
            pickedCategoryIconKey = option.key
            pickedCategoryPhotoUri = null // mutually exclusive — an icon pick replaces any staged photo
            applyCategoryIconPreview(dialogBinding, option.key)
            pickerDialog.dismiss()
        }

        pickerDialog = MaterialAlertDialogBuilder(requireContext())
            .setTitle(R.string.category_icon_picker_title)
            .setView(pickerBinding.root)
            .setNegativeButton(R.string.btn_cancel, null)
            .create()
        pickerDialog.show()
    }

    /** Renders a bundled icon into the category dialog's shared preview
     * slot — same tinted/fit-center treatment CategoryAdapter already uses
     * for the "no photo" placeholder state, just with the picked icon's
     * drawable instead of ic_food_placeholder. */
    private fun applyCategoryIconPreview(dialogBinding: DialogAddCategoryBinding, iconKey: String) {
        dialogBinding.categoryPhotoPreview.scaleType = android.widget.ImageView.ScaleType.FIT_CENTER
        dialogBinding.categoryPhotoPreview.setPadding(20, 20, 20, 20)
        dialogBinding.categoryPhotoPreview.setImageResource(CategoryIcons.drawableFor(iconKey))
        dialogBinding.categoryPhotoPreview.imageTintList =
            android.content.res.ColorStateList.valueOf(requireContext().getColor(R.color.anydrop_primary))
        dialogBinding.categoryPhotoLabel.text = getString(R.string.btn_add_photo)
    }

    private fun saveCategory(existing: MenuCategory?, name: String, photoUri: Uri?, iconKey: String?) {
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                // Upload the photo first (if a new one was picked), same
                // two-step split as EditProfileActivity's logo handling —
                // the upload endpoint only saves the file and returns a
                // path, it never touches the DB itself.
                var imageUrlToSave: String? = null
                if (photoUri != null) {
                    imageUrlToSave = uploadCategoryPhoto(photoUri)
                    if (imageUrlToSave == null) {
                        InAppNotifier.show(activity, getString(R.string.photo_upload_failed), InAppNotifier.Type.ERROR)
                        return@launch
                    }
                }

                val ok = if (existing == null) {
                    api.createCategory(CategoryCreateBody(name = name, imageUrl = imageUrlToSave, iconKey = iconKey)).isSuccessful
                } else {
                    api.updateCategory(existing.id, CategoryUpdateBody(name = name, imageUrl = imageUrlToSave, iconKey = iconKey)).isSuccessful
                }
                if (ok) {
                    InAppNotifier.show(activity, getString(R.string.menu_category_saved), InAppNotifier.Type.SUCCESS)
                    loadMenu(showSkeleton = false)
                } else {
                    InAppNotifier.show(activity, getString(R.string.menu_save_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, getString(R.string.menu_save_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    /** Same copy-to-cache-file-then-multipart-upload approach as
     * EditProfileActivity.uploadLogo() — content Uris from GetContent()
     * aren't guaranteed to expose a real filesystem path. */
    private suspend fun uploadCategoryPhoto(uri: Uri): String? {
        val context = context ?: return null
        val mimeType = context.contentResolver.getType(uri) ?: "image/jpeg"
        val ext = when (mimeType) {
            "image/png" -> "png"
            "image/webp" -> "webp"
            else -> "jpg"
        }
        val tempFile = File(context.cacheDir, "category_photo_upload.$ext")
        context.contentResolver.openInputStream(uri)?.use { input ->
            FileOutputStream(tempFile).use { output -> input.copyTo(output) }
        } ?: return null

        val requestBody = tempFile.asRequestBody(mimeType.toMediaTypeOrNull())
        val part = MultipartBody.Part.createFormData("photo", tempFile.name, requestBody)

        val response = api.uploadCategoryPhoto(part)
        tempFile.delete()

        return if (response.isSuccessful && response.body()?.success == true) {
            response.body()?.data?.imageUrl
        } else {
            null
        }
    }

    private fun confirmDeleteCategory(category: MenuCategory) {
        MaterialAlertDialogBuilder(requireContext())
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
                    loadMenu(showSkeleton = false)
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

        pickedItemPhotoUri = null
        currentItemDialogBinding = dialogBinding
        val existingItemImageUrl = existingItem?.imageUrl
        if (!existingItemImageUrl.isNullOrBlank()) {
            dialogBinding.itemPhotoPreview.imageTintList = null
            dialogBinding.itemPhotoPreview.setPadding(0, 0, 0, 0)
            dialogBinding.itemPhotoPreview.scaleType = android.widget.ImageView.ScaleType.CENTER_CROP
            dialogBinding.itemPhotoPreview.load(ApiClient.baseUrlForStaticFiles(requireContext()) + existingItemImageUrl) {
                placeholder(R.drawable.ic_food_placeholder)
                error(R.drawable.ic_food_placeholder)
                crossfade(true)
            }
            dialogBinding.itemPhotoLabel.text = getString(R.string.btn_change_photo)
        }
        dialogBinding.itemPhotoPickerRow.setOnClickListener { pickItemPhotoLauncher.launch("image/*") }

        MaterialAlertDialogBuilder(requireContext())
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
                saveItem(existingItem, targetCategoryId, name, price, description, isVeg, pickedItemPhotoUri)
            }
            .setNegativeButton(R.string.btn_cancel, null)
            .setOnDismissListener { currentItemDialogBinding = null }
            .show()
    }

    private fun saveItem(
        existing: MenuItem?,
        categoryId: Int,
        name: String,
        price: Double,
        description: String?,
        isVeg: Boolean,
        photoUri: Uri?
    ) {
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                // Upload the photo first (if a new one was picked) — same
                // upload-then-save split as the category flow above and
                // EditProfileActivity's logo handling.
                var imageUrlToSave: String? = null
                if (photoUri != null) {
                    imageUrlToSave = uploadItemPhoto(photoUri)
                    if (imageUrlToSave == null) {
                        InAppNotifier.show(activity, getString(R.string.photo_upload_failed), InAppNotifier.Type.ERROR)
                        return@launch
                    }
                }

                val ok = if (existing == null) {
                    api.createMenuItem(
                        MenuItemCreateBody(
                            categoryId = categoryId,
                            name = name,
                            price = price,
                            description = description,
                            isVeg = isVeg,
                            imageUrl = imageUrlToSave
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
                            isVeg = isVeg,
                            imageUrl = imageUrlToSave
                        )
                    ).isSuccessful
                }
                if (ok) {
                    InAppNotifier.show(activity, getString(R.string.menu_item_saved), InAppNotifier.Type.SUCCESS)
                    loadMenu(showSkeleton = false)
                } else {
                    InAppNotifier.show(activity, getString(R.string.menu_save_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, getString(R.string.menu_save_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    /** Same copy-to-cache-file-then-multipart-upload approach as
     * EditProfileActivity.uploadLogo() / uploadCategoryPhoto() above. */
    private suspend fun uploadItemPhoto(uri: Uri): String? {
        val context = context ?: return null
        val mimeType = context.contentResolver.getType(uri) ?: "image/jpeg"
        val ext = when (mimeType) {
            "image/png" -> "png"
            "image/webp" -> "webp"
            else -> "jpg"
        }
        val tempFile = File(context.cacheDir, "item_photo_upload.$ext")
        context.contentResolver.openInputStream(uri)?.use { input ->
            FileOutputStream(tempFile).use { output -> input.copyTo(output) }
        } ?: return null

        val requestBody = tempFile.asRequestBody(mimeType.toMediaTypeOrNull())
        val part = MultipartBody.Part.createFormData("photo", tempFile.name, requestBody)

        val response = api.uploadMenuItemPhoto(part)
        tempFile.delete()

        return if (response.isSuccessful && response.body()?.success == true) {
            response.body()?.data?.imageUrl
        } else {
            null
        }
    }

    private fun toggleItemAvailable(item: MenuItem, available: Boolean) {
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val ok = api.updateMenuItem(item.id, MenuItemUpdateBody(isAvailable = available)).isSuccessful
                if (!ok) {
                    InAppNotifier.show(activity, getString(R.string.menu_save_failed), InAppNotifier.Type.ERROR)
                    loadMenu(showSkeleton = false) // revert the switch to server truth
                } else {
                    // Keep local cache consistent without a full reload/flicker.
                    items = items.map { if (it.id == item.id) it.copy(isAvailable = available) else it }
                    adapter.submitData(visibleCategories, items)
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, getString(R.string.menu_save_failed), InAppNotifier.Type.ERROR)
                loadMenu(showSkeleton = false)
            }
        }
    }

    private fun confirmDeleteItem(item: MenuItem) {
        MaterialAlertDialogBuilder(requireContext())
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
                    loadMenu(showSkeleton = false)
                } else {
                    InAppNotifier.show(activity, getString(R.string.menu_save_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(activity, getString(R.string.menu_save_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    companion object {
        private const val SEARCH_DEBOUNCE_MS = 400L
        // §5 — "once a restaurant has 5+ categories."
        private const val TAB_STRIP_MIN_CATEGORIES = 5
    }
}
