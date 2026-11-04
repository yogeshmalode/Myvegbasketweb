-- Store Basket chat messages for customer support and conversation history.
-- Run once on each application database after migration_customers.sql.
CREATE TABLE IF NOT EXISTS ai_chat_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id INT DEFAULT NULL,
    conversation_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    role ENUM('user', 'assistant') NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_ai_chat_messages_customer_created (customer_id, created_at),
    KEY ix_ai_chat_messages_conversation (conversation_id, id),
    CONSTRAINT fk_ai_chat_messages_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
