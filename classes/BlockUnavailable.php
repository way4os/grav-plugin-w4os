<?php

namespace W4OS\Grav;

/**
 * A block of the helpers that cannot be read: its reason (no address of the helpers, an error of theirs, no answer), said by a key
 * of the catalog and its values, or as the helpers said it (in the language they were asked).
 */
final class BlockUnavailable extends \RuntimeException
{
    /** @param list<string> $params the values of the key */
    public function __construct(string $message, public readonly string $key = '', public readonly array $params = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The reason in the language of the page.
     *
     * @param \Closure(string $key, array<int,string> $vars): string $t the text of a key of the catalog
     */
    public function reason(\Closure $t): string
    {
        return $this->key === '' ? $this->getMessage() : $t('w4os.'.$this->key, $this->params);
    }
}
