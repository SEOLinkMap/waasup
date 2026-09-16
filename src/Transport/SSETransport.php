<?php

namespace Seolinkmap\Waasup\Transport;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\StreamInterface;
use Seolinkmap\Waasup\Config;
use Seolinkmap\Waasup\Storage\StorageInterface;
use Slim\Psr7\NonBufferedBody;

/**
 * Handles SSE transport for real-time message delivery
 */
class SSETransport implements TransportInterface
{
    private StorageInterface $storage;
    private array $config;

    public function __construct(StorageInterface $storage, array $config = [])
    {
        $this->storage = $storage;
        $this->config = Config::merge($this->getDefaultConfig(), $config);
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

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $isTestMode = $this->config['test_mode'];

        if (!$isTestMode && function_exists('exec') && function_exists('getmypid')) {
            exec('renice 10 ' . getmypid());
        }

        if (!$isTestMode) {
            $response = $response->withBody(new NonBufferedBody());
        }

        $response = $response
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('X-Accel-Buffering', 'no')
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Mcp-Session-Id, MCP-Protocol-Version')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');

        $this->resolveResumePoint($request, $sessionId);

        $body = $response->getBody();

        $this->sendEndpointEvent($body, $sessionId, $context);

        if ($isTestMode) {
            $this->checkAndSendMessages($body, $sessionId);
            return $response;
        }

        $this->pollForMessages($body, $sessionId, $context);

        return $response;
    }

    private function sendEndpointEvent(StreamInterface $body, string $sessionId, array $context): void
    {

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

            $this->sendKeepalive($body);

            if (connection_aborted()) {
                break;
            }

            if ($this->checkAndSendMessages($body, $sessionId)) {
                $startTime = time();
            }

            $currentTime = time();
            if ($currentTime - $startTime > $switchTime) {
                $pollInterval = max($pollInterval * 2, 5);
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
        $messages = $this->storage->getMessages($sessionId, [], $this->lastEventId);

        if (empty($messages)) {
            return false;
        }

        foreach ($messages as $message) {
            $messageData = sprintf(
                "id: %s\nevent: message\ndata: %s\n\n",
                $message['id'],
                json_encode($message['data'])
            );

            $body->write($messageData);

            $this->lastEventId = (string)$message['id'];
        }

        $this->rememberResumePoint($sessionId);

        return true;
    }
    /**
     * Position in the message stream this connection has reached
     */
    private ?string $lastEventId = null;

    /**
     * Resume from the id the client reconnected with, or from where the session left off
     */
    private function resolveResumePoint(Request $request, string $sessionId): void
    {
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
            'sse' => [
                'keepalive_interval' => 1,
                'max_connection_time' => 1800,
                'switch_interval_after' => 60
            ]
        ];
    }
}
