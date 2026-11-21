<?php

use W4OS\Grav\Bundled;

/** What the engine of the grid's machine says of it for a site (GET /grids/{grid}/site-description), with this config. */
function bundled_description(array $config): array
{
    return ['grid' => ['nick' => 'demo', 'name' => 'Demo Grid', 'baseHostname' => 'grid.example.org', 'publicPort' => 8002, 'standalone' => false],
        'config' => $config, 'destinations' => null, 'checkedAt' => '2026-11-19T00:00:00Z'];
}

describe('bundled engine', function () {
    test('is there with the vendor folder', function () {
        expect(Bundled::available())->toBeTrue();
    });

    test('describes a grid that runs elsewhere from its login address and its database', function () {
        $root = sys_get_temp_dir().'/w4os-bundled-'.bin2hex(random_bytes(4));
        $asked = [];
        $bundled = new Bundled(
            ['login' => 'http://grid.example.org:8002/', 'database' => ['host' => 'db.example.org', 'name' => 'robust', 'user' => 'opensim', 'password' => 'secret']],
            $root,
            function (string $url) use (&$asked) {
                $asked[] = $url;

                return '<gridinfo><gridname>Demo World</gridname><gridnick>demo</gridnick><login>http://grid.example.org:8002/</login></gridinfo>';
            },
        );

        expect($bundled->nick())->toBe('demo')
            ->and($asked)->toBe(['http://grid.example.org:8002/get_grid_info'])
            ->and((string) file_get_contents("$root/etc/grids/demo/demo.conf"))->toContain('Remote = true', 'GridName = Demo World', 'GridNick = demo', 'BaseHostname = grid.example.org', 'PublicPort = 8002', 'PrivatePort = 8003')
            ->and((string) file_get_contents("$root/etc/grids/demo/Helpers.ini"))->toContain('Data Source=db.example.org;Database=robust;User ID=opensim;Password=secret;Old Guids=true;')->toContain('gridname = "Demo World"')
            ->and(decoct(fileperms("$root/etc/grids/demo/Helpers.ini") & 0777))->toBe('640');

        exec('rm -rf '.escapeshellarg($root));
    });

    test('takes the nick a wrong one stands for, as the engine does (a warning, never a failure that stops the site)', function () {
        $root = sys_get_temp_dir().'/w4os-bundled-'.bin2hex(random_bytes(4));
        $bundled = new Bundled(
            ['login' => 'http://grid.example.org:8002/', 'database' => ['host' => 'db.example.org:3307', 'name' => 'robust', 'user' => 'opensim', 'password' => 'secret']],
            $root,
            fn (string $url) => '<gridinfo><gridname>Demo World</gridname><gridnick>Demo World!</gridnick></gridinfo>',
        );

        expect($bundled->nick())->toBe('demo_world')
            ->and(array_map('basename', glob("$root/etc/grids/*") ?: []))->toBe(['demo_world']);

        exec('rm -rf '.escapeshellarg($root));
    });

    test('never reads a folder of config, the one the settings name or the one of the machine', function () {
        $root = sys_get_temp_dir().'/w4os-bundled-'.bin2hex(random_bytes(4));
        mkdir("$root/etc/grids/demo", 0700, true);
        file_put_contents("$root/etc/grids/demo/demo.conf", "[Grid]\nGridName = Demo Grid\n");
        file_put_contents("$root/etc/grids/demo/Robust.HG.ini", "[Const]\n    BaseURL = \"http://grid.example.org\"\n");
        $bundled = new Bundled(['config' => "$root/etc", 'w4os_conf' => "$root/etc/w4os.conf", 'grid' => 'demo'], "$root/site", fn () => null, "$root/site/cache");

        expect($bundled->grid())->toBeNull()
            ->and(is_dir("$root/site/etc/grids/demo"))->toBeFalse();

        exec('rm -rf '.escapeshellarg($root));
    });

    test('builds its grid from the description the engine of the machine gives, its databases with the access the site gives', function () {
        $root = sys_get_temp_dir().'/w4os-bundled-'.bin2hex(random_bytes(4));
        $bundled = new Bundled(['grid' => 'demo', 'database' => ['host' => 'db.example.org:3307', 'user' => 'site', 'password' => 'secret']], "$root/site", fn () => null, "$root/site/cache",
            describe: fn () => bundled_description(['Robust' => ['DatabaseService' => ['ConnectionString' => 'Data Source=;Database=demo_robust;']]]));
        $grid = $bundled->grid();
        $connection = (string) $grid?->config()?->find('DatabaseService', 'ConnectionString');

        expect($bundled->nick())->toBe('demo')
            ->and($grid?->name())->toBe('Demo Grid')
            ->and($connection)->toContain('Data Source=db.example.org', 'Database=demo_robust;', 'User ID=site;')
            // What it writes is the site's
            ->and($grid->environment()->layout->etcRoot())->toStartWith("$root/site")
            ->and($grid->environment()->layout->cacheRoot())->toBe("$root/site/cache");

        exec('rm -rf '.escapeshellarg($root));
    });

    test('says a block whose database cannot be reached is unavailable, with why, never an empty or missing answer', function () {
        $root = sys_get_temp_dir().'/w4os-bundled-'.bin2hex(random_bytes(4));
        $bundled = new Bundled(['grid' => 'demo', 'database' => ['host' => 'db.example.org', 'user' => 'site', 'password' => 'secret']], "$root/site", fn () => null, "$root/site/cache",
            describe: fn () => bundled_description(['Robust' => ['DatabaseService' => ['ConnectionString' => 'Data Source=;Database=demo_robust;']]]));
        W4OS\Engine\Data\Connections::using(static fn () => null);

        expect(fn () => $bundled->rendered('profile', ['name' => 'Ada.Love']))->toThrow(W4OS\Grav\BlockUnavailable::class, 'cannot be reached');

        W4OS\Engine\Data\Connections::using(null);
        exec('rm -rf '.escapeshellarg($root));
    });

    test('answer only under the prefix of the helpers the site is set with, when the grid sets a helper there; never another route', function () {
        $configured = ['http://site.example.org/helpers/currency.php', 'http://site.example.org/search'];

        expect(Bundled::answers('/helpers/blocks/grid-info', '/helpers', $configured))->toBeTrue()
            ->and(Bundled::answers('/helpers/currency.php', 'http://site.example.org/helpers/', $configured))->toBeTrue()
            // Outside the prefix: the site's pages, even an address the grid sets for a helper (its search page here)
            ->and(Bundled::answers('/search', '/helpers', $configured))->toBeFalse()
            ->and(Bundled::answers('/helpers-faq', '/helpers', $configured))->toBeFalse()
            // No helper set by the grid under the prefix (our default addresses are not settings): the route is the site's
            ->and(Bundled::answers('/helpers/blocks/grid-info', '/helpers', ['http://site.example.org/search']))->toBeFalse()
            ->and(Bundled::answers('/helpers/blocks/grid-info', '/helpers', ['http://site.example.org/w4os-helpers/currency.php']))->toBeFalse()
            // No prefix set, or the engine API under it
            ->and(Bundled::answers('/helpers/blocks/grid-info', '', $configured))->toBeFalse()
            ->and(Bundled::answers('/', '/', $configured))->toBeFalse()
            ->and(Bundled::answers('/helpers/api/grids', '/helpers', $configured))->toBeFalse();
    });

    test('never gives the helpers an address of the engine API: the engine alone serves it', function () {
        expect(W4OS\Grav\Bundled::isApi('/helpers/api', 'https://grid.example.org/helpers'))->toBeTrue()
            ->and(W4OS\Grav\Bundled::isApi('/helpers/api/grids/demo/avatars', 'https://grid.example.org/helpers/'))->toBeTrue()
            ->and(W4OS\Grav\Bundled::isApi('/helpers/apish', 'https://grid.example.org/helpers'))->toBeFalse()
            ->and(W4OS\Grav\Bundled::isApi('/helpers/search', 'https://grid.example.org/helpers'))->toBeFalse()
            ->and(W4OS\Grav\Bundled::isApi('/api/grids', 'https://grid.example.org/helpers'))->toBeFalse();
    });

    test('answers the web only what the engine says is public (a picture), never the rest of its API', function () {
        $root = sys_get_temp_dir().'/w4os-bundled-'.bin2hex(random_bytes(4));
        $bundled = new Bundled(
            ['login' => 'http://grid.example.org:8002/', 'database' => ['host' => 'db.example.org', 'name' => 'robust', 'user' => 'opensim', 'password' => 'secret']],
            $root,
            fn (string $url) => '<gridinfo><gridname>Demo World</gridname><gridnick>demo</gridnick><login>http://grid.example.org:8002/</login></gridinfo>',
        );

        expect($bundled->publicRoute('GET', '/openapi.json')[0])->toBe(200)
            ->and($bundled->publicRoute('GET', '/grids/demo/assets/00000000-0000-0000-0000-000000000000.jpg?default=person')[0])->not->toBe(401)
            ->and($bundled->publicRoute('GET', '/grids/demo/avatars')[0])->toBe(401)
            ->and($bundled->publicRoute('PATCH', '/grids/demo/regions/00000000-0000-0000-0000-000000000000', '{}')[0])->toBe(401)
            ->and($bundled->route('GET', '/grids/demo/avatars')[0])->not->toBe(401);

        exec('rm -rf '.escapeshellarg($root));
    });

    test('sends a picture to the external web assets service of the grid when nothing here reads JPEG 2000, as the engine says', function () {
        $root = sys_get_temp_dir().'/w4os-bundled-'.bin2hex(random_bytes(4));
        $bundled = new Bundled(['grid' => 'demo'], "$root/site", fn () => null, "$root/site/cache",
            describe: fn () => bundled_description(['Helpers' => ['Helpers' => ['HelpersURL' => 'https://play.example.org/helpers', 'AssetsURL' => 'https://assets.example.org/a']]]));
        [$status, , $headers] = $bundled->publicRoute('GET', '/grids/demo/assets/cb2052ae-d161-43e9-b11b-c834217823cd.jpg');

        expect([$status, $headers['Location'] ?? null])->toBe([302, 'https://assets.example.org/a/cb2052ae-d161-43e9-b11b-c834217823cd.jpg']);

        exec('rm -rf '.escapeshellarg($root));
    })->skip(fn () => W4OS\Engine\Assets\ImagickConverter::available(), 'imagick reads JPEG 2000 here');
});
