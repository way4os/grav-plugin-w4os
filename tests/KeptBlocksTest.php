<?php

use W4OS\Grav\BlockUnavailable;
use W4OS\Grav\KeptBlocks;

/**
 * Kept blocks on a fake cache and a fake clock: the blocks asked are logged by call (one call, all the blocks it asks).
 *
 * @return array{0: KeptBlocks, 1: ArrayObject, 2: ArrayObject, 3: ArrayObject} the blocks, the cache, the calls, the clock (`t`)
 */
function kept_blocks(int $fresh = 60, bool $closesFirst = true, array $failing = [], array $locked = []): array
{
    $cache = new ArrayObject();
    $calls = new ArrayObject();
    $clock = new ArrayObject(['t' => 1000]);
    $blocks = new KeptBlocks(
        read: fn (string $key) => $cache[$key] ?? null,
        write: function (string $key, array $copy, int $lifetime) use ($cache) {
            $cache[$key] = $copy + ['lifetime' => $lifetime];
        },
        fetch: function (array $asked) use ($calls, $clock, $failing) {
            $calls[] = array_values($asked);

            return array_map(fn (string $what) => in_array($what, $failing, true)
                ? new BlockUnavailable("Idle timeout reached for \"https://grid.example.org/helpers/blocks/$what\".", 'Timeout')
                : ['html' => "<p>$what at {$clock['t']}</p>", 'notices' => []], $asked);
        },
        fresh: $fresh,
        closesFirst: $closesFirst,
        lock: fn (string $key) => in_array($key, $locked, true) ? null : fn () => null,
        now: fn () => $clock['t'],
    );

    return [$blocks, $cache, $calls, $clock];
}

describe('the blocks a page shows', function () {
    test('the site never had are asked all at once, given in the order the page asks them', function () {
        [$blocks, , $calls] = kept_blocks();
        $taken = $blocks->take(['a' => ['key' => 'k-motd', 'ask' => 'motd'], 'b' => ['key' => 'k-info', 'ask' => 'grid-info'], 'c' => ['key' => null, 'ask' => 'search']]);

        expect(array_keys($taken))->toBe(['a', 'b', 'c'])
            ->and($taken['b']['html'])->toBe('<p>grid-info at 1000</p>')
            ->and($calls->getArrayCopy())->toBe([['motd', 'grid-info', 'search']]);
    });

    test('are kept a day, shown as they are while fresh (the setting cache), never asked again then', function () {
        [$blocks, $cache, $calls, $clock] = kept_blocks();
        $blocks->take(['a' => ['key' => 'k', 'ask' => 'motd']]);
        $clock['t'] = 1059;
        $taken = $blocks->take(['a' => ['key' => 'k', 'ask' => 'motd']]);
        $blocks->refresh();

        expect($taken['a']['html'])->toBe('<p>motd at 1000</p>')
            ->and($cache['k']['lifetime'])->toBe(KeptBlocks::DAY)
            ->and($calls)->toHaveCount(1);
    });

    test('past their time are shown at once, read again after the page is sent, fresh then for the next page', function () {
        [$blocks, $cache, $calls, $clock] = kept_blocks();
        $blocks->take(['a' => ['key' => 'k', 'ask' => 'motd']]);
        $clock['t'] = 1100;
        $shown = $blocks->take(['a' => ['key' => 'k', 'ask' => 'motd']]);
        $asked = count($calls);
        $blocks->refresh();
        // The next page, once it was read again
        $blocks->take(['a' => ['key' => 'k', 'ask' => 'motd']]);
        $blocks->refresh();

        expect($shown['a']['html'])->toBe('<p>motd at 1000</p>')
            ->and($asked)->toBe(1)
            ->and($cache['k']['html'])->toBe('<p>motd at 1100</p>')
            ->and($calls)->toHaveCount(2);
    });

    test('past their time are read again by one page at a time: the one that does not get the lock leaves it', function () {
        [$blocks, $cache, $calls, $clock] = kept_blocks(locked: ['k']);
        $blocks->take(['a' => ['key' => 'k', 'ask' => 'motd']]);
        $clock['t'] = 1100;
        $blocks->take(['a' => ['key' => 'k', 'ask' => 'motd']]);
        $blocks->refresh();

        expect($calls)->toHaveCount(1)->and($cache['k']['html'])->toBe('<p>motd at 1000</p>');
    });

    test('past their time where the server cannot close the connection first are shown their day, never read again during the page', function () {
        [$blocks, $cache, $calls, $clock] = kept_blocks(closesFirst: false);
        $blocks->take(['a' => ['key' => 'k', 'ask' => 'motd']]);
        $clock['t'] = 1000 + KeptBlocks::DAY - 1;
        $taken = $blocks->take(['a' => ['key' => 'k', 'ask' => 'motd']]);
        $blocks->refresh();

        expect($taken['a']['html'])->toBe('<p>motd at 1000</p>')->and($calls)->toHaveCount(1);
    });

    test('read again in vain keep their copy, tried again a while later only', function () {
        [$blocks, $cache, $calls, $clock] = kept_blocks();
        $blocks->take(['a' => ['key' => 'k', 'ask' => 'motd']]);
        $failing = kept_blocks(failing: ['motd']);
        [$down, $downCache, $downCalls, $downClock] = $failing;
        $downCache['k'] = $cache['k'];
        $downClock['t'] = 1100;
        $down->take(['a' => ['key' => 'k', 'ask' => 'motd']]);
        $down->refresh();
        $downClock['t'] = 1110;
        $shown = $down->take(['a' => ['key' => 'k', 'ask' => 'motd']]);
        $down->refresh();

        expect($shown['a']['html'])->toBe('<p>motd at 1000</p>')
            ->and($downCalls)->toHaveCount(1)
            ->and($downCache['k']['tried'])->toBe(1100);
    });

    test('that cannot be read are said so a short while, as they were said, never waited for again then', function () {
        [$blocks, $cache, $calls, $clock] = kept_blocks(failing: ['motd']);
        $first = $blocks->take(['a' => ['key' => 'k', 'ask' => 'motd']]);
        $clock['t'] = 1000 + KeptBlocks::FAILURE - 1;
        $kept = $blocks->take(['a' => ['key' => 'k', 'ask' => 'motd']]);
        $clock['t'] = 1000 + KeptBlocks::FAILURE;
        $blocks->take(['a' => ['key' => 'k', 'ask' => 'motd']]);

        expect($first['a'])->toBeInstanceOf(BlockUnavailable::class)
            ->and($kept['a'])->toBeInstanceOf(BlockUnavailable::class)
            ->and($kept['a']->status)->toBe('Timeout')
            ->and($kept['a']->getMessage())->toBe($first['a']->getMessage())
            ->and($calls)->toHaveCount(2);
    });

    test('a block never kept (the search), or with the setting cache at 0, is asked each time', function () {
        [$blocks, $cache, $calls] = kept_blocks();
        $blocks->take(['a' => ['key' => null, 'ask' => 'search']]);
        $blocks->take(['a' => ['key' => null, 'ask' => 'search']]);
        [$none, $noneCache, $noneCalls] = kept_blocks(fresh: 0);
        $none->take(['a' => ['key' => 'k', 'ask' => 'motd']]);
        $none->take(['a' => ['key' => 'k', 'ask' => 'motd']]);

        expect($calls)->toHaveCount(2)->and($cache)->toHaveCount(0)
            ->and($noneCalls)->toHaveCount(2)->and($noneCache)->toHaveCount(0);
    });
});

describe('the page sent before the end of the request', function () {
    test('as Grav closes the connection before onShutdown: FastCGI, else Apache by the length of the page, uncompressed', function () {
        $closed = fn (array $server) => W4OS\Grav\Site::closedFirst(...($server + ['close' => true, 'fastcgi' => false, 'apache' => false, 'zlib' => false, 'gzip' => false, 'webserverGzip' => false]));

        expect($closed(['fastcgi' => true]))->toBeTrue()
            // Apache with mod_php: the length of the page and Connection: close, mod_deflate off for it
            ->and($closed(['apache' => true]))->toBeTrue()
            // PHP's built-in server: no length, the client waits for the end
            ->and($closed([]))->toBeFalse()
            // Compressed by PHP, by Grav or by the web server: the length would not be the one sent
            ->and($closed(['apache' => true, 'zlib' => true]))->toBeFalse()
            ->and($closed(['apache' => true, 'gzip' => true]))->toBeFalse()
            ->and($closed(['apache' => true, 'webserverGzip' => true]))->toBeFalse()
            // Grav's closing of the connection off
            ->and($closed(['fastcgi' => true, 'close' => false]))->toBeFalse();
    });
});
