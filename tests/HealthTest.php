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

        expect($html)->toMatch('~<span class="w4os-health-checked" title="[^"]+">\[w4os.admin_health_checked:2 minutes ago\]</span>~')
            ->and($html)->toContain('href="/w4os/health?refresh=1"')
            ->and(substr_count($html, '2 hours ago'))->toBe(1)
            ->and($render(['health' => $health, 'refreshError' => 'Idle timeout reached']))->toContain('[w4os.admin_health_refresh_failed:Idle timeout reached|2 minutes ago]');
    });

    test('shows each kind of line with its own columns, nothing where a value means nothing, the reason of what is not set', function () {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->addFunction(new TwigFunction('w4os_health', fn () => ['health' => ['level' => 'error', 'checkedAt' => time(), 'groups' => [
            ['file' => 'Robust.HG.ini', 'title' => '[GridInfoService]', 'rows' => [
                ['id' => 'a1', 'kind' => 'address', 'label' => 'welcome', 'name' => 'setting_welcome', 'description' => 'setting_welcome_help', 'url' => 'http://grid.example.org/helpers/welcome', 'level' => 'error', 'status' => '404', 'expectedStatus' => '2xx', 'ms' => 3, 'checkedAt' => time()],
                ['id' => 'a2', 'kind' => 'address', 'label' => 'economy', 'name' => 'setting_economy', 'level' => 'unset', 'reason' => 'health_unset', 'checkedAt' => time()],
                ['id' => 'v1', 'kind' => 'value', 'label' => 'gridname', 'text' => 'Friendly Fowl', 'level' => 'none', 'checkedAt' => time()],
            ]],
            ['file' => 'PHP', 'title' => '', 'rows' => [['id' => 'r1', 'kind' => 'requirement', 'label' => 'xmlrpc', 'installed' => false, 'version' => null, 'level' => 'error', 'status' => 'missing', 'checkedAt' => time()]]],
            ['file' => 'cache', 'title' => '', 'rows' => [['id' => 'f1', 'kind' => 'folder', 'label' => 'assets', 'path' => '/var/cache/opensim/demo/assets', 'exists' => false, 'writable' => true, 'level' => 'ok', 'status' => 'can be made', 'checkedAt' => time()]]],
        ]], 'addresses' => null, 'error' => null, 'refreshError' => null]));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => ''));
        $twig->addFilter(new TwigFilter('t', fn ($key, ...$args) => "[$key".($args ? ':'.implode('|', $args) : '').']'));
        $twig->addFilter(new TwigFilter('w4os_date', fn ($value, string $style = 'date') => 'now'));
        $html = $twig->render('w4os/health.html.twig', ['page' => ['route' => '/w4os/health'], 'assets' => new class {
            public function addJs(string $url, array $options = []): void {}
        }]);

        expect($html)->toContain('<tr data-check="a1" class="w4os-health-error">', '[w4os.admin_health_expected]', '2xx', '404', '[w4os.setting_welcome]')
            ->and($html)->toContain('[w4os.health_unset]')
            ->and($html)->toContain('[w4os.admin_health_extension]', '[w4os.admin_health_missing]')
            ->and($html)->toContain('[w4os.admin_health_path]', '/var/cache/opensim/demo/assets', '[w4os.admin_health_writable]')
            ->and($html)->not->toContain('—');
    });

    test('gives one line checked again, as the page shows it', function () {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->addFilter(new TwigFilter('t', fn ($key, ...$args) => "[$key]"));
        $twig->addFilter(new TwigFilter('w4os_date', fn ($value, string $style = 'date') => "at $value"));
        $html = $twig->render('w4os/health-row.html.twig', ['row' => ['id' => 'a1', 'kind' => 'address', 'label' => 'login', 'url' => 'http://grid.example.org:8002', 'level' => 'ok', 'status' => '200', 'ms' => 12, 'checkedAt' => 1795059606]]);

        expect($html)->toContain('<tr data-check="a1" class="w4os-health-ok">', 'at 1795059606', '12 ms');
    });

    test('lists the addresses of the grid by their use, then their file and section, each its key, its name, its address and where it comes from', function () {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->addFunction(new TwigFunction('w4os_health', fn () => ['health' => null, 'error' => null, 'refreshError' => null, 'addresses' => ['services' => [
            ['key' => 'motd', 'url' => 'http://grid.example.org/helpers/motd.php', 'source' => 'config', 'usage' => 'page', 'file' => 'Robust.HG.ini', 'section' => 'LoginService', 'setting' => 'MessageUrl', 'label' => 'service_motd', 'description' => 'service_motd_help'],
            ['key' => 'asset', 'url' => 'http://grid.example.org:8003', 'source' => 'provider', 'usage' => 'robust-simulators', 'file' => 'OpenSim.ini', 'section' => 'AssetService', 'setting' => 'AssetServerURI', 'label' => 'service_asset', 'description' => 'service_asset_help'],
            ['key' => 'parser', 'url' => 'http://grid.example.org/helpers/parser.php', 'source' => 'default', 'usage' => 'task', 'file' => null, 'section' => null, 'setting' => null, 'label' => 'service_parser', 'description' => 'service_parser_help'],
            ['key' => 'guide', 'url' => null, 'source' => null, 'usage' => 'page', 'file' => 'Robust.HG.ini', 'section' => 'LoginService', 'setting' => 'DestinationGuide', 'label' => 'service_guide', 'description' => 'service_guide_help'],
        ]]]));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => ''));
        $twig->addFilter(new TwigFilter('t', fn ($key, ...$args) => "[$key]"));
        $twig->addFilter(new TwigFilter('w4os_date', fn ($value, string $style = 'date') => (string) $value));
        $html = $twig->render('w4os/health.html.twig', ['page' => []]);

        // The Robust services first, then the pages, then the tasks
        expect($html)->toMatch('~\[w4os.usage_robust_simulators\].*\[w4os.usage_page\].*\[w4os.usage_task\]~s')
            ->and($html)->toContain('<code>OpenSim.ini</code> <span class="text-secondary">[AssetService]</span>', '<code>AssetServerURI</code>', '[w4os.service_asset]', 'title="&#x5B;w4os.service_asset_help&#x5D;"', '[w4os.admin_health_source_provider]')
            ->and($html)->toContain('<code>MessageUrl</code>', '>http://grid.example.org/helpers/motd.php</a>', '[w4os.admin_health_source_config]')
            ->and($html)->toContain('[w4os.admin_health_source_default]', '[w4os.health_unset]')
            ->and(substr_count($html, '[LoginService]'))->toBe(1)
            ->and($html)->not->toContain('—');
    });

    test('says each setting in its full context, what to write where it is not set, how to repair an instance', function () {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->addFunction(new TwigFunction('w4os_health', fn () => ['health' => ['level' => 'error', 'checkedAt' => 1000, 'groups' => [
            ['file' => 'Robust.HG.ini', 'title' => '[LoginService]', 'rows' => [
                ['id' => 'g1', 'kind' => 'address', 'label' => 'DestinationGuide', 'level' => 'unset', 'reason' => 'health_unset', 'context' => ['instance' => 'robust', 'file' => 'Robust.HG.ini', 'section' => 'LoginService', 'key' => 'DestinationGuide'], 'expected' => '${Const|WebURL}/guide', 'snippet' => "[LoginService]\n    DestinationGuide = \"\${Const|WebURL}/guide\"", 'checkedAt' => 1000],
            ]],
            ['file' => 'options', 'title' => '', 'rows' => [
                ['id' => 'o1', 'kind' => 'option', 'label' => 'alpha', 'missing' => ['bakes'], 'level' => 'error', 'status' => 'missing: bakes', 'fix' => 'POST /grids/{grid}/options/repair', 'checkedAt' => 1000],
            ]],
        ]], 'addresses' => null, 'error' => null, 'refreshError' => null]));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => ''));
        $twig->addFilter(new TwigFilter('t', fn ($key, ...$args) => "[$key]"));
        $twig->addFilter(new TwigFilter('w4os_date', fn ($value, string $style = 'date') => 'now'));
        $html = $twig->render('w4os/health.html.twig', ['page' => [], 'assets' => new class {
            public function addJs(string $url, array $options = []): void {}
        }]);

        // The instance of the file of a group, the full context of each setting as its tip
        expect($html)->toContain('<span class="badge bg-secondary-lt">robust</span> <code>Robust.HG.ini</code>')
            ->and($html)->toContain('${Const|WebURL}/guide', '<pre class="mb-0 small"><code>[LoginService]')
            ->and($html)->toContain('POST /grids/{grid}/options/repair');
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
