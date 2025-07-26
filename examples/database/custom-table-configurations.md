# Custom Table Configuration Guide

This guide explains how to configure WaaSuP MCP Server to work with your existing database tables instead of creating new ones.

## When to Use Custom Table Mapping

**✅ Use custom table mapping when:**
- You have an existing application with user/agency data you want to preserve
- Your existing tables have the required fields (or can be modified to have them)
- You want to integrate MCP functionality into an existing system

**❌ Don't use custom table mapping when:**
- Starting a new project (use default tables with prefix instead)
- Your existing tables can't be modified to include required fields
- You prefer isolation between MCP and your existing data

## Required Fields by Table Type

### Core Tables (Most Commonly Mapped)

#### Agencies Table
```sql
-- Required fields for agencies table
id INT PRIMARY KEY                -- Numeric ID (auto-increment recommended)
uuid VARCHAR(36) UNIQUE NOT NULL  -- UUID for external references
name VARCHAR(255) NOT NULL        -- Agency/organization name
active BOOLEAN NOT NULL DEFAULT 1 -- Whether agency is active

-- Optional fields (your existing columns are preserved)
created_at DATETIME
updated_at DATETIME
-- ... any other existing fields
```

#### Users Table
```sql
-- Required fields for users table
id INT PRIMARY KEY                -- Numeric ID (auto-increment recommended)
agency_id INT NOT NULL           -- Foreign key to agencies table
name VARCHAR(255) NOT NULL       -- User's display name
email VARCHAR(255) NOT NULL      -- User's email address
password VARCHAR(255) NOT NULL   -- Hashed password

-- Optional social auth fields (add if using social authentication)
google_id VARCHAR(255) NULL      -- Google OAuth ID
linkedin_id VARCHAR(255) NULL    -- LinkedIn OAuth ID
github_id VARCHAR(255) NULL      -- GitHub OAuth ID

-- Optional fields (your existing columns are preserved)
uuid VARCHAR(36)                 -- If you have UUIDs
created_at DATETIME
updated_at DATETIME
-- ... any other existing fields
```

### OAuth Tables (Less Commonly Mapped)

#### OAuth Clients Table
```sql
-- Usually created fresh, but mappable if you have OAuth infrastructure
client_id VARCHAR(255) PRIMARY KEY
client_secret VARCHAR(255)
client_name VARCHAR(255) NOT NULL
redirect_uris JSON NOT NULL      -- PostgreSQL: JSONB
grant_types JSON NOT NULL        -- PostgreSQL: JSONB
response_types JSON NOT NULL     -- PostgreSQL: JSONB
created_at DATETIME NOT NULL
```

#### OAuth Tokens Table
```sql
-- Usually created fresh, but mappable if you have OAuth infrastructure
access_token VARCHAR(255) PRIMARY KEY
refresh_token VARCHAR(255)
client_id VARCHAR(255)
token_type VARCHAR(50) DEFAULT 'Bearer'
scope VARCHAR(500)
expires_at DATETIME NOT NULL
revoked BOOLEAN DEFAULT 0
agency_id INT NOT NULL
user_id INT
code_challenge VARCHAR(255)      -- For PKCE
code_challenge_method VARCHAR(10) -- For PKCE
created_at DATETIME NOT NULL
```

### Session Tables (Rarely Mapped)

#### Sessions, Messages, Response Tables
```sql
-- These are usually created fresh as they're MCP-specific
-- Only map if you have existing session infrastructure you want to reuse
-- See database-schema.sql for complete field requirements
```

## Configuration Examples

### Example 1: Basic Integration (Existing Users/Agencies)

```php
<?php
// Most common scenario: map existing user/agency tables, create new OAuth tables

$config = [
    'table_prefix' => 'mcp_',  // Prefix for new tables
    'table_mapping' => [
        // Map to your existing tables
        'agencies' => 'companies',           // Your existing companies table
        'users' => 'app_users',             // Your existing users table

        // Don't map these - let them use prefixed defaults:
        // oauth_clients -> mcp_oauth_clients (new table)
        // oauth_tokens -> mcp_oauth_tokens (new table)
        // sessions -> mcp_sessions (new table)
        // messages -> mcp_messages (new table)
        // etc.
    ]
];

$storage = new DatabaseStorage($pdo, $config);
```

### Example 2: Existing OAuth Infrastructure

```php
<?php
// If you already have OAuth 2.0 tables you want to reuse

$config = [
    'table_prefix' => 'mcp_',
    'table_mapping' => [
        'agencies' => 'organizations',
        'users' => 'members',
        'oauth_clients' => 'oauth2_clients',    // Existing OAuth table
        'oauth_tokens' => 'oauth2_access_tokens', // Existing OAuth table

        // MCP-specific tables use defaults:
        // sessions -> mcp_sessions
        // messages -> mcp_messages
        // sampling_responses -> mcp_sampling_responses
        // etc.
    ]
];
```

### Example 3: Complete Custom Mapping

```php
<?php
// If you want to control all table names (not recommended for most cases)

$config = [
    'table_prefix' => '',  // Empty since we're mapping everything
    'table_mapping' => [
        'agencies' => 'client_orgs',
        'users' => 'system_users',
        'oauth_clients' => 'api_clients',
        'oauth_tokens' => 'api_tokens',
        'sessions' => 'mcp_user_sessions',
        'messages' => 'mcp_message_queue',
        'sampling_responses' => 'mcp_ai_responses',
        'roots_responses' => 'mcp_file_responses',
        'elicitation_responses' => 'mcp_data_responses'
    ]
];
```

## Database Preparation Examples

### Adding Required Fields to Existing Tables

```sql
-- Example: Adding MCP fields to existing 'companies' table
ALTER TABLE companies
ADD COLUMN uuid VARCHAR(36) UNIQUE,
ADD COLUMN active BOOLEAN DEFAULT 1;

-- Generate UUIDs for existing records
UPDATE companies SET uuid = UUID() WHERE uuid IS NULL;

-- Example: Adding social auth to existing 'users' table
ALTER TABLE users
ADD COLUMN google_id VARCHAR(255) NULL,
ADD COLUMN linkedin_id VARCHAR(255) NULL,
ADD COLUMN github_id VARCHAR(255) NULL;

-- Add indexes for performance
CREATE INDEX idx_users_google_id ON users(google_id);
CREATE INDEX idx_users_linkedin_id ON users(linkedin_id);
CREATE INDEX idx_users_github_id ON users(github_id);
```

### Using Database Views for Field Mapping

```sql
-- If you can't modify existing tables, create views with required field names

-- Example: Your 'organizations' table has 'org_uuid' instead of 'uuid'
CREATE VIEW agencies AS
SELECT
    id,
    org_uuid as uuid,     -- Map org_uuid to uuid
    org_name as name,     -- Map org_name to name
    is_active as active,  -- Map is_active to active
    created_at,
    updated_at
FROM organizations;

-- Example: Your 'members' table has different field names
CREATE VIEW users AS
SELECT
    member_id as id,           -- Map member_id to id
    org_id as agency_id,       -- Map org_id to agency_id
    full_name as name,         -- Map full_name to name
    email_address as email,    -- Map email_address to email
    password_hash as password, -- Map password_hash to password
    google_oauth_id as google_id,
    created_date as created_at
FROM members;
```

## Framework Integration Examples

### Slim Framework

```php
<?php
// File: config/database.php

return [
    'table_prefix' => 'mcp_',
    'table_mapping' => [
        'agencies' => 'companies',
        'users' => 'app_users'
    ]
];

// File: your-slim-server.php
$databaseConfig = require __DIR__ . '/config/database.php';
$storage = new DatabaseStorage($pdo, $databaseConfig);

$mcpProvider = new SlimMCPProvider(
    $storage,
    $toolRegistry,
    $promptRegistry,
    $resourceRegistry,
    $responseFactory,
    $streamFactory,
    $serverConfig
);
```

### Laravel Integration

```php
<?php
// File: config/mcp.php

return [
    'database' => [
        'table_prefix' => 'mcp_',
        'table_mapping' => [
            'agencies' => 'companies',
            'users' => 'users'  // Use Laravel's default users table
        ]
    ]
];

// File: App/Http/Controllers/MCPController.php
class MCPController extends Controller
{
    public function __construct()
    {
        $databaseConfig = config('mcp.database');
        $pdo = DB::connection()->getPdo();
        $this->storage = new DatabaseStorage($pdo, $databaseConfig);
    }
}
```

### Standalone Server

```php
<?php
// File: config.php

$databaseConfig = [
    'table_prefix' => 'mcp_',
    'table_mapping' => [
        'agencies' => $_ENV['AGENCY_TABLE'] ?? 'companies',
        'users' => $_ENV['USER_TABLE'] ?? 'app_users'
    ]
];

// File: standalone-server.php
$config = require __DIR__ . '/config.php';
$storage = new DatabaseStorage($pdo, $config['database']);

$mcpServer = new MCPSaaSServer(
    $storage,
    $toolRegistry,
    $promptRegistry,
    $resourceRegistry,
    $serverConfig
);
```

## Migration Strategies

### Strategy 1: Gradual Migration

```php
<?php
// Phase 1: Start with existing tables, add MCP-specific tables
$config = [
    'table_mapping' => [
        'agencies' => 'companies',  // Use existing
        'users' => 'app_users'      // Use existing
        // oauth_*, sessions, messages use new mcp_* tables
    ]
];

// Phase 2: Later migrate OAuth if needed
// Phase 3: Optionally consolidate table names
```

### Strategy 2: Create New Schema, Migrate Data

```sql
-- Create new MCP schema following database-schema.sql
-- Then migrate your existing data:

INSERT INTO mcp_agencies (uuid, name, active)
SELECT UUID(), company_name, is_active
FROM companies;

INSERT INTO mcp_users (agency_id, name, email, password)
SELECT
    a.id,
    u.full_name,
    u.email_address,
    u.password_hash
FROM app_users u
JOIN companies c ON c.id = u.company_id
JOIN mcp_agencies a ON a.name = c.company_name;
```

## Testing Your Configuration

```php
<?php
// Test script to verify your table mapping works

try {
    $storage = new DatabaseStorage($pdo, $yourConfig);

    // Test agency lookup
    $agency = $storage->getContextData('your-agency-uuid', 'agency');
    if ($agency) {
        echo "✅ Agency table mapping works\n";
    }

    // Test user lookup
    $user = $storage->findUserByEmail('test@example.com');
    if ($user) {
        echo "✅ User table mapping works\n";
    }

    // Test session storage
    $storage->storeSession('test-session', ['test' => 'data']);
    $session = $storage->getSession('test-session');
    if ($session) {
        echo "✅ Session storage works\n";
    }

    echo "✅ All table mappings verified successfully\n";

} catch (Exception $e) {
    echo "❌ Configuration error: " . $e->getMessage() . "\n";
}
```

## Common Issues and Solutions

### Issue: Field Name Mismatches
**Solution:** Use database views to map field names, or add aliases in your mapping logic.

### Issue: Missing Required Fields
**Solution:** Use `ALTER TABLE` to add missing fields, or create views that provide default values.

### Issue: Data Type Incompatibilities
**Solution:** Use database functions in views to convert data types (e.g., `CAST(is_active AS BOOLEAN)`).

### Issue: Foreign Key Constraints
**Solution:** Ensure foreign key relationships are maintained. You may need to adjust your table mapping to preserve referential integrity.

## Best Practices

1. **Start Simple:** Map only agencies/users initially, let other tables use defaults
2. **Backup First:** Always backup your database before making schema changes
3. **Test Thoroughly:** Use the test script above to verify your configuration
4. **Document Changes:** Keep track of any schema modifications for future reference
5. **Consider Views:** Use database views when you can't modify existing tables
6. **Plan Migration:** Have a rollback plan if the integration doesn't work as expected

## Performance Considerations

- Add indexes on fields used for MCP lookups (uuid, email, social auth IDs)
- Consider table sizes when mapping to existing large tables
- Monitor query performance after integration
- Use database views judiciously (they can impact performance)

This configuration approach allows you to integrate WaaSuP with minimal disruption to your existing application while preserving your data and table structure.
