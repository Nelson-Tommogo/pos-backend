<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE shops (
    id bigserial PRIMARY KEY,
    name varchar(160) NOT NULL,
    currency char(3) NOT NULL DEFAULT 'KES',
    tax_bps integer NOT NULL DEFAULT 0 CHECK (tax_bps BETWEEN 0 AND 10000),
    receipt jsonb NOT NULL DEFAULT '{}'
);
CREATE TABLE branches (
    id bigserial PRIMARY KEY,
    shop_id bigint NOT NULL REFERENCES shops,
    name varchar(160) NOT NULL,
    address varchar(255),
    UNIQUE (shop_id, id),
    UNIQUE (shop_id, name)
);
CREATE TABLE users (
    id bigserial PRIMARY KEY,
    shop_id bigint REFERENCES shops,
    branch_id bigint,
    name varchar(160) NOT NULL,
    email varchar(255) NOT NULL UNIQUE,
    password varchar(255) NOT NULL,
    role varchar(32) NOT NULL CHECK (role IN ('platform_admin','administrator','branch_manager','store_manager','cashier','teller','accountant','stock_clerk')),
    preferences jsonb NOT NULL DEFAULT '{}',
    remember_token varchar(100),
    created_at timestamptz,
    updated_at timestamptz,
    UNIQUE (shop_id, id),
    FOREIGN KEY (shop_id, branch_id) REFERENCES branches (shop_id, id),
    CHECK (
        (role = 'platform_admin' AND shop_id IS NULL AND branch_id IS NULL)
        OR (role = 'administrator' AND shop_id IS NOT NULL AND branch_id IS NULL)
        OR (role NOT IN ('platform_admin','administrator') AND shop_id IS NOT NULL AND branch_id IS NOT NULL)
    )
);
CREATE TABLE password_reset_tokens (
    email varchar(255) PRIMARY KEY,
    token varchar(255) NOT NULL,
    created_at timestamptz
);
CREATE TABLE personal_access_tokens (
    id bigserial PRIMARY KEY,
    tokenable_type varchar(255) NOT NULL,
    tokenable_id bigint NOT NULL,
    name text NOT NULL,
    token char(64) NOT NULL UNIQUE,
    abilities text,
    last_used_at timestamptz,
    expires_at timestamptz,
    created_at timestamptz,
    updated_at timestamptz
);
CREATE INDEX ON personal_access_tokens (tokenable_type, tokenable_id);
CREATE TABLE products (
    id bigserial PRIMARY KEY,
    shop_id bigint NOT NULL REFERENCES shops,
    name varchar(160) NOT NULL,
    sku varchar(80) NOT NULL,
    barcode varchar(120),
    category varchar(120),
    variant varchar(120),
    price_minor bigint NOT NULL CHECK (price_minor >= 0),
    cost_minor bigint NOT NULL CHECK (cost_minor >= 0),
    UNIQUE (shop_id, id),
    UNIQUE (shop_id, sku),
    UNIQUE (shop_id, barcode)
);
CREATE TABLE suppliers (
    id bigserial PRIMARY KEY,
    shop_id bigint NOT NULL REFERENCES shops,
    name varchar(160) NOT NULL,
    email varchar(255),
    phone varchar(60),
    address varchar(255),
    UNIQUE (shop_id, id)
);
CREATE TABLE customers (
    id bigserial PRIMARY KEY,
    shop_id bigint NOT NULL REFERENCES shops,
    name varchar(160) NOT NULL,
    email varchar(255),
    phone varchar(60),
    address varchar(255),
    credit_limit_minor bigint NOT NULL DEFAULT 0 CHECK (credit_limit_minor >= 0),
    balance_minor bigint NOT NULL DEFAULT 0 CHECK (balance_minor >= 0 AND balance_minor <= credit_limit_minor),
    UNIQUE (shop_id, id)
);
CREATE TABLE inventory (
    shop_id bigint NOT NULL REFERENCES shops,
    branch_id bigint NOT NULL,
    product_id bigint NOT NULL,
    quantity integer NOT NULL DEFAULT 0 CHECK (quantity >= 0),
    reorder_level integer NOT NULL DEFAULT 0 CHECK (reorder_level >= 0),
    PRIMARY KEY (shop_id, branch_id, product_id),
    FOREIGN KEY (shop_id, branch_id) REFERENCES branches (shop_id, id),
    FOREIGN KEY (shop_id, product_id) REFERENCES products (shop_id, id)
);
CREATE TABLE sales (
    id bigserial PRIMARY KEY,
    shop_id bigint NOT NULL REFERENCES shops,
    branch_id bigint NOT NULL,
    user_id bigint NOT NULL,
    customer_id bigint,
    customer jsonb,
    idempotency_key uuid NOT NULL,
    request_hash char(64) NOT NULL,
    currency char(3) NOT NULL,
    tax_bps integer NOT NULL,
    branding jsonb NOT NULL,
    subtotal_minor bigint NOT NULL,
    tax_minor bigint NOT NULL,
    total_minor bigint NOT NULL,
    items jsonb NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (shop_id, idempotency_key),
    UNIQUE (shop_id, branch_id, id),
    FOREIGN KEY (shop_id, user_id) REFERENCES users (shop_id, id),
    FOREIGN KEY (shop_id, branch_id) REFERENCES branches (shop_id, id),
    FOREIGN KEY (shop_id, customer_id) REFERENCES customers (shop_id, id)
);
CREATE INDEX ON sales (shop_id, branch_id, created_at);
CREATE TABLE purchases (
    id bigserial PRIMARY KEY,
    shop_id bigint NOT NULL REFERENCES shops,
    branch_id bigint NOT NULL,
    supplier_id bigint NOT NULL,
    items jsonb NOT NULL,
    received_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (shop_id, branch_id) REFERENCES branches (shop_id, id),
    FOREIGN KEY (shop_id, supplier_id) REFERENCES suppliers (shop_id, id)
);
CREATE TABLE stock_movements (
    id bigserial PRIMARY KEY,
    shop_id bigint NOT NULL,
    branch_id bigint NOT NULL,
    product_id bigint NOT NULL,
    user_id bigint NOT NULL,
    delta integer NOT NULL CHECK (delta <> 0),
    reason varchar(32) NOT NULL,
    reference_id bigint,
    note varchar(255),
    created_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (shop_id, user_id) REFERENCES users (shop_id, id),
    FOREIGN KEY (shop_id, branch_id, product_id) REFERENCES inventory (shop_id, branch_id, product_id)
);
CREATE INDEX ON stock_movements (shop_id, branch_id, id);
CREATE TABLE expenses (
    id bigserial PRIMARY KEY,
    shop_id bigint NOT NULL REFERENCES shops,
    branch_id bigint NOT NULL,
    description varchar(255) NOT NULL,
    amount_minor bigint NOT NULL CHECK (amount_minor > 0),
    incurred_on date NOT NULL,
    FOREIGN KEY (shop_id, branch_id) REFERENCES branches (shop_id, id)
);
CREATE TABLE payments (
    id bigserial PRIMARY KEY,
    shop_id bigint NOT NULL REFERENCES shops,
    branch_id bigint NOT NULL,
    user_id bigint NOT NULL,
    sale_id bigint,
    idempotency_key uuid,
    customer_id bigint,
    method varchar(20) NOT NULL CHECK (method IN ('cash','mpesa','card','bank_transfer','credit')),
    kind varchar(20) NOT NULL CHECK (kind IN ('sale','repayment','manual')),
    amount_minor bigint NOT NULL CHECK (amount_minor >= 0),
    reference varchar(160),
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (shop_id, idempotency_key),
    CHECK ((kind = 'sale') = (sale_id IS NOT NULL)),
    CHECK ((kind = 'sale') = (idempotency_key IS NULL)),
    CHECK (kind <> 'repayment' OR customer_id IS NOT NULL),
    FOREIGN KEY (shop_id, user_id) REFERENCES users (shop_id, id),
    FOREIGN KEY (shop_id, branch_id, sale_id) REFERENCES sales (shop_id, branch_id, id),
    FOREIGN KEY (shop_id, branch_id) REFERENCES branches (shop_id, id),
    FOREIGN KEY (shop_id, customer_id) REFERENCES customers (shop_id, id)
);
CREATE INDEX ON payments (shop_id, branch_id, id);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE payments, expenses, stock_movements, purchases, sales, inventory, customers, suppliers, products, personal_access_tokens, password_reset_tokens, users, branches, shops');
    }
};
