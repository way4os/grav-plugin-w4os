<?php

use W4OS\Grav\Site;

describe('the description of the grid the site keeps for its helpers', function () {
    test('is asked to the engine when it is older than the cache, kept with its time, and the one kept when the engine cannot tell', function () {
        $file = sys_get_temp_dir().'/w4os-description-'.bin2hex(random_bytes(4)).'/site-description.json';
        $asked = 0;
        $given = ['grid' => ['nick' => 'demo'], 'config' => [], 'destinations' => null, 'checkedAt' => '2026-11-19T10:00:00Z'];
        $engine = function () use (&$asked, $given) {
            $asked++;

            return $given;
        };
        $now = strtotime('2026-11-19T10:30:00Z');

        expect(Site::keptDescription($file, $engine, 3600, $now))->toBe($given)
            ->and(decoct(fileperms($file) & 0777))->toBe('640')
            // Kept, not asked again within the cache
            ->and(Site::keptDescription($file, $engine, 3600, $now))->toBe($given)
            ->and($asked)->toBe(1)
            // Older: asked again; the engine silent: the one kept
            ->and(Site::keptDescription($file, fn () => throw new RuntimeException('down'), 60, $now))->toBe($given)
            ->and(Site::keptDescription(dirname($file).'/none.json', fn () => null, 60, $now))->toBeNull();

        exec('rm -rf '.escapeshellarg(dirname($file)));
    });
});
