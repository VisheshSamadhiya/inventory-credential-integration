-- Preserve existing rows while ensuring stock transaction price/vendor columns exist.
-- IF NOT EXISTS also supports databases that already received the order-details migration.
ALTER TABLE inventory_transactions
    ADD COLUMN IF NOT EXISTS unit_price DECIMAL(13,2) NULL AFTER reason,
    ADD COLUMN IF NOT EXISTS total_price DECIMAL(15,2) NULL AFTER unit_price,
    ADD COLUMN IF NOT EXISTS vendor VARCHAR(180) NULL AFTER total_price;
