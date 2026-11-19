<?php

use W4os\Grav\Site;

describe('the posts of the grid in the admin (Flux)', function () {
    test('live under the admin route, in the menu after the avatars as w4os-wp has them, the engine list of the posts', function () {
        $pages = array_keys(Site::ADMIN_PAGES);

        expect(Site::adminRoutes(['admin' => '/w4os'], [])['flux'])->toBe('/w4os/flux')
            ->and(Site::ADMIN_PAGES['flux']['label'])->toBe('w4os.flux_menu')
            ->and(Site::ADMIN_PAGES['flux']['parent'] ?? null)->toBe('manage')
            ->and(Site::ADMIN_TYPES['flux'])->toBe('posts')
            ->and(Site::titleKey('flux'))->toBe('w4os.flux_title')
            ->and($pages[array_search('models', $pages, true) + 1])->toBe('flux');
    });

    test('its page lists the posts as the engine describes them, in the words the engine gives the list', function () {
        preg_match('/^---\R(.*?)\R---/s', (string) @file_get_contents(dirname(__DIR__).'/pages/flux.md'), $matter);
        $header = (array) Symfony\Component\Yaml\Yaml::parse($matter[1] ?? '');
        $template = (string) @file_get_contents(dirname(__DIR__).'/templates/w4os/flux.html.twig');

        expect($header['w4os_list']['type'] ?? null)->toBe('posts')
            ->and($template)->toContain('data.list.texts');
    });
});
