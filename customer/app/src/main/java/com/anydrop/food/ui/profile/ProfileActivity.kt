package com.anydrop.food.ui.profile

import android.app.AlertDialog
import android.content.Intent
import android.os.Bundle
import android.view.View
import android.widget.LinearLayout
import androidx.appcompat.app.AppCompatActivity
import com.anydrop.food.R
import com.anydrop.food.data.CartManager
import com.anydrop.food.data.TokenManager
import com.anydrop.food.databinding.ActivityProfileBinding
import com.anydrop.food.databinding.ItemProfileMenuRowBinding
import com.anydrop.food.ui.login.LoginActivity

/**
 * Profile → entry point (step 12d, §2.7). Built LAST, only once every
 * sub-screen it references (AddressBookActivity, OrderHistoryActivity,
 * SavedActivity, FaqsActivity, RateUsDialog, FeedbackActivity) already
 * exists and compiles on its own — see docs/Status.md for why (a first
 * attempt at this file was reverted in an earlier session for skipping
 * that order).
 *
 * Account basics shows email only — TokenManager has no name/phone
 * locally and no `customer/me.php`-style endpoint exists.
 *
 * Icon reuse (no purpose-built icons exist for these rows yet, per
 * Status.md's part 6 note): Address Book → ic_location, Order History →
 * ic_restaurant, Saved → ic_bookmark_filled, FAQs → ic_error, Rate Us →
 * ic_star, Feedback → ic_mail, Logout → ic_logout.
 */
class ProfileActivity : AppCompatActivity() {

    private lateinit var binding: ActivityProfileBinding

    private data class MenuRow(
        val iconRes: Int,
        val labelRes: Int,
        val onClick: () -> Unit
    )

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityProfileBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }

        binding.profileEmail.text = TokenManager(this).getEmail()
            ?: getString(R.string.account_basics_title)

        val rows = listOf(
            MenuRow(R.drawable.ic_location, R.string.menu_address_book) {
                startActivity(Intent(this, AddressBookActivity::class.java))
            },
            MenuRow(R.drawable.ic_restaurant, R.string.menu_order_history) {
                startActivity(Intent(this, OrderHistoryActivity::class.java))
            },
            MenuRow(R.drawable.ic_bookmark_filled, R.string.menu_saved) {
                startActivity(Intent(this, SavedActivity::class.java))
            },
            MenuRow(R.drawable.ic_error, R.string.menu_faqs) {
                startActivity(Intent(this, FaqsActivity::class.java))
            },
            MenuRow(R.drawable.ic_star, R.string.menu_rate_us) {
                RateUsDialog.show(this)
            },
            MenuRow(R.drawable.ic_mail, R.string.menu_feedback) {
                startActivity(Intent(this, FeedbackActivity::class.java))
            },
            MenuRow(R.drawable.ic_logout, R.string.menu_logout) {
                confirmLogout()
            }
        )

        rows.forEachIndexed { index, row ->
            val rowBinding = ItemProfileMenuRowBinding.inflate(
                layoutInflater, binding.profileMenuContainer, false
            )
            rowBinding.rowIcon.setImageResource(row.iconRes)
            rowBinding.rowLabel.setText(row.labelRes)
            rowBinding.root.setOnClickListener { row.onClick() }
            binding.profileMenuContainer.addView(rowBinding.root)

            if (index < rows.size - 1) {
                binding.profileMenuContainer.addView(dividerView())
            }
        }
    }

    private fun dividerView(): View {
        val heightPx = (1 * resources.displayMetrics.density).toInt().coerceAtLeast(1)
        val divider = View(this)
        divider.layoutParams = LinearLayout.LayoutParams(
            LinearLayout.LayoutParams.MATCH_PARENT,
            heightPx
        )
        divider.setBackgroundColor(getColor(R.color.outline))
        return divider
    }

    private fun confirmLogout() {
        AlertDialog.Builder(this)
            .setTitle(R.string.logout_confirm_title)
            .setMessage(R.string.logout_confirm_message)
            .setPositiveButton(R.string.logout_confirm_positive) { _, _ -> doLogout() }
            .setNegativeButton(R.string.btn_cancel, null)
            .show()
    }

    private fun doLogout() {
        TokenManager(this).clear()
        CartManager.clear()
        startActivity(Intent(this, LoginActivity::class.java))
        finish()
    }
}
