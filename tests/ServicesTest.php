<?php

use W4os\Grav\Items;
use W4os\Grav\Site;
use W4OS\Views\Contract;
use W4OS\Views\FormView;

describe('the settings pages of the services of the grid', function () {
    test('live under the admin route, in the admin menu after the avatars and the Flux as w4os-wp has them, for whom the contract lets read them', function () {
        $routes = Site::adminRoutes(['admin' => '/w4os'], []);
        $services = ['settings_economy' => 'economy', 'settings_search' => 'search', 'settings_guide' => 'guide', 'settings_offline' => 'offline', 'settings_assets' => 'assets'];
        foreach ($services as $type => $group) {
            expect($routes[$type])->toBe("/w4os/$group")
                ->and(Site::ADMIN_PAGES[$type]['label'])->toBe("w4os.settings_menu_$group")
                ->and(Site::ADMIN_OPERATIONS[$type])->toBe(['get', "/grids/{grid}/settings/$group"])
                ->and(Site::titleKey($type))->toBe("w4os.settings_$group")
                ->and(Site::serviceGroup($type))->toBe($group);
        }
        $pages = array_keys(Site::ADMIN_PAGES);

        expect(array_slice($pages, array_search('flux', $pages, true) + 1, 5))->toBe(array_keys($services))
            ->and(Site::serviceGroup('models'))->toBeNull();
    });

    test('shows what the options of the grid set, unchangeable, and the rest to change', function () {
        $form = (new Contract(OpenSim\Api\Contract::document()))->settingsOf('offline');
        $fields = Items::fields(FormView::of($form, ['offlineModule' => 'OfflineMessageModule', 'offlineMessageUrl' => 'https://play.example.org/offline', 'mailSender' => ''], null, true, 250), shown: true);

        expect($fields['offlineModule'])->toMatchArray(['type' => 'select', 'disabled' => true, 'label' => 'w4os.setting_offline_module'])
            // The address of a service the site does not provide is changed here (Provide off)
            ->and($fields['offlineMessageUrl'])->toMatchArray(['type' => 'url'])
            ->and($fields['mailSender'])->toMatchArray(['type' => 'email', 'placeholder' => 'no-reply@example.org'])
            ->and($fields['mailSender'])->not->toHaveKey('disabled');
    });

    test('a database as its parts, its password never filled in', function () {
        $form = (new Contract(OpenSim\Api\Contract::document()))->settingsOf('search');
        $item = ['searchUrl' => 'https://play.example.org/query.php', 'dataServices' => ['https://a.example.org/register.php'], 'eventsUrl' => '',
            'searchDatabase' => ['host' => 'db.example.org', 'port' => 3306, 'database' => 'search', 'user' => 'os', 'password' => '********']];
        $fields = Items::fields(FormView::of($form, $item, null, true, 250), shown: true);
        $database = $fields['searchDatabase'];

        expect($database)->toMatchArray(['type' => 'fieldset', 'title' => 'w4os.setting_search_database'])
            ->and(array_keys($database['fields']))->toBe(['searchDatabase.useDefault', 'searchDatabase.host', 'searchDatabase.port', 'searchDatabase.database', 'searchDatabase.user', 'searchDatabase.password'])
            ->and($database['fields']['searchDatabase.port'])->toMatchArray(['type' => 'number', 'placeholder' => '3306', 'label' => 'w4os.db_port'])
            ->and($database['fields']['searchDatabase.password'])->toMatchArray(['type' => 'password'])
            ->and($fields['dataServices'])->toMatchArray(['disabled' => true]);
    });

    $render = function (array $service) {
        $twig = new Twig\Environment(new Twig\Loader\ChainLoader([new Twig\Loader\ArrayLoader(['w4os/admin-form.html.twig' => '<form id="{{ form.name }}"></form>']), new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates')]));
        $twig->addFunction(new Twig\TwigFunction('w4os_service', fn () => $service + ['view' => null, 'error' => null, 'help' => []]));
        $twig->addFunction(new Twig\TwigFunction('forms', fn (string $name) => ['name' => $name]));
        $twig->addFunction(new Twig\TwigFunction('nonce_field', fn () => '<input type="hidden" name="nonce" value="n">', ['is_safe' => ['html']]));
        $twig->addFilter(new Twig\TwigFilter('t', fn ($key) => "[$key]"));
        $twig->addFilter(new Twig\TwigFilter('w4os_date', fn ($value) => (string) $value));

        return $twig->render('w4os/service.html.twig', []);
    };

    test('is the form of the engine as wide as the page, or why it could not be read', function () use ($render) {
        $view = FormView::of((new Contract(OpenSim\Api\Contract::document()))->settingsOf('guide'), [], null, true, 250);

        expect($render(['view' => $view]))->toContain('<div class="col-12">', '<form id="w4os-service"></form>')
            ->and($render(['error' => 'The grid cannot be reached']))->toContain('alert-danger', 'The grid cannot be reached')->not->toContain('<form');
    });

    test('every page of the engine makes a form: its help of several points said in full, its notes shown, its path on the address of the site', function () {
        $t = fn (string $key) => "[".substr($key, 5)."]";
        foreach (['models', 'economy', 'search', 'guide', 'offline', 'assets'] as $group) {
            $form = (new Contract(OpenSim\Api\Contract::document()))->settingsOf($group);
            $fields = Items::fields(FormView::of($form, ['provide' => true, 'economyModule' => 'Gloebit'], null, true, 250), shown: true, translate: $t, base: 'http://grid.example.org');
            array_walk_recursive($fields, fn ($value) => expect(is_scalar($value) || $value === null)->toBeTrue());
        }
        $form = (new Contract(OpenSim\Api\Contract::document()))->settingsOf('economy');
        $fields = Items::fields(FormView::of($form, ['provide' => true, 'economyModule' => 'Gloebit', 'path' => '/helpers'], null, true, 250), shown: true, translate: $t, base: 'http://grid.example.org');

        expect($fields['path'])->toMatchArray(['type' => 'w4os_path', 'base' => 'http://grid.example.org', 'help' => '[economy_helper_uri_help]'])
            ->and($fields['gloebitConfiguration'])->toMatchArray(['type' => 'display', 'label' => 'w4os.gloebit_configuration'])
            ->and($fields['gloebitConfiguration']['content'])->toContain('[gloebit_help_crash] [gloebit_help_linux]');
    });

    test('shows Provide as a switch, and under a field the lines of the files of the grid it takes', function () {
        $form = (new Contract(OpenSim\Api\Contract::document()))->settingsOf('economy');
        $snippets = ['path' => [['file' => 'Robust.HG.ini', 'text' => "[GridInfoService]\n    economy = http://grid.example.org/helpers"]]];
        $fields = Items::fields(FormView::of($form, ['provide' => true, 'path' => '/helpers'], null, true, 250), shown: true, translate: fn ($key) => $key, base: 'http://grid.example.org', snippets: $snippets);
        $names = array_keys($fields);

        expect($fields['provide'])->toMatchArray(['type' => 'checkbox'])
            ->and($fields['provide']['wrapper_classes'] ?? '')->toContain('form-switch')
            ->and($names[array_search('path', $names, true) + 1])->toBe('path_snippets')
            ->and($fields['path_snippets'])->toMatchArray(['type' => 'display', 'label' => 'w4os.admin_snippet_lines', 'markdown' => true])
            ->and($fields['path_snippets']['content'])->toContain("```ini\n[GridInfoService]\n    economy = http://grid.example.org/helpers\n```");
    });

    test('a service the site handles needs a page of the site that shows its block', function () {
        expect(Site::holdsBlock('Text [destination-guide] more', 'destination_guide'))->toBeTrue()
            ->and(Site::holdsBlock('[destination-guide title="Guide" /]', 'destination-guide'))->toBeTrue()
            ->and(Site::holdsBlock('[web-search]', 'destination_guide'))->toBeFalse()
            ->and(Site::blockOfPage('guide'))->toBe('destination_guide')
            ->and(Site::blockOfPage('search'))->toBe('search')
            ->and(Site::blockOfPage('account'))->toBeNull();
    });

    test('the search shows its tasks beside its settings, as w4os-wp its parser: when they last ran, what they said, a button to run them now', function () use ($render) {
        $tasks = [
            ['id' => 'friendly_fowl:search-parse', 'name' => 'search-parse', 'scope' => 'friendly_fowl', 'label' => 'Index the regions', 'lastRun' => 1795093084, 'status' => 'ran', 'message' => 'no simulator due'],
            ['id' => 'other:search-parse', 'name' => 'search-parse', 'scope' => 'other', 'label' => 'Index the regions'],
            ['id' => 'friendly_fowl:events-parse', 'name' => 'events-parse', 'scope' => 'friendly_fowl', 'label' => 'Read the events', 'lastRun' => null, 'status' => null, 'message' => ''],
            ['id' => 'friendly_fowl:backup', 'name' => 'backup', 'scope' => 'friendly_fowl', 'label' => 'Back up'],
        ];
        $shown = Site::serviceTasks($tasks, 'friendly_fowl', 'search');
        $view = FormView::of((new Contract(OpenSim\Api\Contract::document()))->settingsOf('guide'), [], null, true, 250);
        $html = $render(['view' => $view, 'tasks' => $shown, 'run' => true]);

        expect(array_column($shown, 'id'))->toBe(['friendly_fowl:search-parse', 'friendly_fowl:events-parse'])
            ->and(Site::serviceTasks($tasks, 'friendly_fowl', 'economy'))->toBe([])
            ->and($html)->toContain('Index the regions', 'no simulator due', 'name="task" value="friendly_fowl&#x3A;search-parse"', '[w4os.admin_task_run]')
            ->and($render(['view' => $view, 'tasks' => $shown, 'run' => false]))->not->toContain('name="task"');
    });

    test('a setting this site cannot write is shown unchangeable, with why and the line to add by hand, and never sent', function () {
        $form = (new Contract(OpenSim\Api\Contract::document()))->settingsOf('guide');
        $fixed = ['path' => ['reason' => 'this server cannot write Robust.HG.ini, where the grid reads it: set it there by hand', 'file' => 'Robust.HG.ini', 'text' => "[LoginService]\n    DestinationGuide = http://grid.example.org/guide"]];
        $view = FormView::of($form, ['provide' => true, 'path' => '/guide', 'guideSource' => 'destinations.txt'], null, true, 250);
        $fields = Items::fields($view, shown: true, translate: fn ($key) => $key, base: 'http://grid.example.org', fixed: $fixed);

        expect($fields['path'])->toMatchArray(['disabled' => true, 'help' => 'this server cannot write Robust.HG.ini, where the grid reads it: set it there by hand'])
            ->and($fields['path_fixed'])->toMatchArray(['type' => 'display', 'label' => 'w4os.admin_snippet_lines'])
            ->and($fields['path_fixed']['content'])->toContain('DestinationGuide = http://grid.example.org/guide')
            ->and(Items::unfixed(['path' => '', 'guideSource' => 'other.txt'], $fixed))->toBe(['guideSource' => 'other.txt']);
    });

    test('says why a setting cannot be changed in the language of the page, from the notice the engine gives', function () {
        $form = (new Contract(OpenSim\Api\Contract::document()))->settingsOf('guide');
        $fixed = ['path' => ['reason' => ['code' => 'setting-file-unwritable', 'params' => ['file' => 'Robust.HG.ini']], 'file' => 'Robust.HG.ini', 'text' => "[LoginService]\n    DestinationGuide = http://grid.example.org/guide"]];
        $french = OpenSim\I18n\Catalog::messages('fr_FR');
        $translate = fn (string $key) => $french[substr($key, 5)] ?? $key;
        $fields = Items::fields(FormView::of($form, ['provide' => true, 'path' => '/guide'], null, true, 250), shown: true, translate: $translate, fixed: $fixed);

        expect($fields['path']['help'])->toBe(strtr($french['notice_setting_file_unwritable'], ['{file}' => 'Robust.HG.ini']))
            ->and($fields['path']['help'])->not->toContain('This server');
    });

    test('a database the grid has a default for starts with its Use default, its parts hidden while it is checked', function () {
        $form = (new Contract(OpenSim\Api\Contract::document()))->settingsOf('search');
        $fields = Items::fields(FormView::of($form, ['provide' => true, 'searchDatabase' => null], null, true, 250), shown: true);
        $parts = $fields['searchDatabase']['fields'];

        expect(array_key_first($parts))->toBe('searchDatabase.useDefault')
            ->and($parts['searchDatabase.useDefault'])->toMatchArray(['type' => 'checkbox', 'label' => 'w4os.db_use_default'])
            ->and($parts['searchDatabase.host']['outerclasses'])->toContain('w4os-fallback-of-searchDatabase', 'w4os-unmet')
            ->and(Items::fields(FormView::of($form, ['provide' => true, 'searchDatabase' => ['host' => 'db']], null, true, 250), shown: true)['searchDatabase']['fields']['searchDatabase.host']['outerclasses'])
            ->not->toContain('w4os-unmet');
    });

    test('a database this site cannot write has every part unchangeable', function () {
        $form = (new Contract(OpenSim\Api\Contract::document()))->settingsOf('economy');
        $fixed = ['gloebitDatabase' => ['reason' => 'r', 'file' => 'OpenSim.ini', 'text' => '[Gloebit]']];
        $fields = Items::fields(FormView::of($form, ['provide' => true, 'economyModule' => 'Gloebit', 'gloebitDatabase' => null], null, true, 250), shown: true, fixed: $fixed);

        expect(array_filter($fields['gloebitDatabase']['fields'], fn (array $part) => empty($part['disabled'])))->toBe([]);
    });

    test('a path shows the address it is under as the start of its field, in the same line', function () {
        $template = (string) file_get_contents(dirname(__DIR__).'/templates/forms/fields/w4os_path/w4os_path.html.twig');

        expect($template)->toContain('input-group', 'input-group-text', 'field.base')
            ->and($template)->toContain('forms/fields/text/text.html.twig');
    });

    test('names the lines of a file as what they are, and puts an example after the settings, never between them', function () {
        $form = (new Contract(OpenSim\Api\Contract::document()))->settingsOf('guide');
        $snippets = [
            'guideSource' => [['file' => null, 'label' => 'guide_example', 'text' => "Hypergrid examples\nhop://example.org:8002/Place"]],
            'path' => [['file' => 'Robust.HG.ini', 'text' => "[LoginService]\n    DestinationGuide = http://grid.example.org/guide"]],
        ];
        $texts = ['w4os.admin_snippet_lines' => 'Lines to add to %s', 'w4os.guide_example' => 'Example of a destinations file'];
        $fields = Items::fields(FormView::of($form, ['provide' => true, 'path' => '/guide', 'guideSource' => 'destinations.txt'], null, true, 250), shown: true, translate: fn ($key) => $texts[$key] ?? $key, snippets: $snippets);
        $names = array_keys($fields);

        expect($fields['path_snippets']['label'])->toBe('Lines to add to Robust.HG.ini')
            ->and($fields['guideSource_snippets']['label'])->toBe('Example of a destinations file')
            ->and(end($names))->toBe('guideSource_snippets')
            ->and($names[array_search('guideSource', $names, true) + 1])->not->toBe('guideSource_snippets');
    });
});
