-- ============================================================
-- Anydrop — Migration 37: Area-wise Payment Method Restrictions (general)
--
-- Implements recall.md Phase B item 15 — admin-controlled control over
-- WHICH payment methods (`orders.payment_method`: currently 'upi','cod')
-- are allowed to be used at all in a given service area, never
-- hardcoded in the Customer App.
--
-- Deliberately separate from migration 35's area_cod_rules: that table
-- is COD-specific fine-grained eligibility (min prepaid orders, max
-- amount, daily cap, new-customer block) for a customer who is already
-- allowed to see COD as an option. This table is the coarser, more
-- general gate — "is UPI allowed here at all", "is COD allowed here at
-- all" — e.g. a newly-launched area where the platform wants
-- prepaid-only orders until enough delivered-order trust is built, or a
-- cash-collection-risk area where COD should not be offered regardless
-- of any customer's own prepaid history. The two layers compose:
-- payment method must first pass this general area gate, and if it's
-- 'cod', must then also pass area_cod_rules' finer checks.
--
-- One row per service_areas node (city_village or area level, same
-- "either level assignable" pattern as area_cod_rules / area_pricing_rules).
-- A node with no row here has no override and falls back to the
-- platform-wide defaults in app_settings below — same
-- additive/idempotent pattern as migrations 30/33/35/36.
-- ============================================================

CREATE TABLE IF NOT EXISTS area_payment_restrictions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    area_id BIGINT UNSIGNED NOT NULL UNIQUE,   -- one rule row per service_areas node
    upi_allowed TINYINT(1) NOT NULL DEFAULT 1,
    cod_allowed TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,   -- disable the override without deleting it (falls back to platform default)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_area_payment_restrictions_area FOREIGN KEY (area_id) REFERENCES service_areas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A rule that blocks every payment method would leave an area with no
-- way to ever place an order — an admin misconfiguration, not a valid
-- business state. Enforced again in application code (lib/payment_restrictions.php)
-- since MySQL CHECK constraints on two separate columns like this are
-- awkward to express/alter safely across MySQL versions this project
-- targets; the DB-level guard below is a defensive backstop, not the
-- primary enforcement point.
ALTER TABLE area_payment_restrictions
    ADD CONSTRAINT chk_area_payment_restrictions_not_all_blocked
    CHECK (upi_allowed = 1 OR cod_allowed = 1);

-- ---------- Platform-wide defaults, used when a customer's resolved
-- area has no row above (or resolves to no area at all) ----------
INSERT IGNORE INTO app_settings (`key`, `value`) VALUES
    ('default_upi_allowed', '1'),
    ('default_cod_allowed', '1');
