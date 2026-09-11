-- Batch 5: Razorpay customer IDs, corporate invoice delivery tracking

BEGIN;

ALTER TABLE users ADD COLUMN IF NOT EXISTS razorpay_customer_id VARCHAR(100);
CREATE INDEX IF NOT EXISTS idx_users_razorpay_customer ON users (razorpay_customer_id)
  WHERE razorpay_customer_id IS NOT NULL;

ALTER TABLE corporate_invoices ADD COLUMN IF NOT EXISTS email_sent_at TIMESTAMPTZ;

COMMIT;
