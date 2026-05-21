<?php

namespace Seolinkmap\Waasup\Protocol\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Seolinkmap\Waasup\Storage\StorageInterface;

class ResponseManager
{
    private StorageInterface $storage;
    private ProtocolManager $protocolManager;

    public function __construct(StorageInterface $storage, ProtocolManager $protocolManager)
    {
        $this->storage = $storage;
        $this->protocolManager = $protocolManager;
    }


    public function storeSuccessResponse(string $sessionId, mixed $result, mixed $id, Response $response): Response
    {
        $responseData = [
            'jsonrpc' => '2.0',
            'result' => $result,
            'id' => $id
        ];

        return $this->emit($sessionId, $responseData, $response);
    }

    public function storeErrorResponse(
        string $sessionId,
        int $code,
        string $message,
        mixed $id,
        Response $response
    ): Response {
        $responseData = [
            'jsonrpc' => '2.0',
            'error' => [
                'code' => $code,
                'message' => $message
            ],
            'id' => $id
        ];

        return $this->emit($sessionId, $responseData, $response);
    }

    /**
     * Deliver a JSON-RPC response using the transport for the session's protocol version.
     *
     * Streamable HTTP (2025-03-26+) returns the response inline on the POST with a
     * 200 status. HTTP+SSE (2024-11-05) queues the response for the GET stream and
     * acknowledges the POST with a 202.
     */
    private function emit(string $sessionId, array $responseData, Response $response): Response
    {
        if ($this->protocolManager->usesDirectResponse($this->protocolManager->getSessionVersion($sessionId))) {
            $encoded = json_encode($responseData);
            $response->getBody()->write($encoded === false ? '{}' : $encoded);

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withStatus(200);
        }

        $this->storage->storeMessage($sessionId, $responseData);

        $response->getBody()->write('{"status": "queued"}');

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withStatus(202);
    }

    public function sanitizeHeaderValue(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return preg_replace('/[^\x21-\x7E\x80-\xFF]/', '', $value);
    }
}
