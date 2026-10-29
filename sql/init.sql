CREATE TABLE IF NOT EXISTS tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    wa_message_id VARCHAR(191) NULL,
    from_number VARCHAR(32) NOT NULL,
    from_name VARCHAR(191) NULL,
    type VARCHAR(32) NOT NULL,
    body TEXT NOT NULL,
    received_at DATETIME(3) NOT NULL,
    done TINYINT(1) NOT NULL DEFAULT 0,
    done_at DATETIME(3) NULL,
    done_by VARCHAR(191) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tasks_wa_message_id (wa_message_id),
    KEY idx_tasks_status_received (done, received_at),
    KEY idx_tasks_received_at (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(32) NOT NULL,
    data LONGTEXT NOT NULL,
    created_at BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_task_events_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
