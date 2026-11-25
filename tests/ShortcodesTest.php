<?php

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use W4OS\Grav\Site;

describe('the page of the shortcodes', function () {
    test('is a page of the admin, as the page Shortcodes of w4os-wp', function () {
        expect(Site::adminRoutes(['admin' => '/w4os'], [])['shortcodes'])->toBe('/w4os/shortcodes')
            ->and(Site::ADMIN_PAGES['shortcodes']['label'])->toBe('w4os.admin_shortcodes_menu')
            ->and(Site::titleKey('shortcodes'))->toBe('w4os.shortcodes_title');
    });

    test('has one shortcode by block, by the names of w4os-wp, no other name, and plain shortcodes for the grid and the site', function () {
        expect(Site::BLOCK_SHORTCODES)->toBe([
            'grid-info' => 'grid_info', 'grid-status' => 'grid_status', 'avatar-profile' => 'profile', 'popular-places' => 'popular_places',
            'web-search' => 'search', 'destination-guide' => 'destination_guide', 'new-avatars' => 'new_users', 'directory-info' => 'directory_info',
            'motd' => 'motd', 'grid-map' => 'map',
        ]);
    });

    test('lists the blocks first, in the order of w4os-wp, each its shortcode without a slash, then the shortcodes of the site', function () {
        $codes = array_column(Site::SHORTCODES, 'code');

        expect(array_slice($codes, 0, 5))->toBe(['[grid-info]', '[grid-status]', '[avatar-profile]', '[popular-places]', '[web-search]'])
            ->and($codes)->toContain('[new-avatars]', '[login-uri]', '[grid-name]', '[site-title]', '[site-slogan]', '[base_url]', '[w4os-home]…[/w4os-home]', '[w4os-guest]…[/w4os-guest]', '[w4os-register]…[/w4os-register]')
            ->and(implode(' ', $codes))->not->toContain('[w4os ', '[new-users]', ' /]')
            ->and(array_merge(...array_column(Site::SHORTCODES, 'aliases')))->toBe([])
            ->and(array_column(Site::SHORTCODES[0]['options'], 'option'))->toBe(['title', 'level=h3'])
            ->and(array_column(Site::SHORTCODES[2]['options'], 'option'))->toContain('name')
            ->and(array_column(Site::SHORTCODES[3]['options'], 'option'))->toBe(['title', 'level=h3', 'max=5', 'include-hypergrid', 'include-landsales']);
    });

    test('shows each one with a short preview and the table of its options', function () {
        $asked = [];
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->addFunction(new TwigFunction('w4os_shortcodes', fn () => Site::SHORTCODES));
        $twig->addFunction(new TwigFunction('w4os_block', function (string $name, array $args = []) use (&$asked) {
            $asked[$name] = $args;

            return "<section class=\"block block-$name\">$name</section>";
        }, ['is_safe' => ['html']]));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => ''));
        $twig->addFilter(new TwigFilter('t', fn ($key) => "[$key]"));
        $html = $twig->render('w4os/shortcodes.html.twig', []);

        expect($html)->toContain('[w4os.shortcodes_help]', '<code>[grid-info]</code>', '[w4os.block_grid_info_help]', '<section class="block block-grid-info">grid-info</section>')
            ->and($html)->toContain('[w4os.shortcode_options]', '<code>level=h3</code>', '[w4os.shortcode_option_level]')
            // The search as a page shows it embedded (its form alone), the popular places a few
            ->and($asked['search'])->not->toHaveKey('level')
            ->and($asked['popular-places']['count'] ?? null)->toBe(3);
    });
});
