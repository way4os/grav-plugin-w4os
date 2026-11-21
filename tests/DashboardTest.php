<?php

use W4OS\Engine\Api\Contract;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use W4OS\Views\Contract as OpenApi;
use W4OS\Grav\Site;

describe('the dashboard', function () {
    $api = new OpenApi(Contract::document());
    $render = function (array $cards) {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->addFunction(link_function());
        $twig->addFunction(new TwigFunction('w4os_dashboard', fn () => $cards));
        $twig->addFunction(new TwigFunction('w4os_picture', fn (?string $uuid, ?string $default = null) => "/pictures/$uuid.jpg"));
        $twig->addFunction(new TwigFunction('w4os_block', fn (string $name, array $args = []) => "block:$name", ['is_safe' => ['html']]));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => ''));
        $twig->addFilter(new TwigFilter('t', fn ($key, ...$args) => $args ? "[$key:".implode('|', $args).']' : "[$key]"));
        $twig->addFilter(new TwigFilter('w4os_date', fn ($value, string $style = 'date') => W4OS\Views\Dates::format($value, 'fr', $style)));

        return $twig->render('w4os/dashboard.html.twig', ['page' => ['header' => []]]);
    };

    test('shows its cards in their order, in columns that leave a small card small, each the same: its title in its header, its link to its list in its footer', function () use ($api, $render) {
        $html = $render([
            ['kind' => 'block', 'block' => 'grid-info', 'title' => 'w4os.dashboard_grid_info', 'args' => []],
            ['kind' => 'count', 'page' => 'regions', 'list' => $api->listOf('regions'), 'part' => 5, 'total' => 7, 'url' => '/regions', 'partUrl' => '/regions?status=online', 'error' => null],
        ]);

        expect($html)->toContain('class="w4os-dashboard"', '<div class="w4os-dashboard-card">')
            ->and(substr_count($html, '<div class="card-header"><h3 class="card-title">'))->toBe(2)
            ->and($html)->toContain('<h3 class="card-title">[w4os.dashboard_grid_info]</h3>', 'block:grid-info')
            ->and(strpos($html, 'block:grid-info'))->toBeLessThan(strpos($html, '[w4os.regions_title]'))
            ->and($html)->not->toContain('col-lg-6', 'row-deck');
    });

    test('says how many of a list are online of all it knows as n/n, its link to the list apart in its footer', function () use ($api, $render) {
        $html = $render([['kind' => 'count', 'page' => 'regions', 'list' => $api->listOf('regions'), 'part' => 5, 'total' => 7, 'url' => '/regions', 'partUrl' => '/regions?status=online', 'error' => null]]);

        expect($html)->toContain('[w4os.regions_title]', '>5/7<', '<div class="card-footer text-end"><a class="w4os-more" href="/regions">[w4os.see_all]</a></div>')
            ->and($html)->not->toContain('regions_online');
    });

    test('puts the counts of a list in one card, its total first, each by the plain name of what it counts, leading to the list so filtered', function () use ($api, $render) {
        $counts = [['filter' => ['online' => 'online'], 'total' => 0, 'url' => '/avatars?online=online'], ['filter' => ['status' => 'pending'], 'total' => 1, 'url' => '/avatars?status=pending'],
            ['filter' => ['type' => 'model'], 'total' => 0, 'url' => '/avatars?type=model'], ['filter' => ['type' => 'service'], 'total' => 2, 'url' => '/avatars?type=service']];
        $html = $render([['kind' => 'counts', 'page' => 'manage', 'list' => $api->listOf('avatars'), 'total' => 16, 'counts' => $counts, 'url' => '/avatars', 'error' => null]]);

        expect(substr_count($html, 'class="card w4os-stat"'))->toBe(1)
            ->and($html)->toContain('[w4os.manage_title]', '[w4os.total]', '>16<', 'href="&#x2F;avatars&#x3F;status&#x3D;pending"', '[w4os.status_pending]', '[w4os.avatar_type_service]', '>2<', '[w4os.see_all]')
            ->and(strpos($html, '[w4os.total]'))->toBeLessThan(strpos($html, '[w4os.online]'))
            ->and($html)->not->toContain('[w4os.label_value');
    });

    test('lists the newest avatars as w4os-wp names them, each leading to its page, with the date in the format of the language, and a link to them all', function () use ($api, $render) {
        $created = time() - 60 * 86400;
        $bart = ['uuid' => 'a1', 'name' => 'Bart Simpson', 'created' => $created, 'profileImage' => null];
        $html = $render([['kind' => 'rows', 'page' => 'manage', 'title' => 'w4os.recent_avatars', 'list' => $api->listOf('avatars'), 'url' => '/avatars', 'items' => [$bart], 'item' => ['url' => '/avatars/edit', 'param' => 'avatar'], 'error' => null]]);

        expect($html)->toContain('[w4os.recent_avatars]', 'href="/avatars/edit?avatar=a1"', 'Bart Simpson', W4OS\Views\Dates::format($created, 'fr', 'list'), '<div class="card-footer text-end"><a class="w4os-more" href="/avatars">[w4os.see_all]</a></div>')
            ->not->toContain(date('Y-m-d', $created));
    });

    test('says so in one sentence when a list has no row', function () use ($api, $render) {
        $html = $render([['kind' => 'rows', 'page' => 'manage', 'title' => 'w4os.recent_avatars', 'list' => $api->listOf('avatars'), 'url' => '/avatars', 'items' => [], 'item' => null, 'error' => null]]);

        expect($html)->toContain('<p class="empty-title">[w4os.no_avatars]</p>');
    });

    test('says the problems the health of the grid found, as w4os-wp says them in its notices, leading to the Health page', function () {
        $health = ['level' => 'error', 'groups' => [
            ['file' => 'Robust.HG.ini', 'rows' => [['label' => 'LoginURI', 'level' => 'ok'], ['label' => 'SRV_ProfileServerURI', 'level' => 'error']]],
            ['file' => 'PHP', 'rows' => [['label' => 'imagick JPEG 2000', 'level' => 'error'], ['label' => 'intl', 'level' => 'slow']]],
        ]];
        $problems = Site::healthProblems($health);
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->addFunction(link_function());
        $twig->addFunction(new TwigFunction('w4os_dashboard', fn () => []));
        $twig->addFunction(new TwigFunction('w4os_health_problems', fn () => $problems + ['url' => '/w4os/health']));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => ''));
        $twig->addFilter(new TwigFilter('t', fn ($key, ...$args) => $args ? "[$key:".implode('|', $args).']' : "[$key]"));
        $twig->addFilter(new TwigFilter('w4os_date', fn ($value) => (string) $value));
        $html = $twig->render('w4os/dashboard.html.twig', ['page' => ['header' => []]]);

        expect($problems)->toMatchArray(['count' => 2, 'labels' => ['SRV_ProfileServerURI', 'imagick JPEG 2000'], 'statuses' => ['', '']])
            ->and(Site::healthProblems(['level' => 'ok', 'groups' => []]))->toMatchArray(['count' => 0])
            ->and($html)->toContain('alert-danger', '[w4os.admin_health_problems:2]', 'SRV_ProfileServerURI', 'href="&#x2F;w4os&#x2F;health"');
    });
});
