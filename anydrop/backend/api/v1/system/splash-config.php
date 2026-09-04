<?php
/**
 * GET /api/v1/system/splash-config.php
 * No auth required — called at splash/login screen startup.
 * Returns the branded splash/login banner image and legal page URLs, all
 * driven from app_settings (nothing hardcoded), following the same
 * convention as app-version.php.
 *
 * app_settings keys used (seed these via admin panel / seed script):
 *   splash_banner_image_url     hero food-photo banner shown on splash + login screen
 *   legal_terms_url             opened in in-app WebView from login/signup
 *   legal_privacy_url           opened in in-app WebView
 *   legal_content_policy_url    opened in in-app WebView
 *   home_promo_enabled          '1' to show a promo banner on the Home screen, '0' to hide it
 *   home_promo_title            e.g. "Gold Flash Sale"
 *   home_promo_subtitle         e.g. "50% OFF up to ₹120"
 *   home_promo_image_url        background image for the promo banner
 *   coupon_field_enabled        '1' to show the coupon-code field in the Cart
 *                                (admin can turn this off instantly, e.g. to
 *                                pause a promo campaign, without an app update)
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/settings.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

respond_ok([
    'banner_image_url' => get_setting('splash_banner_image_url', ''),
    'terms_url' => get_setting('legal_terms_url', ''),
    'privacy_url' => get_setting('legal_privacy_url', ''),
    'content_policy_url' => get_setting('legal_content_policy_url', ''),
    'home_promo_enabled' => get_setting('home_promo_enabled', '0') === '1',
    'home_promo_title' => get_setting('home_promo_title', ''),
    'home_promo_subtitle' => get_setting('home_promo_subtitle', ''),
    'home_promo_image_url' => get_setting('home_promo_image_url', ''),
    'coupon_field_enabled' => get_setting('coupon_field_enabled', '1') === '1',
]);
