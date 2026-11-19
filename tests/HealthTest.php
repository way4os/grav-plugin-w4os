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
        $twig->addFilter(new TwigFilter('w4os_date', fn ($value, string $style = 'date') => (string) $value));
        $html = $twig->render('w4os/health.html.twig', ['page' => ['route' => '/w4os/health']]);

        expect($html)->toContain('alert alert-danger', 'Robust.HG.ini', '[LoginService]', 'DestinationGuide', '>http://example.org/guide</a>', 'cURL error 28', 'Operation timed out')
            ->and($html)->toContain('ti ti-circle-x', 'ti ti-circle-check', '446', 'Message', 'Friendly Fowl')
            ->and($html)->toContain('motd', 'config', '[w4os.admin_health_check]');
    });

    test('is checked again when asked, else the last report; a check that cannot run says why, with the last report', function () {
        $read = fn (bool $refresh) => $refresh ? throw new RuntimeException('Idle timeout reached') : ['level' => 'error', 'checkedAt' => 1795058400];

        expect(Site::healthReport(fn (bool $refresh) => ['level' => 'ok', 'checkedAt' => $refresh ? 1795058484 : 1795058400], true))->toMatchArray(['health' => ['level' => 'ok', 'checkedAt' => 1795058484], 'error' => null, 'refreshError' => null])
            ->and(Site::healthReport(fn (bool $refresh) => ['level' => 'ok', 'checkedAt' => $refresh ? 1795058484 : 1795058400], false)['health']['checkedAt'])->toBe(1795058400)
            ->and(Site::healthReport($read, true))->toMatchArray(['health' => ['level' => 'error', 'checkedAt' => 1795058400], 'error' => null, 'refreshError' => 'Idle timeout reached'])
            ->and(Site::healthReport(fn () => throw new RuntimeException('down'), false))->toMatchArray(['health' => null, 'error' => 'down']);
    });

    test('says when it was checked, each row its own time where it differs; a check that could not run, with the time of the report', function () {
        $now = time();
        $render = function (array $data) {
            $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
            $twig->addFunction(new TwigFunction('w4os_health', fn () => $data + ['addresses' => null, 'error' => null, 'refreshError' => null]));
            $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => ''));
            $twig->addFilter(new TwigFilter('t', fn ($key, ...$args) => "[$key".($args ? ':'.implode('|', $args) : '').']'));
            $twig->addFilter(new TwigFilter('w4os_date', fn ($value, string $style = 'date') => W4OS\Views\Dates::format($value, 'en', $style)));

            return $twig->render('w4os/health.html.twig', ['page' => ['route' => '/w4os/health']]);
        };
        $health = ['level' => 'ok', 'checkedAt' => $now - 120, 'groups' => [['file' => 'get_grid_info', 'title' => '', 'rows' => [
            ['label' => 'login', 'url' => 'http://grid.example.org:8002', 'level' => 'ok', 'status' => '200', 'ms' => 12, 'checkedAt' => $now - 120],
            ['label' => 'welcome', 'url' => 'http://grid.example.org/welcome', 'level' => 'error', 'status' => '404', 'ms' => 3, 'checkedAt' => $now - 7200],
        ]]]];
        $html = $render(['health' => $health]);

        expect($html)->toMatch('~<span title="[^"]+">\[w4os.admin_health_checked:2 minutes ago\]</span>~')
            ->and($html)->toContain('href="/w4os/health?refresh=1"')
            ->and(substr_count($html, '2 hours ago'))->toBe(1)
            ->and($render(['health' => $health, 'refreshError' => 'Idle timeout reached']))->toContain('[w4os.admin_health_refresh_failed:Idle timeout reached|2 minutes ago]');
    });

    test('says why when the engine does not answer', function () {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->addFunction(new TwigFunction('w4os_health', fn () => ['health' => null, 'addresses' => null, 'error' => 'The engine API cannot be reached']));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => ''));
        $twig->addFilter(new TwigFilter('t', fn ($key, ...$args) => "[$key]"));
        $twig->addFilter(new TwigFilter('w4os_date', fn ($value, string $style = 'date') => (string) $value));

        expect($twig->render('w4os/health.html.twig', ['page' => []]))->toContain('alert alert-danger', 'The engine API cannot be reached');
    });
});
