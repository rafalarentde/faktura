-- Customers (per company). No deletion in v1: documents keep their own snapshots.
-- vies_status: 'valid' | 'invalid' | 'unavailable', NULL = never checked. vies_checked_at is UTC.

CREATE TABLE IF NOT EXISTS customers (
    id {AUTOID},
    company_id INTEGER NOT NULL,
    name VARCHAR(190) NOT NULL,
    address TEXT NULL,
    country CHAR(2) NOT NULL DEFAULT 'DE',
    is_company INTEGER NOT NULL DEFAULT 1,
    vat_id VARCHAR(32) NULL,
    email VARCHAR(190) NULL,
    default_lang VARCHAR(5) NOT NULL DEFAULT 'de',
    default_currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
    payment_days INTEGER NOT NULL DEFAULT 14,
    notes TEXT NULL,
    vies_status VARCHAR(16) NULL,
    vies_checked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX customers_company_idx ON customers (company_id);
