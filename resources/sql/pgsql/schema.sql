CREATE TABLE IF NOT EXISTS schema_version (
    version INT NOT NULL
);

CREATE TABLE IF NOT EXISTS sites (
    id VARCHAR(64) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    url TEXT NULL,
    host VARCHAR(255) NULL,
    port INT NULL,
    check_type VARCHAR(32) NOT NULL,
    keyword TEXT NULL,
    keyword_mode VARCHAR(32) NULL,
    check_interval_seconds INT NULL,
    enabled BOOLEAN NOT NULL,
    created_at TIMESTAMPTZ(6) NOT NULL,
    status VARCHAR(32) NOT NULL,
    consecutive_fails INT NOT NULL DEFAULT 0,
    last_outcome VARCHAR(32) NULL,
    last_checked_at TIMESTAMPTZ(6) NULL,
    next_due_at TIMESTAMPTZ(6) NOT NULL,
    last_transition_at TIMESTAMPTZ(6) NULL,
    challenge_delivery VARCHAR(16) NULL
);

CREATE INDEX IF NOT EXISTS idx_sites_next_due_at ON sites (next_due_at);

CREATE TABLE IF NOT EXISTS site_maintenance_windows (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    site_id VARCHAR(64) NOT NULL REFERENCES sites (id) ON DELETE CASCADE,
    window_id VARCHAR(16) NOT NULL,
    starts_at TIMESTAMPTZ(6) NOT NULL,
    ends_at TIMESTAMPTZ(6) NOT NULL,
    note TEXT NULL,
    UNIQUE (site_id, window_id)
);

CREATE TABLE IF NOT EXISTS site_groups (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    site_id VARCHAR(64) NOT NULL REFERENCES sites (id) ON DELETE CASCADE,
    group_name VARCHAR(128) NOT NULL,
    UNIQUE (site_id, group_name)
);

CREATE TABLE IF NOT EXISTS history (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    site_id VARCHAR(64) NOT NULL,
    ts TIMESTAMPTZ(6) NOT NULL,
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
    evidence JSONB NULL,
    attestation VARCHAR(16) NOT NULL DEFAULT 'none',
    role VARCHAR(16) NOT NULL DEFAULT 'primary',
    flagged BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE INDEX IF NOT EXISTS idx_history_site_ts ON history (site_id, ts);

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
    uptime_percent DOUBLE PRECISION NULL,
    first_check_at TIMESTAMPTZ(6) NULL,
    last_check_at TIMESTAMPTZ(6) NULL,
    closed_at TIMESTAMPTZ(6) NOT NULL,
    PRIMARY KEY (site_id, date)
);

CREATE TABLE IF NOT EXISTS peers (
    id VARCHAR(64) PRIMARY KEY,
    label VARCHAR(255) NOT NULL,
    base_url TEXT NULL,
    enabled BOOLEAN NOT NULL,
    allow_outbound_delegation BOOLEAN NOT NULL,
    rate_limit_per_minute INT NULL
);

CREATE TABLE IF NOT EXISTS peer_keys (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    peer_id VARCHAR(64) NOT NULL REFERENCES peers (id) ON DELETE CASCADE,
    key_id VARCHAR(64) NOT NULL,
    secret_b64 TEXT NOT NULL,
    outbound BOOLEAN NOT NULL,
    inbound BOOLEAN NOT NULL,
    enabled BOOLEAN NOT NULL,
    created_at TIMESTAMPTZ(6) NOT NULL,
    UNIQUE (peer_id, key_id)
);

CREATE INDEX IF NOT EXISTS idx_peer_keys_lookup ON peer_keys (key_id, inbound, enabled);

CREATE TABLE IF NOT EXISTS peer_target_allowlist (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    peer_id VARCHAR(64) NOT NULL REFERENCES peers (id) ON DELETE CASCADE,
    pattern VARCHAR(512) NOT NULL,
    UNIQUE (peer_id, pattern)
);

CREATE TABLE IF NOT EXISTS peer_reputation (
    peer_id VARCHAR(64) PRIMARY KEY,
    score DOUBLE PRECISION NOT NULL,
    consecutive_exclusions INT NOT NULL,
    excluded_until TIMESTAMPTZ(6) NULL,
    last_event_at TIMESTAMPTZ(6) NULL,
    total_events INT NOT NULL
);

INSERT INTO schema_version (version)
SELECT 1 WHERE NOT EXISTS (SELECT 1 FROM schema_version);
