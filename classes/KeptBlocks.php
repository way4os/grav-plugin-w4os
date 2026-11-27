<?php

namespace W4OS\Grav;

/**
 * The copies the site keeps of the blocks of the helpers, for the blocks of a page: a copy is kept a day, shown as it is while fresh
 * (the setting `cache`), past that shown still and read again after the page is sent, where the server closes the connection
 * first (refresh()), one page at a time; elsewhere shown its day. The blocks the site has no copy of are asked all at once; one
 * that cannot be read is said so a short while (FAILURE), never waited for again then. Plain PHP: the cache, the reading, the lock
 * and the clock are given.
 */
final class KeptBlocks
{
    /** How long a copy is kept (seconds). */
    public const DAY = 86400;

    /** How long a block that cannot be read is said so without being asked again, and a copy read again in vain is not tried. */
    public const FAILURE = 30;

    /** @var array<string,mixed> the blocks to read again after the page is sent, what to ask by their key */
    private array $later = [];

    /**
     * @param \Closure(string $key): mixed $read the copy kept under a key
     * @param \Closure(string $key, array<string,mixed> $copy, int $lifetime): void $write
     * @param \Closure(array<string,mixed> $asked): array<string, array{html: string, notices: list<array<string,string>>}|BlockUnavailable> $fetch
     *     the blocks asked all at once, by the keys they are asked by
     * @param int $fresh how long a copy is shown without being read again (seconds); 0, never kept
     * @param bool $closesFirst whether the page is sent before the end of the request, its connection closed (refresh())
     * @param \Closure(string $key): ?\Closure $lock the lock of the refresh of a block, its release; null when another page has it
     * @param ?\Closure(): int $now
     */
    public function __construct(
        private \Closure $read,
        private \Closure $write,
        private \Closure $fetch,
        private int $fresh,
        private bool $closesFirst,
        private \Closure $lock,
        private ?\Closure $now = null,
    ) {}

    /**
     * The blocks of a page, in the order asked: their copies, the others read all at once.
     *
     * @param array<string, array{key: ?string, ask: mixed}> $asked each block: the key of its copy (null: never kept), what to ask
     * @return array<string, array{html: string, notices: list<array<string,string>>}|BlockUnavailable>
     */
    public function take(array $asked): array
    {
        $now = $this->now();
        $taken = array_fill_keys(array_keys($asked), null);
        $ask = [];
        foreach ($asked as $id => $block) {
            $key = $this->fresh > 0 ? $block['key'] : null;
            $copy = $key === null ? null : ($this->read)($key);
            $copy = is_array($copy) ? $copy : null;
            if (isset($copy['html'])) {
                $taken[$id] = ['html' => (string) $copy['html'], 'notices' => (array) ($copy['notices'] ?? [])];
                $this->closesFirst && $now - (int) ($copy['read'] ?? 0) >= $this->fresh && $now - (int) ($copy['tried'] ?? 0) >= self::FAILURE
                    && $this->later[$key] = $block['ask'];
            } elseif (isset($copy['failure']) && $now - (int) ($copy['read'] ?? 0) < self::FAILURE) {
                $taken[$id] = new BlockUnavailable((string) ($copy['failure']['message'] ?? ''), (string) ($copy['failure']['status'] ?? ''), (string) ($copy['failure']['key'] ?? ''));
            } else {
                $ask[$id] = $block['ask'];
            }
        }
        foreach ($ask === [] ? [] : ($this->fetch)($ask) as $id => $answer) {
            $taken[$id] = $answer;
            $key = $this->fresh > 0 ? $asked[$id]['key'] : null;
            if ($key === null) {
                continue;
            }
            $answer instanceof BlockUnavailable
                ? ($this->write)($key, ['failure' => ['message' => $answer->getMessage(), 'status' => $answer->status, 'key' => $answer->key], 'read' => $now], self::FAILURE)
                : ($this->write)($key, $answer + ['read' => $now], self::DAY);
        }

        return $taken;
    }

    /** Whether blocks wait to be read again after the page is sent. */
    public function waiting(): bool
    {
        return $this->later !== [];
    }

    /**
     * The blocks past their time read again, all at once, once the page is sent: each by the page that gets its lock, unless
     * another one read it meanwhile; one read in vain keeps its copy, tried again a while later.
     */
    public function refresh(): void
    {
        $now = $this->now();
        $held = [];
        $ask = [];
        foreach ($this->later as $key => $what) {
            $release = ($this->lock)($key);
            if ($release === null) {
                continue;
            }
            $copy = ($this->read)($key);
            $copy = is_array($copy) && isset($copy['html']) ? $copy : null;
            if ($copy !== null && $now - (int) ($copy['read'] ?? 0) < $this->fresh) {
                $release();

                continue;
            }
            $held[$key] = [$release, $copy];
            $ask[$key] = $what;
        }
        $this->later = [];
        foreach ($ask === [] ? [] : ($this->fetch)($ask) as $key => $answer) {
            [$release, $copy] = $held[$key];
            if (! $answer instanceof BlockUnavailable) {
                ($this->write)($key, $answer + ['read' => $now], self::DAY);
            } elseif ($copy !== null) {
                ($this->write)($key, ['tried' => $now] + $copy, self::DAY);
            }
            $release();
        }
    }

    private function now(): int
    {
        return $this->now === null ? time() : (int) ($this->now)();
    }
}
