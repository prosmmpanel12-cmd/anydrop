-- Anydrop — Migration 07: Server-side cart persistence
--
-- Customer app cart was in-memory only (CartManager singleton) — cleared on
-- app kill/restart. This table lets the app snapshot-sync the full
-- multi-restaurant cart to the server and restore it on next launch/login.
--
-- Design: "replace-all snapshot" per customer, not per-row CRUD — the app
-- POSTs its entire current cart state after every local change (debounced),
-- and the server deletes+reinserts that customer's rows in one transaction.
-- Simpler and safer than granular add/remove endpoints for something that's
-- not financially critical (real validation still happens at checkout via
-- POST /cart/validate before an order can be placed).

CREATE TABLE IF NOT EXISTS customer_cart_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    menu_item_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    coupon_code VARCHAR(50) NULL, -- same value repeated across a restaurant's rows; simplest to store per-row since a cart is never large enough for this to matter
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_customer_cart_item (customer_id, restaurant_id, menu_item_id),
    CONSTRAINT fk_cartitem_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_cartitem_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    CONSTRAINT fk_cartitem_menuitem FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    INDEX idx_cartitem_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
