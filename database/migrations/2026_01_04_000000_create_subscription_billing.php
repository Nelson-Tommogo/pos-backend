<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE subscription_plans (
    id bigserial PRIMARY KEY,
    name varchar(120) NOT NULL,
    interval_months integer NOT NULL CHECK (interval_months BETWEEN 1 AND 36),
    amount_minor bigint NOT NULL CHECK (amount_minor BETWEEN 100 AND 100000000 AND amount_minor % 100 = 0),
    active boolean NOT NULL DEFAULT true
);
CREATE TABLE subscription_invoices (
    id bigserial PRIMARY KEY,
    shop_id bigint NOT NULL REFERENCES shops,
    plan_id bigint NOT NULL REFERENCES subscription_plans,
    plan_name varchar(120) NOT NULL,
    interval_months integer NOT NULL CHECK (interval_months BETWEEN 1 AND 36),
    amount_minor bigint NOT NULL CHECK (amount_minor > 0 AND amount_minor % 100 = 0),
    request_key uuid NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    paid_at timestamptz,
    cancelled_at timestamptz,
    CHECK (paid_at IS NULL OR cancelled_at IS NULL),
    UNIQUE (shop_id, request_key),
    UNIQUE (shop_id, id)
);
CREATE UNIQUE INDEX subscription_invoices_open_shop_idx ON subscription_invoices (shop_id) WHERE paid_at IS NULL AND cancelled_at IS NULL;
CREATE TABLE shop_subscriptions (
    shop_id bigint PRIMARY KEY REFERENCES shops,
    plan_id bigint NOT NULL REFERENCES subscription_plans,
    covered_from timestamptz NOT NULL,
    covered_until timestamptz NOT NULL CHECK (covered_until > covered_from),
    last_invoice_id bigint NOT NULL,
    FOREIGN KEY (shop_id, last_invoice_id) REFERENCES subscription_invoices (shop_id, id)
);
CREATE TABLE subscription_payment_attempts (
    id bigserial PRIMARY KEY,
    shop_id bigint NOT NULL,
    invoice_id bigint NOT NULL,
    request_key uuid NOT NULL,
    phone varchar(12) NOT NULL,
    state varchar(16) NOT NULL CHECK (state IN ('starting','pending','review','failed','confirmed')),
    merchant_request_id varchar(120),
    checkout_request_id varchar(120) UNIQUE,
    result_code varchar(24),
    created_at timestamptz NOT NULL DEFAULT now(),
    checked_at timestamptz,
    confirmed_at timestamptz,
    UNIQUE (shop_id, request_key),
    FOREIGN KEY (shop_id, invoice_id) REFERENCES subscription_invoices (shop_id, id)
);
CREATE UNIQUE INDEX subscription_attempts_active_invoice_idx ON subscription_payment_attempts (invoice_id)
    WHERE state IN ('starting','pending','review','confirmed');
CREATE INDEX subscription_attempts_pending_idx ON subscription_payment_attempts (checked_at, id) WHERE state = 'pending';
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE subscription_payment_attempts, shop_subscriptions, subscription_invoices, subscription_plans');
    }
};
