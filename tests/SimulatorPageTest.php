<?php

use W4OS\Grav\Items;
use W4OS\Grav\Site;
use W4OS\Views\Contract;
use W4OS\Views\FormView;

describe('the page of a simulator', function () {
    test('lives under the simulators, each of their list leading to it', function () {
        $routes = Site::adminRoutes(['admin' => '/w4os'], []);
        $page = (string) file_get_contents(dirname(__DIR__).'/pages/simulator.md');

        expect($routes['simulator'])->toBe('/w4os/simulators/simulator')
            ->and(Site::ADMIN_SECTIONS['simulator'])->toBe('simulators')
            ->and(Site::ADMIN_TYPES['simulator'])->toBe('simulators')
            ->and($page)->toContain('w4os: item', 'type: simulators', 'list: simulators')
            ->and((string) file_get_contents(dirname(__DIR__).'/templates/w4os/simulators.html.twig'))->toContain("w4os_route('simulator') ~ '?id={uuid}'");
    });

    test('its form in the tabs of its groups: its port, its console, its database with its Use default; its URI only shown', function () {
        $form = (new Contract(W4OS\Engine\Api\Contract::document()))->itemOf('simulators');
        $item = ['uuid' => 's1', 'uri' => 'http://sim.example.org:9001', 'httpPort' => 9001, 'consoleUser' => 'console', 'consolePort' => 9010, 'database' => null];
        $tabs = Items::fields(FormView::of($form, $item, null, true, 250))['groups']['fields'];

        expect(array_keys($tabs))->toBe(['simulator', 'console', 'database'])
            ->and(array_keys($tabs['simulator']['fields']))->toBe(['httpPort'])
            ->and($tabs['console']['fields']['consolePass'])->toMatchArray(['type' => 'password'])
            ->and($tabs['database']['fields']['database'])->toMatchArray(['type' => 'fieldset', 'label' => 'w4os.setting_simulator_database', 'classes' => 'w4os-database'])
            ->and($tabs['database']['fields']['database']['description'])->toContain('w4os.db_default_opensim')
            ->and($tabs['database']['fields']['database']['fields']['database.host']['outerclasses'])->toContain('w4os-unmet');
    });
});
