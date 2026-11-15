<?php

namespace W4os\Grav;

/** A form that cannot be done: the key of its text (languages/<lang>.yaml), and what the engine said (English, for the log). */
final class FormError extends \RuntimeException
{
    /**
     * @param string $key the key of its text (`w4os.error_name_taken`)
     * @param string $detail what the engine said (English, for the log)
     * @param list<scalar> $vars the values of the %s of its text
     */
    public function __construct(public readonly string $key, public readonly string $detail = '', ?\Throwable $previous = null, public readonly array $vars = [])
    {
        parent::__construct($detail !== '' ? $detail : $key, 0, $previous);
    }
}
