CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE TABLE IF NOT EXISTS users (
    id            BIGSERIAL PRIMARY KEY,
    username      VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          VARCHAR(20) NOT NULL DEFAULT 'user',
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS sessions (
    token      VARCHAR(255) PRIMARY KEY,
    username   VARCHAR(100) NOT NULL REFERENCES users(username) ON DELETE CASCADE,
    machine_id VARCHAR(100) NOT NULL UNIQUE,  -- satu session aktif per mesin
    expires_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS takeover_requests (
    id              BIGSERIAL PRIMARY KEY,
    username        VARCHAR(100) NOT NULL REFERENCES users(username) ON DELETE CASCADE,
    machine_id      VARCHAR(100) NOT NULL,
    requester_token VARCHAR(255) NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    timeout_count   INTEGER NOT NULL DEFAULT 0,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (username, machine_id, requester_token)  -- untuk ON CONFLICT di timeout handling
);

CREATE TABLE IF NOT EXISTS settings (
    key        VARCHAR(100) PRIMARY KEY,
    value      TEXT,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS suppliers (
    material_name VARCHAR(255) PRIMARY KEY,
    display_order INTEGER NOT NULL DEFAULT 0,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS material_suppliers (
    id            BIGSERIAL PRIMARY KEY,
    material_name VARCHAR(255) NOT NULL REFERENCES suppliers(material_name) ON DELETE CASCADE,
    supplier_name VARCHAR(255) NOT NULL,
    display_order INTEGER NOT NULL DEFAULT 0,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (material_name, supplier_name)
);

CREATE TABLE IF NOT EXISTS conversions (
    mid          VARCHAR(50) PRIMARY KEY,
    item_name    VARCHAR(255) NOT NULL,
    cat_bag      VARCHAR(255) NOT NULL DEFAULT '',
    cat_box      VARCHAR(255) NOT NULL DEFAULT '',
    ratio        NUMERIC(12,4) NOT NULL DEFAULT 0,
    weight_grams NUMERIC(12,4) NOT NULL DEFAULT 0,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS transactions (
    id             BIGSERIAL PRIMARY KEY,
    uuid           UUID NOT NULL,
    production_date DATE NOT NULL,
    shift_id       SMALLINT NOT NULL,
    machine_id     VARCHAR(100) NOT NULL,
    product_size   VARCHAR(100) NOT NULL DEFAULT '',
    revision_count INTEGER NOT NULL DEFAULT 0,
    created_by     VARCHAR(100) NOT NULL DEFAULT '',
    status         VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (uuid, revision_count)
);

CREATE TABLE IF NOT EXISTS transaction_materials (
    id                BIGSERIAL PRIMARY KEY,
    transaction_uuid  UUID NOT NULL,
    transaction_id    BIGINT NOT NULL REFERENCES transactions(id) ON DELETE CASCADE,
    material_name     VARCHAR(255) NOT NULL,
    supplier_name     VARCHAR(255) NOT NULL DEFAULT '',
    stock_initial     NUMERIC(14,4) NOT NULL DEFAULT 0,
    stock_in          NUMERIC(14,4) NOT NULL DEFAULT 0,
    stock_return      NUMERIC(14,4) NOT NULL DEFAULT 0,
    reject_amount     NUMERIC(14,4) NOT NULL DEFAULT 0,
    production_amount NUMERIC(14,4) NOT NULL DEFAULT 0,
    total_usage       NUMERIC(14,4) NOT NULL DEFAULT 0,
    stock_final       NUMERIC(14,4) NOT NULL DEFAULT 0,
    label_photos      TEXT NOT NULL DEFAULT '',
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS material_hourly_usage (
    id                      BIGSERIAL PRIMARY KEY,
    transaction_material_id BIGINT NOT NULL REFERENCES transaction_materials(id) ON DELETE CASCADE,
    hour_idx                SMALLINT NOT NULL,
    amount                  NUMERIC(14,4) NOT NULL DEFAULT 0,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (transaction_material_id, hour_idx)
);

CREATE TABLE IF NOT EXISTS production_outputs (
    id               BIGSERIAL PRIMARY KEY,
    transaction_uuid UUID NOT NULL,
    production_date  DATE NOT NULL,
    mid              VARCHAR(50) NOT NULL,
    product_name     VARCHAR(255) NOT NULL DEFAULT '',
    qty_box          INTEGER NOT NULL DEFAULT 0,
    category_bag     VARCHAR(255) NOT NULL DEFAULT '',
    category_box     VARCHAR(255) NOT NULL DEFAULT '',
    counter_pcs      BIGINT NOT NULL DEFAULT 0,
    total_weight_kg  NUMERIC(14,4) NOT NULL DEFAULT 0,
    loss_kg          NUMERIC(14,4) NOT NULL DEFAULT 0,
    revision_count   INTEGER NOT NULL DEFAULT 0,
    status           VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS production_reports (
    id                 BIGSERIAL PRIMARY KEY,
    transaction_uuid   UUID NOT NULL,
    production_date    DATE NOT NULL,
    shift_id           SMALLINT NOT NULL,
    machine_id         VARCHAR(100) NOT NULL,
    total_output_kg    NUMERIC(14,4) NOT NULL DEFAULT 0,
    total_loss_kg      NUMERIC(14,4) NOT NULL DEFAULT 0,
    avg_loss_pct       NUMERIC(10,4) NOT NULL DEFAULT 0,
    downtime_min       INTEGER NOT NULL DEFAULT 0,
    downtime_pct       NUMERIC(10,4) NOT NULL DEFAULT 0,
    speed_ppm          INTEGER NOT NULL DEFAULT 0,
    trouble_logs       TEXT NOT NULL DEFAULT '',
    near_miss          TEXT NOT NULL DEFAULT '',
    notes              TEXT NOT NULL DEFAULT '',
    reject_printing_kg NUMERIC(14,4) NOT NULL DEFAULT 0,
    revision_count     INTEGER NOT NULL DEFAULT 0,
    status             VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id         BIGSERIAL PRIMARY KEY,
    actor      VARCHAR(100) NOT NULL DEFAULT '',
    action     VARCHAR(50) NOT NULL DEFAULT '',
    details    TEXT NOT NULL DEFAULT '',
    timestamp  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS photo_migration (
    id         BIGSERIAL PRIMARY KEY,
    gdrive_id  VARCHAR(200) NOT NULL UNIQUE,
    local_path VARCHAR(500),
    mime_type  VARCHAR(100),
    file_size  BIGINT,
    status     VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    attempts   SMALLINT NOT NULL DEFAULT 0,
    error_msg  TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS photo_migration_map (
    id         BIGSERIAL PRIMARY KEY,
    tm_id      BIGINT NOT NULL REFERENCES transaction_materials(id) ON DELETE CASCADE,
    gdrive_id  VARCHAR(200) NOT NULL,
    position   SMALLINT NOT NULL DEFAULT 0,
    UNIQUE (tm_id, gdrive_id, position)
);

CREATE INDEX IF NOT EXISTS idx_sessions_user_machine ON sessions(username, machine_id);
CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at);
CREATE INDEX IF NOT EXISTS idx_takeover_user_machine ON takeover_requests(username, machine_id, status);
CREATE INDEX IF NOT EXISTS idx_transactions_uuid ON transactions(uuid);
CREATE INDEX IF NOT EXISTS idx_transactions_date_status ON transactions(production_date, status);
CREATE INDEX IF NOT EXISTS idx_transactions_machine_shift_date ON transactions(machine_id, shift_id, production_date);
CREATE INDEX IF NOT EXISTS idx_tm_transaction_id ON transaction_materials(transaction_id);
CREATE INDEX IF NOT EXISTS idx_tm_transaction_uuid ON transaction_materials(transaction_uuid);
CREATE INDEX IF NOT EXISTS idx_tm_material ON transaction_materials(material_name);
CREATE INDEX IF NOT EXISTS idx_mhu_tm_hour ON material_hourly_usage(transaction_material_id, hour_idx);
CREATE INDEX IF NOT EXISTS idx_outputs_uuid_status ON production_outputs(transaction_uuid, status);
CREATE INDEX IF NOT EXISTS idx_reports_uuid_status ON production_reports(transaction_uuid, status);
CREATE INDEX IF NOT EXISTS idx_audit_logs_timestamp ON audit_logs(timestamp DESC);
CREATE INDEX IF NOT EXISTS idx_pm_status ON photo_migration(status);
CREATE INDEX IF NOT EXISTS idx_pm_gdrive ON photo_migration(gdrive_id);
CREATE INDEX IF NOT EXISTS idx_pmm_tm ON photo_migration_map(tm_id);
CREATE INDEX IF NOT EXISTS idx_pmm_gdid ON photo_migration_map(gdrive_id);

INSERT INTO settings (key, value) VALUES
    ('LIST_MESIN', 'Mesin BHP 1,Mesin BHP 2,Mesin BHP 3,Mesin AHP 1,Mesin BHP 4,Mesin BHP 5,Mesin NAP 1,Mesin PAN 1'),
    ('LIST_SIZE', 'Size S,Size M,Size L,Size XL,Size XXL'),
    ('LIST_ALIASES', '{}'),
    ('ADMIN_PIN', 'DAM!@#123'),
    ('ENABLE_HANDOVER', 'TRUE'),
    ('LOCK_DATE', ''),
    ('BROADCAST_MSG', ''),
    ('BROADCAST_ACTIVE', 'FALSE')
ON CONFLICT (key) DO NOTHING;
