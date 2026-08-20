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
import androidx.recyclerview.widget.GridLayoutManager
import androidx.recyclerview.widget.ItemTouchHelper
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import coil.load
import com.google.android.material.bottomsheet.BottomSheetDialog
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.DialogAddCategoryBinding
import com.anydrop.restaurant.databinding.DialogAddMenuItemBinding
import com.anydrop.restaurant.databinding.DialogCategoryIconPickerBinding
import com.anydrop.restaurant.databinding.DialogConfirmDeleteBinding
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
import com.anydrop.restaurant.network.external.ExternalApiClient
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

    // Tag picker (Pizza / Onion / Capsicum / ...) — fetched once per
    // fragment lifetime (list barely changes, same "load once, cache"
    // reasoning as MainActivity's own reference-data fetches) and reused
    // across every showItemDialog() call rather than re-hitting the
    // network on every dialog open.
    private var cachedFoodTags: List<com.anydrop.restaurant.network.FoodTag>? = null
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
                currentCategoryDialogBinding?.let { b -> applyCategoryPhotoPreview(b, croppedUri) }
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
        // Neither the category dialog (AlertDialog) nor the item dialog
        // (BottomSheetDialog, since doc 22's Phase 3 bottom-sheet split)
        // is tied to the fragment view lifecycle — both would otherwise
        // leak a reference to a detached dialog binding.
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

    /** doc 22 item 1 + Phase 1 of the 2026-08-19 UI/UX overhaul — opens a
     * 3-tab picker (Bundled / Icons / Photos, the latter two backed by
     * live search against Iconify/Openverse). Picking any result in any
     * tab clears the other of icon-key/staged-photo, updates the shared
     * preview slot in [dialogBinding], and dismisses this dialog — same
     * end state as before, just three ways to reach it now instead of
     * one. See [downloadRemoteImageToLocalFile] for how a search result
     * (a remote URL) becomes the same kind of local staged Uri a gallery
     * pick already produces. */
    private fun showCategoryIconPickerDialog(dialogBinding: DialogAddCategoryBinding) {
        val pickerBinding = DialogCategoryIconPickerBinding.inflate(layoutInflater)
        val gridLayoutManager4 = GridLayoutManager(requireContext(), 4)
        val gridLayoutManager3 = GridLayoutManager(requireContext(), 3)
        pickerBinding.categoryIconGrid.layoutManager = gridLayoutManager4

        lateinit var pickerDialog: androidx.appcompat.app.AlertDialog
        var pendingIconSearchRunnable: Runnable? = null

        fun setBusy(busy: Boolean) {
            pickerBinding.searchProgress.visibility = if (busy) View.VISIBLE else View.GONE
        }

        fun setEmptyState(text: String?) {
            pickerBinding.searchEmptyState.visibility = if (text != null) View.VISIBLE else View.GONE
            pickerBinding.searchEmptyState.text = text ?: ""
        }

        val bundledAdapter = CategoryIconPickerAdapter(requireContext(), pickedCategoryIconKey) { option ->
            pickedCategoryIconKey = option.key
            pickedCategoryPhotoUri = null // mutually exclusive — an icon pick replaces any staged photo
            applyCategoryIconPreview(dialogBinding, option.key)
            pickerDialog.dismiss()
        }
        val iconSearchAdapter = CategoryIconSearchAdapter { result ->
            onIconSearchResultPicked(dialogBinding, pickerBinding, result) { pickerDialog.dismiss() }
        }
        val photoSearchAdapter = CategoryPhotoSearchAdapter { image ->
            onPhotoSearchResultPicked(dialogBinding, pickerBinding, image) { pickerDialog.dismiss() }
        }

        fun runIconSearch(query: String) {
            if (query.isBlank()) {
                iconSearchAdapter.submit(emptyList())
                setBusy(false)
                setEmptyState(getString(R.string.icon_picker_search_prompt))
                return
            }
            setBusy(true)
            setEmptyState(null)
            viewLifecycleOwner.lifecycleScope.launch {
                // Fallback chain (2026-08-20 — app-owner asked for more
                // icon sources here too, alongside the Photos tab fix
                // below). Each provider already swallows its own
                // exceptions and returns an empty list on failure/timeout
                // (see ExternalApiClient's search*() wrappers) — this
                // just tries the next one whenever the current one comes
                // back empty, for whatever reason (down, rate-limited, no
                // results for this query). Iconify first since it's by
                // far the largest/most reliable icon source when it's up.
                // Google Material Icons + Flaticon added in the same
                // 2026-08-20 pass to widen the chain to 5 sources total
                // (Flaticon is key-gated like the photo chain's
                // Pixabay/Pexels/Unsplash — see res/values/api_keys.xml).
                val iconKeys = ExternalApiClient.readOptionalApiKeys(requireContext())
                var results = ExternalApiClient.searchIconsIconify(query)
                if (results.isEmpty()) results = ExternalApiClient.searchIconsOpenclipart(query)
                if (results.isEmpty()) results = ExternalApiClient.searchIconsWikimedia(query)
                if (results.isEmpty()) results = ExternalApiClient.searchIconsGoogleMaterial(query)
                if (results.isEmpty()) results = ExternalApiClient.searchIconsFlaticon(query, iconKeys.flaticon)
                iconSearchAdapter.submit(results)
                setBusy(false)
                setEmptyState(if (results.isEmpty()) getString(R.string.icon_picker_search_no_results) else null)
            }
        }

        fun runPhotoSearch(query: String) {
            if (query.isBlank()) {
                photoSearchAdapter.submit(emptyList())
                setBusy(false)
                setEmptyState(getString(R.string.icon_picker_search_prompt))
                return
            }
            setBusy(true)
            setEmptyState(null)
            viewLifecycleOwner.lifecycleScope.launch {
                // Same fallback-chain reasoning as runIconSearch() above,
                // just a longer chain (this is the tab that was actually
                // reported broken) — Openverse first (real photos, best
                // match for "search photos" when it's up), then two more
                // official no-key sources, then the three key-gated ones
                // (each a genuine no-op until a free key is filled in —
                // see res/values/api_keys.xml), then the DuckDuckGo
                // scrape as an absolute last resort.
                val keys = ExternalApiClient.readOptionalApiKeys(requireContext())
                var results = ExternalApiClient.searchPhotosOpenverse(query)
                if (results.isEmpty()) results = ExternalApiClient.searchPhotosWikimedia(query)
                if (results.isEmpty()) results = ExternalApiClient.searchPhotosOpenclipart(query)
                if (results.isEmpty()) results = ExternalApiClient.searchPhotosPixabay(query, keys.pixabay)
                if (results.isEmpty()) results = ExternalApiClient.searchPhotosPexels(query, keys.pexels)
                if (results.isEmpty()) results = ExternalApiClient.searchPhotosUnsplash(query, keys.unsplash)
                if (results.isEmpty()) results = ExternalApiClient.searchPhotosDuckDuckGoScrape(query)
                photoSearchAdapter.submit(results)
                setBusy(false)
                setEmptyState(if (results.isEmpty()) getString(R.string.icon_picker_search_no_results) else null)
            }
        }

        fun selectTab(tab: IconPickerTab) {
            pendingIconSearchRunnable?.let { searchHandler.removeCallbacks(it) }
            pickerBinding.iconSearchInput.text?.clear()
            when (tab) {
                IconPickerTab.BUNDLED -> {
                    pickerBinding.searchInputLayout.visibility = View.GONE
                    pickerBinding.categoryIconGrid.layoutManager = gridLayoutManager4
                    pickerBinding.categoryIconGrid.adapter = bundledAdapter
                    setBusy(false)
                    setEmptyState(null)
                }
                IconPickerTab.ICONS -> {
                    pickerBinding.searchInputLayout.visibility = View.VISIBLE
                    pickerBinding.categoryIconGrid.layoutManager = gridLayoutManager4
                    pickerBinding.categoryIconGrid.adapter = iconSearchAdapter
                    iconSearchAdapter.submit(emptyList())
                    setEmptyState(getString(R.string.icon_picker_search_prompt))
                }
                IconPickerTab.PHOTOS -> {
                    pickerBinding.searchInputLayout.visibility = View.VISIBLE
                    pickerBinding.categoryIconGrid.layoutManager = gridLayoutManager3
                    pickerBinding.categoryIconGrid.adapter = photoSearchAdapter
                    photoSearchAdapter.submit(emptyList())
                    setEmptyState(getString(R.string.icon_picker_search_prompt))
                }
            }
        }

        pickerBinding.iconPickerTabGroup.addOnButtonCheckedListener { _, checkedId, isChecked ->
            if (!isChecked) return@addOnButtonCheckedListener
            selectTab(
                when (checkedId) {
                    pickerBinding.tabIcons.id -> IconPickerTab.ICONS
                    pickerBinding.tabPhotos.id -> IconPickerTab.PHOTOS
                    else -> IconPickerTab.BUNDLED
                }
            )
        }

        pickerBinding.iconSearchInput.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            override fun afterTextChanged(s: Editable?) {
                pendingIconSearchRunnable?.let { searchHandler.removeCallbacks(it) }
                val query = s?.toString()?.trim().orEmpty()
                val isPhotosTab = pickerBinding.categoryIconGrid.adapter === photoSearchAdapter
                val runnable = Runnable { if (isPhotosTab) runPhotoSearch(query) else runIconSearch(query) }
                pendingIconSearchRunnable = runnable
                searchHandler.postDelayed(runnable, SEARCH_DEBOUNCE_MS)
            }
        })

        selectTab(IconPickerTab.BUNDLED)

        pickerDialog = MaterialAlertDialogBuilder(requireContext())
            .setTitle(R.string.category_icon_picker_title)
            .setView(pickerBinding.root)
            .setNegativeButton(R.string.btn_cancel, null)
            .setOnDismissListener {
                pendingIconSearchRunnable?.let { searchHandler.removeCallbacks(it) }
            }
            .create()
        pickerDialog.show()
    }

    private enum class IconPickerTab { BUNDLED, ICONS, PHOTOS }

    /** A picked icon-search result (Iconify/Openclipart/Wikimedia,
     * whichever provider actually served it — see runIconSearch()'s
     * fallback chain) — download it (rasterizing via Coil's SvgDecoder
     * first when [com.anydrop.restaurant.network.external.SearchResultImage.isSvg]
     * says the source is Iconify's SVG; every other provider's result is
     * already a plain raster preview, no SVG step needed) to a local PNG
     * and stage it exactly like a gallery photo pick. [onDone] dismisses
     * the picker dialog once staging finishes (success or failure — a
     * failed download shouldn't leave the picker stuck open with no
     * feedback). */
    private fun onIconSearchResultPicked(
        dialogBinding: DialogAddCategoryBinding,
        pickerBinding: com.anydrop.restaurant.databinding.DialogCategoryIconPickerBinding,
        result: com.anydrop.restaurant.network.external.SearchResultImage,
        onDone: () -> Unit
    ) {
        pickerBinding.searchProgress.visibility = View.VISIBLE
        viewLifecycleOwner.lifecycleScope.launch {
            val localUri = downloadRemoteImageToLocalFile(result.downloadUrl, isSvg = result.isSvg, fileName = "category_icon_search.png")
            pickerBinding.searchProgress.visibility = View.GONE
            if (localUri != null) {
                pickedCategoryPhotoUri = localUri
                pickedCategoryIconKey = null // mutually exclusive — same as any other photo pick
                applyCategoryPhotoPreview(dialogBinding, localUri)
            } else {
                InAppNotifier.show(activity, getString(R.string.icon_picker_search_failed), InAppNotifier.Type.ERROR)
            }
            onDone()
        }
    }

    /** Same shape as [onIconSearchResultPicked], for a picked photo-search
     * result (any of up to six providers plus the DuckDuckGo scrape
     * fallback — see runPhotoSearch()'s chain) — none of the photo-chain
     * providers return SVG content, so this never needs the SvgDecoder
     * step, just a plain network-image download to a local file. */
    private fun onPhotoSearchResultPicked(
        dialogBinding: DialogAddCategoryBinding,
        pickerBinding: com.anydrop.restaurant.databinding.DialogCategoryIconPickerBinding,
        image: com.anydrop.restaurant.network.external.SearchResultImage,
        onDone: () -> Unit
    ) {
        pickerBinding.searchProgress.visibility = View.VISIBLE
        viewLifecycleOwner.lifecycleScope.launch {
            val localUri = downloadRemoteImageToLocalFile(image.downloadUrl, isSvg = false, fileName = "category_photo_search.jpg")
            pickerBinding.searchProgress.visibility = View.GONE
            if (localUri != null) {
                pickedCategoryPhotoUri = localUri
                pickedCategoryIconKey = null
                applyCategoryPhotoPreview(dialogBinding, localUri)
            } else {
                InAppNotifier.show(activity, getString(R.string.icon_picker_search_failed), InAppNotifier.Type.ERROR)
            }
            onDone()
        }
    }

    /** Downloads [url] (optionally decoding it as an SVG first) via the
     * app's shared Coil [coil.ImageLoader] and writes the resulting
     * bitmap to a local cache file, returning a `file://` Uri — the same
     * kind of Uri [pickCategoryPhotoLauncher]'s gallery-pick flow already
     * produces, so every downstream step (staged-preview, upload-on-Save
     * via [uploadCategoryPhoto]) is unaware of whether a photo came from
     * the device gallery or a live search result. Returns null on any
     * failure (network error, non-2xx, undecodable image) rather than
     * throwing — callers show one shared error message either way. */
    private suspend fun downloadRemoteImageToLocalFile(url: String, isSvg: Boolean, fileName: String): Uri? {
        val context = context ?: return null
        return try {
            val requestBuilder = coil.request.ImageRequest.Builder(context)
                .data(url)
                .allowHardware(false) // must be a software bitmap to read its pixels below
            if (isSvg) requestBuilder.decoderFactory(coil.decode.SvgDecoder.Factory())
            val result = coil.Coil.imageLoader(context).execute(requestBuilder.build())
            if (result !is coil.request.SuccessResult) return null
            val bitmap = (result.drawable as? android.graphics.drawable.BitmapDrawable)?.bitmap ?: return null
            val file = File(context.cacheDir, fileName)
            FileOutputStream(file).use { output ->
                bitmap.compress(android.graphics.Bitmap.CompressFormat.PNG, 100, output)
            }
            Uri.fromFile(file)
        } catch (e: Exception) {
            null
        }
    }

    /** Shared "a real photo (from the gallery, or now, a downloaded
     * search result) is staged" preview treatment — factored out of
     * [cropCategoryPhotoLauncher]'s callback so both call sites render
     * identically instead of drifting apart. */
    private fun applyCategoryPhotoPreview(dialogBinding: DialogAddCategoryBinding, uri: Uri) {
        dialogBinding.categoryPhotoPreview.imageTintList = null
        dialogBinding.categoryPhotoPreview.setPadding(0, 0, 0, 0)
        dialogBinding.categoryPhotoPreview.scaleType = android.widget.ImageView.ScaleType.CENTER_CROP
        dialogBinding.categoryPhotoPreview.load(uri) {
            placeholder(R.drawable.ic_food_placeholder)
            error(R.drawable.ic_food_placeholder)
            crossfade(true)
        }
        dialogBinding.categoryPhotoLabel.text = getString(R.string.btn_change_photo)
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
        // Illustration retrofit (2026-08-19): was a plain
        // MaterialAlertDialogBuilder(.setMessage(...)) text dialog —
        // dialog_confirm_delete.xml is shared with confirmDeleteItem()
        // below, title/message set per call site.
        val dialogBinding = DialogConfirmDeleteBinding.inflate(layoutInflater)
        dialogBinding.deleteDialogTitle.text = getString(R.string.dialog_delete_category_title)
        dialogBinding.deleteDialogMessage.text = getString(R.string.confirm_delete_category)
        val dialog = MaterialAlertDialogBuilder(requireContext())
            .setView(dialogBinding.root)
            .create()
        dialogBinding.btnDeleteDialogCancel.setOnClickListener { dialog.dismiss() }
        dialogBinding.btnDeleteDialogDelete.setOnClickListener {
            dialog.dismiss()
            deleteCategory(category)
        }
        dialog.show()
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

        dialogBinding.itemDialogTitle.text =
            getString(if (existingItem == null) R.string.dialog_add_item_title else R.string.dialog_edit_item_title)

        loadFoodTagsIntoDialog(dialogBinding, existingItem?.tags ?: emptyList())

        // doc 22 item 2 follow-up — bottom sheet instead of a centered
        // MaterialAlertDialogBuilder (app-owner-confirmed split: add-coupon
        // + add-menu-item are the two "complex" dialogs that get bottom
        // sheets, everything else stays centered). No built-in positive/
        // negative buttons on a plain BottomSheetDialog, so the Save/Cancel
        // MaterialButtons in dialog_add_menu_item.xml do what
        // setPositiveButton/setNegativeButton used to.
        val itemDialog = BottomSheetDialog(requireContext())
        itemDialog.setContentView(dialogBinding.root)
        itemDialog.setOnDismissListener { currentItemDialogBinding = null }

        dialogBinding.btnItemDialogCancel.setOnClickListener { itemDialog.dismiss() }
        dialogBinding.btnItemDialogSave.setOnClickListener {
            val name = dialogBinding.inputItemName.text?.toString()?.trim().orEmpty()
            val priceText = dialogBinding.inputItemPrice.text?.toString()?.trim().orEmpty()
            val price = priceText.toDoubleOrNull()
            val description = dialogBinding.inputItemDescription.text?.toString()?.trim()
            val isVeg = dialogBinding.switchIsVeg.isChecked

            if (name.isEmpty() || price == null || price <= 0.0) {
                InAppNotifier.show(activity, getString(R.string.menu_save_failed), InAppNotifier.Type.ERROR)
                return@setOnClickListener
            }
            val selectedTags = (0 until dialogBinding.itemTagsGroup.childCount)
                .map { dialogBinding.itemTagsGroup.getChildAt(it) as com.google.android.material.chip.Chip }
                .filter { it.isChecked }
                .map { it.tag as String }
            saveItem(existingItem, targetCategoryId, name, price, description, isVeg, pickedItemPhotoUri, selectedTags)
            itemDialog.dismiss()
        }
        itemDialog.show()
    }

    /** Populates itemTagsGroup with one checkable Chip per active
     * food_category (fetched via food-tags-list.php, cached after the
     * first call), pre-checking whichever ones the item already carries
     * on an edit. Chip.tag holds the slug (not id) since that's what
     * the create/update bodies and MenuItem.tags both speak — avoids a
     * separate id<->slug lookup at save time. */
    private fun loadFoodTagsIntoDialog(dialogBinding: DialogAddMenuItemBinding, selectedSlugs: List<String>) {
        val cached = cachedFoodTags
        if (cached != null) {
            renderTagChips(dialogBinding, cached, selectedSlugs)
            return
        }
        dialogBinding.itemTagsEmptyLabel.visibility = View.VISIBLE
        viewLifecycleOwner.lifecycleScope.launch {
            try {
                val response = api.getFoodTags()
                val tags = if (response.isSuccessful) response.body()?.data?.tags.orEmpty() else emptyList()
                cachedFoodTags = tags
                // The dialog may already be dismissed by the time this
                // returns — guard against a stale binding the same way
                // pickItemPhotoLauncher's callback checks currentItemDialogBinding.
                if (currentItemDialogBinding === dialogBinding) {
                    renderTagChips(dialogBinding, tags, selectedSlugs)
                }
            } catch (e: Exception) {
                if (currentItemDialogBinding === dialogBinding) {
                    dialogBinding.itemTagsEmptyLabel.text = getString(R.string.label_item_tags_unavailable)
                }
            }
        }
    }

    private fun renderTagChips(
        dialogBinding: DialogAddMenuItemBinding,
        tags: List<com.anydrop.restaurant.network.FoodTag>,
        selectedSlugs: List<String>
    ) {
        dialogBinding.itemTagsGroup.removeAllViews()
        if (tags.isEmpty()) {
            dialogBinding.itemTagsEmptyLabel.text = getString(R.string.label_item_tags_unavailable)
            dialogBinding.itemTagsEmptyLabel.visibility = View.VISIBLE
            return
        }
        dialogBinding.itemTagsEmptyLabel.visibility = View.GONE
        val selectedSet = selectedSlugs.toSet()
        for (tag in tags) {
            val chip = com.google.android.material.chip.Chip(requireContext())
            chip.setChipDrawable(
                com.google.android.material.chip.ChipDrawable.createFromAttributes(
                    requireContext(), null, 0, com.google.android.material.R.style.Widget_MaterialComponents_Chip_Choice
                )
            )
            chip.text = tag.name
            chip.tag = tag.slug
            chip.isCheckable = true
            chip.isChecked = selectedSet.contains(tag.slug)
            dialogBinding.itemTagsGroup.addView(chip)
        }
    }

    private fun saveItem(
        existing: MenuItem?,
        categoryId: Int,
        name: String,
        price: Double,
        description: String?,
        isVeg: Boolean,
        photoUri: Uri?,
        tags: List<String>
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
                            imageUrl = imageUrlToSave,
                            tags = tags
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
                            imageUrl = imageUrlToSave,
                            tags = tags
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
        val dialogBinding = DialogConfirmDeleteBinding.inflate(layoutInflater)
        dialogBinding.deleteDialogTitle.text = getString(R.string.dialog_delete_item_title)
        dialogBinding.deleteDialogMessage.text = getString(R.string.confirm_delete_item)
        val dialog = MaterialAlertDialogBuilder(requireContext())
            .setView(dialogBinding.root)
            .create()
        dialogBinding.btnDeleteDialogCancel.setOnClickListener { dialog.dismiss() }
        dialogBinding.btnDeleteDialogDelete.setOnClickListener {
            dialog.dismiss()
            deleteItem(item)
        }
        dialog.show()
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
