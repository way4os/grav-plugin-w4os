<?php

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use W4OS\Grav\Site;

/** The visit block with the grid's visit given (null: not reachable by hypergrid), as Grav renders it (escaping what it prints). */
function render_visit(?array $hypergrid, array $context = []): string
{
    $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'), ['autoescape' => 'html']);
    $twig->addFunction(new TwigFunction('w4os_hypergrid', fn () => $hypergrid));
    $twig->addFunction(new TwigFunction('w4os_anchor', fn (string $href, string $text, string $kind = 'plain', string $class = '') => W4OS\Views\Link::html($href, $text, $kind, $class), ['is_safe' => ['html']]));
    $twig->addFilter(new TwigFilter('t', fn (string $key) => $key));
    $assets = new class {
        public function addJs(string $url): void {}
    };

    return $twig->render('w4os/hypergrid-visit.html.twig', $context + ['assets' => $assets]);
}

describe('the visit of the grid from another one', function () {
    test('is the gatekeeper as host:port and the engine\'s link for the web, none without a gatekeeper', function () {
        expect(Site::hypergridVisit('http://demo.w4os.org:3405'))->toBe(['address' => 'demo.w4os.org:3405', 'link' => 'hop://demo.w4os.org:3405'])
            ->and(Site::hypergridVisit('demo.w4os.org:3405')['address'])->toBe('demo.w4os.org:3405')
            ->and(Site::hypergridVisit(''))->toBeNull()
            ->and(Site::hypergridVisit('  '))->toBeNull();
    });

    test('is read from the grid info of the engine (its gatekeeper)', function () {
        expect(fakeEngine([[200, ['data' => ['type' => 'info', 'id' => 'demo', 'attributes' => ['elements' => ['gatekeeper' => 'http://demo.w4os.org:3405']]]]]], $log)->gatekeeper())->toBe('http://demo.w4os.org:3405')
            ->and(fakeEngine([[200, ['data' => ['type' => 'info', 'id' => 'demo', 'attributes' => ['elements' => []]]]]], $log)->gatekeeper())->toBe('');
    });

    test('shows nothing when the grid is not reachable by hypergrid', function () {
        expect(trim(render_visit(null)))->toBe('');
    });

    test('shows a callout: what to do, the address to copy, the button that takes a viewer there', function () {
        $html = render_visit(['address' => 'demo.w4os.org:3405', 'link' => 'hop://demo.w4os.org:3405']);

        expect($html)->toContain('<aside class="w4os-callout w4os-hypergrid-visit">', '<h2 class="w4os-callout-title">w4os.hg_visit_title</h2>', '<p>w4os.hg_visit_text</p>')
            ->and($html)->toContain('<code class="w4os-login-uri">demo.w4os.org:3405</code>', 'data-w4os-copy="demo.w4os.org&#x3A;3405"')
            ->and($html)->toContain('<a class="btn btn-primary" href="hop://demo.w4os.org:3405">w4os.hg_visit_go</a>')
            ->and(render_visit(['address' => 'a.org:8002', 'link' => 'hop://a.org:8002'], ['hg_title' => 'Hypergrid', 'hg_level' => 'h3']))->toContain('<h3 class="w4os-callout-title">Hypergrid</h3>');
    });

    test('is a shortcode of the site, its title and level as options', function () {
        $codes = array_column(Site::SHORTCODES, 'code');
        $visit = Site::SHORTCODES[array_search('[hypergrid-visit]', $codes, true)];

        expect($codes)->toContain('[hypergrid-visit]')
            ->and(array_column($visit['options'], 'option'))->toBe(['title', 'level=h2'])
            ->and((string) file_get_contents(dirname(__DIR__).'/w4os.php'))->toContain("\$handlers->add('hypergrid-visit'");
    });
});
