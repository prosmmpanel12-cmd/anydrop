-- ============================================================
-- Anydrop — Phase 1 Database Schema
-- Run this entire script once in InfinityFree's phpMyAdmin
-- (SQL tab -> paste -> Go). Safe to re-run: uses IF NOT EXISTS.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. Core Identity Tables
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS customers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    mobile VARCHAR(15) NULL,
    login_type ENUM('google','email') NOT NULL DEFAULT 'email',
    google_id VARCHAR(255) NULL,
    profile_photo VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS restaurants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    owner_name VARCHAR(100) NULL,
    owner_mobile VARCHAR(15) NULL,
    owner_email VARCHAR(150) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    address TEXT NULL,
    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,
    logo_url VARCHAR(255) NULL,
    cover_url VARCHAR(255) NULL,
    cuisine_tags VARCHAR(255) NULL,
    is_veg_only TINYINT(1) NOT NULL DEFAULT 0,
    opening_time TIME NULL,
    closing_time TIME NULL,
    working_days VARCHAR(20) NULL DEFAULT '1,2,3,4,5,6,7',
    delivery_radius_km DECIMAL(4,1) NULL DEFAULT 5.0,
    min_order_amount DECIMAL(8,2) NULL DEFAULT 0,
    gst_number VARCHAR(20) NULL,
    fssai_number VARCHAR(30) NULL,
    upi_id VARCHAR(100) NULL,
    description TEXT NULL,
    status ENUM('pending','approved','rejected','suspended') NOT NULL DEFAULT 'pending',
    operational_status ENUM('open','closed','busy','vacation','temp_closed','admin_disabled') NOT NULL DEFAULT 'closed',
    auto_accept_orders TINYINT(1) NOT NULL DEFAULT 0,
    current_due DECIMAL(10,2) NOT NULL DEFAULT 0,
    commission_percent DECIMAL(5,2) NOT NULL DEFAULT 15.00,
    rating_avg DECIMAL(3,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS riders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    mobile VARCHAR(15) NULL,
    vehicle_type VARCHAR(30) NULL,
    vehicle_number VARCHAR(20) NULL,
    is_online TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_lat DECIMAL(10,8) NULL,
    last_lng DECIMAL(11,8) NULL,
    last_location_at TIMESTAMP NULL,
    fcm_token VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    CONSTRAINT fk_riders_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin','staff') NOT NULL DEFAULT 'super_admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 2. Menu Tables
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS menu_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_menucat_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS menu_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    price DECIMAL(8,2) NOT NULL,
    discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    is_veg TINYINT(1) NOT NULL DEFAULT 1,
    image_url VARCHAR(255) NULL,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    is_recommended TINYINT(1) NOT NULL DEFAULT 0,
    is_bestseller TINYINT(1) NOT NULL DEFAULT 0,
    prep_time_minutes SMALLINT NOT NULL DEFAULT 15,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    CONSTRAINT fk_menuitem_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_menuitem_category FOREIGN KEY (category_id) REFERENCES menu_categories(id),
    INDEX idx_menuitems_lookup (restaurant_id, category_id, is_available)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS menu_item_variants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    menu_item_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(50) NOT NULL,
    price_delta DECIMAL(8,2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_variant_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS menu_item_addons (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    menu_item_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(50) NOT NULL,
    price DECIMAL(8,2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_addon_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 3. Order Tables
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS customer_addresses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(50) NULL,
    full_address TEXT NOT NULL,
    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT fk_address_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS coupons (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    restaurant_id BIGINT UNSIGNED NULL,
    discount_type ENUM('flat','percent') NOT NULL,
    discount_value DECIMAL(8,2) NOT NULL,
    min_order_amount DECIMAL(8,2) NOT NULL DEFAULT 0,
    max_discount_amount DECIMAL(8,2) NULL,
    valid_from DATETIME NULL,
    valid_until DATETIME NULL,
    usage_limit_total INT NULL,
    usage_limit_per_user INT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_coupon_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_code VARCHAR(20) UNIQUE NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    rider_id BIGINT UNSIGNED NULL,
    status ENUM('pending','accepted','rejected','preparing','ready','rider_assigned','picked_up','out_for_delivery','delivered','cancelled','refunded','failed','expired') NOT NULL DEFAULT 'pending',
    item_total DECIMAL(8,2) NOT NULL DEFAULT 0,
    delivery_charge DECIMAL(8,2) NOT NULL DEFAULT 0,
    platform_fee DECIMAL(8,2) NOT NULL DEFAULT 0,
    packing_charge DECIMAL(8,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(8,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(8,2) NOT NULL DEFAULT 0,
    grand_total DECIMAL(8,2) NOT NULL DEFAULT 0,
    commission_amount DECIMAL(8,2) NOT NULL DEFAULT 0,
    payment_method ENUM('upi','cod') NOT NULL DEFAULT 'cod',
    payment_status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    delivery_address_id BIGINT UNSIGNED NULL,
    delivery_instructions TEXT NULL,
    coupon_id BIGINT UNSIGNED NULL,
    delivery_otp VARCHAR(6) NULL,
    otp_verified_at TIMESTAMP NULL,
    otp_attempts TINYINT NOT NULL DEFAULT 0,
    estimated_prep_minutes SMALLINT NULL,
    accepted_at TIMESTAMP NULL,
    ready_at TIMESTAMP NULL,
    picked_up_at TIMESTAMP NULL,
    delivered_at TIMESTAMP NULL,
    cancelled_at TIMESTAMP NULL,
    cancellation_reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_order_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_order_rider FOREIGN KEY (rider_id) REFERENCES riders(id),
    CONSTRAINT fk_order_address FOREIGN KEY (delivery_address_id) REFERENCES customer_addresses(id),
    CONSTRAINT fk_order_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id),
    INDEX idx_orders_restaurant_status (restaurant_id, status),
    INDEX idx_orders_customer_created (customer_id, created_at),
    INDEX idx_orders_rider_status (rider_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    menu_item_id BIGINT UNSIGNED NULL,
    item_name_snapshot VARCHAR(150) NOT NULL,
    variant_name VARCHAR(50) NULL,
    quantity SMALLINT NOT NULL DEFAULT 1,
    unit_price DECIMAL(8,2) NOT NULL,
    addons_json TEXT NULL,
    subtotal DECIMAL(8,2) NOT NULL,
    CONSTRAINT fk_orderitem_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_orderitem_menuitem FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(30) NOT NULL,
    changed_by_type ENUM('system','restaurant','rider','admin','customer') NOT NULL,
    changed_by_id BIGINT UNSIGNED NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_orderhist_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS coupon_usages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    coupon_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_couponusage_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id),
    CONSTRAINT fk_couponusage_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_couponusage_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 4. Delivery / Location Tables
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rider_locations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rider_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NULL,
    latitude DECIMAL(10,8) NOT NULL,
    longitude DECIMAL(11,8) NOT NULL,
    speed_kmh DECIMAL(5,2) NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_riderloc_rider FOREIGN KEY (rider_id) REFERENCES riders(id),
    CONSTRAINT fk_riderloc_order FOREIGN KEY (order_id) REFERENCES orders(id),
    INDEX idx_riderloc_rider_time (rider_id, recorded_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 5. Financial / Ledger Tables
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS restaurant_due_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NULL,
    entry_type ENUM('commission','platform_fee','payment_received','manual_adjustment') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    running_balance DECIMAL(10,2) NOT NULL,
    note VARCHAR(255) NULL,
    created_by ENUM('system','admin') NOT NULL DEFAULT 'system',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ledger_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_ledger_order FOREIGN KEY (order_id) REFERENCES orders(id),
    INDEX idx_ledger_restaurant_created (restaurant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS restaurant_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_reference VARCHAR(100) NULL,
    verified_by_admin_id BIGINT UNSIGNED NULL,
    verified_at TIMESTAMP NULL,
    status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payment_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_payment_admin FOREIGN KEY (verified_by_admin_id) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 6. Reviews
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    rider_id BIGINT UNSIGNED NULL,
    restaurant_rating TINYINT NULL,
    food_rating TINYINT NULL,
    delivery_rating TINYINT NULL,
    comment TEXT NULL,
    restaurant_reply TEXT NULL,
    is_reported TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_review_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_review_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_review_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_review_rider FOREIGN KEY (rider_id) REFERENCES riders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 7. Notifications
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_type ENUM('customer','restaurant','rider','admin') NOT NULL,
    recipient_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    body TEXT NULL,
    type ENUM('order','promo','system','security') NOT NULL DEFAULT 'system',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    data_json TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_recipient (recipient_type, recipient_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 8. System / Admin Configuration
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS app_settings (
    `key` VARCHAR(100) PRIMARY KEY,
    `value` TEXT NOT NULL,
    description VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_type ENUM('customer','restaurant','rider') NOT NULL,
    min_supported_version INT NOT NULL DEFAULT 1,
    latest_version INT NOT NULL DEFAULT 1,
    force_update TINYINT(1) NOT NULL DEFAULT 0,
    changelog TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_type ENUM('customer','restaurant','rider','admin','system') NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    details_json TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 9. Auth tokens (Bearer token storage, referenced by API contract)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS auth_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_type ENUM('customer','restaurant','rider','admin') NOT NULL,
    owner_id BIGINT UNSIGNED NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token_hash (token_hash),
    INDEX idx_token_owner (owner_type, owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 10. Email OTP storage (customer email login flow)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS email_otps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL,
    otp_code VARCHAR(6) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    is_used TINYINT(1) NOT NULL DEFAULT 0,
    attempts TINYINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_otp_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- 11. Seed default app_settings ("nothing hardcoded" rule)
-- ------------------------------------------------------------

INSERT INTO app_settings (`key`, `value`, description) VALUES
('commission_default_percent', '15', 'Default commission % charged to restaurants'),
('platform_fee_flat', '5', 'Flat platform fee added to every order'),
('restaurant_due_limit', '2000', 'Due amount above which a restaurant is auto-suspended'),
('otp_required_for_cod', '0', 'Whether delivery OTP is required for cash-on-delivery orders'),
('otp_length', '4', 'Length of delivery OTP'),
('otp_expiry_minutes', '10', 'Minutes before an OTP expires'),
('otp_max_attempts', '3', 'Max wrong OTP attempts before flagging for manual override'),
('gps_ping_interval_moving_sec', '7', 'Rider location ping interval while moving'),
('gps_ping_interval_idle_sec', '30', 'Rider location ping interval while idle'),
('min_app_version_customer', '1', 'Minimum supported customer app version code'),
('maintenance_mode', '0', 'Set to 1 to put the API in maintenance mode'),
('cron_secret_key', 'CHANGE_ME_SECRET', 'Shared secret required on /system/cron/* endpoints')
ON DUPLICATE KEY UPDATE `key` = `key`;
