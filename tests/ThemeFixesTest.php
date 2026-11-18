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
        expect(Site::themeFixes('hadron', $hadron, [], fn () => true))->toMatchArray(['menu' => null, 'langswitcher.format' => 'short'])
            ->and(Site::themeFixes('learn2', [], [], fn () => true))->toMatchArray(['github.position' => 'off'])
            ->and(Site::themeFixes('quark2', [], [], fn () => true))->toBe([]);
    });

    test('never change what the site set itself', function () use ($hadron) {
        $fixes = Site::themeFixes('hadron', $hadron, ['menu', 'custom_logo'], fn () => false);

        expect($fixes)->not->toHaveKey('menu')->not->toHaveKey('custom_logo')->toHaveKey('favicon');
    });
});
