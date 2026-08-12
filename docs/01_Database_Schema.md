# Anydrop — Database Schema (MySQL)

**Version:** 1.0 · **Depends on:** `00_README.md`

This schema is designed to work within InfinityFree's MySQL limits (single DB, no stored procedures/triggers reliance beyond basics, InnoDB engine). All tables use `id BIGINT UNSIGNED AUTO_INCREMENT` primary keys, `created_at` / `updated_at` timestamps, and soft deletes (`deleted_at`) where deletion history matters.

---

## 1. Core Identity Tables

### `customers`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| name | VARCHAR(100) | |
| email | VARCHAR(150) UNIQUE | Login identity |
| mobile | VARCHAR(15) | Contact only, never used for OTP login |
| login_type | ENUM('google','email') | |
| google_id | VARCHAR(255) NULL | |
| profile_photo | VARCHAR(255) NULL | |
| is_active | TINYINT(1) DEFAULT 1 | Admin can suspend |
| created_at, updated_at, deleted_at | TIMESTAMP | |

### `restaurants`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| name | VARCHAR(150) | |
| owner_name | VARCHAR(100) | |
| owner_mobile | VARCHAR(15) | |
| owner_email | VARCHAR(150) UNIQUE | |
| password_hash | VARCHAR(255) | bcrypt |
| address | TEXT | |
| latitude | DECIMAL(10,8) | |
| longitude | DECIMAL(11,8) | |
| logo_url | VARCHAR(255) NULL | |
| cover_url | VARCHAR(255) NULL | |
| cuisine_tags | VARCHAR(255) | comma-separated or JSON |
| is_veg_only | TINYINT(1) DEFAULT 0 | |
| opening_time | TIME | |
| closing_time | TIME | |
| working_days | VARCHAR(20) | e.g. "1,2,3,4,5,6,7" |
| delivery_radius_km | DECIMAL(4,1) | |
| min_order_amount | DECIMAL(8,2) | |
| gst_number | VARCHAR(20) NULL | |
| fssai_number | VARCHAR(30) NULL | |
| upi_id | VARCHAR(100) NULL | |
| description | TEXT NULL | |
| status | ENUM('pending','approved','rejected','suspended') DEFAULT 'pending' | |
| operational_status | ENUM('open','closed','busy','vacation','temp_closed','admin_disabled') DEFAULT 'closed' | |
| auto_accept_orders | TINYINT(1) DEFAULT 0 | |
| current_due | DECIMAL(10,2) DEFAULT 0 | Ledger balance owed to platform |
| commission_percent | DECIMAL(5,2) DEFAULT 15.00 | Overridable per restaurant |
| rating_avg | DECIMAL(3,2) DEFAULT 0 | Denormalized for fast reads |
| created_at, updated_at, deleted_at | TIMESTAMP | |

### `riders`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| restaurant_id | BIGINT FK → restaurants.id | Riders belong to one restaurant |
| name | VARCHAR(100) | |
| username | VARCHAR(50) UNIQUE | |
| password_hash | VARCHAR(255) | |
| mobile | VARCHAR(15) | |
| vehicle_type | VARCHAR(30) NULL | |
| vehicle_number | VARCHAR(20) NULL | |
| is_online | TINYINT(1) DEFAULT 0 | |
| is_active | TINYINT(1) DEFAULT 1 | Restaurant can disable |
| last_lat | DECIMAL(10,8) NULL | Last known location (fast lookup) |
| last_lng | DECIMAL(11,8) NULL | |
| last_location_at | TIMESTAMP NULL | |
| fcm_token | VARCHAR(255) NULL | For push notifications |
| created_at, updated_at, deleted_at | TIMESTAMP | |

### `admins`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| username | VARCHAR(50) UNIQUE | |
| password_hash | VARCHAR(255) | |
| role | ENUM('super_admin','staff') DEFAULT 'super_admin' | Future multi-admin support |
| created_at, updated_at | TIMESTAMP | |

---

## 2. Menu Tables

### `menu_categories`
| id | restaurant_id (FK) | name | sort_order | is_active |

### `menu_items`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| restaurant_id | FK | |
| category_id | FK → menu_categories.id | |
| name | VARCHAR(150) | |
| description | TEXT NULL | |
| price | DECIMAL(8,2) | |
| discount_percent | DECIMAL(5,2) DEFAULT 0 | |
| is_veg | TINYINT(1) | |
| image_url | VARCHAR(255) NULL | |
| is_available | TINYINT(1) DEFAULT 1 | |
| is_recommended | TINYINT(1) DEFAULT 0 | |
| is_bestseller | TINYINT(1) DEFAULT 0 | |
| prep_time_minutes | SMALLINT DEFAULT 15 | |
| created_at, updated_at, deleted_at | TIMESTAMP | |

### `menu_item_variants` (e.g. Half/Full)
| id | menu_item_id (FK) | name | price_delta |

### `menu_item_addons` (e.g. Extra Cheese)
| id | menu_item_id (FK) | name | price | is_active |

---

## 3. Order Tables

### `orders`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| order_code | VARCHAR(20) UNIQUE | Human-friendly (e.g. QRX-20260802-0001) |
| customer_id | FK | |
| restaurant_id | FK | |
| rider_id | FK NULL | Assigned later |
| status | ENUM('pending','accepted','rejected','preparing','ready','rider_assigned','picked_up','out_for_delivery','delivered','cancelled','refunded','failed','expired') | |
| item_total | DECIMAL(8,2) | |
| delivery_charge | DECIMAL(8,2) | |
| platform_fee | DECIMAL(8,2) | |
| packing_charge | DECIMAL(8,2) DEFAULT 0 | |
| tax_amount | DECIMAL(8,2) DEFAULT 0 | |
| discount_amount | DECIMAL(8,2) DEFAULT 0 | |
| grand_total | DECIMAL(8,2) | Customer pays this to restaurant |
| commission_amount | DECIMAL(8,2) | Computed at order time, goes to due ledger |
| payment_method | ENUM('upi','cod') | |
| payment_status | ENUM('pending','paid','failed','refunded') | |
| delivery_address_id | FK → customer_addresses.id | |
| delivery_instructions | TEXT NULL | |
| coupon_id | FK NULL | |
| delivery_otp | VARCHAR(6) NULL | Generated at rider-assigned stage |
| otp_verified_at | TIMESTAMP NULL | |
| otp_attempts | TINYINT DEFAULT 0 | |
| estimated_prep_minutes | SMALLINT NULL | |
| accepted_at, ready_at, picked_up_at, delivered_at, cancelled_at | TIMESTAMP NULL | Full timeline |
| cancellation_reason | VARCHAR(255) NULL | |
| created_at, updated_at | TIMESTAMP | |

### `order_items`
| id | order_id (FK) | menu_item_id (FK) | item_name_snapshot | variant_name | quantity | unit_price | addons_json | subtotal |

*(name/price snapshotted so historical orders don't change if restaurant edits menu later)*

### `order_status_history`
| id | order_id (FK) | status | changed_by_type ('system','restaurant','rider','admin') | changed_by_id | note | created_at |

*(Full audit trail — required by your "every state must have logs" rule)*

---

## 4. Delivery / Location Tables

### `rider_locations` (time-series, short retention)
| id | rider_id (FK) | order_id (FK NULL) | latitude | longitude | speed_kmh NULL | recorded_at |

Indexed on `(rider_id, recorded_at)`. A daily cleanup job deletes rows older than 48 hours (via pseudo-cron) to keep this table small — see `03_Live_Tracking.md`.

### `customer_addresses`
| id | customer_id (FK) | label | full_address | latitude | longitude | is_default |

---

## 5. Financial / Ledger Tables

### `restaurant_due_ledger`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| restaurant_id | FK | |
| order_id | FK NULL | Null for manual adjustments |
| entry_type | ENUM('commission','platform_fee','payment_received','manual_adjustment') | |
| amount | DECIMAL(10,2) | Positive = increases due, negative = payment received |
| running_balance | DECIMAL(10,2) | Balance after this entry |
| note | VARCHAR(255) NULL | |
| created_by | ENUM('system','admin') | |
| created_at | TIMESTAMP | |

*(This is the append-only ledger your business model requires. `restaurants.current_due` is a denormalized cache of the latest `running_balance`, updated whenever a row is inserted here.)*

### `restaurant_payments`
| id | restaurant_id (FK) | amount | payment_reference | verified_by_admin_id (FK NULL) | verified_at NULL | status ENUM('pending','verified','rejected') | created_at |

---

## 6. Promotions

### `coupons`
| id | code (UNIQUE) | restaurant_id (FK NULL, null = platform-wide) | discount_type ENUM('flat','percent') | discount_value | min_order_amount | max_discount_amount NULL | valid_from | valid_until | usage_limit_total | usage_limit_per_user | is_active |

### `coupon_usages`
| id | coupon_id (FK) | customer_id (FK) | order_id (FK) | used_at |

---

## 7. Reviews

### `reviews`
| id | order_id (FK) | customer_id (FK) | restaurant_id (FK) | rider_id (FK NULL) | restaurant_rating (1-5) | food_rating (1-5) | delivery_rating (1-5) | comment | restaurant_reply NULL | is_reported | created_at |

---

## 8. Notifications

### `notifications`
| id | recipient_type ENUM('customer','restaurant','rider','admin') | recipient_id | title | body | type ENUM('order','promo','system','security') | is_read | data_json NULL | created_at |

---

## 9. System / Admin Configuration

### `app_settings` (the "nothing hardcoded" table)
| `key` VARCHAR(100) PK | `value` TEXT | `description` VARCHAR(255) |

Example rows:
```
commission_default_percent      15
platform_fee_flat               5
restaurant_due_limit            2000
otp_required_for_cod            0
otp_length                      4
otp_expiry_minutes              10
otp_max_attempts                3
gps_ping_interval_moving_sec    7
gps_ping_interval_idle_sec      30
min_app_version_customer        1
maintenance_mode                0
```

### `app_versions`
| id | app_type ENUM('customer','restaurant','rider') | min_supported_version | latest_version | force_update TINYINT(1) | changelog TEXT |

### `audit_logs`
| id | actor_type ENUM('customer','restaurant','rider','admin','system') | actor_id | action | details_json | ip_address | created_at |

*(Every sensitive action — login, password reset, due-limit change, order status override — writes here.)*

---

## 10. Indexing Notes

- `orders`: index on `(restaurant_id, status)`, `(customer_id, created_at)`, `(rider_id, status)`
- `rider_locations`: index on `(rider_id, recorded_at DESC)` — this table is read constantly for live tracking, keep it lean
- `menu_items`: index on `(restaurant_id, category_id, is_available)`
- `restaurant_due_ledger`: index on `(restaurant_id, created_at)`

## 11. Why Append-Only Ledger Instead of Just a `due` Number

A single mutable "due" column can't answer "how did we get to ₹1850?" if a restaurant disputes it. The ledger gives a full paper trail — every commission entry and every payment is its own row, and `restaurants.current_due` is just a fast cache recomputed from (or verified against) the ledger. This matches enterprise accounting practice and protects you if a restaurant ever disputes a suspension.
