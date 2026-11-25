<?php

use W4OS\Grav\Backdrop;

describe('the backdrop of the site', function () {
    test('a series is the site own images, else the theme ones', function () {
        $own = ['user/data/theme/images/backdrop/light/a.jpg' => ['name' => 'a.jpg'], 'user/data/theme/images/backdrop/light/gone.jpg' => ['name' => 'gone.jpg']];
        $theme = ['user/themes/w4os/images/backdrop/light/midi.jpg'];
        $exists = fn (string $path) => ! str_contains($path, 'gone');

        expect(Backdrop::series($own, $theme, $exists))->toBe(['user/data/theme/images/backdrop/light/a.jpg'])
            ->and(Backdrop::series([], $theme, $exists))->toBe($theme)
            ->and(Backdrop::series(['user/data/theme/images/backdrop/light/gone.jpg' => []], $theme, $exists))->toBe($theme)
            ->and(Backdrop::series([], [], $exists))->toBe([]);
    });

    test('the theme series are the images of its folder for the mode', function () {
        $root = sys_get_temp_dir().'/w4os-backdrop-'.bin2hex(random_bytes(4));
        mkdir("$root/user/themes/w4os/images/backdrop/dark", 0777, true);
        foreach (['minuit.jpg', 'aube.png', 'notes.txt'] as $file) {
            touch("$root/user/themes/w4os/images/backdrop/dark/$file");
        }

        expect(Backdrop::themeSeries($root, 'user/themes/w4os/images/backdrop/dark'))->toBe(['user/themes/w4os/images/backdrop/dark/aube.png', 'user/themes/w4os/images/backdrop/dark/minuit.jpg'])
            ->and(Backdrop::themeSeries($root, 'user/themes/w4os/images/backdrop/light'))->toBe([]);

        exec('rm -rf '.escapeshellarg($root));
    });

    test('one image of a series at random', function () {
        $series = ['a.jpg', 'b.jpg', 'c.jpg'];
        $picked = array_map(fn () => Backdrop::pick($series), range(1, 60));

        expect(array_diff($picked, $series))->toBe([])
            ->and(count(array_unique($picked)))->toBeGreaterThan(1)
            ->and(Backdrop::pick([]))->toBeNull();
    });

    test('its widths, never larger than the image', function () {
        expect(Backdrop::widths(2048))->toBe([2048 => '', 1280 => '(max-width: 48rem)'])
            ->and(Backdrop::widths(4000))->toBe([2048 => '', 1280 => '(max-width: 48rem)'])
            ->and(Backdrop::widths(1600))->toBe([1600 => '', 1280 => '(max-width: 48rem)'])
            ->and(Backdrop::widths(1000))->toBe([1000 => '']);
    });

    test('its style follows the switch of the theme and the browser, the image of the mode alone', function () {
        $css = Backdrop::css([
            'light' => [2048 => ['webp' => '/images/midi-2048.webp', 'jpg' => '/images/midi-2048.jpg'], 1280 => ['webp' => '/images/midi-1280.webp', 'jpg' => '/images/midi-1280.jpg']],
            'dark' => [2048 => ['webp' => '/images/minuit-2048.webp', 'jpg' => '/images/minuit-2048.jpg']],
        ]);

        expect($css)
            ->toContain('--w4os-backdrop-light:image-set(url("/images/midi-2048.webp") type("image/webp"),url("/images/midi-2048.jpg") type("image/jpeg"))')
            ->toContain('--w4os-backdrop-dark:image-set(url("/images/minuit-2048.webp") type("image/webp"),url("/images/minuit-2048.jpg") type("image/jpeg"))')
            ->toContain('@media (max-width: 48rem){:root{--w4os-backdrop-light:image-set(url("/images/midi-1280.webp")')
            ->toContain(':root[data-theme="dark"]{--w4os-backdrop:var(--w4os-backdrop-dark)}')
            ->toContain('@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){--w4os-backdrop:var(--w4os-backdrop-dark)}}')
            ->toContain('body::before{content:"";position:fixed;inset:0;z-index:-1;pointer-events:none;background:var(--w4os-backdrop,none) center/cover no-repeat}')
            // Behind the page, never under a background of the theme's body (painted over what is behind it)
            ->toContain(':root body{background:transparent}')
            // The dark image only once its mode is set: the property of the light one first
            ->toContain(':root{--w4os-backdrop:var(--w4os-backdrop-light)');
    });

    test('on the pages the theme lays out in a browser and on the splash page, never on the admin pages nor on another page a viewer shows', function () {
        expect(Backdrop::shown('default', null, false))->toBeTrue()
            ->and(Backdrop::shown('w4os', null, false))->toBeTrue()
            ->and(Backdrop::shown('w4os-admin', null, false))->toBeFalse()
            ->and(Backdrop::shown('w4os', 'w4os-viewer.html.twig', false))->toBeFalse()
            // The web only: in a viewer, whatever the page (its own day and night in the world, no mode of its own)
            ->and(Backdrop::shown('default', null, true))->toBeFalse()
            ->and(Backdrop::shown('w4os', null, true))->toBeFalse()
            ->and(Backdrop::shown('w4os', 'w4os-viewer.html.twig', true))->toBeFalse()
            // But the splash page, the one a viewer shows before the login: in the viewer as in a browser
            ->and(Backdrop::shown('default', 'w4os-welcome.html.twig', true))->toBeTrue()
            ->and(Backdrop::shown('default', 'w4os-welcome.html.twig', false))->toBeTrue();
    });

    test('no image, no style', function () {
        expect(Backdrop::css([]))->toBe('')
            ->and(Backdrop::css(['light' => [], 'dark' => []]))->toBe('');
    });

    test('an address in its style never closes the url', function () {
        expect(Backdrop::css(['light' => [2048 => ['jpg' => '/images/a").b{x:y}.jpg']]]))->toContain('url("/images/a%22).b{x:y}.jpg")');
    });

    test('the settings page has its fields, in the appearance of the site', function () {
        $fields = Symfony\Component\Yaml\Yaml::parse(explode('---', file_get_contents(dirname(__DIR__).'/pages/settings.md'))[1])['form']['fields']['appearance']['fields'];

        expect($fields['backdrop']['type'])->toBe('checkbox')
            ->and($fields['backdrop_light']['type'])->toBe('file')
            ->and($fields['backdrop_light']['multiple'])->toBeTrue()
            ->and($fields['backdrop_dark']['type'])->toBe('file')
            ->and($fields['backdrop_dark']['multiple'])->toBeTrue()
            ->and(array_keys(Backdrop::FOLDERS))->toBe(['light', 'dark']);
    });
});
