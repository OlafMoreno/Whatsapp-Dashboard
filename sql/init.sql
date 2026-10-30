CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(64) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(191) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source ENUM('whatsapp', 'email') NOT NULL DEFAULT 'whatsapp',
    
    wa_message_id VARCHAR(191) NULL,
    from_number VARCHAR(32) NULL,
    from_name VARCHAR(191) NULL,
    
    email_message_id VARCHAR(191) NULL,
    sender_email VARCHAR(191) NULL,
    subject VARCHAR(255) NULL,
    
    type VARCHAR(32) NOT NULL DEFAULT 'text',
    body TEXT NOT NULL,
    received_at DATETIME(3) NOT NULL,
    
    done TINYINT(1) NOT NULL DEFAULT 0,
    done_at DATETIME(3) NULL,
    done_by VARCHAR(191) NULL,
    done_by_user_id INT UNSIGNED NULL,
    
    PRIMARY KEY (id),
    UNIQUE KEY uq_tasks_wa_message_id (wa_message_id),
    UNIQUE KEY uq_tasks_email_message_id (email_message_id),
    KEY idx_tasks_source (source),
    KEY idx_tasks_status_received (done, received_at),
    KEY idx_tasks_received_at (received_at),
    CONSTRAINT fk_tasks_done_by_user FOREIGN KEY (done_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(32) NOT NULL,
    data LONGTEXT NOT NULL,
    created_at BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_task_events_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usuario inicial: tv / 123456
INSERT INTO users (username, password_hash, name) 
VALUES 
    ('tv', '$2y$10$P51RUTOIlH9012rH3YBa9OYEHUFD/op3jgEsIbm2waAWI7o5IsGXS', 'TV'),
    ('david', '$2y$10$P51RUTOIlH9012rH3YBa9OYEHUFD/op3jgEsIbm2waAWI7o5IsGXS', 'David'),
    ('olaf', '$2y$10$P51RUTOIlH9012rH3YBa9OYEHUFD/op3jgEsIbm2waAWI7o5IsGXS', 'Olaf'),
    ('jota', '$2y$10$P51RUTOIlH9012rH3YBa9OYEHUFD/op3jgEsIbm2waAWI7o5IsGXS', 'Jota'),
    ('adrian', '$2y$10$P51RUTOIlH9012rH3YBa9OYEHUFD/op3jgEsIbm2waAWI7o5IsGXS', 'Adrian'),
    ('alejandro', '$2y$10$P51RUTOIlH9012rH3YBa9OYEHUFD/op3jgEsIbm2waAWI7o5IsGXS', 'Alejandro'),
    ('jorgea', '$2y$10$P51RUTOIlH9012rH3YBa9OYEHUFD/op3jgEsIbm2waAWI7o5IsGXS', 'JorgeA'),
    ('jorgee', '$2y$10$P51RUTOIlH9012rH3YBa9OYEHUFD/op3jgEsIbm2waAWI7o5IsGXS', 'JorgeE')
ON DUPLICATE KEY UPDATE 
    password_hash = VALUES(password_hash),
    name = VALUES(name);