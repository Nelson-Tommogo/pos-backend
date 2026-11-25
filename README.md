# Northstar POS backend

Laravel 13 and PostgreSQL REST API for the Northstar Nuxt point-of-sale workspace. The API persists shops, users, catalog and directory records, stock, purchases, expenses, receipts, payments, and credit accounts. A super admin, represented by the API role `platform_admin`, provisions shops and their administrators.

See [POS architecture](docs/architecture.md) for domain boundaries, analytics definitions, and the roadmap for register shifts, returns, and provider integrations.

## Contents

- [Run locally](#run-locally)
- [First sale walkthrough](#first-sale-walkthrough)
- [Connect Nuxt](#connect-nuxt)
- [Roles and permissions](#roles-and-permissions)
- [API routes](#api-routes)
- [Analytics filters](#analytics-filters)
- [Bulk imports](#bulk-imports)
- [Audit trail](#audit-trail)
- [Checkout](#checkout)
- [Deploy and operate](#deploy-and-operate)
- [Troubleshooting](#troubleshooting)

## Run locally

Install PHP 8.4.1 or later with the Composer platform extensions, including `pdo_pgsql`, `simplexml`, and `zip`. Start PostgreSQL and create an empty database and an application login. For example, from a terminal where `psql -d postgres` connects as a PostgreSQL administrator:

```sql
CREATE ROLE northstar LOGIN;
\password northstar
CREATE DATABASE northstar OWNER northstar;
```

Run these in the backend directory. Preserve an existing `.env`.

```bash
composer install
composer check-platform-reqs
test -f .env || cp .env.example .env
```

Set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env`. Keep `DB_CONNECTION=pgsql`. Generate the application key once per environment. Then apply the schema and start the server.

```bash
php artisan key:generate  # Skip if APP_KEY is already set.
php artisan migrate
php artisan serve
```

Open `http://localhost:8000/up` to check that Laravel boots. Use `php artisan migrate:status` and a request to the API to verify the database connection.

In another terminal, bootstrap a super admin using one of these commands.

```bash
# Local or testing only. Password is password123.
php artisan pos:platform-admin alex@northstar.store --demo

# Regular account. The command prompts for a password.
php artisan pos:platform-admin you@example.com
```

Set `FRONTEND_URL` to the exact Nuxt origin, such as `http://localhost:3000`. It configures CORS and the destination of invitation and reset links. In local development, messages are written to the Laravel log. To deliver them, configure `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`, and `MAIL_FROM_ADDRESS`. Allow at least 5 MB for individual uploads in PHP and allow multipart overhead in the PHP and reverse proxy request size limits.

## First sale walkthrough

Run this in Bash with `curl` and `jq` against a local development database with the demo super admin. The sample creates a new shop and sample records each time.

```bash
set -euo pipefail

API=http://127.0.0.1:8000/api/v1
STAMP=$(date +%s)
ADMIN_EMAIL="admin-${STAMP}@example.com"
ADMIN_PASSWORD='LocalOnly-Demo-123!'
JSON=(-H 'Accept: application/json' -H 'Content-Type: application/json')

SUPER_TOKEN=$(curl -fsS "${JSON[@]}" "$API/auth/login" \
  -d '{"email":"alex@northstar.store","password":"password123"}' | jq -er '.token')

SHOP_ID=$(curl -fsS "${JSON[@]}" -H "Authorization: Bearer $SUPER_TOKEN" "$API/shops" \
  -d "$(jq -n --arg email "$ADMIN_EMAIL" --arg password "$ADMIN_PASSWORD" \
    '{shop_name:"Northstar Demo",name:"Shop Administrator",email:$email,password:$password}')" | jq -er '.shop_id')

ADMIN_TOKEN=$(curl -fsS "${JSON[@]}" "$API/auth/login" \
  -d "$(jq -n --arg email "$ADMIN_EMAIL" --arg password "$ADMIN_PASSWORD" \
    '{email:$email,password:$password}')" | jq -er '.token')
AUTH=(-H "Authorization: Bearer $ADMIN_TOKEN")

BRANCH_ID=$(curl -fsS "${JSON[@]}" "${AUTH[@]}" "$API/branches" \
  -d '{"name":"Main Branch"}' | jq -er '.id')
PRODUCT_ID=$(curl -fsS "${JSON[@]}" "${AUTH[@]}" "$API/products" \
  -d '{"name":"Tea","sku":"TEA-001","barcode":"0012345","price_minor":15000,"cost_minor":9000}' | jq -er '.id')

curl -fsS "${JSON[@]}" "${AUTH[@]}" "$API/inventory/adjustments" \
  -d "$(jq -n --argjson branch "$BRANCH_ID" --argjson product "$PRODUCT_ID" \
    '{branch_id:$branch,product_id:$product,delta:10,note:"Opening count"}')" | jq

CHECKOUT_KEY=$(php -r 'require "vendor/autoload.php"; echo Illuminate\Support\Str::uuid();')
SALE_ID=$(curl -fsS "${JSON[@]}" "${AUTH[@]}" "$API/sales/checkout" \
  -d "$(jq -n --arg key "$CHECKOUT_KEY" --argjson branch "$BRANCH_ID" --argjson product "$PRODUCT_ID" \
    '{idempotency_key:$key,branch_id:$branch,items:[{product_id:$product,quantity:2}],method:"cash"}')" | jq -er '.id')

curl -fsS "${JSON[@]}" "${AUTH[@]}" "$API/receipts/$SALE_ID" | jq
curl -fsS "${JSON[@]}" "${AUTH[@]}" "$API/analytics?branch_id=$BRANCH_ID" | jq
curl -fsS "${JSON[@]}" "${AUTH[@]}" "$API/audit-events?action=sale.checkout&subject_id=$SALE_ID" | jq

printf 'Shop %s, branch %s, receipt %s\n' "$SHOP_ID" "$BRANCH_ID" "$SALE_ID"
```

Expected results are 8 units remaining, a KES 300 receipt at the default zero tax rate, and one checkout audit event with the sale ID. Analytics reports KES 120 estimated gross profit for the sample sale. Use the returned IDs for subsequent requests.

## Connect Nuxt

Use `http://localhost:8000/api/v1` as the local API base. Send `Accept: application/json` and include `Authorization: Bearer <token>` on authenticated requests. JSON writes use `Content-Type: application/json`. The API returns JSON validation errors as `422` and uses `401` for missing or expired authentication, `403` for forbidden operations, and `404` for unavailable records. Most lists return 50 records per page in `data`, along with pagination metadata such as `current_page`, `last_page`, and `total`. The audit trail uses simple pagination with next and previous links.

1. Call `POST /auth/login` with `email` and `password`. The response includes `user` and a bearer token valid for 12 hours.
2. As the super admin, call `POST /shops` with `shop_name`, `name`, `email`, and `password`. To add an administrator to an existing shop, call `POST /shops/{shop}/administrators` with `name`, `email`, and `password`. The API assigns the `administrator` role from the route and uses the shop in the URL.
3. Sign in as that shop administrator, create a branch, and add staff. Use `GET /me` to initialize role-aware navigation. Shop and branch authorization are enforced by the API based on the authenticated user.
4. Use `POST /auth/forgot-password` with `email` and `POST /auth/reset-password` with `email`, `token`, `password`, and `password_confirmation`. The reset revokes active tokens. `POST /auth/logout` revokes the current token.

The Nuxt starter's local-storage workflows still require wiring to these endpoints. Camera scanning and printer dialogs stay in the browser. M-Pesa, card, and bank transfer are recorded as payment methods. Payment provider integration and printer hardware bridges are outside this implementation.

## Roles and permissions

| Role | Scope | Main responsibilities |
| --- | --- | --- |
| Super admin (`platform_admin`) | Platform | List and onboard shops, create shop administrators, and view platform-administrator audit activity. |
| Shop administrator (`administrator`) | Entire shop | Perform all shop operations across branches, manage roles and access, set credit limits and settings, and review shop audit history. |
| Branch manager (`branch_manager`) and store manager (`store_manager`) | Assigned branch | Checkout, stock operations, purchasing, branch staff visibility, finance, and analytics. |
| Cashier (`cashier`) and teller (`teller`) | Assigned branch | Checkout, sales and receipts, product lookup, and customer entry. |
| Accountant (`accountant`) | Assigned branch | Sales and receipts, purchase visibility, expenses, payments, and analytics. |
| Stock clerk (`stock_clerk`) | Assigned branch | Products, suppliers, inventory adjustments, and purchasing. |

Each non-administrator shop account requires one branch. Cross-branch transfers, credit limit changes, and credit repayments are shop administrator operations. Catalog and directory records are shared within the shop according to the applicable role permissions. Branch-scoped operations stay within the assigned branch.

## API routes

Routes below are relative to `/api/v1`.

| Area | Routes and input |
| --- | --- |
| Onboarding | `GET /shops`, `POST /shops`, and `POST /shops/{shop}/administrators` for super admins (`platform_admin`). |
| Staff | `GET /team[/{id}]`, `GET /users[/{id}]`, `POST /users`, `PATCH /users/{id}`. User fields are `name`, `email`, `password`, `role`, and `branch_id`. Use `POST /users/invitations` with the same fields except `password` to send a password-setup link. |
| Directories | `GET /{resource}[/{id}]`, `POST /{resource}`, `PATCH /{resource}/{id}` for `branches`, `products`, `customers`, `suppliers`, and `expenses`. Lists accept `q`. Products also accept exact `barcode` lookup. |
| Imports | `GET /{resource}/template`, `POST /{resource}/import` for `products`, `users`, `customers`, and `suppliers`. Upload multipart field `file` as CSV or XLSX. Import reads the first worksheet, with one header row and at most 500 records and 5 MB. The entire import rolls back on a row error. |
| Inventory | `GET /inventory` accepts `branch_id`, `product_id`, and `low_stock=1`. `GET /inventory/{branch}/{product}` reads a balance. `PUT` on that route sets `reorder_level`. |
| Movements | `GET /inventory/movements`. `POST /inventory/adjustments` takes `branch_id`, `product_id`, signed `delta`, and `note`. `POST /inventory/transfers` takes `from_branch_id`, `to_branch_id`, `product_id`, `quantity`, and optional `note`. |
| Purchases | `GET /purchases[/{id}]`, `POST /purchases`, `PATCH /purchases/{id}`. Send `branch_id`, `supplier_id`, and `items` with `product_id`, `quantity`, and `cost_minor`. `POST /purchases/{id}/receive` receives the full order and can be retried. Received orders are locked. |
| Sales and receipts | `POST /sales/checkout`, `GET /sales[/{id}]`, `GET /receipts[/{id}]`. Receipt details contain the item, customer, tax, branding, and payment data captured at checkout. |
| Credit and payments | `GET /credit-customers`, `POST /credit-customers/{customer}/repayments`, `GET /payments[/{id}]`, `POST /payments`. A repayment or manual payment takes `idempotency_key` (UUID), `branch_id`, `method`, `amount_minor`, and optional `reference`. Payments are append-only. |
| Analytics | `GET /analytics`, `GET /reports`, and `GET /dashboard` accept `from` and `to` as inclusive `YYYY-MM-DD` dates, optional `branch_id`, and `timezone`. The default is the past seven days including today in `Africa/Nairobi`. |
| Audit trail | `GET /audit-events` for shop and platform administrators. Filters include `from`, `to`, `actor_id`, `action`, `branch_id`, `subject_type`, and `subject_id`. |
| Settings | `GET /settings`, `PATCH /settings` for `name`, `currency`, `tax_bps`, and `receipt` fields `name`, `address`, `phone`, `footer`, and `accent`. `PATCH /preferences` accepts `theme`, `paper_mm` (58, 80, or 210), and `print_after_checkout`. |

A branch takes `name` and optional `address`. Products take `name`, `sku`, `price_minor`, `cost_minor`, and optional `barcode`, `category`, and `variant`. Suppliers and customers take `name` and optional `email`, `phone`, and `address`. Administrators can set `credit_limit_minor` on customers. Expenses take `branch_id`, `description`, `amount_minor`, and `incurred_on` as `YYYY-MM-DD`.

### Analytics filters

Use `GET /api/v1/analytics` for combined sales, purchases, and financial data.

| Filter | Behavior |
| --- | --- |
| `from`, `to` | Inclusive local dates in `YYYY-MM-DD` format. |
| `from_at`, `to_at` | Exact timestamps with seconds and a numeric UTC offset. The first is inclusive and the second is exclusive. Supply both for analytics and use them in place of `from` and `to`. |
| `timezone` | IANA timezone for daily buckets and local business dates. Defaults to `Africa/Nairobi`. |
| `branch_id` | Filters all sections to an authorized branch. |
| `salesperson_id`, `customer_id`, `product_id`, `payment_method` | Combine to filter completed receipts and their tenders. Product filtering selects whole receipts containing the product. Customer filtering also applies to credit repayments. |

`GET /sales` and `GET /receipts` list routes also support date or timestamp bounds, timezone, branch, salesperson, customer, product, and payment-method filters. Purchases and expenses follow the branch and reporting period. Expenses are stored by local date, including when the report uses exact timestamps. Inventory shows current branch balances.

```text
GET /api/v1/analytics?from=2026-11-01&to=2026-11-25&branch_id=1&salesperson_id=42&payment_method=mpesa
```

### Analytics response

The analytics routes return the sales totals, daily sales, payment mix, cashier totals, and inventory summaries. `financial_summary` adds pre-tax sales, tax, estimated cost, recorded expenses, credit sales, and credit repayments. `top_products` returns up to 20 product IDs ranked by pre-tax sales with units sold. `purchases` includes orders placed during the period, orders received during the period, and the total cost of received items in minor units. Receiving costs are tracked separately from the sale-time costs used to estimate gross profit. The date range filters financial activity. Stock summaries report current balances.

### Bulk imports

Download the matching template at `GET /api/v1/{resource}/template`. Upload UTF-8 CSV or XLSX using multipart field `file` at `POST /api/v1/{resource}/import`. Imports create records in the authenticated shop, with a 5 MB file limit, a 500-record limit, and validation before the transaction commits. Customer and user imports require a shop administrator. Supplier and product imports require stock-management permissions. Use audited stock, purchase, and checkout operations for changes that post financial or stock movements.

Using the API and authorization variables from the walkthrough:

```bash
curl -fsS "${AUTH[@]}" "$API/products/template" -o products-template.csv
curl -fsS -H 'Accept: application/json' "${AUTH[@]}" "$API/products/import" \
  -F 'file=@products.csv'
```

Save the populated template as `products.csv` before uploading. Keep identifiers such as SKUs and barcodes as text in spreadsheets so leading zeros survive. For XLSX, put the header and values on the first worksheet. Use invitation links for individual account setup.

### Audit trail

Run `php artisan migrate` to create the audit table. Audit events record successful sign-ins, sign-outs, password resets, shop onboarding, user changes, directory changes, imports, inventory operations, purchases, checkout, payments, repayments, and settings changes from that point forward. Business mutations and their audit entries commit together. Retrying a completed checkout, repayment, payment, or goods receipt leaves one event for the committed operation.

Shop administrators see events for their own shop. Platform administrators see platform-administrator activity, including onboarding, across shops. The API returns 50 newest-first events per page. Date filters apply to inclusive UTC dates. Each event records the actor, role, action, affected record and branch where applicable, timestamp, and limited operation details such as imported record IDs or stock movement IDs. Passwords, tokens, request bodies, and file contents are excluded. Failed requests and record reads are outside this business audit trail.

Example: `GET /api/v1/audit-events?action=sale.checkout&branch_id=1&from=2026-11-01`

The database blocks updates, deletes, and truncation of audit rows through ordinary SQL. Use a separate migration account and a non-owner application role in production so application credentials cannot disable the audit trigger or drop the table.

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

Use `cash`, `mpesa`, `card`, `bank_transfer`, or `credit`. Credit requires `customer_id`. Optional `customer` fields are `name`, `phone`, and `email`. Use `reference` for a payment reference. The backend calculates totals from the shop's prices and tax settings. It commits the sale, stock movements, credit balance, and payment together. An identical retry by the same user returns the existing sale or payment. Reusing the key for a different request returns `409`.

## Deploy and operate

1. Serve Laravel over HTTPS with the web root at `public/`. Set `APP_ENV=production`, `APP_DEBUG=false`, and the real `APP_URL` and `FRONTEND_URL`. Store `APP_KEY`, database credentials, and mail credentials in the environment's secret store.
2. Run `composer install --no-dev --optimize-autoloader` during the build. Run `php artisan migrate --force` with the schema migration account during deployment, then cache configuration and routes. Keep `storage/` and `bootstrap/cache/` writable by the runtime. Use the non-owner application role described in [Audit trail](#audit-trail) for API traffic.
3. Configure shared rate-limit storage for multiple API instances and set up SMTP for staff invitations and password resets. Verify the origin, invitation link, sign-in, and receipt printing on the intended register devices.
4. Back up PostgreSQL regularly and test restoring both business and audit tables. Monitor checkout errors, database lock waits, disk usage, and mail delivery. Confirm applicable fiscal invoicing requirements before going live. The current backend records tenders and prints receipt data through the frontend. Live settlement, returns, split tenders, register shifts, and eTIMS integration are covered in the [architecture roadmap](docs/architecture.md).

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Could not connect to PostgreSQL | Confirm the service is running and the host, port, database, username, and password agree with `.env`. Run `php artisan migrate:status`. Clear cached configuration with `php artisan config:clear` after changing local settings. |
| `401` on an authenticated request | Check the bearer header. Sign in again if the token expired, the user signed out, or their credential or access change revoked the token. |
| `403` or `404` on a shop operation | Confirm the role and assigned branch through `GET /me`. Use IDs from the authenticated shop. |
| `409` during checkout or repayment | Compare the submitted payload with the original request for that UUID. Reuse the UUID for an identical retry and create a new UUID for a new transaction. |
| `422` during checkout or import | Read the JSON `errors` object. Check stock and available credit for checkout, or compare upload headers and reported rows with the template. |
| `429` on repeated calls | Wait for the rate-limit window, using `Retry-After` when returned. Debounce dashboard filters and avoid parallel upload retries. |
| Browser blocks a request | Confirm `FRONTEND_URL` exactly matches the browser origin, including scheme and port. |
| Invitation or reset email is missing | Look in the local Laravel log when using the log mailer. With SMTP, verify transport configuration and sender delivery. |

Useful commands for checking the running version and routes:

```bash
php artisan about
php artisan route:list --path=api/v1
php artisan migrate:status
composer audit
```
