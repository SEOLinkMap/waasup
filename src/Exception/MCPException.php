<?php

namespace Seolinkmap\Waasup\Exception;

/**
 * Base MCP exception
 */
class MCPException extends \Exception
{
    public function __construct(string $message = "", int $code = -32603, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
