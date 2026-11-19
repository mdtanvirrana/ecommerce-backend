# Database Design — Nocfinity E-Commerce Platform

Target RDBMS: **MySQL 8.0+** (InnoDB engine for row-level locking via `SELECT ... FOR UPDATE`, ACID transactions, foreign key constraints, and `DECIMAL(12,2)` exact monetary precision).

## Entity-Relationship Overview

```
users (customers & staff, distinguished by user_type)
  ├──< role_user >── roles ──< permission_role >── permissions
  ├──1 carts ──< cart_items >── products
  ├──< orders ──< order_items >── products
  │      ├──< payments
  │      ├──< coupon_usages >── coupons
  │      └──< inventory_transactions >── products
  └──< activity_logs

categories (self-referencing parent_id for 2+ levels)
  └──< products ──< product_images
```

## Table Specifications

### 1. Authentication & Users
- **`users`**:
  - `id` BIGINT UNSIGNED PK
  - `name` VARCHAR(255)
  - `email` VARCHAR(255) UNIQUE
  - `phone` VARCHAR(30) NULLABLE
  - `user_type` ENUM('customer', 'staff') DEFAULT 'customer' INDEX
  - `is_active` BOOLEAN DEFAULT true INDEX
  - `email_verified_at` TIMESTAMP NULLABLE
  - `password` VARCHAR(255) (bcrypt hashed)
  - `remember_token` VARCHAR(100) NULLABLE
  - `timestamps`, `softDeletes`
- **`password_reset_tokens`**: `email` PK, `token`, `created_at`
- **`personal_access_tokens`** (Sanctum): `id`, `tokenable_type`, `tokenable_id`, `name`, `token` UNIQUE, `abilities`, `last_used_at`, `expires_at`, `timestamps`

### 2. Database-Driven RBAC
- **`roles`**:
  - `id` BIGINT UNSIGNED PK
  - `name` VARCHAR(100) UNIQUE (e.g., `Super Admin`, `Admin`, `Product Manager`, `Order Manager`, `Support`)
  - `slug` VARCHAR(100) UNIQUE (`super-admin`, `admin`, `product-manager`, `order-manager`, `support`)
  - `description` VARCHAR(255) NULLABLE
  - `is_system` BOOLEAN DEFAULT false (protects core roles / Super Admin)
  - `timestamps`
- **`permissions`**:
  - `id` BIGINT UNSIGNED PK
  - `name` VARCHAR(100) UNIQUE (e.g., `products.create`, `orders.update_status`)
  - `group` VARCHAR(60) INDEX (`products`, `categories`, `orders`, `coupons`, `customers`, `reports`, `staff`, `roles`)
  - `description` VARCHAR(255) NULLABLE
  - `timestamps`
- **`role_user`** (pivot):
  - `role_id` FK -> `roles.id` ON DELETE CASCADE
  - `user_id` FK -> `users.id` ON DELETE CASCADE
  - PRIMARY (`role_id`, `user_id`)
- **`permission_role`** (pivot):
  - `permission_id` FK -> `permissions.id` ON DELETE CASCADE
  - `role_id` FK -> `roles.id` ON DELETE CASCADE
  - PRIMARY (`permission_id`, `role_id`)

### 3. Catalog
- **`categories`**:
  - `id` BIGINT UNSIGNED PK
  - `parent_id` BIGINT UNSIGNED NULLABLE FK -> `categories.id` ON DELETE RESTRICT
  - `name` VARCHAR(150)
  - `slug` VARCHAR(170) UNIQUE
  - `description` TEXT NULLABLE
  - `image_path` VARCHAR(255) NULLABLE
  - `sort_order` INT DEFAULT 0
  - `is_active` BOOLEAN DEFAULT true INDEX
  - `timestamps`, `softDeletes`
  - Index on `(parent_id, is_active)`
- **`products`**:
  - `id` BIGINT UNSIGNED PK
  - `category_id` BIGINT UNSIGNED FK -> `categories.id` ON DELETE RESTRICT
  - `name` VARCHAR(255)
  - `slug` VARCHAR(255) UNIQUE
  - `sku` VARCHAR(100) UNIQUE
  - `description` TEXT NULLABLE
  - `regular_price` DECIMAL(12,2) UNSIGNED
  - `discount_price` DECIMAL(12,2) UNSIGNED NULLABLE
  - `stock_quantity` INT UNSIGNED DEFAULT 0 INDEX
  - `low_stock_threshold` INT UNSIGNED DEFAULT 5
  - `is_active` BOOLEAN DEFAULT true INDEX
  - `is_featured` BOOLEAN DEFAULT false INDEX
  - `is_new_arrival` BOOLEAN DEFAULT false INDEX
  - `is_top_selling` BOOLEAN DEFAULT false INDEX
  - `rating` DECIMAL(3,2) DEFAULT 4.50
  - `reviews_count` INT UNSIGNED DEFAULT 0
  - `colors` JSON NULLABLE (e.g., `["#4F4631", "#314F4A", "#31344F"]`)
  - `sizes` JSON NULLABLE (e.g., `["Small", "Medium", "Large", "X-Large"]`)
  - `timestamps`, `softDeletes`
  - Indexes: `(category_id, is_active)`, `(is_active, regular_price)`, `(is_active, created_at)`
- **`product_images`**:
  - `id` BIGINT UNSIGNED PK
  - `product_id` BIGINT UNSIGNED FK -> `products.id` ON DELETE CASCADE
  - `path` VARCHAR(255)
  - `alt_text` VARCHAR(255) NULLABLE
  - `is_primary` BOOLEAN DEFAULT false
  - `sort_order` INT UNSIGNED DEFAULT 0
  - `timestamps`
  - Index on `(product_id, sort_order)`

### 4. Shopping Cart
- **`carts`**:
  - `id` BIGINT UNSIGNED PK
  - `user_id` BIGINT UNSIGNED UNIQUE FK -> `users.id` ON DELETE CASCADE
  - `coupon_id` BIGINT UNSIGNED NULLABLE FK -> `coupons.id` ON DELETE SET NULL
  - `timestamps`
- **`cart_items`**:
  - `id` BIGINT UNSIGNED PK
  - `cart_id` BIGINT UNSIGNED FK -> `carts.id` ON DELETE CASCADE
  - `product_id` BIGINT UNSIGNED FK -> `products.id` ON DELETE CASCADE
  - `quantity` INT UNSIGNED
  - `selected_color` VARCHAR(50) NULLABLE
  - `selected_size` VARCHAR(50) NULLABLE
  - `timestamps`
  - Unique index on `(cart_id, product_id, selected_color, selected_size)` (or `(cart_id, product_id)` normalized with default `''` strings to prevent duplicate rows cleanly in MySQL).

### 5. Coupons & Usage Tracking
- **`coupons`**:
  - `id` BIGINT UNSIGNED PK
  - `code` VARCHAR(64) UNIQUE
  - `type` ENUM('percentage', 'fixed')
  - `value` DECIMAL(12,2) UNSIGNED
  - `minimum_order_amount` DECIMAL(12,2) UNSIGNED DEFAULT 0.00
  - `maximum_discount_amount` DECIMAL(12,2) UNSIGNED NULLABLE
  - `usage_limit` INT UNSIGNED NULLABLE
  - `per_customer_usage_limit` INT UNSIGNED NULLABLE
  - `used_count` INT UNSIGNED DEFAULT 0
  - `starts_at` TIMESTAMP NULLABLE
  - `expires_at` TIMESTAMP NULLABLE
  - `is_active` BOOLEAN DEFAULT true INDEX
  - `timestamps`, `softDeletes`
- **`coupon_usages`**:
  - `id` BIGINT UNSIGNED PK
  - `coupon_id` BIGINT UNSIGNED FK -> `coupons.id` ON DELETE CASCADE
  - `user_id` BIGINT UNSIGNED FK -> `users.id` ON DELETE CASCADE
  - `order_id` BIGINT UNSIGNED FK -> `orders.id` ON DELETE CASCADE
  - `discount_amount` DECIMAL(12,2) UNSIGNED
  - `timestamps`
  - Indexes on `(coupon_id, user_id)` and UNIQUE `(coupon_id, order_id)`

### 6. Orders & Order Sequence
- **`order_sequences`** (concurrency-safe human-readable order number generator):
  - `year` SMALLINT UNSIGNED PK
  - `last_number` INT UNSIGNED DEFAULT 0
  - Locked with `SELECT ... FOR UPDATE` inside checkout transaction to generate `ORD-YYYY-000001` with zero gaps or collisions.
- **`orders`**:
  - `id` BIGINT UNSIGNED PK
  - `order_number` VARCHAR(32) UNIQUE
  - `user_id` BIGINT UNSIGNED FK -> `users.id` ON DELETE RESTRICT
  - `coupon_id` BIGINT UNSIGNED NULLABLE FK -> `coupons.id` ON DELETE SET NULL
  - `coupon_code` VARCHAR(64) NULLABLE
  - `status` ENUM('pending', 'paid', 'processing', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending' INDEX
  - `payment_status` ENUM('pending', 'succeeded', 'failed', 'cancelled', 'refunded', 'needs_resolution') DEFAULT 'pending' INDEX
  - `subtotal` DECIMAL(12,2) UNSIGNED
  - `discount_amount` DECIMAL(12,2) UNSIGNED DEFAULT 0.00
  - `shipping_amount` DECIMAL(12,2) UNSIGNED DEFAULT 0.00
  - `total_amount` DECIMAL(12,2) UNSIGNED
  - `currency` VARCHAR(3) DEFAULT 'usd'
  - `shipping_full_name` VARCHAR(255)
  - `shipping_email` VARCHAR(255)
  - `shipping_phone` VARCHAR(50)
  - `shipping_address` TEXT
  - `shipping_city` VARCHAR(120)
  - `shipping_postal_code` VARCHAR(30)
  - `delivery_instructions` TEXT NULLABLE
  - `stock_deducted_at` TIMESTAMP NULLABLE
  - `stock_restored_at` TIMESTAMP NULLABLE
  - `paid_at` TIMESTAMP NULLABLE
  - `cancelled_at` TIMESTAMP NULLABLE
  - `cancellation_reason` TEXT NULLABLE
  - `timestamps`
  - Indexes: `(user_id, created_at)`, `(status, created_at)`, `(payment_status, created_at)`
- **`order_items`**:
  - `id` BIGINT UNSIGNED PK
  - `order_id` BIGINT UNSIGNED FK -> `orders.id` ON DELETE CASCADE
  - `product_id` BIGINT UNSIGNED FK -> `products.id` ON DELETE RESTRICT
  - `product_name` VARCHAR(255) (snapshot)
  - `product_sku` VARCHAR(100) (snapshot)
  - `selected_color` VARCHAR(50) NULLABLE
  - `selected_size` VARCHAR(50) NULLABLE
  - `unit_price` DECIMAL(12,2) UNSIGNED (server-verified snapshot at checkout)
  - `quantity` INT UNSIGNED
  - `line_total` DECIMAL(12,2) UNSIGNED
  - `timestamps`

### 7. Payments, Webhooks & Audit
- **`payments`**:
  - `id` BIGINT UNSIGNED PK
  - `order_id` BIGINT UNSIGNED FK -> `orders.id` ON DELETE CASCADE
  - `provider` VARCHAR(32) DEFAULT 'stripe'
  - `stripe_payment_intent_id` VARCHAR(255) UNIQUE NULLABLE
  - `stripe_charge_id` VARCHAR(255) NULLABLE
  - `stripe_refund_id` VARCHAR(255) NULLABLE
  - `amount` DECIMAL(12,2) UNSIGNED
  - `currency` VARCHAR(3) DEFAULT 'usd'
  - `status` ENUM('pending', 'succeeded', 'failed', 'cancelled', 'refunded') DEFAULT 'pending' INDEX
  - `failure_code` VARCHAR(100) NULLABLE
  - `failure_message` TEXT NULLABLE
  - `metadata` JSON NULLABLE
  - `verified_at` TIMESTAMP NULLABLE
  - `refunded_at` TIMESTAMP NULLABLE
  - `timestamps`
- **`webhook_events`** (idempotency table):
  - `id` BIGINT UNSIGNED PK
  - `provider` VARCHAR(32) DEFAULT 'stripe'
  - `event_id` VARCHAR(255) UNIQUE
  - `event_type` VARCHAR(120) INDEX
  - `status` ENUM('processing', 'processed', 'failed', 'ignored') DEFAULT 'processing'
  - `payload` JSON NULLABLE
  - `error_message` TEXT NULLABLE
  - `processed_at` TIMESTAMP NULLABLE
  - `timestamps`
- **`inventory_transactions`** (full stock audit trail):
  - `id` BIGINT UNSIGNED PK
  - `product_id` BIGINT UNSIGNED FK -> `products.id` ON DELETE CASCADE
  - `order_id` BIGINT UNSIGNED NULLABLE FK -> `orders.id` ON DELETE SET NULL
  - `actor_id` BIGINT UNSIGNED NULLABLE FK -> `users.id` ON DELETE SET NULL
  - `type` ENUM('sale_deduction', 'cancellation_restoration', 'manual_adjustment')
  - `quantity_change` INT (negative for deduction, positive for restoration)
  - `stock_before` INT UNSIGNED
  - `stock_after` INT UNSIGNED
  - `notes` VARCHAR(255) NULLABLE
  - `timestamps`
- **`activity_logs`**:
  - `id` BIGINT UNSIGNED PK
  - `actor_id` BIGINT UNSIGNED NULLABLE FK -> `users.id` ON DELETE SET NULL
  - `action` VARCHAR(100) INDEX
  - `subject_type` VARCHAR(120) NULLABLE
  - `subject_id` BIGINT UNSIGNED NULLABLE
  - `properties` JSON NULLABLE
  - `ip_address` VARCHAR(45) NULLABLE
  - `timestamps`
