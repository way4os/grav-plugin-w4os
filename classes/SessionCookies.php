<?php

namespace W4OS\Grav;

/**
 * The cookie of a session a browser sends more than once: an older one of other attributes (another domain, another path), which
 * a cookie of the site never replaces, so the browser sends both, the older first, and PHP reads that one: a new session on every
 * request (no sign-in, no language kept). Settled before the session starts: the id the site keeps is the one read, else a new
 * session; the other cookies of that name are expired with the answer (their attributes guessed: host only and each domain above
 * the host, each path above the address asked). Only the names given (the sessions of the site), never another cookie. Plain PHP,
 * nothing of the grid nor of Grav.
 *
 *     SessionCookies::settle(['grav-site-87d8ca0'], $_SERVER['HTTP_COOKIE'] ?? '', 'kimbe.red', '/opensim/fr/login', $exists);
 */
final class SessionCookies
{
    /** A session id as PHP makes them (session.sid_length 22 to 256, session.sid_bits_per_character 4 to 6). */
    private const ID = '/^[a-zA-Z0-9,-]{22,256}$/';

    /**
     * The values a Cookie header gives a name, in the order sent.
     *
     * @return list<string>
     */
    public static function values(string $header, string $name): array
    {
        $values = [];
        foreach (explode(';', $header) as $pair) {
            [$key, $value] = array_map('trim', explode('=', $pair, 2)) + [1 => ''];
            $key === $name && $values[] = $value;
        }

        return $values;
    }

    /**
     * The session to read among the ids a browser sent: the first the site keeps; none when none is, or when that cannot be asked.
     * An id not made as PHP makes them is never looked up.
     *
     * @param list<string> $ids
     * @param ?\Closure(string $id): bool $exists whether the site keeps that session, null when it cannot tell
     */
    public static function live(array $ids, ?\Closure $exists): ?string
    {
        foreach ($ids as $id) {
            if ($exists !== null && preg_match(self::ID, $id) && $exists($id)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Whether a session is kept, for sessions kept in files (PHP's `files` handler), from the session options of the site: its folder
     * (`save_path`, relative to the root of the site), else PHP's; null for another handler, or a folder that is not there.
     *
     * @param array<string,mixed> $options the session options (`save_handler`, `save_path`)
     * @return ?\Closure(string $id): bool
     */
    public static function files(array $options, string $root): ?\Closure
    {
        if ((string) ($options['save_handler'] ?? ini_get('session.save_handler')) !== 'files') {
            return null;
        }
        $path = (string) ($options['save_path'] ?? '');
        $path === '' && $path = (string) preg_replace('/^.*;/', '', (string) ini_get('session.save_path'));
        $path === '' && $path = sys_get_temp_dir();
        $path[0] === '/' || $path = rtrim($root, '/').'/'.$path;
        if (! is_dir($path)) {
            return null;
        }

        return fn (string $id): bool => preg_match(self::ID, $id) === 1 && is_file($path.'/sess_'.$id);
    }

    /**
     * Every cookie of a name a browser may send to this address beside the one of now: host only, or at the host and each domain
     * above it; at each path above the address asked, with and without its slash; never the one of now (a leading dot is the same
     * domain to a browser). An address or localhost takes no domain.
     *
     * @return list<array{domain: string, path: string}>
     */
    public static function variants(string $host, string $requested, string $domain, string $path): array
    {
        $domain = ltrim($domain, '.');
        $domains = [''];
        if ($host !== '' && $host !== 'localhost' && filter_var($host, FILTER_VALIDATE_IP) === false) {
            $labels = explode('.', $host);
            for ($i = 0; $i < count($labels) - 1; $i++) {
                $domains[] = implode('.', array_slice($labels, $i));
            }
        }
        $paths = ['/'];
        $at = '';
        foreach (array_values(array_filter(explode('/', $requested), 'strlen')) as $segment) {
            $at .= '/'.$segment;
            array_push($paths, $at, $at.'/');
        }
        $variants = [];
        foreach (array_unique($domains) as $d) {
            foreach (array_unique($paths) as $p) {
                ($d === $domain && $p === $path) || $variants[] = ['domain' => $d, 'path' => $p];
            }
        }

        return $variants;
    }

    /**
     * Settles the names a request sends more than once, before the session starts: the id read is the one the site keeps, else
     * none (a new session); once the answer goes, the other cookies of each name are expired, the one the site sets kept.
     *
     * @param list<string> $names the names of the sessions of the site
     * @param ?\Closure(string $id): bool $exists whether the site keeps a session (files()), null when it cannot tell
     * @return array<string, ?string> each name sent more than once, the id kept (null: a new session)
     */
    public static function settle(array $names, string $header, string $host, string $requested, ?\Closure $exists): array
    {
        $settled = [];
        foreach ($names as $name) {
            $ids = self::values($header, $name);
            if (count($ids) < 2) {
                continue;
            }
            $live = self::live($ids, $exists);
            if ($live === null) {
                unset($_COOKIE[$name]);
            } else {
                $_COOKIE[$name] = $live;
            }
            $settled[$name] = $live;
        }
        if ($settled !== []) {
            header_register_callback(function () use ($settled, $host, $requested): void {
                $now = session_get_cookie_params();
                foreach (array_keys($settled) as $name) {
                    foreach (self::variants($host, $requested, (string) $now['domain'], (string) $now['path']) as $variant) {
                        setcookie((string) $name, '', ['expires' => 1, 'path' => $variant['path'], 'domain' => $variant['domain'],
                            'secure' => (bool) $now['secure'], 'httponly' => (bool) $now['httponly'], 'samesite' => (string) ($now['samesite'] ?: 'Lax')]);
                    }
                }
            });
        }

        return $settled;
    }
}
