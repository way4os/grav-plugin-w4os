<?php

namespace W4os\Grav;

/**
 * A problem (RFC 9457) the engine API answered, or the API that cannot be reached (503 channel-unavailable). Its code says which
 * problem it is (`sign-in-refused`, `name-taken`...: Problem::CODES of the engine); the detail is in English.
 */
final class EngineException extends \RuntimeException
{
    /** The code of a problem without one, by its status, as the engine gives them. */
    private const STATUS_CODES = [401 => 'unauthorized', 404 => 'not-found', 405 => 'method-not-allowed', 503 => 'channel-unavailable'];

    public readonly string $problemCode;

    /**
     * @param int $status the HTTP status
     * @param string $detail what the API says, in English
     * @param array<string,mixed> $problem the whole problem document
     */
    public function __construct(
        public readonly int $status,
        string $detail,
        public readonly array $problem = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($detail, $status, $previous);
        $this->problemCode = (string) ($problem['code'] ?? (self::STATUS_CODES[$status] ?? ($status >= 500 ? 'internal-error' : 'invalid-request')));
    }
}
