-- Audit log viewer (Task 23): newest-first listing per company and filtering by action prefix.
-- Re-runnable: the Migrator skips CREATE INDEX on MySQL when the index already exists.
CREATE INDEX audit_log_company_at_idx ON audit_log (company_id, at);

CREATE INDEX audit_log_action_idx ON audit_log (action);
