-- Offers (Task 19): the decision status of an ISSUED offer. NULL for every other type and for
-- offer drafts; 'open' is set at issue, then 'sent' | 'accepted' | 'rejected' (and back to 'open').
-- 'expired' is never stored: it is computed from valid_until (DocumentService::offerStatus).
-- Re-runnable: the Migrator skips ADD COLUMN when the column already exists.
ALTER TABLE documents ADD COLUMN offer_status VARCHAR(10) NULL;
