# VegBasket 🥕

A responsive, interactive vegetable ordering website built in plain PHP + MySQL, with a session-based cart, Razorpay payment integration, and an admin panel for full CRUD on the product catalog.

## Features
- **Interactive** — AJAX add-to-cart, live quantity steppers, slide-in cart drawer, toast notifications (no full page reloads for cart actions).
- **Responsive** — mobile-first CSS, works down to small phone widths.
- **Payment gateway** — Razorpay (test mode by default), with real server-side order creation and signature verification.
- **Vegetable list with price** — catalog page with search + category filter, price tags per item.
- **Cart facility** — session-based cart, drawer + full cart page, quantity update / remove.
- **CRUD functionality** — admin login + dashboard to Create, Read, Update, Delete vegetables, plus an orders view.

## Requirements
- PHP 8.0+
- MySQL 5.7+ / MariaDB
- A Razorpay account (free) for test API keys — https://dashboard.razorpay.com/app/keys

## Setup

1. **Import the database**
   ```bash
   mysql -u root -p < database.sql
   ```
   This creates the `vegbasket` database with `vegetables`, `orders`, `order_items`, and `admins` tables, plus 12 sample vegetables.

2. **Configure `config.php`**
   Edit these constants at the top of `config.php`:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'vegbasket');
   define('DB_USER', 'root');
   define('DB_PASS', '');

   define('RAZORPAY_KEY_ID', 'rzp_test_xxxxxxxxxxxxxx');
   define('RAZORPAY_KEY_SECRET', 'your_test_key_secret_here');

   define('BASE_URL', '/vegbasket'); // change if the folder name differs, or set to '' if hosted at the domain root
   ```

3. **Create your admin account**
   Place the project on your PHP server (e.g. in `htdocs/vegbasket` for XAMPP), then open:
   ```
   http://localhost/vegbasket/admin/setup.php
   ```
   Create a username/password. **Delete `admin/setup.php` afterwards** — it only works once (it refuses to run again if an admin already exists), but it's good practice to remove it from a live server.

4. **Browse the store**
   ```
   http://localhost/vegbasket/index.php
   ```
   Add items to the cart, go to checkout, and pay with a Razorpay **test card**:
   - Card number: `4111 1111 1111 1111`
   - Any future expiry date, any CVV, any name.

5. **Manage vegetables**
   ```
   http://localhost/vegbasket/admin/login.php
   ```
   Add, edit, delete vegetables and view placed orders.

## Folder structure
```
vegbasket/
├── admin/                 # admin panel (login, dashboard, CRUD, orders)
├── ajax/                  # cart + Razorpay endpoints called via fetch()
├── assets/css/style.css   # design system + responsive styles
├── assets/js/script.js    # cart drawer, AJAX add-to-cart, toasts
├── includes/              # shared header/footer
├── config.php             # DB + Razorpay + site settings
├── database.sql           # schema + seed data
├── index.php              # homepage / vegetable listing
├── cart.php                # full cart page
├── checkout.php            # customer details + Razorpay payment
├── verify_payment.php       # signature verification + order save
└── order_success.php       # confirmation page
```

## Security notes
- Passwords are hashed with PHP's `password_hash()` / verified with `password_verify()`.
- All database queries use PDO prepared statements.
- Razorpay payments are verified server-side via HMAC-SHA256 signature check — the client can never fake a "successful" payment.
- Swap in your **live** Razorpay keys only once you're ready to accept real payments, and serve the site over HTTPS.
