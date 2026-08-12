-- Anydrop — Migration: splash/login banner image + legal page URLs
-- Run this once against the existing `anydrop` database (phpMyAdmin > SQL
-- tab > paste and run). Safe to re-run (ON DUPLICATE KEY UPDATE).

INSERT INTO app_settings (`key`, `value`, description) VALUES
('splash_banner_image_url', 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?w=1200&q=80', 'Hero food banner shown on the Customer app splash + login screen. Replace with your own hosted image whenever ready — no app update needed.'),
('legal_terms_url', 'https://your-domain.example.com/legal/terms.html', 'Terms of Service page, opened in an in-app WebView from the login screen'),
('legal_privacy_url', 'https://your-domain.example.com/legal/privacy.html', 'Privacy Policy page, opened in an in-app WebView'),
('legal_content_policy_url', 'https://your-domain.example.com/legal/content-policy.html', 'Content Policy page, opened in an in-app WebView'),
('home_promo_enabled', '1', 'Set to 1 to show the promo banner on the Customer app Home screen, 0 to hide it'),
('home_promo_title', 'Flash Sale', 'Promo banner title shown on Home screen'),
('home_promo_subtitle', 'Up to 50% OFF on your first order', 'Promo banner subtitle shown on Home screen'),
('home_promo_image_url', 'https://images.unsplash.com/photo-1550547660-d9450f859349?w=1200&q=80', 'Promo banner background image')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- Replace the placeholder URLs above with real ones once you have them —
-- editable from the Admin Panel (Phase 5) or directly via phpMyAdmin for now.
