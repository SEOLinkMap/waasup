-- MCP SaaS Server Database Schema
-- Compatible with MySQL 5.7+ and PostgreSQL 12+
-- Choose the appropriate section for your database

-- =============================================================================
-- MYSQL VERSION
-- =============================================================================

-- For MySQL, run this section:
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO';

-- Messages table for SSE delivery
CREATE TABLE `mcp_messages` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `session_id` VARCHAR(64) NOT NULL,
    `message_data` LONGTEXT NOT NULL,
    `context_data` JSON DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_session_created` (`session_id`, `created_at`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions table
CREATE TABLE `mcp_sessions` (
    `session_id` VARCHAR(64) NOT NULL,
    `session_data` JSON NOT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`session_id`),
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Agencies table (multi-tenant contexts)
CREATE TABLE `mcp_agencies` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `uuid` VARCHAR(36) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uuid` (`uuid`),
    INDEX `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Users table
CREATE TABLE `mcp_users` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `uuid` VARCHAR(36) NOT NULL,
    `agency_id` INT NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `google_id` VARCHAR(255) DEFAULT NULL,
    `linkedin_id` VARCHAR(255) DEFAULT NULL,
    `github_id` VARCHAR(255) DEFAULT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uuid` (`uuid`),
    UNIQUE KEY `email_agency` (`email`, `agency_id`),
    INDEX `idx_agency_id` (`agency_id`),
    INDEX `idx_active` (`active`),
    INDEX `idx_google_id` (`google_id`),
    INDEX `idx_linkedin_id` (`linkedin_id`),
    INDEX `idx_github_id` (`github_id`),
    CONSTRAINT `fk_users_agency` FOREIGN KEY (`agency_id`) REFERENCES `mcp_agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- OAuth clients table
CREATE TABLE `mcp_oauth_clients` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `client_id` VARCHAR(255) NOT NULL,
    `client_secret` VARCHAR(255) DEFAULT NULL,
    `client_name` VARCHAR(255) NOT NULL,
    `redirect_uris` JSON NOT NULL,
    `grant_types` JSON NOT NULL,
    `response_types` JSON NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `client_id` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- OAuth tokens table
CREATE TABLE `mcp_oauth_tokens` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `access_token` VARCHAR(255) NOT NULL,
    `refresh_token` VARCHAR(255) DEFAULT NULL,
    `token_type` VARCHAR(50) NOT NULL DEFAULT 'Bearer',
    `scope` VARCHAR(500) DEFAULT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `revoked` TINYINT(1) NOT NULL DEFAULT 0,
    `agency_id` INT NOT NULL,
    `user_id` INT DEFAULT NULL,
    `code_challenge` VARCHAR(255) DEFAULT NULL,
    `code_challenge_method` VARCHAR(10) DEFAULT NULL,
    `client_id` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `access_token` (`access_token`),
    INDEX `idx_expires_revoked` (`expires_at`, `revoked`),
    INDEX `idx_agency_id` (`agency_id`),
    INDEX `idx_refresh_token` (`refresh_token`),
    INDEX `idx_client_id` (`client_id`),
    CONSTRAINT `fk_tokens_agency` FOREIGN KEY (`agency_id`) REFERENCES `mcp_agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `mcp_users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tokens_client` FOREIGN KEY (`client_id`) REFERENCES `mcp_oauth_clients` (`client_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `mcp_sampling_responses` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `session_id` varchar(255) NOT NULL,
    `request_id` varchar(255) NOT NULL,
    `response_data` longtext NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_session_request` (`session_id`, `request_id`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `mcp_roots_responses` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `session_id` varchar(255) NOT NULL,
    `request_id` varchar(255) NOT NULL,
    `response_data` longtext NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_session_request` (`session_id`, `request_id`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mcprepo_elicitation_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(255) NOT NULL,
    elicitation_id VARCHAR(255) NOT NULL,
    response_data JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session_elicitation (session_id, elicitation_id),
    INDEX idx_created_at (created_at)
);

-- Sample data for MySQL
INSERT INTO `mcp_agencies` (`uuid`, `name`, `active`) VALUES
('550e8400-e29b-41d4-a716-446655440000', 'Test Agency', 1);

INSERT INTO `mcp_users` (`uuid`, `agency_id`, `name`, `email`, `password`, `active`) VALUES
('660e8400-e29b-41d4-a716-446655440001', 1, 'Test User', 'test@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1);

INSERT INTO `mcp_oauth_clients` (`client_id`, `client_name`, `redirect_uris`, `grant_types`, `response_types`) VALUES
('test-client', 'Test MCP Client', '["http://localhost:3000/callback", "urn:ietf:wg:oauth:2.0:oob"]', '["authorization_code", "refresh_token"]', '["code"]');

INSERT INTO `mcp_oauth_tokens` (`access_token`, `scope`, `expires_at`, `agency_id`, `revoked`, `client_id`) VALUES
('test-token-12345', 'mcp:read mcp:write', DATE_ADD(NOW(), INTERVAL 1 YEAR), 1, 0, 'test-client');

-- =============================================================================
-- POSTGRESQL VERSION
-- =============================================================================

-- For PostgreSQL, run this section instead:

-- Messages table for SSE delivery
CREATE TABLE mcp_messages (
    id BIGSERIAL PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL,
    message_data TEXT NOT NULL,
    context_data JSONB DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_messages_session_created ON mcp_messages (session_id, created_at);
CREATE INDEX idx_messages_created_at ON mcp_messages (created_at);

-- Sessions table
CREATE TABLE mcp_sessions (
    session_id VARCHAR(64) PRIMARY KEY,
    session_data JSONB NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_sessions_expires ON mcp_sessions (expires_at);

-- Agencies table (multi-tenant contexts)
CREATE TABLE mcp_agencies (
    id SERIAL PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_agencies_active ON mcp_agencies (active);

-- Function to update updated_at timestamp
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Trigger for agencies updated_at
CREATE TRIGGER update_agencies_updated_at
    BEFORE UPDATE ON mcp_agencies
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- Users table
CREATE TABLE mcp_users (
    id SERIAL PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL UNIQUE,
    agency_id INTEGER NOT NULL REFERENCES mcp_agencies(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    google_id VARCHAR(255) DEFAULT NULL,
    linkedin_id VARCHAR(255) DEFAULT NULL,
    github_id VARCHAR(255) DEFAULT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(email, agency_id)
);

CREATE INDEX idx_users_agency_id ON mcp_users (agency_id);
CREATE INDEX idx_users_active ON mcp_users (active);
CREATE INDEX idx_users_google_id ON mcp_users (google_id);
CREATE INDEX idx_users_linkedin_id ON mcp_users (linkedin_id);
CREATE INDEX idx_users_github_id ON mcp_users (github_id);

-- Trigger for users updated_at
CREATE TRIGGER update_users_updated_at
    BEFORE UPDATE ON mcp_users
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- OAuth clients table
CREATE TABLE mcp_oauth_clients (
    id SERIAL PRIMARY KEY,
    client_id VARCHAR(255) NOT NULL UNIQUE,
    client_secret VARCHAR(255) DEFAULT NULL,
    client_name VARCHAR(255) NOT NULL,
    redirect_uris JSONB NOT NULL,
    grant_types JSONB NOT NULL,
    response_types JSONB NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- OAuth tokens table
CREATE TABLE mcp_oauth_tokens (
    id SERIAL PRIMARY KEY,
    access_token VARCHAR(255) NOT NULL UNIQUE,
    refresh_token VARCHAR(255) DEFAULT NULL,
    token_type VARCHAR(50) NOT NULL DEFAULT 'Bearer',
    scope VARCHAR(500) DEFAULT NULL,
    expires_at TIMESTAMP NOT NULL,
    revoked BOOLEAN NOT NULL DEFAULT FALSE,
    agency_id INTEGER NOT NULL REFERENCES mcp_agencies(id) ON DELETE CASCADE,
    user_id INTEGER DEFAULT NULL REFERENCES mcp_users(id) ON DELETE SET NULL,
    code_challenge VARCHAR(255) DEFAULT NULL,
    code_challenge_method VARCHAR(10) DEFAULT NULL,
    client_id VARCHAR(255) DEFAULT NULL REFERENCES mcp_oauth_clients(client_id) ON DELETE SET NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_tokens_expires_revoked ON mcp_oauth_tokens (expires_at, revoked);
CREATE INDEX idx_tokens_agency_id ON mcp_oauth_tokens (agency_id);
CREATE INDEX idx_tokens_refresh_token ON mcp_oauth_tokens (refresh_token);
CREATE INDEX idx_tokens_client_id ON mcp_oauth_tokens (client_id);

-- Sample data for PostgreSQL
INSERT INTO mcp_agencies (uuid, name, active) VALUES
('550e8400-e29b-41d4-a716-446655440000', 'Test Agency', TRUE);

INSERT INTO mcp_users (uuid, agency_id, name, email, password, active) VALUES
('660e8400-e29b-41d4-a716-446655440001', 1, 'Test User', 'test@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', TRUE);

INSERT INTO mcp_oauth_clients (client_id, client_name, redirect_uris, grant_types, response_types) VALUES
('test-client', 'Test MCP Client', '["http://localhost:3000/callback", "urn:ietf:wg:oauth:2.0:oob"]', '["authorization_code", "refresh_token"]', '["code"]');

INSERT INTO mcp_oauth_tokens (access_token, scope, expires_at, agency_id, revoked, client_id) VALUES
('test-token-12345', 'mcp:read mcp:write', CURRENT_TIMESTAMP + INTERVAL '1 year', 1, FALSE, 'test-client');

-- =============================================================================
-- USAGE INSTRUCTIONS
-- =============================================================================

/*
MYSQL SETUP:
1. Create database: CREATE DATABASE mcp_server CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
2. Run the MySQL section above
3. Update your .env: DB_CONNECTION=mysql

POSTGRESQL SETUP:
1. Create database: CREATE DATABASE mcp_server;
2. Run the PostgreSQL section above
3. Update your .env: DB_CONNECTION=pgsql

The DatabaseStorage class automatically detects the database type and adapts accordingly.

Key improvements in this schema:
- Added missing indexes for social login IDs (google_id, linkedin_id, github_id)
- Added proper foreign key constraints for all relationships
- Fixed PostgreSQL boolean handling (TRUE/FALSE instead of 1/0)
- Removed backticks from PostgreSQL version
- Added index on refresh_token for performance
- Added sample user data for testing
- Consistent timestamp handling between databases

Test credentials:
- Token: test-token-12345
- Agency UUID: 550e8400-e29b-41d4-a716-446655440000
- User: test@example.com / password (hashed: "password")
*/
