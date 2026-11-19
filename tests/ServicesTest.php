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
            ->and(array_keys($database['fields']))->toBe(['searchDatabase.host', 'searchDatabase.port', 'searchDatabase.database', 'searchDatabase.user', 'searchDatabase.password'])
            ->and($database['fields']['searchDatabase.port'])->toMatchArray(['type' => 'number', 'placeholder' => '3306', 'label' => 'w4os.db_port'])
            ->and($database['fields']['searchDatabase.password'])->toMatchArray(['type' => 'password'])
            ->and($fields['dataServices'])->toMatchArray(['disabled' => true]);
    });

    $render = function (array $service) {
        $twig = new Twig\Environment(new Twig\Loader\ChainLoader([new Twig\Loader\ArrayLoader(['w4os/admin-form.html.twig' => '<form id="{{ form.name }}"></form>']), new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates')]));
        $twig->addFunction(new Twig\TwigFunction('w4os_service', fn () => $service + ['view' => null, 'error' => null, 'help' => []]));
        $twig->addFunction(new Twig\TwigFunction('forms', fn (string $name) => ['name' => $name]));
        $twig->addFilter(new Twig\TwigFilter('t', fn ($key) => "[$key]"));

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

        expect($fields['path'])->toMatchArray(['type' => 'text', 'prepend' => 'http://grid.example.org', 'help' => '[economy_helper_uri_help]'])
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
            ->and($fields['path_snippets'])->toMatchArray(['type' => 'display', 'label' => 'Robust.HG.ini', 'markdown' => true])
            ->and($fields['path_snippets']['content'])->toContain("```ini\n[GridInfoService]\n    economy = http://grid.example.org/helpers\n```");
    });

    test('a service the site handles needs a page of the site that shows its block', function () {
        expect(Site::holdsBlock('Text [w4os block="destination-guide" /] more', 'destination_guide'))->toBeTrue()
            ->and(Site::holdsBlock('[w4os block="destination_guide" title="Guide"]', 'destination_guide'))->toBeTrue()
            ->and(Site::holdsBlock('[w4os block="search" /]', 'destination_guide'))->toBeFalse()
            ->and(Site::blockOfPage('guide'))->toBe('destination_guide')
            ->and(Site::blockOfPage('search'))->toBe('search')
            ->and(Site::blockOfPage('account'))->toBeNull();
    });
});
