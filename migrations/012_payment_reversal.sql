-- Payment reversal ("Storno"): a reversal is a NEGATIVE payments row that points at the payment it
-- cancels. Payments are never updated or deleted; every sum over payments (paid amount, balance,
-- status, dunning, dashboard) is the NET sum, so a reversal re-opens the invoice by itself.
-- The UNIQUE index lets each payment be reversed once (NULLs - ordinary payments - may repeat).
-- Re-runnable: the Migrator skips ADD COLUMN when the column already exists.
ALTER TABLE payments ADD COLUMN reverses_payment_id INTEGER NULL;

CREATE UNIQUE INDEX payments_reverses_uq ON payments (reverses_payment_id);
