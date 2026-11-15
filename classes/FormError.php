<?php

namespace W4os\Grav;

/** A form that cannot be done: the key of its text (languages.yaml), and what the engine said, when it says what is wrong. */
final class FormError extends \RuntimeException
{
    public function __construct(public readonly string $key, public readonly string $detail = '', ?\Throwable $previous = null)
    {
        parent::__construct($detail !== '' ? $detail : $key, 0, $previous);
    }
}
