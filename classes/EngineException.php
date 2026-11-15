<?php

namespace W4os\Grav;

/** A problem (RFC 9457) the engine API answered, or the API that cannot be reached (503). */
final class EngineException extends \RuntimeException
{
    /**
     * @param int $status the HTTP status
     * @param string $detail what the API says, in English
     * @param string $type the type of the problem (`urn:opensim:problem:sign-in-refused`)
     * @param array<string,mixed> $problem the whole problem document
     */
    public function __construct(
        public readonly int $status,
        string $detail,
        public readonly string $type = '',
        public readonly array $problem = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($detail, $status, $previous);
    }
}
