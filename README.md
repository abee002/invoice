# Invoice App (PHP + MySQL)

A lightweight invoicing system with OTP login (email/phone), 24h sessions, first-time onboarding, customers/products, invoices with tax/discount, payments, and a print view.

## 1) Requirements

- PHP 8.1+ with extensions: `pdo_mysql`, `mbstring`, `openssl`, `json`, `ctype`, `filter`, `session`, `fileinfo`
- MySQL 8+ (or MariaDB 10.4+)
- Web server (Apache/Nginx). For Apache, see `public/.htaccess`.
- Composer **not required** for this starter (no external libs yet).

## 2) Folder layout

invoice-app/
├─ app/
│ ├─ auth.php # session + OTP utils
│ ├─ config.php # DB + app config (edit me)
│ ├─ csrf.php # CSRF helpers
│ ├─ db.php # PDO bootstrap
│ ├─ helpers.php # money(), totals, file uploads, etc
│ └─ middleware.php # require_login(), require_onboarded(), owner guard
├─ database/
│ └─ schema.sql # run this in MySQL Workbench
├─ public/ # web root (point your vhost here)
│ ├─ assets/
│ │ ├─ app.css
│ │ ├─ app.js
│ │ └─ uploads/ (logos) + index.php (403)
│ ├─ customers/ (CRUD)
│ ├─ invoices/ (create/list/view/delete/print)
│ ├─ payments/ (add/list)
│ ├─ products/ (CRUD)
│ ├─ settings/ (onboarding/profile)
│ ├─ .htaccess (optional)
│ ├─ index.php (dashboard) ← will send next
│ ├─ login.php, verify.php, logout.php
└─ README.md




## 3) Install & run (local)

1. Create DB & tables  
   - Open **MySQL Workbench** → run `database/schema.sql`.

2. Configure the app  
   - Open `app/config.php`:
     - Set DB credentials (`db.host`, `db.user`, `db.pass`).
     - Adjust `app.base_url` to match how you serve:  
       - If served at `http://localhost/invoice-app/public` → `'/invoice-app/public'`  
       - If the vhost root is `/public` → `'/'` (or empty string).
     - Set currency (default `LKR`).
     - For development, `security.dev_echo_otp = true` shows OTP on screen.

3. Point your web server to **`public/`**.  
   - Apache: enable `AllowOverride All` for `.htaccess` to work (optional).

4. Login (no password—OTP only):  
   - Visit `/login.php`, enter **email/phone/username**.  
   - If it’s a new identifier, a user is created.  
   - OTP is sent via stub; in dev you’ll see it on the page (since `dev_echo_otp=true`).  
   - Verify the code → you’ll be redirected to **Settings** (first-time onboarding).

5. Complete **Settings**: add display name, address, phone, and upload a logo (optional).

## 4) Routes Checklist (what URL does what)

- `/login.php` → Start OTP flow (CSRF-protected)
- `/verify.php` → Verify OTP + login (CSRF-protected)
- `/logout.php` → Destroy session
- `/settings/` → Onboarding + profile (address/phone/logo)
- `/customers/`
  - `index.php` → List
  - `create.php`, `edit.php`, `delete.php`
- `/products/`
  - `index.php` → List
  - `create.php`, `edit.php`, `delete.php`
- `/invoices/`
  - `create.php` → New invoice (discount + tax-inclusive option)
  - `list.php`   → History (status tabs + search)
  - `view.php`   → Details + change status + payments + **Print**
  - `print.php`  → Clean print view (opens in new tab)
  - `delete.php`
- `/payments/`
  - `create.php?invoice_id=...` → Add payment (recomputes balance/status)
  - `list.php` → All payments with date range/search
- `/index.php` → **Dashboard (links & quick stats)**  ← sending next

## 5) Security

- Sessions are configured for **24 hours** (`security.session_lifetime`).
- **CSRF**: enabled on OTP forms (`login.php`, `verify.php`).  
  Add `<?= csrf_field() ?>` to any form you later create, and call `require_csrf_token()` on POST.
- **Owner scope** checks to prevent cross-account access: `ensure_owner_scope(...)`.
- **Uploads**: logos go to `public/assets/uploads/`; listing is blocked by `index.php` (403).

## 6) Email/SMS OTP (production)

- Stubs are in `app/auth.php` → `send_otp_email()` and `send_otp_sms()`.
- Configure providers in `app/config.php` (`mail` and `sms` sections) and implement the real send.
- Turn **off** dev echo: `security.dev_echo_otp = false`.

## 7) Test Plan (happy path)

1. **Login & Onboarding**
   - Go to `/login.php` → enter new email (e.g., `me@example.com`) → OTP appears (dev).
   - Verify OTP → redirected to `/settings/`.
   - Fill display name, address, phone, upload a small PNG/JPG → Save → lands on dashboard.

2. **Customers**
   - `/customers/create.php` → add 2 customers (Active).
   - `/customers/index.php` → verify list, edit one, try deleting (works if no invoices).

3. **Products**
   - `/products/create.php` → add a few (with tax %).
   - `/products/index.php` → search by name/SKU, edit, delete one.

4. **Invoices**
   - `/invoices/create.php` → pick a customer, add lines (choose products or free text),
     set a discount and optionally “Prices include tax” → Save.
   - Redirect to `/invoices/view.php?id=...` → check items, totals, status timestamps.
   - Click **Print** → `/invoices/print.php?id=...` → print preview OK.

5. **Payments**
   - From invoice view, **Add Payment** (exact remaining balance) → invoice status flips to *completed*.
   - `/payments/list.php` → filter by date range, search by invoice no or method.

6. **History & Status**
   - `/invoices/list.php` → switch tabs (Pending/Completed/Cancelled), search by customer or invoice no.
   - Change status on an invoice and confirm “Status changed” timestamp updates.

## 8) Common Issues

- **Blank page / errors**: check PHP error log; make sure `pdo_mysql` is enabled.
- **Can’t connect to DB**: verify `app/config.php` → DB credentials and that schema was imported.
- **Assets 404**: confirm `app.config.app.base_url` and your server’s docroot point to `/public`.

---

Happy building!  
Next file to paste: **`public/index.php`** (dashboard with quick stats + shortcuts).
