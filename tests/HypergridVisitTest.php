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
    $twig->addFunction(new TwigFunction('w4os_tp_link', fn (?string $destination, ?string $label = null) => W4OS\Views\TeleportLink::html(['browser' => [['format' => 8, 'link' => "hop://$destination", 'label' => 'hop'], ['format' => 1024, 'link' => (string) $destination, 'label' => 'Copy']]], (string) $label), ['is_safe' => ['html']]));
    $twig->addFilter(new TwigFilter('t', fn (string $key, ...$values) => $values === [] ? $key : $key.'('.implode(',', $values).')'));
    $assets = new class {
        public function addJs(string $url): void {}
    };

    return $twig->render('w4os/hypergrid-visit.html.twig', $context + ['assets' => $assets]);
}

describe('the visit of the grid from another one', function () {
    test('is the gatekeeper as host:port, none without a gatekeeper', function () {
        expect(Site::hypergridVisit('http://demo.w4os.org:3405'))->toBe(['address' => 'demo.w4os.org:3405'])
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
        $html = render_visit(['address' => 'demo.w4os.org:3405']);

        expect($html)->toContain('<aside class="w4os-callout w4os-hypergrid-visit">', '<h2 class="w4os-callout-title">w4os.hg_visit_title</h2>', '<p>w4os.hg_visit_text</p>')
            ->and($html)->toContain('<code class="w4os-login-uri">demo.w4os.org:3405</code>', 'data-w4os-copy="demo.w4os.org&#x3A;3405"')
            // Its teleport link, the one of every place (w4os_tp_link): the main link of the client and its menu
            ->and($html)->toContain('<p class="w4os-hypergrid-go"><span class="w4os-tp" data-w4os-tp data-client="browser"><a class="w4os-tp-main" href="hop://demo.w4os.org:3405"', '>w4os.hg_visit_go</a>', 'w4os-tp-copy')
            ->and(render_visit(['address' => 'a.org:8002'], ['hg_title' => 'Hypergrid', 'hg_level' => 'h3']))->toContain('<h3 class="w4os-callout-title">Hypergrid</h3>');
    });

    test('is a shortcode of the site, its title and level as options', function () {
        $codes = array_column(Site::SHORTCODES, 'code');
        $visit = Site::SHORTCODES[array_search('[hypergrid-visit]', $codes, true)];

        expect($codes)->toContain('[hypergrid-visit]')
            ->and(array_column($visit['options'], 'option'))->toBe(['title', 'level=h2'])
            ->and((string) file_get_contents(dirname(__DIR__).'/w4os.php'))->toContain("\$handlers->add('hypergrid-visit'");
    });

    test('names the grids an avatar may come from, as the site sets them, joined as the language lists choices; none, the text without', function () {
        $with = render_visit(['address' => 'a.org:8002', 'examples' => 'Wolf Territories, Groovy Verse ou Kitely']);

        expect($with)->toContain('<p>w4os.hg_visit_text_examples(Wolf Territories, Groovy Verse ou Kitely)</p>')
            ->and(render_visit(['address' => 'a.org:8002', 'examples' => '']))->toContain('<p>w4os.hg_visit_text</p>')
            ->and(Site::joinOr(['Wolf Territories', 'Groovy Verse', 'Alternate Metaverse', 'Kitely'], '%1$s ou %2$s'))->toBe('Wolf Territories, Groovy Verse, Alternate Metaverse ou Kitely')
            ->and(Site::joinOr(['Kitely', ' '], '%1$s or %2$s'))->toBe('Kitely')
            ->and(Site::joinOr([], '%1$s or %2$s'))->toBe('')
            ->and(Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__).'/w4os.yaml')['hypergrid'])->toBe(['examples' => ['Wolf Territories', 'Groovy Verse', 'GBG World', 'Alternate Metaverse', 'DigiWorldz', 'New Life Italy', 'Neverworld', "Darkheart's Playground", 'Cave Grid', 'Kitely'], 'count' => 3])
            ->and((string) file_get_contents(dirname(__DIR__).'/blueprints.yaml'))->toContain('hypergrid.examples:', 'hypergrid.count:');
    });

    test('names a few of the grids, the same all day, others another day, all of them with a count of 0, none without a list', function () {
        $grids = Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__).'/w4os.yaml')['hypergrid']['examples'];
        $today = Site::examplesOfDay($grids, 3, '2026-11-21');
        $week = array_map(fn (int $day) => Site::examplesOfDay($grids, 3, sprintf('2026-11-%02d', $day)), range(21, 27));

        expect($today)->toHaveCount(3)
            ->and(array_diff($today, $grids))->toBe([])
            ->and(Site::examplesOfDay($grids, 3, '2026-11-21'))->toBe($today)
            ->and(count(array_unique(array_map('serialize', $week))))->toBeGreaterThan(1)
            ->and(Site::examplesOfDay($grids, 0, '2026-11-21'))->toHaveCount(10)
            ->and(Site::examplesOfDay(['Kitely', ' ', 'Kitely'], 3, '2026-11-21'))->toBe(['Kitely'])
            ->and(Site::examplesOfDay([], 3, '2026-11-21'))->toBe([]);
    });
});
