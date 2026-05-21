# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
