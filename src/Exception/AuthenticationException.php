<?php

namespace Seolinkmap\Waasup\Exception;

/**
 * Authentication-related exceptions
 */
class AuthenticationException extends MCPException
{
    public function __construct(string $message = "Authentication required", \Throwable $previous = null)
    {
        parent::__construct($message, -32000, $previous);
    }
}
