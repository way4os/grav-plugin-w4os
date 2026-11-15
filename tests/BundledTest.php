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
            ->and((string) file_get_contents("$root/etc/grids/demo/demo.conf"))->toContain('Remote = "true"')->toContain('GridName = "Demo World"')->toContain('BaseHostname = "grid.example.org"')->toContain('PublicPort = "8002"')
            ->and((string) file_get_contents("$root/etc/grids/demo/Helpers.ini"))->toContain('Data Source=db.example.org;Port=3306;Database=robust;User ID=opensim;Password=secret')->toContain('gridname = "Demo World"')
            ->and(decoct(fileperms("$root/etc/grids/demo/Helpers.ini") & 0777))->toBe('640');

        exec('rm -rf '.escapeshellarg($root));
    });
});
