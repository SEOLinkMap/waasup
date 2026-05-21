#!/usr/bin/env php
<?php
/**
 * MCP SPECIFICATION COMPLIANCE TEST
 * 
 * - Complete OAuth endpoint testing
 * - Tests ALL 4 protocol versions independently
 * - Validates proper HTTP status codes and response bodies per spec
 * - Validates JSON-RPC 2.0 structure compliance
 * 
 * Usage:
 *   php mcptest.php [mcp_url] [oauth_token]
 *   
 *   mcp_url: Optional MCP server URL
 *   oauth_token: Optional OAuth access token
 *   
 *   If not provided, the script will prompt for these values when needed.
 */

// Parse command line arguments
$mcpUrl = null;
$accessToken = null;

if (isset($argv[1])) {
    $mcpUrl = $argv[1];
}

if (isset($argv[2])) {
    $accessToken = $argv[2];
}

// If URL not provided via CLI, ask for it
if (!$mcpUrl) {
    echo "Enter MCP server URL: ";
    $mcpUrl = trim(fgets(STDIN));
    if (!$mcpUrl) {
        die("Error: MCP URL is required\n");
    }
    echo "\n";
}

$allResults = [];

echo str_repeat('=', 80) . "\n";
echo "MCP SPECIFICATION COMPLIANCE TEST\n";
echo str_repeat('=', 80) . "\n";
echo "Server: $mcpUrl\n";
echo str_repeat('=', 80) . "\n\n";

// ============================================================================
// OAUTH ENDPOINT TESTING
// ============================================================================
echo "OAUTH ENDPOINT TESTING\n";
echo str_repeat('=', 80) . "\n\n";

echo "TEST 1: WWW-Authenticate Header (RFC 9728)\n";
echo str_repeat('-', 80) . "\n";

$result = httpPost($mcpUrl, ['Content-Type: application/json'], 
    '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}');

echo "REQUEST: POST $mcpUrl (unauthenticated)\n";
echo "RESPONSE: HTTP {$result['status']}\n\n";

if ($result['status'] != 401) {
    echo "✅ No authentication required - Skipping OAuth tests\n\n";
} else {
    echo "✅ Server requires authentication (HTTP 401)\n\n";
    
    $headerText = $result['raw_headers'];
    if (!preg_match('/www-authenticate:.*resource_metadata="([^"]+)"/i', $headerText, $matches)) {
        die("❌ FAIL: WWW-Authenticate header missing resource_metadata parameter\n");
    }
    
    $prmUrl = $matches[1];
    echo "TEST 2: Protected Resource Metadata\n";
    echo str_repeat('-', 80) . "\n";
    echo "PRM URL: $prmUrl\n\n";
    
    $result = httpGet($prmUrl);
    if ($result['status'] != 200) {
        die("❌ FAIL: PRM endpoint returned HTTP {$result['status']}\n");
    }
    
    $prm = json_decode($result['body_raw'], true);
    if (!isset($prm['authorization_servers'])) {
        die("❌ FAIL: PRM missing authorization_servers field\n");
    }
    echo "✅ Valid Protected Resource Metadata\n\n";
    
    $authServerUrl = $prm['authorization_servers'][0];
    echo "TEST 3: Authorization Server Metadata (RFC 8414)\n";
    echo str_repeat('-', 80) . "\n";
    
    $parsed = parse_url($authServerUrl);
    $path = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';
    $asmUrl = $parsed['scheme'] . '://' . $parsed['host'];
    if (isset($parsed['port'])) $asmUrl .= ':' . $parsed['port'];
    if ($path) {
        $asmUrl .= '/.well-known/oauth-authorization-server' . $path;
    } else {
        $asmUrl .= '/.well-known/oauth-authorization-server';
    }
    
    echo "ASM URL: $asmUrl\n\n";
    
    $result = httpGet($asmUrl);
    if ($result['status'] != 200) {
        die("❌ FAIL: ASM endpoint returned HTTP {$result['status']}\n");
    }
    
    $asm = json_decode($result['body_raw'], true);
    $requiredFields = ['issuer', 'token_endpoint', 'grant_types_supported'];
    foreach ($requiredFields as $field) {
        if (!isset($asm[$field])) {
            die("❌ FAIL: ASM missing required field: $field\n");
        }
    }
    echo "✅ Valid Authorization Server Metadata\n\n";
    
    // TEST 4: Dynamic Client Registration (DCR)
    if (isset($asm['registration_endpoint'])) {
        echo "TEST 4: Dynamic Client Registration (RFC 7591)\n";
        echo str_repeat('-', 80) . "\n";
        echo "Registration Endpoint: {$asm['registration_endpoint']}\n\n";
        
        $registrationData = json_encode([
            'client_name' => 'MCP Compliance Test Client',
            'client_uri' => 'https://github.com/modelcontextprotocol',
            'redirect_uris' => ['http://localhost:3000/callback'],
            'grant_types' => ['authorization_code'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none'
        ]);
        
        $result = httpPost($asm['registration_endpoint'], 
            ['Content-Type: application/json'], $registrationData);
        
        if ($result['status'] == 201 || $result['status'] == 200) {
            $registration = json_decode($result['body_raw'], true);
            if (isset($registration['client_id'])) {
                echo "✅ Dynamic Client Registration successful\n";
                echo "   Client ID: {$registration['client_id']}\n\n";
            } else {
                echo "❌ FAIL: Registration response missing client_id\n\n";
            }
        } else {
            echo "❌ FAIL: DCR returned HTTP {$result['status']}\n\n";
        }
    } else {
        echo "TEST 4: Dynamic Client Registration\n";
        echo str_repeat('-', 80) . "\n";
        echo "⚠️  UNSUPPORTED: No registration_endpoint in ASM\n\n";
    }
    
    // TEST 5: Token Endpoint
    echo "TEST 5: Token Endpoint\n";
    echo str_repeat('-', 80) . "\n";
    echo "Token Endpoint: {$asm['token_endpoint']}\n\n";
    
    $tokenData = http_build_query([
        'grant_type' => 'authorization_code',
        'code' => 'invalid_test_code',
        'client_id' => 'test_client',
        'code_verifier' => 'test_verifier'
    ]);
    
    $result = httpPost($asm['token_endpoint'], 
        ['Content-Type: application/x-www-form-urlencoded'], $tokenData);
    
    if ($result['status'] == 400) {
        $error = json_decode($result['body_raw'], true);
        if (isset($error['error'])) {
            echo "✅ Token endpoint returns proper OAuth error response\n";
            echo "   Error: {$error['error']}\n\n";
        } else {
            echo "❌ FAIL: 400 response but no OAuth error format\n\n";
        }
    } else {
        echo "⚠️  Token endpoint returned HTTP {$result['status']} (expected 400)\n\n";
    }
    
    // TEST 6: Revocation Endpoint
    if (isset($asm['revocation_endpoint'])) {
        echo "TEST 6: Revocation Endpoint (RFC 7009)\n";
        echo str_repeat('-', 80) . "\n";
        echo "Revocation Endpoint: {$asm['revocation_endpoint']}\n\n";
        
        $revokeData = http_build_query([
            'token' => 'test_token',
            'client_id' => 'test_client'
        ]);
        
        $result = httpPost($asm['revocation_endpoint'],
            ['Content-Type: application/x-www-form-urlencoded'], $revokeData);
        
        if ($result['status'] == 200) {
            echo "✅ Revocation endpoint accepts requests\n\n";
        } else {
            echo "⚠️  Revocation endpoint returned HTTP {$result['status']}\n\n";
        }
    } else {
        echo "TEST 6: Revocation Endpoint\n";
        echo str_repeat('-', 80) . "\n";
        echo "⚠️  UNSUPPORTED: No revocation_endpoint in ASM\n\n";
    }
    
    // TEST 7: Introspection Endpoint
    if (isset($asm['introspection_endpoint'])) {
        echo "TEST 7: Introspection Endpoint (RFC 7662)\n";
        echo str_repeat('-', 80) . "\n";
        echo "Introspection Endpoint: {$asm['introspection_endpoint']}\n\n";
        
        $introspectData = http_build_query([
            'token' => 'test_token',
            'client_id' => 'test_client'
        ]);
        
        $result = httpPost($asm['introspection_endpoint'],
            ['Content-Type: application/x-www-form-urlencoded'], $introspectData);
        
        if ($result['status'] == 200) {
            echo "✅ Introspection endpoint accepts requests\n\n";
        } else {
            echo "⚠️  Introspection endpoint returned HTTP {$result['status']}\n\n";
        }
    } else {
        echo "TEST 7: Introspection Endpoint\n";
        echo str_repeat('-', 80) . "\n";
        echo "⚠️  UNSUPPORTED: No introspection_endpoint in ASM\n\n";
    }
    
    // TEST 8: ASM Required Fields
    echo "TEST 8: ASM Required Fields\n";
    echo str_repeat('-', 80) . "\n";
    
    $requiredAsmFields = [
        'issuer',
        'authorization_endpoint',
        'token_endpoint',
        'grant_types_supported',
        'response_types_supported',
        'token_endpoint_auth_methods_supported'
    ];
    
    $allPresent = true;
    foreach ($requiredAsmFields as $field) {
        if (!isset($asm[$field])) {
            echo "❌ Missing required field: $field\n";
            $allPresent = false;
        }
    }
    
    if ($allPresent) {
        echo "✅ All required ASM fields present\n\n";
    } else {
        echo "\n";
    }
    
    // TEST 9: PKCE Support
    echo "TEST 9: PKCE Support\n";
    echo str_repeat('-', 80) . "\n";
    
    if (isset($asm['code_challenge_methods_supported'])) {
        $methods = $asm['code_challenge_methods_supported'];
        echo "✅ PKCE supported\n";
        echo "   Methods: " . implode(', ', $methods) . "\n";
        
        if (in_array('S256', $methods)) {
            echo "   ✅ S256 method supported (recommended)\n\n";
        } else {
            echo "   ⚠️  S256 method not supported\n\n";
        }
    } else {
        echo "❌ PKCE not advertised in ASM\n\n";
    }
    
    // TEST 10: PRM/ASM Consistency
    echo "TEST 10: PRM/ASM Consistency\n";
    echo str_repeat('-', 80) . "\n";
    
    if ($prm['authorization_servers'][0] === $asm['issuer']) {
        echo "✅ PRM authorization_servers matches ASM issuer\n\n";
    } else {
        echo "❌ FAIL: PRM authorization_servers doesn't match ASM issuer\n";
        echo "   PRM: {$prm['authorization_servers'][0]}\n";
        echo "   ASM: {$asm['issuer']}\n\n";
    }
    
    // TEST 11: PRM Required Fields
    echo "TEST 11: PRM Required Fields\n";
    echo str_repeat('-', 80) . "\n";
    
    $requiredPrmFields = ['resource', 'authorization_servers', 'bearer_methods_supported'];
    $allPresent = true;
    foreach ($requiredPrmFields as $field) {
        if (!isset($prm[$field])) {
            echo "❌ Missing required field: $field\n";
            $allPresent = false;
        }
    }
    
    if ($allPresent) {
        echo "✅ All required PRM fields present\n\n";
    } else {
        echo "\n";
    }
    
    // TEST 12: PRM MCP-Specific Fields
    echo "TEST 12: PRM MCP-Specific Fields\n";
    echo str_repeat('-', 80) . "\n";
    
    $mcpPrmFields = ['http_sse_supported', 'mcp_features_supported', 'scopes_supported'];
    foreach ($mcpPrmFields as $field) {
        if (isset($prm[$field])) {
            if (is_array($prm[$field])) {
                echo "✅ $field: " . implode(', ', $prm[$field]) . "\n";
            } else {
                echo "✅ $field: " . ($prm[$field] ? 'true' : 'false') . "\n";
            }
        }
    }
    echo "\n";
    
    // TEST 13: Get Access Token
    echo "TEST 13: User Access Token\n";
    echo str_repeat('-', 80) . "\n";
    
    // Only ask for token if not provided via CLI
    if (!$accessToken) {
        echo "Enter your access token (or press Enter to skip authenticated tests): ";
        $accessToken = trim(fgets(STDIN));
    }
    
    if ($accessToken) {
        echo "\n✅ Access token provided\n\n";
        
        if (isset($asm['introspection_endpoint'])) {
            $introspectData = http_build_query(['token' => $accessToken]);
            
            $result = httpPost($asm['introspection_endpoint'],
                ['Content-Type: application/x-www-form-urlencoded'], $introspectData);
            
            if ($result['status'] == 200) {
                $introspection = json_decode($result['body_raw'], true);
                if (isset($introspection['active']) && $introspection['active']) {
                    echo "✅ Token is valid and active\n\n";
                } else {
                    echo "⚠️  Token introspection indicates inactive token\n\n";
                }
            }
        }
    } else {
        echo "\n⚠️  No access token provided - tests may fail if auth required\n\n";
    }
}

echo str_repeat('=', 80) . "\n";
echo "OAUTH TESTING COMPLETE\n";
echo str_repeat('=', 80) . "\n\n";

// ============================================================================
// MCP PROTOCOL TESTING
// ============================================================================

$versions = ['2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25'];

foreach ($versions as $requestedVersion) {
    echo str_repeat('=', 80) . "\n";
    echo "TESTING PROTOCOL VERSION: $requestedVersion\n";
    echo str_repeat('=', 80) . "\n\n";
    
    $results = [];
    $sessionId = null;
    $requestId = 1;
    
    // Initialize with this version
    echo "Initializing with version $requestedVersion...\n";
    
    $initParams = [
        'protocolVersion' => $requestedVersion,
        'capabilities' => new stdClass(),
        'clientInfo' => ['name' => 'MCP-Test', 'version' => '1.0.0']
    ];
    
    echo "\nINITIALIZE REQUEST:\n";
    echo json_encode(['jsonrpc' => '2.0', 'id' => $requestId, 'method' => 'initialize', 'params' => $initParams], JSON_PRETTY_PRINT) . "\n\n";
    
    $initResult = mcpRequest('initialize', $initParams, $requestId++, $requestedVersion);
    
    echo "INITIALIZE RESPONSE (HTTP {$initResult['status']}):\n";
    echo "Headers:\n";
    foreach ($initResult['headers'] as $key => $value) {
        echo "  $key: $value\n";
    }
    echo "\nBody:\n";
    echo json_encode($initResult['body'], JSON_PRETTY_PRINT) . "\n\n";
    
    if ($initResult['status'] != 200) {
        echo "❌ FAIL: Initialize returned HTTP {$initResult['status']}\n\n";
        $allResults[$requestedVersion] = ['initialize' => "FAIL - HTTP {$initResult['status']}"];
        continue;
    }
    
    // Validate JSON-RPC structure
    $jsonRpcValid = validateJsonRpcResponse($initResult['body'], 1, 'initialize');
    if ($jsonRpcValid !== true) {
        echo "❌ FAIL: $jsonRpcValid\n\n";
        $allResults[$requestedVersion] = ['initialize' => "FAIL - $jsonRpcValid"];
        continue;
    }
    
    if (!isset($initResult['body']['result']['protocolVersion'])) {
        echo "❌ FAIL: No protocolVersion in response\n\n";
        $allResults[$requestedVersion] = ['initialize' => 'FAIL - No version'];
        continue;
    }
    
    $negotiatedVersion = $initResult['body']['result']['protocolVersion'];
    
    if ($negotiatedVersion !== $requestedVersion) {
        echo "❌ Server does NOT support version $requestedVersion\n";
        echo "   Server negotiated: $negotiatedVersion\n\n";
        $allResults[$requestedVersion] = ['initialize' => "UNSUPPORTED - Server returned $negotiatedVersion"];
        continue;
    }
    
    echo "✅ Server supports version $requestedVersion\n";
    
    $serverCapabilities = $initResult['body']['result']['capabilities'] ?? [];
    
    echo "\nSERVER CAPABILITIES:\n";
    echo json_encode($serverCapabilities, JSON_PRETTY_PRINT) . "\n\n";
    
    if (isset($initResult['headers']['mcp-session-id'])) {
        $sessionId = $initResult['headers']['mcp-session-id'];
        echo "Session ID: $sessionId\n";
    }
    
    echo "\n";
    $results['initialize'] = 'PASS';
    
    // Send initialized notification
    echo "TEST: notifications/initialized\n";
    $notifResult = mcpNotification('notifications/initialized', new stdClass(), $requestedVersion, $sessionId);
    
    // Notifications can return 202 with empty body
    if ($notifResult['status'] == 202) {
        if (trim($notifResult['body_raw']) === '') {
            echo "✅ PASS\n\n";
            $results['notifications/initialized'] = 'PASS';
        } else {
            echo "❌ FAIL: HTTP 202 must have empty body (spec violation)\n";
            echo "   Body: " . substr($notifResult['body_raw'], 0, 100) . "\n\n";
            $results['notifications/initialized'] = "FAIL - 202 with body";
        }
    } else {
        echo "❌ FAIL: HTTP {$notifResult['status']}\n\n";
        $results['notifications/initialized'] = "FAIL - HTTP {$notifResult['status']}";
    }
    
    // Test utilities
    echo "UTILITIES\n";
    echo str_repeat('-', 80) . "\n\n";
    
    testMethod('ping', new stdClass(), $requestedVersion, $sessionId, $requestId++, $results);
    
    echo "TEST: notifications/progress\n";
    $notifResult = mcpNotification('notifications/progress', [
        'progressToken' => 'test-123',
        'progress' => 50,
        'total' => 100
    ], $requestedVersion, $sessionId);
    if ($notifResult['status'] == 202) {
        if (trim($notifResult['body_raw']) === '') {
            echo "✅ PASS\n\n";
            $results['notifications/progress'] = 'PASS';
        } else {
            echo "❌ FAIL: HTTP 202 must have empty body (spec violation)\n\n";
            $results['notifications/progress'] = "FAIL - 202 with body";
        }
    } else {
        echo "❌ FAIL: HTTP {$notifResult['status']}\n\n";
        $results['notifications/progress'] = "FAIL - HTTP {$notifResult['status']}";
    }
    
    echo "TEST: notifications/cancelled\n";
    $notifResult = mcpNotification('notifications/cancelled', [
        'requestId' => 999,
        'reason' => 'test'
    ], $requestedVersion, $sessionId);
    if ($notifResult['status'] == 202) {
        if (trim($notifResult['body_raw']) === '') {
            echo "✅ PASS\n\n";
            $results['notifications/cancelled'] = 'PASS';
        } else {
            echo "❌ FAIL: HTTP 202 must have empty body (spec violation)\n\n";
            $results['notifications/cancelled'] = "FAIL - 202 with body";
        }
    } else {
        echo "❌ FAIL: HTTP {$notifResult['status']}\n\n";
        $results['notifications/cancelled'] = "FAIL - HTTP {$notifResult['status']}";
    }
    
    // Test tools if capability exists
    if (isset($serverCapabilities['tools'])) {
        echo "\nTOOLS\n";
        echo str_repeat('-', 80) . "\n\n";
        
        $tools = testMethod('tools/list', new stdClass(), $requestedVersion, $sessionId, $requestId++, $results);
        
        if (is_array($tools) && count($tools) > 0) {
            echo "Found " . count($tools) . " tools:\n";
            foreach ($tools as $tool) {
                echo "  - {$tool['name']}\n";
            }
            echo "\n";
            
            // Try calling first tool
            echo "TEST: tools/call (calling {$tools[0]['name']})\n";
            $args = new stdClass();
            if (isset($tools[0]['inputSchema']['properties'])) {
                foreach ($tools[0]['inputSchema']['properties'] as $prop => $schema) {
                    $args->$prop = 'test';
                }
            }
            testMethod('tools/call', ['name' => $tools[0]['name'], 'arguments' => $args], 
                       $requestedVersion, $sessionId, $requestId++, $results);
        } else {
            echo "No tools available\n";
            $results['tools/call'] = 'SKIP - no tools';
            echo "\n";
        }
        
        echo "TEST: notifications/tools/list_changed\n";
        $notifResult = mcpNotification('notifications/tools/list_changed', new stdClass(), $requestedVersion, $sessionId);
        if ($notifResult['status'] == 202) {
            if (trim($notifResult['body_raw']) === '') {
                echo "✅ PASS\n\n";
                $results['notifications/tools/list_changed'] = 'PASS';
            } else {
                echo "❌ FAIL: HTTP 202 must have empty body (spec violation)\n\n";
                $results['notifications/tools/list_changed'] = "FAIL - 202 with body";
            }
        } else {
            echo "❌ FAIL: HTTP {$notifResult['status']}\n\n";
            $results['notifications/tools/list_changed'] = "FAIL - HTTP {$notifResult['status']}";
        }
    } else {
        $results['tools/list'] = 'UNSUPPORTED';
        $results['tools/call'] = 'UNSUPPORTED';
        $results['notifications/tools/list_changed'] = 'UNSUPPORTED';
    }
    
    // Test resources if capability exists
    if (isset($serverCapabilities['resources'])) {
        echo "\nRESOURCES\n";
        echo str_repeat('-', 80) . "\n\n";
        
        $resources = testMethod('resources/list', new stdClass(), $requestedVersion, $sessionId, $requestId++, $results);
        
        if (is_array($resources) && count($resources) > 0) {
            echo "Found " . count($resources) . " resources:\n";
            foreach ($resources as $resource) {
                echo "  - {$resource['uri']}\n";
            }
            echo "\n";
            
            echo "TEST: resources/read (reading {$resources[0]['uri']})\n";
            testMethod('resources/read', ['uri' => $resources[0]['uri']], 
                       $requestedVersion, $sessionId, $requestId++, $results);
        } else {
            echo "No resources available\n";
            $results['resources/read'] = 'SKIP - no resources';
            echo "\n";
        }
        
        testMethod('resources/templates/list', new stdClass(), $requestedVersion, $sessionId, $requestId++, $results);
        
        // Test subscribe if capability exists
        if (isset($serverCapabilities['resources']['subscribe']) && $serverCapabilities['resources']['subscribe']) {
            if (is_array($resources) && count($resources) > 0) {
                echo "\nTEST: resources/subscribe (subscribing to {$resources[0]['uri']})\n";
                testMethod('resources/subscribe', ['uri' => $resources[0]['uri']], 
                           $requestedVersion, $sessionId, $requestId++, $results);
                
                echo "TEST: resources/unsubscribe (unsubscribing from {$resources[0]['uri']})\n";
                testMethod('resources/unsubscribe', ['uri' => $resources[0]['uri']], 
                           $requestedVersion, $sessionId, $requestId++, $results);
            } else {
                $results['resources/subscribe'] = 'SKIP - no resources';
                $results['resources/unsubscribe'] = 'SKIP - no resources';
            }
        } else {
            $results['resources/subscribe'] = 'UNSUPPORTED';
            $results['resources/unsubscribe'] = 'UNSUPPORTED';
        }
        
        echo "\nTEST: notifications/resources/list_changed\n";
        $notifResult = mcpNotification('notifications/resources/list_changed', new stdClass(), $requestedVersion, $sessionId);
        if ($notifResult['status'] == 202) {
            if (trim($notifResult['body_raw']) === '') {
                echo "✅ PASS\n\n";
                $results['notifications/resources/list_changed'] = 'PASS';
            } else {
                echo "❌ FAIL: HTTP 202 must have empty body (spec violation)\n\n";
                $results['notifications/resources/list_changed'] = "FAIL - 202 with body";
            }
        } else {
            echo "❌ FAIL: HTTP {$notifResult['status']}\n\n";
            $results['notifications/resources/list_changed'] = "FAIL - HTTP {$notifResult['status']}";
        }
        
        echo "TEST: notifications/resources/updated\n";
        $notifResult = mcpNotification('notifications/resources/updated', ['uri' => 'test://resource'], $requestedVersion, $sessionId);
        if ($notifResult['status'] == 202) {
            if (trim($notifResult['body_raw']) === '') {
                echo "✅ PASS\n\n";
                $results['notifications/resources/updated'] = 'PASS';
            } else {
                echo "❌ FAIL: HTTP 202 must have empty body (spec violation)\n\n";
                $results['notifications/resources/updated'] = "FAIL - 202 with body";
            }
        } else {
            echo "❌ FAIL: HTTP {$notifResult['status']}\n\n";
            $results['notifications/resources/updated'] = "FAIL - HTTP {$notifResult['status']}";
        }
    } else {
        $results['resources/list'] = 'UNSUPPORTED';
        $results['resources/read'] = 'UNSUPPORTED';
        $results['resources/templates/list'] = 'UNSUPPORTED';
        $results['resources/subscribe'] = 'UNSUPPORTED';
        $results['resources/unsubscribe'] = 'UNSUPPORTED';
        $results['notifications/resources/list_changed'] = 'UNSUPPORTED';
        $results['notifications/resources/updated'] = 'UNSUPPORTED';
    }
    
    // Test prompts if capability exists
    if (isset($serverCapabilities['prompts'])) {
        echo "\nPROMPTS\n";
        echo str_repeat('-', 80) . "\n\n";
        
        $prompts = testMethod('prompts/list', new stdClass(), $requestedVersion, $sessionId, $requestId++, $results);
        
        if (is_array($prompts) && count($prompts) > 0) {
            echo "Found " . count($prompts) . " prompts:\n";
            foreach ($prompts as $prompt) {
                echo "  - {$prompt['name']}\n";
            }
            echo "\n";
            
            echo "TEST: prompts/get (getting {$prompts[0]['name']})\n";
            $args = new stdClass();
            if (isset($prompts[0]['arguments'])) {
                foreach ($prompts[0]['arguments'] as $arg) {
                    $args->{$arg['name']} = 'test';
                }
            }
            testMethod('prompts/get', ['name' => $prompts[0]['name'], 'arguments' => $args], 
                       $requestedVersion, $sessionId, $requestId++, $results);
        } else {
            echo "No prompts available\n";
            $results['prompts/get'] = 'SKIP - no prompts';
            echo "\n";
        }
        
        echo "TEST: notifications/prompts/list_changed\n";
        $notifResult = mcpNotification('notifications/prompts/list_changed', new stdClass(), $requestedVersion, $sessionId);
        if ($notifResult['status'] == 202) {
            if (trim($notifResult['body_raw']) === '') {
                echo "✅ PASS\n\n";
                $results['notifications/prompts/list_changed'] = 'PASS';
            } else {
                echo "❌ FAIL: HTTP 202 must have empty body (spec violation)\n\n";
                $results['notifications/prompts/list_changed'] = "FAIL - 202 with body";
            }
        } else {
            echo "❌ FAIL: HTTP {$notifResult['status']}\n\n";
            $results['notifications/prompts/list_changed'] = "FAIL - HTTP {$notifResult['status']}";
        }
    } else {
        $results['prompts/list'] = 'UNSUPPORTED';
        $results['prompts/get'] = 'UNSUPPORTED';
        $results['notifications/prompts/list_changed'] = 'UNSUPPORTED';
    }
    
    // Test logging if capability exists
    if (isset($serverCapabilities['logging'])) {
        echo "\nLOGGING\n";
        echo str_repeat('-', 80) . "\n\n";
        testMethod('logging/setLevel', ['level' => 'info'], $requestedVersion, $sessionId, $requestId++, $results);
    } else {
        $results['logging/setLevel'] = 'UNSUPPORTED';
    }
    
    // Test completions if capability exists (2025-03-26+)
    if ($requestedVersion >= '2025-03-26') {
        if (isset($serverCapabilities['completion'])) {
            echo "\nCOMPLETIONS\n";
            echo str_repeat('-', 80) . "\n\n";
            testMethod('completion/complete', [
                'ref' => ['type' => 'ref/prompt', 'name' => 'test'],
                'argument' => ['name' => 'query', 'value' => 'test']
            ], $requestedVersion, $sessionId, $requestId++, $results);
        } else {
            $results['completion/complete'] = 'UNSUPPORTED';
        }
        
        // Test SSE Stream Support
        echo "\nTRANSPORT FEATURES\n";
        echo str_repeat('-', 80) . "\n\n";
        
        echo "TEST: SSE Stream Support (GET)\n";
        
        $ch = curl_init($mcpUrl);
        $headers = [
            'Accept: text/event-stream',
            "MCP-Protocol-Version: $requestedVersion"
        ];
        if ($sessionId) {
            $headers[] = "Mcp-Session-Id: $sessionId";
        }
        if ($accessToken) {
            $headers[] = "Authorization: Bearer $accessToken";
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $headerText = substr($response, 0, $headerSize);
        $parsedHeaders = [];
        foreach (explode("\r\n", $headerText) as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $parsedHeaders[strtolower(trim($key))] = trim($value);
            }
        }
        
        if ($httpCode == 200 && strpos($parsedHeaders['content-type'] ?? '', 'text/event-stream') !== false) {
            echo "✅ PASS\n\n";
            $results['SSE Stream Support'] = 'PASS';
        } elseif ($httpCode == 405) {
            echo "⚠️  UNSUPPORTED (405 Method Not Allowed)\n\n";
            $results['SSE Stream Support'] = 'UNSUPPORTED';
        } else {
            echo "❌ FAIL: HTTP $httpCode, Content-Type: " . ($parsedHeaders['content-type'] ?? 'missing') . "\n\n";
            $results['SSE Stream Support'] = 'FAIL';
        }
    }
    
    $allResults[$requestedVersion] = $results;
    echo "\n";
}

// ============================================================================
// SUMMARY
// ============================================================================
echo str_repeat('=', 80) . "\n";
echo "SUMMARY\n";
echo str_repeat('=', 80) . "\n\n";

foreach ($allResults as $version => $results) {
    echo "Protocol Version: $version\n";
    echo str_repeat('-', 80) . "\n";
    
    $passed = $failed = $skipped = 0;
    
    foreach ($results as $method => $status) {
        if (strpos($status, 'PASS') === 0) {
            $passed++;
            $emoji = '✅';
        } elseif (strpos($status, 'FAIL') === 0) {
            $failed++;
            $emoji = '❌';
        } else {
            $skipped++;
            $emoji = '⚠️ ';
        }
        
        echo sprintf("  %s %-35s %s\n", $emoji, $method, $status);
    }
    
    echo str_repeat('-', 80) . "\n";
    echo sprintf("  PASSED: %d  |  FAILED: %d  |  SKIPPED: %d\n\n", $passed, $failed, $skipped);
}

echo str_repeat('=', 80) . "\n";
echo "TEST COMPLETE\n";
echo str_repeat('=', 80) . "\n";

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

function validateJsonRpcResponse($body, $expectedId, $method) {
    // Check if body is an array (should be object)
    if (!is_array($body)) {
        return "Response is not a JSON object";
    }
    
    // Required: jsonrpc field must be "2.0"
    if (!isset($body['jsonrpc'])) {
        return "Missing required 'jsonrpc' field";
    }
    if ($body['jsonrpc'] !== '2.0') {
        return "Invalid jsonrpc version: {$body['jsonrpc']} (must be '2.0')";
    }
    
    // Required: id field must match request
    if (!isset($body['id'])) {
        return "Missing required 'id' field";
    }
    if ($body['id'] !== $expectedId) {
        return "ID mismatch: expected $expectedId, got {$body['id']}";
    }
    
    // Must have either result or error, but not both
    $hasResult = isset($body['result']);
    $hasError = isset($body['error']);
    
    if (!$hasResult && !$hasError) {
        return "Response must have either 'result' or 'error' field";
    }
    
    if ($hasResult && $hasError) {
        return "Response cannot have both 'result' and 'error' fields";
    }
    
    // If error, validate error structure
    if ($hasError) {
        if (!isset($body['error']['code'])) {
            return "Error missing required 'code' field";
        }
        if (!isset($body['error']['message'])) {
            return "Error missing required 'message' field";
        }
        if (!is_int($body['error']['code'])) {
            return "Error code must be an integer";
        }
    }
    
    return true;
}

function testMethod($method, $params, $version, $sessionId, $requestId, &$results) {
    echo "TEST: $method\n";
    echo "REQUEST:\n";
    echo json_encode(['jsonrpc' => '2.0', 'id' => $requestId, 'method' => $method, 'params' => $params], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
    
    $result = mcpRequest($method, $params, $requestId, $version, $sessionId);
    
    echo "IMMEDIATE RESPONSE (HTTP {$result['status']}):\n";
    if ($result['status'] == 200 || $result['status'] == 202) {
        echo json_encode($result['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
    } else {
        echo "Status: {$result['status']}\n";
        echo "Body: " . $result['body_raw'] . "\n\n";
    }
    
    // SPEC VALIDATION: Requests (with id) MUST NOT return 202
    if ($result['status'] == 202) {
        $bodyEmpty = trim($result['body_raw']) === '';
        echo "❌ FAIL: HTTP 202 invalid for requests with id (spec violation)\n";
        echo "   Spec requires: Content-Type application/json OR text/event-stream\n";
        if (!$bodyEmpty) {
            echo "   Additional violation: 202 body must be empty\n";
            echo "   Body received: " . substr($result['body_raw'], 0, 100) . "\n";
        }
        
        // BUT - let's try to read the SSE anyway to see if data is actually there
        echo "   Attempting to read SSE despite spec violation...\n";
        $sseResponse = readQueuedResponse($sessionId, $version, $requestId);
        
        if ($sseResponse && isset($sseResponse['result'])) {
            echo "   ✅ Data retrieved from SSE (functionality works, transport wrong)\n\n";
            $results[$method] = 'FAIL - 202 for request (data via SSE)';
            return extractData($method, $sseResponse['result']);
        } elseif ($sseResponse && isset($sseResponse['error'])) {
            $error = $sseResponse['error'];
            if ($error['code'] == -32601) {
                echo "   ⚠️  Method not found\n\n";
                $results[$method] = 'UNSUPPORTED';
            } else {
                echo "   ❌ Error {$error['code']}: {$error['message']}\n\n";
                $results[$method] = "FAIL - Error {$error['code']}";
            }
            return null;
        } else {
            echo "   ❌ Could not retrieve data from SSE\n\n";
            $results[$method] = 'FAIL - 202 for request (no SSE data)';
            return null;
        }
    }
    
    // Handle direct 200 response with JSON
    if ($result['status'] == 200 && isset($result['headers']['content-type'])) {
        $contentType = $result['headers']['content-type'];
        
        // Direct JSON response
        if (strpos($contentType, 'application/json') !== false) {
            // Validate it's a proper JSON-RPC response
            if (!isset($result['body']['result']) && !isset($result['body']['error'])) {
                echo "❌ FAIL: application/json response missing JSON-RPC result/error\n";
                echo "   Body: " . json_encode($result['body']) . "\n\n";
                $results[$method] = 'FAIL - malformed JSON-RPC';
                return null;
            }
            
            // Validate JSON-RPC 2.0 structure
            $validation = validateJsonRpcResponse($result['body'], $requestId, $method);
            if ($validation !== true) {
                echo "❌ FAIL: Invalid JSON-RPC structure: $validation\n\n";
                $results[$method] = "FAIL - $validation";
                return null;
            }
            
            if (isset($result['body']['result'])) {
                echo "✅ PASS (direct JSON response)\n\n";
                $results[$method] = 'PASS';
                return extractData($method, $result['body']['result']);
            }
            
            if (isset($result['body']['error'])) {
                $error = $result['body']['error'];
                if ($error['code'] == -32601) {
                    echo "⚠️  Method not found\n\n";
                    $results[$method] = 'UNSUPPORTED';
                } else {
                    echo "❌ FAIL: Error {$error['code']}: {$error['message']}\n\n";
                    $results[$method] = "FAIL - Error {$error['code']}";
                }
                return null;
            }
        }
        
        // SSE stream response
        if (strpos($contentType, 'text/event-stream') !== false) {
            echo "✅ PASS (SSE stream response)\n\n";
            $results[$method] = 'PASS (SSE)';
            return null;
        }
    }
    
    // Handle direct 200 response without proper content-type
    if ($result['status'] == 200) {
        // Check if it has a JSON body with result/error
        if (isset($result['body']['result']) || isset($result['body']['error'])) {
            // Validate JSON-RPC structure
            $validation = validateJsonRpcResponse($result['body'], $requestId, $method);
            if ($validation !== true) {
                echo "❌ FAIL: Invalid JSON-RPC structure: $validation\n\n";
                $results[$method] = "FAIL - $validation";
                return null;
            }
            
            if (isset($result['body']['result'])) {
                echo "✅ PASS (direct response)\n\n";
                $results[$method] = 'PASS';
                return extractData($method, $result['body']['result']);
            }
            
            if (isset($result['body']['error'])) {
                $error = $result['body']['error'];
                if ($error['code'] == -32601) {
                    echo "⚠️  Method not found\n\n";
                    $results[$method] = 'UNSUPPORTED';
                } else {
                    echo "❌ FAIL: Error {$error['code']}: {$error['message']}\n\n";
                    $results[$method] = "FAIL - Error {$error['code']}";
                }
                return null;
            }
        }
        
        // 200 but no valid JSON-RPC structure - might be SSE stream that we need to read
        echo "Got 200 without valid JSON-RPC structure - checking SSE stream...\n";
        
        $sseResponse = readQueuedResponse($sessionId, $version, $requestId);
        
        if ($sseResponse && isset($sseResponse['result'])) {
            echo "✅ PASS (via SSE)\n\n";
            $results[$method] = 'PASS (SSE)';
            return extractData($method, $sseResponse['result']);
        } elseif ($sseResponse && isset($sseResponse['error'])) {
            $error = $sseResponse['error'];
            if ($error['code'] == -32601) {
                echo "⚠️  Method not found\n\n";
                $results[$method] = 'UNSUPPORTED';
            } else {
                echo "❌ FAIL: Error {$error['code']}: {$error['message']}\n\n";
                $results[$method] = "FAIL - Error {$error['code']}";
            }
        }
        return null;
    }
    
    echo "❌ FAIL: HTTP {$result['status']}\n\n";
    $results[$method] = "FAIL - HTTP {$result['status']}";
    return null;
}

function extractData($method, $result) {
    if ($method == 'tools/list' && isset($result['tools'])) {
        return $result['tools'];
    }
    if ($method == 'resources/list' && isset($result['resources'])) {
        return $result['resources'];
    }
    if ($method == 'prompts/list' && isset($result['prompts'])) {
        return $result['prompts'];
    }
    return $result;
}

function readQueuedResponse($sessionId, $version, $expectedId, $timeout = 10) {
    global $mcpUrl, $accessToken;
    
    $ch = curl_init($mcpUrl);
    
    $headers = [
        'Accept: text/event-stream',
        "MCP-Protocol-Version: $version"
    ];
    if ($sessionId) {
        $headers[] = "Mcp-Session-Id: $sessionId";
    }
    if ($accessToken) {
        $headers[] = "Authorization: Bearer $accessToken";
    }
    
    echo "   Sending SSE GET with headers:\n";
    foreach ($headers as $h) {
        echo "      $h\n";
    }
    
    // Accumulate the full response body
    $responseBody = '';
    $foundMessage = null;
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $chunk) use (&$responseBody, &$foundMessage, $expectedId) {
        $responseBody .= $chunk;
        
        // Try to find a complete JSON-RPC RESPONSE (has "id" field) in SSE format
        if (preg_match('/data:\s*(\{[^}]*"jsonrpc"[^}]*\}.*)$/m', $responseBody, $matches)) {
            $jsonStr = trim($matches[1]);
            // Find the closing brace for this JSON object
            $depth = 0;
            $endPos = 0;
            for ($i = 0; $i < strlen($jsonStr); $i++) {
                if ($jsonStr[$i] === '{') $depth++;
                if ($jsonStr[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $endPos = $i + 1;
                        break;
                    }
                }
            }
            
            if ($endPos > 0) {
                $jsonStr = substr($jsonStr, 0, $endPos);
                $json = json_decode($jsonStr, true);
                // Only capture RESPONSES with matching ID
                if ($json && isset($json['jsonrpc']) && isset($json['id']) && $json['id'] == $expectedId) {
                    $foundMessage = $json;
                    return 0; // Stop transfer
                }
            }
        }
        
        return strlen($chunk);
    });
    
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "   SSE GET returned HTTP $httpCode\n";
    echo "   Response body length: " . strlen($responseBody) . " bytes\n";
    
    echo "\n   RAW SSE RESPONSE:\n";
    echo "   " . str_repeat('-', 76) . "\n";
    $displayLines = explode("\n", substr($responseBody, 0, 2000));
    foreach ($displayLines as $line) {
        echo "   " . $line . "\n";
    }
    if (strlen($responseBody) > 2000) {
        echo "   ... (" . (strlen($responseBody) - 2000) . " more bytes)\n";
    }
    echo "   " . str_repeat('-', 76) . "\n\n";
    
    if ($foundMessage) {
        echo "   ✅ Found JSON-RPC message in callback\n";
        // Validate the JSON-RPC structure
        $validation = validateJsonRpcResponse($foundMessage, $expectedId, 'SSE response');
        if ($validation !== true) {
            echo "   ❌ Invalid JSON-RPC structure: $validation\n\n";
            return null;
        }
        echo "   MESSAGE:\n";
        echo "   " . json_encode($foundMessage, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
        return $foundMessage;
    }
    
    // If we didn't find it in the callback, try parsing what we have
    $lines = explode("\n", $responseBody);
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, 'data:') === 0) {
            $data = trim(substr($line, 5));
            $json = json_decode($data, true);
            // Only capture RESPONSES with matching ID
            if ($json && isset($json['jsonrpc']) && isset($json['id']) && $json['id'] == $expectedId) {
                echo "   ✅ Found JSON-RPC message in final parse\n";
                // Validate the JSON-RPC structure
                $validation = validateJsonRpcResponse($json, $expectedId, 'SSE response');
                if ($validation !== true) {
                    echo "   ❌ Invalid JSON-RPC structure: $validation\n\n";
                    return null;
                }
                echo "   MESSAGE:\n";
                echo "   " . json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
                return $json;
            }
        }
    }
    
    echo "   ⚠️  No JSON-RPC message found\n";
    echo "   First 500 chars: " . substr($responseBody, 0, 500) . "\n\n";
    return null;
}

function mcpRequest($method, $params, $id, $version, $sessionId = null) {
    global $mcpUrl, $accessToken;
    
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json, text/event-stream',
        "MCP-Protocol-Version: $version"
    ];
    
    if ($sessionId) {
        $headers[] = "Mcp-Session-Id: $sessionId";
    }
    if ($accessToken) {
        $headers[] = "Authorization: Bearer $accessToken";
    }
    
    $body = json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => $method,
        'params' => $params
    ]);
    
    return httpPost($mcpUrl, $headers, $body);
}

function mcpNotification($method, $params, $version, $sessionId = null) {
    global $mcpUrl, $accessToken;
    
    $headers = [
        'Content-Type: application/json',
        "MCP-Protocol-Version: $version"
    ];
    
    if ($sessionId) {
        $headers[] = "Mcp-Session-Id: $sessionId";
    }
    if ($accessToken) {
        $headers[] = "Authorization: Bearer $accessToken";
    }
    
    $body = json_encode([
        'jsonrpc' => '2.0',
        'method' => $method,
        'params' => $params
    ]);
    
    return httpPost($mcpUrl, $headers, $body);
}

function httpPost($url, $headers, $body, $timeout = 5) {
    $ch = curl_init($url);
    
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    
    $headerText = substr($response, 0, $headerSize);
    $bodyText = substr($response, $headerSize);
    
    // Parse headers
    $parsedHeaders = [];
    foreach (explode("\r\n", $headerText) as $line) {
        if (strpos($line, ':') !== false) {
            list($key, $value) = explode(':', $line, 2);
            $parsedHeaders[strtolower(trim($key))] = trim($value);
        }
    }
    
    return [
        'status' => $httpCode,
        'headers' => $parsedHeaders,
        'raw_headers' => $headerText,
        'body_raw' => $bodyText,
        'body' => json_decode($bodyText, true)
    ];
}

function httpGet($url, $timeout = 5) {
    $ch = curl_init($url);
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    
    $headerText = substr($response, 0, $headerSize);
    $bodyText = substr($response, $headerSize);
    
    return [
        'status' => $httpCode,
        'raw_headers' => $headerText,
        'body_raw' => $bodyText
    ];
}
