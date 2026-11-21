<?php

use W4OS\Grav\Site;

describe('the links of the site', function () {
    test('from a /fr page are /fr/ links, never /fr/fr, under the base of the site, absolute or not', function () {
        $fr = Site::siteLinks('', '/fr', ['en', 'fr', 'de'], '/aide');

        expect($fr->build('/search'))->toBe('/fr/search')
            ->and($fr->build('/fr/search'))->toBe('/fr/search')
            ->and($fr->build('/search', ['q' => 'plaza']))->toBe('/fr/search?q=plaza')
            ->and(Site::siteLinks('https://dev.w4os.org', '/fr', ['en', 'fr'], '/')->build('/account'))->toBe('https://dev.w4os.org/fr/account')
            ->and(Site::siteLinks('/site', '/fr', ['en', 'fr'], '/')->build('/account'))->toBe('/site/fr/account')
            // The language of the site without a prefix, and the pictures, which have none
            ->and(Site::siteLinks('', '', ['en', 'fr'], '/')->build('/search'))->toBe('/search');
    });

    test('are built by the plugin\'s templates through it (w4os_url()), never by hand from the base', function () {
        $handmade = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/templates')) as $file) {
            if (str_ends_with((string) $file, '.twig') && str_contains((string) file_get_contents((string) $file), 'base_url_relative ~')) {
                $handmade[] = basename((string) $file);
            }
        }

        expect($handmade)->toBe([]);
    });
});
