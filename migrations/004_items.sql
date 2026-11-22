-- Items and services catalogue (per company). Never deleted in v1; is_active hides an item from
-- the "pick from catalogue" lists. unit is a key of App\Domain\Units::KEYS, price_minor is in
-- minor units (cents/grosze), vat_bp in basis points (1900, 700, 0).
-- item_names holds one row per language (de|pl|en); company_id is on both tables.

CREATE TABLE IF NOT EXISTS items (
    id {AUTOID},
    company_id INTEGER NOT NULL,
    type VARCHAR(16) NOT NULL DEFAULT 'service',
    unit VARCHAR(16) NOT NULL DEFAULT 'hour',
    price_minor BIGINT NOT NULL DEFAULT 0,
    currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
    vat_bp INTEGER NOT NULL DEFAULT 1900,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX items_company_idx ON items (company_id);

CREATE TABLE IF NOT EXISTS item_names (
    id {AUTOID},
    company_id INTEGER NOT NULL,
    item_id INTEGER NOT NULL,
    lang VARCHAR(5) NOT NULL,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL
);

CREATE INDEX item_names_company_idx ON item_names (company_id);

CREATE INDEX item_names_item_idx ON item_names (item_id);

CREATE UNIQUE INDEX item_names_item_lang_uq ON item_names (item_id, lang);
