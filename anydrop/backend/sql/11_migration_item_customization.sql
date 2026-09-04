-- Anydrop — Migration 11: Dish customization sheet (§2.6 / bug 1.9)
--
-- The Customer App's new ItemDetailBottomSheetFragment lets a customer pick
-- addons + an optional free-text cooking request ("less spicy", "no onion")
-- per dish, matching Zomato/Swiggy's item-customization flow. Addons already
-- had full backend support (menu_item_addons + orders/create.php's
-- addon_ids handling, see docs/02_API_Contract.md) — the only new piece is
-- a place to store the per-item cooking request, which is distinct from
-- orders.delivery_instructions (that's order-level, not per-dish).
--
-- Also extends the Phase-3.7 cart-persistence table (migration 07) with the
-- same two fields, so a customized cart line (addons + note) survives an
-- app kill/restart instead of silently reverting to the plain item on
-- restore.
--
-- NOTE: this migration does NOT add addon "groups" / max-select caps
-- (menu_item_addon_groups) — that part of the original §2.6 request from
-- docs/07_Phase_3.7_Bug_Tracker.md is still open. Addons remain flat
-- checkboxes (any combination, no cap) for this pass. See docs/Status.md
-- for the full explanation of what shipped vs. what's still pending.

ALTER TABLE order_items
    ADD COLUMN special_instructions TEXT NULL AFTER addons_json;

ALTER TABLE customer_cart_items
    ADD COLUMN addon_ids TEXT NULL AFTER quantity,
    ADD COLUMN special_instructions TEXT NULL AFTER addon_ids;
