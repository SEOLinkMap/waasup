<?php

namespace Seolinkmap\Waasup\Transport;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Seolinkmap\Waasup\Config;
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
        $this->config = Config::merge($this->getDefaultConfig(), $config);
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

            exec('renice 10 ' . getmypid());
        }

        $protocolVersion = $context['protocol_version'] ?? '';
        $this->resumable = !\Seolinkmap\Waasup\Protocol\Handlers\ProtocolManager::isStatelessVersion($protocolVersion);

        if (!$isTestMode) {
            $response = $response->withBody(new NonBufferedBody());
        }

        $response = $response
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('X-Accel-Buffering', 'no')
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Mcp-Session-Id, Mcp-Protocol-Version')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');

        $this->resolveResumePoint($request, $sessionId);

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
     */
    private function pollForMessages(StreamInterface $body, string $sessionId, array $context): void
    {
        $startTime = time();
        $pollInterval = $this->config['streamable_http']['keepalive_interval'];
        $maxTime = $this->config['streamable_http']['max_connection_time'];
        $switchTime = $this->config['streamable_http']['switch_interval_after'];
        $endTime = $startTime + $maxTime;

        while (time() < $endTime && connection_status() === CONNECTION_NORMAL) {
            if (connection_aborted()) {

                break;
            }

            if ($this->checkAndSendMessages($body, $sessionId)) {

                $startTime = time();

                $endTime = $startTime + $maxTime;
            } else {
                $this->sendKeepalive($body);
            }

            $currentTime = time();
            if ($currentTime - $startTime > $switchTime) {
                $pollInterval = max($pollInterval * 2, 5);
            }

            sleep($pollInterval);
        }
        $this->logger->debug('connection ended, but they always do');
    }

    /**
     * Write an SSE comment to keep the connection open
     */
    private function sendKeepalive(StreamInterface $body): void
    {
        $body->write(": keepalive\n\n");

        if (method_exists($body, 'flush')) {
            $body->flush();
        }
    }

    private function checkAndSendMessages(StreamInterface $body, string $sessionId): bool
    {
        $messages = $this->storage->getMessages($sessionId, [], $this->lastEventId);

        if (empty($messages)) {
            return false;
        }

        foreach ($messages as $message) {
            $this->writeSSEMessage($body, $message['data'], (string)$message['id']);
            $this->lastEventId = (string)$message['id'];
        }

        $this->rememberResumePoint($sessionId);

        return true;
    }

    private function writeSSEMessage(StreamInterface $body, array $message, string $eventId): void
    {
        $jsonData = json_encode($message);
        if ($jsonData === false) {
            return;
        }

        $sseData = ($this->resumable ? "id: " . $eventId . "\n" : '')
            . "event: message\ndata: " . $jsonData . "\n\n";
        $body->write($sseData);

        if (method_exists($body, 'flush')) {
            $body->flush();
        }
    }
    /**
     * Position in the message stream this connection has reached
     */
    private ?string $lastEventId = null;

    /**
     * Whether the negotiated version replays what a dropped connection missed
     */
    private bool $resumable = true;

    /**
     * Resume from the id the client reconnected with, or from where the session left off
     *
     * A version without resumption carries no event ids for a client to have kept,
     * so both the header and any stored position are ignored and the stream starts
     * at its first message.
     */
    private function resolveResumePoint(Request $request, string $sessionId): void
    {
        if (!$this->resumable) {
            $this->lastEventId = null;

            return;
        }

        $header = $request->getHeaderLine('Last-Event-ID');

        if ($header !== '') {
            $this->lastEventId = $header;
            return;
        }

        $sessionData = $this->storage->getSession($sessionId) ?? [];
        $this->lastEventId = $sessionData['last_event_id'] ?? null;
    }

    /**
     * Record how far this session has been delivered
     */
    private function rememberResumePoint(string $sessionId): void
    {
        if (!$this->resumable) {
            return;
        }

        $sessionData = $this->storage->getSession($sessionId);

        if ($sessionData === null) {
            return;
        }

        $sessionData['last_event_id'] = $this->lastEventId;

        $this->storage->storeSession($sessionId, $sessionData, $this->config['session_lifetime']);
    }


    private function getDefaultConfig(): array
    {
        return [
            'test_mode' => false,
            'session_lifetime' => 3600,
            'streamable_http' => [
                'keepalive_interval' => 1,
                'max_connection_time' => 1800,
                'switch_interval_after' => 60
            ]
        ];
    }
}
