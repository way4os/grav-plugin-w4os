<?php

namespace W4OS\Grav;

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
     * @param bool $connected false for a site that has no engine to ask (no address or no key): nothing was asked
     */
    public function __construct(
        public readonly int $status,
        string $detail,
        public readonly array $problem = [],
        ?\Throwable $previous = null,
        public readonly bool $connected = true,
    ) {
        parent::__construct($detail, $status, $previous);
        $this->problemCode = (string) ($problem['code'] ?? (self::STATUS_CODES[$status] ?? ($status >= 500 ? 'internal-error' : 'invalid-request')));
    }

    /**
     * Whether the engine answered a problem of what was asked (a name taken, a token refused: a 4xx with its code), which the person
     * can act on, rather than failed to answer it (an error of its own, no answer, the key of the site refused).
     */
    public function answered(): bool
    {
        return $this->getPrevious() === null && $this->status >= 400 && $this->status < 500 && isset($this->problem['code']) && $this->problemCode !== 'unauthorized';
    }

    /**
     * Why the site cannot work with the engine at all, whatever it asks: `key`, the engine refuses the key of the site; `down`, its
     * API does not answer (unreachable, or an error without a problem document: a web server or a PHP service in front of it); null
     * for a problem the engine answered about what was asked, or a site not connected.
     */
    public function trouble(): ?string
    {
        if ($this->problemCode === 'unauthorized') {
            return 'key';
        }

        return $this->connected && $this->status >= 500 && $this->problem === [] ? 'down' : null;
    }
}
