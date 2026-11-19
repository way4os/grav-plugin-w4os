<?php

use W4os\Grav\Site;

describe('the settings the plugin gives a theme', function () {
    $hadron = [
        'custom_logo' => ['user/media/logo/grav-logo.svg' => ['name' => 'grav-logo.svg', 'path' => 'user/media/logo/grav-logo.svg']],
        'favicon' => ['user/media/favicon/grav-favicon.svg' => ['name' => 'grav-favicon.svg', 'path' => 'user/media/favicon/grav-favicon.svg']],
        'menu' => [['text' => 'Grav', 'url' => 'https://getgrav.org/']],
        'langswitcher' => ['enable' => true, 'format' => 'icon'],
    ];

    test('clear a logo or a favicon whose file is missing (a demo site\'s), whatever the theme', function () use ($hadron) {
        $fixes = Site::themeFixes('any', $hadron, [], fn (string $path) => false);

        expect($fixes)->toMatchArray(['custom_logo' => null, 'favicon' => null])
            ->and(Site::themeFixes('any', $hadron, [], fn (string $path) => true))->not->toHaveKey('custom_logo');
    });

    test('hide what a theme shows of its demo through its own options (Hadron\'s menu link, Learn2\'s repository link)', function () use ($hadron) {
        expect(Site::themeFixes('hadron', $hadron, [], fn () => true))->toMatchArray(['custommenus.enabled' => false, 'langswitcher.format' => 'short'])
            ->and(Site::themeFixes('learn2', [], [], fn () => true))->toMatchArray(['github.position' => 'off'])
            ->and(Site::themeFixes('quark2', [], [], fn () => true))->toBe([]);
    });

    test('never change what the site set itself', function () use ($hadron) {
        $fixes = Site::themeFixes('hadron', $hadron, ['custommenus.enabled', 'custom_logo'], fn () => false);

        expect($fixes)->not->toHaveKey('custommenus.enabled')->not->toHaveKey('custom_logo')->toHaveKey('favicon');
    });

    test('give Hypertext the favicon of the site where it takes it (the site\'s images, image://favicon/favicon.png), its option on', function () {
        $root = sys_get_temp_dir().'/w4os-hypertext-'.bin2hex(random_bytes(4));
        mkdir("$root/templates/partials", 0777, true);
        file_put_contents("$root/templates/partials/base.html.twig", '<link rel="icon" href="{{ url(\'image://favicon/favicon.png\') }}">');

        expect(Site::fixedBrandFiles($root))->toBe(['favicon' => 'image://favicon/favicon.png'])
            ->and(Site::brandSettings('hypertext', ['favicon'], []))->toBe(['structure.use_favicon' => true])
            ->and(Site::brandSettings('hypertext', ['favicon'], ['structure.use_favicon']))->toBe([])
            ->and(Site::brandSettings('hypertext', [], []))->toBe([])
            ->and(Site::brandSettings('quark2', ['favicon'], []))->toBe([]);
        exec('rm -rf '.escapeshellarg($root));
    });
});

