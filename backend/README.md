# NovaPOS Backend API

Production-grade Laravel 11/12 REST API backend for **NovaPOS**, a Point of Sale and inventory management system.

---

## 1. Setup & Installation (macOS with XAMPP)

### Prerequisites
- macOS
- PHP 8.2+ (`/Applications/XAMPP/xamppfiles/bin/php`)
- MySQL / MariaDB via XAMPP running on `127.0.0.1:3306` (or socket `/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock`)
- Composer 2.x

### Quick Setup Steps
1. Navigate to the backend directory:
   ```bash
   cd backend
   ```
2. Copy environment file and configure database credentials:
   ```bash
   cp .env.example .env
   ```
   Verify database parameters in `.env`:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=novapos
   DB_USERNAME=novapos
   DB_PASSWORD=change_me
   DB_SOCKET=/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock
   POS_TAX_RATE=0.08
   SANCTUM_TOKEN_EXPIRATION=720
   ```
3. Install dependencies:
   ```bash
   /Applications/XAMPP/xamppfiles/bin/php /opt/homebrew/bin/composer install
   ```
4. Generate application key:
   ```bash
   /Applications/XAMPP/xamppfiles/bin/php artisan key:generate
   ```

---

## 2. Database Migrations & Seeding

Run migrations fresh with the production seeder:
```bash
/Applications/XAMPP/xamppfiles/bin/php artisan migrate:fresh --seed
```

### Default Accounts & Security Warning

> [!WARNING]
> The seeded accounts below are for development and testing only. **Change these passwords immediately in production environments.**

| Role | Email | Password | Permissions |
|---|---|---|---|
| **Admin** | `admin@novapos.test` | `password` | Full access (Product & Category CRUD, Stock Adjustments, All Sales, Dashboard) |
| **Cashier** | `cashier@novapos.test` | `password` | POS Checkout, Products & Categories (Read-only), Own Sales, Dashboard |

---

## 3. Running Tests & Quality Verification

Run Pest feature and unit test suites:
```bash
/Applications/XAMPP/xamppfiles/bin/php artisan test
```

Run Laravel Pint code style verification:
```bash
/Applications/XAMPP/xamppfiles/bin/php vendor/bin/pint --test
```

---

## 4. Starting the Server

Start the local development server:
```bash
/Applications/XAMPP/xamppfiles/bin/php artisan serve --host=127.0.0.1 --port=8000
```
- **Base URL**: `http://127.0.0.1:8000/api`

---

## 5. API Reference & Example cURL Commands

All endpoints are prefixed with `/api` and return strictly JSON.

### Authentication

#### Login
```bash
curl -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"cashier@novapos.test","password":"password"}'
```

#### Logout
```bash
curl -X POST http://127.0.0.1:8000/api/auth/logout \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Accept: application/json"
```

---

### Dashboard

#### Get Dashboard Analytics
```bash
curl -X GET http://127.0.0.1:8000/api/dashboard \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Accept: application/json"
```

---

### Categories

#### List Categories (Paginated, includes `products_count`)
```bash
curl -X GET "http://127.0.0.1:8000/api/categories?per_page=20" \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Accept: application/json"
```

#### Create Category (Admin Only)
```bash
curl -X POST http://127.0.0.1:8000/api/categories \
  -H "Authorization: Bearer <ADMIN_TOKEN>" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"name":"Pastries","slug":"pastries","description":"Freshly baked pastries"}'
```

#### Update Category (Admin Only)
```bash
curl -X PUT http://127.0.0.1:8000/api/categories/1 \
  -H "Authorization: Bearer <ADMIN_TOKEN>" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"name":"Cold Beverages"}'
```

#### Delete Category (Admin Only - refused if products reference it)
```bash
curl -X DELETE http://127.0.0.1:8000/api/categories/1 \
  -H "Authorization: Bearer <ADMIN_TOKEN>" \
  -H "Accept: application/json"
```

---

### Products

#### List Products (Paginated, Search & Filter)
```bash
curl -X GET "http://127.0.0.1:8000/api/products?search=Coffee&low_stock=1&per_page=20" \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Accept: application/json"
```

#### Get Single Product
```bash
curl -X GET http://127.0.0.1:8000/api/products/1 \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Accept: application/json"
```

#### Create Product (Admin Only)
```bash
curl -X POST http://127.0.0.1:8000/api/products \
  -H "Authorization: Bearer <ADMIN_TOKEN>" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "category_id": 1,
    "name": "Espresso Roast",
    "sku": "BEV-005",
    "price": 3.75,
    "cost_price": 1.50,
    "stock_quantity": 50,
    "low_stock_threshold": 10,
    "image_url": "https://example.com/images/espresso.jpg"
  }'
```

#### Update Product (Admin Only)
```bash
curl -X PUT http://127.0.0.1:8000/api/products/1 \
  -H "Authorization: Bearer <ADMIN_TOKEN>" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"price": 4.75}'
```

#### Adjust Stock (Admin Only)
```bash
curl -X POST http://127.0.0.1:8000/api/products/1/adjust-stock \
  -H "Authorization: Bearer <ADMIN_TOKEN>" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"quantity": -5, "reference_note": "Spillage during restocking"}'
```

#### Delete Product (Admin Only - Soft Delete)
```bash
curl -X DELETE http://127.0.0.1:8000/api/products/1 \
  -H "Authorization: Bearer <ADMIN_TOKEN>" \
  -H "Accept: application/json"
```

---

### Sales & POS Checkout

#### Process Checkout (Cashier & Admin)
```bash
curl -X POST http://127.0.0.1:8000/api/sales \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "payment_method": "card",
    "discount_percent": 10,
    "items": [
      {"product_id": 1, "quantity": 2},
      {"product_id": 4, "quantity": 1}
    ]
  }'
```

#### List Sales (Cashier scoped to own sales, Admin sees all)
```bash
curl -X GET "http://127.0.0.1:8000/api/sales?per_page=20" \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Accept: application/json"
```

#### Get Sale Details (Owner or Admin)
```bash
curl -X GET http://127.0.0.1:8000/api/sales/1 \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Accept: application/json"
```
