-- Migration 20 — bugs.md #2.4 fix (server-side half). Adds
-- orders.idempotency_key: a client-generated UUID sent with
-- POST /orders, unique per (customer_id, idempotency_key). A retried
-- request that reuses the same key (timeout-then-retry, not a genuinely
-- new order attempt) now returns the original order instead of creating
-- a duplicate — see orders/create.php's updated INSERT-then-catch-duplicate
-- handling. Nullable: older/other-client requests that don't send a key
-- fall back to today's un-deduplicated behaviour rather than failing.
-- Idempotent conditional-ALTER pattern, same as 06/16/17/18.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'idempotency_key');
SET @sql := IF(@c = 0, 'ALTER TABLE orders ADD COLUMN idempotency_key VARCHAR(64) NULL AFTER customer_id', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'uniq_customer_idempotency_key');
SET @sql2 := IF(@i = 0, 'ALTER TABLE orders ADD UNIQUE KEY uniq_customer_idempotency_key (customer_id, idempotency_key)', 'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
