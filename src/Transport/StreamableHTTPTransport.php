<?php

namespace Seolinkmap\Waasup\Transport;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Seolinkmap\Waasup\Storage\StorageInterface;
use Slim\Psr7\NonBufferedBody;

/**
 * Handles Streamable HTTP transport for MCP 2025-03-26+
 * Uses chunked HTTP streaming for full-duplex communication
 */
class StreamableHTTPTransport implements TransportInterface
{
    private LoggerInterface $logger;
    private StorageInterface $storage;
    private array $config;


    public function __construct(
        StorageInterface $storage,
        array $config = [],
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->storage = $storage;
        $this->config = array_replace_recursive($this->getDefaultConfig(), $config);
    }

    public function handleConnection(
        Request $request,
        Response $response,
        string $sessionId,
        array $context
    ): Response {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $isTestMode = $this->config['test_mode'];

        if (!$isTestMode && function_exists('exec') && function_exists('getmypid')) {
            // Make the streamed and sustained connection gentler on the server resources.
            // This can wait a millisecond or so longer to respond if the server is busy.
            exec('renice 10 ' . getmypid());
        }

        // Get protocol version from context if available
        $protocolVersion = $context['protocol_version'];

        $response = $response
            ->withBody(new NonBufferedBody())
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('X-Accel-Buffering', 'no')
            //->withHeader('MCP-Protocol-Version', $protocolVersion)
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Mcp-Session-Id, Mcp-Protocol-Version')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');

        $body = $response->getBody();

        if ($isTestMode) {
            $this->checkAndSendMessages($body, $sessionId);
            return $response;
        }

        $this->pollForMessages($body, $sessionId, $context);
        return $response;
    }

    /**
     * Handles message queue check for the sessionID
     *
     * Also handles timing:
     * Fast polling for a period after a message was found.
     *  - this assumes if one message happened, there may be a series of messages coming through
     * Reduced polling if no messages for a period
     * Ultimate timeout (server connection shutdown) when no messages for an extended time
    */
    private function pollForMessages(StreamInterface $body, string $sessionId, array $context): void
    {
        $startTime = time();
        $pollInterval = $this->config['streamable_http']['keepalive_interval'];
        $maxTime = $this->config['streamable_http']['max_connection_time']; // $maxTime after 'last active' messaging.
        $switchTime = $this->config['streamable_http']['switch_interval_after'];
        $endTime = $startTime + $maxTime;

        // Begin Streaming loop until server connection shutdown
        while (time() < $endTime && connection_status() === CONNECTION_NORMAL) {
            if (connection_aborted()) {
                // Client (or other player) ended the connection
                break;
            }

            if ($this->checkAndSendMessages($body, $sessionId)) {
                // Reset 'last active' start time for more frequent messaging for a little bit
                $startTime = time();
                // Reset the server lifetime
                $endTime = $startTime + $maxTime;
            } else {
                $this->sendKeepalive($body);
            }

            // Kick down the polling schedule since there has been no messaging for a little bit
            // Stay active, but apparently there is no need for spastic polling
            $currentTime = time();
            if ($currentTime - $startTime > $switchTime) {
                $pollInterval = max($pollInterval * 2, 5);
            }

            sleep($pollInterval);
        }
    }

    private function sendKeepalive(StreamInterface $body): void
    {
        $keepaliveMessage = [
            'jsonrpc' => '2.0',
            'method' => 'notifications/ping',
            'params' => [
                'timestamp' => date('c')
            ]
        ];

        $this->writeSSEMessage($body, $keepaliveMessage);
    }

    private function checkAndSendMessages(StreamInterface $body, string $sessionId): bool
    {
        $messages = $this->storage->getMessages($sessionId);

        if (empty($messages)) {
            return false;
        }

        foreach ($messages as $message) {
            $this->writeSSEMessage($body, $message['data']);
            $this->storage->deleteMessage($message['id']);
        }

        return true;
    }

    private function writeSSEMessage(StreamInterface $body, array $message): void
    {
        $jsonData = json_encode($message);
        if ($jsonData === false) {
            return;
        }

        // Format as Server-Sent Events for streaming connections
        $sseData = "event: message\ndata: " . $jsonData . "\n\n";
        $body->write($sseData);

        // Force immediate send
        if (method_exists($body, 'flush')) {
            $body->flush();
        }
    }

    private function getDefaultConfig(): array
    {
        return [
            'test_mode' => false,              // set to true in tests for instant responses
            'streamable_http' => [
                'keepalive_interval' => 1,     // seconds
                'max_connection_time' => 1800, // 30 minutes
                'switch_interval_after' => 60  // switch to longer intervals after 1 minute
            ]
        ];
    }
}
