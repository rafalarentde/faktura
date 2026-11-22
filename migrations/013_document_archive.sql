-- GoBD archive of issued documents: the PDF bytes as first rendered are kept under
-- <storage>/archive/<company_id>/<document_id>.pdf (outside the web root, 0600/0700) and
-- served for every later download, e-mail attachment and export. This table records, per document,
-- the SHA-256 of those bytes; the UNIQUE index on document_id makes the archive write-once
-- (a second archiving of the same document is refused). company_id scopes every access.
-- email_log.pdf_sha256: checksum of the PDF that was attached to the e-mail.
-- Re-runnable: the Migrator skips ADD COLUMN when the column already exists.
CREATE TABLE IF NOT EXISTS document_archives (
    id {AUTOID},
    company_id INTEGER NOT NULL,
    document_id INTEGER NOT NULL,
    sha256 VARCHAR(64) NOT NULL,
    size_bytes BIGINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX document_archives_document_uq ON document_archives (document_id);

CREATE INDEX document_archives_company_idx ON document_archives (company_id);

ALTER TABLE email_log ADD COLUMN pdf_sha256 VARCHAR(64) NULL;
