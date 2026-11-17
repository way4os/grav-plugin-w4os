<?php

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

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
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => null));
        $twig->addFilter(new TwigFilter('t', fn ($key) => $key));
        $menu = $twig->createTemplate("{% import 'w4os/list-cells.html.twig' as cells %}{{ cells.menu(list, item, links) }}");
        $links = [['key' => 'action_view', 'attribute' => 'profilePage'], ['key' => 'action_edit', 'url' => '/edit?avatar={uuid}']];
        $render = fn (array $item) => $menu->render(['list' => ['actions' => []], 'item' => $item, 'links' => $links]);
        $ada = $render(['uuid' => 'u1', 'name' => 'Ada  Love', 'profilePage' => 'https://grid.example.org/profile/Ada.Love']);

        expect($ada)->toContain('href="https&#x3A;&#x2F;&#x2F;grid.example.org&#x2F;profile&#x2F;Ada.Love"', 'href="&#x2F;edit&#x3F;avatar&#x3D;u1"')
            ->and($render(['uuid' => 'u2', 'name' => 'Model Avatar']))->not->toContain('w4os.action_view')->toContain('w4os.action_edit');
    });

    test('an action the engine asks to confirm (ask) is confirmed before it is sent, in the language of the page', function () {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => $name === 'w4os_list_actions' ? [
            ['name' => 'stop', 'label' => 'action_stop', 'ask' => 'ask_stop_instance'],
            ['name' => 'start', 'label' => 'action_start'],
        ] : ''));
        $twig->addFilter(new TwigFilter('t', fn ($key) => "[$key]"));
        $html = $twig->createTemplate("{% import 'w4os/list-cells.html.twig' as cells %}{{ cells.menu(list, item, []) }}")
            ->render(['list' => ['actions' => []], 'item' => ['id' => 'h:9040', 'name' => 'Test Solo']]);

        expect($html)->toContain('<button name="action" value="stop" data-w4os-ask="&#x5B;w4os.ask_stop_instance&#x5D;" onclick="return confirm(this.dataset.w4osAsk)" class="dropdown-item text-danger">')
            ->and($html)->toContain('<button name="action" value="start" class="dropdown-item">');
    });

    test('a column with a picture (a region\'s map) shows it as a square thumbnail by its value, the default one when there is none', function () {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->addFunction(new TwigFunction('w4os_picture', fn (?string $uuid, ?string $default = null) => '/pictures/'.($uuid ?? "none-$default").'.jpg'));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => null));
        $twig->addFilter(new TwigFilter('t', fn ($key) => $key));
        $cell = $twig->createTemplate("{% import 'w4os/list-cells.html.twig' as cells %}{{ cells.cell(column, item) }}");
        $map = ['name' => 'name', 'type' => 'text', 'picture' => 'mapImage', 'default' => 'place'];
        $person = ['name' => 'name', 'type' => 'text', 'picture' => 'profileImage', 'default' => 'person'];

        expect($cell->render(['column' => $map, 'item' => ['name' => 'Test East', 'mapImage' => 'm1']]))
            ->toContain('<img class="w4os-thumb" src="&#x2F;pictures&#x2F;m1.jpg"', 'Test East')
            ->and($cell->render(['column' => $map, 'item' => ['name' => 'Test North']]))->toContain('src="&#x2F;pictures&#x2F;none-place.jpg"')
            ->and($cell->render(['column' => $person, 'item' => ['name' => 'Ada Love']]))->toContain('class="w4os-thumb w4os-thumb-person"', 'none-person');
    });

    test('an indicators column shows an icon by state (its tone, its title), its action as the button of the icon, nothing for a value it does not list', function () {
        $document = OpenSim\Api\Contract::document();
        $list = (new W4os\Grav\OpenApi($document))->listOf('simulators');
        $column = array_column($list['columns'], null, 'name')['status'];
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->addFunction(new TwigFunction('w4os_list_actions', fn (array $list, array $item) => W4os\Grav\Lists::actions($list, $item)));
        $twig->addFunction(new TwigFunction('w4os_icon', fn (string $icon) => "fa-$icon"));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => ''));
        $twig->addFilter(new TwigFilter('t', fn ($key) => "[$key]"));
        $row = ['id' => 'h:9040', 'slug' => 'demo.solo', 'name' => 'Test Solo', 'enabled' => true, 'running' => true, 'state' => 'running', 'connections' => ['console' => 'ok', 'screen' => 'failed', 'config' => 'configured', 'regions' => 'none', 'mysql' => 'none', 'sqlite' => 'none']];
        $cell = $twig->createTemplate("{% import 'w4os/list-cells.html.twig' as cells %}{{ cells.cell(column, item, list) }}")->render(['column' => $column, 'item' => $row, 'list' => $list]);
        $menu = $twig->createTemplate("{% import 'w4os/list-cells.html.twig' as cells %}{{ cells.menu(list, item, []) }}")->render(['list' => $list, 'item' => $row]);

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
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->addFunction(new TwigFunction('w4os_icon', fn (string $icon) => "ti-$icon"));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => []));
        $twig->addFilter(new TwigFilter('t', fn ($key) => "[$key]"));
        $column = ['name' => 'status', 'type' => 'indicators', 'indicators' => [['name' => 'running', 'label' => 'running', 'icon' => 'run', 'states' => [
            ['value' => true, 'label' => 'state_running', 'tone' => 'ok', 'icon' => 'pause'],
            ['value' => false, 'label' => 'state_stopped', 'tone' => 'off'],
        ]]]];
        $cell = $twig->createTemplate("{% import 'w4os/list-cells.html.twig' as cells %}{{ cells.cell(column, item) }}");

        expect($cell->render(['column' => $column, 'item' => ['running' => true]]))->toContain('ti-pause', 'w4os-tone-ok')->not->toContain('ti-run')
            ->and($cell->render(['column' => $column, 'item' => ['running' => false]]))->toContain('ti-run', 'w4os-tone-off');
    });

    test('a ban shows by its icons, never by its color alone: the account off and the sign in red, or the sign in orange', function () {
        $list = (new W4os\Grav\OpenApi(OpenSim\Api\Contract::document()))->listOf('avatars');
        $column = array_column($list['columns'], null, 'name')['status'];
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->addFunction(new TwigFunction('w4os_icon', fn (string $icon) => "ti-$icon"));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => []));
        $twig->addFilter(new TwigFilter('t', fn ($key) => "[$key]"));
        $cell = fn (array $item) => $twig->createTemplate("{% import 'w4os/list-cells.html.twig' as cells %}{{ cells.cell(column, item) }}")->render(['column' => $column, 'item' => $item]);
        $hard = $cell(['uuid' => 'a1', 'status' => 'banned', 'ban' => 'hard', 'online' => false]);
        $estates = $cell(['uuid' => 'a2', 'status' => 'active', 'ban' => 'estates', 'online' => false]);

        expect($hard)->toMatch('~w4os-tone-failed[^>]*><i class="ti-user-off"~')->toMatch('~w4os-tone-failed[^>]*><i class="ti-ban"~')->not->toContain('ti-account')
            ->and($estates)->toMatch('~w4os-tone-ok[^>]*><i class="ti-account"~')->toMatch('~w4os-tone-warning[^>]*><i class="ti-ban"~')->not->toContain('ti-user-off');
    });
});
