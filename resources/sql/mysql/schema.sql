CREATE TABLE IF NOT EXISTS schema_version (
    version INT NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sites (
    id VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    url TEXT NULL,
    host VARCHAR(255) NULL,
    port INT NULL,
    check_type VARCHAR(32) NOT NULL,
    keyword TEXT NULL,
    keyword_mode VARCHAR(32) NULL,
    check_interval_seconds INT NULL,
    enabled BOOLEAN NOT NULL,
    created_at DATETIME(6) NOT NULL,
    status VARCHAR(32) NOT NULL,
    consecutive_fails INT NOT NULL DEFAULT 0,
    last_outcome VARCHAR(32) NULL,
    last_checked_at DATETIME(6) NULL,
    next_due_at DATETIME(6) NOT NULL,
    last_transition_at DATETIME(6) NULL,
    challenge_delivery VARCHAR(16) NULL,
    PRIMARY KEY (id),
    KEY idx_sites_next_due_at (next_due_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS site_maintenance_windows (
    id BIGINT AUTO_INCREMENT,
    site_id VARCHAR(64) NOT NULL,
    window_id VARCHAR(16) NOT NULL,
    starts_at DATETIME(6) NOT NULL,
    ends_at DATETIME(6) NOT NULL,
    note TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_site_maintenance_windows (site_id, window_id),
    CONSTRAINT fk_site_maintenance_windows_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS site_groups (
    id BIGINT AUTO_INCREMENT,
    site_id VARCHAR(64) NOT NULL,
    group_name VARCHAR(128) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_site_groups (site_id, group_name),
    CONSTRAINT fk_site_groups_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS history (
    id BIGINT AUTO_INCREMENT,
    site_id VARCHAR(64) NOT NULL,
    ts DATETIME(6) NOT NULL,
    check_type VARCHAR(32) NOT NULL,
    source VARCHAR(128) NOT NULL,
    peer_id VARCHAR(64) NULL,
    peer_label TEXT NULL,
    outcome VARCHAR(32) NOT NULL,
    http_status INT NULL,
    response_time_ms INT NULL,
    keyword_matched BOOLEAN NULL,
    error TEXT NULL,
    state_before VARCHAR(32) NOT NULL,
    state_after VARCHAR(32) NOT NULL,
    excluded_by_maintenance BOOLEAN NOT NULL DEFAULT FALSE,
    evidence JSON NULL,
    attestation VARCHAR(16) NOT NULL DEFAULT 'none',
    role VARCHAR(16) NOT NULL DEFAULT 'primary',
    flagged BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (id),
    KEY idx_history_site_ts (site_id, ts)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rollups (
    site_id VARCHAR(64) NOT NULL,
    date DATE NOT NULL,
    checks_total INT NOT NULL,
    checks_pass INT NOT NULL,
    checks_fail INT NOT NULL,
    checks_unknown INT NOT NULL,
    checks_excluded_maintenance INT NOT NULL,
    checks_self_sourced INT NOT NULL,
    checks_peer_sourced INT NOT NULL,
    uptime_percent DOUBLE NULL,
    first_check_at DATETIME(6) NULL,
    last_check_at DATETIME(6) NULL,
    closed_at DATETIME(6) NOT NULL,
    PRIMARY KEY (site_id, date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS peers (
    id VARCHAR(64) NOT NULL,
    label VARCHAR(255) NOT NULL,
    base_url TEXT NULL,
    enabled BOOLEAN NOT NULL,
    allow_outbound_delegation BOOLEAN NOT NULL,
    rate_limit_per_minute INT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS peer_keys (
    id BIGINT AUTO_INCREMENT,
    peer_id VARCHAR(64) NOT NULL,
    key_id VARCHAR(64) NOT NULL,
    secret_b64 TEXT NOT NULL,
    outbound BOOLEAN NOT NULL,
    inbound BOOLEAN NOT NULL,
    enabled BOOLEAN NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_peer_keys (peer_id, key_id),
    KEY idx_peer_keys_lookup (key_id, inbound, enabled),
    CONSTRAINT fk_peer_keys_peer FOREIGN KEY (peer_id) REFERENCES peers (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS peer_target_allowlist (
    id BIGINT AUTO_INCREMENT,
    peer_id VARCHAR(64) NOT NULL,
    pattern VARCHAR(512) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_peer_target_allowlist (peer_id, pattern),
    CONSTRAINT fk_peer_target_allowlist_peer FOREIGN KEY (peer_id) REFERENCES peers (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS peer_reputation (
    peer_id VARCHAR(64) NOT NULL,
    score DOUBLE NOT NULL,
    consecutive_exclusions INT NOT NULL,
    excluded_until DATETIME(6) NULL,
    last_event_at DATETIME(6) NULL,
    total_events INT NOT NULL,
    PRIMARY KEY (peer_id)
) ENGINE=InnoDB;

INSERT INTO schema_version (version)
SELECT 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM schema_version);
