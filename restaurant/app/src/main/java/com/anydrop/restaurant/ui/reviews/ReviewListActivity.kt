package com.anydrop.restaurant.ui.reviews

import android.os.Bundle
import android.text.InputType
import android.view.View
import android.widget.EditText
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.anydrop.restaurant.R
import com.anydrop.restaurant.databinding.ActivityNotificationListBinding
import com.anydrop.restaurant.network.ApiClient
import com.anydrop.restaurant.network.ReportReviewBody
import com.anydrop.restaurant.network.Review
import com.anydrop.restaurant.network.ReviewReplyBody
import com.anydrop.restaurant.ui.common.InAppNotifier
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import kotlinx.coroutines.launch

/**
 * Reviews reply screen (docs/restorent/00_Status.md, this session).
 * Reuses activity_notification_list.xml as its layout — that file's own
 * comment already flagged it as generalizable to a second screen wanting
 * the same shape ("purpose-built for NotificationListActivity
 * specifically... could be generalized... if a second screen ends up
 * wanting the same shape"), and this is exactly that: same back button +
 * title + swipe-refresh + infinite-scroll list + empty state, just with
 * btnAction (mark-all-read on the notifications screen) hidden since
 * there's no equivalent bulk action here.
 *
 * Same infinite-scroll pagination shape as NotificationListActivity —
 * currentPage/hasMore/isLoadingPage, load-more triggered 3 rows from the
 * bottom. Reply submission is per-row (see ReviewAdapter's onSendReply/
 * onEditReply callbacks) rather than anything at the screen level.
 */
class ReviewListActivity : AppCompatActivity() {

    private lateinit var binding: ActivityNotificationListBinding
    private val api by lazy { ApiClient.create(this) }
    private lateinit var adapter: ReviewAdapter

    private var currentPage = 1
    private var hasMore = true
    private var isLoadingPage = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityNotificationListBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.screenTitle.text = getString(R.string.reviews_title)
        binding.btnBack.setOnClickListener { finish() }
        binding.btnAction.visibility = View.GONE

        adapter = ReviewAdapter(
            onSendReply = { review, text -> sendReply(review, text) },
            onEditReply = { review -> adapter.startEditing(review.id) },
            onReportReview = { review -> promptReportReview(review) }
        )
        val layoutManager = LinearLayoutManager(this)
        binding.contentList.layoutManager = layoutManager
        binding.contentList.adapter = adapter
        binding.contentList.addOnScrollListener(object : RecyclerView.OnScrollListener() {
            override fun onScrolled(recyclerView: RecyclerView, dx: Int, dy: Int) {
                super.onScrolled(recyclerView, dx, dy)
                if (dy <= 0 || isLoadingPage || !hasMore) return
                val visibleItemCount = layoutManager.childCount
                val totalItemCount = layoutManager.itemCount
                val firstVisible = layoutManager.findFirstVisibleItemPosition()
                if (visibleItemCount + firstVisible >= totalItemCount - 3) {
                    loadNextPage()
                }
            }
        })

        binding.emptyStateText.text = getString(R.string.empty_reviews)
        binding.swipeRefresh.setOnRefreshListener { loadFirstPage() }

        loadFirstPage()
    }

    private fun sendReply(review: Review, text: String) {
        if (text.isBlank()) {
            InAppNotifier.show(this, getString(R.string.review_reply_empty), InAppNotifier.Type.ERROR)
            return
        }
        lifecycleScope.launch {
            try {
                val result = api.replyToReview(id = review.id, body = ReviewReplyBody(reply = text)).body()?.data
                val updated = result?.review
                if (updated != null) {
                    adapter.patchReply(review.id, updated)
                    InAppNotifier.show(this@ReviewListActivity, getString(R.string.review_reply_sent), InAppNotifier.Type.SUCCESS)
                } else {
                    InAppNotifier.show(this@ReviewListActivity, getString(R.string.review_reply_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@ReviewListActivity, getString(R.string.review_reply_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    /** §7, today.md 2026-08-28. Same reason-input confirm-dialog shape as
     * OrderAdapter.promptRejectReason — plain EditText in a
     * MaterialAlertDialogBuilder, no custom dialog class needed for
     * something this simple. */
    private fun promptReportReview(review: Review) {
        val input = EditText(this).apply {
            hint = getString(R.string.review_report_hint)
            inputType = InputType.TYPE_CLASS_TEXT
            val padding = (16 * resources.displayMetrics.density).toInt()
            setPadding(padding, padding / 2, padding, padding / 2)
        }
        MaterialAlertDialogBuilder(this)
            .setTitle(R.string.review_report_title)
            .setView(input)
            .setPositiveButton(R.string.review_report_confirm) { _, _ ->
                val reason = input.text?.toString()?.trim().orEmpty()
                if (reason.isEmpty()) {
                    InAppNotifier.show(this, getString(R.string.review_report_reason_required), InAppNotifier.Type.INFO)
                } else {
                    reportReview(review, reason)
                }
            }
            .setNegativeButton(R.string.btn_cancel, null)
            .show()
    }

    private fun reportReview(review: Review, reason: String) {
        lifecycleScope.launch {
            try {
                val result = api.reportReview(ReportReviewBody(reviewId = review.id, reason = reason)).body()?.data
                if (result?.reported == true) {
                    adapter.markReported(review.id)
                    InAppNotifier.show(this@ReviewListActivity, getString(R.string.review_report_sent), InAppNotifier.Type.SUCCESS)
                } else {
                    InAppNotifier.show(this@ReviewListActivity, getString(R.string.review_report_failed), InAppNotifier.Type.ERROR)
                }
            } catch (e: Exception) {
                InAppNotifier.show(this@ReviewListActivity, getString(R.string.review_report_failed), InAppNotifier.Type.ERROR)
            }
        }
    }

    private fun loadFirstPage() {
        currentPage = 1
        hasMore = true
        binding.swipeRefresh.isRefreshing = true
        lifecycleScope.launch {
            try {
                val result = api.getReviews(page = currentPage).body()?.data
                val items = result?.items ?: emptyList()
                hasMore = result?.hasMore ?: false
                adapter.submit(items)
                binding.emptyState.visibility = if (items.isEmpty()) View.VISIBLE else View.GONE
                binding.contentList.visibility = if (items.isEmpty()) View.GONE else View.VISIBLE
            } catch (e: Exception) {
                InAppNotifier.show(this@ReviewListActivity, "Couldn't load reviews", InAppNotifier.Type.ERROR)
            } finally {
                binding.swipeRefresh.isRefreshing = false
            }
        }
    }

    private fun loadNextPage() {
        isLoadingPage = true
        lifecycleScope.launch {
            try {
                val nextPage = currentPage + 1
                val result = api.getReviews(page = nextPage).body()?.data
                val items = result?.items ?: emptyList()
                if (items.isNotEmpty()) {
                    adapter.appendPage(items)
                    currentPage = nextPage
                }
                hasMore = result?.hasMore ?: false
            } catch (e: Exception) {
                // Silent — same "pull-to-refresh to retry" reasoning as
                // NotificationListActivity's loadNextPage.
            } finally {
                isLoadingPage = false
            }
        }
    }
}
