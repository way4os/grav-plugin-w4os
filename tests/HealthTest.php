<?php

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use W4os\Grav\Site;

describe('the health page of the admin', function () {
    test('lives under the admin route, in the admin menu, for whom the contract lets read the health of the grid', function () {
        expect(Site::adminRoutes(['admin' => '/w4os'], [])['health'])->toBe('/w4os/health')
            ->and(Site::ADMIN_PAGES)->toHaveKey('health')
            ->and(Site::ADMIN_OPERATIONS['health'])->toBe(['get', '/grids/{grid}/health']);
    });

    test('shows what the engine checks, group by group (a file and its section), each row its level, address, answer and time', function () {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->addFunction(new TwigFunction('w4os_health', fn () => [
            'health' => ['level' => 'error', 'groups' => [
                ['file' => 'Robust.HG.ini', 'title' => '[LoginService]', 'rows' => [
                    ['label' => 'DestinationGuide', 'url' => 'http://example.org/guide', 'level' => 'error', 'status' => 'cURL error 28', 'ms' => null, 'detail' => 'Operation timed out'],
                    ['label' => 'MessageUrl', 'url' => 'http://example.org/helpers/motd.php', 'level' => 'ok', 'status' => '200', 'ms' => 446, 'tag' => 'Message'],
                ]],
                ['file' => 'get_grid_info', 'title' => '', 'rows' => [['label' => 'gridname', 'url' => '', 'text' => 'Friendly Fowl', 'level' => 'none', 'status' => '', 'ms' => null]]],
            ]],
            'addresses' => ['addresses' => ['motd' => 'http://example.org/helpers/motd.php'], 'sources' => ['motd' => 'config'], 'servedBy' => ['welcome' => 'helpers']],
            'error' => null,
        ]));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => ''));
        $twig->addFilter(new TwigFilter('t', fn ($key, ...$args) => "[$key]"));
        $html = $twig->render('w4os/health.html.twig', ['page' => ['route' => '/w4os/health']]);

        expect($html)->toContain('alert alert-danger', 'Robust.HG.ini', '[LoginService]', 'DestinationGuide', '>http://example.org/guide</a>', 'cURL error 28', 'Operation timed out')
            ->and($html)->toContain('ti ti-circle-x', 'ti ti-circle-check', '446', 'Message', 'Friendly Fowl')
            ->and($html)->toContain('motd', 'config', '[w4os.admin_health_check]');
    });

    test('says why when the engine does not answer', function () {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->addFunction(new TwigFunction('w4os_health', fn () => ['health' => null, 'addresses' => null, 'error' => 'The engine API cannot be reached']));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => ''));
        $twig->addFilter(new TwigFilter('t', fn ($key, ...$args) => "[$key]"));

        expect($twig->render('w4os/health.html.twig', ['page' => []]))->toContain('alert alert-danger', 'The engine API cannot be reached');
    });
});
