# Architectural & Engineering Decisions (DECISIONS.md)

This document records all architectural, security, database, and API design decisions made where the specification was silent or allowed discretion.

---

### 1. Framework & Package Selection
- **Laravel Framework**: Used Laravel 12.x running on local PHP 8.2.4 (XAMPP macOS).
- **Packages**:
  - `laravel/sanctum`: Official Laravel API token authentication.
  - `pestphp/pest` (^3.8) & `pestphp/pest-plugin-laravel` (^3.0): Modern testing framework for expressive tests.
  - No other third-party dependencies were installed to minimize attack surface and supply chain risks.

---

### 2. Database & Schema Design
- **MySQL Strict Mode & Charset**: Configured `strict => true`, `charset => utf8mb4`, `collation => utf8mb4_unicode_ci` in `config/database.php`.
- **Money Columns**: Stored strictly as `decimal(10, 2)` (never float) for `price`, `cost_price`, `total_amount`, `discount_amount`, `tax_amount`, `unit_price`, and `subtotal`.
- **Foreign Keys**: Explicit `restrictOnDelete` on all parent references except `sale_items.sale_id` which uses `cascadeOnDelete`.
- **Default Users Migration**: Deleted Laravel default `0001_01_01_000000_create_users_table.php` and recreated the single clean users migration meeting the exact schema specification (`name(120)`, `email(191)` unique, `role` enum, timestamps).
- **Socket Connectivity**: Configured `DB_SOCKET=/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock` alongside `127.0.0.1:3306` for local XAMPP MariaDB/MySQL support.

---

### 3. Inventory & Stock Movement Convention
- **Application-Level CHECK Guarantee**: In addition to MySQL `unsignedInteger` columns, added an Eloquent model lifecycle hook (`saving`) on `Product` to enforce that `stock_quantity` can never be saved below zero.
- **Movement Type & Sign Convention**:
  - `type = 'in'`: Stock added upon product creation with positive integer quantity.
  - `type = 'out'`: Stock reduced upon checkout with positive integer magnitude sold (`quantity: N`, directionality denoted by type).
  - `type = 'adjustment'`: Stock updated via product edit or `/products/{product}/adjust-stock` with signed integer difference (positive for increase, negative for deduction).
- **Soft Deletes**: Soft deletes on `Product` preserve inventory audit trail in `stock_movements` and purchase records in `sale_items`.

---

### 4. Checkout Math & Concurrency
- **Concurrency & Deadlock Prevention**:
  1. Duplicate cart lines merged by `product_id` and summed before processing.
  2. Products ordered by ascending `product_id` and locked using `lockForUpdate()` inside a database transaction.
  3. Pre-transaction validation of stock ensures no partial state or stock deduction occurs if any product has insufficient stock.
- **Integer Cents Math**: Money calculations performed entirely in integer cents (`round(price * 100)`) to eliminate binary floating point rounding drift.
  - Line Subtotal (cents) = `price_cents * quantity`
  - Discount (cents) = `round(subtotal_cents * discount_percent / 100)` (half up)
  - Taxable (cents) = `subtotal_cents - discount_cents`
  - Tax (cents) = `round(taxable_cents * tax_rate_basis_points / 10000)` where 8% = 800 basis points.
  - Final amounts formatted as two-decimal strings (`10.00`, `9.72`).
- **Invoice Number Generation**: Format `NV-YYYYMMDD-XXXXXX` generated using cryptographically secure `Str::random(6)`. Up to 5 retries on collision inside transaction.

---

### 5. API Response Contracts & Status Codes
- **Category Deletion Conflict**: Returns HTTP 422 Unprocessable Content if active or trashed products reference the category, with message: `"Cannot delete category. It has associated products."`.
- **Category List Pagination**: Implemented pagination on `GET /api/categories` (`per_page` capped at 100, default 20) with `withCount('products')` to fulfill both "All list endpoints paginate" and "returns products_count".
- **Search Escaping**: Sanitized `%`, `_`, and `\` wildcards using parameter-bound `LIKE ? ESCAPE '\'` to ensure search security and identical behavior across MySQL and in-memory SQLite test suites.
- **Security Headers**: Custom middleware adds `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, and `Strict-Transport-Security: max-age=31536000; includeSubDomains` in production.
- **401 Unauthenticated Responses**: Custom exception renderer in `bootstrap/app.php` guarantees JSON `{"message": "Unauthenticated."}` for unauthenticated API requests, preventing web redirects.
