# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.0] - Unreleased
### Added
- **MCP 2025-11-25**: negotiated alongside 2024-11-05, 2025-03-26 and 2025-06-18, with its own feature gates. Adds `icons` on tools, prompts, resources, resource templates and `serverInfo`, URL mode elicitation (pass `['url' => ...]` as the new `$options` argument of `requestElicitation()`), and `tools`/`toolChoice` on sampling requests. A client asking for a newer revision than this server speaks is answered with 2025-11-25.
- **`resources/subscribe` and `resources/unsubscribe`**: the `subscribe` capability the handshake has always advertised now works. Subscriptions are held in the session record, and `MCPSaaSServer::notifyResourceUpdated()` sends `notifications/resources/updated` only to sessions that subscribed to that URI.
- **`logging/setLevel`**: the `logging` capability now works. `MCPSaaSServer::sendLogMessage()` emits `notifications/message`, dropping anything below the severity the client asked for and sending nothing at all until a client sets a level.
- **`MCPSaaSServer::notifyListChanged()`**: sends `notifications/tools/list_changed`, `notifications/prompts/list_changed` or `notifications/resources/list_changed`, so the `listChanged` capability is backed by something an implementer can call.
- **`MCPSaaSServer::sendProgressNotification()`**: progress reporting is reachable from the server object instead of only from `MessageHandler`, and takes an optional `$total`.
- **Client responses to server requests**: a JSON-RPC result or error carrying the id of a `sampling/createMessage`, `elicitation/create` or `roots/list` request the server sent is now accepted and stored. Previously only a non-standard request shape was understood, so no conformant client could answer one.
- **`completion/complete`**: the specified method name is served. `completions/complete` keeps working. Values come from the `enum` declared for that argument in the registered tool or prompt schema.
- **`MCPSaaSServer::requestSampling()`, `requestElicitation()` and `requestRootsList()`**: the server to client requests existed only on `Protocol\MessageHandler`, which `MCPSaaSServer` holds privately with no accessor, so no application could reach them. They are now on the server object alongside the notification senders.
- **Incremental scope consent**: a valid token that lacks a required scope is answered with `403` and `WWW-Authenticate: Bearer error="insufficient_scope", scope="...", resource_metadata="...", error_description="..."`, where it previously returned the same `401` as an absent token. The `scope` challenge carries the scopes already granted alongside those required, so a step-up authorization does not drop existing permissions. The `401` challenge now also carries a `scope` parameter naming the scopes to request.
- **OpenID Connect Discovery 1.0 endpoint**: `WellKnownProvider::openidConfiguration()` serves the authorization server metadata at `/.well-known/openid-configuration`, alongside the RFC 8414 endpoint, for MCP clients that probe that location. Both documents carry `code_challenge_methods_supported`. `SlimMCPProvider::handleOpenIdDiscovery()` exposes it for Slim.
- **Tasks (2025-11-25, experimental)**: `tools/call` accepts a `task` parameter and answers with a `CreateTaskResult`, and `tasks/get`, `tasks/result`, `tasks/cancel` and `tasks/list` serve the task lifecycle. Tools opt in through `execution.taskSupport` (`optional` or `required`); a tool that does not declare it refuses task augmentation with `-32601`, and a `required` tool refuses a plain call the same way. Tasks are held on the session, so they are bound to the authorization context the session was opened with, and are pruned by their `ttl`. `tasks.default_ttl`, `tasks.max_ttl`, `tasks.poll_interval` and `tasks.max_retained` configure them. The wrapped call is executed during the originating request, so the `CreateTaskResult` carries the terminal status the call reached rather than `working`. `tasks/result` returns exactly what the call produced, with `io.modelcontextprotocol/related-task` metadata attached; a terminal task refuses cancellation with `-32602`, as does an unknown or expired task id.
- **SSE stream resumability**: every SSE event now carries an `id`, and a client reconnecting with `Last-Event-ID` is replayed only what it missed. Messages are retained until `cleanup()` prunes them by `database.message_lifetime` instead of being deleted the moment they are written, so a dropped connection no longer loses the message that was in flight. A reconnect without the header resumes from the point the session last reached.
- **`Accept` negotiation**: a request whose `Accept` header cannot accommodate what the endpoint produces is answered with `406` instead of a response the client asked not to receive. A request without the header is unaffected.
- **`StorageInterface::revokeTokenFamily()`**: withdraws every live token issued to the same client and user as a given refresh token. Implemented by `DatabaseStorage` and `MemoryStorage`.
- **`auth.allowed_origins`**: an explicit origin allowlist for the Origin check. Empty keeps the previous behaviour of trusting any origin except a cross-origin request to a loopback host.
- **`database.message_lifetime`**: how long an undelivered message survives `cleanup()`.
- **`title` on tools, prompts, resources and resource templates**: the display name the 2025-06-18 specification defines is now sent for both registration styles, from `getTitle()` on a registered instance or a `title` key in a callable's schema, and withheld from older versions. It was previously dropped.
- **`instructions`**: optional server instructions returned by `initialize`.

### Fixed
- **Tool results are no longer re-serialized**: `tools/call` returned every tool result as a single text block containing the JSON encoding of the whole return value, so image, audio, resource link and embedded resource blocks could never reach a client and the audio pipeline was unreachable. A tool that returns `content` now has it passed through unchanged. A tool that returns anything else keeps the serialized-JSON text block.
- **Tool failures report `isError`**: an exception inside a tool produced a successful result reading "Tool execution failed", which told the model the tool had worked. It now returns `isError: true` with the reason. An unknown tool name is a `-32602` protocol error instead.
- **Error messages survive**: every protocol error was replaced with a single generic sentence and answered with `"id": null`. The thrown message is now returned as written and the response carries the id of the request that failed. The generic sentence is kept for the unauthenticated case it was written for.
- **Stale sessions answer 404**: an expired or unknown `Mcp-Session-Id` returned 400, which no client treats as recoverable. It now returns 404, the signal to start a new session with `initialize`.
- **HTTP+SSE (2024-11-05) can complete its handshake**: the transport opens with GET and learns where to POST from the endpoint event, but GET required a session that only a POST could create. GET now mints the session, and `initialize` adopts the session an open stream is already using instead of minting a second one.
- **`DELETE` ends a session**: it was answered with 400. It now terminates the session and returns 204. Any other method returns 405.
- **Invalid Origin answers 403** rather than 400.
- **Server capabilities**: `initialize` advertised `sampling`, `roots` and `elicitation`, which are client capabilities. They are no longer sent, and the client's own capabilities are recorded on the session.
- **`ping`** returns an empty result rather than a status and timestamp.
- **Progress notifications carry `progressToken`**: they were sent without the required token and with a hard-coded `total` of 100, so clients dropped them. The token is taken from the `_meta` of the request being handled, and nothing is sent for a request that did not ask for progress.
- **`notifications/cancelled` cancels one request**: it deleted every queued message for the session, discarding unrelated pending responses. It now drops only the message for the given `requestId`.
- **Prompt and resource failures are errors**: `prompts/get` returned a fabricated assistant message and `resources/read` returned contents reading "Resource read failed", both as successful results. They now return JSON-RPC errors, `-32002` for a resource that does not exist.
- **`inputSchema` is valid JSON Schema**: a tool with no parameters emitted `"properties": []`, a JSON array where a schema object is required. It now emits `{"type": "object", "additionalProperties": false}`.
- **Tool annotations are not invented**: every callable tool was advertised as `readOnlyHint: true`, `destructiveHint: false`, a claim clients use to decide what can run without asking the user. Hints are now sent only when the implementer declares them. `PingTool` and `ServerInfoTool` declare their own.
- **Resource links**: `_meta.resourceLinks` was returned as a non-standard top-level result key. Those links are now emitted as `resource_link` content blocks.
- **`completion/complete` no longer fatals**: a spec-shaped `argument` object raised an uncaught `TypeError`. The result also had the wrong shape, returning `completions` instead of `completion` with `values`, `total` and `hasMore`, and its values were placeholders.
- **`completion/complete` handles `ref/resource`**: only `ref/prompt` and the non-standard `ref/tool` were served, while the error message claimed `ref/resource` was accepted. A resource reference now resolves the registered template matching its `uri` and completes from the `enum` declared for that variable in the template's `inputSchema`.
- **`structuredContent` is checked against `outputSchema`**: a tool could declare an output schema and return anything, or nothing. A tool that declares one and returns structured content that does not match, or none at all, now fails with `-32603` naming the offending property. The check covers the declared type, required properties and each declared property's type; nested subschemas and composition keywords are not checked.
- **Refresh token reuse revokes the token family**: replaying a rotated refresh token returned `invalid_grant` and left the rest of the client's tokens usable. Reuse is now treated as a possible theft and every live token for that client and user is revoked, as the OAuth 2.1 security guidance requires.
- **Resource binding is checked against the endpoint, not the host**: with `base_url` configured the identifier dropped the request path, so a token bound to one tenant matched every endpoint on the server. The identifier now includes the request path, and a token bound to a different tenant is refused. When a token carries a binding and `base_url` is not configured, the request is refused rather than validated against a host the caller chose.
- **Tool names are validated at registration**: `register()` and `registerTool()` reject a name that is empty, longer than 128 characters, or contains anything but letters, digits, underscore, hyphen and dot.
- **Tool result `_meta` reaches the client**: anything a tool returns under `_meta`, other than the library's own `structured` and `resourceLinks` keys, is passed through on the result.
- **`serverInfo` fields are version gated**: `title` is withheld before 2025-06-18 and `description`, `websiteUrl` and `icons` before 2025-11-25.
- **`MemoryStorage::getTokenByRefreshToken()` skips revoked tokens**, matching `DatabaseStorage`. It returned them, so refresh rotation could not be detected under the in-memory driver.
- **Pagination on the four list methods**: `tools/list`, `prompts/list`, `resources/list` and `resources/templates/list` accept a `cursor` and return a `nextCursor` while more results remain, omitting it on the final page. A cursor the server did not issue is answered with `-32602`, where it was previously accepted and silently ignored. `pagination.page_size` sets the page size, 50 by default, and 0 returns every item in one response as before.
- **Duplicate request ids are refused for the whole session**: the seen ids lived in a per-process array, so under PHP-FPM a replayed id was only caught when both requests landed in the same worker, or in the same batch. They are recorded in the session record now, bounded to the most recent `MessageHandler::SEEN_REQUEST_ID_LIMIT` ids. The session write this needs is combined with the one that records the request's progress token, so it costs no extra round trip.
- **Server to client requests respect the negotiated capabilities**: `requestSampling()`, `requestElicitation()` and the `requestRoots*()` methods would queue a request to a client that never declared `sampling`, `elicitation` or `roots` during `initialize`, which the client is then entitled to ignore. They now refuse with a message naming the missing capability.
- **Resource binding is always enforced**: audience and resource checks ran only when the client sent `MCP-Protocol-Version: 2025-06-18`, so omitting a header the caller controls skipped them. They now run on every request, compare on a path boundary instead of by prefix, and reject an audience that is not an absolute URL. A token's binding comes from the authorization code again rather than being overwritten with the configured base URL.
- **PKCE is required and S256 only**: `/oauth/authorize` accepted a request with no `code_challenge`, and the token endpoint fell back to the `plain` method, which leaves the verifier readable in the authorization request.
- **Authorization forms carry a CSRF token**: the consent and sign in forms were posted with nothing but the session cookie, so a third party page could submit the consent decision on a signed in user's behalf.
- **Google sign in validates `state`**: the LinkedIn and GitHub callbacks validated it, the Google one had none. `validateState()` also accepts a missing value instead of raising a `TypeError`.
- **Authorization codes are single use**: the code was revoked without checking whether it already had been, so two concurrent exchanges could both mint tokens.
- **Client secrets compare in constant time**, and `/oauth/revoke` authenticates the client.
- **Registration answers 201** with `client_id_issued_at`, `client_secret_expires_at` and `redirect_uris`, which RFC 7591 requires alongside an issued secret.
- **Authorization responses carry `iss`** (RFC 9207), which the discovery metadata already claimed.
- **Requested scopes are validated** against `scopes_supported` instead of being granted as asked.
- **`auth.required_scopes` default**: the master config shipped `['mcp:read mcp:write']`, one string containing a space, which no token could ever satisfy.
- **`cleanup()` prunes messages**: only sessions were deleted, so messages queued for a stream that never returned accumulated forever. Message delivery order also breaks ties on id rather than on a whole-second timestamp.
- **Resource template matching escapes the pattern**, so a `.` in a template no longer matches any character.
- **The keepalive on the streamable transport** is an SSE comment instead of a `notifications/ping` JSON-RPC message, which is not a method the protocol defines.

### Changed
- **Breaking (behaviour)**: tools that return `content` now have it delivered as written. A tool relying on the whole return value being JSON-encoded into one text block is unaffected only if it does not use a `content` key.
- **Breaking (OAuth)**: clients that do not send `code_challenge` with `code_challenge_method=S256` can no longer complete authorization, and applications rendering their own consent form must post the `csrf_token` field.
- **Breaking (subclasses)**: `Auth\Middleware\AuthMiddleware::validateToken()` no longer performs the scope check. Scope enforcement moved out so an insufficient scope can be answered with `403` rather than a token-invalid `401`; a subclass overriding it need only validate the token itself.
- **Breaking (custom storage)**: implementations of `Storage\StorageInterface` must add `revokeTokenFamily(string $refreshToken): bool`, and `getMessages()` takes a third `?string $afterId = null` argument used for stream resumption.
- **Breaking (custom tools, prompts and resources)**: implementations of `Tools\ToolInterface`, `Resources\ResourceInterface` and `Prompts\PromptInterface` must add `getIcons(): array` and `getTitle(): string`. Classes extending `AbstractTool`, `AbstractResource` or `AbstractPrompt` inherit both and take optional `$icons` and `$title` constructor arguments, so they need no change.
- **Breaking (internal)**: the four list handler methods take the request `params` as their first argument, matching the other handlers, so they can read `cursor`. `Protocol\Handlers\ResourcesHandler::__construct()` and `Protocol\Handlers\PromptsHandler::__construct()` take a `Protocol\Handlers\ProtocolManager` argument. `PromptRegistry::getPromptsList()`, `ResourceRegistry::getResourcesList()` and `ResourceRegistry::getResourceTemplatesList()` take an optional protocol version, matching `ToolRegistry::getToolsList()`.

## [2.1.0] - 2026-09-10
### Added
- **Configurable OAuth lifetimes**: `oauth.access_token_lifetime`, `oauth.refresh_token_lifetime` and `oauth.authorization_code_lifetime` replace the hard-coded 3600/300 second values. Defaults are unchanged, and `access_token_lifetime` is what the token endpoint now returns as `expires_in`.
- **Sliding access token expiration**: with `oauth.sliding_expiration` enabled, each authenticated MCP request extends the presented token to `now + access_token_lifetime`, turning that value into an idle timeout so active clients keep their lease instead of being dropped an hour after login. `oauth.sliding_expiration_max_lifetime` sets an absolute ceiling measured from issue time and `oauth.sliding_expiration_interval` throttles storage writes.
- **Light/dark OAuth pages**: the sign in, consent and out-of-band authorization code screens follow the visitor's operating system color scheme, and `oauth.ui.background_color`, `oauth.ui.text_color` and `oauth.ui.accent_color` customize the palette. Each accepts a single value or a `['light' => ..., 'dark' => ...]` pair, and values that are not a valid CSS color are ignored.
- **`StorageInterface::touchAccessToken()`**: extends a stored access token's expiry. Implemented by `DatabaseStorage` and `MemoryStorage`.

### Fixed
- **Optional RFC 8707 resource parameter**: `authorize` ran its resource URL shape check even when no `resource` was supplied, raising `parse_url(): Passing null` and `str_contains(): Passing null` deprecations on every request without one. The check now runs only when the parameter is present; validation of a supplied value is unchanged.
- **`DatabaseStorage` base URL default**: constructing `DatabaseStorage` without an explicit `base_url` raised `Undefined array key "base_url"` on every `storeAccessToken()` and `storeAuthorizationCode()` call, because the class defaulted only its own `database` block. It now defaults `base_url` to `null`, which stores tokens with no resource binding as intended.
- **OAuth endpoint defaults**: `Auth\OAuthServer` now carries the same default endpoint paths as the master config, so the rendered forms post to the right URL when the application does not repeat them.
- **Page output escaping**: client names, user names, scopes and resource URLs are HTML-escaped in the rendered OAuth pages.
- **`session_lifetime` ignored**: MCP sessions were always stored with the 3600 second default. The configured value is now used.

### Changed
- **Breaking (custom storage)**: implementations of `Storage\StorageInterface` must add `touchAccessToken(string $accessToken, int $expiresAt): bool`.

## [2.0.1] - 2026-05-20
### Fixed
- **Streamable HTTP response compliance**: Responses to JSON-RPC requests are now returned inline on the originating POST with HTTP 200 for protocol versions 2025-03-26 and 2025-06-18, as required by the Streamable HTTP transport. Previously every request response was acknowledged with HTTP 202 and delivered over the GET stream, which is only correct for the HTTP+SSE transport (2024-11-05). The 2024-11-05 behavior is unchanged.
- **Batch request aggregation**: Batched requests (2025-03-26) now return the array of JSON-RPC responses inline. The previous implementation read each item's output after the stream pointer and returned an empty array. Errors raised while processing a batch item now carry the originating JSON-RPC error code and message instead of empty values.

### Changed
- **Internal**: `Protocol\Handlers\ResponseManager::__construct()` now requires a `Protocol\Handlers\ProtocolManager` argument so responses are emitted according to the negotiated protocol version's transport.

## [2.0.0] - 2025-08-09
### Added
- **Configuration Restructure**: Breaking changes to configuration structure for improved organization and granular control
- **Database Schema Extensions**: New tables and fields for enhanced functionality
- **Custom Mapping Support**: Table and field mapping for seamless integration with existing user/OAuth/SaaS systems

### Changed
- **Codebase Optimization**: Reduced file sizes and improved structure for MCP compatibility
- **Enhanced MCP Integration**: Improved code documentation and structure for better LLM context understanding



## [1.1.0] - 2025-07-23
### Added
- **Multi-Protocol Support**: Complete MCP protocol compliance for versions 2024-11-05, 2025-03-26, and 2025-06-18 with automatic feature gating
- **RFC 8707 Resource Indicators**: OAuth 2.1 resource binding for enhanced security in MCP 2025-06-18
- **Audio Content Support**: Full audio content handling in tools for protocol versions 2025-03-26+ with support for MP3, WAV, OGG, M4A, WebM, FLAC, and AAC formats
- **Elicitation API**: Structured user input requests with schema validation (MCP 2025-06-18)
- **Tool Annotations**: Enhanced tool metadata with behavioral hints (readOnlyHint, destructiveHint, idempotentHint, openWorldHint)
- **Social Authentication**: Google, LinkedIn, and GitHub OAuth providers with automatic user linking
- **Streamable HTTP Transport**: New transport method for MCP 2025-03-26+ replacing SSE for better performance
- **JSON-RPC Batching**: Batch request processing support for MCP 2025-03-26
- **Laravel Integration**: Complete Laravel service provider with controller patterns and middleware
- **Completions API**: Tool and prompt completion support for enhanced developer experience
- **Resource Templates**: Dynamic resource handling with URI template matching
- **Progress Notifications**: Enhanced progress updates with optional message field (2025-03-26+)
- **Discovery Endpoints**: OAuth Resource Server metadata and enhanced authorization server discovery
- **Built-in Tools**: PingTool and ServerInfoTool for basic server functionality testing

### Changed
- **Database Schema**: Added new tables for sampling responses, roots responses, and elicitation responses
- **Authentication Flow**: Enhanced OAuth flow with social provider integration and consent screens
- **Protocol Negotiation**: Automatic version negotiation during client initialization
- **Session Management**: Improved session handling with protocol version tracking

### Improved
- **Error Handling**: Enhanced error reporting with proper MCP error codes and protocol-specific responses
- **Security**: Added DNS rebinding protection, enhanced token validation, and audience claim verification
- **Documentation**: Comprehensive API documentation with protocol version compatibility matrix
- **Performance**: Optimized streaming connections with configurable keepalive intervals and connection timeouts
- **Developer Experience**: Better debugging with detailed logging and structured error responses

### Fixed
- **Header Validation**: Proper MCP-Protocol-Version header handling for 2025-06-18 compliance
- **Memory Management**: Improved session cleanup and memory usage in long-running connections
- **Content Processing**: Enhanced content validation and processing for mixed media types



## [1.0.0] - 2025-06-17
### Added
- Initial release
- OAuth 2.1 authentication
- Server-Sent Events transport
- Database and memory storage backends
- Slim Framework integration



## [Unreleased]
