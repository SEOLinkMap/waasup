<?php

namespace Seolinkmap\Waasup\Transport;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\StreamInterface;
use Seolinkmap\Waasup\Storage\StorageInterface;
use Slim\Psr7\NonBufferedBody;

/**
 * Handles Streamable HTTP transport for MCP 2025-03-26+
 * Uses chunked HTTP streaming for full-duplex communication
 */
class StreamableHTTPTransport implements TransportInterface
{
    private StorageInterface $storage;
    private array $config;

    public function __construct(StorageInterface $storage, array $config = [])
    {
        $this->storage = $storage;
        $this->config = array_merge($this->getDefaultConfig(), $config);
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

        $isTestMode = $this->config['test_mode'] ?? false;

        if (!$isTestMode && function_exists('exec') && function_exists('getmypid')) {
            // Make the streamed and sustained connection gentler on the server resources.
            // This can wait a millisecond or so longer to respond if the server is busy.
            exec('renice 10 ' . getmypid());
        }

        // Get protocol version from context if available
        $protocolVersion = $context['protocol_version'];

        $response = $response
            ->withBody(new NonBufferedBody())
            ->withHeader('Content-Type', 'application/json')
            //->withHeader('Transfer-Encoding', 'chunked')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('X-Accel-Buffering', 'no')
            ->withHeader('MCP-Protocol-Version', $protocolVersion)
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Mcp-Session-Id, Mcp-Protocol-Version')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');

        $body = $response->getBody();

        // $this->sendConnectionAck($body, $sessionId, $context);

        if ($isTestMode) {
            $this->checkAndSendMessages($body, $sessionId);
            return $response;
        }

        $this->pollForMessages($body, $sessionId, $context);
        return $response;
    }

    private function sendConnectionAck(StreamInterface $body, string $sessionId, array $context): void
    {
        $ackMessage = [
            'jsonrpc' => '2.0',
            'method' => 'notifications/connection',
            'params' => [
                'status' => 'connected',
                'sessionId' => $sessionId,
                'timestamp' => date('c')
            ]
        ];

        $this->writeChunkedMessage($body, $ackMessage);
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
        $pollInterval = $this->config['keepalive_interval'];
        $maxTime = $this->config['max_connection_time']; // $maxTime after 'last active' messaging.
        $switchTime = $this->config['switch_interval_after'];
        $endTime = $startTime + $maxTime;

        // Begin Streaming loop until server connection shutdown
        while (time() < $endTime && connection_status() === CONNECTION_NORMAL) {
            $this->sendKeepalive($body);

            if (connection_aborted()) {
                // Client (or other player) ended the connection
                break;
            }

            if ($this->checkAndSendMessages($body, $sessionId)) {
                // Reset 'last active' start time for more frequent messaging for a little bit
                $startTime = time();
                // Reset the server lifetime
                $endTime = $startTime + $maxTime;
            }

            // Kick down the polling schedule since there has been no messaging for a little bit
            // Stay active, but apparently there is no need for spastic polling
            $currentTime = time();
            if ($currentTime - $startTime > $switchTime) {
                $pollInterval = max($pollInterval * 2, 5);
            }

            sleep($pollInterval);
        }

        $this->sendConnectionClose($body);
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

        $this->writeChunkedMessage($body, $keepaliveMessage);
    }

    private function checkAndSendMessages(StreamInterface $body, string $sessionId): bool
    {
        $messages = $this->storage->getMessages($sessionId);

        if (empty($messages)) {
            return false;
        }

        foreach ($messages as $message) {
            $this->writeChunkedMessage($body, $message['data']);
            $this->storage->deleteMessage($message['id']);
        }

        return true;
    }

    private function sendConnectionClose(StreamInterface $body): void
    {
        $closeMessage = [
            'jsonrpc' => '2.0',
            'method' => 'notifications/connection',
            'params' => [
                'status' => 'closed',
                'timestamp' => date('c')
            ]
        ];

        $this->writeChunkedMessage($body, $closeMessage);
    }

    private function writeChunkedMessage(StreamInterface $body, array $message): void
    {
        $jsonData = json_encode($message);
        if ($jsonData === false) {
            return;
        }

        $jsonData .= "\n";
        $body->write($jsonData);
    }

    private function getDefaultConfig(): array
    {
        return [
            'keepalive_interval' => 2,
            'max_connection_time' => 1800,
            'switch_interval_after' => 60,
            'test_mode' => false
        ];
    }
}
