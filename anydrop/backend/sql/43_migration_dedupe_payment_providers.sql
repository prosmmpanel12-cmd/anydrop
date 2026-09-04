-- ============================================================
-- Anydrop — Migration 43: dedupe payment_providers, enforce uniqueness
--
-- Root cause: `driver_key` had no UNIQUE constraint, so migration 39's
-- `INSERT IGNORE` seed silently inserted a fresh row every time that
-- script ran more than once — nothing existed for IGNORE to collide
-- against. Symptom in the field: two "UPIPE" cards on
-- admin/payment-gateways.php, and edits/toggles (Active, Test Mode,
-- UPI ID, MID) on the wrong one having no effect, because
-- PaymentService::getActiveProvider() always resolves ties by lowest
-- id — the OLDEST row silently keeps winning regardless of which
-- card an admin has been editing.
--
-- Fix, in order:
--   1. For each driver_key with duplicates, pick a "keeper" — prefer
--      whichever row has a non-empty upi_id in its config_json (i.e.
--      the one an admin actually configured), falling back to lowest
--      id if none do.
--   2. Repoint any payment_transactions rows referencing a
--      to-be-deleted duplicate over to the keeper (never orphan a
--      transaction's provider_id).
--   3. Delete the duplicate row(s).
--   4. Add a UNIQUE constraint on driver_key so this can never
--      recur, and make future re-runs of a seed insert genuinely
--      idempotent (INSERT IGNORE now has something to ignore against).
-- ============================================================

-- Step 1+2+3: merge duplicates, keeping the row with a real upi_id
-- configured if one exists, otherwise the lowest id.
SET @keep := NULL;

DROP TEMPORARY TABLE IF EXISTS _ptxn_keepers;
CREATE TEMPORARY TABLE _ptxn_keepers AS
SELECT driver_key,
       SUBSTRING_INDEX(
           SUBSTRING_INDEX(
               GROUP_CONCAT(
                   id
                   ORDER BY (JSON_UNQUOTE(JSON_EXTRACT(config_json, '$.upi_id')) IS NOT NULL
                             AND JSON_UNQUOTE(JSON_EXTRACT(config_json, '$.upi_id')) != '') DESC,
                            id ASC
               ),
               ',', 1
           ),
           ',', -1
       ) AS keeper_id
FROM payment_providers
GROUP BY driver_key;

-- Repoint transactions from any duplicate row onto its driver_key's keeper.
UPDATE payment_transactions t
JOIN payment_providers p ON p.id = t.provider_id
JOIN _ptxn_keepers k ON k.driver_key = p.driver_key
SET t.provider_id = k.keeper_id
WHERE p.id != k.keeper_id;

-- Delete every duplicate row that isn't its driver_key's keeper.
DELETE p FROM payment_providers p
JOIN _ptxn_keepers k ON k.driver_key = p.driver_key
WHERE p.id != k.keeper_id;

DROP TEMPORARY TABLE IF EXISTS _ptxn_keepers;

-- Step 4: prevent this from ever happening again.
ALTER TABLE payment_providers
    ADD CONSTRAINT uq_pprov_driver_key UNIQUE (driver_key);
