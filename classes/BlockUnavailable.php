<?php

namespace W4OS\Grav;

/**
 * A block of the helpers that cannot be read: its status as a failure says it (Failure: `500 Internal Server Error`, `Connection
 * refused`), or, where the site's manager can act on it, a key of the catalog (the address of the helpers not set); its message
 * for the log.
 */
final class BlockUnavailable extends \RuntimeException
{
    /**
     * @param string $status the status of the failure (Failure::status(), Failure::transport())
     * @param string $key a key of the catalog said instead, for what the manager can set
     */
    public function __construct(string $message, public readonly string $status = '', public readonly string $key = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The reason in the language of the page: its key's text, else its status.
     *
     * @param \Closure(string $key, array<int,string> $vars): string $t the text of a key of the catalog
     */
    public function reason(\Closure $t): string
    {
        return $this->key !== '' ? $t('w4os.'.$this->key, []) : ($this->status !== '' ? $this->status : Failure::transport($this->getMessage()));
    }
}
