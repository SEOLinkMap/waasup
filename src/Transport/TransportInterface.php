<?php

namespace Seolinkmap\Waasup\Transport;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Interface for MCP transport implementations
 */
interface TransportInterface
{
    public function handleConnection(
        Request $request,
        Response $response,
        string $sessionId,
        array $context
    ): Response;
}
