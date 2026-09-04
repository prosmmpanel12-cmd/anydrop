-- ============================================================
-- Anydrop — Migration 39: Payment Provider Architecture
-- (recall.md Phase C item 24, doc 19 §8)
--
-- Interface-and-registry pattern, deliberately identical in shape to
-- the (also still unbuilt) Email OTP provider architecture doc 19 §7
-- describes — a later gateway swap (Razorpay, Cashfree, PhonePe...)
-- should never require touching order-processing code, only adding a
-- new payment_providers row + a new class implementing
-- PaymentProviderInterface.
-- ============================================================

CREATE TABLE IF NOT EXISTS payment_providers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,           -- 'UPIPE', 'Razorpay', 'Cashfree', ...
    driver_key VARCHAR(50) NOT NULL,     -- maps to a PaymentProviderInterface class, see lib/payment/PaymentService.php
    config_json TEXT NOT NULL,           -- merchant ID, keys — UPIPE's real values added later
                                          -- when the SDK/API details are provided; empty
                                          -- JSON object is fine while it's still a stub
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_test_mode TINYINT(1) NOT NULL DEFAULT 1, -- doc 19 §8's "Admin test mode" — starts ON,
                                                  -- so nothing can accidentally look like a real
                                                  -- payment until an admin deliberately flips it
    priority INT NOT NULL DEFAULT 0,     -- for future multi-gateway failover, higher = tried first
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    provider_id BIGINT UNSIGNED NOT NULL,
    provider_txn_id VARCHAR(100) NULL,   -- the provider's own reference once real integration lands
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('initiated','success','failed','refunded') NOT NULL DEFAULT 'initiated',
    raw_response_json TEXT NULL,         -- store the provider's full response for disputes —
                                          -- populated even by the stub provider, so the shape
                                          -- of this column never needs to change at swap time
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ptxn_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_ptxn_provider FOREIGN KEY (provider_id) REFERENCES payment_providers(id),
    INDEX idx_ptxn_order (order_id),
    INDEX idx_ptxn_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the one provider that exists today — still a stub (no real
-- UPIPE credentials), but the row needs to exist for PaymentService's
-- "pick the active provider by priority" lookup to have anything to find.
INSERT IGNORE INTO payment_providers (name, driver_key, config_json, is_active, is_test_mode, priority)
VALUES ('UPIPE', 'upipe', '{}', 1, 1, 100);
