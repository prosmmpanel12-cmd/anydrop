-- ============================================================
-- Anydrop — Migration 38: Category+Area Commission Rules,
-- Per-Line Commission Snapshot, and the full Settlement/
-- Platform Ledger schema (recall.md Phase C items 20-23).
--
-- Owner's explicit ask (2026-08-22): commission should NOT be a single
-- flat restaurant.commission_percent forever — it needs to differ
-- *by food category* (e.g. cold drinks lower than regular food) AND
-- *by area*, on top of a flat default, with the specific override
-- winning wherever one exists. This migration adds that as a small
-- rules table (not a giant per-restaurant matrix) plus the ledger/
-- settlement schema doc 19 §6/§6b already speced.
--
-- ---------- Part 1: commission_rules (category + area override) ----------
--
-- Priority, most specific first (see lib/commission.php's
-- get_effective_commission_rate() — the single function that walks
-- this order, used everywhere commission is computed):
--   1. category_id SET + area_id SET   (this category, in this area)
--   2. category_id SET + area_id NULL  (this category, any area)
--   3. category_id NULL + area_id SET  (any category, in this area)
--   4. restaurants.commission_percent  (existing flat per-restaurant override)
--   5. app_settings.commission_default_percent (existing platform default)
--
-- area_id here means the RESTAURANT's own service_areas node
-- (restaurants.area_id, migration 30) — commission is a
-- platform/restaurant revenue-share question, not a customer-delivery
-- question, so it's scoped to where the restaurant operates, not
-- where the customer's order is going.
--
-- category_id is food_categories.id (admin-managed Home categories,
-- migration 05) via menu_item_categories — a menu item can carry more
-- than one category tag (many-to-many). When an item matches more than
-- one category at the SAME specificity level with different rates,
-- get_effective_commission_rate() takes the HIGHEST rate among them —
-- a deliberate, documented judgement call (protects platform revenue
-- rather than silently under-charging), not a spec'd requirement from
-- the owner. Flag for owner review if a different tie-break is wanted.
CREATE TABLE IF NOT EXISTS commission_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    food_category_id BIGINT UNSIGNED NULL,   -- NULL = applies to every category
    area_id BIGINT UNSIGNED NULL,            -- NULL = applies to every restaurant area
    commission_percent DECIMAL(5,2) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_commission_rule_category FOREIGN KEY (food_category_id) REFERENCES food_categories(id),
    CONSTRAINT fk_commission_rule_area FOREIGN KEY (area_id) REFERENCES service_areas(id),
    -- A rule with BOTH null would silently apply to literally everything,
    -- indistinguishable from (and redundant with) the platform default —
    -- reject that combination rather than let two different "everything"
    -- rows exist with no way to tell which wins.
    CONSTRAINT chk_commission_rule_scoped CHECK (food_category_id IS NOT NULL OR area_id IS NOT NULL),
    INDEX idx_commission_rule_lookup (food_category_id, area_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Part 2: per-line commission snapshot ----------
--
-- Today commission_amount only exists at the whole-order level
-- (orders.commission_amount), computed as a single flat
-- itemTotal × restaurant.commission_percent (lib/orders.php's
-- price_cart()). Once category/area rates exist, a single order can
-- legitimately mix rates across its own line items (e.g. one cold
-- drink + two regular dishes from the same restaurant) — so each line
-- needs its own snapshot, same reasoning order_items already
-- snapshots item_name/unit_price so a later menu/category edit never
-- silently changes a historical order's numbers.
ALTER TABLE order_items
    ADD COLUMN commission_percent DECIMAL(5,2) NULL AFTER subtotal,
    ADD COLUMN commission_amount DECIMAL(8,2) NULL AFTER commission_percent;

-- ---------- Part 3: settlement schema (doc 19 §6) ----------
--
-- restaurant_due_ledger and restaurant_payments tables already exist
-- (backend/sql/01_schema.sql) but nothing writes to them yet. This
-- extends both per doc 19 §6's "correction worth flagging" — money can
-- flow BOTH directions (COD: restaurant owes admin the commission;
-- online/UPI: admin owes restaurant their payout share), so
-- current_due must be read as signed, not "restaurant always owes".
ALTER TABLE restaurant_due_ledger
    MODIFY COLUMN entry_type ENUM(
        'commission_cod',            -- +amount: COD order, restaurant owes admin the commission
        'payout_payable',            -- -amount: online order, admin owes restaurant their share
        'platform_fee',              -- +amount: existing behaviour, unchanged
        'settlement_to_restaurant',  -- -amount: admin actually paid the restaurant (reduces what admin owes)
        'settlement_from_restaurant',-- -amount: restaurant actually paid admin (reduces what restaurant owes)
        'manual_adjustment'
    ) NOT NULL;

ALTER TABLE restaurant_payments
    ADD COLUMN direction ENUM('admin_to_restaurant','restaurant_to_admin') NOT NULL DEFAULT 'admin_to_restaurant',
    ADD COLUMN utr_number VARCHAR(30) NULL,
    ADD COLUMN screenshot_url VARCHAR(255) NULL,
    ADD COLUMN remarks VARCHAR(255) NULL,
    ADD COLUMN payment_date DATE NULL,
    ADD COLUMN settled_by_admin_id BIGINT UNSIGNED NULL,
    ADD CONSTRAINT fk_payment_settled_by FOREIGN KEY (settled_by_admin_id) REFERENCES admins(id);

CREATE TABLE IF NOT EXISTS restaurant_bank_details (
    restaurant_id BIGINT UNSIGNED PRIMARY KEY,
    account_holder_name VARCHAR(100) NOT NULL,
    bank_name VARCHAR(100) NOT NULL,
    account_number VARCHAR(30) NOT NULL,
    ifsc_code VARCHAR(15) NOT NULL,
    upi_id VARCHAR(100) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_bank_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Part 4: platform-wide cash ledger (doc 19 §6b) ----------
--
-- Separate from restaurant_due_ledger (which is per-restaurant, "who
-- owes whom") — this tracks the admin's own UPIPE merchant-account
-- cash balance platform-wide. platform_revenue rows are informational
-- only (do not move cash) — see doc 19 §6b for why: platform revenue
-- is just the gap between money in and money out for the same order,
-- not a separate transfer.
CREATE TABLE IF NOT EXISTS platform_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_type ENUM(
        'customer_payment_in',
        'restaurant_settlement_in',
        'restaurant_payout_out',
        'refund_out',
        'platform_revenue',
        'manual_adjustment'
    ) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    running_balance DECIMAL(12,2) NOT NULL,
    restaurant_id BIGINT UNSIGNED NULL,
    order_id BIGINT UNSIGNED NULL,
    restaurant_payment_id BIGINT UNSIGNED NULL,
    note VARCHAR(255) NULL,
    created_by ENUM('system','admin') NOT NULL,
    admin_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_platform_ledger_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_platform_ledger_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_platform_ledger_payment FOREIGN KEY (restaurant_payment_id) REFERENCES restaurant_payments(id),
    CONSTRAINT fk_platform_ledger_admin FOREIGN KEY (admin_id) REFERENCES admins(id),
    INDEX idx_platform_ledger_created (created_at),
    INDEX idx_platform_ledger_type (entry_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- GST-on-commission setting doc 19 §6 flags as needed for the Payout
-- screen's GST column — not computed anywhere yet, this just reserves
-- the admin-configurable knob so it isn't hardcoded once that column
-- is built.
INSERT IGNORE INTO app_settings (`key`, `value`) VALUES
    ('gst_percent', '18');
