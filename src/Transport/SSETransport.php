<?php

namespace Seolinkmap\Waasup\Transport;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\StreamInterface;
use Seolinkmap\Waasup\Storage\StorageInterface;
use Slim\Psr7\NonBufferedBody;

/**
 * Handles SSE transport for real-time message delivery
 */
class SSETransport implements TransportInterface
{
    private StorageInterface $storage;
    private array $config; // config array (master in MCPSaaSServer::getDefaultConfig())

    public function __construct(StorageInterface $storage, array $config = [])
    {
        $this->storage = $storage;
        $this->config = array_replace_recursive($this->getDefaultConfig(), $config);
    }

    /**
     * Handle SSE connection
     */
    public function handleConnection(
        Request $request,
        Response $response,
        string $sessionId,
        array $context
    ): Response {
        // CRITICAL: Close session to prevent blocking - PHP sessions are file-locked
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Check if we're in a test environment for shortened behavior
        $isTestMode = $this->config['test_mode'];

        // Set low process priority (only in non-test environments)
        if (!$isTestMode && function_exists('exec')) {
            exec('renice 10 ' . getmypid());
        }

        $response = $response
            ->withBody(new NonBufferedBody())
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('X-Accel-Buffering', 'no')
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Mcp-Session-Id, MCP-Protocol-Version')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');

        $body = $response->getBody();

        // Send endpoint event immediately (MCP spec requirement)
        $this->sendEndpointEvent($body, $sessionId, $context);

        // In test mode, send any pending messages and return immediately
        if ($isTestMode) {
            $this->checkAndSendMessages($body, $sessionId);
            return $response;
        }

        // Start message polling loop (production mode only)
        $this->pollForMessages($body, $sessionId, $context);

        return $response;
    }

    private function sendEndpointEvent(StreamInterface $body, string $sessionId, array $context): void
    {
        // Build endpoint URL from context
        $baseUrl = $context['base_url'] ?? 'https://localhost';
        $contextId = $context['context_id'] ?? 'unknown';
        $endpointUrl = "{$baseUrl}/{$contextId}";

        if (!empty($sessionId)) {
            $endpointUrl = $endpointUrl . "/{$sessionId}";
        }

        $endpointData = sprintf(
            "event: endpoint\ndata: %s\n\n",
            $endpointUrl
        );

        $body->write($endpointData);
    }

    private function pollForMessages(StreamInterface $body, string $sessionId, array $context): void
    {
        $startTime = time();
        $pollInterval = $this->config['sse']['keepalive_interval'];
        $maxTime = $this->config['sse']['max_connection_time'];
        $switchTime = $this->config['sse']['switch_interval_after'];
        $endTime = $startTime + $maxTime;

        while (time() < $endTime && connection_status() === CONNECTION_NORMAL) {
            // Send keepalive
            $this->sendKeepalive($body);

            if (connection_aborted()) {
                break;
            }

            // Check for messages and send them
            if ($this->checkAndSendMessages($body, $sessionId)) {
                $startTime = time(); // Reset timer on activity
            }

            // Adjust polling interval after initial period
            $currentTime = time();
            if ($currentTime - $startTime > $switchTime) {
                $pollInterval = max($pollInterval * 2, 5); // Increase interval, max 5 seconds
            }

            sleep($pollInterval);
        }
    }

    private function sendKeepalive(StreamInterface $body): void
    {
        $keepaliveData = ": keepalive\n\n";
        $body->write($keepaliveData);
    }

    private function checkAndSendMessages(StreamInterface $body, string $sessionId): bool
    {
        $messages = $this->storage->getMessages($sessionId);

        if (empty($messages)) {
            return false;
        }

        foreach ($messages as $message) {
            $messageData = sprintf(
                "event: message\ndata: %s\n\n",
                json_encode($message['data'])
            );

            $body->write($messageData);

            // Delete message after sending
            $this->storage->deleteMessage($message['id']);
        }

        // We know messages were sent since $messages wasn't empty
        return true;
    }

    private function getDefaultConfig(): array
    {
        return [
            'test_mode' => false,              // set to true in tests for instant responses
            'sse' => [
                'keepalive_interval' => 1,     // seconds
                'max_connection_time' => 1800, // 30 minutes
                'switch_interval_after' => 60  // switch to longer intervals after 1 minute
            ]
        ];
    }
}
