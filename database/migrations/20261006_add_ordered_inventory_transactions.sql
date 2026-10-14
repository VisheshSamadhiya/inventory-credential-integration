-- Apply once to databases created before ordered quantities were tracked.
ALTER TABLE inventory_transactions
    MODIFY COLUMN transaction_type ENUM('opening','ordered','in','out','adjustment') NOT NULL;
