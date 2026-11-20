-- Log of e-mails sent from the app (Task 18): one row per send ATTEMPT of an issued document,
-- status 'sent' or 'failed'. `error` holds only a short reason code (auth, connect, tls,
-- recipient, other), never server text or credentials.
-- The mail body is not stored. company_id scopes every access (EmailLogRepo, BaseRepo helpers).
-- created_at is UTC. sent_by is the user id of the sender (NULL if the user is gone).

CREATE TABLE IF NOT EXISTS email_log (
    id {AUTOID},
    company_id INTEGER NOT NULL,
    document_id INTEGER NOT NULL,
    to_email VARCHAR(254) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    status VARCHAR(10) NOT NULL,
    error VARCHAR(40) NULL,
    sent_by INTEGER NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX email_log_company_idx ON email_log (company_id);

CREATE INDEX email_log_document_idx ON email_log (document_id);
