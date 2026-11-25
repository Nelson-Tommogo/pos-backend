# Northstar POS backend

Laravel 13 and PostgreSQL REST API for the Northstar Nuxt point-of-sale workspace. The API persists shops, users, catalog and directory records, stock, purchases, expenses, receipts, payments, and credit accounts. A platform administrator provisions the shop and its first administrator.

## Run locally

Install PHP 8.4.1 or later with the Composer platform extensions, including `pdo_pgsql`, `simplexml`, and `zip`. Start PostgreSQL and create an empty database and an application login. For example, from a terminal where `psql -d postgres` connects as a PostgreSQL administrator:

```sql
CREATE ROLE northstar LOGIN;
\password northstar
CREATE DATABASE northstar OWNER northstar;
```

Run the following in the backend directory. Set the connection credentials in `.env` before migrating.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan pos:platform-admin alex@northstar.store --demo
php artisan serve
```

The demo login is `alex@northstar.store` with password `password123` in local and testing environments. For a regular installation, run `php artisan pos:platform-admin you@example.com` and enter a password at the prompt. The health endpoint is `GET http://localhost:8000/up`. Business records start empty.

Set `FRONTEND_URL` to the Nuxt origin. It configures CORS and the link in email invitations and password resets. Local mail uses the log transport. For email delivery, set `MAIL_MAILER=smtp`, the `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`, and a valid `MAIL_FROM_ADDRESS`. Allow at least 5 MB for PHP file uploads and enough request size for multipart form data.

## Connect Nuxt

Use `http://localhost:8000/api/v1` as the local API base. Send `Accept: application/json` and include `Authorization: Bearer <token>` on authenticated requests. JSON writes use `Content-Type: application/json`. The API returns JSON validation errors as `422` and uses `401` for missing or expired authentication, `403` for forbidden operations, and `404` for unavailable records. Lists use Laravel's paginator with records in `data` and metadata that includes `current_page`, `last_page`, and `total`.

1. Call `POST /auth/login` with `email` and `password`. The response includes `user` and a bearer token valid for 12 hours.
2. As the platform administrator, call `POST /shops` with `shop_name`, `name`, `email`, and `password`.
3. Sign in as that shop administrator, create a branch, and add staff. Use `GET /me` to initialize role-aware navigation. Shop and branch authorization are enforced by the API based on the authenticated user.
4. Use `POST /auth/forgot-password` with `email` and `POST /auth/reset-password` with `email`, `token`, `password`, and `password_confirmation`. The reset revokes active tokens. `POST /auth/logout` revokes the current token.

The Nuxt starter's local-storage workflows still require wiring to these endpoints. Camera scanning and printer dialogs stay in the browser. M-Pesa, card, and bank transfer are recorded as payment methods. Payment provider integration and printer hardware bridges are outside this implementation.

## API routes

Routes below are relative to `/api/v1`.

| Area | Routes and input |
| --- | --- |
| Onboarding | `GET /shops`, `POST /shops` for platform administrators |
| Staff | `GET /team[/{id}]`, `GET /users[/{id}]`, `POST /users`, `PATCH /users/{id}`. User fields are `name`, `email`, `password`, `role`, and `branch_id`. Use `POST /users/invitations` with the same fields except `password` to send a password-setup link. |
| Directories | `GET /{resource}[/{id}]`, `POST /{resource}`, `PATCH /{resource}/{id}` for `branches`, `products`, `customers`, `suppliers`, and `expenses`. Lists accept `q`. Products also accept exact `barcode` lookup. |
| Imports | `GET /{resource}/template`, `POST /{resource}/import` for `products` and `users`. Upload multipart field `file` as CSV or XLSX. Import reads the first worksheet, with one header row and at most 500 records and 5 MB. The entire import rolls back on a row error. |
| Inventory | `GET /inventory` accepts `branch_id`, `product_id`, and `low_stock=1`. `GET /inventory/{branch}/{product}` reads a balance. `PUT` on that route sets `reorder_level`. |
| Movements | `GET /inventory/movements`. `POST /inventory/adjustments` takes `branch_id`, `product_id`, signed `delta`, and `note`. `POST /inventory/transfers` takes `from_branch_id`, `to_branch_id`, `product_id`, `quantity`, and optional `note`. |
| Purchases | `GET /purchases[/{id}]`, `POST /purchases`, `PATCH /purchases/{id}`. Send `branch_id`, `supplier_id`, and `items` with `product_id`, `quantity`, and `cost_minor`. `POST /purchases/{id}/receive` receives the full order and can be retried. Received orders are locked. |
| Sales and receipts | `POST /sales/checkout`, `GET /sales[/{id}]`, `GET /receipts[/{id}]`. Receipt details contain the item, customer, tax, branding, and payment data captured at checkout. |
| Credit and payments | `GET /credit-customers`, `POST /credit-customers/{customer}/repayments`, `GET /payments[/{id}]`, `POST /payments`. A repayment or manual payment takes `idempotency_key` (UUID), `branch_id`, `method`, `amount_minor`, and optional `reference`. Payments are append-only. |
| Analytics | `GET /reports` and `GET /dashboard` accept `from` and `to` as inclusive `YYYY-MM-DD` dates, optional `branch_id`, and `timezone`. The default is the past seven days including today in `Africa/Nairobi`. |
| Settings | `GET /settings`, `PATCH /settings` for `name`, `currency`, `tax_bps`, and `receipt` fields `name`, `address`, `phone`, `footer`, and `accent`. `PATCH /preferences` accepts `theme`, `paper_mm` (58, 80, or 210), and `print_after_checkout`. |

A branch takes `name` and optional `address`. Products take `name`, `sku`, `price_minor`, `cost_minor`, and optional `barcode`, `category`, and `variant`. Suppliers and customers take `name` and optional `email`, `phone`, and `address`. Administrators can set `credit_limit_minor` on customers. Expenses take `branch_id`, `description`, `amount_minor`, and `incurred_on` as `YYYY-MM-DD`.

Shop roles are `administrator`, `branch_manager`, `store_manager`, `cashier`, `teller`, `accountant`, and `stock_clerk`. Shop administrators have shop-wide access. Each other role requires one branch. Managers and stock clerks can operate inventory, and administrators initiate cross-branch transfers. Administrators, managers, cashiers, and tellers can check out sales. Administrators, managers, and accountants can access financial reports and the payment ledger.

## Checkout

Money fields ending in `_minor` are integer minor units. KES 125.50 is `12550`. The tax rate is stored in basis points, so 16% is `1600`. Currency defaults to KES and can be changed before monetary records are created.

Example `POST /api/v1/sales/checkout` body:

```json
{
  "idempotency_key": "47dc8275-a160-4cbe-a9ea-764d4ffa8420",
  "branch_id": 1,
  "items": [{"product_id": 1, "quantity": 2}],
  "method": "cash"
}
```

Use `cash`, `mpesa`, `card`, `bank_transfer`, or `credit`. Credit requires `customer_id`. Optional `customer` fields are `name`, `phone`, and `email`; `reference` can store a payment reference. The backend calculates totals from the shop's prices and tax settings. It commits the sale, stock movements, credit balance, and payment together. An identical retry by the same user returns the existing sale or payment. Reusing the key for a different request returns `409`.

For browser production deployments, serve the API over HTTPS and point the web server document root at `public/`. Configure a shared rate-limit cache for multiple application instances.
