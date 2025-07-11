-- Test database fixtures for MCP SaaS Server tests
-- SQLite compatible version

-- Clean up existing data
DELETE FROM mcp_oauth_tokens;
DELETE FROM mcp_users;
DELETE FROM mcp_agencies;
DELETE FROM mcp_sessions;
DELETE FROM mcp_messages;

-- Insert test agencies
INSERT INTO mcp_agencies (id, uuid, name, active, created_at) VALUES
(1, '550e8400-e29b-41d4-a716-446655440000', 'Test Agency', 1, datetime('now')),
(2, '550e8400-e29b-41d4-a716-446655440001', 'Inactive Agency', 0, datetime('now')),
(3, '550e8400-e29b-41d4-a716-446655440002', 'Another Agency', 1, datetime('now'));

-- Insert test users
INSERT INTO mcp_users (id, uuid, agency_id, name, email, active, created_at) VALUES
(1, 'user-uuid-123', 1, 'Test User', 'test@example.com', 1, datetime('now')),
(2, 'user-uuid-456', 1, 'Another User', 'user2@example.com', 1, datetime('now')),
(3, 'user-uuid-789', 2, 'Inactive Agency User', 'inactive@example.com', 1, datetime('now'));

-- Insert test OAuth tokens
INSERT INTO mcp_oauth_tokens (id, access_token, scope, expires_at, revoked, agency_id, user_id, created_at) VALUES
(1, 'test-valid-token', 'mcp:read mcp:write', datetime('now', '+1 hour'), 0, 1, 1, datetime('now')),
(2, 'test-expired-token', 'mcp:read', datetime('now', '-1 hour'), 0, 1, 1, datetime('now')),
(3, 'test-revoked-token', 'mcp:read mcp:write', datetime('now', '+1 hour'), 1, 1, 1, datetime('now')),
(4, 'test-limited-scope', 'mcp:read', datetime('now', '+1 hour'), 0, 1, 2, datetime('now')),
(5, 'test-agency2-token', 'mcp:read mcp:write', datetime('now', '+1 hour'), 0, 3, NULL, datetime('now'));

-- Insert test sessions
INSERT INTO mcp_sessions (session_id, session_data, expires_at, created_at) VALUES
('test-session-123', '{"user_id": 1, "agency_id": 1, "started_at": "2025-01-01T00:00:00Z"}', datetime('now', '+30 minutes'), datetime('now')),
('test-session-456', '{"user_id": 2, "agency_id": 1, "started_at": "2025-01-01T00:00:00Z"}', datetime('now', '+30 minutes'), datetime('now')),
('expired-session', '{"user_id": 1, "agency_id": 1, "started_at": "2025-01-01T00:00:00Z"}', datetime('now', '-30 minutes'), datetime('now', '-1 hour'));

-- Insert test messages
INSERT INTO mcp_messages (id, session_id, message_data, context_data, created_at) VALUES
(1, 'test-session-123', '{"jsonrpc": "2.0", "result": {"tools": []}, "id": 1}', '{"agency_id": 1, "user_id": 1}', datetime('now')),
(2, 'test-session-123', '{"jsonrpc": "2.0", "result": {"status": "pong"}, "id": 2}', '{"agency_id": 1, "user_id": 1}', datetime('now')),
(3, 'test-session-456', '{"jsonrpc": "2.0", "result": {"content": [{"type": "text", "text": "Hello"}]}, "id": 3}', '{"agency_id": 1, "user_id": 2}', datetime('now')),
(4, 'old-session', '{"jsonrpc": "2.0", "result": {"old": "message"}, "id": 4}', '{"agency_id": 1}', datetime('now', '-2 hours'));
