<?php

use W4OS\Grav\Site;

/** A page of the grid as the engine gives it (GET/PUT site-pages). */
function site_page_document(string $page, ?string $url, bool $declared, array $meta = []): array
{
    return ['data' => ['type' => 'site-pages', 'id' => $page, 'attributes' => ['url' => $url, 'declared' => $declared, 'servedBy' => $url === null ? null : 'site']]] + ($meta === [] ? [] : ['meta' => $meta]);
}

describe('the pages of the grid the site serves', function () {
    test('are its own pages at the base of the site, prefix included, the registration only while it is open', function () {
        $routes = ['profile' => '/profile', 'search' => '/search', 'guide' => '/guide', 'welcome' => '/welcome', 'register' => '/register', 'password' => '/password', 'account' => '/account', 'dashboard' => '/w4os'];
        $pages = Site::servedPages($routes, 'https://kimbe.example.org/opensim/');

        expect($pages)->toBe([
            'profile' => 'https://kimbe.example.org/opensim/profile',
            'search' => 'https://kimbe.example.org/opensim/search',
            'guide' => 'https://kimbe.example.org/opensim/guide',
            'welcome' => 'https://kimbe.example.org/opensim/welcome',
            'register' => 'https://kimbe.example.org/opensim/register',
            'password' => 'https://kimbe.example.org/opensim/password',
        ])
            // Registration closed: its route is register_closed (Site::routes()); a page without a route, none
            ->and(Site::servedPages(['profile' => '/profile', 'register_closed' => '/register'], 'https://example.org'))->toBe(['profile' => 'https://example.org/profile']);

        return $pages;
    });

    test('are declared again when they moved on the site (its prefix, a route), never for another name of its host', function (array $pages) {
        expect(Site::pagesToDeclare($pages, []))->toBe($pages)
            ->and(Site::pagesToDeclare($pages, $pages))->toBe([])
            ->and(Site::pagesToDeclare($pages, array_map(fn ($url) => str_replace('kimbe.example.org', '192.0.2.7', $url), $pages)))->toBe([])
            ->and(Site::pagesToDeclare($pages, array_map(fn ($url) => str_replace('/opensim/', '/', $url), $pages)))->toBe($pages)
            ->and(Site::pagesToDeclare($pages, $pages, true))->toBe($pages)
            ->and(Site::samePage('https://example.org/profile/[AGENT_NAME]', 'https://example.org/profile'))->toBeTrue();
    })->depends('are its own pages at the base of the site, prefix included, the registration only while it is open');

    test('are written by the engine, checked first on a save: none it has as the site declared it, the one it keeps said, never an error', function () {
        $restart = ['code' => 'restart-grid', 'params' => ['restart' => 'opensim restart demo']];
        $engine = fakeEngine([
            // profile: the grid has it as the site declared it, nothing written
            [200, site_page_document('profile', 'https://example.org/profile/[AGENT_NAME]', true)],
            // search: the helpers' one, replaced
            [200, site_page_document('search', 'https://example.org/helpers/search', false)],
            [200, site_page_document('search', 'https://example.org/search', true, ['reminders' => [$restart], 'previous' => 'https://example.org/helpers/search'])],
            // guide: the grid's own, kept
            [200, site_page_document('guide', 'https://other.example.net/guide', false)],
            [409, problem(409, 'the address of the guide page is https://other.example.net/guide', 'page-set-elsewhere')],
            // welcome: written, the same restart
            [200, site_page_document('welcome', null, false)],
            [200, site_page_document('welcome', 'https://example.org/welcome', true, ['reminders' => [$restart]])],
        ], $log, 'demo');
        $pages = ['profile' => 'https://example.org/profile', 'search' => 'https://example.org/search', 'guide' => 'https://example.org/guide', 'welcome' => 'https://example.org/welcome'];
        $failed = [];
        $done = Site::declareTo($engine, $pages, true, fn (string $page) => "kept: $page", function (string $page, string $why) use (&$failed) {
            $failed[] = $page;
        });

        expect($done)->toBe(['declared' => $pages, 'notes' => [$restart, 'kept: guide']])
            ->and($failed)->toBe([])
            ->and(array_map(fn ($request) => $request['method'].' '.$request['url'], $log))->toBe([
                'GET https://grid.example.org/api/grids/demo/site-pages/profile',
                'GET https://grid.example.org/api/grids/demo/site-pages/search',
                'PUT https://grid.example.org/api/grids/demo/site-pages/search',
                'GET https://grid.example.org/api/grids/demo/site-pages/guide',
                'PUT https://grid.example.org/api/grids/demo/site-pages/guide',
                'GET https://grid.example.org/api/grids/demo/site-pages/welcome',
                'PUT https://grid.example.org/api/grids/demo/site-pages/welcome',
            ])
            ->and($log[2]['body'])->toBe(['data' => ['type' => 'site-pages', 'id' => 'search', 'attributes' => ['url' => 'https://example.org/search']]]);
    });

    test('are asked again when the engine cannot be reached or does not know them yet, kept as declared when it settled them', function () {
        $engine = new W4OS\Grav\Engine('https://grid.example.org/api/', 'osk_test', 'demo', fn () => throw new RuntimeException('Connection refused'));
        $failed = [];
        $done = Site::declareTo($engine, ['profile' => 'https://example.org/profile'], false, fn () => '', function (string $page, string $why) use (&$failed) {
            $failed[] = "$page: $why";
        });

        expect($done)->toBe(['declared' => [], 'notes' => []])
            ->and($failed)->toBe(['profile: The engine API cannot be reached: Connection refused'])
            ->and(Site::declareTo(fakeEngine([[503, problem(503, 'the grid has no files here', 'channel-unavailable')]]), ['profile' => 'https://example.org/profile'], false, fn () => '', fn () => null)['declared'])
            ->toBe(['profile' => 'https://example.org/profile'])
            // An engine of before site-pages
            ->and(Site::declareTo(fakeEngine([[404, problem(404, 'No route')]]), ['profile' => 'https://example.org/profile'], false, fn () => '', fn () => null)['declared'])->toBe([]);
    });

    test('are declared when the settings of the site are saved; never on a page shown: the dashboard offers to announce the ones that are not', function () {
        $plugin = (string) file_get_contents(dirname(__DIR__).'/w4os.php');
        $dashboard = (string) file_get_contents(dirname(__DIR__).'/templates/w4os/dashboard.html.twig');

        expect($plugin)->toContain("\$this->done('w4os.admin_site_saved', \$routes['settings'], notes: \$site->declarePages(true));")
            ->and(substr_count($plugin, 'declarePages('))->toBe(2)
            ->and($plugin)->toContain("\$type === 'dashboard' && (\$_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (\$_POST['action'] ?? '') === 'announce_pages'", "Utils::verifyNonce(\$nonce, 'w4os-pages') || ! \$this->site()->canSettings()")
            ->and($dashboard)->toContain("{% set pending = w4os_pending_pages() %}", "{{ nonce_field('w4os-pages')|raw }}", 'name="action" value="announce_pages"');
    });

    test('are said on the dashboard, those never announced or those that moved, with the button that announces them', function () {
        $twig = new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new Twig\TwigFunction($name, fn () => null));
        $twig->registerUndefinedFilterCallback(fn (string $name) => new Twig\TwigFilter($name, fn ($value) => $value));
        $twig->addFilter(new Twig\TwigFilter('t', fn ($key) => "[$key]"));
        $pending = [];
        $twig->addFunction(new Twig\TwigFunction('w4os_pending_pages', function () use (&$pending) {
            return $pending;
        }));
        $render = function (array $pages) use ($twig, &$pending) {
            $pending = $pages;

            return $twig->render('w4os/dashboard.html.twig');
        };
        $html = $render([['url' => 'https://example.org/opensim/profile', 'moved' => true, 'label' => 'w4os.setting_web_profile'], ['url' => 'https://example.org/opensim/search', 'moved' => false, 'label' => 'w4os.setting_web_search']]);

        expect($html)->toContain('[w4os.admin_site_pages_moved]', '>[w4os.setting_web_profile]</code>, <code title="', 'opensim&#x2F;search">[w4os.setting_web_search]</code>', '[w4os.admin_site_pages_announce]')
            ->and($render([]))->not->toContain('announce_pages');
    });
});
