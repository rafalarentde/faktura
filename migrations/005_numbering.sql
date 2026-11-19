-- Document numbering (per company and document type; doc types: App\Domain\DocType).
-- number_formats: the editable pattern (App\Domain\NumberFormat) and the yearly-reset switch.
-- number_counters: one row per period bucket = the calendar year when the format resets yearly,
-- otherwise 0. last_value is the last issued counter; the next number is last_value + 1.
-- The unique indexes make "create the bucket row on first use" race-safe.

CREATE TABLE IF NOT EXISTS number_formats (
    id {AUTOID},
    company_id INTEGER NOT NULL,
    doc_type VARCHAR(16) NOT NULL,
    pattern VARCHAR(60) NOT NULL,
    reset_yearly INTEGER NOT NULL DEFAULT 1,
    updated_at BIGINT NOT NULL DEFAULT 0
);

CREATE INDEX number_formats_company_idx ON number_formats (company_id);

CREATE UNIQUE INDEX number_formats_type_uq ON number_formats (company_id, doc_type);

CREATE TABLE IF NOT EXISTS number_counters (
    id {AUTOID},
    company_id INTEGER NOT NULL,
    doc_type VARCHAR(16) NOT NULL,
    period INTEGER NOT NULL DEFAULT 0,
    last_value BIGINT NOT NULL DEFAULT 0
);

CREATE INDEX number_counters_company_idx ON number_counters (company_id);

CREATE UNIQUE INDEX number_counters_bucket_uq ON number_counters (company_id, doc_type, period);
