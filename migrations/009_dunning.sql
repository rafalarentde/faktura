-- Dunning letters (Task 21): a document of type 'dunning' is ISSUED when it is created (number
-- from the 'dunning' sequence, snapshots, immutable). Its net/vat/gross stay 0 so no revenue or
-- open-amount report can ever count it; the claim lives in these dedicated columns instead.
-- related_document_id = the dunned invoice (ALWAYS filter by type when following that link).
--   dunning_level             0 = payment reminder, 1 = 1st, 2 = 2nd, 3 = final (DunningLevel)
--   dunning_outstanding_minor outstanding balance of the invoice at creation (gross - payments - issued credit notes)
--   dunning_interest_minor    default interest; NULL = not charged
--   dunning_interest_days     days overdue the interest was computed for (NULL = not charged)
--   dunning_interest_rate_bp  the annual rate used (base rate + surcharge), NULL = not charged
--   dunning_flat_fee_minor    statutory flat fee (§ 288 Abs. 5 BGB); NULL = not charged
--   dunning_fee_minor         per-dunning fee; NULL = not charged
--   dunning_claim_minor       outstanding + every charged extra
--   dunning_pay_by            issue date + 7 days
-- Re-runnable: the Migrator skips ADD COLUMN when the column already exists.
ALTER TABLE documents ADD COLUMN dunning_level INTEGER NULL;

ALTER TABLE documents ADD COLUMN dunning_outstanding_minor BIGINT NULL;

ALTER TABLE documents ADD COLUMN dunning_interest_minor BIGINT NULL;

ALTER TABLE documents ADD COLUMN dunning_interest_days INTEGER NULL;

ALTER TABLE documents ADD COLUMN dunning_interest_rate_bp INTEGER NULL;

ALTER TABLE documents ADD COLUMN dunning_flat_fee_minor BIGINT NULL;

ALTER TABLE documents ADD COLUMN dunning_fee_minor BIGINT NULL;

ALTER TABLE documents ADD COLUMN dunning_claim_minor BIGINT NULL;

ALTER TABLE documents ADD COLUMN dunning_pay_by DATE NULL;
