<?php

namespace Seolinkmap\Waasup\Protocol\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Seolinkmap\Waasup\Storage\StorageInterface;

class ResponseManager
{
    private StorageInterface $storage;

    public function __construct(StorageInterface $storage)
    {
        $this->storage = $storage;
    }


    public function storeSuccessResponse(string $sessionId, mixed $result, mixed $id, Response $response): Response
    {
        $logFile = '/var/www/devsa/logs/uncaught.log';

        $responseData = [
            'jsonrpc' => '2.0',
            'result' => $result,
            'id' => $id
        ];

        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] RESPONSE-MANAGER-DEBUG: About to store message - sessionId: $sessionId\n", FILE_APPEND);

        $storeResult = $this->storage->storeMessage($sessionId, $responseData);

        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] RESPONSE-MANAGER-DEBUG: storeMessage result: " . ($storeResult ? 'SUCCESS' : 'FAILED') . "\n", FILE_APPEND);

        $response->getBody()->write('{"status": "queued"}');
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withStatus(202);
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
