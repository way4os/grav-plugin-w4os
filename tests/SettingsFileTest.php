<?php

use W4OS\Grav\Bundled;
use W4OS\Grav\SettingsFile;

describe('the settings the site keeps for its engine', function () {
    test('are kept in a file of the site only its web server reads, and read back', function () {
        $root = sys_get_temp_dir().'/w4os-settings-'.bin2hex(random_bytes(4));
        $file = "$root/data/engine-settings.json";
        $store = new SettingsFile($file);
        $store->set('MoneyServer', 'MoneyScriptAccessKey', 'site-key');
        $store->set('Helpers', 'AssetsURL', 'https://site.example.org/assets');
        $store->set('Helpers', 'AssetsURL', null);

        expect($store->writable())->toBeTrue()
            ->and((new SettingsFile($file))->all())->toBe(['MoneyServer' => ['MoneyScriptAccessKey' => 'site-key'], 'Helpers' => []])
            ->and(decoct(fileperms($file) & 0777))->toBe('640')
            ->and((new SettingsFile("$root/none.json"))->all())->toBe([]);

        exec('rm -rf '.escapeshellarg($root));
    });

    test('are what the bundled engine reads before the files of the grid', function () {
        $root = sys_get_temp_dir().'/w4os-bundled-'.bin2hex(random_bytes(4));
        (new SettingsFile("$root/site/engine-settings.json"))->set('MoneyServer', 'MoneyScriptAccessKey', 'site-key');
        $bundled = new Bundled(['grid' => 'demo'], "$root/site", fn () => null, "$root/site/cache", "$root/site/engine-settings.json", fn () => bundled_description([]));

        expect($bundled->grid()?->config()?->find('MoneyServer', 'MoneyScriptAccessKey'))->toBe('site-key');

        exec('rm -rf '.escapeshellarg($root));
    });
});
