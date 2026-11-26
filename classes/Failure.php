<?php

namespace W4OS\Grav;

/**
 * What a failure of a call says on the pages of the site, one way for all of them: its status code and its standard reason phrase
 * (`500 Internal Server Error`, never translated), or the transport's own short reason when no HTTP answer came (`Connection
 * refused`, `Timeout`), then what was asked, in a few words (`helpers: search`).
 *
 *     Failure::line(Failure::status(500), 'helpers: search');   // 500 Internal Server Error · helpers: search
 */
final class Failure
{
    /** The reason phrases of the status codes (RFC 9110, RFC 6585). */
    public const PHRASES = [
        400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden', 404 => 'Not Found',
        405 => 'Method Not Allowed', 406 => 'Not Acceptable', 408 => 'Request Timeout', 409 => 'Conflict', 410 => 'Gone',
        413 => 'Content Too Large', 415 => 'Unsupported Media Type', 422 => 'Unprocessable Content', 423 => 'Locked',
        429 => 'Too Many Requests', 500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway',
        503 => 'Service Unavailable', 504 => 'Gateway Timeout',
    ];

    /** What the transport says when no HTTP answer came, by the words of its message (curl), in this order. */
    private const TRANSPORT = [
        'Could not resolve host' => 'Could not resolve host', 'timed out' => 'Timeout', 'Timeout' => 'Timeout',
        'Connection refused' => 'Connection refused', 'Could not connect' => 'Connection refused', 'Failed to connect' => 'Connection refused',
        'No route to host' => 'No route to host', 'Network is unreachable' => 'Network is unreachable', 'SSL' => 'SSL error',
        'certificate' => 'SSL error', 'Connection reset' => 'Connection reset', 'Empty reply' => 'Empty reply',
    ];

    /** A status code and its reason phrase: `500 Internal Server Error`. */
    public static function status(int $status): string
    {
        return trim($status.' '.(self::PHRASES[$status] ?? ''));
    }

    /** The short reason of a call that got no HTTP answer, from the transport's message: `Connection refused`, `Timeout`. */
    public static function transport(string $message): string
    {
        foreach (self::TRANSPORT as $words => $reason) {
            if (stripos($message, $words) !== false) {
                return $reason;
            }
        }

        return 'No answer';
    }

    /** What a failure says: an answer of the engine by its status, a call that got none by the transport's reason. */
    public static function of(\Throwable $e): string
    {
        if ($e instanceof BlockUnavailable && $e->status !== '') {
            return $e->status;
        }
        if ($e instanceof EngineException) {
            return $e->getPrevious() !== null ? self::transport($e->getPrevious()->getMessage()) : self::status($e->status);
        }

        return $e->getPrevious() !== null ? self::of($e->getPrevious()) : self::transport($e->getMessage());
    }

    /** The line of a failure: its status, then what was asked. */
    public static function line(string $status, string $asked): string
    {
        return $asked === '' ? $status : "$status · $asked";
    }
}
