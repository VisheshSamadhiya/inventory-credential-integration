-- Add purchase details to the existing transaction history table.
ALTER TABLE inventory_transactions
    ADD COLUMN unit_price DECIMAL(13,2) NULL AFTER reason,
    ADD COLUMN total_price DECIMAL(15,2) NULL AFTER unit_price,
    ADD COLUMN order_reason ENUM('Requested','New','Replacement') NULL AFTER total_price,
    ADD COLUMN vendor VARCHAR(180) NULL AFTER order_reason,
    ADD COLUMN expected_date DATE NULL AFTER vendor,
    ADD COLUMN status ENUM('Pending','Received') NULL AFTER expected_date,
    ADD COLUMN related_order_id BIGINT UNSIGNED NULL AFTER status,
    ADD INDEX idx_transaction_related_order (related_order_id),
    ADD CONSTRAINT fk_transaction_order FOREIGN KEY (related_order_id) REFERENCES inventory_transactions(id) ON DELETE SET NULL;
