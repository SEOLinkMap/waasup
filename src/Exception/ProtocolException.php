<?php

namespace Seolinkmap\Waasup\Exception;

/**
 * Protocol-related exceptions
 */
class ProtocolException extends MCPException
{
    public function __construct(string $message = "Protocol error", int $code = -32600, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
