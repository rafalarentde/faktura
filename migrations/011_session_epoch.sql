-- Session invalidation: users.session_epoch is copied into the session at login and compared on every
-- request; it is incremented on password change, 2FA enable/reset, backup-code regeneration and an admin
-- temporary password, which ends all other sessions of that user.
-- Re-runnable: the Migrator skips ADD COLUMN when the column already exists.
ALTER TABLE users ADD COLUMN session_epoch INTEGER NOT NULL DEFAULT 0;

-- Set by an admin's temporary password: RequireLogin keeps the user on the security page until the password is changed.
ALTER TABLE users ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 0;
