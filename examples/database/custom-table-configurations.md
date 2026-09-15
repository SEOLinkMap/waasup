# Custom Table Configurations

The WaaSuP MCP library works with existing database tables through configuration mapping. **You must create all required tables and fields yourself** - the library does not create any database structures.

**Important:** Use the provided `database-schema.sql` file to create the correct database structure. **Run only the MySQL section OR the PostgreSQL section** - not both.

## How Table Names Are Resolved

The library uses this logic for table names:

1. **Check table_mapping** - If a logical table is mapped, use the mapped name
2. **Fall back to prefix** - If not mapped, use `table_prefix + logical_name`

```php
// Example configuration
$config = [
    'database' => [
        'table_prefix' => 'mcp_',
        'table_mapping' => [
            'agencies' => 'client_agency',  // Uses 'client_agency'
            'users' => 'app_users',        // Uses 'app_users'
            // oauth_clients not mapped     // Uses 'mcp_oauth_clients'
            // sessions not mapped          // Uses 'mcp_sessions'
        ]
    ]
];
```

**Result:**
- `agencies` → `client_agency` (mapped)
- `users` → `app_users` (mapped)
- `oauth_clients` → `mcp_oauth_clients` (prefix + name)
- `sessions` → `mcp_sessions` (prefix + name)
- `messages` → `mcp_messages` (prefix + name)

## Field Mapping

When your existing tables have different field names:

```php
$config = [
    'database' => [
        'table_prefix' => 'mcp_',
        'table_mapping' => [
            'agencies' => 'client_agency',
        ],
        'field_mapping' => [
            'agencies' => [
                'uuid' => 'agency_uuid',
                'name' => 'company_name',
                'active' => 'is_active',
            ]
        ]
    ]
];
```

## Required Database Tables and Fields

**Use the appropriate section from the provided `database-schema.sql` file.** Run the MySQL section for MySQL or the PostgreSQL section for PostgreSQL - not both. The schemas below are from the MySQL section:

### Agencies Table
```sql
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
```

### Users Table
```sql
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
    INDEX `idx_github_id` (`github_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### OAuth Clients Table
```sql
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
```

### OAuth Tokens Table
```sql
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
    `resource` VARCHAR(500) DEFAULT NULL,
    `aud` TEXT DEFAULT NULL,
    `code_challenge` VARCHAR(255) DEFAULT NULL,
    `code_challenge_method` VARCHAR(10) DEFAULT NULL,
    `client_id` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `access_token` (`access_token`),
    INDEX `idx_expires_revoked` (`expires_at`, `revoked`),
    INDEX `idx_agency_id` (`agency_id`),
    INDEX `idx_refresh_token` (`refresh_token`),
    INDEX `idx_client_id` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Sessions Table
```sql
CREATE TABLE `mcp_sessions` (
    `session_id` VARCHAR(64) NOT NULL,
    `session_data` JSON NOT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`session_id`),
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Messages Table
```sql
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
```

### Sampling Responses Table
```sql
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
```

### Roots Responses Table
```sql
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
```

### Elicitation Responses Table
```sql
CREATE TABLE `mcp_elicitation_responses` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `session_id` VARCHAR(255) NOT NULL,
    `request_id` VARCHAR(255) NOT NULL,
    `response_data` JSON NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_session_request` (`session_id`, `request_id`),
    INDEX `idx_created_at` (`created_at`)
);
```

## Field Semantics

Behaviours the library depends on that the column definitions do not convey.

### `oauth_tokens` holds two kinds of row

The table stores both access tokens and authorization codes, told apart by `token_type`:

| `token_type` | Row represents | Notes |
|---|---|---|
| `Bearer` | An access token | Only rows with this value validate as bearer tokens |
| `authorization_code` | An authorization code mid-flow | `access_token` holds the code; `code_challenge` and `code_challenge_method` hold its PKCE binding |

An authorization code is single use. Redeeming one sets `revoked = 1`; the update tests
`revoked = 0` in its `WHERE` clause and a second redemption is refused.

### Agency scoping is a security boundary

`validateToken()` matches `agency_id` (or `user_id`, depending on the context type)
against the context in the request URL. `agency_id` must be populated on every token row
and must reference the agency whose `uuid` appears in the MCP endpoint path.

### JSON-encoded columns

These columns hold JSON documents written and read by the library. Use a `JSON` column
where your database supports one, otherwise `TEXT`:

- `sessions.session_data` — negotiated protocol version, client capabilities, resource
  subscriptions, log level, pending server-to-client requests, recently seen request ids
  and the last SSE event id delivered to the session
- `messages.message_data` — one queued JSON-RPC message
- `messages.context_data` — the context the message was created under
- `oauth_tokens.aud` — the audience array for RFC 8707 resource binding
- `sampling_responses.response_data`, `roots_responses.response_data`,
  `elicitation_responses.response_data` — a client's answer to a server request

### Expiry and cleanup

Messages are **not** deleted on delivery. They are retained for replay to a client
reconnecting with `Last-Event-ID`. `cleanup()` removes messages older than
`database.message_lifetime` (3600 seconds by default) and sessions past `expires_at`. It
runs on roughly one percent of session writes; index `sessions.expires_at` and
`messages.created_at`.

The `messages.id` column is the SSE event id and must be monotonically increasing. The
provided schema uses an auto-increment primary key.

## Summary

- **Default approach**: Use `table_prefix` with standard table names (recommended)
- **Advanced approach**: Use `table_mapping` and `field_mapping` for existing data
- **Use provided schema**: Use the MySQL section OR PostgreSQL section from `database-schema.sql` for your database type
- **Prefix applies to unmapped tables**: Mapped tables ignore the prefix completely
- **Field mapping only needed for different names**: Don't map fields with the same name
