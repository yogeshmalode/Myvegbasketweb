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

## Management System Enhancements
- **Inventory:** supplier name, purchase/cost price, stock control, and automatic selling-price helper using a **45% markup** (cost × 1.45).
- **Billing / POS:** counter billing with Cash/UPI payment selection, percentage discount before checkout, atomic stock deduction, and printable receipts showing the discount.
- **Wastage:** record Expired, Damaged, Spoiled, or Other wastage with notes; stock is reduced atomically.
- **Reports:** date-range P&L for paid orders, product cost, gross profit, wastage loss, and net profit.
- **Roles:** separate Admin and Staff sessions. Admins can create staff/admin accounts; operational pages are available to Staff.
- **Security:** new management forms use CSRF protection, password hashing, prepared statements, session regeneration on login, and transactional stock changes with row locking.

### Existing database installation
If your existing database is already in use, import `migration_management_system.sql` once before using the new management pages. For a fresh installation, `database.sql` already contains the new tables/columns.

## Product photographs

The storefront product cards now use real photographs from Wikimedia Commons instead of the original SVG illustrations. The selected files are mapped in `config.php`, loaded from their Commons file URLs, and fall back to an emoji if a remote photograph is temporarily unavailable. `photo_credits.php` lists the source file, creator and stated licence for each product photograph.

## Opt-in SaaS tenant routing

The current storefront remains the central store by default. Tenant routing is opt-in through `VEGBASKET_TENANT_RUNTIME_CONFIG`; when that variable is unset, requests continue to use the existing database and settings. Do not set it until tenant databases, domains, and payment settings have been prepared and tested.

The tenant registry migration is `platform/migrations/001_tenant_registry.sql`; DNS challenge fields are added by `platform/migrations/002_tenant_domain_verification.sql`. Apply both migrations to the current central database. The registry holds tenant status, exact verified domain mappings, database/secret references, and per-store display, currency, notification-email, UPI QR, and default store-location settings.

To enable routing in cPanel, create a private PHP config file outside the document root and configure `VEGBASKET_TENANT_RUNTIME_CONFIG` in the PHP application's environment to its absolute path:

```php
<?php
return [
    'central_hosts' => ['myvegbasket.com', 'www.myvegbasket.com'],
    'tenant_secret_directory' => '/home/account/private/tenant-db-secrets',
];
```

Requests to the exact `central_hosts` keep using the current central-store connection. Other hosts must match an active tenant with a verified exact domain record; unknown hosts receive 404. Do not use wildcard suffix matching or forwarded-host headers. Configure the web server to accept only the intended hostnames before they reach PHP. Your platform base domain is still undecided, so replace the example central hosts and provision wildcard DNS/virtual hosts only after choosing it.

### Admin-panel onboarding

Central admins can manage tenants at **Admin → Administration → Tenants** (`admin/tenants.php`). The page generates a 48-hour DNS TXT challenge; add the displayed `_myvegbasket-verification.<tenant-domain>` record with the provided token, then click **Check DNS TXT & verify**. It checks DNS from PHP and will not mark a domain verified until the token resolves. DNS ownership proof does not configure cPanel's domain/virtual host or TLS certificate; set those up separately.

For one-click database/schema provisioning, configure these environment variables for PHP-FPM/cPanel before opening Tenant Management:

```text
VEGBASKET_CPANEL_API_URL=https://your-cpanel-server.example:2083
VEGBASKET_CPANEL_USERNAME=your-dedicated-cpanel-account
VEGBASKET_CPANEL_API_TOKEN=store-this-only-in-server-environment
VEGBASKET_TENANT_SECRET_DIRECTORY=/home/account/private/tenant-db-secrets
VEGBASKET_TENANT_DB_HOST=localhost
VEGBASKET_TENANT_DB_PORT=3306
VEGBASKET_CPANEL_CLUSTER_ID=cpanel-main
```

Use HTTPS with a valid server certificate. Never put the API token in the repository, browser, or database. Prefer a dedicated cPanel account with only the required MySQL provisioning permissions. The secret directory must be writable by PHP but outside the document root. With this configuration the admin form can create the cPanel database and user, grant that user access to its own database, write a random database password to a private JSON file, copy the current database's **table structure only** (no central rows, and excluding platform registry tables), and create the tenant's default fulfillment location. If cPanel API is not configured, the page falls back to manual database and credential setup.

When onboarding manually, create a dedicated database and user in cPanel, and grant that user access only to that database. Place a JSON credential file named after the tenant registry's `credential_ref` in the private secret directory:

```json
{
  "host": "localhost",
  "port": 3306,
  "database": "account_tenant1",
  "username": "account_user1",
  "password": "replace-with-the-tenant-database-password"
}
```

The tenant schema has no admin/customer/product/order rows. After activating the tenant database, open that tenant's `/admin/setup.php` once to create its first admin account, then remove/disable that setup endpoint for production. Add the tenant's products through its own admin panel. Set the notification email, currency symbol, UPI QR image path and payee name, and default store name/coordinates in Tenant Management. The QR image must be a local absolute path served by the app. Razorpay checkout endpoints are explicitly denied on tenant domains so the existing central merchant credentials cannot be used by tenant stores.

The tenant-aware switch is in the shared `config.php` bootstrap, so existing storefront, AJAX, admin, and delivery routes use the resolved tenant PDO consistently. Tenant schema auto-healing is disabled. Automatic onboarding copies table definitions only and refuses a non-empty target database; it does not copy central customer/order/product rows, and it does not copy views, triggers, or routines. For manual setup, export/import the application schema as **structure only** and apply the migrations that the tenant store needs. Admin schema checks use the currently connected database. Secrets and runtime config must remain outside the document root and version control, with restrictive filesystem permissions.

Tenant registration, DNS TXT setup, cPanel domain/SSL setup, runtime enablement, and tenant admin creation still require deliberate admin/hosting steps. Database/user creation and schema-only setup can be automated through cPanel UAPI when configured.

New tenants remain in `provisioning`; the page never requests or stores database passwords. Before using **Preflight & activate**, verify DNS, configure the domain/SSL in cPanel, enable the runtime config, and ensure the tenant database has the required tables. Activation checks the verified DNS proof, matching private database credential, successful database connection, and core application tables. Tenant management is blocked on tenant storefront hosts and is central-admin-only.

Run the isolated routing safeguards test without connecting to or modifying MySQL:

```bash
php platform/tests/TenantRoutingTest.php
```
