CREATE TABLE IF NOT EXISTS battery_tests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    test_uuid CHAR(36) NOT NULL,

    label VARCHAR(255) DEFAULT NULL,
    status ENUM('running', 'stopped', 'finished') NOT NULL DEFAULT 'running',

    client_ip VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    accept_language VARCHAR(255) DEFAULT NULL,

    browser_name VARCHAR(80) DEFAULT NULL,
    browser_version VARCHAR(80) DEFAULT NULL,
    os_name VARCHAR(80) DEFAULT NULL,
    platform VARCHAR(120) DEFAULT NULL,
    language VARCHAR(40) DEFAULT NULL,
    timezone VARCHAR(80) DEFAULT NULL,

    cpu_cores SMALLINT UNSIGNED DEFAULT NULL,
    device_memory_gb DECIMAL(5,2) DEFAULT NULL,

    screen_width INT UNSIGNED DEFAULT NULL,
    screen_height INT UNSIGNED DEFAULT NULL,
    avail_width INT UNSIGNED DEFAULT NULL,
    avail_height INT UNSIGNED DEFAULT NULL,
    pixel_ratio DECIMAL(6,3) DEFAULT NULL,

    touch_enabled TINYINT(1) DEFAULT NULL,
    max_touch_points SMALLINT UNSIGNED DEFAULT NULL,

    webgl_vendor VARCHAR(255) DEFAULT NULL,
    webgl_renderer VARCHAR(255) DEFAULT NULL,

    battery_supported TINYINT(1) NOT NULL DEFAULT 0,
    battery_start_percent TINYINT UNSIGNED DEFAULT NULL,
    battery_last_percent TINYINT UNSIGNED DEFAULT NULL,
    battery_charging TINYINT(1) DEFAULT NULL,
    battery_discharging_time_seconds INT UNSIGNED DEFAULT NULL,

    stress_profile VARCHAR(40) NOT NULL DEFAULT 'medium',
    worker_count SMALLINT UNSIGNED DEFAULT NULL,
    workload_interval_seconds SMALLINT UNSIGNED DEFAULT NULL,
    workload_duration_seconds SMALLINT UNSIGNED DEFAULT NULL,
    workload_cycles_completed BIGINT UNSIGNED NOT NULL DEFAULT 0,

    started_at DATETIME NOT NULL,
    last_seen_at DATETIME DEFAULT NULL,
    ended_at DATETIME DEFAULT NULL,
    elapsed_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_test_uuid (test_uuid),
    KEY idx_started_at (started_at),
    KEY idx_last_seen_at (last_seen_at),
    KEY idx_status (status),
    KEY idx_stress_profile (stress_profile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(80) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Default admin user:
-- username: admin
-- password: admin
--
-- IMPORTANT:
-- Change this password immediately after first login.
--

INSERT IGNORE INTO admin_users (
    username,
    password_hash,
    is_active
) VALUES (
    'admin',
    '$2y$12$m838rvTzAk3nCy0dlw9A0.GwpTJ7bCFFxPWL3h6ChrejaxjWdg3Qa',
    1
);