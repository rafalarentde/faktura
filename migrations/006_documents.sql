-- Documents (invoices, credit notes, offers; dunning letters later), their lines and payments.
-- All three tables carry company_id and are reached only through the BaseRepo scoped helpers.
-- type: 'invoice' | 'credit_note' | 'offer' | 'dunning'; status: 'draft' | 'issued'.
-- Drafts have no number (NULL); NULL numbers may repeat under the unique index on both drivers.
-- Once issued a document row, its lines and its payments' amounts are never changed again.
-- Totals are stored with the document's sign: positive for invoices/offers, NEGATIVE for
-- credit notes. Lines are always positive. Dates are Europe/Berlin calendar dates (DATE).
-- seller_json / buyer_json are the snapshots taken at issue (a credit note copies the buyer of
-- its invoice). buyer_name is a denormalised copy of the buyer's name for list search.
-- vat_reason is the VatDecision reason of the latest save (needs confirmation = vies_not_confirmed).

CREATE TABLE IF NOT EXISTS documents (
    id {AUTOID},
    company_id INTEGER NOT NULL,
    type VARCHAR(16) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'draft',
    number VARCHAR(40) NULL,
    related_document_id INTEGER NULL,
    customer_id INTEGER NULL,
    lang VARCHAR(5) NOT NULL DEFAULT 'de',
    currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
    eur_rate VARCHAR(20) NULL,
    reverse_charge INTEGER NOT NULL DEFAULT 0,
    vat_note_key VARCHAR(80) NULL,
    vat_reason VARCHAR(40) NULL,
    issue_date DATE NULL,
    service_date DATE NULL,
    due_date DATE NULL,
    valid_until DATE NULL,
    buyer_name VARCHAR(190) NULL,
    seller_json TEXT NULL,
    buyer_json TEXT NULL,
    intro_text TEXT NULL,
    outro_text TEXT NULL,
    notes TEXT NULL,
    net_minor BIGINT NOT NULL DEFAULT 0,
    vat_minor BIGINT NOT NULL DEFAULT 0,
    gross_minor BIGINT NOT NULL DEFAULT 0,
    vat_eur_minor BIGINT NULL,
    issued_at DATETIME NULL,
    created_by INTEGER NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX documents_company_idx ON documents (company_id);

CREATE UNIQUE INDEX documents_number_uq ON documents (company_id, type, number);

CREATE INDEX documents_type_status_idx ON documents (company_id, type, status);

CREATE INDEX documents_customer_idx ON documents (company_id, customer_id);

CREATE INDEX documents_related_idx ON documents (company_id, related_document_id);

CREATE INDEX documents_due_idx ON documents (company_id, due_date);

CREATE TABLE IF NOT EXISTS document_lines (
    id {AUTOID},
    company_id INTEGER NOT NULL,
    document_id INTEGER NOT NULL,
    position INTEGER NOT NULL DEFAULT 0,
    item_id INTEGER NULL,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    unit VARCHAR(20) NOT NULL,
    qty_milli BIGINT NOT NULL,
    unit_minor BIGINT NOT NULL,
    discount_bp INTEGER NOT NULL DEFAULT 0,
    vat_bp INTEGER NOT NULL,
    net_minor BIGINT NOT NULL DEFAULT 0
);

CREATE INDEX document_lines_company_idx ON document_lines (company_id);

CREATE INDEX document_lines_document_idx ON document_lines (document_id);

CREATE TABLE IF NOT EXISTS payments (
    id {AUTOID},
    company_id INTEGER NOT NULL,
    document_id INTEGER NOT NULL,
    amount_minor BIGINT NOT NULL,
    paid_on DATE NOT NULL,
    note VARCHAR(190) NULL,
    created_by INTEGER NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX payments_company_idx ON payments (company_id);

CREATE INDEX payments_document_idx ON payments (document_id);
