<?php

use OpenSim\Api\Contract;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use W4os\Grav\OpenApi;
use W4os\Grav\Site;

describe('the dashboard', function () {
    $api = new OpenApi(Contract::document());
    $render = function (array $cards) {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->addFunction(new TwigFunction('w4os_dashboard', fn () => $cards));
        $twig->addFunction(new TwigFunction('w4os_picture', fn (?string $uuid, ?string $default = null) => "/pictures/$uuid.jpg"));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => ''));
        $twig->addFilter(new TwigFilter('t', fn ($key, ...$args) => $args ? "[$key:".implode('|', $args).']' : "[$key]"));
        $twig->addFilter(new TwigFilter('w4os_date', fn ($value) => Site::date($value, 'fr')));

        return $twig->render('w4os/dashboard.html.twig', ['page' => ['header' => []]]);
    };

    test('has a card by number, what its filters keep in the words of its list, leading to the list so filtered', function () use ($api, $render) {
        $html = $render([['page' => 'regions', 'list' => $api->listOf('regions'), 'filter' => ['status' => 'online'], 'url' => '/regions?status=online', 'total' => 2, 'rows' => 0, 'items' => [], 'item' => null, 'error' => null]]);

        expect($html)->toContain('<a class="card card-link w4os-stat" href="&#x2F;regions&#x3F;status&#x3D;online">', '[w4os.regions_title]', '<div class="h1 mb-1 mt-2">2</div>',
            '[w4os.label_value:[w4os.status]|[w4os.online]]');
    });

    test('lists the first rows of a list, each leading to its page, with the date it is sorted by in the format of the language', function () use ($api, $render) {
        $bart = ['uuid' => 'a1', 'name' => 'Bart Simpson', 'created' => 1794787200, 'profileImage' => null];
        $html = $render([['page' => 'manage', 'list' => $api->listOf('avatars'), 'filter' => [], 'url' => '/avatars', 'total' => 16, 'rows' => 5, 'items' => [$bart], 'item' => ['url' => '/avatars/edit', 'param' => 'avatar'], 'error' => null]]);

        expect($html)->toContain('href="/avatars/edit?avatar=a1"', 'Bart Simpson', '16 nov. 2026', '[w4os.created]')
            ->not->toContain('2026-11-16', 'w4os-stat');
    });

    test('says so in one sentence when a list has no row', function () use ($api, $render) {
        $html = $render([['page' => 'manage', 'list' => $api->listOf('avatars'), 'filter' => [], 'url' => '/avatars', 'total' => 0, 'rows' => 5, 'items' => [], 'item' => null, 'error' => null]]);

        expect($html)->toContain('<p class="empty-title">[w4os.no_avatars]</p>');
    });
});
