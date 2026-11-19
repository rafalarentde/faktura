-- Base schema: tenants (companies), users, auth helpers, audit log.
-- Portable DDL: {AUTOID} is replaced per driver. Money columns are integer minor units / basis points.
-- Convention: every *_minor (money) and *_milli (quantity) column and every unix-timestamp column is BIGINT (MySQL INTEGER is 32 bit); basis points and small counts may be INTEGER.
-- Re-runnable: CREATE TABLE IF NOT EXISTS; indexes are skipped by the Migrator on MySQL if present.
-- No foreign keys on purpose (SQLite does not enforce them by default; integrity is kept in the app).

CREATE TABLE IF NOT EXISTS companies (
    id {AUTOID},
    name VARCHAR(190) NOT NULL,
    address TEXT NULL,
    tax_number VARCHAR(64) NULL,
    vat_id VARCHAR(64) NULL,
    iban VARCHAR(64) NULL,
    bic VARCHAR(32) NULL,
    bank VARCHAR(190) NULL,
    logo_path VARCHAR(255) NULL,
    default_lang VARCHAR(5) NOT NULL DEFAULT 'de',
    default_currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
    default_payment_days INTEGER NOT NULL DEFAULT 14,
    intro_text TEXT NULL,
    outro_text TEXT NULL,
    footer_text TEXT NULL,
    smtp_host VARCHAR(190) NULL,
    smtp_port INTEGER NULL,
    smtp_user VARCHAR(190) NULL,
    smtp_pass_enc TEXT NULL,
    smtp_from_name VARCHAR(190) NULL,
    smtp_from_addr VARCHAR(190) NULL,
    dunning_d0 INTEGER NOT NULL DEFAULT 7,
    dunning_d1 INTEGER NOT NULL DEFAULT 21,
    dunning_d2 INTEGER NOT NULL DEFAULT 35,
    dunning_d3 INTEGER NOT NULL DEFAULT 49,
    dunning_base_rate_bp INTEGER NULL,
    dunning_b2b_surcharge_bp INTEGER NOT NULL DEFAULT 900,
    dunning_b2c_surcharge_bp INTEGER NOT NULL DEFAULT 500,
    dunning_flat_minor BIGINT NOT NULL DEFAULT 4000,
    dunning_fee_minor BIGINT NOT NULL DEFAULT 0,
    access_status VARCHAR(16) NOT NULL DEFAULT 'active',
    access_until DATE NULL,
    plan VARCHAR(32) NOT NULL DEFAULT 'free',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id {AUTOID},
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    totp_secret_enc TEXT NULL,
    totp_enabled INTEGER NOT NULL DEFAULT 0,
    ui_lang VARCHAR(5) NOT NULL DEFAULT 'de',
    is_admin INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX users_email_uq ON users (email);

CREATE TABLE IF NOT EXISTS backup_codes (
    id {AUTOID},
    user_id INTEGER NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    used_at DATETIME NULL
);

CREATE INDEX backup_codes_user_idx ON backup_codes (user_id);

CREATE TABLE IF NOT EXISTS company_users (
    company_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    role VARCHAR(16) NOT NULL DEFAULT 'user',
    PRIMARY KEY (company_id, user_id)
);

CREATE INDEX company_users_user_idx ON company_users (user_id);

CREATE TABLE IF NOT EXISTS login_attempts (
    id {AUTOID},
    `key` VARCHAR(190) NOT NULL,
    at BIGINT NOT NULL
);

CREATE INDEX login_attempts_key_idx ON login_attempts (`key`);

CREATE TABLE IF NOT EXISTS audit_log (
    id {AUTOID},
    company_id INTEGER NULL,
    user_id INTEGER NULL,
    action VARCHAR(64) NOT NULL,
    detail TEXT NULL,
    at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX audit_log_company_idx ON audit_log (company_id);
