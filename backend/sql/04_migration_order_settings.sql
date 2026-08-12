-- Phase 3 — order pricing/cancel settings. Safe to re-run.
INSERT INTO app_settings (`key`, `value`, description) VALUES
('tax_percent', '5', 'Tax % applied on item_total for every order'),
('delivery_charge_flat', '25', 'Flat delivery charge added to every order (until distance-based pricing is added)'),
('packing_charge_flat', '0', 'Flat packing charge added to every order'),
('order_cancel_window_minutes', '5', 'Minutes after placing an order a customer can still self-cancel (only in pending/accepted status)')
ON DUPLICATE KEY UPDATE `key` = `key`;
