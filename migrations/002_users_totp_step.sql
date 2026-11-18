-- TOTP replay protection: the last accepted 30-second step per user.
-- Re-runnable: the Migrator skips ADD COLUMN when the column already exists.
ALTER TABLE users ADD COLUMN totp_last_step INTEGER NULL;
