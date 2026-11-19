<?php

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use W4os\Grav\Site;

describe('the page of the shortcodes', function () {
    test('is a page of the admin, as the page Shortcodes of w4os-wp', function () {
        expect(Site::adminRoutes(['admin' => '/w4os'], [])['shortcodes'])->toBe('/w4os/shortcodes')
            ->and(Site::ADMIN_PAGES['shortcodes']['label'])->toBe('w4os.shortcodes_title')
            ->and(Site::titleKey('shortcodes'))->toBe('w4os.shortcodes_title');
    });

    test('lists the blocks first, in the order of w4os-wp, then the shortcodes of the site, each its code, what it shows and its options', function () {
        $codes = array_column(Site::SHORTCODES, 'code');

        expect(array_slice($codes, 0, 4))->toBe(['[w4os block="grid-info" /]', '[w4os block="grid-status" /]', '[w4os block="profile" /]', '[w4os block="popular-places" /]'])
            ->and($codes)->toContain('[w4os-home]…[/w4os-home]', '[w4os-guest]…[/w4os-guest]', '[w4os-register]…[/w4os-register]', '[w4os info="uri" /]')
            ->and(array_column(Site::SHORTCODES[0]['options'], 'option'))->toBe(['title', 'level=h3'])
            ->and(array_column(Site::SHORTCODES[2]['options'], 'option'))->toContain('name')
            ->and(array_column(Site::SHORTCODES[3]['options'], 'help'))->toContain('shortcode_option_count', 'shortcode_option_include_hypergrid', 'shortcode_option_include_landsales');
    });

    test('shows each one with its live preview and the table of its options', function () {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->addFunction(new TwigFunction('w4os_shortcodes', fn () => Site::SHORTCODES));
        $twig->addFunction(new TwigFunction('w4os_block', fn (string $name, array $args = []) => "<section class=\"block block-$name\">$name</section>", ['is_safe' => ['html']]));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => ''));
        $twig->addFilter(new TwigFilter('t', fn ($key) => "[$key]"));
        $html = $twig->render('w4os/shortcodes.html.twig', []);

        expect($html)->toContain('[w4os.shortcodes_help]', '<code>[w4os block=&quot;grid-info&quot; /]</code>', '[w4os.block_grid_info_help]', '<section class="block block-grid-info">grid-info</section>')
            ->and($html)->toContain('[w4os.shortcode_options]', '<code>level=h3</code>', '[w4os.shortcode_option_level]');
    });
});
