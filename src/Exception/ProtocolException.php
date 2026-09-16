<?php

namespace Seolinkmap\Waasup\Exception;

/**
 * Protocol-related exceptions
 */
class ProtocolException extends MCPException
{
    private ?array $data;

    /**
     * @param array|null $data the error's data member, for the codes that define one
     */
    public function __construct(string $message = "Protocol error", int $code = -32600, \Throwable $previous = null, ?array $data = null)
    {
        parent::__construct($message, $code, $previous);

        $this->data = $data;
    }

    /**
     * The error's data member
     *
     * @return array|null the payload, null for the codes that define none
     */
    public function getData(): ?array
    {
        return $this->data;
    }
}
