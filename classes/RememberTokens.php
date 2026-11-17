<?php

namespace W4os\Grav;

use Grav\Common\Yaml;
use Grav\Plugin\Login\RememberMe\TokenStorage;

/**
 * The "remember me" tokens of the Login plugin, with the requests a browser sends at once: each sign-in by the cookie renews its
 * token, so the requests sent with the same cookie before the first answer came back hold a token just replaced. Such a token
 * still signs in for GRACE seconds, and the tokens given then are all valid, whichever answer the browser kept. Any other token of
 * a series is a stolen cookie: every token of the account is dropped, as the Login plugin does, and the browser is a visitor
 * (stolen()) instead of an error page.
 *
 * A series, by the hash of its persistent token: [hash of the token => issued, …, 'replaced' => [hash => when]], the latest
 * token first, the way the Login plugin reads it. Its file is read and written whole, one request at a time.
 */
final class RememberTokens extends TokenStorage
{
    /** How long a token just replaced still signs in, in seconds */
    public const GRACE = 60;

    /** The hash of the token this request signed in with */
    private ?string $presented = null;

    private bool $stolen = false;

    /** Whether a stolen cookie came in this request: the account's tokens are dropped. */
    public function stolen(): bool
    {
        return $this->stolen;
    }

    public function findTriplet($credential, $token, $persistentToken)
    {
        $this->presented = null;
        $series = $this->read((string) $credential)[sha1($persistentToken)] ?? null;
        if (! is_array($series) || $series === []) {
            return self::TRIPLET_NOT_FOUND;
        }
        $issued = array_filter($series, 'is_int');
        if ($this->timeout > 0 && max($issued ?: [0]) + $this->timeout < time()) {
            return self::TRIPLET_NOT_FOUND;
        }
        $hash = sha1($token);
        $replaced = (int) (((array) ($series['replaced'] ?? []))[$hash] ?? 0);
        if (isset($issued[$hash]) || $replaced + self::GRACE >= time()) {
            $this->presented = $hash;

            return self::TRIPLET_FOUND;
        }
        $this->cleanAllTriplets($credential);
        $this->stolen = true;

        return self::TRIPLET_NOT_FOUND;
    }

    public function storeTriplet($credential, $token, $persistentToken, $expire = null)
    {
        $this->change((string) $credential, fn (array $tokens) => [sha1($persistentToken) => [sha1($token) => time()]] + $tokens);
    }

    /** The token renewed: the ones issued with the token of this request are replaced, unless that one was replaced already. */
    public function replaceTriplet($credential, $token, $persistentToken, $expire = null)
    {
        $this->change((string) $credential, function (array $tokens) use ($token, $persistentToken) {
            $key = sha1($persistentToken);
            $series = (array) ($tokens[$key] ?? []);
            $issued = array_filter($series, 'is_int');
            $replaced = array_filter((array) ($series['replaced'] ?? []), fn ($when) => (int) $when + self::GRACE >= time());
            if ($this->presented === null || isset($issued[$this->presented])) {
                $replaced = array_fill_keys(array_keys($issued), time()) + $replaced;
                $issued = [];
            }
            $tokens[$key] = [sha1($token) => time()] + $issued + ($replaced === [] ? [] : ['replaced' => $replaced]);

            return $tokens;
        });
    }

    public function cleanTriplet($credential, $persistentToken)
    {
        $this->change((string) $credential, fn (array $tokens) => array_diff_key($tokens, [sha1($persistentToken) => true]));
    }

    public function cleanAllTriplets($credential)
    {
        $this->change((string) $credential, fn () => []);
    }

    /** @return array<string,mixed> the series of an account, as the file has them now */
    private function read(string $credential): array
    {
        $file = $this->file($credential);
        $tokens = is_file($file) ? Yaml::parse((string) file_get_contents($file)) : [];

        return is_array($tokens) ? $tokens : [];
    }

    /** Changes the series of an account, one request at a time; none left, its file goes. */
    private function change(string $credential, callable $change): void
    {
        $file = $this->file($credential);
        is_dir($this->path) || mkdir($this->path, 0775, true);
        $lock = fopen($this->path.'/.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $tokens = $change($this->read($credential));
            if ($tokens === []) {
                is_file($file) && unlink($file);

                return;
            }
            // Written aside, then moved: a request reading it meanwhile has the old file or the new one, whole
            $temporary = $file.'.'.getmypid().'.tmp';
            file_put_contents($temporary, Yaml::dump($tokens));
            rename($temporary, $file);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function file(string $credential): string
    {
        return $this->path.'/'.sha1($credential).'.yaml';
    }
}
