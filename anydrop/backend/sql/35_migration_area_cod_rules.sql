-- ============================================================
-- Anydrop — Migration 35: Area-wise COD / Payment Rules
--
-- Implements recall.md item 4 — admin-controlled COD eligibility rules
-- per service area, never hardcoded in the Customer App. The Customer
-- App only ever receives the server's yes/no decision (see
-- lib/cod_rules.php's evaluate_cod_eligibility()) plus a short reason
-- string; it never sees or evaluates the rule itself.
--
-- One row per service_areas node that has a rule configured. A node
-- with no row here has no override and falls back to the platform-wide
-- defaults in app_settings (see below) — same "additive, nothing
-- existing breaks" pattern as migration 30. Meaningful at 'city_village'
-- or 'area' level, same as service_areas.center_lat/radius_km (area is
-- optional — a rule can live on whichever level actually needs it).
--
-- v1 scope (per app owner, 2026-08-22): COD on/off, minimum completed
-- prepaid orders before COD unlocks, max COD order amount, max COD
-- orders per customer per day, and a new-customer COD restriction.
-- Area-specific delivery fee / minimum order / general payment
-- restrictions are separate, still-pending recall.md items — not
-- folded into this table, to keep this migration scoped to what was
-- actually asked for this round.
--
-- Same idempotent CREATE-IF-NOT-EXISTS pattern as migration 30/33 —
-- safe to run any number of times, in any partial-prior-state.
-- ============================================================

CREATE TABLE IF NOT EXISTS area_cod_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    area_id BIGINT UNSIGNED NOT NULL UNIQUE,        -- one rule row per service_areas node
    cod_enabled TINYINT(1) NOT NULL DEFAULT 1,
    min_prepaid_orders INT UNSIGNED NOT NULL DEFAULT 0,     -- 0 = no prepaid-history requirement
    max_cod_order_amount DECIMAL(10,2) NULL,                -- NULL = no per-order cap
    max_cod_orders_per_day INT UNSIGNED NULL,                -- NULL = no daily-count cap
    new_customer_cod_blocked TINYINT(1) NOT NULL DEFAULT 0, -- 1 = a customer with zero delivered orders can't use COD regardless of min_prepaid_orders
    is_active TINYINT(1) NOT NULL DEFAULT 1,                -- disable the override without deleting it (falls back to platform default)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_area_cod_rules_area FOREIGN KEY (area_id) REFERENCES service_areas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Platform-wide defaults, used when a customer's resolved
-- area has no row above (or resolves to no area at all) ----------
-- Same app_settings table every other platform-wide flag already uses
-- (lib/settings.php's get_setting()) — INSERT IGNORE so re-running this
-- migration never clobbers a value an admin already changed.

INSERT IGNORE INTO app_settings (`key`, `value`) VALUES
    ('default_cod_enabled', '1'),
    ('default_cod_min_prepaid_orders', '0'),
    ('default_cod_max_order_amount', ''),
    ('default_cod_max_orders_per_day', ''),
    ('default_cod_new_customer_blocked', '0');
