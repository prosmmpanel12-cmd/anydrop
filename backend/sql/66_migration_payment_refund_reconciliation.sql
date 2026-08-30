-- ============================================================
-- Anydrop — Migration 66: Payment / Refund / Settlement Reconciliation
-- (PENDING.md item 24, recall.md section 28, doc 21 §5.6/§5.7)
--
-- Two things, both additive:
--
-- 1. `restaurant_due_ledger.restaurant_payment_id` — a real gap found
--    while building this: `record_settlement()` (lib/ledger.php)
--    writes a restaurant_due_ledger row for every Pay Now settlement,
--    but that row only ever recorded `order_id` (always NULL for a
--    settlement) — nothing links it back to the specific
--    `restaurant_payments` row that caused it. `platform_ledger`
--    already carries `restaurant_payment_id` (migration 38) for this
--    exact purpose; `restaurant_due_ledger` never got the matching
--    column. Without it, "does every verified settlement have a
--    matching due-ledger entry, and does every settlement-type
--    due-ledger entry belong to a real settlement" can only be
--    checked by fuzzy amount/timestamp/note matching — not a real
--    reconciliation. Backfill below is exactly that fuzzy match,
--    used ONCE to populate history; every new settlement from this
--    migration forward gets the real FK written directly by
--    `record_settlement()` (lib/ledger.php, updated alongside this
--    migration).
--
-- 2. `reconciliation_flags` — the admin-visible "something doesn't
--    add up" queue. `platform-ledger.php` already had a single
--    project-wide balance check (Net Balance Held vs -1*SUM(negative
--    current_due)) — useful, but it's one coarse number, not a
--    reviewable list of the individual rows behind a mismatch, and it
--    doesn't persist: reload the page and a resolved-vs-open history
--    is gone. This table is the persisted, actionable version:
--    `lib/reconciliation.php`'s scan writes rows here (deduped by
--    (flag_type, entity_type, entity_id)), and `admin/reconciliation
--    .php` is where an admin reviews / resolves / ignores them.
-- ============================================================

-- ---------- Part 1: restaurant_due_ledger settlement linkage ----------

ALTER TABLE restaurant_due_ledger
    ADD COLUMN restaurant_payment_id BIGINT UNSIGNED NULL AFTER order_id,
    ADD CONSTRAINT fk_ledger_restaurant_payment FOREIGN KEY (restaurant_payment_id) REFERENCES restaurant_payments(id),
    ADD INDEX idx_ledger_restaurant_payment (restaurant_payment_id);

-- Best-effort backfill for history written before this column
-- existed. Matches a settlement-type ledger row to the
-- restaurant_payments row that most plausibly caused it: same
-- restaurant, matching direction/amount sign, and created within 120
-- seconds of each other (record_settlement() writes both inside the
-- same request/transaction, so real matches are seconds apart, not
-- coincidental same-day matches). Deliberately 1:1 — ROW_NUMBER on
-- both sides means an ambiguous case (two settlements of the same
-- amount to the same restaurant within the same 120s window) is left
-- unlinked rather than guessed wrong; those will surface as their own
-- reconciliation flags afterward, which is correct — a human should
-- look at a genuinely ambiguous pair, not have this migration silently
-- pick one.
DROP TEMPORARY TABLE IF EXISTS _recon_ledger_payment_match;
CREATE TEMPORARY TABLE _recon_ledger_payment_match AS
SELECT
    l.id AS ledger_id,
    p.id AS payment_id,
    ROW_NUMBER() OVER (PARTITION BY l.id ORDER BY ABS(TIMESTAMPDIFF(SECOND, l.created_at, p.created_at))) AS rn_ledger,
    ROW_NUMBER() OVER (PARTITION BY p.id ORDER BY ABS(TIMESTAMPDIFF(SECOND, l.created_at, p.created_at))) AS rn_payment
FROM restaurant_due_ledger l
JOIN restaurant_payments p
    ON p.restaurant_id = l.restaurant_id
   AND p.status = 'verified'
   AND (
        (l.entry_type = 'settlement_to_restaurant'   AND p.direction = 'admin_to_restaurant'   AND ABS(l.amount - p.amount) < 0.01)
     OR (l.entry_type = 'settlement_from_restaurant' AND p.direction = 'restaurant_to_admin'    AND ABS(l.amount + p.amount) < 0.01)
   )
   AND ABS(TIMESTAMPDIFF(SECOND, l.created_at, p.created_at)) <= 120
WHERE l.entry_type IN ('settlement_to_restaurant', 'settlement_from_restaurant')
  AND l.restaurant_payment_id IS NULL;

UPDATE restaurant_due_ledger l
JOIN _recon_ledger_payment_match m ON m.ledger_id = l.id AND m.rn_ledger = 1 AND m.rn_payment = 1
SET l.restaurant_payment_id = m.payment_id
WHERE l.restaurant_payment_id IS NULL;

DROP TEMPORARY TABLE IF EXISTS _recon_ledger_payment_match;

-- ---------- Part 2: the admin mismatch queue ----------

CREATE TABLE IF NOT EXISTS reconciliation_flags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    flag_type VARCHAR(64) NOT NULL,
    severity ENUM('info', 'warning', 'critical') NOT NULL DEFAULT 'warning',
    entity_type VARCHAR(32) NOT NULL,      -- 'order' | 'refund' | 'restaurant_payment' | 'platform'
    entity_id BIGINT UNSIGNED NOT NULL,    -- the row id within entity_type's own table (0 for a platform-wide flag, which has no single row)
    order_id BIGINT UNSIGNED NULL,
    restaurant_id BIGINT UNSIGNED NULL,
    description VARCHAR(500) NOT NULL,
    expected_value VARCHAR(255) NULL,
    actual_value VARCHAR(255) NULL,
    status ENUM('open', 'resolved', 'ignored') NOT NULL DEFAULT 'open',
    resolved_by_admin_id BIGINT UNSIGNED NULL,
    resolved_at TIMESTAMP NULL,
    resolution_note VARCHAR(500) NULL,
    first_detected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_reconflag_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_reconflag_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_reconflag_resolved_by FOREIGN KEY (resolved_by_admin_id) REFERENCES admins(id),
    UNIQUE KEY uq_reconflag_identity (flag_type, entity_type, entity_id),
    INDEX idx_reconflag_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- RBAC: new permission keys (same pattern every prior finance-feature migration used) ----------
-- Deliberately separate from `refunds_manage`/`payment_providers_manage`/
-- `payouts_manage` — this page only ever marks a flag resolved/ignored,
-- it never moves money or edits a financial row, but "who's allowed to
-- see/clear a fraud-relevant queue" is still its own distinct concern.
INSERT IGNORE INTO admin_permissions (`key`, module, action) VALUES
    ('reconciliation_view', 'reconciliation', 'view'),
    ('reconciliation_manage', 'reconciliation', 'manage');

-- Grant both to every role that already holds `payment_providers_manage`
-- (i.e. today, just Super Admin) — same "don't silently reduce anyone's
-- access" principle every prior permission-adding migration here uses.
INSERT IGNORE INTO admin_role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id
FROM admin_role_permissions rp
JOIN admin_permissions existing ON existing.id = rp.permission_id AND existing.`key` = 'payment_providers_manage'
JOIN admin_permissions np ON np.`key` IN ('reconciliation_view', 'reconciliation_manage');
