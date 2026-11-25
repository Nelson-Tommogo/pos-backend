<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE audit_events (
    id bigserial PRIMARY KEY,
    shop_id bigint REFERENCES shops,
    actor_id bigint NOT NULL REFERENCES users,
    actor_role varchar(32) NOT NULL,
    action varchar(100) NOT NULL,
    subject_type varchar(40),
    subject_id bigint,
    branch_id bigint,
    details jsonb NOT NULL DEFAULT '{}',
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    FOREIGN KEY (shop_id, branch_id) REFERENCES branches (shop_id, id)
);
CREATE INDEX audit_events_shop_time_idx ON audit_events (shop_id, created_at DESC, id DESC);

CREATE FUNCTION reject_audit_event_changes() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Audit events are append-only';
END;
$$;
CREATE TRIGGER audit_events_append_only
    BEFORE UPDATE OR DELETE OR TRUNCATE ON audit_events
    FOR EACH STATEMENT EXECUTE FUNCTION reject_audit_event_changes();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE audit_events; DROP FUNCTION reject_audit_event_changes()');
    }
};
