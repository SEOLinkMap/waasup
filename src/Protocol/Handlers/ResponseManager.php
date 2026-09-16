<?php

namespace Seolinkmap\Waasup\Protocol\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\StreamInterface;
use Seolinkmap\Waasup\Storage\StorageInterface;

class ResponseManager
{
    private StorageInterface $storage;
    private ProtocolManager $protocolManager;
    private ?StreamInterface $stream = null;

    public function __construct(StorageInterface $storage, ProtocolManager $protocolManager)
    {
        $this->storage = $storage;
        $this->protocolManager = $protocolManager;
    }

    public function storeSuccessResponse(string $sessionId, mixed $result, mixed $id, Response $response): Response
    {
        $responseData = [
            'jsonrpc' => '2.0',
            'result' => $this->decorateResult($result, $sessionId),
            'id' => $id
        ];

        return $this->emit($sessionId, $responseData, $response);
    }

    /**
     * Add the result fields the negotiated version requires
     *
     * @param mixed $result the handler's result
     * @return mixed the result as it goes on the wire
     */
    private function decorateResult(mixed $result, string $sessionId): mixed
    {
        $version = $this->protocolManager->getSessionVersion($sessionId);

        if (!$this->protocolManager->isFeatureSupported('stateless', $version)) {
            return $result;
        }

        $result = $result instanceof \stdClass ? (array)$result : $result;

        if (!is_array($result)) {
            return $result;
        }

        $result['resultType'] = $result['resultType'] ?? 'complete';

        $serverInfo = $this->protocolManager->getServerInfo($version);

        if ($serverInfo !== []) {
            $result['_meta']['io.modelcontextprotocol/serverInfo'] = $serverInfo;
        }

        return $result;
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
     * Send this request's messages on its own response stream instead of a queue
     *
     * @param StreamInterface|null $stream the response body, or null to use the queue
     */
    public function useStream(?StreamInterface $stream): void
    {
        $this->stream = $stream;
    }

    /**
     * Send a notification raised while the request it belongs to is being handled
     *
     * @param array $notification the JSON-RPC notification
     */
    public function emitNotification(string $sessionId, array $notification): void
    {
        if ($this->stream === null) {
            $this->storage->storeMessage($sessionId, $notification);

            return;
        }

        $this->writeEvent($this->stream, $notification);
    }

    /**
     * Write one SSE event, which this revision sends without an id
     *
     * @param array $message the JSON-RPC message
     */
    public static function writeEvent(StreamInterface $stream, array $message): void
    {
        $encoded = json_encode($message);

        $stream->write("event: message\ndata: " . ($encoded === false ? '{}' : $encoded) . "\n\n");

        if (method_exists($stream, 'flush')) {
            $stream->flush();
        }
    }

    /**
     * Deliver a JSON-RPC response using the transport for the session's protocol version.
     */
    private function emit(string $sessionId, array $responseData, Response $response): Response
    {
        if ($this->stream !== null) {
            $this->writeEvent($this->stream, $responseData);

            return $response;
        }

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

    /**
     * Answer with the input a handler still needs from the client
     *
     * @return Response|null the interim result, null when nothing further is needed
     */
    public function inputRequired(string $sessionId, mixed $id, Response $response): ?Response
    {
        $inputRequests = $this->protocolManager->getSessionValue($sessionId, 'input_requests', []);

        if ($inputRequests === []) {
            return null;
        }

        return $this->storeSuccessResponse(
            $sessionId,
            ['resultType' => 'input_required', 'inputRequests' => $inputRequests],
            $id,
            $response
        );
    }

    /**
     * Add the caching fields a cacheable result carries
     *
     * @param array $result a list or read result
     * @return array the result, with ttlMs and cacheScope where the version defines them
     */
    public function cacheable(array $result, string $sessionId): array
    {
        $version = $this->protocolManager->getSessionVersion($sessionId);

        if (!$this->protocolManager->isFeatureSupported('cacheable_results', $version)) {
            return $result;
        }

        $result['ttlMs'] = $this->protocolManager->getCacheTtlMs();
        $result['cacheScope'] = $this->protocolManager->getCacheScope();

        return $result;
    }

    public function sanitizeHeaderValue(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return preg_replace('/[^\x21-\x7E\x80-\xFF]/', '', $value);
    }
}
