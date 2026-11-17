-- Shared fixed-window login counters. Keys are HMACs, never raw emails or IPs.
CREATE TABLE auth_login_limits (
    limit_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    window_start DATETIME NOT NULL,
    KEY idx_auth_login_limits_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
