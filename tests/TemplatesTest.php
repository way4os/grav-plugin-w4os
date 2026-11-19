<?php

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** The indicators and the menu of a row, as w4os/list.html.twig draws them from its view (w4os-views' ListView). */
function row_html(array $list, array $item, array $links = [], string $icons = 'ti'): array
{
    $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
    $twig->addFunction(new TwigFunction('w4os_icon', fn (string $icon) => "$icons-$icon"));
    $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => ''));
    $twig->registerUndefinedFilterCallback(fn (string $name) => new TwigFilter($name, fn ($value) => (string) $value));
    $twig->addFilter(new TwigFilter('t', fn ($key) => "[$key]"));
    $row = W4OS\Views\ListView::of($list, [$item], [], [], $links)->rows[0];
    $template = $twig->createTemplate("{% import 'w4os/list-cells.html.twig' as cells %}{% for name, indicators in row.indicators %}{{ cells.indicators(indicators, row) }}{% endfor %}|{{ cells.menu(row) }}");

    return array_combine(['indicators', 'menu'], explode('|', $template->render(['row' => $row]), 2));
}

describe('templates', function () {
    test('every template of the plugin compiles', function () {
        $loader = new FilesystemLoader(dirname(__DIR__).'/templates');
        $twig = new Environment($loader);
        // The functions and filters of Grav and of the plugin: their names only matter here
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => null));
        $twig->registerUndefinedFilterCallback(fn (string $name) => new TwigFilter($name, fn ($v) => $v));
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/templates', FilesystemIterator::SKIP_DOTS));
        $compiled = 0;
        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), '.twig')) {
                $twig->compileSource($twig->getLoader()->getSourceContext(substr($file->getPathname(), strlen(dirname(__DIR__).'/templates/'))));
                $compiled++;
            }
        }

        expect($compiled)->toBeGreaterThan(5);
    });

    test('the menu of a row links to the address the row gives (its profile page), and to its edit page by its UUID', function () {
        $links = [['key' => 'action_view', 'attribute' => 'profilePage'], ['key' => 'action_edit', 'url' => '/edit?avatar={uuid}']];
        $list = ['columns' => [['name' => 'name']], 'actions' => []];
        $ada = row_html($list, ['uuid' => 'u1', 'name' => 'Ada  Love', 'profilePage' => 'https://grid.example.org/profile/Ada.Love'], $links)['menu'];

        expect($ada)->toContain('href="https&#x3A;&#x2F;&#x2F;grid.example.org&#x2F;profile&#x2F;Ada.Love"', 'href="&#x2F;edit&#x3F;avatar&#x3D;u1"')
            ->and(row_html($list, ['uuid' => 'u2', 'name' => 'Model Avatar'], $links)['menu'])->not->toContain('w4os.action_view')->toContain('w4os.action_edit');
    });

    test('an action the engine asks to confirm (ask) is confirmed before it is sent, in the language of the page', function () {
        $list = ['columns' => [['name' => 'name']], 'actions' => [
            ['name' => 'stop', 'label' => 'action_stop', 'ask' => 'ask_stop_instance', 'method' => 'POST', 'path' => '/x'],
            ['name' => 'start', 'label' => 'action_start', 'method' => 'POST', 'path' => '/y'],
        ]];
        $html = row_html($list, ['id' => 'h:9040', 'name' => 'Test Solo'])['menu'];

        expect($html)->toContain('<button name="action" value="stop" data-w4os-ask="&#x5B;w4os.ask_stop_instance&#x5D;" onclick="return confirm(this.dataset.w4osAsk)" class="dropdown-item text-danger">')
            ->and($html)->toContain('<button name="action" value="start" class="dropdown-item">');
    });

    test('a column with a picture (a region\'s map) shows it as a square thumbnail by its value, the default one when there is none', function () {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->addFunction(new TwigFunction('w4os_picture', fn (?string $uuid, ?string $default = null) => '/pictures/'.($uuid ?? "none-$default").'.jpg'));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => null));
        $twig->registerUndefinedFilterCallback(fn (string $name) => new TwigFilter($name, fn ($value) => (string) $value));
        $twig->addFilter(new TwigFilter('t', fn ($key) => $key));
        $cell = $twig->createTemplate("{% import 'w4os/list-cells.html.twig' as cells %}{{ cells.cell(column, item) }}");
        $map = ['name' => 'name', 'type' => 'text', 'picture' => 'mapImage', 'default' => 'place'];
        $person = ['name' => 'name', 'type' => 'text', 'picture' => 'profileImage', 'default' => 'person'];

        expect($cell->render(['column' => $map, 'item' => ['name' => 'Test East', 'mapImage' => 'm1']]))
            ->toContain('<img class="w4os-thumb" src="&#x2F;pictures&#x2F;m1.jpg"', 'Test East')
            ->and($cell->render(['column' => $map, 'item' => ['name' => 'Test North']]))->toContain('src="&#x2F;pictures&#x2F;none-place.jpg"')
            ->and($cell->render(['column' => $person, 'item' => ['name' => 'Ada Love']]))->toContain('class="w4os-thumb w4os-thumb-person"', 'none-person');
    });

    test('an area reads in km² as the engine says it (two decimals under 10, none above), a usage as a bar in the colour of its thresholds, of its total when it has one', function () {
        expect(W4os\Grav\Site::areaText(65536))->toBe(['unit_km2', '0.07'])
            ->and(W4os\Grav\Site::areaText(4194304))->toBe(['unit_km2', '4.19'])
            ->and(W4os\Grav\Site::areaText(26214400))->toBe(['unit_km2', '26'])
            // In the language of the page, by the formatter of PHP (Intl)
            ->and(W4os\Grav\Site::areaText(524288, 'fr'))->toBe(['unit_km2', '0,52'])
            ->and(str_replace(["\u{202F}", "\u{A0}"], ' ', W4os\Grav\Site::number(15565, 'fr')))->toBe('15 565')
            ->and(W4os\Grav\Site::number(15565, 'de'))->toBe('15.565')
            ->and(W4os\Grav\Site::number(12.5, 'pt-BR'))->toBe('12,5');
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->addFunction(new TwigFunction('w4os_area', fn ($m2) => implode(':', W4os\Grav\Site::areaText((float) $m2))));
        $twig->addFunction(new TwigFunction('w4os_number', fn ($value, int $decimals = 2) => W4os\Grav\Site::number((float) $value, 'en', $decimals)));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => null));
        $twig->registerUndefinedFilterCallback(fn (string $name) => new TwigFilter($name, fn ($value) => (string) $value));
        $twig->addFilter(new TwigFilter('t', fn ($key, ...$args) => $args ? "[$key:".implode('|', $args).']' : "[$key]"));
        $cell = $twig->createTemplate("{% import 'w4os/list-cells.html.twig' as cells %}{{ cells.cell(column, item) }}");
        $columns = array_column((new W4OS\Views\Contract(OpenSim\Api\Contract::document()))->listOf('simulators')['columns'], null, 'name');

        expect($cell->render(['column' => $columns['area'], 'item' => ['area' => 65536]]))->toContain('unit_km2:0.07')
            ->and($cell->render(['column' => $columns['cpu'], 'item' => ['cpu' => 75]]))->toContain('progress-bar bg-warning', 'width: 75%', '75 %')
            ->and($cell->render(['column' => $columns['cpu'], 'item' => ['cpu' => 12.5]]))->toContain('bg-success', 'width: 12.5%')
            ->and($cell->render(['column' => $columns['memory'], 'item' => ['memory' => 95, 'memoryUsed' => 15565, 'memoryTotal' => 16384]]))
            ->toContain('bg-danger', '[w4os.usage_of:[w4os.unit_mb:15,565]|[w4os.unit_mb:16,384]]')
            ->and($cell->render(['column' => $columns['cpu'], 'item' => []]))->not->toContain('progress');
        // The size of a region: its width and its height in meters (the engine's unit_dimensions), in the language of the page
        $size = array_column(array_column((new W4OS\Views\Contract(OpenSim\Api\Contract::document()))->listOf('regions')['columns'], null, 'name')['place']['parts'], null, 'name')['size'];
        expect($cell->render(['column' => $size, 'item' => ['size' => 512, 'width' => 512, 'height' => 1024]]))->toContain('[w4os.unit_dimensions:512|1,024]')->not->toContain('text-nowrap')
            ->and($cell->render(['column' => $size, 'item' => ['size' => 256]]))->toContain('[w4os.unit_dimensions:256|256]');

        // Several values in one column (a group): each its label and its value, one under the other, an empty one left out
        $regions = array_column((new W4OS\Views\Contract(OpenSim\Api\Contract::document()))->listOf('regions')['columns'], null, 'name');
        $usage = $cell->render(['column' => $regions['usage'], 'item' => ['avatars' => 1, 'prims' => 2150]]);
        expect($usage)->toContain('<div class="w4os-group">', '<div><span class="text-secondary">[w4os.column_presence]</span> 1</div>', '<div><span class="text-secondary">[w4os.column_prims]</span> 2150</div>')
            ->and($usage)->not->toContain('column_scripts')
            ->and($cell->render(['column' => $regions['place'], 'item' => ['location' => '8015, 8012', 'size' => 256]]))
            ->toContain('[w4os.profile_location]</span> 8015, 8012</div>', '[w4os.column_size]</span> [w4os.unit_dimensions:256|256]</div>')
            ->and($cell->render(['column' => $regions['usage'], 'item' => []]))->not->toContain('w4os-group');
    });

    test('an indicators column shows an icon by state (its tone, its title), its action as the button of the icon, nothing for a value it does not list', function () {
        $list = (new W4OS\Views\Contract(OpenSim\Api\Contract::document()))->listOf('simulators');
        $row = ['id' => 'h:9040', 'slug' => 'demo.solo', 'name' => 'Test Solo', 'enabled' => true, 'running' => true, 'state' => 'running', 'connections' => ['console' => 'ok', 'screen' => 'failed', 'config' => 'configured', 'regions' => 'none', 'mysql' => 'none', 'sqlite' => 'none']];
        ['indicators' => $cell, 'menu' => $menu] = row_html($list, $row, [], 'fa');

        expect($cell)->toContain('class="w4os-indicator w4os-tone-ok"', 'fa-power', 'fa-play', 'fa-console', 'w4os-tone-failed', 'fa-screen', 'w4os-tone-off', 'fa-file')
            ->and($cell)->toContain('title="&#x5B;w4os.state_enabled&#x5D;"', '<span class="sr-only visually-hidden">[w4os.state_enabled] (')
            ->and($cell)->not->toContain('column_enabled')
            // The action the row meets is the button of its icon, with its confirmation
            ->and($cell)->toContain('value="disable"', 'value="stop" data-w4os-ask="&#x5B;w4os.ask_stop_instance&#x5D;"')
            ->and($cell)->not->toContain('fa-files', 'fa-database', 'fa-pause', 'value="start"', 'value="enable"')
            // The row's menu keeps the other actions only
            ->and($menu)->toContain('value="restart"')
            ->and($menu)->not->toContain('value="stop"', 'value="disable"');
    });
    test('an indicator shows the icon of its state when the state has one, its own else', function () {
        $list = ['columns' => [['name' => 'name'], ['name' => 'status', 'type' => 'indicators', 'indicators' => [['name' => 'running', 'label' => 'running', 'icon' => 'run', 'states' => [
            ['value' => true, 'label' => 'state_running', 'tone' => 'ok', 'icon' => 'pause'],
            ['value' => false, 'label' => 'state_stopped', 'tone' => 'off'],
        ]]]]]];

        expect(row_html($list, ['running' => true])['indicators'])->toContain('ti-pause', 'w4os-tone-ok')->not->toContain('ti-run')
            ->and(row_html($list, ['running' => false])['indicators'])->toContain('ti-run', 'w4os-tone-off');
    });

    test('a ban, a presence show by their icons, never by their color alone: a user banned or online, the world cancelled or crossed', function () {
        $api = new W4OS\Views\Contract(OpenSim\Api\Contract::document());
        $avatars = ['actions' => []] + $api->listOf('avatars');
        $regions = ['actions' => []] + $api->listOf('regions');
        $hard = row_html($avatars, ['uuid' => 'a1', 'accountState' => 'banned', 'ban' => 'hard'])['indicators'];
        $estates = row_html($avatars, ['uuid' => 'a2', 'accountState' => 'active', 'ban' => 'estates'])['indicators'];
        $online = row_html($avatars, ['uuid' => 'a3', 'accountState' => 'online', 'ban' => null])['indicators'];

        expect($hard)->toMatch('~w4os-tone-failed[^>]*><i class="ti-account-banned"~')->toMatch('~w4os-tone-failed[^>]*><i class="ti-ban"~')
            ->and($estates)->toMatch('~w4os-tone-ok[^>]*><i class="ti-account"~')->toMatch('~w4os-tone-warning[^>]*><i class="ti-ban"~')->not->toContain('ti-account-banned')
            ->and($online)->toMatch('~w4os-tone-ok[^>]*><i class="ti-account-online"~')->not->toContain('ti-ban')
            ->and(row_html($regions, ['uuid' => 'r1', 'enabled' => true, 'online' => false])['indicators'])->toMatch('~w4os-tone-off[^>]*><i class="ti-offline"~')
            ->and(row_html($regions, ['uuid' => 'r1', 'enabled' => true, 'online' => true])['indicators'])->toMatch('~w4os-tone-ok[^>]*><i class="ti-online"~');
    });
});
