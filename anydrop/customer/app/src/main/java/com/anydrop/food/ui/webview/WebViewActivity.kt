package com.anydrop.food.ui.webview

import android.os.Bundle
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.appcompat.app.AppCompatActivity
import com.anydrop.food.databinding.ActivityWebviewBinding

/**
 * Generic in-app WebView screen used for legal pages (Terms of Service,
 * Privacy Policy, Content Policy). URL and title are passed in via intent
 * extras — the URLs themselves come from the backend (splash-config API),
 * never hardcoded in the app.
 */
class WebViewActivity : AppCompatActivity() {

    companion object {
        const val EXTRA_URL = "extra_url"
        const val EXTRA_TITLE = "extra_title"
    }

    private lateinit var binding: ActivityWebviewBinding

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityWebviewBinding.inflate(layoutInflater)
        setContentView(binding.root)

        val url = intent.getStringExtra(EXTRA_URL).orEmpty()
        val title = intent.getStringExtra(EXTRA_TITLE).orEmpty()
        binding.webviewTitle.text = title

        binding.btnWebviewBack.setOnClickListener { finish() }

        binding.webview.settings.javaScriptEnabled = true
        binding.webview.webViewClient = object : WebViewClient() {
            override fun onPageFinished(view: WebView?, finishedUrl: String?) {
                super.onPageFinished(view, finishedUrl)
                binding.webviewProgress.visibility = android.view.View.GONE
            }
        }

        if (url.isNotBlank()) {
            binding.webview.loadUrl(url)
        }
    }

    override fun onBackPressed() {
        if (binding.webview.canGoBack()) {
            binding.webview.goBack()
        } else {
            super.onBackPressed()
        }
    }
}
