# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2025-06-17
### Added
- Initial release
- OAuth 2.1 authentication
- Server-Sent Events transport
- Database and memory storage backends
- Slim Framework integration

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
