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

    /**
     * Slice a list into the page identified by an opaque cursor
     *
     * @param array $items the full list
     * @param string $keyField field identifying an item, used to build the cursor
     * @param string|null $cursor cursor from the client, null for the first page
     * @param int $pageSize items per page, 0 for no pagination
     * @return array|null page as ['items' => array, 'nextCursor' => ?string], null when the cursor is unknown
     */
    public function paginate(array $items, string $keyField, ?string $cursor, int $pageSize): ?array
    {
        $offset = 0;

        if ($cursor !== null && $cursor !== '') {
            $after = base64_decode(strtr($cursor, '-_', '+/'), true);

            if ($after === false) {
                return null;
            }

            $offset = null;
            foreach ($items as $index => $item) {
                if (($item[$keyField] ?? null) === $after) {
                    $offset = $index + 1;
                    break;
                }
            }

            if ($offset === null) {
                return null;
            }
        }

        if ($pageSize <= 0) {
            return ['items' => array_slice($items, $offset), 'nextCursor' => null];
        }

        $page = array_slice($items, $offset, $pageSize);
        $nextCursor = null;

        if (count($items) > $offset + count($page) && $page !== []) {
            $last = end($page);
            $nextCursor = rtrim(strtr(base64_encode((string)($last[$keyField] ?? '')), '+/', '-_'), '=');
        }

        return ['items' => $page, 'nextCursor' => $nextCursor];
    }

    public function sanitizeHeaderValue(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return preg_replace('/[^\x21-\x7E\x80-\xFF]/', '', $value);
    }
}
