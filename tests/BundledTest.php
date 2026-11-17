<?php

use W4os\Grav\Bundled;

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

    test('refuses a grid whose nick is not one, rather than changing it', function () {
        $root = sys_get_temp_dir().'/w4os-bundled-'.bin2hex(random_bytes(4));
        $bundled = new Bundled(
            ['login' => 'http://grid.example.org:8002/', 'database' => ['host' => 'db.example.org:3307', 'name' => 'robust', 'user' => 'opensim', 'password' => 'secret']],
            $root,
            fn (string $url) => '<gridinfo><gridname>Demo World</gridname><gridnick>Demo World!</gridnick></gridinfo>',
        );

        expect(fn () => $bundled->nick())->toThrow(W4os\Grav\EngineException::class)
            ->and(glob("$root/etc/grids/*") ?: [])->toBe([]);

        exec('rm -rf '.escapeshellarg($root));
    });

    test('never gives the helpers an address of the engine API: the engine alone serves it', function () {
        expect(W4os\Grav\Bundled::isApi('/helpers/api', 'https://grid.example.org/helpers'))->toBeTrue()
            ->and(W4os\Grav\Bundled::isApi('/helpers/api/grids/demo/avatars', 'https://grid.example.org/helpers/'))->toBeTrue()
            ->and(W4os\Grav\Bundled::isApi('/helpers/apish', 'https://grid.example.org/helpers'))->toBeFalse()
            ->and(W4os\Grav\Bundled::isApi('/helpers/search', 'https://grid.example.org/helpers'))->toBeFalse()
            ->and(W4os\Grav\Bundled::isApi('/api/grids', 'https://grid.example.org/helpers'))->toBeFalse();
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
});
