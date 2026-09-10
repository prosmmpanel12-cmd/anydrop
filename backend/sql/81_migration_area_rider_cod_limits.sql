-- ============================================================
-- Anydrop — Migration 81: Area-wise Rider COD Cash-Hold Limit
--
-- Deep Plan Phase 4 (docs/00_Deep_Plan_Statement_CODLimit_QRPay_
-- CashFlow_2026-09-09.md §4). Today `app_settings.rider_cod_settlement_
-- limit` (migration 53) is the ONLY cash-hold ceiling — one number for
-- every rider on the platform, checked in `lib/dispatch.php`'s
-- find_eligible_riders() (`$codLimit = get_setting('rider_cod_
-- settlement_limit', 2000)`). This migration adds a per-area override
-- table on top of that, same "one row per service_areas node, no row =
-- fall back to the platform default" shape `area_cod_rules` (migration
-- 35) already established for the customer-side COD rules — nothing
-- about that existing table is touched or reused directly, since this
-- is a different concern (how much cash a RIDER may hold) from that
-- one (whether a CUSTOMER may pay COD at all).
--
-- Meaningful at 'city_village' or 'area' level, same as area_cod_rules
-- and service_areas.center_lat/radius_km — an area with no row here
-- has no override and falls back to `rider_cod_settlement_limit`.
--
-- Enforcement: backend/lib/rider_cod_limit.php (new, this session).
-- Admin UI: backend/admin/rider-cod-limits.php (new, this session).
--
-- Same idempotent CREATE-IF-NOT-EXISTS pattern as migration 35/30/33 —
-- safe to run any number of times, in any partial-prior-state.
-- ============================================================

CREATE TABLE IF NOT EXISTS area_rider_cod_limits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    area_id BIGINT UNSIGNED NOT NULL UNIQUE,      -- one override row per service_areas node
    cod_hold_limit DECIMAL(10,2) NOT NULL,        -- ₹ cap for a rider whose service_area_id resolves into this node's chain
    is_active TINYINT(1) NOT NULL DEFAULT 1,      -- disable the override without deleting it (falls back to platform default)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_area_rider_cod_limits_area FOREIGN KEY (area_id) REFERENCES service_areas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- No new app_settings row needed — `rider_cod_settlement_limit`
-- (migration 53) already exists and continues to serve as the
-- platform-wide fallback exactly as it does today.
