-- ============================================================
-- Anydrop — Migration 71: riders.username / password_hash → nullable
--
-- Context: doc 79 (Rider App Phase 1) flagged this as a follow-up
-- cleanup candidate — migration 69's self-signup path fills
-- `username`/`password_hash` (legacy NOT NULL columns from the old
-- restaurant-created-rider path, 01_schema.sql) with random
-- placeholder values a platform rider never actually uses, purely to
-- satisfy the NOT NULL constraint, "once it's confirmed nothing
-- restaurant side still depends on them being non-null."
--
-- Confirmed this session (grep across the whole backend/ tree):
--   - No admin page reads riders.username or riders.password_hash.
--   - No restaurant-facing API endpoint reads, writes, or creates a
--     riders row via username/password at all — the restaurant-scoped
--     rider model described in 01_schema.sql's comments has no actual
--     API surface built for it anywhere in this codebase; it's
--     schema-only. (This matches doc 79's own audit note under "What
--     already existed": the riders table "was found during audit, not
--     built this session" and was already restaurant-scoped in the
--     schema before any rider API existed.)
--   - The only INSERT INTO riders anywhere is rider-signup.php
--     (this same batch), which is what this migration lets stop
--     generating placeholders for.
-- Given that, these two columns are safe to make nullable — nothing
-- reads them expecting a non-null value, and no code path other than
-- the one this migration updates writes to them.
--
-- Deliberately NOT dropping the columns outright — a live DB may
-- already have real restaurant-created rider rows with real
-- username/password_hash values from before this feature existed;
-- making them nullable preserves those rows completely untouched
-- while just relaxing the constraint for future inserts. Dropping is
-- a bigger, harder-to-reverse step than this cleanup calls for.
--
-- Same idempotent CONTINUE-HANDLER pattern as every other ALTER-TABLE
-- migration in this project. MODIFY COLUMN is naturally idempotent
-- anyway (running it twice just re-applies the same nullable
-- definition), so no CONTINUE HANDLER is actually needed here, but
-- the pattern is kept for consistency with how every other migration
-- in this project documents/handles reruns.
-- ============================================================

ALTER TABLE riders MODIFY COLUMN username VARCHAR(50) NULL;
ALTER TABLE riders MODIFY COLUMN password_hash VARCHAR(255) NULL;

-- The old UNIQUE constraint on username (01_schema.sql) still applies
-- and is unaffected by NULL-ability — MySQL allows multiple NULLs
-- under a UNIQUE index, same reasoning migration 69 already relied on
-- for riders.email's unique index.

-- Confirm final state — uses SHOW, not information_schema (this
-- environment's DB user can't read information_schema).
SHOW COLUMNS FROM riders;
