-- Record the rider's explicit acceptance before they confirm physical pickup.
SET @has_rider_accepted_at = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'rider_accepted_at'
);
SET @rider_acceptance_ddl = IF(
    @has_rider_accepted_at = 0,
    'ALTER TABLE orders ADD COLUMN rider_accepted_at TIMESTAMP NULL DEFAULT NULL',
    'SELECT 1'
);
PREPARE rider_acceptance_stmt FROM @rider_acceptance_ddl;
EXECUTE rider_acceptance_stmt;
DEALLOCATE PREPARE rider_acceptance_stmt;
